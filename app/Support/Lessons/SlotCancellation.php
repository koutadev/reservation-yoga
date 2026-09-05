<?php

namespace App\Support\Lessons;

use App\Enums\ReservationStatus;
use App\Enums\WaitlistStatus;
use App\Models\LessonSlot;
use App\Models\Reservation;
use App\Models\Waitlist;
use Illuminate\Support\Facades\DB;

/**
 * レッスン枠を中止したときの連鎖処理（STEP2-2）。
 *
 * 枠が中止になったら、その枠に入っていた予約とキャンセル待ちも意味を失うため、
 * まとめてキャンセル扱いにする。途中で失敗して「枠だけ中止・予約は残る」状態に
 * ならないよう、枠の行をロックしたうえで 1 つのトランザクションで行う。
 *
 * キャンセル待ちの繰り上げ（STEP5）はここでは行わない。
 * 枠そのものが無くなる以上、繰り上げる先が無いため。
 */
final class SlotCancellation
{
    /**
     * 枠に紐づく予約・キャンセル待ちをキャンセル扱いにする。
     *
     * すでに中止済みの枠に対して呼んでも安全（対象が無ければ 0 件を返す）。
     */
    public static function apply(LessonSlot $slot): SlotCancellationResult
    {
        return DB::transaction(function () use ($slot): SlotCancellationResult {
            // 同時に予約が入ってくるのを防ぐため、まず枠の行を押さえる
            LessonSlot::query()->lockForUpdate()->find($slot->id);

            $canceledAt = now();

            $reservations = 0;

            /** @var Reservation $reservation */
            foreach ($slot->reservations()->occupying()->lockForUpdate()->get() as $reservation) {
                $reservation->update([
                    'status' => ReservationStatus::Canceled,
                    'canceled_at' => $canceledAt,
                ]);

                $reservations++;
            }

            $waitlists = 0;

            /** @var Waitlist $waitlist */
            foreach ($slot->waitlists()->where('status', WaitlistStatus::Waiting->value)->lockForUpdate()->get() as $waitlist) {
                $waitlist->update(['status' => WaitlistStatus::Canceled]);

                $waitlists++;
            }

            return new SlotCancellationResult($reservations, $waitlists);
        });
    }
}
