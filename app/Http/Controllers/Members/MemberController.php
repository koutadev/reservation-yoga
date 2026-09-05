<?php

namespace App\Http\Controllers\Members;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 会員向け画面の共通処理。
 *
 * 権限（reservation.book）はルート側で見ているので、ここでは
 * 「操作しているのが会員本人である」ことだけを扱う。
 */
abstract class MemberController extends Controller
{
    /**
     * ログイン中の会員。
     */
    protected function member(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * 操作したあとの戻り先。
     *
     * キャンセルはマイ予約とレッスン詳細の両方から行えるので、
     * どちらから来たかをフォームの from で受け取り、その画面に戻す。
     */
    protected function backToOrigin(Request $request, int $slotId): RedirectResponse
    {
        return $request->input('from') === 'my-reservations'
            ? redirect()->route('my-reservations.index')
            : redirect()->route('lessons.show', $slotId);
    }
}
