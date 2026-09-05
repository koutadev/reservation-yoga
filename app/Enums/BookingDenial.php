<?php

namespace App\Enums;

/**
 * 予約・キャンセル待ち登録を受け付けられなかった理由。
 *
 * 画面表示のためではなく「サーバが確定時に下した判断」を表す。
 * 一覧・詳細の表示は古くなりうるので、予約確定はこの判定だけを信用する
 * （設計書 6. 予約競合の設計）。
 */
enum BookingDenial: string
{
    /** 枠が中止になっている */
    case SlotCanceled = 'slot_canceled';

    /** 受付を締め切っている（開催はする） */
    case ReceptionClosed = 'reception_closed';

    /** すでに開始した枠 */
    case AlreadyStarted = 'already_started';

    /** 定員に達している */
    case Full = 'full';

    /** 同じ枠をすでに予約している */
    case AlreadyReserved = 'already_reserved';

    /** 同じ会員が、時間帯の重なる別の枠を予約している */
    case TimeConflict = 'time_conflict';

    /** すでにキャンセル待ちに並んでいる */
    case AlreadyWaiting = 'already_waiting';

    /** まだ空きがあるので、キャンセル待ちではなく予約すればよい */
    case SeatsAvailable = 'seats_available';

    /**
     * 会員に見せる説明。
     */
    public function message(): string
    {
        return match ($this) {
            self::SlotCanceled => 'このレッスンは中止になったため、予約できません。',
            self::ReceptionClosed => 'このレッスンは受付を終了したため、予約できません。',
            self::AlreadyStarted => 'このレッスンはすでに開始しているため、予約できません。',
            self::Full => '満席のため予約できませんでした。ほかのレッスンをお探しください。',
            self::AlreadyReserved => 'このレッスンはすでに予約済みです。',
            self::TimeConflict => '同じ時間帯に予約しているレッスンがあるため、予約できません。',
            self::AlreadyWaiting => 'このレッスンはすでにキャンセル待ちに登録済みです。',
            self::SeatsAvailable => 'まだ空きがあります。そのまま予約してください。',
        };
    }
}
