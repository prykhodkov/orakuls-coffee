<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Categories;
use App\Domain\Languages;
use App\Domain\Phrase;
use App\Domain\PhraseFilter;
use App\Domain\PhraseInput;
use App\Domain\PhraseValidator;
use App\Http\Request;
use App\Http\Response;
use App\Repository\PhraseRepository;
use App\Support\ApiException;
use App\Support\Db;

/**
 * Фрази для адмінки: читання (GET /api/admin/phrases, GET /api/admin/phrases/{id}) і запис (POST, PUT, DELETE).
 * Токен перевіряє AdminGuard у Kernel (D-20), тут його не повторюємо.
 * Явні фільтри строгі (на відміну від exclude у BE-003): невідоме значення — 400, бо адмін обирає їх сам.
 * Валідація завершується до звернення до БД: відхилений запит не виконує жодного SQL.
 */
final class PhraseController
{
    private const MAX_SEARCH_CHARS = 200;
    private const MAX_PER_PAGE = 100;

    public function index(Request $request, array $params): Response
    {
        $filter = self::parseFilter($request);

        // Db::pdo() викликається тут, а не в конструкторі: створення контролера в routes.php БД не відкриває
        $result = (new PhraseRepository(Db::pdo()))->search($filter);

        return Response::json(
            [
                'items' => array_map(static fn (Phrase $phrase): array => $phrase->toArray(), $result['items']),
                'total' => $result['total'],
                'page' => $filter->page,
                'perPage' => $filter->perPage,
            ],
            200,
            ['Cache-Control' => 'no-store']
        );
    }

    public function show(Request $request, array $params): Response
    {
        $id = self::parseId($params['id']);

        $phrase = (new PhraseRepository(Db::pdo()))->find($id);
        if ($phrase === null) {
            throw ApiException::notFound('Phrase not found');
        }

        return Response::json($phrase->toArray(), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * POST /api/admin/phrases. Порядок: тіло (400) → валідація (422) → запис. БД відкривається лише після валідації.
     */
    public function create(Request $request, array $params): Response
    {
        $input = self::validatedInput($request);

        $phrase = (new PhraseRepository(Db::pdo()))->create($input);

        return Response::json($phrase->toArray(), 201, ['Cache-Control' => 'no-store']);
    }

    /**
     * PUT /api/admin/phrases/{id} — повна заміна. Порядок: формат id (404) → тіло (400) → валідація (422) → запис (немає фрази — 404).
     */
    public function update(Request $request, array $params): Response
    {
        $id = self::parseId($params['id']);
        $input = self::validatedInput($request);

        $phrase = (new PhraseRepository(Db::pdo()))->update($id, $input);
        if ($phrase === null) {
            throw ApiException::notFound('Phrase not found');
        }

        return Response::json($phrase->toArray(), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * DELETE /api/admin/phrases/{id} → 204 без тіла. Порядок: формат id (404) → видалення (немає фрази — 404).
     */
    public function delete(Request $request, array $params): Response
    {
        $id = self::parseId($params['id']);

        if (!(new PhraseRepository(Db::pdo()))->delete($id)) {
            throw ApiException::notFound('Phrase not found');
        }

        return new Response(204, null, ['Cache-Control' => 'no-store']);
    }

    /**
     * Канонічний id зі шляху: без нулів попереду, знака й дробової частини; неканонічний → 404.
     *
     * @throws ApiException 404
     */
    private static function parseId(string $id): int
    {
        // D: без нього $ пропустив би перенос рядка в кінці ("1\n")
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $id) !== 1) {
            throw ApiException::notFound('Phrase not found');
        }

        return (int) $id;
    }

    /**
     * Тіло запиту → нормалізовані дані фрази.
     *
     * @throws ApiException 400 (не JSON-об'єкт), 422 (поля; усі помилки разом)
     */
    private static function validatedInput(Request $request): PhraseInput
    {
        $result = PhraseValidator::validate($request->json());
        if ($result['input'] === null) {
            throw ApiException::validation($result['fields']);
        }

        return $result['input'];
    }

    /**
     * Розбір і сувора перевірка параметрів списку. Публічний і статичний, щоб тестуватись без БД.
     * Порожній рядок означає «параметра немає». Повідомлення містять назву параметра, але не його значення.
     *
     * @throws ApiException 400
     */
    public static function parseFilter(Request $request): PhraseFilter
    {
        // query() кидає 400 на параметр-масив
        $category = self::param($request, 'category');
        if ($category !== null && !Categories::isValid($category)) {
            throw ApiException::badRequest('Invalid category');
        }

        $missing = self::param($request, 'missing');
        if ($missing !== null && !Languages::isValid($missing)) {
            throw ApiException::badRequest('Invalid missing: expected lv, en or ru');
        }

        $isActiveRaw = self::param($request, 'isActive');
        $isActive = match ($isActiveRaw) {
            null => null,
            'true' => true,
            'false' => false,
            default => throw ApiException::badRequest('Invalid isActive: expected true or false'),
        };

        $search = self::param($request, 'search');
        if ($search !== null) {
            $search = trim($search);
            if ($search === '') {
                $search = null;
            } elseif (mb_strlen($search) > self::MAX_SEARCH_CHARS) { // у символах, не в байтах
                throw ApiException::badRequest('Parameter search is too long: at most ' . self::MAX_SEARCH_CHARS . ' characters');
            }
        }

        // До 9 цифр: OFFSET гарантовано вміщується в 64-бітний int
        $page = self::param($request, 'page');
        if ($page === null) {
            $page = 1;
        } elseif (preg_match('/^[1-9][0-9]{0,8}$/D', $page) === 1) {
            $page = (int) $page;
        } else {
            throw ApiException::badRequest('Invalid page: expected an integer from 1 to 999999999');
        }

        $perPage = self::param($request, 'perPage');
        if ($perPage === null) {
            $perPage = 50;
        } elseif (preg_match('/^[1-9][0-9]{0,2}$/D', $perPage) === 1 && (int) $perPage <= self::MAX_PER_PAGE) {
            $perPage = (int) $perPage;
        } else {
            throw ApiException::badRequest('Invalid perPage: expected an integer from 1 to ' . self::MAX_PER_PAGE);
        }

        return new PhraseFilter($category, $search, $missing, $isActive, $page, $perPage);
    }

    /** Значення параметра; відсутній і порожній (`?category=`) — однаково null. Масив → 400. */
    private static function param(Request $request, string $name): ?string
    {
        $value = $request->query($name);

        return $value === '' ? null : $value;
    }
}
