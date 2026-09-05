<?php

namespace Tests\Feature;

use App\Enums\BookingDenial;
use App\Enums\RoleName;
use App\Models\LessonSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Reservations\BookingDenied;
use App\Support\Reservations\ReservationBooking;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * STEP4: 予約の同時実行（このシステムの核）。
 *
 * 「2 人が同時に最後の 1 席を取ろうとしても、席は 1 つしか出ない」ことを、
 * 実際に 2 本のトランザクションを張って確かめる。
 *
 * ほかのテストのように RefreshDatabase（テスト全体を 1 つのトランザクションで
 * 包む）を使うと、もう一方のコネクションからデータが見えず競合を再現できない。
 * そのためこのクラスだけは DatabaseMigrations（毎回作り直し）を使う。
 *
 * 進み方を決めるために、後続側には lock_timeout を掛けている。
 * 行ロックを取らない実装なら待たずに読めてしまい、この時間切れは起きない。
 */
class ReservationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

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

    private function member(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Member->value);

        return $user;
    }

    private function slot(int $capacity = 1, string $startsAt = '2026-09-10 10:00', string $endsAt = '2026-09-10 11:00'): LessonSlot
    {
        return LessonSlot::factory()->create([
            'capacity' => $capacity,
            'starts_at' => Carbon::parse($startsAt),
            'ends_at' => Carbon::parse($endsAt),
        ]);
    }

    /**
     * 先行トランザクション: 枠の行をロックし、席を 1 つ埋める（コミットはしない）。
     */
    private function rivalTakesSeat(LessonSlot $slot, User $user): void
    {
        $rival = DB::connection(self::RIVAL);

        $rival->beginTransaction();
        $rival->select('select id from lesson_slots where id = ? for update', [$slot->id]);

        $rival->table('reservations')->insert([
            'code' => 'RSV-2026-9001',
            'lesson_slot_id' => $slot->id,
            'user_id' => $user->id,
            'status' => 'reserved',
            'reserved_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function only_one_of_two_racing_bookings_gets_the_last_seat(): void
    {
        $slot = $this->slot(capacity: 1);
        $winner = $this->member();
        $loser = $this->member();

        $this->rivalTakesSeat($slot, $winner);

        // 後続は枠の行ロックを待つ（＝古い残枠のまま INSERT に進めない）
        DB::statement("set lock_timeout = '500ms'");

        $thrown = null;

        try {
            ReservationBooking::book($slot, $loser);
        } catch (Throwable $exception) {
            $thrown = $exception;
        } finally {
            DB::statement('set lock_timeout = 0');
        }

        $this->assertInstanceOf(
            QueryException::class,
            $thrown,
            '行ロックを待たずに予約が進んでしまった（定員超過の原因になる）。',
        );

        // 55P03 = lock_not_available（待っている途中で時間切れ）
        $this->assertSame('55P03', (string) $thrown->getCode());

        // 待たされたのは「枠の行ロック」。ここで待つからこそ、残枠を数えるのは
        // 先行トランザクションが終わったあとになる（先に数えてから INSERT で待つ形だと、
        // 古い残枠のまま席を作ってしまう）
        $sql = strtolower($thrown->getSql());

        $this->assertStringContainsString('lesson_slots', $sql);
        $this->assertStringContainsString('for update', $sql);

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
        $slot = $this->slot(capacity: 1);
        $first = $this->member();
        $second = $this->member();

        $this->rivalTakesSeat($slot, $first);

        // 先行が失敗して取り消された（席は空いたまま）
        DB::connection(self::RIVAL)->rollBack();

        $reservation = ReservationBooking::book($slot, $second);

        $this->assertSame($second->id, $reservation->user_id);
        $this->assertSame(1, $slot->activeReservations()->count());
    }

    #[Test]
    public function a_lock_on_one_lesson_does_not_block_another_lesson(): void
    {
        $locked = $this->slot(capacity: 1, startsAt: '2026-09-10 10:00', endsAt: '2026-09-10 11:00');
        $other = $this->slot(capacity: 1, startsAt: '2026-09-10 12:00', endsAt: '2026-09-10 13:00');

        $this->rivalTakesSeat($locked, $this->member());

        // 押さえているのは枠の行だけなので、別の枠の予約は待たされない
        DB::statement("set lock_timeout = '500ms'");

        $reservation = ReservationBooking::book($other, $this->member());

        DB::statement('set lock_timeout = 0');

        $this->assertSame($other->id, $reservation->lesson_slot_id);

        DB::connection(self::RIVAL)->rollBack();
    }
}
