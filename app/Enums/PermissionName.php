<?php

namespace App\Enums;

/**
 * アプリケーションで使う権限の一覧。
 *
 * 権限を追加したら RolePermissionSeeder を再実行すること。
 *
 *   docker compose exec app php artisan db:seed --class=RolePermissionSeeder
 *
 * Blade からは can ディレクティブに文字列を渡して参照する。
 *
 *   PermissionName::MasterManage->value  //=> 'master.manage'
 */
enum PermissionName: string
{
    /** ダッシュボードを閲覧する */
    case DashboardView = 'dashboard.view';

    /** マスタを閲覧する */
    case MasterView = 'master.view';

    /** マスタを登録・編集・削除する */
    case MasterManage = 'master.manage';

    /** ユーザーとロールを管理する */
    case UserManage = 'user.manage';

    /** 操作ログを閲覧する */
    case ActivityLogView = 'activity_log.view';

    /** 会員として予約する(空き枠の閲覧・予約・キャンセル・キャンセル待ち) */
    case ReservationBook = 'reservation.book';

    /** 予約状況を管理側として把握する */
    case ReservationManage = 'reservation.manage';

    public function label(): string
    {
        return match ($this) {
            self::DashboardView => 'ダッシュボード閲覧',
            self::MasterView => 'マスタ閲覧',
            self::MasterManage => 'マスタ管理',
            self::UserManage => 'ユーザー管理',
            self::ActivityLogView => '操作ログ閲覧',
            self::ReservationBook => '予約する(会員)',
            self::ReservationManage => '予約状況の管理',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
