<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * レッスンの形態。
 *
 * マンツーマンは定員 1 名で、グループは枠ごとに定員を持つ。
 */
enum LessonType: string
{
    use HasOptions;

    /** グループレッスン */
    case Group = 'group';

    /** マンツーマンレッスン（定員 1 名） */
    case Personal = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::Group => 'グループ',
            self::Personal => 'マンツーマン',
        };
    }

    /**
     * この形態で許される定員（マンツーマンは 1 名固定）。
     */
    public function fixedCapacity(): ?int
    {
        return $this === self::Personal ? 1 : null;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Group => 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200',
            self::Personal => 'bg-violet-100 text-violet-800 dark:bg-violet-900 dark:text-violet-200',
        };
    }
}
