<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Franquia;
use App\Models\Vaga;

$vaga = Vaga::find(8);
echo "Vaga 8 created_at: {$vaga->created_at}\n\n";

$franchises = Franquia::get();
foreach ($franchises as $f) {
    echo "Franquia: {$f->nome} (ID: {$f->id}) created_at: {$f->created_at}\n";
}
