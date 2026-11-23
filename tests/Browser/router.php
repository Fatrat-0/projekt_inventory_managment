<?php

require __DIR__.'/environment.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath($root.'/public/'.rawurldecode($path));
if ($file && str_starts_with($file, realpath($root.'/public').DIRECTORY_SEPARATOR)
    && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') return false;
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($runtime.'/storage');
// Use a browser-context clock only on this isolated test server.
$testTime = $_COOKIE['inventory_browser_test_time'] ?? '';
if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $testTime)) {
    Illuminate\Support\Carbon::setTestNow($testTime);
    // Keep browser cookies and the file-backed session valid when testing arbitrary calendar dates.
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app) {
        $app['config']->set(['session.expire_on_close' => true, 'session.lifetime' => 5256000]);
    });
}
$app->handleRequest(Illuminate\Http\Request::capture());
