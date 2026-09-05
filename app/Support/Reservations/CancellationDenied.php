<?php

namespace App\Support\Reservations;

use App\Enums\CancellationDenial;
use RuntimeException;

/**
 * キャンセルが受け付けられなかったことを表す例外。
 *
 * 予約側の {@see BookingDenied} と同じ形にしてあり、画面は理由の文言を出すだけでよい。
 */
class CancellationDenied extends RuntimeException
{
    public function __construct(public readonly CancellationDenial $reason)
    {
        parent::__construct($reason->message());
    }

    public static function because(CancellationDenial $reason): self
    {
        return new self($reason);
    }
}
