<?php

namespace App\Enums;

/**
 * キャンセルを受け付けられなかった理由。
 *
 * キャンセルは「席を空ける」操作で、空いた席はキャンセル待ちの繰り上げに回る。
 * 受け付けるかどうかは、確定時にサーバが枠を押さえて判断する（設計書 6.）。
 */
enum CancellationDenial: string
{
    /** すでにキャンセル済み */
    case AlreadyCanceled = 'already_canceled';

    /** 枠が中止になっている（予約は連鎖でキャンセル済み） */
    case SlotCanceled = 'slot_canceled';

    /** すでに開始した枠 */
    case AlreadyStarted = 'already_started';

    /** キャンセル期限（開始の N 時間前）を過ぎている */
    case DeadlinePassed = 'deadline_passed';

    public function message(): string
    {
        return match ($this) {
            self::AlreadyCanceled => 'この予約はすでにキャンセル済みです。',
            self::SlotCanceled => 'このレッスンは中止になったため、予約は取り消し済みです。',
            self::AlreadyStarted => 'このレッスンはすでに開始しているため、キャンセルできません。',
            self::DeadlinePassed => 'キャンセル期限を過ぎているため、キャンセルできません。',
        };
    }
}
