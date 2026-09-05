<?php

namespace App\Support\Reservations;

use App\Enums\CancellationDenial;
use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 予約のキャンセルと、それに続くキャンセル待ちの繰り上げ（設計書 6.）。
 *
 * 「席を空ける」と「空いた席を次の人へ回す」は 1 つの出来事なので、
 * 予約と同じように枠の行を FOR UPDATE で押さえたまま、同じトランザクションで行う。
 * こうしておくと、同時に 2 件キャンセルされても
 * 「1 席しか空いていないのに 2 人繰り上がる」ことが起きない。
 *
 * キャンセルできる期限は config/reservation.php の cancel_deadline_hours（既定 2 時間前）。
 */
final class ReservationCancellation
{
    /**
     * 予約をキャンセルし、空いた席を繰り上げる。
     *
     * @throws CancellationDenied キャンセルを受け付けられないとき（理由つき）
     */
    public static function cancel(Reservation $reservation): CancellationResult
    {
        return DB::transaction(function () use ($reservation): CancellationResult {
            // 枠を押さえる。以降、この枠の予約・キャンセル・繰り上げは 1 つずつしか進めない
            $slot = LessonSlot::query()->lockForUpdate()->find($reservation->lesson_slot_id);

            if ($slot === null) {
                throw CancellationDenied::because(CancellationDenial::SlotCanceled);
            }

            // 予約の行も押さえる（同じ予約を二重にキャンセルしない）
            $target = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            self::assertCancelable($slot, $target);

            $target->update([
                'status' => ReservationStatus::Canceled,
                'canceled_at' => now(),
            ]);

            // 空いた 1 席を、待ち行列の先頭から繰り上げられる人へ回す
            $promoted = WaitlistPromotion::promoteOne($slot);

            return new CancellationResult($target, $promoted);
        });
    }

    /**
     * この予約をいまキャンセルしてよいか（確定時のサーバ判定）。
     */
    private static function assertCancelable(LessonSlot $slot, Reservation $reservation): void
    {
        if ($reservation->isCanceled()) {
            throw CancellationDenied::because(CancellationDenial::AlreadyCanceled);
        }

        if ($slot->status === LessonSlotStatus::Canceled) {
            throw CancellationDenied::because(CancellationDenial::SlotCanceled);
        }

        if ($slot->starts_at->isPast()) {
            throw CancellationDenied::because(CancellationDenial::AlreadyStarted);
        }

        if (now()->greaterThan(self::deadlineFor($slot))) {
            throw CancellationDenied::because(CancellationDenial::DeadlinePassed);
        }
    }

    /**
     * この枠のキャンセル期限（開始の N 時間前）。
     */
    public static function deadlineFor(LessonSlot $slot): Carbon
    {
        return $slot->starts_at->copy()->subHours(self::deadlineHours());
    }

    /**
     * 期限内にあるか（画面の出し分けにも使う）。
     */
    public static function isWithinDeadline(LessonSlot $slot): bool
    {
        return now()->lessThanOrEqualTo(self::deadlineFor($slot));
    }

    public static function deadlineHours(): int
    {
        return (int) config('reservation.cancel_deadline_hours', 2);
    }
}
