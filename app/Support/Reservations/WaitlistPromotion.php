<?php

namespace App\Support\Reservations;

use App\Enums\ReminderChannel;
use App\Enums\ReservationStatus;
use App\Enums\WaitlistStatus;
use App\Models\LessonSlot;
use App\Models\Reminder;
use App\Models\Reservation;
use App\Models\Waitlist;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * キャンセル待ちの繰り上げ（設計書 6.）。
 *
 * 空きが 1 件できたときに、待ち行列の先頭から見て「いま繰り上げられる最初の 1 人」を
 * 繰上確定にする。1 回の呼び出しで繰り上がるのは 1 人だけで、空いた席の数を超えない。
 *
 * **呼び出し側が枠の行を FOR UPDATE で押さえていること**が前提。
 * キャンセルと繰り上げを同じロック・同じトランザクションで行うことで、
 * 「同時に 2 件キャンセル → 1 席しかないのに 2 人繰り上がる」を防ぐ。
 *
 * 繰り上げの通知は reminders に予定として積む（実送信は拡張点）。
 */
final class WaitlistPromotion
{
    /**
     * 繰り上げ可能な先頭の 1 人を繰上確定にする。
     *
     * 繰り上げなかった場合（空きがない／待機者がいない／全員が時間帯の重複で繰り上げ
     * られない）は null を返し、席は空いたままにする。
     */
    public static function promoteOne(LessonSlot $slot): ?Reservation
    {
        // 席が空いているかは、その場で数え直す（一覧で withCount した値は使わない）
        if ($slot->activeReservations()->count() >= $slot->capacity) {
            return null;
        }

        // 待ち順に並べて押さえる（同時に走るキャンセルと取り合わない）
        $waiting = $slot->waitlists()
            ->where('status', WaitlistStatus::Waiting->value)
            ->orderBy('position')
            ->lockForUpdate()
            ->get();

        foreach ($waiting as $waitlist) {
            if (! self::canPromote($slot, $waitlist)) {
                // 例えば、待っている間に同じ時間帯の別レッスンを予約した人。
                // その人は飛ばして次の人を見る（待ち行列からは外さない）
                continue;
            }

            return self::promote($slot, $waitlist);
        }

        return null;
    }

    /**
     * この待機者を、いま繰り上げてよいか。
     */
    private static function canPromote(LessonSlot $slot, Waitlist $waitlist): bool
    {
        // すでにこの枠の席を持っている（通常は起きないが、二重に席を作らない）
        $hasSeat = $slot->activeReservations()
            ->where('user_id', $waitlist->user_id)
            ->exists();

        if ($hasSeat) {
            return false;
        }

        // 時間帯の重なる別の枠を押さえている人は繰り上げない（予約時と同じ判定）
        return ! TimeConflict::existsFor($slot, $waitlist->user_id);
    }

    /**
     * 繰上確定にする（待ち行列を繰上済にし、席を占める予約を作る）。
     */
    private static function promote(LessonSlot $slot, Waitlist $waitlist): ?Reservation
    {
        try {
            $reservation = Reservation::create([
                'lesson_slot_id' => $slot->id,
                'user_id' => $waitlist->user_id,
                'status' => ReservationStatus::Promoted,
                'reserved_at' => now(),
                'is_active' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // 同じ枠 × 同じ会員の予約がすでにある場合（部分ユニーク）。
            // 繰り上げる必要がなかったということなので、待ち行列だけ畳んで終わる
            $waitlist->update(['status' => WaitlistStatus::Promoted]);

            return null;
        }

        $waitlist->update(['status' => WaitlistStatus::Promoted]);

        self::scheduleNotice($slot, $reservation);

        return $reservation;
    }

    /**
     * 繰り上げの連絡を予定として残す。
     *
     * 実送信は拡張点。ここでは「いつ・どの予約に・どの手段で送るか」だけを積む
     * （前日リマインドの生成は STEP6）。
     */
    private static function scheduleNotice(LessonSlot $slot, Reservation $reservation): void
    {
        Reminder::create([
            'lesson_slot_id' => $slot->id,
            'reservation_id' => $reservation->id,
            // 繰り上げは早く知らせたいので、送信予定は「いま」
            'scheduled_at' => now(),
            'sent_at' => null,
            'channel' => ReminderChannel::Email,
            'is_active' => true,
        ]);
    }
}
