<?php

namespace Tests\Feature;

use App\Enums\LessonSlotStatus;
use App\Enums\RoleName;
use App\Models\Instructor;
use App\Models\User;
use App\Support\Dashboard\Kpi;
use App\Support\Reservations\WaitlistRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

/**
 * STEP6-3: 予約状況ダッシュボード（管理／講師）の検証。
 *
 * 見るのは次の 4 点。
 *   1. KPI（レッスン数・予約数・平均稼働率・キャンセル待ち）が正しい
 *   2. その日の枠一覧に、予約 / 定員・稼働ゲージ・待ち・状態が出る
 *   3. 日付を切り替えられる（開講がない日は次に開講がある日を出す）
 *   4. 枠や予約が増えてもクエリ本数が変わらない
 */
class ReservationDashboardTest extends TestCase
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

    private function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    /**
     * @return list<Kpi>
     */
    private function kpisFor(User $user, array $query = []): array
    {
        return $this->actingAs($user)
            ->get(route('reservations.dashboard', $query))
            ->assertOk()
            ->viewData('kpis');
    }

    // --- KPI ----------------------------------------------------------------

    #[Test]
    public function the_kpis_summarise_the_day(): void
    {
        // 本日: 開講 2 / 締切 1 / 中止 1
        $morning = $this->slot('2026-09-08 10:00', '2026-09-08 11:00', ['capacity' => 10]);
        $this->fill($morning, 6);

        $evening = $this->slot('2026-09-08 19:00', '2026-09-08 20:00', ['capacity' => 4]);
        $this->fill($evening, 4);
        WaitlistRegistration::join($evening, $this->member());
        WaitlistRegistration::join($evening, $this->member());

        $closed = $this->slot('2026-09-08 12:00', '2026-09-08 13:00', ['capacity' => 6]);
        $this->fill($closed, 2);
        $closed->update(['status' => LessonSlotStatus::Closed]);

        $canceled = $this->slot('2026-09-08 21:00', '2026-09-08 22:00', ['capacity' => 100]);
        $canceled->update(['status' => LessonSlotStatus::Canceled]);

        // 別の日の枠は数に入らない
        $this->slot('2026-09-09 10:00', '2026-09-09 11:00', ['capacity' => 10]);

        $kpis = $this->kpisFor($this->userWithRole(RoleName::Admin));

        $this->assertCount(4, $kpis);

        $this->assertSame(4, $kpis[0]->value, '本日の枠数（中止も含めて出す）');
        $this->assertSame('開講 2 / 締切 1 / 中止 1', $kpis[0]->note);

        $this->assertSame(12, $kpis[1]->value, '予約 6 + 4 + 2 件');

        // 稼働率は中止を除いた 20 席に対する 12 件 = 60%
        $this->assertSame(60, $kpis[2]->value);
        $this->assertSame('予約 12 / 定員 20（中止を除く）', $kpis[2]->note);

        $this->assertSame(2, $kpis[3]->value, 'キャンセル待ちは 2 名');
        $this->assertSame('1 枠で発生中', $kpis[3]->note);
    }

    #[Test]
    public function a_day_without_lessons_shows_zeroes(): void
    {
        $kpis = $this->kpisFor($this->userWithRole(RoleName::Admin), ['date' => '2026-09-08']);

        $this->assertSame(0, $kpis[0]->value);
        $this->assertSame(0, $kpis[2]->value);
        $this->assertSame('開催する枠がありません', $kpis[2]->note);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('reservations.dashboard', ['date' => '2026-09-08']))
            ->assertOk()
            ->assertSee('この日に開講しているレッスンはありません。');
    }

    // --- 枠一覧 -------------------------------------------------------------

    #[Test]
    public function the_list_shows_each_slot_with_its_load(): void
    {
        $instructor = Instructor::factory()->create(['name' => '佐倉 みなと']);

        $slot = $this->slot('2026-09-08 10:00', '2026-09-08 11:00', [
            'title' => '朝のベーシックヨガ',
            'instructor_id' => $instructor->id,
            'capacity' => 10,
        ]);

        $this->fill($slot, 6);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('reservations.dashboard'))
            ->assertOk()
            ->assertSee('10:00')
            ->assertSee('朝のベーシックヨガ')
            ->assertSee('佐倉 みなと')
            ->assertSee('グループ')
            ->assertSee('6 / 10')
            // 稼働ゲージ（予約数 ÷ 定員）
            ->assertSee('width: 60%', false)
            ->assertSee('開講');
    }

    #[Test]
    public function a_full_slot_shows_its_waiting_list(): void
    {
        $slot = $this->slot('2026-09-08 19:00', '2026-09-08 20:00', ['capacity' => 1]);
        $this->fill($slot, 1);
        WaitlistRegistration::join($slot, $this->member());

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('reservations.dashboard'))
            ->assertOk()
            ->assertSee('width: 100%', false)
            ->assertSee('1 名');
    }

    #[Test]
    public function the_rows_open_the_slot_detail_for_people_who_manage_slots(): void
    {
        $slot = $this->slot('2026-09-08 10:00', '2026-09-08 11:00');

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('reservations.dashboard'))
            ->assertOk()
            ->assertSee(route('lesson-slots.detail', $slot->id), false)
            ->assertSee('open-detail', false);
    }

    // --- 日付の切り替え -----------------------------------------------------

    #[Test]
    public function another_day_can_be_opened(): void
    {
        $this->slot('2026-09-08 10:00', '2026-09-08 11:00', ['title' => '本日のヨガ']);
        $this->slot('2026-09-09 10:00', '2026-09-09 11:00', ['title' => '翌日のヨガ']);

        $admin = $this->userWithRole(RoleName::Admin);

        $this->actingAs($admin)
            ->get(route('reservations.dashboard'))
            ->assertOk()
            ->assertSee('本日のヨガ')
            ->assertDontSee('翌日のヨガ');

        $this->actingAs($admin)
            ->get(route('reservations.dashboard', ['date' => '2026-09-09']))
            ->assertOk()
            ->assertSee('翌日のヨガ')
            ->assertDontSee('本日のヨガ');
    }

    #[Test]
    public function the_next_day_with_lessons_is_shown_when_today_has_none(): void
    {
        $this->slot('2026-09-11 10:00', '2026-09-11 11:00', ['title' => '次に開講するヨガ']);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('reservations.dashboard'))
            ->assertOk()
            ->assertSee('次に開講するヨガ')
            ->assertSee('本日は開講がないため、次に開講がある日を表示しています。');
    }

    // --- 権限 ---------------------------------------------------------------

    #[Test]
    public function only_people_who_manage_reservations_can_open_it(): void
    {
        $this->actingAs($this->userWithRole(RoleName::Staff))
            ->get(route('reservations.dashboard'))
            ->assertOk();

        foreach ([RoleName::Viewer, RoleName::Member] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get(route('reservations.dashboard'))
                ->assertForbidden();
        }
    }

    #[Test]
    public function it_appears_in_the_navigation_for_the_people_who_can_open_it(): void
    {
        $this->actingAs($this->userWithRole(RoleName::Staff))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('予約状況')
            ->assertSee(route('reservations.dashboard'), false);
    }

    // --- クエリ本数 ---------------------------------------------------------

    #[Test]
    public function the_number_of_queries_does_not_grow_with_the_number_of_slots(): void
    {
        $admin = $this->userWithRole(RoleName::Admin);

        // 権限などのキャッシュを温めてから数える
        $this->actingAs($admin)->get(route('reservations.dashboard'))->assertOk();

        $this->buildDay(2);
        $few = $this->countQueries(fn () => $this->actingAs($admin)->get(route('reservations.dashboard'))->assertOk());

        $this->buildDay(20);
        $many = $this->countQueries(fn () => $this->actingAs($admin)->get(route('reservations.dashboard'))->assertOk());

        // 集計はその日の枠を 1 回引いた結果から組み立てる
        $this->assertSame($few, $many, "枠を増やしたらクエリが増えた（{$few} → {$many}）。");
    }

    /**
     * 本日の枠を n 件（それぞれ予約つき）作る。
     */
    private function buildDay(int $count): void
    {
        foreach (range(1, $count) as $index) {
            $slot = $this->slot('2026-09-08 10:00', '2026-09-08 11:00', ['capacity' => 4]);

            $this->fill($slot, 2);
        }
    }
}
