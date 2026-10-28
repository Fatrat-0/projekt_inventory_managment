<?php

$root = dirname(__DIR__, 2);
$runtime = __DIR__.'/.runtime';
if (!is_dir($runtime)) mkdir($runtime, 0777, true);
foreach (['storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache', 'storage/logs'] as $folder) {
    if (!is_dir($runtime.'/'.$folder)) mkdir($runtime.'/'.$folder, 0777, true);
}
$keyFile = $runtime.'/app-key';
if (!file_exists($keyFile)) file_put_contents($keyFile, 'base64:'.base64_encode(random_bytes(32)));
$database = $runtime.'/database.sqlite';
if (!file_exists($database)) touch($database);
$env = [
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8019',
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
