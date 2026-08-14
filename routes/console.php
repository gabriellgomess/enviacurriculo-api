<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Agendamento diário de backup automático do banco de dados na VPS às 03:00 com retenção de 5 dias
// Schedule::command('app:backup-db --days=5')->dailyAt('03:00');
Schedule::command('app:backup-db --days=5')->everyMinute();
