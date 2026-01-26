<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// maintenance
if (file_exists($maintenance = __DIR__.'/../../task-time-calc/storage/framework/maintenance.php')) {
    require $maintenance;
}

// autoload
require __DIR__.'/../../task-time-calc/vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../../task-time-calc/bootstrap/app.php';

$app->handleRequest(Request::capture());