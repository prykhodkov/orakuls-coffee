<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Валідація фрази для BE-006 і BE-007 (імпорт викликає validate() для кожного елемента).
 * Збирає всі помилки полів разом, винятків на помилках даних не кидає. Не залежить від Http і Repository.
 *
 * Коди помилок (їх читає фронт): required, invalid, too_long, empty. Ключі — шлях до поля:
 * category, text, text.lv|en|ru, isActive. Невідомі ключі (верхній рівень і всередині text) ігноруються.
 */
final class PhraseValidator
{
    /** Максимум символів (не байтів і не UTF-16-одиниць) в одному тексті після trim. */
    public const TEXT_MAX_CHARS = 500;

    /**
     * Вміст символьного класу для trim: рівно ті символи, які обрізає JS String.prototype.trim
     * (пробільні + термінатори рядка). PHP trim() не знає NBSP, U+3000, U+FEFF та інших (D-27).
     * U+0085, U+180E, U+200B JS не обрізає, тож і ми — ні.
     */
    private const TRIM_CLASS = '\t\n\x0B\x0C\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /** Керівні символи, заборонені в тексті; дозволені \t, \n, \r (D-28). C1 (U+0080–U+009F) не забороняємо. */
    private const CONTROL_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';

    /**
     * @param array<string, mixed> $data тіло запиту (або елемент імпорту) після json_decode
     * @return array{input: ?PhraseInput, fields: array<string, string>} input заповнений, лише коли fields порожній
     */
    public static function validate(array $data): array
    {
        $fields = [];

        // category
        $category = $data['category'] ?? null;
        if ($category === null) {
            $fields['category'] = 'required';
        } elseif (!is_string($category) || !Categories::isValid($category)) {
            $fields['category'] = 'invalid';
        }

        // text
        $texts = [];
        if (!array_key_exists('text', $data)) {
            $fields['text'] = 'required';
        } elseif (!is_array($data['text']) || ($data['text'] !== [] && array_is_list($data['text']))) {
            // Об'єкт — це масив, що не є непорожнім списком; {} і [] дають порожній масив (D-28)
            $fields['text'] = 'invalid';
        } else {
            $textErrors = false;
            foreach (Languages::CODES as $lang) {
                if (!array_key_exists($lang, $data['text'])) {
                    $fields["text.{$lang}"] = 'required';
                    $textErrors = true;
                    continue;
                }

                $error = self::checkText($data['text'][$lang], $value);
                if ($error !== null) {
                    $fields["text.{$lang}"] = $error;
                    $textErrors = true;
                    continue;
                }
                $texts[$lang] = $value;
            }

            if (!$textErrors && implode('', $texts) === '') {
                $fields['text'] = 'empty';
            }
        }

        // isActive
        $isActive = true;
        if (array_key_exists('isActive', $data)) {
            if (is_bool($data['isActive'])) {
                $isActive = $data['isActive'];
            } else {
                $fields['isActive'] = 'invalid';
            }
        }

        if ($fields !== []) {
            return ['input' => null, 'fields' => $fields];
        }

        return [
            'input' => new PhraseInput($category, ['lv' => $texts['lv'], 'en' => $texts['en'], 'ru' => $texts['ru']], $isActive),
            'fields' => [],
        ];
    }

    /**
     * Юнікодний trim, як у JS String.prototype.trim (D-27).
     * Невалідний UTF-8 дав би null від preg_replace: валідатор перевіряє кодування до виклику,
     * але для прямого виклику повертаємо вхідний рядок.
     */
    public static function trim(string $s): string
    {
        $class = '[' . self::TRIM_CLASS . ']';
        // (?<!клас) дозволяє правій гілці починатись лише з початку серії пробілів, а присвійний ++ забороняє
        // повертатись усередину серії: без них довга серія пробілів усередині тексту сканувалась би заново з кожної
        // позиції (квадратична складність), а на мільйонах символів ще й вичерпувала б backtrack_limit (тоді preg_replace
        // повертає null і обрізання мовчки пропускалось би)
        $result = preg_replace('/^' . $class . '++|(?<!' . $class . ')' . $class . '++$/u', '', $s);

        return $result ?? $s;
    }

    /**
     * Перевірка одного тексту. Повертає код помилки або null; trimmed значення — через $value.
     */
    private static function checkText(mixed $raw, ?string &$value): ?string
    {
        $value = null;
        if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) {
            return 'invalid';
        }

        $trimmed = self::trim($raw);
        if (preg_match(self::CONTROL_PATTERN, $trimmed) === 1) {
            return 'invalid';
        }
        if (mb_strlen($trimmed, 'UTF-8') > self::TEXT_MAX_CHARS) {
            return 'too_long';
        }

        $value = $trimmed;

        return null;
    }
}
