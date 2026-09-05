<?php

namespace App\Support\Reservations;

use App\Enums\BookingDenial;
use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 予約の確定（このシステムの核。設計書 6.）。
 *
 * 定員超過も二重予約も「起きてから直す」ことができないので、次の 3 段で守る。
 *
 *   1. 枠の行を SELECT ... FOR UPDATE で押さえる
 *      同じ枠への予約はここで直列化されるため、2 人が同時に最後の 1 席を
 *      見ることがない。ロックは INSERT のあとコミットまで保持される。
 *   2. ロックを取ったあとに、状態・開始時刻・定員・重複をサーバ側で数え直す
 *      一覧や詳細の表示は古くなりうるので、確定時の判定だけを信用する。
 *   3. それでも通り抜けた同時実行は DB の部分ユニーク制約が弾く
 *      （同じ枠 × 同じ会員で席を占める予約は 1 件だけ）。
 *
 * 満席のときはここで断る。キャンセル待ちへの登録は STEP5。
 */
final class ReservationBooking
{
    /**
     * 予約を確定する。
     *
     * @throws BookingDenied 予約を受け付けられないとき（理由つき）
     */
    public static function book(LessonSlot $slot, User $user): Reservation
    {
        return DB::transaction(function () use ($slot, $user): Reservation {
            // (1) 枠の行を押さえる。ここから先、この枠への予約は 1 つずつしか進めない
            $locked = LessonSlot::query()->lockForUpdate()->find($slot->id);

            if ($locked === null) {
                // ロックを取る間に枠が消えた（削除）場合は、中止と同じ扱いにする
                throw BookingDenied::because(BookingDenial::SlotCanceled);
            }

            // (2) 確定時のサーバ再判定
            self::assertSlotAcceptsReservation($locked);
            self::assertNotReservedYet($locked, $user);
            self::assertNoTimeConflict($locked, $user);
            self::assertHasFreeSeat($locked);

            try {
                return Reservation::create([
                    'lesson_slot_id' => $locked->id,
                    'user_id' => $user->id,
                    'status' => ReservationStatus::Reserved,
                    'reserved_at' => now(),
                    'is_active' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                // (3) 同じ枠 × 同じ会員の同時実行は、DB の部分ユニークが最後の砦
                throw BookingDenied::because(BookingDenial::AlreadyReserved);
            }
        });
    }

    /**
     * 枠そのものが予約を受け付ける状態か（中止・締切・開始済みを弾く）。
     */
    private static function assertSlotAcceptsReservation(LessonSlot $slot): void
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

    /**
     * 同じ枠をすでに予約していないか。
     *
     * DB の部分ユニークでも守っているが、会員に理由を返せるよう先に確かめる。
     */
    private static function assertNotReservedYet(LessonSlot $slot, User $user): void
    {
        $exists = $slot->activeReservations()
            ->where('user_id', $user->id)
            ->exists();

        if ($exists) {
            throw BookingDenied::because(BookingDenial::AlreadyReserved);
        }
    }

    /**
     * 時間帯の重なる別の枠を予約していないか。
     *
     * 重なりは「開始 < 相手の終了 かつ 終了 > 相手の開始」で判定する。
     * 10:00–11:00 と 11:00–12:00 のように境界が一致するだけの隣接は重複としない。
     */
    private static function assertNoTimeConflict(LessonSlot $slot, User $user): void
    {
        $conflicts = Reservation::query()
            ->occupying()
            ->where('user_id', $user->id)
            ->whereHas('lessonSlot', function ($query) use ($slot): void {
                $query->whereKeyNot($slot->id)
                    // 中止になった枠の予約は連鎖キャンセル済みだが、念のため除いておく
                    ->where('status', '!=', LessonSlotStatus::Canceled->value)
                    ->where('starts_at', '<', $slot->ends_at)
                    ->where('ends_at', '>', $slot->starts_at);
            })
            ->exists();

        if ($conflicts) {
            throw BookingDenied::because(BookingDenial::TimeConflict);
        }
    }

    /**
     * 残席があるか（定員 − 席を占める予約数）。
     *
     * 枠をロックしたあとに数えるので、ここで数えた値はコミットまで動かない。
     */
    private static function assertHasFreeSeat(LessonSlot $slot): void
    {
        if ($slot->activeReservations()->count() >= $slot->capacity) {
            throw BookingDenied::because(BookingDenial::Full);
        }
    }
}
