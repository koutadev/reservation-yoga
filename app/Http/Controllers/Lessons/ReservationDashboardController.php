<?php

namespace App\Http\Controllers\Lessons;

use App\Enums\LessonSlotStatus;
use App\Http\Controllers\Controller;
use App\Models\LessonSlot;
use App\Support\Dashboard\Kpi;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * 予約状況ダッシュボード（管理／講師）。
 *
 * その日の稼働を 1 画面で掴むための画面。KPI カードは共通基盤の Kpi をそのまま使う
 * （ダッシュボードの作りは CRM 側と同じ）。
 *
 * 集計はすべて「その日の枠を 1 回引いた結果」から組み立てるので、
 * 枠や予約が増えてもクエリ本数は変わらない。
 */
class ReservationDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $today = Carbon::today();
        $requested = $this->requestedDate($request);
        $date = $requested ?? $today;

        $slots = $this->slotsOn($date);

        // 日付の指定がなく、その日に開講が無いときは、次に開講がある日を出す
        $fallback = null;

        if ($requested === null && $slots->isEmpty()) {
            $fallback = $this->nextLessonDate($today);

            if ($fallback !== null) {
                $date = $fallback;
                $slots = $this->slotsOn($date);
            }
        }

        return view('lessons.dashboard', [
            'date' => $date,
            'today' => $today,
            'slots' => $slots,
            'kpis' => $this->kpis($slots),
            'previousDate' => $date->copy()->subDay()->toDateString(),
            'nextDate' => $date->copy()->addDay()->toDateString(),
            // 本日ではなく「次に開講がある日」を出しているか
            'shiftedToNextDay' => $fallback !== null,
            'canManageSlots' => $request->user()?->can('viewAny', LessonSlot::class) ?? false,
        ]);
    }

    /**
     * その日の枠（予約数・キャンセル待ち人数つき）。
     *
     * @return Collection<int, LessonSlot>
     */
    private function slotsOn(Carbon $date): Collection
    {
        return LessonSlot::query()
            ->with('instructor:id,name')
            // 予約数・待ち人数は枠に持たせていないので、ここでまとめて数える
            ->withCount([
                'activeReservations as reserved_count',
                'waitingList as waiting_count',
            ])
            ->whereBetween('starts_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * KPI カード。その日の枠を数え直すだけなので、追加のクエリは投げない。
     *
     * @param  Collection<int, LessonSlot>  $slots
     * @return list<Kpi>
     */
    private function kpis(Collection $slots): array
    {
        $open = $slots->where('status', LessonSlotStatus::Open)->count();
        $closed = $slots->where('status', LessonSlotStatus::Closed)->count();
        $canceled = $slots->where('status', LessonSlotStatus::Canceled)->count();

        // 稼働率は「開催する枠」で見る（中止した枠は分母に入れない）
        $held = $slots->where('status', '!=', LessonSlotStatus::Canceled);

        $reserved = (int) $held->sum('reserved_count');
        $capacity = (int) $held->sum('capacity');
        $rate = $capacity > 0 ? (int) round($reserved / $capacity * 100) : 0;

        $waiting = (int) $slots->sum('waiting_count');
        $waitingSlots = $slots->filter(static fn (LessonSlot $slot): bool => $slot->waiting_count > 0)->count();

        return [
            new Kpi(
                label: 'レッスン',
                value: $slots->count(),
                unit: '枠',
                href: route('lesson-slots.index'),
                note: $canceled > 0
                    ? sprintf('開講 %d / 締切 %d / 中止 %d', $open, $closed, $canceled)
                    : sprintf('開講 %d / 締切 %d', $open, $closed),
            ),
            new Kpi(
                label: '予約',
                value: $reserved,
                unit: '件',
                note: sprintf('定員の合計 %s 名', number_format($capacity)),
            ),
            new Kpi(
                label: '平均稼働率',
                value: $rate,
                unit: '%',
                note: $capacity > 0
                    ? sprintf('予約 %d / 定員 %d（中止を除く）', $reserved, $capacity)
                    : '開催する枠がありません',
            ),
            new Kpi(
                label: 'キャンセル待ち',
                value: $waiting,
                unit: '名',
                note: $waitingSlots > 0 ? sprintf('%d 枠で発生中', $waitingSlots) : '待ちはありません',
            ),
        ];
    }

    /**
     * 次に開講がある日。
     */
    private function nextLessonDate(Carbon $from): ?Carbon
    {
        $startsAt = LessonSlot::query()
            ->where('starts_at', '>=', $from->copy()->startOfDay())
            ->orderBy('starts_at')
            ->value('starts_at');

        return $startsAt === null ? null : Carbon::parse($startsAt)->startOfDay();
    }

    private function requestedDate(Request $request): ?Carbon
    {
        $value = (string) $request->query('date');

        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date !== null && $date->format('Y-m-d') === $value ? $date->startOfDay() : null;
    }
}
