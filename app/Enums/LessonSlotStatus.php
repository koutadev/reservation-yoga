<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * レッスン枠の状態。
 *
 * 「開講」だけが予約を受け付ける。締切・中止は新規の予約を受け付けない。
 */
enum LessonSlotStatus: string
{
    use HasOptions;

    /** 開講（予約受付中） */
    case Open = 'open';

    /** 締切（受付終了。開催はする） */
    case Closed = 'closed';

    /** 中止（開催しない） */
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Open => '開講',
            self::Closed => '締切',
            self::Canceled => '中止',
        };
    }

    /**
     * 会員の空き枠一覧に出す状態（DEC-016）。
     *
     * 締切は開催するので一覧に出し「受付終了」として見せる。
     * 中止は探す対象ではないため一覧から外す（予約済み会員のマイ予約には残す）。
     *
     * @return list<string>
     */
    public static function browsableValues(): array
    {
        return [self::Open->value, self::Closed->value];
    }

    /**
     * 新しい予約を受け付ける状態か。
     */
    public function acceptsReservation(): bool
    {
        return $this === self::Open;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Open => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200',
            self::Closed => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
            self::Canceled => 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-200',
        };
    }
}
