<?php

namespace Tests\Unit;

use App\Models\LessonSlot;
use App\Support\Lessons\WeekCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 週カレンダーの組み立て（DB を使わない）。
 *
 * 見るのは 3 点。
 *   1. 行になるのは「開講のある時刻」だけで、早い順に並ぶ
 *   2. 同じ日・同じ時刻のレッスンは同じセルに入る（画面では上下に積む）
 *   3. その週の外の枠は入らない
 */
class WeekCalendarTest extends TestCase
{
    private function slot(int $id, string $startsAt, string $title = 'ヨガ'): LessonSlot
    {
        $slot = new LessonSlot(['title' => $title, 'starts_at' => $startsAt]);
        $slot->id = $id;

        return $slot;
    }

    /**
     * @param  list<LessonSlot>  $slots
     */
    private function calendar(array $slots): WeekCalendar
    {
        // 2026-09-06 は日曜。週は日〜土
        return WeekCalendar::build(Carbon::parse('2026-09-06'), new Collection($slots));
    }

    #[Test]
    public function rows_are_built_only_from_the_times_that_have_lessons(): void
    {
        $calendar = $this->calendar([
            $this->slot(1, '2026-09-09 20:00'),
            $this->slot(2, '2026-09-08 07:30'),
        ]);

        $this->assertSame(['07:30', '20:00'], array_column($calendar->rows(), 'time'));
    }

    #[Test]
    public function lessons_at_the_same_time_share_one_cell(): void
    {
        $calendar = $this->calendar([
            $this->slot(1, '2026-09-09 20:00', '夜のリラックスヨガ'),
            $this->slot(2, '2026-09-09 20:00', 'おやすみ前ストレッチ'),
        ]);

        $rows = $calendar->rows();

        $this->assertCount(1, $rows, '時刻が同じなら行は 1 本にまとまる。');

        // 2026-09-09 は水曜なので、日曜起点で 3 列目
        $cell = $rows[0]['cells'][3];

        $this->assertCount(2, $cell, '同じ日・同じ時刻のレッスンは同じセルに積まれる。');
        $this->assertSame(['夜のリラックスヨガ', 'おやすみ前ストレッチ'], array_map(
            static fn (LessonSlot $slot): string => $slot->title,
            $cell,
        ));

        // ほかの曜日は空
        $this->assertSame([], $rows[0]['cells'][0]);
    }

    #[Test]
    public function lessons_outside_the_week_are_left_out(): void
    {
        $calendar = $this->calendar([
            $this->slot(1, '2026-09-12 10:00', '週の最終日（土）'),
            $this->slot(2, '2026-09-13 10:00', '翌週の日曜'),
        ]);

        $rows = $calendar->rows();

        $this->assertCount(1, $rows);
        $this->assertCount(1, $rows[0]['cells'][6], '土曜のぶんだけが残る。');
    }

    #[Test]
    public function the_day_list_returns_the_lessons_of_that_day(): void
    {
        $calendar = $this->calendar([
            $this->slot(1, '2026-09-08 07:30', '火曜の朝'),
            $this->slot(2, '2026-09-09 20:00', '水曜の夜'),
        ]);

        $slots = $calendar->slotsOn(Carbon::parse('2026-09-08'));

        $this->assertCount(1, $slots);
        $this->assertSame('火曜の朝', $slots[0]->title);
    }

    #[Test]
    public function the_range_label_covers_the_whole_week(): void
    {
        $this->assertSame('2026年9月 6 – 12', $this->calendar([])->rangeLabel());

        $crossesMonths = WeekCalendar::build(Carbon::parse('2026-08-30'), new Collection);

        $this->assertSame('2026年8月30日 – 9月5日', $crossesMonths->rangeLabel());
    }
}
