<?php

namespace Tests\Feature;

use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Reservations\ReservationBooking;
use App\Support\Reservations\ReservationCancellation;
use App\Support\Reservations\WaitlistRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

/**
 * STEP6-1: マイ予約の検証。
 *
 * 見るのは次の 4 点。
 *   1. 予約中 / キャンセル待ち / 履歴 の 3 つに分かれて出る
 *   2. 繰上確定（キャンセル待ちから回ってきた席）が予約中と区別して見える
 *   3. この画面からキャンセルでき、終わったらマイ予約に戻る
 *   4. 件数が増えてもクエリ本数が変わらない（履歴はページング）
 */
class MyReservationsTest extends TestCase
{
    use MakesReservations;
    use RefreshDatabase;

    private const NOW = '2026-09-08 09:00:00';

    /** 予約を作るたびにずらす日数（同じ会員の予約が時間帯で重ならないように） */
    private int $dayCursor = 0;

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
     * 画面の一部分だけを取り出す（節ごとに何が出ているかを見るため）。
     */
    private function section(string $html, string $heading, ?string $nextHeading = null): string
    {
        $from = strpos($html, $heading);

        $this->assertNotFalse($from, $heading.' の節が画面にありません。');

        $to = $nextHeading === null ? strlen($html) : strpos($html, $nextHeading, $from);

        return substr($html, $from, ($to === false ? strlen($html) : $to) - $from);
    }

    private function myPage(User $member): string
    {
        return $this->actingAs($member)
            ->get(route('my-reservations.index'))
            ->assertOk()
            ->getContent();
    }

    // --- 3 つの節 -----------------------------------------------------------

    #[Test]
    public function the_page_splits_reservations_into_upcoming_waiting_and_history(): void
    {
        $member = $this->member();

        // 予約中
        $upcoming = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['title' => 'これから受けるヨガ']);
        ReservationBooking::book($upcoming, $member);

        // キャンセル待ち（満席の枠）
        $full = $this->slot('2026-09-11 10:00', '2026-09-11 11:00', ['title' => '満席のヨガ', 'capacity' => 1]);
        $this->fill($full, 1);
        WaitlistRegistration::join($full, $member);

        // 履歴（終わった枠）
        $past = $this->slot('2026-09-01 10:00', '2026-09-01 11:00', ['title' => '受けたヨガ']);
        Reservation::factory()->create([
            'lesson_slot_id' => $past->id,
            'user_id' => $member->id,
            'status' => ReservationStatus::Reserved,
        ]);

        $html = $this->myPage($member);

        $upcomingSection = $this->section($html, '>予約中<', '>キャンセル待ち<');
        $waitingSection = $this->section($html, '>キャンセル待ち<', '>履歴<');
        $historySection = $this->section($html, '>履歴<');

        $this->assertStringContainsString('これから受けるヨガ', $upcomingSection);
        $this->assertStringContainsString('確定', $upcomingSection);

        $this->assertStringContainsString('満席のヨガ', $waitingSection);
        $this->assertStringContainsString('待ち 1 番目', $waitingSection);

