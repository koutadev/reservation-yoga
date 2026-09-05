<?php

namespace App\Support\Reservations;

use App\Enums\BookingDenial;
use App\Enums\LessonSlotStatus;
use App\Enums\WaitlistStatus;
use App\Models\LessonSlot;
use App\Models\User;
use App\Models\Waitlist;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * キャンセル待ちへの登録・取り消し（設計書 6.）。
 *
 * 待ち順（position）は枠の中の連番で、DB でも「待機中は枠内で重複しない」よう
 * 部分ユニークにしてある。採番のときに同じ番号を 2 人に配らないよう、
 * 予約と同じく枠の行を FOR UPDATE で押さえてから採番する。
 *
 * 空きがあるうちは並ばせない（そのまま予約すればよい）。
 */
final class WaitlistRegistration
{
    /**
     * キャンセル待ちに並ぶ。
     *
     * @throws BookingDenied 受け付けられないとき（理由つき）
     */
    public static function join(LessonSlot $slot, User $user): Waitlist
    {
        return DB::transaction(function () use ($slot, $user): Waitlist {
            $locked = LessonSlot::query()->lockForUpdate()->find($slot->id);

            if ($locked === null) {
                throw BookingDenied::because(BookingDenial::SlotCanceled);
            }

            self::assertSlotAcceptsWaiting($locked);
            self::assertNotReserved($locked, $user);
            self::assertNotWaitingYet($locked, $user);
            self::assertFull($locked);

            try {
                return Waitlist::create([
                    'lesson_slot_id' => $locked->id,
                    'user_id' => $user->id,
                    'position' => self::nextPosition($locked),
                    'status' => WaitlistStatus::Waiting,
                    'requested_at' => now(),
                    'is_active' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                // 同じ枠 × 同じ会員の待機、または待ち順の重複。どちらも DB が最後に弾く
                throw BookingDenied::because(BookingDenial::AlreadyWaiting);
            }
        });
    }

    /**
     * キャンセル待ちを取り下げる（並び直しはできる）。
     */
    public static function withdraw(Waitlist $waitlist): void
    {
        DB::transaction(function () use ($waitlist): void {
            // 繰り上げの最中に取り下げが割り込まないよう、枠を押さえてから畳む
            LessonSlot::query()->lockForUpdate()->find($waitlist->lesson_slot_id);

            $target = Waitlist::query()->lockForUpdate()->findOrFail($waitlist->id);

            if ($target->status !== WaitlistStatus::Waiting) {
                // すでに繰り上がった／取り消し済み。何もしない（画面には最新の状態が出る）
                return;
            }

            $target->update(['status' => WaitlistStatus::Canceled]);
        });
    }

    /**
     * この枠の次の待ち順。
     *
     * 繰り上げ済み・取り消し済みは番号を空けたままにして、待機中の最大 + 1 を配る。
     * 枠をロックしたあとに採番するので、同じ番号が 2 人に渡ることはない。
     */
    private static function nextPosition(LessonSlot $slot): int
    {
        $max = $slot->waitlists()
            ->where('status', WaitlistStatus::Waiting->value)
            ->max('position');

        return (int) $max + 1;
    }

    private static function assertSlotAcceptsWaiting(LessonSlot $slot): void
    {
        if ($slot->status === LessonSlotStatus::Canceled) {
            throw BookingDenied::because(BookingDenial::SlotCanceled);
        }

        if (! $slot->status->acceptsReservation()) {
            throw BookingDenied::because(BookingDenial::ReceptionClosed);
        }

        if ($slot->starts_at->isPast()) {
            throw BookingDenied::because(BookingDenial::AlreadyStarted);
        }
    }

    private static function assertNotReserved(LessonSlot $slot, User $user): void
    {
        $reserved = $slot->activeReservations()
            ->where('user_id', $user->id)
            ->exists();

        if ($reserved) {
            throw BookingDenied::because(BookingDenial::AlreadyReserved);
        }
    }

    private static function assertNotWaitingYet(LessonSlot $slot, User $user): void
    {
        $waiting = $slot->waitlists()
            ->where('status', WaitlistStatus::Waiting->value)
            ->where('user_id', $user->id)
            ->exists();

        if ($waiting) {
            throw BookingDenied::because(BookingDenial::AlreadyWaiting);
        }
    }

    /**
     * 満席でなければ、並ぶ必要はない（そのまま予約してもらう）。
     */
    private static function assertFull(LessonSlot $slot): void
    {
        if ($slot->activeReservations()->count() < $slot->capacity) {
            throw BookingDenied::because(BookingDenial::SeatsAvailable);
        }
    }
}
