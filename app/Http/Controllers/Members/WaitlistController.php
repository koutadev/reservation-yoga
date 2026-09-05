<?php

namespace App\Http\Controllers\Members;

use App\Models\LessonSlot;
use App\Models\Waitlist;
use App\Support\Reservations\BookingDenied;
use App\Support\Reservations\WaitlistRegistration;
use App\Support\Ui\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * キャンセル待ちの登録・取り消し（会員）。
 *
 * 満席の枠に並んでおくと、誰かがキャンセルしたときに先頭から繰り上がる。
 * 受け付けるかどうかと待ち順の採番は WaitlistRegistration が枠をロックして決める。
 */
class WaitlistController extends MemberController
{
    /**
     * キャンセル待ちに並ぶ。
     */
    public function store(Request $request, int $id): RedirectResponse
    {
        $slot = LessonSlot::query()->findOrFail($id);
        $user = $this->member($request);

        try {
            WaitlistRegistration::join($slot, $user);
        } catch (BookingDenied $denied) {
            return redirect()
                ->route('lessons.show', $slot->id)
                ->with(Toast::SESSION_KEY, Toast::error($denied->getMessage()));
        }

        return redirect()
            ->route('lessons.show', $slot->id)
            ->with(Toast::SESSION_KEY, Toast::success('キャンセル待ちに登録しました。空きが出たら順にご案内します。'));
    }

    /**
     * キャンセル待ちを取り消す。
     */
    public function destroy(Request $request, int $id): RedirectResponse
    {
        $user = $this->member($request);

        $waitlist = Waitlist::query()->findOrFail($id);

        abort_unless($waitlist->user_id === $user->id, 403);

        WaitlistRegistration::withdraw($waitlist);

        return $this->backToOrigin($request, $waitlist->lesson_slot_id)
            ->with(Toast::SESSION_KEY, Toast::success('キャンセル待ちを取り消しました。'));
    }
}
