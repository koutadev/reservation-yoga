<?php

namespace Tests\Feature;

use App\Enums\CancellationDenial;
use App\Enums\ReservationStatus;
use App\Enums\WaitlistStatus;
use App\Models\Reminder;
use App\Models\Reservation;
use App\Support\Reservations\CancellationDenied;
use App\Support\Reservations\ReservationBooking;
use App\Support\Reservations\ReservationCancellation;
use App\Support\Ui\Toast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

/**
 * STEP5: キャンセルと、それに続く繰り上げの検証。
 *
 * 見るのは次の 5 点。同時実行は ReservationConcurrencyTest で確かめる。
 *   1. 会員が期限内に自分の予約をキャンセルでき、席が空く
 *   2. 期限超過・開始済み・中止の枠はキャンセルできない（＝繰り上げも起きない）
 *   3. 空いた 1 席は、待ち行列の先頭から 1 人だけに回る
 *   4. 時間帯の重なる予約を持つ待機者は飛ばして次の人が繰り上がる
 *   5. 繰り上げた人には通知の予定（reminders）が積まれる
 */
class ReservationCancellationTest extends TestCase
{
    use MakesReservations;
    use RefreshDatabase;

    private const NOW = '2026-09-08 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function assertDeniedWith(CancellationDenial $reason, Reservation $reservation): void
    {
        try {
            ReservationCancellation::cancel($reservation);

            $this->fail('キャンセルが通ってしまった（期待した拒否: '.$reason->value.'）。');
        } catch (CancellationDenied $denied) {
            $this->assertSame($reason, $denied->reason);
        }
    }

    // --- キャンセルできる ---------------------------------------------------

