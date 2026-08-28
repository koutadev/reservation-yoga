<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * リマインドの送信手段。
 *
 * MVP はメールのみ。実送信インフラ（LINE・プッシュ通知など）は拡張点として、
 * 送信予定・送信済みの管理だけを先に持たせてある。
 */
enum ReminderChannel: string
{
    use HasOptions;

    /** メール */
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'メール',
        };
    }
}
