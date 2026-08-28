<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * キャンセル待ちの状態。
 *
 * 待機中のものだけが繰り上げの対象になり、待ち順（position）を持つ。
 */
enum WaitlistStatus: string
{
    use HasOptions;

    /** 待機中 */
    case Waiting = 'waiting';

    /** 繰上済（予約に変わった） */
    case Promoted = 'promoted';

    /** 取消（会員が取り下げた／枠が中止になった） */
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => '待機中',
            self::Promoted => '繰上済',
            self::Canceled => '取消',
        };
    }

    /**
     * 繰り上げの対象になる状態か。
     */
    public function isWaiting(): bool
    {
        return $this === self::Waiting;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Waiting => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
            self::Promoted => 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200',
            self::Canceled => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        };
    }
}
