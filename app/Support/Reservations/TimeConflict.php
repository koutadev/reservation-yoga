<?php

namespace App\Support\Reservations;

use App\Enums\LessonSlotStatus;
use App\Models\LessonSlot;
use App\Models\Reservation;

/**
 * 「その会員が、この枠と時間の重なる別の枠を押さえているか」の判定。
 *
 * 重なりは「開始 < 相手の終了 かつ 終了 > 相手の開始」で見る。
 * 10:00–11:00 と 11:00–12:00 のように境界が一致するだけの隣接は重複としない。
 *
 * 予約するとき（ReservationBooking）と、キャンセル待ちを繰り上げるとき
 * （WaitlistPromotion）の両方で同じ判定を使う。
 */
final class TimeConflict
{
    public static function existsFor(LessonSlot $slot, int $userId): bool
    {
        return Reservation::query()
            ->occupying()
            ->where('user_id', $userId)
            ->whereHas('lessonSlot', function ($query) use ($slot): void {
                $query->whereKeyNot($slot->id)
                    // 中止になった枠の予約は連鎖キャンセル済みだが、念のため除いておく
                    ->where('status', '!=', LessonSlotStatus::Canceled->value)
                    ->where('starts_at', '<', $slot->ends_at)
                    ->where('ends_at', '>', $slot->starts_at);
            })
            ->exists();
    }
}
