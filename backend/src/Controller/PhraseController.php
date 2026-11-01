<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Categories;
use App\Domain\Languages;
use App\Domain\Phrase;
use App\Domain\PhraseFilter;
use App\Http\Request;
use App\Http\Response;
use App\Repository\PhraseRepository;
use App\Support\ApiException;
use App\Support\Db;

/**
 * Читання фраз для адмінки: GET /api/admin/phrases і GET /api/admin/phrases/{id}.
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
        $id = $params['id'];
        // D: без нього $ пропустив би перенос рядка в кінці ("1\n")
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $id) !== 1) {
            throw ApiException::notFound('Phrase not found');
        }

        $phrase = (new PhraseRepository(Db::pdo()))->find((int) $id);
        if ($phrase === null) {
            throw ApiException::notFound('Phrase not found');
        }

        return Response::json($phrase->toArray(), 200, ['Cache-Control' => 'no-store']);
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
