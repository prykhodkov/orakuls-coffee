<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Categories;
use App\Domain\Languages;
use App\Domain\Phrase;
use App\Domain\PhraseFilter;
use App\Domain\PhraseInput;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Запити до таблиці phrases. SQL живе тут, а не в контролерах (D-17): ним користуватимуться
 * кілька ендпоінтів. PDO отримує ззовні, тож клас не залежить від Db і HTTP.
 */
final class PhraseRepository
{
    /** Колонки, з яких Phrase::fromRow будує фразу. */
    private const COLUMNS = '`id`, `category`, `text_lv`, `text_en`, `text_ru`, `is_active`, `created_at`, `updated_at`';

    /** Службовий символ x02 замість % у пошуку, що починається з % (обхід вади LIKE у MySQL 5.7, див. buildWhere). */
    private const PERCENT_STAND_IN = "\x02";

    /** Рядків в одному багаторядковому INSERT імпорту: 100 × 5 плейсхолдерів, далеко від ліміту 65 535. */
    private const INSERT_BATCH = 100;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * SQL-умова «переклад мовою $lang не порожній». Єдине визначення порожнього для всіх ендпоінтів
     * (BE-003, BE-005 `missing`, BE-008). TRIM у MySQL прибирає лише пробіли (на відміну від trim() у PHP,
     * що обрізає й табуляцію та переноси): BE-006 при записі обрізає всі пробільні символи,
     * тож розбіжність можлива лише для застарілих або внесених вручну даних.
     */
    public static function hasTextSql(string $lang): string
    {
        return 'TRIM(`' . Languages::column($lang) . '`) <> \'\'';
    }

    /**
     * SQL-умова «переклад мовою $lang порожній». Поряд із hasTextSql: єдине визначення порожнього для всіх ендпоінтів.
     */
    public static function lacksTextSql(string $lang): string
    {
        return 'TRIM(`' . Languages::column($lang) . '`) = \'\'';
    }

    /**
     * Готує підрядок для LIKE ... ESCAPE x'01'. Службовий символ вилучається з вводу (його не можна набрати,
     * тож він не може бути шуканим), а % і _ екрануються ним. Зворотний слеш і решта символів лишаються звичайними.
     * Чому не ESCAPE '\' (пошук одного такого символа на MySQL 5.7 нічого не знаходить) — див. D-24.
     */
    public static function escapeLike(string $needle): string
    {
        $needle = str_replace("\x01", '', $needle);

        return strtr($needle, ['%' => "\x01%", '_' => "\x01_"]);
    }

    /**
     * Сторінка фраз за фільтром і загальна кількість після фільтрів. Рівно два запити (вибірка + COUNT)
     * з одним і тим самим WHERE, тож total завжди відповідає фільтрам. Сортування: id за спаданням.
     *
     * @return array{items: list<Phrase>, total: int}
     */
    public function search(PhraseFilter $filter): array
    {
        [$where, $binds] = $this->buildWhere($filter);

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM `phrases`' . $where . ' ORDER BY `id` DESC LIMIT ? OFFSET ?'
        );
        $binds[] = [$filter->perPage, PDO::PARAM_INT];
        $binds[] = [$filter->offset(), PDO::PARAM_INT];
        $this->bindAll($stmt, $binds);
        $stmt->execute();
        $items = array_map(
            static fn (array $row): Phrase => Phrase::fromRow($row),
            array_values($stmt->fetchAll())
        );

        // Ті самі параметри WHERE, але без LIMIT і OFFSET (два останні елементи)
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM `phrases`' . $where);
        $this->bindAll($count, array_slice($binds, 0, -2));
        $count->execute();

