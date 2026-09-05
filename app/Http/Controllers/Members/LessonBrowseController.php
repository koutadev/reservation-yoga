<?php

namespace App\Http\Controllers\Members;

use App\Enums\LessonSlotStatus;
use App\Enums\LessonType;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\LessonSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Waitlist;
use App\Support\Lessons\SlotAvailability;
use App\Support\Lessons\WeekCalendar;
use App\Support\Reservations\ReservationCancellation;
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

        $slots = $this->weekSlots($weekStart, $instructorId, $lessonType);
        $calendar = WeekCalendar::build($weekStart, $slots);

        // 前の週へ戻るのは「今日を含む週」まで（過去の枠は会員に出さない）
        $previousWeek = $weekStart->copy()->subWeek();
        $isFirstWeek = $previousWeek->lt($today->copy()->startOfWeek(CarbonInterface::SUNDAY));

        return view('members.lessons.index', [
            'calendar' => $calendar,
            'daySlots' => $calendar->slotsOn($selectedDate),
            // 予約済み・キャンセル待ちの枠は、一覧でもそれと分かるようにする
            'reservedSlotIds' => $this->reservedSlotIds($request->user(), $slots),
            'waitingSlotIds' => $this->waitingSlotIds($request->user(), $slots),
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
    public function show(Request $request, int $id): View
    {
        $slot = LessonSlot::query()
            ->with('instructor')
            ->withCount('activeReservations as reserved_count')
            ->findOrFail($id);

        $availability = SlotAvailability::of($slot);
        $user = $request->user();

        $reservation = $this->reservationOf($user, $slot);
        $waitlist = $this->waitlistOf($user, $slot);
        $waitingRank = $waitlist === null ? null : $this->waitingRank($slot, $waitlist);

        return view('members.lessons.show', [
            'slot' => $slot,
            'availability' => $availability,
            'reservation' => $reservation,
            'waitlist' => $waitlist,
            'waitingRank' => $waitingRank,
            'waitingCount' => $slot->waitingList()->count(),
            'cancelDeadlineHours' => ReservationCancellation::deadlineHours(),
            'booking' => $this->memberState($slot, $availability, $reservation, $waitlist, $waitingRank),
        ]);
    }

    /**
     * この週の枠のうち、その会員が予約済みのもの。
     *
     * 枠ごとに問い合わせず、1 クエリでまとめて引く。
     *
     * @param  Collection<int, LessonSlot>  $slots
     * @return list<int>
     */
    private function reservedSlotIds(?User $user, Collection $slots): array
    {
        if ($user === null || $slots->isEmpty()) {
            return [];
        }

        return Reservation::query()
            ->occupying()
            ->where('user_id', $user->id)
            ->whereIn('lesson_slot_id', $slots->modelKeys())
            ->pluck('lesson_slot_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * この週の枠のうち、その会員がキャンセル待ちに並んでいるもの。
     *
     * @param  Collection<int, LessonSlot>  $slots
     * @return list<int>
     */
    private function waitingSlotIds(?User $user, Collection $slots): array
    {
        if ($user === null || $slots->isEmpty()) {
            return [];
        }

        return Waitlist::query()
            ->waiting()
            ->where('user_id', $user->id)
            ->whereIn('lesson_slot_id', $slots->modelKeys())
            ->pluck('lesson_slot_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * その会員のこの枠の予約（席を占めているもの）。
     */
    private function reservationOf(?User $user, LessonSlot $slot): ?Reservation
    {
        if ($user === null) {
            return null;
        }

        return $slot->activeReservations()->where('user_id', $user->id)->first();
    }

    /**
     * その会員のこの枠のキャンセル待ち（待機中のもの）。
     */
    private function waitlistOf(?User $user, LessonSlot $slot): ?Waitlist
    {
        if ($user === null) {
            return null;
        }

        return $slot->waitlists()->waiting()->where('user_id', $user->id)->first();
    }

    /**
     * いま何番目に待っているか。
     *
     * position は採番した番号なので、前の人が繰り上がると実際の順番とずれる。
     * 会員には「いま何番目か」を出す。
     */
    private function waitingRank(LessonSlot $slot, Waitlist $waitlist): int
    {
        return $slot->waitlists()
            ->waiting()
            ->where('position', '<', $waitlist->position)
            ->count() + 1;
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
     * 詳細画面で、その会員に出す操作。
     *
     * これは表示のための下ごしらえで、受け付けられるかどうかの結論ではない。
     * 実際に受け付けるかは、送信を受けたときに枠をロックして数え直す
     * （ReservationBooking / ReservationCancellation / WaitlistRegistration）。
     * 画面を開いたまま満席になることも、期限を過ぎることもあるため。
     *
     * action は画面に出すボタンの種類。
     *   reserve  … 予約する
     *   waitlist … キャンセル待ちに並ぶ
     *   cancel   … 予約をキャンセルする
     *   withdraw … キャンセル待ちを取り消す
     *   null     … 押せる操作がない（理由を note に出す）
     *
     * @return array{action: string|null, label: string, note: string}
     */
    private function memberState(
        LessonSlot $slot,
        SlotAvailability $availability,
        ?Reservation $reservation,
        ?Waitlist $waitlist,
        ?int $waitingRank,
    ): array {
        $deadlineHours = ReservationCancellation::deadlineHours();

        if ($slot->isCanceled()) {
            return ['action' => null, 'label' => '中止になりました', 'note' => 'このレッスンは開催されません。'];
        }

        if ($reservation !== null) {
            if ($slot->starts_at->isPast()) {
                return ['action' => null, 'label' => '予約済み', 'note' => 'このレッスンは終了しました。'];
            }

            if (! ReservationCancellation::isWithinDeadline($slot)) {
                return [
                    'action' => null,
                    'label' => '予約済み',
                    'note' => 'キャンセル期限（開始 '.$deadlineHours.' 時間前）を過ぎています。',
                ];
            }

            return [
                'action' => 'cancel',
                'label' => '予約をキャンセルする',
                'note' => 'キャンセルは開始 '.$deadlineHours.' 時間前まで受け付けます。',
            ];
        }

        if ($waitlist !== null) {
            if ($slot->starts_at->isPast()) {
                return ['action' => null, 'label' => 'キャンセル待ち', 'note' => 'このレッスンは終了しました。'];
            }

            return [
                'action' => 'withdraw',
                'label' => 'キャンセル待ちを取り消す',
                'note' => '現在 '.$waitingRank.' 番目です。空きが出たら順にご案内します。',
            ];
        }

        if ($slot->starts_at->isPast()) {
            return ['action' => null, 'label' => '終了しました', 'note' => 'このレッスンはすでに終了しています。'];
        }

        if ($slot->isClosed()) {
            return ['action' => null, 'label' => '受付終了', 'note' => 'このレッスンは受付を終了しました（開催はします）。'];
        }

        if ($availability->isFull()) {
            return [
                'action' => 'waitlist',
                'label' => 'キャンセル待ちに登録',
                'note' => '満席です。空きが出たら、キャンセル待ちの先頭から繰り上げます。',
            ];
        }

        return ['action' => 'reserve', 'label' => '予約する', 'note' => ''];
    }
}
