<?php

namespace Tests\Feature;

use App\Enums\BookingDenial;
use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Models\LessonSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Reservations\BookingDenied;
use App\Support\Reservations\ReservationBooking;
use App\Support\Ui\Toast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * STEP4: 予約の確定（業務ルール）の検証。
 *
 * 見るのは次の 5 点。同時実行そのものは ReservationConcurrencyTest で確かめる。
 *   1. 会員が空き枠を予約でき、予約完了画面に着く（コードは RSV-）
 *   2. 満席・締切・中止・開始済みは、画面の表示に関わらず確定時に断られる
 *   3. 同じ枠の二重予約と、時間帯の重なる別枠の予約が断られる（隣接は許す）
 *   4. キャンセル済みの予約は席を占めない
 *   5. 予約まわりの表示クエリが、予約の件数に依存しない
 */
class ReservationBookingTest extends TestCase
{
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

    private function member(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Member->value);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function slot(string $startsAt, string $endsAt, array $attributes = []): LessonSlot
    {
        return LessonSlot::factory()->create(array_merge([
            'starts_at' => Carbon::parse($startsAt),
            'ends_at' => Carbon::parse($endsAt),
            'capacity' => 5,
        ], $attributes));
    }

    /**
     * 席を占める予約で枠を埋める。
     */
    private function fill(LessonSlot $slot, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Reservation::factory()->create([
                'lesson_slot_id' => $slot->id,
                'user_id' => $this->member()->id,
                'status' => ReservationStatus::Reserved,
            ]);
        }
    }

    private function assertDeniedWith(BookingDenial $reason, LessonSlot $slot, User $user): void
    {
        try {
            ReservationBooking::book($slot, $user);

            $this->fail('予約が通ってしまった（期待した拒否: '.$reason->value.'）。');
        } catch (BookingDenied $denied) {
            $this->assertSame($reason, $denied->reason);
        }
    }

    // --- 予約できる ---------------------------------------------------------

    #[Test]
    public function a_member_books_a_lesson_and_lands_on_the_completion_screen(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 07:30', '2026-09-10 08:15', ['title' => '朝のベーシックヨガ']);

        $response = $this->actingAs($member)->post(route('lessons.reserve', $slot->id));

        $reservation = Reservation::query()->sole();

        $response->assertRedirect(route('reservations.complete', $reservation->id));

        $this->assertSame('RSV-2026-0001', $reservation->code, '予約コードは年で区切った連番。');
        $this->assertSame($slot->id, $reservation->lesson_slot_id);
        $this->assertSame($member->id, $reservation->user_id);
        $this->assertSame(ReservationStatus::Reserved, $reservation->status);
        $this->assertNull($reservation->canceled_at);

        $this->actingAs($member)
            ->get(route('reservations.complete', $reservation->id))
            ->assertOk()
            ->assertSee('予約が確定しました')
            ->assertSee($reservation->code)
            ->assertSee('朝のベーシックヨガ');
    }

    #[Test]
    public function the_lesson_shows_as_reserved_after_booking(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 07:30', '2026-09-10 08:15');

        ReservationBooking::book($slot, $member);

        $this->actingAs($member)
            ->get(route('lessons.show', $slot->id))
            ->assertOk()
            ->assertSee('予約済み')
            // 予約済みの枠に、もう一度予約する導線は出さない
            ->assertDontSee(route('lessons.reserve', $slot->id), false);
    }

    #[Test]
    public function another_member_can_still_take_a_remaining_seat(): void
    {
        $slot = $this->slot('2026-09-10 07:30', '2026-09-10 08:15', ['capacity' => 2]);

        ReservationBooking::book($slot, $this->member());
        ReservationBooking::book($slot, $this->member());

        $this->assertSame(2, $slot->activeReservations()->count());
        $this->assertSame(0, $slot->fresh()->remainingSeats());
    }

    // --- 断られる（枠の状態） -----------------------------------------------

    #[Test]
    public function a_full_lesson_is_denied(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 07:30', '2026-09-10 08:15', ['capacity' => 2]);

        $this->fill($slot, 2);

        $this->assertDeniedWith(BookingDenial::Full, $slot, $member);

        $response = $this->actingAs($member)->post(route('lessons.reserve', $slot->id));

        $response->assertRedirect(route('lessons.show', $slot->id));
        $this->assertToastSays($response, '満席');

        $this->assertSame(2, $slot->activeReservations()->count(), '定員を超えて席が増えていない。');
    }

    #[Test]
    public function closed_canceled_and_started_lessons_are_denied(): void
    {
        $cases = [
            // [枠, 期待する理由]
            [$this->slot('2026-09-10 07:30', '2026-09-10 08:15', ['status' => LessonSlotStatus::Closed]), BookingDenial::ReceptionClosed],
            [$this->slot('2026-09-10 09:30', '2026-09-10 10:15', ['status' => LessonSlotStatus::Canceled]), BookingDenial::SlotCanceled],
            // 「今」は 2026-09-08 09:00
            [$this->slot('2026-09-08 07:30', '2026-09-08 08:15'), BookingDenial::AlreadyStarted],
        ];

        foreach ($cases as [$slot, $reason]) {
            $this->assertDeniedWith($reason, $slot, $this->member());
        }

        $this->assertSame(0, Reservation::query()->count());
    }

    #[Test]
    public function the_server_decides_again_when_the_page_was_already_stale(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 07:30', '2026-09-10 08:15', ['capacity' => 1]);

        // 画面を開いた時点では最後の 1 席が空いていて、CTA も出ている
        $this->actingAs($member)
            ->get(route('lessons.show', $slot->id))
            ->assertOk()
            ->assertSee(route('lessons.reserve', $slot->id), false);

        // その画面を開いたまま、ほかの会員に最後の 1 席を取られる
        ReservationBooking::book($slot, $this->member());

        // 送信を受けたサーバは、表示ではなく数え直した結果で断る
        $response = $this->actingAs($member)->post(route('lessons.reserve', $slot->id));

        $response->assertRedirect(route('lessons.show', $slot->id));
        $this->assertToastSays($response, '満席');

        $this->assertSame(1, $slot->activeReservations()->count());
        $this->assertSame(0, Reservation::query()->where('user_id', $member->id)->count());
    }

    #[Test]
    public function a_lesson_closed_after_the_page_was_rendered_is_denied(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 07:30', '2026-09-10 08:15');

        // 画面を開いたあとに、運営が締め切る
        $slot->update(['status' => LessonSlotStatus::Closed]);

        $this->assertDeniedWith(BookingDenial::ReceptionClosed, $slot, $member);
    }

    // --- 断られる（会員の予約状況） -----------------------------------------

    #[Test]
    public function booking_the_same_lesson_twice_is_denied(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 07:30', '2026-09-10 08:15');

        ReservationBooking::book($slot, $member);

        $this->assertDeniedWith(BookingDenial::AlreadyReserved, $slot, $member);

        $this->assertSame(1, $slot->activeReservations()->count());
    }

    #[Test]
    public function a_lesson_that_overlaps_another_reservation_is_denied(): void
    {
        $member = $this->member();

        $booked = $this->slot('2026-09-10 10:00', '2026-09-10 11:00');
        ReservationBooking::book($booked, $member);

        // 後ろにずれて重なる / 前にずれて重なる / すっぽり含まれる
        $overlaps = [
            $this->slot('2026-09-10 10:30', '2026-09-10 11:30'),
            $this->slot('2026-09-10 09:30', '2026-09-10 10:30'),
            $this->slot('2026-09-10 10:15', '2026-09-10 10:45'),
        ];

        foreach ($overlaps as $slot) {
            $this->assertDeniedWith(BookingDenial::TimeConflict, $slot, $member);
        }

        $this->assertSame(1, Reservation::query()->where('user_id', $member->id)->count());
    }

    #[Test]
    public function lessons_that_only_touch_at_the_boundary_can_both_be_booked(): void
    {
        $member = $this->member();

        $first = $this->slot('2026-09-10 10:00', '2026-09-10 11:00');
        $before = $this->slot('2026-09-10 09:00', '2026-09-10 10:00');
        $after = $this->slot('2026-09-10 11:00', '2026-09-10 12:00');

        ReservationBooking::book($first, $member);
        ReservationBooking::book($before, $member);
        ReservationBooking::book($after, $member);

        $this->assertSame(3, Reservation::query()->where('user_id', $member->id)->count(), '隣接は重複ではない。');
    }

    #[Test]
    public function another_member_is_not_blocked_by_someone_elses_reservation(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00');
        $other = $this->slot('2026-09-10 10:30', '2026-09-10 11:30');

        ReservationBooking::book($slot, $this->member());

        // 時間の重なりは「その会員の予約」だけを見る
        $reservation = ReservationBooking::book($other, $this->member());

        $this->assertSame($other->id, $reservation->lesson_slot_id);
    }

    // --- キャンセル済みは席を空ける -----------------------------------------

    #[Test]
    public function canceled_reservations_do_not_hold_a_seat(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 2]);

        $this->fill($slot, 1);

        Reservation::factory()->canceled()->create([
            'lesson_slot_id' => $slot->id,
            'user_id' => $this->member()->id,
        ]);

        // 予約中 1 + キャンセル 1 なので、定員 2 にはまだ 1 席ある
        $reservation = ReservationBooking::book($slot, $member);

        $this->assertSame(ReservationStatus::Reserved, $reservation->status);
        $this->assertSame(2, $slot->activeReservations()->count());
        $this->assertSame(0, $slot->fresh()->remainingSeats());
    }

    #[Test]
    public function a_member_can_book_again_after_their_reservation_was_canceled(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00');

        $first = ReservationBooking::book($slot, $member);
        $first->update(['status' => ReservationStatus::Canceled, 'canceled_at' => now()]);

        // 部分ユニーク（キャンセル済みを除く）なので取り直せる
        $second = ReservationBooking::book($slot, $member);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(1, $slot->activeReservations()->count());
    }

    // --- 権限 ---------------------------------------------------------------

    #[Test]
    public function only_members_can_reserve(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00');

        $staff = User::factory()->create();
        $staff->assignRole(RoleName::Staff->value);

        $this->actingAs($staff)->post(route('lessons.reserve', $slot->id))->assertForbidden();

        $this->assertSame(0, Reservation::query()->count());
    }

    #[Test]
    public function a_visitor_is_sent_to_the_login_screen(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00');

        $this->post(route('lessons.reserve', $slot->id))->assertRedirect(route('login'));

        $this->assertSame(0, Reservation::query()->count());
    }

    #[Test]
    public function the_completion_screen_is_only_for_the_person_who_booked(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00');
        $reservation = ReservationBooking::book($slot, $this->member());

        $this->actingAs($this->member())
            ->get(route('reservations.complete', $reservation->id))
            ->assertForbidden();
    }

    // --- 確定の手順 ---------------------------------------------------------

    #[Test]
    public function the_seats_are_counted_only_after_the_row_lock_is_taken(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00');

        /** @var list<string> $queries */
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        ReservationBooking::book($slot, $member);

        DB::getEventDispatcher()->forget('Illuminate\\Database\\Events\\QueryExecuted');

        $lock = $this->firstQueryMatching($queries, '/select .*lesson_slots.* for update/');
        $count = $this->firstQueryMatching($queries, '/select count\(\*\).* from "reservations"/');
        $insert = $this->firstQueryMatching($queries, '/insert into "reservations"/');

        $this->assertNotNull($lock, '枠の行をロックしていない。');
        $this->assertNotNull($count, '残枠を数えていない。');
        $this->assertNotNull($insert, '予約を作っていない。');

        // ロック → 数える → 作る の順でないと、古い残枠のまま席を作ってしまう
        $this->assertLessThan($count, $lock);
        $this->assertLessThan($insert, $count);
    }

    /**
     * @param  list<string>  $queries
     */
    private function firstQueryMatching(array $queries, string $pattern): ?int
    {
        foreach ($queries as $index => $sql) {
            if (preg_match($pattern, $sql) === 1) {
                return $index;
            }
        }

        return null;
    }

    // --- クエリ本数 ---------------------------------------------------------

    #[Test]
    public function the_number_of_queries_does_not_grow_with_the_number_of_reservations(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 50]);

        // 権限などのキャッシュを温めてから数える
        $this->actingAs($member)->get(route('lessons.show', $slot->id))->assertOk();

        $this->fill($slot, 2);
        $few = $this->countQueries(fn () => $this->actingAs($member)->get(route('lessons.show', $slot->id))->assertOk());

        $this->fill($slot, 30);
        $many = $this->countQueries(fn () => $this->actingAs($member)->get(route('lessons.show', $slot->id))->assertOk());

        // 残枠は件数を数えるだけ（予約を 1 件ずつ引かない）
        $this->assertSame($few, $many, "予約を増やしたらクエリが増えた（{$few} → {$many}）。");
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

        return $count;
    }

    /**
     * 断った理由がトーストで会員に伝わっているか。
     */
    private function assertToastSays(TestResponse $response, string $text): void
    {
        $response->assertSessionHas(Toast::SESSION_KEY, function (array $toast) use ($text): bool {
            return $toast['type'] === 'danger' && str_contains($toast['message'], $text);
        });
    }
}