    #[Test]
    public function a_member_cancels_their_own_reservation_and_frees_the_seat(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 2]);

        $reservation = ReservationBooking::book($slot, $member);

        $this->assertSame(1, $slot->fresh()->reservedCount());

        $response = $this->actingAs($member)->delete(route('reservations.cancel', $reservation->id));

        $response->assertRedirect(route('lessons.show', $slot->id));
        $response->assertSessionHas(Toast::SESSION_KEY, fn (array $toast): bool => $toast['type'] === 'success');

        $reservation->refresh();

        $this->assertSame(ReservationStatus::Canceled, $reservation->status);
        $this->assertNotNull($reservation->canceled_at);
        $this->assertSame(0, $slot->fresh()->reservedCount(), '席が空いている。');
    }

    #[Test]
    public function the_detail_screen_offers_the_cancel_action_to_the_person_who_booked(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00');

        $reservation = ReservationBooking::book($slot, $member);

        $this->actingAs($member)
            ->get(route('lessons.show', $slot->id))
            ->assertOk()
            ->assertSee('予約をキャンセルする')
            ->assertSee(route('reservations.cancel', $reservation->id), false)
            ->assertSee('キャンセルは開始 2 時間前まで受け付けます。');
    }

    // --- キャンセルできない -------------------------------------------------

    #[Test]
    public function canceling_after_the_deadline_is_denied(): void
    {
        $member = $this->member();

        // 「いま」の 1 時間後に始まる枠（キャンセル期限は開始 2 時間前）
        $slot = $this->slot('2026-09-08 10:00', '2026-09-08 11:00');
        $reservation = ReservationBooking::book($slot, $member);

        $this->assertFalse(ReservationCancellation::isWithinDeadline($slot));

        $this->assertDeniedWith(CancellationDenial::DeadlinePassed, $reservation);

        $this->assertSame(ReservationStatus::Reserved, $reservation->fresh()->status);
    }

    #[Test]
    public function the_deadline_is_the_configured_number_of_hours_before_the_start(): void
    {
        $member = $this->member();

        // ちょうど期限（開始 2 時間前）はまだ受け付ける
        $slot = $this->slot('2026-09-08 11:00', '2026-09-08 12:00');
        $reservation = ReservationBooking::book($slot, $member);

        $this->assertTrue(ReservationCancellation::isWithinDeadline($slot));

        $result = ReservationCancellation::cancel($reservation);

        $this->assertSame(ReservationStatus::Canceled, $result->canceled->status);
    }

    #[Test]
    public function a_started_or_canceled_lesson_cannot_be_canceled(): void
    {
        $member = $this->member();

        $started = $this->slot('2026-09-10 10:00', '2026-09-10 11:00');
        $startedReservation = ReservationBooking::book($started, $member);

        // 予約したあとに開始時刻を過ぎた
        Carbon::setTestNow('2026-09-10 10:30:00');
        $this->assertDeniedWith(CancellationDenial::AlreadyStarted, $startedReservation);
        Carbon::setTestNow(self::NOW);

        $canceledSlot = $this->slot('2026-09-11 10:00', '2026-09-11 11:00');
        $reservation = ReservationBooking::book($canceledSlot, $member);
        $canceledSlot->update(['status' => 'canceled']);

        $this->assertDeniedWith(CancellationDenial::SlotCanceled, $reservation);
    }

    #[Test]
    public function the_same_reservation_cannot_be_canceled_twice(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00');

        $reservation = ReservationBooking::book($slot, $member);

        ReservationCancellation::cancel($reservation);

        $this->assertDeniedWith(CancellationDenial::AlreadyCanceled, $reservation->fresh());
    }

    #[Test]
    public function only_the_owner_can_cancel_a_reservation(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00');
        $reservation = ReservationBooking::book($slot, $this->member());

        $this->actingAs($this->member())
            ->delete(route('reservations.cancel', $reservation->id))
            ->assertForbidden();

        $this->assertSame(ReservationStatus::Reserved, $reservation->fresh()->status);
    }

    // --- 繰り上げ -----------------------------------------------------------

    #[Test]
    public function the_first_person_in_line_is_promoted_when_a_seat_opens(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 1]);

        [$booked] = $this->fill($slot, 1);

        $first = $this->member();
        $second = $this->member();

        $firstWait = $this->waiting($slot, $first, 1);
        $secondWait = $this->waiting($slot, $second, 2);

        $reservation = Reservation::query()->where('user_id', $booked->id)->sole();

        $result = ReservationCancellation::cancel($reservation);

        $promoted = $result->promoted;

        $this->assertTrue($result->hasPromotion());
        $this->assertNotNull($promoted);
        $this->assertSame($first->id, $promoted->user_id, '待ち行列の先頭が繰り上がる。');
        $this->assertSame(ReservationStatus::Promoted, $promoted->status);

        $this->assertSame(WaitlistStatus::Promoted, $firstWait->fresh()->status);
        $this->assertSame(WaitlistStatus::Waiting, $secondWait->fresh()->status, '2 番目はそのまま待つ。');

        // 席は 1 つのまま（キャンセルした人の席が 1 人に渡っただけ）
        $this->assertSame(1, $slot->fresh()->reservedCount());
    }

    #[Test]
    public function the_promoted_member_gets_a_notice_scheduled(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 1]);

        [$booked] = $this->fill($slot, 1);
        $waiter = $this->member();
        $this->waiting($slot, $waiter, 1);

        $result = ReservationCancellation::cancel(Reservation::query()->where('user_id', $booked->id)->sole());

        $reminder = Reminder::query()->sole();

        $this->assertSame($result->promoted?->id, $reminder->reservation_id);
        $this->assertSame($slot->id, $reminder->lesson_slot_id);
        $this->assertNull($reminder->sent_at, '実際の送信は拡張点。ここでは予定を積むだけ。');
        $this->assertTrue($reminder->scheduled_at->equalTo(now()));
    }

    #[Test]
    public function only_one_person_is_promoted_for_one_freed_seat(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 2]);

        [$firstBooked] = $this->fill($slot, 2);

        foreach ([1, 2, 3] as $position) {
            $this->waiting($slot, $this->member(), $position);
        }

        ReservationCancellation::cancel(Reservation::query()->where('user_id', $firstBooked->id)->sole());

        $this->assertSame(2, $slot->fresh()->reservedCount(), '定員を超えていない。');
        $this->assertSame(1, $slot->waitlists()->where('status', WaitlistStatus::Promoted->value)->count());
        $this->assertSame(2, $slot->waitlists()->waiting()->count(), '残りは待ち順のまま。');
    }

    #[Test]
    public function a_waiting_member_who_is_busy_at_that_time_is_skipped(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 1]);
        [$booked] = $this->fill($slot, 1);

        // 待っている間に、同じ時間帯の別レッスンを予約してしまった人
        $busy = $this->member();
        $another = $this->slot('2026-09-10 10:30', '2026-09-10 11:30');
        ReservationBooking::book($another, $busy);

        $next = $this->member();

        $busyWait = $this->waiting($slot, $busy, 1);
        $nextWait = $this->waiting($slot, $next, 2);

        $result = ReservationCancellation::cancel(Reservation::query()->where('user_id', $booked->id)->sole());

        $this->assertSame($next->id, $result->promoted?->user_id, '重なっている人は飛ばして次の人へ。');
        $this->assertSame(WaitlistStatus::Waiting, $busyWait->fresh()->status, '飛ばされた人は待ち行列に残る。');
        $this->assertSame(WaitlistStatus::Promoted, $nextWait->fresh()->status);
    }

    #[Test]
    public function the_seat_stays_open_when_nobody_can_be_promoted(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 1]);
        [$booked] = $this->fill($slot, 1);

        $busy = $this->member();
        $another = $this->slot('2026-09-10 10:30', '2026-09-10 11:30');
        ReservationBooking::book($another, $busy);

        $this->waiting($slot, $busy, 1);

        $result = ReservationCancellation::cancel(Reservation::query()->where('user_id', $booked->id)->sole());

        $this->assertFalse($result->hasPromotion());
        $this->assertSame(0, $slot->fresh()->reservedCount(), '繰り上げられないときは空席のまま。');
        $this->assertSame(1, $slot->waitlists()->waiting()->count());
    }

    #[Test]
    public function canceling_without_anyone_waiting_just_frees_the_seat(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 1]);
        [$booked] = $this->fill($slot, 1);

        $result = ReservationCancellation::cancel(Reservation::query()->where('user_id', $booked->id)->sole());

        $this->assertFalse($result->hasPromotion());
        $this->assertSame(0, $slot->fresh()->reservedCount());
        $this->assertSame(0, Reminder::query()->count());
    }

    #[Test]
    public function the_promoted_member_holds_a_seat_and_sees_it_on_the_lesson(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 1]);
        [$booked] = $this->fill($slot, 1);

        $waiter = $this->member();
        $this->waiting($slot, $waiter, 1);

        ReservationCancellation::cancel(Reservation::query()->where('user_id', $booked->id)->sole());

        // 繰上確定も席を占める（残枠 0）
        $this->assertSame(0, $slot->fresh()->remainingSeats());

        $this->actingAs($waiter)
            ->get(route('lessons.show', $slot->id))
            ->assertOk()
            ->assertSee('予約済み')
            ->assertSee('予約をキャンセルする');
    }

    #[Test]
    public function the_freed_seat_can_also_be_taken_by_a_new_booking_when_no_one_waits(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 1]);
        [$booked] = $this->fill($slot, 1);

        ReservationCancellation::cancel(Reservation::query()->where('user_id', $booked->id)->sole());

        $newcomer = $this->member();
        $reservation = ReservationBooking::book($slot, $newcomer);

        $this->assertSame($newcomer->id, $reservation->user_id);
        $this->assertSame(1, $slot->fresh()->reservedCount());
    }

    // --- クエリ本数 ---------------------------------------------------------

    #[Test]
    public function the_queries_for_a_cancellation_do_not_grow_with_the_queue(): void
    {
        $short = $this->cancelWithQueue(waiting: 2);
        $long = $this->cancelWithQueue(waiting: 20);

        $this->assertSame($short, $long, "待ち行列が伸びるとクエリが増える（{$short} → {$long}）。");
    }

    /**
     * 待機者 n 人の枠で 1 件キャンセルしたときのクエリ本数。
     */
    private function cancelWithQueue(int $waiting): int
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 1]);
        [$booked] = $this->fill($slot, 1);

        foreach (range(1, $waiting) as $position) {
            $this->waiting($slot, $this->member(), $position);
        }

        $reservation = Reservation::query()
            ->where('lesson_slot_id', $slot->id)
            ->where('user_id', $booked->id)
            ->sole();

        return $this->countQueries(function () use ($reservation): void {
            ReservationCancellation::cancel($reservation);
        });
    }
}
