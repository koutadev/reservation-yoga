<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * 予約の状態。
 *
 * 「予約中」と「繰上確定」はどちらも席を 1 つ占める（残枠の計算に入る）。
 * キャンセル済みは席を占めない。
 */
enum ReservationStatus: string
{
    use HasOptions;

    /** 予約中 */
    case Reserved = 'reserved';

    /** キャンセル済み */
    case Canceled = 'canceled';

    /** 繰上確定（キャンセル待ちからの繰り上げ） */
    case Promoted = 'promoted';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => '予約中',
            self::Canceled => 'キャンセル',
            self::Promoted => '繰上確定',
        };
    }

    /**
     * 席を占めている状態か（残枠 = 定員 − この状態の件数）。
     */
    public function occupiesSeat(): bool
    {
        return $this !== self::Canceled;
    }

    /**
     * 席を占める状態の一覧。
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->occupiesSeat()),
        ));
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Reserved => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200',
            self::Canceled => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
            self::Promoted => 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200',
        };
    }
}
