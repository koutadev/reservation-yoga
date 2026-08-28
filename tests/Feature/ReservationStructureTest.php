<?php

namespace Tests\Feature;

use App\Enums\LessonSlotStatus;
use App\Enums\LessonType;
use App\Enums\ReminderChannel;
use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Enums\WaitlistStatus;
use App\Models\Instructor;
use App\Models\LessonSlot;
use App\Models\Reminder;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Waitlist;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * STEP1: テーブル・リレーション・採番・制約の検証。
 *
 * 予約確定やキャンセル待ちの繰り上げ（STEP4 / STEP5）はまだ実装しないが、
 * 「二重予約を DB が拒む」ことはこの時点で確かめておく。
 */
class ReservationStructureTest extends TestCase
{
    use RefreshDatabase;

    private function slot(int $capacity = 3, LessonType $type = LessonType::Group): LessonSlot
    {
        return LessonSlot::factory()->create([
            'instructor_id' => Instructor::factory(),
            'capacity' => $capacity,
            'lesson_type' => $type,
        ]);
    }

    private function member(string $name = '会員 太郎'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole(RoleName::Member->value);

        return $user;
    }

    #[Test]
    public function instructors_and_slots_get_their_codes(): void
    {
        $instructor = Instructor::factory()->create(['name' => '佐倉 みなと']);

        // インストラクターは通し番号
        $this->assertSame('INS-0001', $instructor->code);
        $this->assertSame('INS-0002', Instructor::factory()->create()->code);

        // レッスン枠と予約は「年」で区切った採番
        $slot = LessonSlot::factory()->create([
            'instructor_id' => $instructor->id,
            'starts_at' => Carbon::parse('2026-09-01 10:00'),
            'ends_at' => Carbon::parse('2026-09-01 11:00'),
        ]);
        $this->assertSame('LSN-2026-0001', $slot->code);

        // 年が変われば連番も 1 に戻る
        $next = LessonSlot::factory()->create([
            'instructor_id' => $instructor->id,
            'starts_at' => Carbon::parse('2027-01-10 10:00'),
            'ends_at' => Carbon::parse('2027-01-10 11:00'),
        ]);
        $this->assertSame('LSN-2027-0001', $next->code);

        $reservation = Reservation::factory()->create([
            'lesson_slot_id' => $slot->id,
            'user_id' => $this->member()->id,
        ]);
        $this->assertMatchesRegularExpression('/^RSV-\d{4}-0001$/', $reservation->code);
    }

    #[Test]
    public function the_relations_follow_the_er_diagram(): void
    {
        $instructor = Instructor::factory()->create();
        $slot = LessonSlot::factory()->create(['instructor_id' => $instructor->id, 'capacity' => 2]);
        $member = $this->member();

        $reservation = Reservation::factory()->create([
            'lesson_slot_id' => $slot->id,
            'user_id' => $member->id,
        ]);

        $waitlist = Waitlist::factory()->create([
            'lesson_slot_id' => $slot->id,
            'user_id' => $this->member('会員 花子')->id,
        ]);

        $reminder = Reminder::factory()->create([
            'lesson_slot_id' => $slot->id,
            'reservation_id' => $reservation->id,
        ]);

        // 講師 → 枠 → 予約 / キャンセル待ち / リマインド
        $this->assertSame([$slot->id], $instructor->lessonSlots->pluck('id')->all());
        $this->assertSame([$reservation->id], $slot->reservations->pluck('id')->all());
        $this->assertSame([$waitlist->id], $slot->waitlists->pluck('id')->all());
        $this->assertSame([$reminder->id], $slot->reminders->pluck('id')->all());

        // 予約 → 枠 / 会員 / リマインド
        $this->assertSame($slot->id, $reservation->lessonSlot?->id);
        $this->assertSame($member->id, $reservation->user?->id);
        $this->assertSame([$reminder->id], $reservation->reminders->pluck('id')->all());

        // 会員 → 予約 / キャンセル待ち
        $this->assertSame([$reservation->id], $member->reservations->pluck('id')->all());
        $this->assertSame($slot->id, $waitlist->lessonSlot?->id);
        $this->assertSame(ReminderChannel::Email, $reminder->channel);
    }

    #[Test]
    public function the_same_member_cannot_book_the_same_slot_twice(): void
    {
        $slot = $this->slot();
        $member = $this->member();

        Reservation::factory()->create(['lesson_slot_id' => $slot->id, 'user_id' => $member->id]);

        // DB の部分ユニークインデックスが二重予約を拒む
        $this->expectException(QueryException::class);

        Reservation::factory()->create(['lesson_slot_id' => $slot->id, 'user_id' => $member->id]);
    }

