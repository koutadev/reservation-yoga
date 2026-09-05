<?php

namespace App\Support\Reservations;

use App\Models\Reservation;

/**
 * キャンセルの結果（空いた席が誰かに回ったかどうか）。
 */
final class CancellationResult
{
    public function __construct(
        public readonly Reservation $canceled,
        public readonly ?Reservation $promoted,
    ) {}

    /**
     * 空いた席がキャンセル待ちの人に回ったか。
     */
    public function hasPromotion(): bool
    {
        return $this->promoted !== null;
    }

    /**
     * 会員に見せる文言。
     */
    public function message(): string
    {
        return $this->hasPromotion()
            ? '予約をキャンセルしました。空いた席はキャンセル待ちの方へ繰り上げます。'
            : '予約をキャンセルしました。';
    }
}
