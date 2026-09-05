<?php

namespace App\Console\Commands;

use App\Support\Reminders\ReminderSchedule;
use Illuminate\Console\Command;

/**
 * 前日リマインドの送信予定をまとめて作る（日次で実行する想定）。
 *
 *   docker compose exec app php artisan reminders:schedule
 *   docker compose exec app php artisan reminders:schedule --days=3
 *
 * 何度実行しても、まだ予定が無い予約にだけ作る（重複しない）。
 * **実際の送信は拡張点**で、このコマンドは予定を作るところまでを受け持つ。
 */
class ScheduleReminders extends Command
{
    protected $signature = 'reminders:schedule {--days=2 : 何日先の枠まで送信予定を作るか}';

    protected $description = 'これから開催されるレッスンの予約に、前日リマインドの送信予定を作る';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $created = ReminderSchedule::scheduleLessonReminders($days);

        $this->info(sprintf('%d 日先までの枠に、リマインドの送信予定を %d 件作りました。', $days, $created));
        $this->line('※ 実際の送信は拡張点です（送信予定の管理まで）。');

        return self::SUCCESS;
    }
}
