<?php

namespace App\Support\Reminders;

use App\Enums\LessonSlotStatus;
use App\Enums\ReminderChannel;
use App\Enums\ReminderType;
use App\Models\LessonSlot;
use App\Models\Reminder;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * リマインドの送信予定の管理（設計書 3. / 7.）。
 *
 * このシステムが持つのは「いつ・どの予約に・どの手段で送る予定か」と「送ったか」まで。
 * **実際の送信は拡張点**で、送る側は {@see Reminder::scopeDue()} で予定を拾い、
 * 送れたら sent_at を入れる、という流れを想定している。
 *
 *   - 前日リマインド … 日次コマンド（reminders:schedule）でまとめて予定を作る
 *   - 繰り上げの連絡 … 繰り上げたその場で予定を作る（すぐ知らせたいので予定は「いま」）
 *
 * 予約がキャンセルされたら、その予約あての未送信の予定は無効（is_active = false）にする。
 */
final class ReminderSchedule
{
    /** 前日リマインドを送る時刻（前日の 20:00） */
    private const REMINDER_HOUR = 20;

    /**
     * 前日リマインドの送信予定時刻。
     *
     * 直前に入った予約で「前日 20 時」がもう過ぎている場合は、いま送る予定にする。
     */
    public static function lessonReminderAt(LessonSlot $slot, ?Carbon $now = null): Carbon
    {
        $now ??= now();
        $scheduledAt = $slot->starts_at->copy()->subDay()->setTime(self::REMINDER_HOUR, 0);

        return $scheduledAt->lessThan($now) ? $now->copy() : $scheduledAt;
    }

    /**
     * 前日リマインドの予定を作る（すでにある予約は作り直さない）。
     *
     * 対象は「これから開催される枠の、席を占めている予約」。
     *
     * @param  int  $days  何日先の枠まで予定を作るか
     * @return int 作った件数
     */
    public static function scheduleLessonReminders(int $days = 2): int
    {
        $now = now();
        $until = $now->copy()->addDays($days);

        $reservations = Reservation::query()
            ->occupying()
            ->whereHas('lessonSlot', fn ($query) => $query
                ->whereIn('status', LessonSlotStatus::browsableValues())
                ->whereBetween('starts_at', [$now, $until]))
            // すでに生きている予定があるものは飛ばす（何度実行しても増えない）
            ->whereDoesntHave('reminders', function (Builder $query): void {
                /** @var Builder<Reminder> $query */
                $query->ofType(ReminderType::LessonReminder)->pending();
            })
            ->with('lessonSlot:id,starts_at')
            ->get();

        if ($reservations->isEmpty()) {
            return 0;
        }

        $rows = $reservations
            ->filter(static fn (Reservation $reservation): bool => $reservation->lessonSlot !== null)
            ->map(static fn (Reservation $reservation): array => [
                'lesson_slot_id' => $reservation->lesson_slot_id,
                'reservation_id' => $reservation->id,
                'type' => ReminderType::LessonReminder->value,
                'scheduled_at' => self::lessonReminderAt($reservation->lessonSlot, $now),
                'sent_at' => null,
                'channel' => ReminderChannel::Email->value,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        // 定期実行の一括生成なので、操作ログには残さず 1 回の INSERT でまとめて作る
        Reminder::query()->insert($rows);

        return count($rows);
    }

    /**
     * 繰り上げの連絡の予定を作る（すぐ知らせたいので送信予定は「いま」）。
     */
    public static function promotionNotice(LessonSlot $slot, Reservation $reservation): Reminder
    {
        return Reminder::create([
            'lesson_slot_id' => $slot->id,
            'reservation_id' => $reservation->id,
            'type' => ReminderType::PromotionNotice,
            'scheduled_at' => now(),
            'sent_at' => null,
            'channel' => ReminderChannel::Email,
            'is_active' => true,
        ]);
    }

    /**
     * その予約あての、まだ送っていない予定を無効にする。
     *
     * キャンセルされた予約や、中止になった枠の予約に対して送らないため。
     * 送信済み（sent_at あり）の記録は履歴として残す。
     *
     * @param  list<int>|int  $reservationIds
     * @return int 無効にした件数
     */
    public static function deactivateFor(array|int $reservationIds): int
    {
        $ids = is_array($reservationIds) ? $reservationIds : [$reservationIds];

        if ($ids === []) {
            return 0;
        }

        return Reminder::query()
            ->whereIn('reservation_id', $ids)
            ->pending()
            ->update(['is_active' => false]);
    }
}
