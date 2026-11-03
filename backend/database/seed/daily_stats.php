<?php

declare(strict_types=1);

// Історія лічильників для тестів звіту BE-013 (GET /api/admin/visits). НА ПРОД НЕ ПОТРАПЛЯЄ. НЕ завантажується в reseed():
// тести BE-011/BE-012 рахують «+1» з нуля, зокрема на датах 2026-03-29 та 2026-10-25. Вантажить лише vrWithHistory() у
// tests/api/visits-report.php через Seeder::runStats().
//
// Рядок = день; відсутня метрика = рядка в таблиці немає (нулі для звіту). scan ≤ visit. Усі дати — у минулому
// відносно «сьогодні» (2026-11-03) і не збігаються з ним. Значення довільні, але ФІКСОВАНІ: на них спираються ручні контрольні
// числа в тестах. НЕ МІНЯЙ чисел; нові випадки додавай лише в кінець.

return [
    ['day' => '2025-12-30', 'visit' => 12, 'scan' => 9,  'message_lv' => 4,  'message_en' => 1, 'message_ru' => 3],
    ['day' => '2025-12-31', 'visit' => 20, 'scan' => 15, 'message_lv' => 7,  'message_en' => 2, 'message_ru' => 5],
    ['day' => '2026-01-01', 'visit' => 8,  'scan' => 3,  'message_lv' => 2,                     'message_ru' => 1],   // без en
    ['day' => '2026-01-03', 'visit' => 5,  'scan' => 5],                                                             // без послань
    ['day' => '2026-02-28', 'visit' => 30, 'scan' => 22, 'message_lv' => 10, 'message_en' => 4, 'message_ru' => 6],
    ['day' => '2026-03-01', 'visit' => 41, 'scan' => 33, 'message_lv' => 14, 'message_en' => 5, 'message_ru' => 9],
    ['day' => '2026-03-28', 'visit' => 25, 'scan' => 20, 'message_lv' => 8,  'message_en' => 3, 'message_ru' => 5],
    ['day' => '2026-03-29', 'visit' => 18, 'scan' => 14, 'message_lv' => 6,  'message_en' => 2, 'message_ru' => 4],   // перехід на літній час
    ['day' => '2026-03-30', 'visit' => 22, 'scan' => 17, 'message_lv' => 7,  'message_en' => 3, 'message_ru' => 5],
    ['day' => '2026-10-24', 'visit' => 35, 'scan' => 27, 'message_lv' => 11, 'message_en' => 4, 'message_ru' => 8],
    ['day' => '2026-10-25', 'visit' => 28, 'scan' => 21, 'message_lv' => 9,  'message_en' => 3, 'message_ru' => 6],   // перехід на зимовий час
    ['day' => '2026-10-26', 'visit' => 31, 'scan' => 24, 'message_lv' => 10, 'message_en' => 4, 'message_ru' => 7],
];
