<?php

namespace App\Support\Navigation;

use App\Enums\PermissionName;
use App\Models\User;

/**
 * ログイン後に開く画面。
 *
 * 管理・講師はダッシュボード、会員はレッスン一覧。会員は管理画面の
 * ダッシュボードを開けない（dashboard.view を持たない）ため、
 * ログイン直後に 403 を見せないようここで振り分ける。
 */
class HomeRoute
{
    public static function for(?User $user): string
    {
        if ($user !== null
            && ! $user->can(PermissionName::DashboardView->value)
            && $user->can(PermissionName::ReservationBook->value)) {
            return route('lessons.index', absolute: false);
        }

        return route('dashboard', absolute: false);
    }
}
