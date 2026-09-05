<?php

namespace App\Support\Reservations;

use App\Enums\BookingDenial;
use RuntimeException;

/**
 * 予約が受け付けられなかったことを表す例外。
 *
 * 「どうして駄目だったか」を BookingDenial で持ち、画面はその文言を出すだけにする。
 */
class BookingDenied extends RuntimeException
{
    public function __construct(public readonly BookingDenial $reason)
    {
        parent::__construct($reason->message());
    }

    public static function because(BookingDenial $reason): self
    {
        return new self($reason);
    }
}