    #[Test]
    public function a_canceled_reservation_can_be_taken_again(): void
    {
        $slot = $this->slot();
        $member = $this->member();

        $first = Reservation::factory()->canceled()->create([
            'lesson_slot_id' => $slot->id,
            'user_id' => $member->id,
        ]);

        // キャンセル済みは席を占めないので、取り直せる
        $second = Reservation::factory()->create([
            'lesson_slot_id' => $slot->id,
            'user_id' => $member->id,
        ]);

        $this->assertTrue($first->isCanceled());
        $this->assertSame(ReservationStatus::Reserved, $second->status);
        $this->assertSame(1, $slot->activeReservations()->count());
    }

    #[Test]
    public function the_same_member_cannot_queue_twice_for_one_slot(): void
    {
        $slot = $this->slot();
        $member = $this->member();

        Waitlist::factory()->create(['lesson_slot_id' => $slot->id, 'user_id' => $member->id, 'position' => 1]);

        $this->expectException(QueryException::class);

        Waitlist::factory()->create(['lesson_slot_id' => $slot->id, 'user_id' => $member->id, 'position' => 2]);
    }

    #[Test]
    public function waiting_positions_are_unique_within_a_slot(): void
    {
        $slot = $this->slot();

        Waitlist::factory()->create([
            'lesson_slot_id' => $slot->id,
            'user_id' => $this->member('待ち 1')->id,
            'position' => 1,
        ]);

        $this->expectException(QueryException::class);

        Waitlist::factory()->create([
            'lesson_slot_id' => $slot->id,
            'user_id' => $this->member('待ち 2')->id,
            'position' => 1,
        ]);
    }

    #[Test]
    public function the_remaining_seats_come_from_the_active_reservations(): void
    {
        $slot = $this->slot(capacity: 3);

        Reservation::factory()->create(['lesson_slot_id' => $slot->id, 'user_id' => $this->member('A')->id]);
        Reservation::factory()->promoted()->create(['lesson_slot_id' => $slot->id, 'user_id' => $this->member('B')->id]);
        // キャンセルは席を空ける
        Reservation::factory()->canceled()->create(['lesson_slot_id' => $slot->id, 'user_id' => $this->member('C')->id]);

        $this->assertSame(1, $slot->remainingSeats());
        $this->assertFalse($slot->isFull());

        Reservation::factory()->create(['lesson_slot_id' => $slot->id, 'user_id' => $this->member('D')->id]);

        $slot->refresh();

        $this->assertSame(0, $slot->remainingSeats());
        $this->assertTrue($slot->isFull());
    }

    #[Test]
    public function a_personal_lesson_holds_one_seat(): void
    {
        $slot = $this->slot(capacity: 1, type: LessonType::Personal);

        $this->assertSame(1, $slot->lesson_type->fixedCapacity());
        $this->assertSame(1, $slot->capacity);
        $this->assertTrue($slot->status->acceptsReservation());
    }

    #[Test]
    public function only_open_slots_accept_reservations(): void
    {
        $this->assertTrue(LessonSlotStatus::Open->acceptsReservation());
        $this->assertFalse(LessonSlotStatus::Closed->acceptsReservation());
        $this->assertFalse(LessonSlotStatus::Canceled->acceptsReservation());

        // 席を占めるのは「予約中」と「繰上確定」
        $this->assertSame(['reserved', 'promoted'], ReservationStatus::activeValues());
        $this->assertTrue(WaitlistStatus::Waiting->isWaiting());
        $this->assertFalse(WaitlistStatus::Promoted->isWaiting());
    }

    #[Test]
    public function the_common_columns_and_soft_delete_are_in_place(): void
    {
        $slot = $this->slot();

        $this->assertTrue($slot->is_active);
        $this->assertNotNull($slot->created_at);

        $slot->delete();

        $this->assertSoftDeleted($slot);
        $this->assertSame(0, LessonSlot::query()->count());
        $this->assertSame(1, LessonSlot::withTrashed()->count());
    }

    #[Test]
    public function the_member_role_exists_with_its_permission(): void
    {
        $member = $this->member();

        $this->assertTrue($member->hasRole(RoleName::Member->value));
        $this->assertTrue($member->can('reservation.book'));
        // 会員は管理側には入れない
        $this->assertFalse($member->can('master.view'));
    }

    #[Test]
    public function reminders_can_be_listed_by_their_schedule(): void
    {
        $slot = $this->slot();
        $reservation = Reservation::factory()->create([
            'lesson_slot_id' => $slot->id,
            'user_id' => $this->member()->id,
        ]);

        $due = Reminder::factory()->create([
            'lesson_slot_id' => $slot->id,
            'reservation_id' => $reservation->id,
            'scheduled_at' => now()->subHour(),
        ]);

        Reminder::factory()->sent()->create([
            'lesson_slot_id' => $slot->id,
            'reservation_id' => $reservation->id,
            'scheduled_at' => now()->subHours(2),
        ]);

        Reminder::factory()->create([
            'lesson_slot_id' => $slot->id,
            'reservation_id' => $reservation->id,
            'scheduled_at' => now()->addDay(),
        ]);

        // 送信予定が来ていて、まだ送っていないものだけ
        $this->assertSame([$due->id], Reminder::query()->due()->pluck('id')->all());
    }
}
