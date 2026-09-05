<?php

namespace App\Http\Controllers\Members;

use App\Models\LessonSlot;
use App\Models\Reservation;
use App\Support\Reservations\BookingDenied;
use App\Support\Reservations\CancellationDenied;
use App\Support\Reservations\ReservationBooking;
use App\Support\Reservations\ReservationCancellation;
use App\Support\Ui\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 予約の確定とキャンセル（会員）。
 *
 * 受け付けられるかどうかの判断は ReservationBooking / ReservationCancellation が
 * 持つ（枠の行ロック + 確定時の再判定）。ここはその結果を画面に返すだけにしている。
 *
 * 予約の一覧（マイ予約）は STEP6。いまは各レッスンの詳細画面から操作する。
 */
class ReservationController extends MemberController
{
    /**
     * 予約する。
     */
    public function store(Request $request, int $id): RedirectResponse
    {
        $slot = LessonSlot::query()->findOrFail($id);
        $user = $this->member($request);

        try {
            $reservation = ReservationBooking::book($slot, $user);
        } catch (BookingDenied $denied) {
            // 断った理由をそのまま伝えて、詳細画面に戻す
            return redirect()
                ->route('lessons.show', $slot->id)
                ->with(Toast::SESSION_KEY, Toast::error($denied->getMessage()));
        }

        return redirect()->route('reservations.complete', $reservation->id);
    }

    /**
     * 予約完了（簡易）。マイ予約は STEP6 で作る。
     */
    public function complete(Request $request, int $id): View
    {
        $user = $this->member($request);

        $reservation = Reservation::query()
            ->with('lessonSlot.instructor')
            ->findOrFail($id);

        // 自分の予約以外は見せない
        abort_unless($reservation->user_id === $user->id, 403);

        return view('members.reservations.complete', [
            'reservation' => $reservation,
            'slot' => $reservation->lessonSlot,
            'cancelDeadlineHours' => (int) config('reservation.cancel_deadline_hours', 2),
        ]);
    }

    /**
     * 予約をキャンセルする。
     *
     * 空いた席は、同じトランザクションの中でキャンセル待ちの先頭へ回る。
     */
    public function destroy(Request $request, int $id): RedirectResponse
    {
        $user = $this->member($request);

        $reservation = Reservation::query()->findOrFail($id);

        // 自分の予約以外はキャンセルできない
        abort_unless($reservation->user_id === $user->id, 403);

        try {
            $result = ReservationCancellation::cancel($reservation);
        } catch (CancellationDenied $denied) {
            return redirect()
                ->route('lessons.show', $reservation->lesson_slot_id)
                ->with(Toast::SESSION_KEY, Toast::error($denied->getMessage()));
        }

        return redirect()
            ->route('lessons.show', $reservation->lesson_slot_id)
            ->with(Toast::SESSION_KEY, Toast::success($result->message()));
    }
}
