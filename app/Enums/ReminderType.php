<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * リマインドの種別。
 *
 * 送る理由と時期が違うので、同じ reminders の中で見分けられるようにしている。
 * 実際の送信は拡張点で、ここでは「いつ・誰に・何を送る予定か」を持つだけ。
 */
enum ReminderType: string
{
    use HasOptions;

    /** レッスン前日のリマインド（日次コマンドでまとめて予定を作る） */
    case LessonReminder = 'lesson';

    /** キャンセル待ちからの繰り上げの連絡（繰り上げたその場で予定を作る） */
    case PromotionNotice = 'promotion';

    public function label(): string
    {
        return match ($this) {
            self::LessonReminder => 'レッスン前日のリマインド',
            self::PromotionNotice => '繰り上げの連絡',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::LessonReminder => 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200',
            self::PromotionNotice => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
        };
    }
}
