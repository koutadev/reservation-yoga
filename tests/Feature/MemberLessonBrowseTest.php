<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Models\Instructor;
use App\Models\LessonSlot;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * STEP3: 会員側の空き枠閲覧の検証。
 *
 * 見るのは次の 6 点。
 *   1. 出る枠は「これから始まる、開講または締切の枠」。締切は受付終了、中止は出さない（DEC-016）
 *   2. スマホの日付切替リストで、その日のレッスンだけが並ぶ
 *   3. PC の週カレンダーで、ゲージ（予約数 ÷ 定員）と同時間帯の上下積みが正しい
 *   4. 残枠は「定員 − 席を占める予約数」で、クエリ本数は枠の件数に依存しない
 *   5. 絞り込み（講師・形式）が効く
 *   6. 会員（reservation.book）だけが開け、画面は管理レイアウトと分かれている
 */
class MemberLessonBrowseTest extends TestCase
{
    use RefreshDatabase;

    /** 週の途中（火曜）に固定して、週の範囲を動かさずに確かめる */
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

    private function member(): User
    {
        return $this->userWithRole(RoleName::Member);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function slot(string $startsAt, array $attributes = []): LessonSlot
    {
        $start = Carbon::parse($startsAt);

        return LessonSlot::factory()->create(array_merge([
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(45),
        ], $attributes));
    }

    /**
     * 席を占める予約を n 件つくる。
     */
    private function reserve(LessonSlot $slot, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Reservation::factory()->create([
                'lesson_slot_id' => $slot->id,
                'user_id' => User::factory()->create()->id,
                'status' => ReservationStatus::Reserved,
            ]);
        }
    }

    /**
     * 画面の一部分だけを取り出す。
     *
     * 一覧はスマホ向けリストと PC 向けカレンダーを同じ HTML に含むので、
     * 「リストには出るがカレンダーには出ない」といった確認をこれで行う。
     */
    private function section(string $html, string $start, ?string $end = null): string
    {
        $from = strpos($html, $start);

        $this->assertNotFalse($from, $start.' が画面にありません。');

        $to = $end === null ? strlen($html) : strpos($html, $end, $from);

        return substr($html, $from, ($to === false ? strlen($html) : $to) - $from);
    }

    private function dayList(string $html): string
    {
        return $this->section($html, 'id="day-list"', 'id="week-calendar"');
    }

    private function calendar(string $html): string
    {
        return $this->section($html, 'id="week-calendar"');
    }

    // --- 一覧に出る枠 -----------------------------------------------------

    #[Test]
    public function the_list_shows_todays_upcoming_lessons(): void
    {
        $instructor = Instructor::factory()->create(['name' => '佐倉 みなと']);

        $this->slot('2026-09-08 19:00', ['title' => '夜のリラックスヨガ', 'instructor_id' => $instructor->id]);

        $html = $this->actingAs($this->member())
            ->get(route('lessons.index'))
            ->assertOk()
            ->getContent();

        $list = $this->dayList($html);

        $this->assertStringContainsString('夜のリラックスヨガ', $list);
        $this->assertStringContainsString('佐倉 みなと', $list);
        $this->assertStringContainsString('19:00 – 19:45', $list);
    }

    #[Test]
    public function closed_lessons_are_listed_as_finished_and_canceled_ones_are_hidden(): void
    {
        $this->slot('2026-09-08 12:00', ['title' => '締切のヨガ'])->update(['status' => 'closed']);
        $this->slot('2026-09-08 13:00', ['title' => '中止のヨガ'])->update(['status' => 'canceled']);

        $response = $this->actingAs($this->member())->get(route('lessons.index'))->assertOk();

        $response->assertSee('締切のヨガ')
            ->assertSee('受付終了')
            // 中止の枠は探す対象ではないので一覧から外す（DEC-016）
            ->assertDontSee('中止のヨガ');
    }

    #[Test]
    public function lessons_that_already_started_are_not_listed(): void
    {
        // 「今」は 2026-09-08 09:00
        $this->slot('2026-09-08 07:30', ['title' => '今朝のヨガ']);
        $this->slot('2026-09-08 10:00', ['title' => 'これからのヨガ']);

        $this->actingAs($this->member())
            ->get(route('lessons.index'))
            ->assertOk()
            ->assertDontSee('今朝のヨガ')
            ->assertSee('これからのヨガ');
    }