        $this->assertStringContainsString('受けたヨガ', $historySection);
        $this->assertStringContainsString('受講済み', $historySection);
    }

    #[Test]
    public function a_promoted_reservation_is_shown_as_such(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 1]);
        [$booked] = $this->fill($slot, 1);

        $member = $this->member();
        WaitlistRegistration::join($slot, $member);

        // 先に予約していた人がキャンセル → 待っていた会員が繰り上がる
        ReservationCancellation::cancel(Reservation::query()->where('user_id', $booked->id)->sole());

        $html = $this->myPage($member);

        $upcoming = $this->section($html, '>予約中<', '>履歴<');

        $this->assertStringContainsString('繰り上がりました', $upcoming);
        $this->assertStringContainsString('キャンセル待ちから繰り上がって確定しました。', $upcoming);
    }

    #[Test]
    public function the_upcoming_section_links_to_the_online_lesson(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', [
            'online_url' => 'https://example.com/meet/room-1',
        ]);

        ReservationBooking::book($slot, $member);

        $this->actingAs($member)
            ->get(route('my-reservations.index'))
            ->assertOk()
            ->assertSee('オンラインで参加する')
            ->assertSee('https://example.com/meet/room-1', false);
    }

    #[Test]
    public function canceled_and_called_off_lessons_land_in_the_history(): void
    {
        $member = $this->member();

        $canceledByMember = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['title' => '自分でキャンセルしたヨガ']);
        $reservation = ReservationBooking::book($canceledByMember, $member);
        ReservationCancellation::cancel($reservation);

        $calledOff = $this->slot('2026-09-12 10:00', '2026-09-12 11:00', ['title' => '中止になったヨガ']);
        Reservation::factory()->create([
            'lesson_slot_id' => $calledOff->id,
            'user_id' => $member->id,
            'status' => ReservationStatus::Reserved,
        ]);
        $calledOff->update(['status' => LessonSlotStatus::Canceled]);

        $html = $this->myPage($member);

        $upcoming = $this->section($html, '>予約中<', '>履歴<');
        $history = $this->section($html, '>履歴<');

        $this->assertStringContainsString('予約中のレッスンはありません', $upcoming);
        $this->assertStringContainsString('自分でキャンセルしたヨガ', $history);
        $this->assertStringContainsString('中止になったヨガ', $history);
        $this->assertStringContainsString('中止', $history);
    }

    #[Test]
    public function the_waiting_section_shows_the_current_place_in_line(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 1]);
        $this->fill($slot, 1);

        WaitlistRegistration::join($slot, $this->member());

        $member = $this->member();
        WaitlistRegistration::join($slot, $member);

        $this->actingAs($member)
            ->get(route('my-reservations.index'))
            ->assertOk()
            ->assertSee('待ち 2 番目');
    }

    // --- キャンセル ---------------------------------------------------------

    #[Test]
    public function a_reservation_can_be_canceled_from_this_page(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00');

        $reservation = ReservationBooking::book($slot, $member);

        $this->actingAs($member)
            ->get(route('my-reservations.index'))
            ->assertOk()
            ->assertSee(route('reservations.cancel', $reservation->id), false);

        // マイ予約から取り消したら、マイ予約に戻る
        $this->actingAs($member)
            ->delete(route('reservations.cancel', $reservation->id), ['from' => 'my-reservations'])
            ->assertRedirect(route('my-reservations.index'));

        $this->assertSame(ReservationStatus::Canceled, $reservation->fresh()->status);
    }

    #[Test]
    public function a_waitlist_can_be_withdrawn_from_this_page(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['capacity' => 1]);
        $this->fill($slot, 1);

        $waitlist = WaitlistRegistration::join($slot, $member);

        $this->actingAs($member)
            ->delete(route('waitlists.cancel', $waitlist->id), ['from' => 'my-reservations'])
            ->assertRedirect(route('my-reservations.index'));

        $this->assertSame(0, $slot->waitlists()->waiting()->count());
    }

    #[Test]
    public function canceling_from_the_lesson_detail_still_returns_to_the_lesson(): void
    {
        $member = $this->member();
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00');

        $reservation = ReservationBooking::book($slot, $member);

        $this->actingAs($member)
            ->delete(route('reservations.cancel', $reservation->id))
            ->assertRedirect(route('lessons.show', $slot->id));
    }

    // --- ほかの人のものは出ない --------------------------------------------

    #[Test]
    public function a_member_only_sees_their_own_reservations(): void
    {
        $slot = $this->slot('2026-09-10 10:00', '2026-09-10 11:00', ['title' => 'ほかの人のヨガ']);
        ReservationBooking::book($slot, $this->member());

        $this->actingAs($this->member())
            ->get(route('my-reservations.index'))
            ->assertOk()
            ->assertDontSee('ほかの人のヨガ')
            ->assertSee('予約中のレッスンはありません');
    }

    #[Test]
    public function only_members_can_open_my_reservations(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(RoleName::Staff->value);

        $this->actingAs($staff)->get(route('my-reservations.index'))->assertForbidden();
    }

    #[Test]
    public function a_visitor_is_sent_to_the_login_screen(): void
    {
        $this->get(route('my-reservations.index'))->assertRedirect(route('login'));
    }

    // --- ページングとクエリ本数 --------------------------------------------

    #[Test]
    public function the_history_is_paginated(): void
    {
        $member = $this->member();

        foreach (range(1, 12) as $day) {
            $slot = $this->slot(
                '2026-08-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 10:00',
                '2026-08-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 11:00',
                ['title' => '過去のヨガ'.$day],
            );

            Reservation::factory()->create([
                'lesson_slot_id' => $slot->id,
                'user_id' => $member->id,
                'status' => ReservationStatus::Reserved,
            ]);
        }

        // 新しい順に 10 件、続きは 2 ページ目
        $this->actingAs($member)
            ->get(route('my-reservations.index'))
            ->assertOk()
            ->assertSee('過去のヨガ12')
            ->assertDontSee('過去のヨガ2<')
            ->assertSee('page=2', false);

        $this->actingAs($member)
            ->get(route('my-reservations.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('過去のヨガ2');
    }

    #[Test]
    public function the_number_of_queries_does_not_grow_with_the_number_of_reservations(): void
    {
        $member = $this->member();

        // 権限などのキャッシュを温めてから数える
        $this->actingAs($member)->get(route('my-reservations.index'))->assertOk();

        $this->buildHistory($member, 2);
        $few = $this->countQueries(fn () => $this->actingAs($member)->get(route('my-reservations.index'))->assertOk());

        $this->buildHistory($member, 20);
        $many = $this->countQueries(fn () => $this->actingAs($member)->get(route('my-reservations.index'))->assertOk());

        // 実測は 10 本（ユーザー / 3 つの節のクエリと eager load / 履歴の件数）
        $this->assertSame($few, $many, "予約を増やしたらクエリが増えた（{$few} → {$many}）。");
    }

    /**
     * 予約中・待ち・履歴をまとめて増やす。
     */
    private function buildHistory(User $member, int $count): void
    {
        foreach (range(1, $count) as $ignored) {
            // 同じ会員なので、時間帯が重ならないよう 1 件ずつ日をずらす
            $this->dayCursor++;

            $day = Carbon::parse('2026-09-20')->addDays($this->dayCursor)->format('Y-m-d');
            $pastDay = Carbon::parse('2026-08-20')->subDays($this->dayCursor)->format('Y-m-d');

            $upcoming = $this->slot($day.' 10:00', $day.' 11:00', ['capacity' => 1]);
            ReservationBooking::book($upcoming, $member);

            $full = $this->slot($day.' 19:00', $day.' 20:00', ['capacity' => 1]);
            $this->fill($full, 1);
            WaitlistRegistration::join($full, $member);

            $past = $this->slot($pastDay.' 10:00', $pastDay.' 11:00');
            Reservation::factory()->create([
                'lesson_slot_id' => $past->id,
                'user_id' => $member->id,
                'status' => ReservationStatus::Reserved,
            ]);
        }
    }
}
