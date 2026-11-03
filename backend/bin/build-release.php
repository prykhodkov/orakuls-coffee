<?php

declare(strict_types=1);

// Збирає пакет для розгортання на хостингу (BE-009) у backend/build/release/:
//   orakuls/       src/, database/ (schema.sql + migrations), bin/, .env.example — кладеться ВИЩЕ public_html
//   public_html/   .htaccess, api/index.php, api/.user.ini + (за бажанням) збірка фронта — кладеться в public_html
//   diagnostics/   diag.php — ТИМЧАСОВИЙ діагностичний скрипт (не заливати в public_html без ключа і не лишати на сервері)
// Тести, сід, Docker-файли й .env у пакет не потрапляють.
//
// Запуск: docker compose exec php php bin/build-release.php [--frontend=<каталог dist>] [--out=<каталог>]
//   --frontend: готова збірка фронта (npm run build у frontend/); її вміст копіюється в public_html (index.html, assets/, admin/ …).
//               Без прапорця пакет містить лише бекенд; фронт викладається окремо. УВАГА: у Docker змонтовано лише backend/,
//               тож шлях ../frontend/dist із контейнера недоступний — там зберіть пакет без прапорця й скопіюйте dist на хості:
//               cp -r ../frontend/dist/. build/release/public_html/
//   --out:      куди збирати (за замовчуванням backend/build/release; каталог спершу ОЧИЩАЄТЬСЯ — лише якщо це build/release
//               або щойно створений порожній каталог).

if (PHP_SAPI !== 'cli') { // скрипти не мають виконуватись через веб
    http_response_code(404);
    exit;
}

$backend = dirname(__DIR__);
$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(frontend|out)=(.+)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

$out = rtrim(str_replace('\\', '/', $options['out'] ?? $backend . '/build/release'), '/');

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function copyTree(string $from, string $to): int
{
    $count = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
        $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
        if ($item->isDir()) {
            is_dir($target) || mkdir($target, 0755, true);
        } else {
            is_dir(dirname($target)) || mkdir(dirname($target), 0755, true);
            copy($item->getPathname(), $target);
            $count++;
        }
    }

    return $count;
}

// Очищаємо лише відомий каталог збірки або порожній: помилковий --out=/ чи --out=<домашня папка> нічого не зітре
$isDefault = realpath($out) === realpath($backend . '/build/release');
if (is_dir($out)) {
    $empty = count(array_diff(scandir($out) ?: [], ['.', '..'])) === 0;
    if (!$isDefault && !$empty) {
        fwrite(STDERR, "Refusing to clean non-empty directory outside build/release: {$out}\n");
        exit(2);
    }
    removeTree($out);
}

$files = 0;
mkdir($out . '/orakuls/database', 0755, true);
mkdir($out . '/orakuls/bin', 0755, true);
mkdir($out . '/public_html', 0755, true);

$files += copyTree($backend . '/src', $out . '/orakuls/src');
$files += copyTree($backend . '/database/migrations', $out . '/orakuls/database/migrations');
copy($backend . '/database/schema.sql', $out . '/orakuls/database/schema.sql');
copy($backend . '/.env.example', $out . '/orakuls/.env.example');
$files += 2;
// У пакет — лише скрипти, потрібні на сервері; build-schema.php і seed.php належать розробці (сід — лише для *_test)
foreach (['migrate.php', 'hash-password.php', 'generate-admin-credentials.php'] as $script) {
    copy($backend . '/bin/' . $script, $out . '/orakuls/bin/' . $script);
    $files++;
}
$files += copyTree($backend . '/deploy/public_html', $out . '/public_html');
// Тимчасовий діагностичний скрипт — ПОЗА public_html: його заливають вручну (з ключем) лише на час першої перевірки й одразу видаляють
$files += copyTree($backend . '/deploy/diagnostics', $out . '/diagnostics');

$frontend = null;
if (isset($options['frontend'])) {
    $frontend = rtrim(str_replace('\\', '/', $options['frontend']), '/');
    if (!is_file($frontend . '/index.html') || !is_file($frontend . '/admin/index.html')) {
        fwrite(STDERR, "Frontend build not found (expected index.html and admin/index.html in {$frontend})\n");
        exit(2);
    }
    foreach (scandir($frontend) ?: [] as $entry) {
        if ($entry === 'api' || $entry === '.htaccess') {
            fwrite(STDERR, "Frontend build contains '{$entry}', which would overwrite the backend files\n");
            exit(2);
        }
    }
    $files += copyTree($frontend, $out . '/public_html');
}

echo "Release built: {$out}\n";
echo "  orakuls/      -> ~/orakuls (above public_html)\n";
echo "  diagnostics/  -> temporary: upload diag.php only for the first check (set DIAG_KEY), delete right after
";
echo "  public_html/  -> ~/public_html" . ($frontend === null ? ' (backend only; the frontend is deployed separately)' : ' (backend + frontend)') . "\n";
echo "  files: {$files}\n";
echo "Next: docs/deployment.md (upload, .env, migrations, checks).\n";
