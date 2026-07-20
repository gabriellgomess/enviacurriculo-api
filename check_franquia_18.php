<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$f = \DB::table('franquias')->where('id', 18)->first();
echo json_encode($f, JSON_PRETTY_PRINT);