    // --- 残枠のバッジ -----------------------------------------------------

    #[Test]
    public function each_card_shows_the_remaining_seats_the_full_mark_or_the_personal_mark(): void
    {
        $open = $this->slot('2026-09-08 10:00', ['title' => '空きのあるヨガ', 'capacity' => 10]);
        $this->reserve($open, 3);

        $full = $this->slot('2026-09-08 11:00', ['title' => '満席のヨガ', 'capacity' => 4]);
        $this->reserve($full, 4);

        $this->slot('2026-09-08 12:00', [
            'title' => 'パーソナルヨガ',
            'capacity' => 1,
            'lesson_type' => 'personal',
        ]);

        $list = $this->dayList(
            $this->actingAs($this->member())->get(route('lessons.index'))->assertOk()->getContent()
        );

        // 残枠 = 定員 − 席を占める予約数
        $this->assertStringContainsString('残り7', $list);
        $this->assertStringContainsString('満席', $list);
        // マンツーマンは「残り 1」ではなく形式が分かるように出す
        $this->assertStringContainsString('個人', $list);
    }

    #[Test]
    public function canceled_reservations_do_not_take_a_seat(): void
    {
        $slot = $this->slot('2026-09-08 10:00', ['capacity' => 5]);

        $this->reserve($slot, 2);

        Reservation::factory()->canceled()->create([
            'lesson_slot_id' => $slot->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->assertStringContainsString(
            '残り3',
            $this->dayList($this->actingAs($this->member())->get(route('lessons.index'))->assertOk()->getContent()),
        );
    }

    // --- 日付切替（スマホ） -----------------------------------------------

    #[Test]
    public function the_date_chips_switch_which_day_is_listed(): void
    {
        $this->slot('2026-09-08 10:00', ['title' => '火曜のヨガ']);
        $this->slot('2026-09-10 10:00', ['title' => '木曜のヨガ']);

        $member = $this->member();

        $tuesday = $this->dayList(
            $this->actingAs($member)->get(route('lessons.index'))->assertOk()->getContent()
        );

        $this->assertStringContainsString('火曜のヨガ', $tuesday);
        $this->assertStringNotContainsString('木曜のヨガ', $tuesday);

        $thursday = $this->dayList(
            $this->actingAs($member)
                ->get(route('lessons.index', ['date' => '2026-09-10']))
                ->assertOk()
                ->getContent()
        );

        $this->assertStringContainsString('木曜のヨガ', $thursday);
        $this->assertStringNotContainsString('火曜のヨガ', $thursday);
    }

    #[Test]
    public function a_day_without_lessons_says_so(): void
    {
        $this->slot('2026-09-10 10:00', ['title' => '木曜のヨガ']);

        $html = $this->actingAs($this->member())
            ->get(route('lessons.index', ['date' => '2026-09-08']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('この日のレッスンはありません', $this->dayList($html));
    }

    #[Test]
    public function the_first_day_with_lessons_is_opened_when_today_has_none(): void
    {
        // 今日（火）には枠がなく、次の開講は翌週の月曜
        $this->slot('2026-09-14 10:00', ['title' => '来週のヨガ']);

        $html = $this->actingAs($this->member())
            ->get(route('lessons.index'))
            ->assertOk()
            ->assertSee('2026年9月 13 – 19')
            ->getContent();

        $this->assertStringContainsString('来週のヨガ', $this->dayList($html));
    }

    // --- 週カレンダー（PC） -----------------------------------------------

    #[Test]
    public function the_week_calendar_shows_the_whole_week_with_a_gauge_for_each_lesson(): void
    {
        $slot = $this->slot('2026-09-10 07:30', ['title' => '朝のベーシックヨガ', 'capacity' => 10]);
        $this->reserve($slot, 6);

        $calendar = $this->calendar(
            $this->actingAs($this->member())->get(route('lessons.index'))->assertOk()->getContent()
        );

        // 面積（予約数 ÷ 定員）・数値の両方で埋まり具合を出す
        $this->assertStringContainsString('height: 60%', $calendar);
        $this->assertStringContainsString('6/10', $calendar);
        $this->assertStringContainsString('07:30', $calendar);
        // 同じ週の枠は、その日を選んでいなくてもカレンダーには出る
        $this->assertStringContainsString('朝のベーシックヨガ', $calendar);
    }

    #[Test]
    public function lessons_at_the_same_time_are_stacked_in_one_cell(): void
    {
        $first = $this->slot('2026-09-09 20:00', ['title' => '夜のリラックスヨガ', 'capacity' => 12]);
        $second = $this->slot('2026-09-09 20:00', ['title' => 'おやすみ前ストレッチ', 'capacity' => 10]);

        $calendar = $this->calendar(
            $this->actingAs($this->member())->get(route('lessons.index'))->assertOk()->getContent()
        );

        $this->assertStringContainsString(route('lessons.show', $first->id), $calendar);
        $this->assertStringContainsString(route('lessons.show', $second->id), $calendar);

        // 時刻の行は 1 本だけ（同じコマに 2 レッスンが積まれる）
        $this->assertSame(
            1,
            substr_count($calendar, 'data-row-time="20:00"'),
            '同じ時刻の行が 2 本できている。',
        );
    }

    #[Test]
    public function a_full_lesson_is_painted_to_the_top_of_the_cell(): void
    {
        $slot = $this->slot('2026-09-09 20:00', ['title' => '満席のヨガ', 'capacity' => 6]);
        $this->reserve($slot, 6);

        $calendar = $this->calendar(
            $this->actingAs($this->member())->get(route('lessons.index'))->assertOk()->getContent()
        );

        $this->assertStringContainsString('height: 100%', $calendar);
        $this->assertStringContainsString('満席', $calendar);
    }

    #[Test]
    public function next_week_can_be_opened_from_the_week_navigation(): void
    {
        $this->slot('2026-09-15 10:00', ['title' => '来週のヨガ']);

        $this->actingAs($this->member())
            ->get(route('lessons.index', ['date' => '2026-09-13']))
            ->assertOk()
            ->assertSee('来週のヨガ')
            ->assertSee('2026年9月 13 – 19');
    }

    // --- 絞り込み ---------------------------------------------------------

    #[Test]
    public function the_list_can_be_narrowed_by_instructor_and_lesson_type(): void
    {
        $sakura = Instructor::factory()->create(['name' => '佐倉 みなと']);
        $kisaragi = Instructor::factory()->create(['name' => '如月 あかり']);

        $this->slot('2026-09-08 10:00', ['title' => '佐倉のグループ', 'instructor_id' => $sakura->id]);
        $this->slot('2026-09-08 11:00', ['title' => '如月のグループ', 'instructor_id' => $kisaragi->id]);
        $this->slot('2026-09-08 12:00', [
            'title' => '佐倉のパーソナル',
            'instructor_id' => $sakura->id,
            'lesson_type' => 'personal',
            'capacity' => 1,
        ]);

        $member = $this->member();

        $this->actingAs($member)
            ->get(route('lessons.index', ['instructor_id' => $sakura->id]))
            ->assertOk()
            ->assertSee('佐倉のグループ')
            ->assertDontSee('如月のグループ');

        $this->actingAs($member)
            ->get(route('lessons.index', ['lesson_type' => 'personal']))
            ->assertOk()
            ->assertSee('佐倉のパーソナル')
            ->assertDontSee('佐倉のグループ');
    }

    // --- クエリ本数 -------------------------------------------------------

    #[Test]
    public function the_number_of_queries_does_not_grow_with_the_number_of_lessons(): void
    {
        $member = $this->member();

        // 権限などのキャッシュを温めてから数える
        $this->actingAs($member)->get(route('lessons.index'))->assertOk();

        foreach (range(0, 2) as $index) {
            $slot = $this->slot('2026-09-08 '.(10 + $index).':00', ['capacity' => 6]);
            $this->reserve($slot, 2);
        }

        $few = $this->countQueries(fn () => $this->actingAs($member)->get(route('lessons.index'))->assertOk());

        foreach (range(0, 29) as $index) {
            $slot = $this->slot('2026-09-09 '.(7 + $index % 12).':00', ['capacity' => 6]);
            $this->reserve($slot, 2);
        }

        $many = $this->countQueries(fn () => $this->actingAs($member)->get(route('lessons.index'))->assertOk());

        // 実測は 4 本（ログイン中のユーザー / 最初に開く日 / その週の枠 / 講師の候補）
        $this->assertSame($few, $many, "枠を増やしたらクエリが増えた（{$few} → {$many}）。");
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

    // --- 権限・レイアウト -------------------------------------------------

    #[Test]
    public function only_members_can_browse_the_lessons(): void
    {
        $slot = $this->slot('2026-09-08 10:00');

        foreach ([RoleName::Staff, RoleName::Viewer] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)->get(route('lessons.index'))->assertForbidden();
            $this->actingAs($user)->get(route('lessons.show', $slot->id))->assertForbidden();
        }

        // 管理者は全権限を持つので開ける
        $this->actingAs($this->userWithRole(RoleName::Admin))->get(route('lessons.index'))->assertOk();
    }

    #[Test]
    public function a_visitor_is_sent_to_the_login_screen(): void
    {
        $this->get(route('lessons.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_member_screens_do_not_use_the_admin_layout(): void
    {
        $this->slot('2026-09-08 10:00');

        $this->actingAs($this->member())
            ->get(route('lessons.index'))
            ->assertOk()
            ->assertSee('レッスンを探す')
            // 管理側のナビゲーション（サイドバー）は会員に出さない
            ->assertDontSee(route('masters.index'))
            ->assertDontSee(route('lesson-slots.index'))
            ->assertDontSee('マスタ管理');
    }

    #[Test]
    public function a_member_lands_on_the_lesson_list_after_logging_in(): void
    {
        $member = $this->member();

        $this->post(route('login'), ['email' => $member->email, 'password' => 'password'])
            ->assertRedirect(route('lessons.index'));

        $this->post(route('logout'));

        $admin = $this->userWithRole(RoleName::Admin);

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
    }

    // --- 詳細への導線 -----------------------------------------------------

    #[Test]
    public function a_lesson_card_links_to_the_detail_screen(): void
    {
        $slot = $this->slot('2026-09-08 10:00', ['title' => '朝のベーシックヨガ']);

        $this->actingAs($this->member())
            ->get(route('lessons.index'))
            ->assertOk()
            ->assertSee(route('lessons.show', $slot->id));
    }

    #[Test]
    public function the_detail_screen_shows_the_lesson_but_cannot_book_yet(): void
    {
        $instructor = Instructor::factory()->create(['name' => '佐倉 みなと', 'profile' => 'RYT200 取得。']);

        $slot = $this->slot('2026-09-10 07:30', [
            'title' => '朝のベーシックヨガ',
            'instructor_id' => $instructor->id,
            'capacity' => 10,
        ]);

        $this->reserve($slot, 3);

        $this->actingAs($this->member())
            ->get(route('lessons.show', $slot->id))
            ->assertOk()
            ->assertSee('朝のベーシックヨガ')
            ->assertSee('佐倉 みなと')
            ->assertSee('RYT200 取得。')
            ->assertSee('7 / 10')
            ->assertSee($slot->code)
            // キャンセル期限は config から（DEC-016）
            ->assertSee('開始 2 時間前まで')
            // 予約の確定は STEP4。ここではまだ押せない
            ->assertSee('予約する')
            ->assertSee('予約の受付は準備中です。');
    }

    #[Test]
    public function the_detail_screen_explains_why_a_lesson_cannot_be_booked(): void
    {
        $member = $this->member();

        $full = $this->slot('2026-09-10 10:00', ['capacity' => 2]);
        $this->reserve($full, 2);

        $this->actingAs($member)
            ->get(route('lessons.show', $full->id))
            ->assertOk()
            ->assertSee('キャンセル待ちに登録')
            ->assertSee('満席です。');

        $canceled = $this->slot('2026-09-10 11:00');
        $canceled->update(['status' => 'canceled']);

        $this->actingAs($member)
            ->get(route('lessons.show', $canceled->id))
            ->assertOk()
            ->assertSee('中止になりました')
            ->assertSee('このレッスンは開催されません。');
    }
}
