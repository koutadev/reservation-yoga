<?php

namespace Tests\Feature;

use App\Enums\BookingDenial;
use App\Enums\ReservationStatus;
use App\Enums\WaitlistStatus;
use App\Models\LessonSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Reservations\BookingDenied;
use App\Support\Reservations\ReservationBooking;
use App\Support\Reservations\ReservationCancellation;
use App\Support\Reservations\WaitlistRegistration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;
use Throwable;

/**
 * STEP4 / STEP5: 予約・キャンセル・繰り上げの同時実行（このシステムの核）。
 *
 * 「2 人が同時に最後の 1 席を取ろうとしても席は 1 つしか出ない」「同時に 2 件
 * キャンセルされても、繰り上がるのは空いた席の数だけ」を、実際に 2 本の
 * トランザクションを張って確かめる。
 *
 * ほかのテストのように RefreshDatabase（テスト全体を 1 つのトランザクションで
 * 包む）を使うと、もう一方のコネクションからデータが見えず競合を再現できない。
 * そのためこのクラスだけは DatabaseMigrations（毎回作り直し）を使う。
 *
 * 進み方を決めるために、後続側には lock_timeout を掛けている。
 * 枠の行ロックを取らない実装なら待たずに進めてしまい、この時間切れは起きない。
 */
class ReservationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use MakesReservations;

    /** 競合相手（別のトランザクション）に使うコネクション名 */
    private const RIVAL = 'rival';

    private const NOW = '2026-09-08 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);

        // 同じ DB へのもう 1 本のコネクション（別トランザクションを張るため）
        config([
            'database.connections.'.self::RIVAL => config('database.connections.'.config('database.default')),
        ]);
    }

    protected function tearDown(): void
    {
        // 途中で失敗しても、握ったままのロックを必ず手放す
        DB::connection(self::RIVAL)->disconnect();
        DB::statement('set lock_timeout = 0');

        Carbon::setTestNow();

        parent::tearDown();
    }

    private function lesson(int $capacity = 1, string $startsAt = '2026-09-10 10:00', string $endsAt = '2026-09-10 11:00'): LessonSlot
    {
        return $this->slot($startsAt, $endsAt, ['capacity' => $capacity]);
    }

    /**
     * 先行トランザクションを始め、枠の行を押さえる（コミットはしない）。
     */
    private function rivalLocksSlot(LessonSlot $slot): void
    {
        $rival = DB::connection(self::RIVAL);

        $rival->beginTransaction();
        $rival->select('select id from lesson_slots where id = ? for update', [$slot->id]);
    }

    /**
     * 先行トランザクションが席を 1 つ埋める。
     */
    private function rivalTakesSeat(LessonSlot $slot, User $user, string $code = 'RSV-2026-9001'): void
    {
        DB::connection(self::RIVAL)->table('reservations')->insert([
            'code' => $code,
            'lesson_slot_id' => $slot->id,
            'user_id' => $user->id,
            'status' => ReservationStatus::Reserved->value,
            'reserved_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 後続の処理を走らせ、枠の行ロック待ちで時間切れになることを確かめる。
     *
     * 「どの文で待たされたか」まで見るのが要点。ロックを取らない実装だと、
     * 残枠を古いまま読んでから INSERT で待つことになり、この確認が落ちる。
     */
    private function assertBlockedOnSlotLock(callable $callback): void
    {
        DB::statement("set lock_timeout = '500ms'");

        $thrown = null;

        try {
            $callback();
        } catch (Throwable $exception) {
            $thrown = $exception;
        } finally {
            DB::statement('set lock_timeout = 0');
        }

        $this->assertInstanceOf(
            QueryException::class,
            $thrown,
            '枠の行ロックを待たずに処理が進んでしまった。',
        );

        // 55P03 = lock_not_available（待っている途中で時間切れ）
        $this->assertSame('55P03', (string) $thrown->getCode());

        $sql = strtolower($thrown->getSql());

        $this->assertStringContainsString('lesson_slots', $sql);
        $this->assertStringContainsString('for update', $sql);
    }

    // --- 予約（STEP4） ------------------------------------------------------

    #[Test]
    public function only_one_of_two_racing_bookings_gets_the_last_seat(): void
    {
        $slot = $this->lesson(capacity: 1);
        $winner = $this->member();
        $loser = $this->member();

        $this->rivalLocksSlot($slot);
        $this->rivalTakesSeat($slot, $winner);

        $this->assertBlockedOnSlotLock(fn () => ReservationBooking::book($slot, $loser));

        // 先行はまだ未確定なので、この時点で確定している予約は 1 件もない
        $this->assertSame(0, Reservation::query()->count(), '待たされている側が席を作ってしまっている。');

        // 先行が確定すると、後続は数え直した結果「満席」で断られる
        DB::connection(self::RIVAL)->commit();

        try {
            ReservationBooking::book($slot, $loser);

            $this->fail('定員 1 の枠に 2 件目の予約が入ってしまった。');
        } catch (BookingDenied $denied) {
            $this->assertSame(BookingDenial::Full, $denied->reason);
        }

        $this->assertSame(1, Reservation::query()->where('lesson_slot_id', $slot->id)->count());
        $this->assertSame($winner->id, Reservation::query()->sole()->user_id);
    }

    #[Test]
    public function the_seat_freed_by_a_rolled_back_booking_can_be_taken(): void
    {
        $slot = $this->lesson(capacity: 1);
        $second = $this->member();

        $this->rivalLocksSlot($slot);
        $this->rivalTakesSeat($slot, $this->member());

        // 先行が失敗して取り消された（席は空いたまま）
        DB::connection(self::RIVAL)->rollBack();

        $reservation = ReservationBooking::book($slot, $second);

        $this->assertSame($second->id, $reservation->user_id);
        $this->assertSame(1, $slot->activeReservations()->count());
    }

    #[Test]
    public function a_lock_on_one_lesson_does_not_block_another_lesson(): void
    {
        $locked = $this->lesson(capacity: 1);
        $other = $this->lesson(capacity: 1, startsAt: '2026-09-10 12:00', endsAt: '2026-09-10 13:00');

        $this->rivalLocksSlot($locked);
        $this->rivalTakesSeat($locked, $this->member());

        // 押さえているのは枠の行だけなので、別の枠の予約は待たされない
        DB::statement("set lock_timeout = '500ms'");

        $reservation = ReservationBooking::book($other, $this->member());

        DB::statement('set lock_timeout = 0');

        $this->assertSame($other->id, $reservation->lesson_slot_id);

        DB::connection(self::RIVAL)->rollBack();
    }

    // --- キャンセルと繰り上げ（STEP5） --------------------------------------

    #[Test]
    public function concurrent_cancellations_promote_only_as_many_people_as_seats_freed(): void
    {
        $slot = $this->lesson(capacity: 2);

        [$booked, $otherBooked] = $this->fill($slot, 2);

        $first = $this->member();
        $second = $this->member();
        $third = $this->member();

        $firstWait = $this->waiting($slot, $first, 1);
        $secondWait = $this->waiting($slot, $second, 2);
        $thirdWait = $this->waiting($slot, $third, 3);

        // 先行トランザクション: もう 1 件のキャンセルと、先頭の繰り上げを進めている
        $this->rivalLocksSlot($slot);
        $this->rivalCancels($otherBooked, $slot);
        $this->rivalPromotes($slot, $firstWait->id, $first);

        // 後続のキャンセルは、先行が終わるまで枠の行ロックで待たされる
        $reservation = Reservation::query()
            ->where('lesson_slot_id', $slot->id)
            ->where('user_id', $booked->id)
            ->sole();

        $this->assertBlockedOnSlotLock(fn () => ReservationCancellation::cancel($reservation));

        $this->assertSame(
            ReservationStatus::Reserved,
            $reservation->fresh()->status,
            '待たされている側が先にキャンセルを書き込んでしまっている。',
        );

        DB::connection(self::RIVAL)->commit();

        // 先行の結果（1 人繰り上げ済み）を見たうえで、空いた 1 席を次の人へ
        $result = ReservationCancellation::cancel($reservation);

        $this->assertSame($second->id, $result->promoted?->user_id, '2 人目が繰り上がる。');

        $this->assertSame(WaitlistStatus::Promoted, $firstWait->fresh()->status);
        $this->assertSame(WaitlistStatus::Promoted, $secondWait->fresh()->status);
        $this->assertSame(WaitlistStatus::Waiting, $thirdWait->fresh()->status, '3 人目は待ち行列に残る。');

        // 空いた席は 2 つ、繰り上がったのも 2 人。定員は超えていない
        $this->assertSame(2, $slot->activeReservations()->count());
        $this->assertSame(2, $slot->waitlists()->where('status', WaitlistStatus::Promoted->value)->count());
        $this->assertSame(
            2,
            Reservation::query()->where('status', ReservationStatus::Canceled->value)->count(),
        );
    }

    #[Test]
    public function two_people_joining_a_waitlist_at_once_do_not_share_a_position(): void
    {
        $slot = $this->lesson(capacity: 1);

        $this->fill($slot, 1);

        // 先行トランザクション: 枠を押さえて 1 番目に並んでいる
        $this->rivalLocksSlot($slot);

        DB::connection(self::RIVAL)->table('waitlists')->insert([
            'lesson_slot_id' => $slot->id,
            'user_id' => $this->member()->id,
            'position' => 1,
            'status' => WaitlistStatus::Waiting->value,
            'requested_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 後続の登録は、採番の前に枠の行ロックで待たされる
        $this->assertBlockedOnSlotLock(fn () => WaitlistRegistration::join($slot, $this->member()));

        DB::connection(self::RIVAL)->commit();

        $waitlist = WaitlistRegistration::join($slot, $this->member());

        $this->assertSame(2, $waitlist->position, '同じ待ち順を 2 人に配ってしまっている。');
        $this->assertSame([1, 2], $slot->waitlists()->waiting()->pluck('position')->all());
    }

    /**
     * 先行トランザクションが、その会員の予約をキャンセルする。
     */
    private function rivalCancels(User $user, LessonSlot $slot): void
    {
        DB::connection(self::RIVAL)
            ->table('reservations')
            ->where('lesson_slot_id', $slot->id)
            ->where('user_id', $user->id)
            ->update([
                'status' => ReservationStatus::Canceled->value,
                'canceled_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * 先行トランザクションが、待ち行列の先頭を繰り上げる。
     */
    private function rivalPromotes(LessonSlot $slot, int $waitlistId, User $user): void
    {
        $rival = DB::connection(self::RIVAL);

        $rival->table('reservations')->insert([
            'code' => 'RSV-2026-9002',
            'lesson_slot_id' => $slot->id,
            'user_id' => $user->id,
            'status' => ReservationStatus::Promoted->value,
            'reserved_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rival->table('waitlists')->where('id', $waitlistId)->update([
            'status' => WaitlistStatus::Promoted->value,
            'updated_at' => now(),
        ]);
    }
}
