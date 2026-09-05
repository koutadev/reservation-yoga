<?php

namespace App\Http\Controllers\Members;

use App\Enums\LessonSlotStatus;
use App\Enums\LessonType;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\LessonSlot;
use App\Support\Lessons\SlotAvailability;
use App\Support\Lessons\WeekCalendar;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * 空き枠の閲覧（会員）。
 *
 * 1 回の表示で「その週の枠」をまとめて取り、同じデータを幅で出し分ける。
 *   - スマホ: 日付チップ ＋ その日のレッスンリスト
 *   - PC:     週カレンダー（時刻 × 日〜土、コマは予約数 ÷ 定員のゲージ）
 *
 * 出す枠は「これから始まる、開講または締切の枠」（DEC-016）。
 * 締切は「受付終了」として見せ、中止は一覧から外す。
 *
 * 残枠は枠に保持していないので都度算出するが、一覧では withCount でまとめて
 * 数えるため、枠が何件あってもクエリ本数は変わらない。
 */
class LessonBrowseController extends Controller
{
    public function index(Request $request): View
    {
        $today = Carbon::today();
        $instructorId = $this->instructorId($request);
        $lessonType = LessonType::tryFrom((string) $request->query('lesson_type'));

        $selectedDate = $this->selectedDate($request, $today, $instructorId, $lessonType);
        $weekStart = $selectedDate->copy()->startOfWeek(CarbonInterface::SUNDAY);

        $calendar = WeekCalendar::build(
            $weekStart,
            $this->weekSlots($weekStart, $instructorId, $lessonType),
        );

        // 前の週へ戻るのは「今日を含む週」まで（過去の枠は会員に出さない）
        $previousWeek = $weekStart->copy()->subWeek();
        $isFirstWeek = $previousWeek->lt($today->copy()->startOfWeek(CarbonInterface::SUNDAY));

        return view('members.lessons.index', [
            'calendar' => $calendar,
            'daySlots' => $calendar->slotsOn($selectedDate),
            'selectedDate' => $selectedDate,
            'today' => $today,
            // 絞り込みは日付・週を移動しても引き継ぐ
            'filters' => array_filter([
                'instructor_id' => $instructorId,
                'lesson_type' => $lessonType?->value,
            ], static fn (mixed $value): bool => $value !== null),
            'instructorOptions' => Instructor::query()->active()->orderBy('name')->pluck('name', 'id')->all(),
            'lessonTypeOptions' => LessonType::options(),
            'previousWeekDate' => $isFirstWeek ? null : $this->weekEntryDate($previousWeek, $today),
            'nextWeekDate' => $weekStart->copy()->addWeek()->toDateString(),
        ]);
    }

    /**
     * レッスンの詳細。
     *
     * 予約・キャンセル待ちの登録は STEP4 / STEP5 で実装するため、
     * ここでは内容と受付状況を見せるところまで。
     */
    public function show(int $id): View
    {
        $slot = LessonSlot::query()
            ->with('instructor')
            ->withCount('activeReservations as reserved_count')
            ->findOrFail($id);

        $availability = SlotAvailability::of($slot);

        return view('members.lessons.show', [
            'slot' => $slot,
            'availability' => $availability,
            'waitingCount' => $slot->waitingList()->count(),
            'cancelDeadlineHours' => (int) config('reservation.cancel_deadline_hours', 2),
            'booking' => $this->bookingState($slot, $availability),
        ]);
    }

    /**
     * この週に会員へ見せる枠。
     *
     * 予約数は withCount でまとめて数える（枠ごとに数えない）。
     *
     * @return Collection<int, LessonSlot>
     */
    private function weekSlots(Carbon $weekStart, ?int $instructorId, ?LessonType $lessonType): Collection
    {
        return LessonSlot::query()
            ->with('instructor:id,name')
            ->withCount('activeReservations as reserved_count')
            ->whereIn('status', LessonSlotStatus::browsableValues())
            ->upcoming()
            ->whereBetween('starts_at', [
                $weekStart->copy()->startOfDay(),
                $weekStart->copy()->addDays(6)->endOfDay(),
            ])
            ->when($instructorId !== null, fn ($query) => $query->where('instructor_id', $instructorId))
            ->when($lessonType !== null, fn ($query) => $query->where('lesson_type', $lessonType->value))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * 表示する日。過去は見せないので今日まで戻す。
     *
     * 指定がないときは「次に開講があるレッスンの日」を開く。今日の週に 1 件も
     * ないときに、空のカレンダーを最初に見せないため。
     */
    private function selectedDate(Request $request, Carbon $today, ?int $instructorId, ?LessonType $lessonType): Carbon
    {
        $date = $this->parseDate((string) $request->query('date'));

        if ($date === null) {
            $date = $this->nextLessonDate($instructorId, $lessonType);
        }

        return $date === null || $date->lt($today) ? $today->copy() : $date;
    }

    /**
     * 次に開講があるレッスンの日（絞り込みは効かせる）。
     */
    private function nextLessonDate(?int $instructorId, ?LessonType $lessonType): ?Carbon
    {
        $startsAt = LessonSlot::query()
            ->whereIn('status', LessonSlotStatus::browsableValues())
            ->upcoming()
            ->when($instructorId !== null, fn ($query) => $query->where('instructor_id', $instructorId))
            ->when($lessonType !== null, fn ($query) => $query->where('lesson_type', $lessonType->value))
            ->orderBy('starts_at')
            ->value('starts_at');

        return $startsAt === null ? null : Carbon::parse($startsAt)->startOfDay();
    }

    /**
     * その週を開いたときに選ぶ日（今週なら今日、先の週ならその週の初日）。
     */
    private function weekEntryDate(Carbon $weekStart, Carbon $today): string
    {
        return $weekStart->lt($today) ? $today->toDateString() : $weekStart->toDateString();
    }

    private function parseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        // 2026-13-45 のような値は繰り上がって別の日になるため、往復して確かめる
        return $date !== null && $date->format('Y-m-d') === $value ? $date->startOfDay() : null;
    }

    private function instructorId(Request $request): ?int
    {
        $value = $request->query('instructor_id');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * 詳細画面の受付状況（予約ボタンの見せ方）。
     *
     * 予約の確定そのものは STEP4 で実装する。ここでは「なぜ予約できないか」を
     * 会員に伝えるところまでを持つ。
     *
     * @return array{label: string, note: string, bookable: bool}
     */
    private function bookingState(LessonSlot $slot, SlotAvailability $availability): array
    {
        if ($slot->isCanceled()) {
            return ['label' => '中止になりました', 'note' => 'このレッスンは開催されません。', 'bookable' => false];
        }

        if ($slot->starts_at->isPast()) {
            return ['label' => '終了しました', 'note' => 'このレッスンはすでに終了しています。', 'bookable' => false];
        }

        if ($slot->isClosed()) {
            return ['label' => '受付終了', 'note' => 'このレッスンは受付を終了しました（開催はします）。', 'bookable' => false];
        }

        if ($availability->isFull()) {
            return ['label' => 'キャンセル待ちに登録', 'note' => '満席です。キャンセル待ちの登録は準備中です。', 'bookable' => false];
        }

        return ['label' => '予約する', 'note' => '予約の受付は準備中です。', 'bookable' => true];
    }
}
