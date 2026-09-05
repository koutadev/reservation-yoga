<?php

namespace App\Http\Controllers\Members;

use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Enums\WaitlistStatus;
use App\Models\LessonSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Waitlist;
use App\Support\Reservations\ReservationCancellation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * マイ予約（会員）。
 *
 * 「予約中 / キャンセル待ち / 履歴」の 3 つに分けて出す。
 * 繰上確定（キャンセル待ちから席が回ってきたもの）は、予約中の中でも
 * それと分かるように見せる（reservations.status を活かす）。
 *
 * 予約の件数が増えてもクエリ本数が変わらないよう、それぞれ 1 クエリで取り、
 * 履歴はページングする（待ち順も 1 クエリの中で数える）。
 */
class MyReservationController extends MemberController
{
    /** 履歴の 1 ページあたりの件数 */
    private const HISTORY_PER_PAGE = 10;

    public function index(Request $request): View
    {
        $user = $this->member($request);
        $now = now();

        return view('members.reservations.index', [
            'upcoming' => $this->upcoming($user, $now),
            'waiting' => $this->waiting($user, $now),
            'history' => $this->history($user, $now),
            'cancelDeadlineHours' => ReservationCancellation::deadlineHours(),
        ]);
    }

    /**
     * 予約中（これから受けるレッスン）。繰上確定も席を占めるのでここに入る。
     *
     * @return Collection<int, Reservation>
     */
    private function upcoming(User $user, Carbon $now): Collection
    {
        return Reservation::query()
            ->occupying()
            ->where('user_id', $user->id)
            ->whereHas('lessonSlot', fn ($query) => $query
                ->where('starts_at', '>=', $now)
                ->where('status', '!=', LessonSlotStatus::Canceled->value))
            ->with('lessonSlot.instructor:id,name')
            ->orderBy($this->slotStartsAt('reservations'))
            ->get();
    }

    /**
     * キャンセル待ち（これからのレッスンで待機中のもの）。
     *
     * 「いま何番目か」は、待ち順が自分より前の待機者を数えたもの。
     * 1 件ずつ数えると件数ぶんクエリが増えるので、同じ 1 クエリの中で数える。
     *
     * @return Collection<int, Waitlist>
     */
    private function waiting(User $user, Carbon $now): Collection
    {
        $rank = DB::table('waitlists as ahead')
            ->selectRaw('count(*) + 1')
            ->whereColumn('ahead.lesson_slot_id', 'waitlists.lesson_slot_id')
            ->whereColumn('ahead.position', '<', 'waitlists.position')
            ->where('ahead.status', WaitlistStatus::Waiting->value)
            ->whereNull('ahead.deleted_at');

        return Waitlist::query()
            ->select('waitlists.*')
            ->selectSub($rank, 'waiting_rank')
            ->where('status', WaitlistStatus::Waiting->value)
            ->where('user_id', $user->id)
            ->whereHas('lessonSlot', fn ($query) => $query
                ->where('starts_at', '>=', $now)
                ->where('status', '!=', LessonSlotStatus::Canceled->value))
            ->with('lessonSlot.instructor:id,name')
            ->orderBy($this->slotStartsAt('waitlists'))
            ->get();
    }

    /**
     * 履歴（終わったレッスン・キャンセルした予約・中止になったレッスン）。
     *
     * @return LengthAwarePaginator<int, Reservation>
     */
    private function history(User $user, Carbon $now): LengthAwarePaginator
    {
        return Reservation::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($now): void {
                $query
                    ->where('status', ReservationStatus::Canceled->value)
                    ->orWhereHas('lessonSlot', fn ($slot) => $slot
                        ->where('starts_at', '<', $now)
                        ->orWhere('status', LessonSlotStatus::Canceled->value));
            })
            ->with('lessonSlot.instructor:id,name')
            ->orderByDesc($this->slotStartsAt('reservations'))
            ->paginate(self::HISTORY_PER_PAGE)
            ->withQueryString();
    }

    /**
     * 並べ替えに使う「その行が指すレッスンの開始日時」。
     *
     * @param  string  $table  並べ替える側のテーブル（reservations / waitlists）
     * @return Builder<LessonSlot>
     */
    private function slotStartsAt(string $table)
    {
        return LessonSlot::query()
            ->select('starts_at')
            ->whereColumn('lesson_slots.id', $table.'.lesson_slot_id');
    }
}
