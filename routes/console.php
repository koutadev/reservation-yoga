<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| 定期実行
|--------------------------------------------------------------------------
|
| リマインドの送信予定を毎日まとめて作る（実際の送信は拡張点）。
| 本番では supervisor などで `php artisan schedule:work` を動かす。
|
*/

Schedule::command('reminders:schedule')->dailyAt('18:00');
