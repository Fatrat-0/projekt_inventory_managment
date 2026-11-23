<?php

$root = dirname(__DIR__, 2);
$runtime = realpath(getenv('INVENTORY_BROWSER_RUNTIME') ?: '');
if (!$runtime || !preg_match('/^inventory-browser-[A-Za-z0-9]+$/D', basename($runtime))
    || dirname($runtime) !== realpath(sys_get_temp_dir())) {
    throw new RuntimeException('Browser tests require a temporary directory allocated by their runner.');
}
foreach (['storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache', 'storage/logs'] as $folder) {
    if (!is_dir($runtime.'/'.$folder)) mkdir($runtime.'/'.$folder, 0777, true);
}
$keyFile = $runtime.'/app-key';
if (!file_exists($keyFile)) file_put_contents($keyFile, 'base64:'.base64_encode(random_bytes(32)));
$database = $runtime.'/database.sqlite';
if (!file_exists($database)) touch($database);
$port = filter_var(getenv('INVENTORY_BROWSER_PORT') ?: '8019', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
if ($port === false) throw new RuntimeException('Invalid isolated browser server port.');
$env = [
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:'.$port,
    'APP_KEY' => trim(file_get_contents($keyFile)),
    'VIEW_COMPILED_PATH' => $runtime.'/storage/framework/views',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'inventory_browser_tests',
    'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
];
foreach ($env as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