        return ['items' => $items, 'total' => (int) $count->fetchColumn()];
    }

    public function find(int $id): ?Phrase
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM `phrases` WHERE `id` = ?');
        $stmt->bindValue(1, $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : Phrase::fromRow($row);
    }

    /**
     * Додає фразу. Два запити: INSERT і find. created_at і updated_at заповнює один UTC_TIMESTAMP() (D-29),
     * тож при створенні вони збігаються. Текст пишеться як є (нормалізував валідатор), лише параметрами.
     */
    public function create(PhraseInput $input): Phrase
    {
        $columns = [];
        foreach (Languages::CODES as $lang) {
            $columns[] = '`' . Languages::column($lang) . '`';
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO `phrases` (`category`, ' . implode(', ', $columns) . ', `is_active`, `created_at`, `updated_at`)'
            . ' VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->bindValue(1, $input->category, PDO::PARAM_STR);
        foreach (Languages::CODES as $i => $lang) {
            $stmt->bindValue($i + 2, $input->text[$lang], PDO::PARAM_STR);
        }
        $stmt->bindValue(5, $input->isActive ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();

        $phrase = $this->find((int) $this->pdo->lastInsertId());
        if ($phrase === null) {
            throw new RuntimeException('Phrase disappeared right after INSERT');
        }

        return $phrase;
    }

    /**
     * Масова вставка для імпорту (BE-007): усе або нічого — одна транзакція на виклик, пакети по INSERT_BATCH рядків
     * (багаторядковий INSERT), created_at і updated_at у пакеті від одного UTC_TIMESTAMP(). Транзакцією керує сам
     * метод. Будь-який збій (Throwable) → rollBack() і той самий виняток далі: ErrorHandler віддасть 500.
     * Порожній список — 0 без запитів і без транзакції. lastInsertId не потрібен: id створених фраз не повертаємо.
     *
     * @param list<PhraseInput> $inputs
     * @return int кількість вставлених рядків
     */
    public function createMany(array $inputs): int
    {
        if ($inputs === []) {
            return 0;
        }

        $columns = [];
        foreach (Languages::CODES as $lang) {
            $columns[] = '`' . Languages::column($lang) . '`';
        }
        $head = 'INSERT INTO `phrases` (`category`, ' . implode(', ', $columns) . ', `is_active`, `created_at`, `updated_at`) VALUES ';
        $row = '(?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())';

        $this->pdo->beginTransaction();
        try {
            foreach (array_chunk($inputs, self::INSERT_BATCH) as $batch) {
                $stmt = $this->pdo->prepare($head . implode(', ', array_fill(0, count($batch), $row)));
                $n = 1; // позиційні параметри нумеруються з 1 наскрізно по всьому пакету
                foreach ($batch as $input) {
                    $stmt->bindValue($n++, $input->category, PDO::PARAM_STR);
                    foreach (Languages::CODES as $lang) {
                        $stmt->bindValue($n++, $input->text[$lang], PDO::PARAM_STR);
                    }
                    $stmt->bindValue($n++, $input->isActive ? 1 : 0, PDO::PARAM_INT);
                }
                $stmt->execute();
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return count($inputs);
    }

    /**
     * Повна заміна category, text, is_active; created_at не чіпаємо, updated_at = зараз (UTC). Два запити: UPDATE і find.
     * null — фрази з таким id немає. rowCount() не використовується: після UPDATE без фактичних змін
     * (ті самі дані в ту ж секунду) він дає 0, як і для неіснуючого id (D-29).
     */
    public function update(int $id, PhraseInput $input): ?Phrase
    {
        $assignments = [];
        foreach (Languages::CODES as $lang) {
            $assignments[] = '`' . Languages::column($lang) . '` = ?';
        }

        $stmt = $this->pdo->prepare(
            'UPDATE `phrases` SET `category` = ?, ' . implode(', ', $assignments)
            . ', `is_active` = ?, `updated_at` = UTC_TIMESTAMP() WHERE `id` = ?'
        );
        $stmt->bindValue(1, $input->category, PDO::PARAM_STR);
        foreach (Languages::CODES as $i => $lang) {
            $stmt->bindValue($i + 2, $input->text[$lang], PDO::PARAM_STR);
        }
        $stmt->bindValue(5, $input->isActive ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(6, $id, PDO::PARAM_INT);
        $stmt->execute();

        return $this->find($id);
    }

    /** Видаляє фразу; false — такого id немає. Один запит. */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM `phrases` WHERE `id` = ?');
        $stmt->bindValue(1, $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Єдине місце побудови WHERE для списку: ним користуються і вибірка сторінки, і COUNT(*).
     * Ввід користувача потрапляє лише в параметри; імена колонок беруться з Languages::column().
     *
     * @return array{0: string, 1: list<array{0: mixed, 1: int}>} SQL (із пробілом попереду або порожній) і параметри
     */
    private function buildWhere(PhraseFilter $filter): array
    {
        $conditions = [];
        $binds = [];

        if ($filter->category !== null) {
            $conditions[] = '`category` = ?';
            $binds[] = [$filter->category, PDO::PARAM_STR];
        }
        if ($filter->isActive !== null) {
            $conditions[] = '`is_active` = ?';
            $binds[] = [$filter->isActive ? 1 : 0, PDO::PARAM_INT];
        }
        if ($filter->missing !== null) {
            $conditions[] = self::lacksTextSql($filter->missing);
        }
        if ($filter->search !== null) {
            $needle = $filter->search;

            // Вада MySQL 5.7 (utf8mb4_unicode_ci): екранований % одразу після початкового % шаблону трактується як
            // шаблонний символ, тож пошук «%…» дає хибні збіги («%_» знаходив би все, де є % і далі _). Для таких
            // вводів % у запиті й у колонці замінюється службовим символом x02 (див. D-24); для решти вводів
            // колонка порівнюється як є.
            $viaReplace = str_starts_with($needle, '%');
            if ($viaReplace) {
                $needle = str_replace('%', self::PERCENT_STAND_IN, str_replace(self::PERCENT_STAND_IN, '', $needle));
            }

            $pattern = '%' . self::escapeLike($needle) . '%';
            $likes = [];
            foreach (Languages::CODES as $lang) {
                $column = '`' . Languages::column($lang) . '`';
                if ($viaReplace) {
                    $column = "REPLACE({$column}, '%', _utf8mb4 x'02')";
                }
                // Подвійні лапки: x'01' без підстановок змінних; керівного символа в коді немає
                $likes[] = $column . " LIKE ? ESCAPE x'01'";
                $binds[] = [$pattern, PDO::PARAM_STR];
            }
            $conditions[] = '(' . implode(' OR ', $likes) . ')';
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $binds];
    }

    /**
     * Позиційні параметри нумеруються з 1 наскрізно по всьому запиту.
     *
     * @param list<array{0: mixed, 1: int}> $binds
     */
    private function bindAll(PDOStatement $stmt, array $binds): void
    {
        foreach ($binds as $i => [$value, $type]) {
            $stmt->bindValue($i + 1, $value, $type);
        }
    }

    /**
     * Зведення для адмінки (BE-008) одним запитом з умовною агрегацією: знімок атомарний (кілька окремих запитів
     * могли б побачити різний стан, якщо адмін щось змінив між ними), D-32. Порожній переклад — лише lacksTextSql(),
     * тож missing.xx завжди збігається з total списку ?missing=xx; приховані фрази теж враховуються.
     * Значення категорій у SQL — літерали з Categories::CODES (білий список), ввід користувача сюди не потрапляє.
     * SUM() у MySQL дає DECIMAL (у PHP — рядок) і NULL на порожній таблиці: COALESCE + (int) на кожному значенні.
     * Фраза з категорією поза списком (можлива лише при ручному редагуванні БД) не потрапляє в byCategory,
     * але входить у total. Ключі byCategory і missing — рівно константи, у їхньому порядку.
     *
     * @return array{total: int, active: int, byCategory: array<string, int>, missing: array<string, int>}
     */
    public function stats(): array
    {
        $columns = ['COUNT(*) AS `total`', 'COALESCE(SUM(`is_active` = 1), 0) AS `active`'];
        foreach (Categories::CODES as $code) {
            $columns[] = "COALESCE(SUM(`category` = '{$code}'), 0) AS `cat_{$code}`"; // $code — з білого списку
        }
        foreach (Languages::CODES as $lang) {
            $columns[] = 'COALESCE(SUM(' . self::lacksTextSql($lang) . '), 0) AS `miss_' . $lang . '`';
        }

        $row = $this->pdo->query('SELECT ' . implode(', ', $columns) . ' FROM `phrases`')->fetch();

        $byCategory = [];
        foreach (Categories::CODES as $code) {
            $byCategory[$code] = (int) $row["cat_{$code}"];
        }
        $missing = [];
        foreach (Languages::CODES as $lang) {
            $missing[$lang] = (int) $row["miss_{$lang}"];
        }

        return [
            'total' => (int) $row['total'],
            'active' => (int) $row['active'],
            'byCategory' => $byCategory,
            'missing' => $missing,
        ];
    }

    /**
     * Випадкова активна фраза з непорожнім перекладом. Фрази з $excludeIds вибираються лише тоді,
     * коли інших кандидатів немає: тоді `exclude` фактично ігнорується (D-19).
     *
     * @param list<int> $excludeIds
     * @return array{id: int, text: string}|null null — кандидатів немає
     */
    public function findRandom(string $lang, array $excludeIds = []): ?array
    {
        // Кидає виняток на невідомий код: ім'я колонки не можна передати параметром, тож лише білий список
        $column = Languages::column($lang);

        $sql = "SELECT `id`, `{$column}` AS `text` FROM `phrases` WHERE `is_active` = 1 AND " . self::hasTextSql($lang);
        if ($excludeIds === []) {
            // IN () з порожнім списком — синтаксична помилка MySQL, тож у цьому випадку його не додаємо
            $sql .= ' ORDER BY RAND() LIMIT 1';
        } else {
            // (id IN (…)) дає 0 для непереглянутих і 1 для виключених: спершу йдуть непереглянуті
            // у випадковому порядку, а якщо їх немає — виключені. Другого запиту не потрібно.
            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $sql .= " ORDER BY (`id` IN ({$placeholders})) ASC, RAND() LIMIT 1";
        }

        $stmt = $this->pdo->prepare($sql);
        foreach (array_values($excludeIds) as $i => $id) {
            $stmt->bindValue($i + 1, $id, PDO::PARAM_INT); // позиційні параметри нумеруються з 1
        }
        $stmt->execute();

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return ['id' => (int) $row['id'], 'text' => (string) $row['text']];
    }
}
