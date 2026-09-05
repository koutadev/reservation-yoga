<?php

namespace Tests\Feature;

use App\Enums\LessonSlotStatus;
use App\Enums\LessonType;
use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Enums\WaitlistStatus;
use App\Models\Instructor;
use App\Models\LessonSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Waitlist;
use App\Support\Ui\Toast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * STEP2: レッスン枠の開講・管理（管理側）の検証。
 *
 * 見るのは次の 5 点。
 *   1. 一覧の既定が「今後の枠を日時順」で、過去は絞り込みで出せる
 *   2. 行クリックのモーダルから枠を編集できる
 *   3. すでに入っている予約より小さい定員には変更できない
 *   4. 枠を中止すると、その枠の予約とキャンセル待ちも連鎖してキャンセルされる
 *   5. 繰り返し登録で複数の枠がまとめて作られ、重複する日はスキップされる
 *
 * あわせて、staff が「自分の枠だけ」を編集できることも確かめる。
 */
class LessonSlotManagementTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    /**
     * 講師としてログインする staff（instructors.user_id で紐付ける）。
     */
    private function instructorUser(Instructor $instructor): User
    {
        $user = $this->userWithRole(RoleName::Staff);

        $instructor->update(['user_id' => $user->id]);

        return $user;
    }

    private function slot(array $attributes = []): LessonSlot
    {
        return LessonSlot::factory()->create($attributes);
    }

    /**
     * 枠の更新フォームに送る値。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(LessonSlot $slot, array $overrides = []): array
    {
        return array_merge([
            'instructor_id' => $slot->instructor_id,
            'title' => $slot->title,
            'lesson_type' => $slot->lesson_type->value,
            'starts_at' => $slot->starts_at->format('Y-m-d\TH:i'),
            'ends_at' => $slot->ends_at->format('Y-m-d\TH:i'),
            'capacity' => $slot->capacity,
            'online_url' => $slot->online_url,
            'status' => $slot->status->value,
        ], $overrides);
    }

    /**
     * 席を占める予約を n 件つくる（会員はそれぞれ別人）。
     *
     * @return list<Reservation>
     */
    private function reserve(LessonSlot $slot, int $count, ReservationStatus $status = ReservationStatus::Reserved): array
    {
        $reservations = [];

        for ($i = 0; $i < $count; $i++) {
            $member = $this->userWithRole(RoleName::Member);

            $reservations[] = Reservation::factory()->create([
                'lesson_slot_id' => $slot->id,
                'user_id' => $member->id,
                'status' => $status,
                'canceled_at' => null,
            ]);
        }

        return $reservations;
    }

    // --- 1. 一覧 ---------------------------------------------------------

    #[Test]
    public function the_list_shows_upcoming_slots_in_date_order_by_default(): void
    {
        $later = $this->slot(['starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHour()]);
        $sooner = $this->slot(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        $past = $this->slot(['starts_at' => now()->subWeek(), 'ends_at' => now()->subWeek()->addHour()]);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('lesson-slots.index'))
            ->assertOk()
            // 今後の枠だけが、日時の早い順に並ぶ
            ->assertSeeInOrder([$sooner->code, $later->code])
            ->assertDontSee($past->code);
    }

    #[Test]
    public function past_slots_are_shown_when_the_period_filter_asks_for_them(): void
    {
        $upcoming = $this->slot(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        $past = $this->slot(['starts_at' => now()->subWeek(), 'ends_at' => now()->subWeek()->addHour()]);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('lesson-slots.index', ['period' => 'past']))
            ->assertOk()
            ->assertSee($past->code)
            ->assertDontSee($upcoming->code);
    }

    #[Test]
    public function the_list_shows_the_reservation_count_and_url_setting(): void
    {
        $slot = $this->slot(['capacity' => 5, 'online_url' => null, 'title' => '朝のベーシックヨガ']);
        $this->reserve($slot, 2);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('lesson-slots.index'))
            ->assertOk()
            ->assertSee('朝のベーシックヨガ')
            // 予約数は保持せず都度算出する
            ->assertSee('未設定')
            ->assertSee($slot->code);

        $this->assertSame(2, $slot->fresh()->reservedCount());
        $this->assertSame(3, $slot->fresh()->remainingSeats());
    }

    #[Test]
    public function members_cannot_open_the_lesson_slot_screens(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->userWithRole(RoleName::Member))
            ->get(route('lesson-slots.index'))
            ->assertForbidden();

        $this->actingAs($this->userWithRole(RoleName::Viewer))
            ->get(route('lesson-slots.detail', $slot->id))
            ->assertForbidden();
    }

    // --- 2. 行クリックでの編集 -------------------------------------------

    #[Test]
    public function each_row_opens_the_detail_and_edit_modal(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('lesson-slots.index'))
            ->assertOk()
            ->assertSee(route('lesson-slots.detail', $slot->id), false)
            ->assertSee('open-detail', false)
            ->assertSee('master-detail')
            // 編集はモーダルで行うので、一覧に編集ボタンは置かない
            ->assertDontSee(route('lesson-slots.edit', $slot->id), false);
    }

    #[Test]
    public function the_detail_fragment_shows_the_slot_and_its_edit_form(): void
    {
        $instructor = Instructor::factory()->create(['name' => '佐倉 みなと']);
        $slot = $this->slot([
            'instructor_id' => $instructor->id,
            'title' => '朝のベーシックヨガ',
            'capacity' => 10,
        ]);
        $this->reserve($slot, 3);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('lesson-slots.detail', $slot->id))
            ->assertOk()
            ->assertSee('レッスン枠 — 朝のベーシックヨガ')
            ->assertSee($slot->code)
            ->assertSee('佐倉 みなと')
            // 予約数・残枠は都度算出して出す
            ->assertSee('3 名')
            ->assertSee('7 名')
            ->assertSee(route('lesson-slots.update', $slot->id), false)
            ->assertSee('name="_modal_record"', false)
            ->assertSee('name="capacity"', false);
    }

    #[Test]
    public function a_slot_can_be_updated_from_the_modal(): void
    {
        $slot = $this->slot(['title' => '変更前', 'capacity' => 8]);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->put(route('lesson-slots.update', $slot->id), $this->payload($slot, [
                'title' => '朝のリセットヨガ',
                'capacity' => 12,
                '_modal_record' => $slot->id,
            ]))
            ->assertRedirect(route('lesson-slots.index'));

        $slot->refresh();

        $this->assertSame('朝のリセットヨガ', $slot->title);
        $this->assertSame(12, $slot->capacity);
    }

    #[Test]
    public function a_slot_can_be_opened_from_the_create_form(): void
    {
        $instructor = Instructor::factory()->create();
        $startsAt = now()->addWeek()->setTime(7, 30);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->post(route('lesson-slots.store'), [
                'instructor_id' => $instructor->id,
                'title' => '朝のベーシックヨガ',
                'lesson_type' => LessonType::Group->value,
                'starts_at' => $startsAt->format('Y-m-d\TH:i'),
                'ends_at' => $startsAt->copy()->addMinutes(45)->format('Y-m-d\TH:i'),
                'capacity' => 12,
                'online_url' => 'https://example.com/meet/abc',
                'status' => LessonSlotStatus::Open->value,
            ])
            ->assertRedirect(route('lesson-slots.index'));

        $slot = LessonSlot::query()->sole();

        $this->assertSame('朝のベーシックヨガ', $slot->title);
        $this->assertSame(12, $slot->capacity);
        // コードは共通基盤の採番（開催年で区切る）
        $this->assertSame('LSN-'.$startsAt->year.'-0001', $slot->code);
    }

    #[Test]
    public function the_create_form_offers_single_and_recurring_modes(): void
    {
        Instructor::factory()->create(['name' => '佐倉 みなと']);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('lesson-slots.create'))
            ->assertOk()
            ->assertSee('単発')
            ->assertSee('繰り返し')
            ->assertSee('佐倉 みなと')
            ->assertSee(route('lesson-slots.store'), false)
            ->assertSee(route('lesson-slots.store-recurring'), false)
            // 繰り返しは曜日・時間・期間で指定する
            ->assertSee('name="weekdays[]"', false)
            ->assertSee('name="start_time"', false)
            ->assertSee('name="date_from"', false);
    }

    #[Test]
    public function the_list_can_be_exported_as_csv(): void
    {
        $slot = $this->slot(['title' => '朝のベーシックヨガ']);

        $response = $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('lesson-slots.export'))
            ->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString($slot->code, $csv);
        $this->assertStringContainsString('コード', $csv);
    }

    #[Test]
    public function a_personal_lesson_always_holds_one_seat(): void
    {
        $instructor = Instructor::factory()->create();
        $startsAt = now()->addWeek()->setTime(19, 0);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->post(route('lesson-slots.store'), [
                'instructor_id' => $instructor->id,
                'title' => 'パーソナルヨガ',
                'lesson_type' => LessonType::Personal->value,
                'starts_at' => $startsAt->format('Y-m-d\TH:i'),
                'ends_at' => $startsAt->copy()->addHour()->format('Y-m-d\TH:i'),
                // 画面から何を送られても 1 名に直す
                'capacity' => 8,
                'status' => LessonSlotStatus::Open->value,
            ])
            ->assertRedirect(route('lesson-slots.index'));

        $this->assertSame(1, LessonSlot::query()->sole()->capacity);
    }

    // --- 3. 定員の業務ルール ---------------------------------------------

    #[Test]
    public function the_capacity_cannot_be_set_below_the_number_of_reservations(): void
    {
        $slot = $this->slot(['capacity' => 5]);

        // 予約中 2 件 + 繰上確定 1 件 = 席を占めているのは 3 件
        $this->reserve($slot, 2);
        $this->reserve($slot, 1, ReservationStatus::Promoted);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->from(route('lesson-slots.index'))
            ->put(route('lesson-slots.update', $slot->id), $this->payload($slot, ['capacity' => 2]))
            ->assertRedirect(route('lesson-slots.index'))
            ->assertSessionHasErrors('capacity');

        $this->assertSame(5, $slot->fresh()?->capacity, '定員は変更されない。');
    }

    #[Test]
    public function the_capacity_can_be_reduced_down_to_the_number_of_reservations(): void
    {
        $slot = $this->slot(['capacity' => 8]);
        $this->reserve($slot, 3);

        // キャンセル済みは席を占めないので、下限には数えない
        $this->reserve($slot, 1, ReservationStatus::Canceled);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->put(route('lesson-slots.update', $slot->id), $this->payload($slot, ['capacity' => 3]))
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $slot->fresh()?->capacity);
    }

    // --- 4. 中止の連鎖 ---------------------------------------------------

    #[Test]
    public function canceling_a_slot_cancels_its_reservations_and_waitlists(): void
    {
        $slot = $this->slot(['capacity' => 2, 'status' => LessonSlotStatus::Open]);

        $reservations = $this->reserve($slot, 2);

        // すでにキャンセル済みの予約は触らない（キャンセル日時が上書きされない）
        $alreadyCanceled = Reservation::factory()->create([
            'lesson_slot_id' => $slot->id,
            'user_id' => $this->userWithRole(RoleName::Member)->id,
            'status' => ReservationStatus::Canceled,
            'canceled_at' => Carbon::parse('2026-01-01 10:00'),
        ]);

        foreach ([1, 2] as $position) {
            Waitlist::factory()->create([
                'lesson_slot_id' => $slot->id,
                'user_id' => $this->userWithRole(RoleName::Member)->id,
                'position' => $position,
                'status' => WaitlistStatus::Waiting,
            ]);
        }

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->put(route('lesson-slots.update', $slot->id), $this->payload($slot, [
                'status' => LessonSlotStatus::Canceled->value,
            ]))
            ->assertRedirect(route('lesson-slots.index'));

        $this->assertTrue($slot->fresh()->isCanceled());

        foreach ($reservations as $reservation) {
            $reservation->refresh();

            $this->assertSame(ReservationStatus::Canceled, $reservation->status);
            $this->assertNotNull($reservation->canceled_at);
        }

        $this->assertSame(
            '2026-01-01 10:00:00',
            $alreadyCanceled->fresh()?->canceled_at?->toDateTimeString(),
            'すでにキャンセル済みの予約は触らない。',
        );

        $this->assertSame(0, $slot->waitlists()->where('status', WaitlistStatus::Waiting->value)->count());
        $this->assertSame(2, $slot->waitlists()->where('status', WaitlistStatus::Canceled->value)->count());

        // 席を占める予約が無くなるので、残枠は定員そのものに戻る
        $this->assertSame(0, $slot->fresh()->reservedCount());
    }

    // --- 5. 繰り返し登録 -------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function recurringPayload(Instructor $instructor, array $overrides = []): array
    {
        // 火・木の 2 週間ぶん（基準日は必ず月曜にそろえる）
        $monday = Carbon::now()->addWeek()->startOfWeek();

        return array_merge([
            'instructor_id' => $instructor->id,
            'title' => '朝のベーシックヨガ',
            'lesson_type' => LessonType::Group->value,
            'capacity' => 12,
            'online_url' => 'https://example.com/meet/morning',
            'status' => LessonSlotStatus::Open->value,
            'weekdays' => [2, 4],
            'start_time' => '07:30',
            'end_time' => '08:15',
            'date_from' => $monday->toDateString(),
            'date_to' => $monday->copy()->addDays(13)->toDateString(),
        ], $overrides);
    }

    #[Test]
    public function the_recurring_form_creates_one_slot_per_matching_date(): void
    {
        $instructor = Instructor::factory()->create();

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->post(route('lesson-slots.store-recurring'), $this->recurringPayload($instructor))
            ->assertRedirect(route('lesson-slots.index'));

        // 火・木 × 2 週間 = 4 枠
        $slots = LessonSlot::query()->orderBy('starts_at')->get();

        $this->assertCount(4, $slots);

        foreach ($slots as $slot) {
            $this->assertContains($slot->starts_at->dayOfWeek, [2, 4]);
            $this->assertSame('07:30', $slot->starts_at->format('H:i'));
            $this->assertSame('08:15', $slot->ends_at->format('H:i'));
            $this->assertSame(12, $slot->capacity);
            $this->assertSame($instructor->id, $slot->instructor_id);
        }

        // 生成された枠は独立していて、1 枠だけ変えられる
        $first = $slots->first();
        $first->update(['capacity' => 6]);

        $this->assertSame(6, $first->fresh()?->capacity);
        $this->assertSame(12, $slots->last()->fresh()?->capacity);
    }

    #[Test]
    public function the_recurring_form_skips_slots_that_already_exist(): void
    {
        $instructor = Instructor::factory()->create();
        $admin = $this->userWithRole(RoleName::Admin);

        $this->actingAs($admin)
            ->post(route('lesson-slots.store-recurring'), $this->recurringPayload($instructor));

        $this->assertSame(4, LessonSlot::query()->count());

        // もう一度、同じ条件に 1 週間ぶん足して流す
        $monday = Carbon::now()->addWeek()->startOfWeek();

        $this->actingAs($admin)
            ->post(route('lesson-slots.store-recurring'), $this->recurringPayload($instructor, [
                'date_to' => $monday->copy()->addDays(20)->toDateString(),
            ]))
            ->assertRedirect(route('lesson-slots.index'));

        // 重なった 4 件はスキップし、増えた 1 週間ぶん（火・木）の 2 件だけ作られる
        $this->assertSame(6, LessonSlot::query()->count());

        $this->assertStringContainsString('2 件の枠を開講しました。', $this->flashMessage());
        $this->assertStringContainsString('4 件はスキップ', $this->flashMessage());
    }

    #[Test]
    public function a_different_instructor_at_the_same_time_is_not_a_duplicate(): void
    {
        $first = Instructor::factory()->create();
        $second = Instructor::factory()->create();
        $admin = $this->userWithRole(RoleName::Admin);

        $this->actingAs($admin)->post(route('lesson-slots.store-recurring'), $this->recurringPayload($first));
        $this->actingAs($admin)->post(route('lesson-slots.store-recurring'), $this->recurringPayload($second));

        // 同じ日時でも講師が違えば別の枠として開講できる（設計書 4. 同一日時に複数レッスン）
        $this->assertSame(8, LessonSlot::query()->count());
    }

    #[Test]
    public function the_recurring_form_rejects_conditions_that_produce_no_slots(): void
    {
        $instructor = Instructor::factory()->create();
        $monday = Carbon::now()->addWeek()->startOfWeek();

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->from(route('lesson-slots.create'))
            ->post(route('lesson-slots.store-recurring'), $this->recurringPayload($instructor, [
                // 月曜だけの指定で、期間は火〜水しかない
                'weekdays' => [1],
                'date_from' => $monday->copy()->addDay()->toDateString(),
                'date_to' => $monday->copy()->addDays(2)->toDateString(),
            ]))
            ->assertSessionHasErrors('weekdays');

        $this->assertSame(0, LessonSlot::query()->count());
    }

    // --- 権限（自分の枠だけ編集できる） ----------------------------------

    #[Test]
    public function a_staff_member_can_only_edit_their_own_slots(): void
    {
        $mine = Instructor::factory()->create();
        $others = Instructor::factory()->create();

        $teacher = $this->instructorUser($mine);

        $ownSlot = $this->slot(['instructor_id' => $mine->id]);
        $otherSlot = $this->slot(['instructor_id' => $others->id]);

        // 自分の枠は編集できる
        $this->actingAs($teacher)
            ->put(route('lesson-slots.update', $ownSlot->id), $this->payload($ownSlot, ['title' => '自分の枠']))
            ->assertRedirect(route('lesson-slots.index'));

        $this->assertSame('自分の枠', $ownSlot->fresh()?->title);

        // ほかの講師の枠は編集できない（一覧・詳細は見られる）
        $this->actingAs($teacher)
            ->put(route('lesson-slots.update', $otherSlot->id), $this->payload($otherSlot, ['title' => '横取り']))
            ->assertForbidden();

        $this->actingAs($teacher)
            ->get(route('lesson-slots.detail', $otherSlot->id))
            ->assertOk()
            ->assertSee('担当の講師（または管理者）だけが編集できます')
            ->assertDontSee(route('lesson-slots.update', $otherSlot->id), false);
    }

    #[Test]
    public function an_instructor_can_be_linked_to_a_login_user(): void
    {
        $instructor = Instructor::factory()->create();
        $teacher = $this->instructorUser($instructor);

        // instructors.user_id で講師とログインユーザーが 1 対 1 に結びつく
        $this->assertSame($teacher->id, $instructor->fresh()?->user_id);
        $this->assertSame($instructor->id, $teacher->instructor?->id);

        $this->assertTrue($this->slot(['instructor_id' => $instructor->id])->isOwnedBy($teacher));
        $this->assertFalse($this->slot()->isOwnedBy($teacher));
    }

    #[Test]
    public function an_admin_can_edit_every_slot(): void
    {
        $instructor = Instructor::factory()->create();
        $this->instructorUser($instructor);

        $slot = $this->slot(['instructor_id' => $instructor->id]);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->put(route('lesson-slots.update', $slot->id), $this->payload($slot, ['title' => '管理者が調整']))
            ->assertRedirect(route('lesson-slots.index'));

        $this->assertSame('管理者が調整', $slot->fresh()?->title);
    }

    /**
     * 直前のリダイレクトで出したトーストの本文。
     */
    private function flashMessage(): string
    {
        /** @var array{type: string, message: string}|null $toast */
        $toast = session()->get(Toast::SESSION_KEY);

        return $toast['message'] ?? '';
    }
}
