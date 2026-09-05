<?php

namespace Tests\Feature;

use App\Enums\LessonSlotStatus;
use App\Enums\ReminderType;
use App\Models\Reminder;
use App\Models\Reservation;
use App\Support\Lessons\SlotCancellation;
use App\Support\Reminders\ReminderSchedule;
use App\Support\Reservations\ReservationBooking;
use App\Support\Reservations\ReservationCancellation;
use App\Support\Reservations\WaitlistRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

/**
 * STEP6-2: リマインドの仕組みの検証。
 *
 * このシステムが持つのは「送信予定の生成・管理」まで（実送信は拡張点）。
 * 見るのは次の 4 点。
 *   1. 日次コマンドで、これからのレッスンの予約に前日リマインドの予定ができる
 *   2. 何度実行しても重複しない（すでに予定があるものは飛ばす）
 *   3. 予約のキャンセル・枠の中止で、送るはずだった予定が無効になる
 *   4. 繰り上げの連絡は種別を分けて記録される
 */
class ReminderScheduleTest extends TestCase
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

    // --- 前日リマインドの生成 -----------------------------------------------

    #[Test]
    public function the_daily_command_schedules_a_reminder_for_each_upcoming_reservation(): void
    {
        $slot = $this->slot('2026-09-09 19:00', '2026-09-09 20:00', ['capacity' => 3]);

        ReservationBooking::book($slot, $this->member());
        ReservationBooking::book($slot, $this->member());

        $this->artisan('reminders:schedule')
            ->expectsOutputToContain('送信予定を 2 件作りました')
            ->assertSuccessful();

        $reminders = Reminder::query()->get();

        $this->assertCount(2, $reminders);

        foreach ($reminders as $reminder) {
            $this->assertSame(ReminderType::LessonReminder, $reminder->type);
            $this->assertNull($reminder->sent_at, '送信そのものは拡張点。予定を作るだけ。');
            // 前日の 20 時に送る予定
            $this->assertSame('2026-09-08 20:00', $reminder->scheduled_at->format('Y-m-d H:i'));
        }
    }

    #[Test]
    public function running_the_command_again_does_not_duplicate_the_schedule(): void
    {
        $slot = $this->slot('2026-09-09 19:00', '2026-09-09 20:00');
        ReservationBooking::book($slot, $this->member());

        $this->artisan('reminders:schedule')->assertSuccessful();
        $this->artisan('reminders:schedule')->expectsOutputToContain('0 件')->assertSuccessful();

        $this->assertSame(1, Reminder::query()->count());
    }

    #[Test]
    public function lessons_further_ahead_are_left_for_a_later_run(): void
    {
        $soon = $this->slot('2026-09-09 19:00', '2026-09-09 20:00');
        $later = $this->slot('2026-09-20 19:00', '2026-09-20 20:00');

        $member = $this->member();
        ReservationBooking::book($soon, $member);
        ReservationBooking::book($later, $member);

        ReminderSchedule::scheduleLessonReminders(days: 2);

        $this->assertSame(1, Reminder::query()->count());
        $this->assertSame($soon->id, Reminder::query()->sole()->lesson_slot_id);

        // 日が近づけば、次の実行で予定ができる
        ReminderSchedule::scheduleLessonReminders(days: 14);

        $this->assertSame(2, Reminder::query()->count());
    }

    #[Test]
    public function the_schedule_is_now_when_the_evening_before_has_already_passed(): void
    {
        // 「いま」は 2026-09-08 09:00。前日 20 時はもう過ぎている
        $slot = $this->slot('2026-09-08 19:00', '2026-09-08 20:00');
        ReservationBooking::book($slot, $this->member());

        ReminderSchedule::scheduleLessonReminders();

        $this->assertTrue(Reminder::query()->sole()->scheduled_at->equalTo(now()));
    }

    #[Test]
    public function canceled_reservations_and_called_off_lessons_are_not_scheduled(): void
    {
        $member = $this->member();

        $slot = $this->slot('2026-09-09 19:00', '2026-09-09 20:00');
        $reservation = ReservationBooking::book($slot, $member);
        ReservationCancellation::cancel($reservation);

        $calledOff = $this->slot('2026-09-09 07:00', '2026-09-09 08:00');
        ReservationBooking::book($calledOff, $this->member());
        $calledOff->update(['status' => LessonSlotStatus::Canceled]);

        ReminderSchedule::scheduleLessonReminders();

        $this->assertSame(0, Reminder::query()->count());
    }

    // --- キャンセルでの無効化 -----------------------------------------------

    #[Test]
    public function canceling_a_reservation_turns_off_its_pending_reminder(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-09 19:00', '2026-09-09 20:00');

        $reservation = ReservationBooking::book($slot, $member);
        ReminderSchedule::scheduleLessonReminders();

        $reminder = Reminder::query()->sole();
        $this->assertTrue($reminder->is_active);

        ReservationCancellation::cancel($reservation);

        $this->assertFalse($reminder->fresh()->is_active, 'キャンセルした予約に送ってしまう。');
        $this->assertSame(0, Reminder::query()->due()->count());
    }

    #[Test]
    public function an_already_sent_reminder_is_kept_as_a_record(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-09 19:00', '2026-09-09 20:00');

        $reservation = ReservationBooking::book($slot, $member);

        $sent = Reminder::factory()->sent()->create([
            'lesson_slot_id' => $slot->id,
            'reservation_id' => $reservation->id,
        ]);

        ReservationCancellation::cancel($reservation);

        $this->assertTrue($sent->fresh()->is_active, '送信済みの記録はそのまま残す。');
    }

    #[Test]
    public function calling_off_a_lesson_turns_off_the_reminders_of_its_reservations(): void
    {
        $slot = $this->slot('2026-09-09 19:00', '2026-09-09 20:00', ['capacity' => 2]);

        ReservationBooking::book($slot, $this->member());
        ReservationBooking::book($slot, $this->member());

        ReminderSchedule::scheduleLessonReminders();

        $this->assertSame(2, Reminder::query()->pending()->count());

        $slot->update(['status' => LessonSlotStatus::Canceled]);
        SlotCancellation::apply($slot);

        $this->assertSame(0, Reminder::query()->pending()->count());
    }

    // --- 繰り上げの連絡 -----------------------------------------------------

    #[Test]
    public function a_promotion_notice_is_recorded_with_its_own_type(): void
    {
        $slot = $this->slot('2026-09-10 19:00', '2026-09-10 20:00', ['capacity' => 1]);
        [$booked] = $this->fill($slot, 1);

        $waiter = $this->member();
        WaitlistRegistration::join($slot, $waiter);

        ReservationCancellation::cancel(Reservation::query()->where('user_id', $booked->id)->sole());

        $notice = Reminder::query()->ofType(ReminderType::PromotionNotice)->sole();

        $this->assertSame($waiter->id, $notice->reservation?->user_id);
        // 繰り上げは早く知らせたいので、送信予定は「いま」
        $this->assertTrue($notice->scheduled_at->equalTo(now()));
        $this->assertNull($notice->sent_at);
    }

    #[Test]
    public function the_two_kinds_of_notice_can_be_told_apart(): void
    {
        $slot = $this->slot('2026-09-09 19:00', '2026-09-09 20:00', ['capacity' => 1]);
        [$booked] = $this->fill($slot, 1);

        ReminderSchedule::scheduleLessonReminders();

        $waiter = $this->member();
        WaitlistRegistration::join($slot, $waiter);
        ReservationCancellation::cancel(Reservation::query()->where('user_id', $booked->id)->sole());

        $this->assertSame(1, Reminder::query()->ofType(ReminderType::PromotionNotice)->count());
        $this->assertSame(1, Reminder::query()->ofType(ReminderType::LessonReminder)->count());

        // 前日リマインドはキャンセルで無効になり、繰り上げの連絡だけが残る
        $this->assertSame(1, Reminder::query()->pending()->count());
        $this->assertSame(
            ReminderType::PromotionNotice,
            Reminder::query()->pending()->sole()->type,
        );
    }

    // --- 送信側が拾う予定 ---------------------------------------------------

    #[Test]
    public function due_returns_only_the_notices_that_should_be_sent_now(): void
    {
        $slot = $this->slot('2026-09-09 19:00', '2026-09-09 20:00', ['capacity' => 3]);
        $reservation = ReservationBooking::book($slot, $this->member());

        // まだ送信予定が来ていない
        Reminder::factory()->create([
            'lesson_slot_id' => $slot->id,
            'reservation_id' => $reservation->id,
            'scheduled_at' => now()->addHours(2),
        ]);

        // 送信予定が来ている
        $due = Reminder::factory()->create([
            'lesson_slot_id' => $slot->id,
            'reservation_id' => ReservationBooking::book($slot, $this->member())->id,
            'scheduled_at' => now()->subMinute(),
        ]);

        // 送信済み
        Reminder::factory()->sent()->create([
            'lesson_slot_id' => $slot->id,
            'reservation_id' => ReservationBooking::book($slot, $this->member())->id,
            'scheduled_at' => now()->subHour(),
        ]);

        $this->assertSame([$due->id], Reminder::query()->due()->pluck('id')->all());
    }
}
