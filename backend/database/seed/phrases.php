<?php

declare(strict_types=1);

// Дані сіду для тестової БД (orakuls_test). НА ПРОД НЕ ПОТРАПЛЯЄ: фрази туди завантажує адмін через імпорт.
//
// Порядок рядків = порядок id (1, 2, 3 …); на нього спираються тести BE-003 … BE-008.
// НЕ МІНЯЙ порядок і не вставляй рядки в середину — нові випадки додавай лише в кінець.
// Тексти унікальні між рядками, щоб тест міг впізнати фразу за текстом.
// Ключ 'active' за замовчуванням true; відсутній переклад = порожній рядок.
//
// Склад: 18 базових активних фраз (по 3 на категорію, усі мови) + 12 особливих випадків.

return [
    // ---- базові: усі мови заповнені, активні ----
    ['key' => 'love_1', 'category' => 'love', 'lv' => 'Mīlestība atnāks tur, kur to negaidi.', 'en' => 'Love will arrive where you least expect it.', 'ru' => 'Любовь придёт туда, где ты её не ждёшь.'],
    ['key' => 'love_2', 'category' => 'love', 'lv' => 'Atver sirdi, un tā tev atbildēs.', 'en' => 'Open your heart and it will answer.', 'ru' => 'Открой сердце, и оно тебе ответит.'],
    ['key' => 'love_3', 'category' => 'love', 'lv' => 'Vislabākais siltums ir kopā dalīts.', 'en' => 'The best warmth is the one you share.', 'ru' => 'Лучшее тепло — то, которым делятся.'],

    ['key' => 'money_1', 'category' => 'money', 'lv' => 'Nauda nāk pie tā, kas prot gaidīt.', 'en' => 'Money comes to those who know how to wait.', 'ru' => 'Деньги приходят к тому, кто умеет ждать.'],
    ['key' => 'money_2', 'category' => 'money', 'lv' => 'Mazs ieguldījums šodien ir liels rīt.', 'en' => 'A small investment today is a big one tomorrow.', 'ru' => 'Маленькое вложение сегодня — большое завтра.'],
    ['key' => 'money_3', 'category' => 'money', 'lv' => 'Bagātība sākas ar pateicību.', 'en' => 'Wealth begins with gratitude.', 'ru' => 'Богатство начинается с благодарности.'],

    ['key' => 'luck_1', 'category' => 'luck', 'lv' => 'Veiksme mīl drosmīgos.', 'en' => 'Fortune favors the brave.', 'ru' => 'Удача любит смелых.'],
    ['key' => 'luck_2', 'category' => 'luck', 'lv' => 'Šī diena atnesīs jauku pārsteigumu.', 'en' => 'This day will bring a pleasant surprise.', 'ru' => 'Этот день принесёт приятный сюрприз.'],
    ['key' => 'luck_3', 'category' => 'luck', 'lv' => 'Nejaušība nav nejaušība.', 'en' => 'A coincidence is never just a coincidence.', 'ru' => 'Случайность не бывает случайной.'],

    ['key' => 'work_1', 'category' => 'work', 'lv' => 'Uzdevums, ko sāc šodien, rīt jau būs puse padarīts.', 'en' => 'A task begun today is half done tomorrow.', 'ru' => 'Дело, начатое сегодня, завтра уже наполовину сделано.'],
    ['key' => 'work_2', 'category' => 'work', 'lv' => 'Kolēģa palīdzība ir spēks.', 'en' => 'A colleague\'s help is strength.', 'ru' => 'Помощь коллеги — это сила.'],
    ['key' => 'work_3', 'category' => 'work', 'lv' => 'Tava uzcītība tiks pamanīta.', 'en' => 'Your diligence will be noticed.', 'ru' => 'Твоё усердие заметят.'],

    ['key' => 'travel_1', 'category' => 'travel', 'lv' => 'Ceļš pats parādīs virzienu.', 'en' => 'The road itself will show the direction.', 'ru' => 'Сама дорога покажет направление.'],
    ['key' => 'travel_2', 'category' => 'travel', 'lv' => 'Nākamais brauciens būs neaizmirstams.', 'en' => 'Your next trip will be unforgettable.', 'ru' => 'Следующая поездка будет незабываемой.'],
    ['key' => 'travel_3', 'category' => 'travel', 'lv' => 'Katra jūdze ir jauns stāsts.', 'en' => 'Every mile is a new story.', 'ru' => 'Каждая миля — новая история.'],

    ['key' => 'mood_1', 'category' => 'mood', 'lv' => 'Pasmaidi, un diena pasmaidīs pretī.', 'en' => 'Smile, and the day will smile back.', 'ru' => 'Улыбнись, и день улыбнётся в ответ.'],
    ['key' => 'mood_2', 'category' => 'mood', 'lv' => 'Šodien ir labs laiks mierīgai elpai.', 'en' => 'Today is a good time for a calm breath.', 'ru' => 'Сегодня хорошее время для спокойного вдоха.'],
    ['key' => 'mood_3', 'category' => 'mood', 'lv' => 'Prieks slēpjas sīkumos.', 'en' => 'Joy hides in the small things.', 'ru' => 'Радость прячется в мелочах.'],

    // ---- особливі випадки ----
    // Прихована фраза, усі мови заповнені: не потрапляє в /api/message, фільтр isActive
    ['key' => 'hidden', 'category' => 'love', 'active' => false, 'lv' => 'Slēpta frāze: to neviens nedrīkst redzēt.', 'en' => 'Hidden phrase: nobody should see this one.', 'ru' => 'Скрытая фраза: её никто не должен видеть.'],
    // Лише lv: фільтр missing, видача лише для lv
    ['key' => 'only_lv', 'category' => 'luck', 'lv' => 'Tikai latviešu valodā: veiksme ir tuvu.'],
    // Лише en
    ['key' => 'only_en', 'category' => 'work', 'en' => 'English only: the deadline will move in your favor.'],
    // lv і en є, ru порожній
    ['key' => 'no_ru', 'category' => 'travel', 'lv' => 'Bez krievu tulkojuma: uz ceļu!', 'en' => 'No Russian translation: off you go!'],
    // en складається лише з пробілів (імітує ручну вставку): «порожній після trim»
    ['key' => 'blank_en', 'category' => 'mood', 'lv' => 'Angļu teksts ir tikai atstarpes.', 'en' => '   ', 'ru' => 'Английский текст состоит только из пробелов.'],
    // Латиська діакритика: пошук «sodien», «bus»
    ['key' => 'diacritics', 'category' => 'mood', 'lv' => 'Šodien veiksme būs tavā pusē.', 'en' => 'Luck is on your side today.', 'ru' => 'Сегодня удача на твоей стороне.'],
    // Кирилиця з великої літери: пошук «тест» без урахування регістру
    ['key' => 'cyrillic_case', 'category' => 'money', 'lv' => 'Pārbaude: nauda ieradīsies.', 'en' => 'Test: the money will arrive.', 'ru' => 'Тест: деньги придут'],
    // Емодзі
    ['key' => 'emoji', 'category' => 'love', 'lv' => 'Mīlestība ir tuvu 💛', 'en' => '🍀 Luck loves you 💛', 'ru' => 'Удача любит тебя 🍀'],
    // Справжній \n у тексті
    ['key' => 'multiline', 'category' => 'work', 'lv' => "Pirmā rinda\nOtrā rinda", 'en' => "Line one\nLine two", 'ru' => "Первая строка\nВторая строка"],
    // Рівно 500 символів (багатобайтових): межа довжини
    ['key' => 'len500', 'category' => 'luck', 'lv' => str_repeat('š', 500), 'en' => str_repeat('😀', 500), 'ru' => 'Ровно пятьсот символов в двух других языках.'],
    // HTML зберігається як є (екранування — справа клієнта)
    ['key' => 'html', 'category' => 'travel', 'lv' => 'HTML: lūdzu, neizpildi.', 'en' => '<script>alert(1)</script> & <b>bold</b>', 'ru' => 'HTML: пожалуйста, не исполняй.'],
    // Спецсимволи LIKE: %, _, \ — перевірка екранування пошуку
    ['key' => 'like_chars', 'category' => 'money', 'lv' => 'LIKE simboli: procenti un pasvītras.', 'en' => '100% sure_thing \\ backslash', 'ru' => 'Спецсимволы LIKE: проценты и подчёркивания.'],
];
