<?php

namespace Tests\Feature;

use App\Enums\BookingDenial;
use App\Enums\LessonSlotStatus;
use App\Enums\WaitlistStatus;
use App\Models\LessonSlot;
use App\Models\User;
use App\Models\Waitlist;
use App\Support\Reservations\BookingDenied;
use App\Support\Reservations\ReservationBooking;
use App\Support\Reservations\WaitlistRegistration;
use App\Support\Ui\Toast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

/**
 * STEP5: キャンセル待ちの登録・取り消しの検証。
 *
 * 見るのは次の 4 点。
 *   1. 満席の枠に並べて、待ち順（position）が枠の中で連番になる
 *   2. 空きがある／予約済み／すでに並んでいる／締切・中止・開始済みは並べない
 *   3. 自分の待機は取り消せて、並び直せる
 *   4. 詳細画面から並べる（満席のときだけ CTA が出る）
 */
class WaitlistTest extends TestCase
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

    /**
     * 満席の枠。
     */
    private function fullSlot(int $capacity = 1): LessonSlot
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => $capacity]);

        $this->fill($slot, $capacity);

        return $slot;
    }

    private function assertDeniedWith(BookingDenial $reason, LessonSlot $slot, User $user): void
    {
        try {
            WaitlistRegistration::join($slot, $user);

            $this->fail('キャンセル待ちの登録が通ってしまった（期待した拒否: '.$reason->value.'）。');
        } catch (BookingDenied $denied) {
            $this->assertSame($reason, $denied->reason);
        }
    }

    // --- 並べる -------------------------------------------------------------

    #[Test]
    public function a_member_joins_the_waitlist_of_a_full_lesson(): void
    {
        $member = $this->member();
        $slot = $this->fullSlot();

        $response = $this->actingAs($member)->post(route('lessons.waitlist', $slot->id));

        $response->assertRedirect(route('lessons.show', $slot->id));
        $response->assertSessionHas(Toast::SESSION_KEY, fn (array $toast): bool => $toast['type'] === 'success');

        $waitlist = Waitlist::query()->sole();

        $this->assertSame($member->id, $waitlist->user_id);
        $this->assertSame($slot->id, $waitlist->lesson_slot_id);
        $this->assertSame(1, $waitlist->position);
        $this->assertSame(WaitlistStatus::Waiting, $waitlist->status);
    }

    #[Test]
    public function the_positions_are_numbered_in_the_order_people_join(): void
    {
        $slot = $this->fullSlot();

        $first = WaitlistRegistration::join($slot, $this->member());
        $second = WaitlistRegistration::join($slot, $this->member());
        $third = WaitlistRegistration::join($slot, $this->member());

        $this->assertSame([1, 2, 3], [$first->position, $second->position, $third->position]);
    }

    #[Test]
    public function the_detail_screen_offers_the_waitlist_action_only_when_full(): void
    {
        $member = $this->member();
        $full = $this->fullSlot();

        $this->actingAs($member)
            ->get(route('lessons.show', $full->id))
            ->assertOk()
            ->assertSee('キャンセル待ちに登録')
            ->assertSee(route('lessons.waitlist', $full->id), false);

        $open = $this->slot('2026-09-11 10:00', '2026-09-11 11:00');

        $this->actingAs($member)
            ->get(route('lessons.show', $open->id))
            ->assertOk()
            ->assertSee('予約する')
            ->assertDontSee(route('lessons.waitlist', $open->id), false);
    }

    #[Test]
    public function the_waiting_member_sees_their_place_in_line(): void
    {
        $slot = $this->fullSlot();

        WaitlistRegistration::join($slot, $this->member());

        $member = $this->member();
        WaitlistRegistration::join($slot, $member);

        $this->actingAs($member)
            ->get(route('lessons.show', $slot->id))
            ->assertOk()
            ->assertSee('現在 2 番目です。')
            ->assertSee('キャンセル待ちを取り消す');
    }

    // --- 並べない -----------------------------------------------------------

    #[Test]
    public function a_lesson_with_seats_left_cannot_be_waited_for(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 2]);
        $this->fill($slot, 1);

        $this->assertDeniedWith(BookingDenial::SeatsAvailable, $slot, $this->member());
    }

    #[Test]
    public function a_member_who_already_booked_cannot_wait_for_the_same_lesson(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 2]);

        ReservationBooking::book($slot, $member);
        $this->fill($slot, 1);

        $this->assertDeniedWith(BookingDenial::AlreadyReserved, $slot, $member);
    }

    #[Test]
    public function joining_the_same_waitlist_twice_is_denied(): void
    {
        $member = $this->member();
        $slot = $this->fullSlot();

        WaitlistRegistration::join($slot, $member);

        $this->assertDeniedWith(BookingDenial::AlreadyWaiting, $slot, $member);

        $this->assertSame(1, Waitlist::query()->count());
    }

    #[Test]
    public function closed_canceled_and_started_lessons_cannot_be_waited_for(): void
    {
        $closed = $this->fullSlot();
        $closed->update(['status' => LessonSlotStatus::Closed]);
        $this->assertDeniedWith(BookingDenial::ReceptionClosed, $closed, $this->member());

        $canceled = $this->fullSlot();
        $canceled->update(['status' => LessonSlotStatus::Canceled]);
        $this->assertDeniedWith(BookingDenial::SlotCanceled, $canceled, $this->member());

        // 「いま」は 2026-09-08 09:00
        $started = $this->slot('2026-09-08 07:30', '2026-09-08 08:15', ['capacity' => 1]);
        $this->fill($started, 1);
        $this->assertDeniedWith(BookingDenial::AlreadyStarted, $started, $this->member());
    }

    #[Test]
    public function only_members_can_join_a_waitlist(): void
    {
        $slot = $this->fullSlot();

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->post(route('lessons.waitlist', $slot->id))->assertForbidden();

        $this->assertSame(0, Waitlist::query()->count());
    }

    // --- 取り消す -----------------------------------------------------------

    #[Test]
    public function a_member_withdraws_from_the_waitlist(): void
    {
        $member = $this->member();
        $slot = $this->fullSlot();

        $waitlist = WaitlistRegistration::join($slot, $member);

        $this->actingAs($member)
            ->delete(route('waitlists.cancel', $waitlist->id))
            ->assertRedirect(route('lessons.show', $slot->id));

        $this->assertSame(WaitlistStatus::Canceled, $waitlist->fresh()->status);
        $this->assertSame(0, $slot->waitlists()->waiting()->count());
    }

    #[Test]
    public function a_member_can_line_up_again_after_withdrawing(): void
    {
        $member = $this->member();
        $slot = $this->fullSlot();

        $first = WaitlistRegistration::join($slot, $member);
        $other = WaitlistRegistration::join($slot, $this->member());

        WaitlistRegistration::withdraw($first);

        $again = WaitlistRegistration::join($slot, $member);

        // 待機中の最大 + 1 を配るので、番号は取り消し前より後ろになる
        $this->assertSame(3, $again->position);
        $this->assertSame(2, $other->fresh()->position, 'ほかの人の待ち順は動かさない。');
        $this->assertSame(2, $slot->waitlists()->waiting()->count());
    }

    #[Test]
    public function only_the_owner_can_withdraw_a_waitlist(): void
    {
        $slot = $this->fullSlot();
        $waitlist = WaitlistRegistration::join($slot, $this->member());

        $this->actingAs($this->member())
            ->delete(route('waitlists.cancel', $waitlist->id))
            ->assertForbidden();

        $this->assertSame(WaitlistStatus::Waiting, $waitlist->fresh()->status);
    }

    #[Test]
    public function withdrawing_an_already_promoted_waitlist_changes_nothing(): void
    {
        $slot = $this->fullSlot();
        $member = $this->member();

        $waitlist = WaitlistRegistration::join($slot, $member);
        $waitlist->update(['status' => WaitlistStatus::Promoted]);

        WaitlistRegistration::withdraw($waitlist);

        $this->assertSame(WaitlistStatus::Promoted, $waitlist->fresh()->status);
    }

    #[Test]
    public function the_lesson_list_shows_the_lessons_a_member_is_waiting_for(): void
    {
        $member = $this->member();
        $slot = $this->fullSlot();

        WaitlistRegistration::join($slot, $member);

        $this->actingAs($member)
            ->get(route('lessons.index', ['date' => '2026-09-10']))
            ->assertOk()
            ->assertSee('キャンセル待ち');
    }
}
