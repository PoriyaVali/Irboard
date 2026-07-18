<?php
// Print the panel's domain. Used by _server_configs/restore.sh (aaPanel) to
// locate the site's nginx vhost. Resolves the domain from, in order:
//   1) config/v2board.php -> app_url  (set by the installer / admin settings)
//   2) .env -> APP_URL
//   3) the install directory name     (aaPanel puts sites at /www/wwwroot/<domain>)

$url = '';

$cfg = @include __DIR__ . '/config/v2board.php';
if (is_array($cfg) && !empty($cfg['app_url'])) {
    $url = $cfg['app_url'];
}

if ($url === '' && is_file(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
        if (strncmp($line, 'APP_URL=', 8) === 0) {
            $url = trim(substr($line, 8), " \"'");
            break;
        }
    }
}

$host = $url !== '' ? parse_url($url, PHP_URL_HOST) : '';
echo $host ?: basename(__DIR__);
