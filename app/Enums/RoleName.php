<?php

namespace App\Enums;

/**
 * アプリケーションで使うロールの一覧。
 *
 * 各ロールが持つ権限は {@see self::permissions()} が唯一の定義元。
 * 変更したら RolePermissionSeeder を再実行すると DB に反映される。
 */
enum RoleName: string
{
    /** 管理者: すべての操作が可能 */
    case Admin = 'admin';

    /** 担当者: 業務データの登録・編集が可能 */
    case Staff = 'staff';

    /** 閲覧者: 参照のみ */
    case Viewer = 'viewer';

    /** 会員: 空き枠を見て予約・キャンセル・キャンセル待ちができる */
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => '管理者',
            self::Staff => '担当者',
            self::Viewer => '閲覧者',
            self::Member => '会員',
        };
    }

    /**
     * このロールに割り当てる権限。
     *
     * @return list<PermissionName>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => PermissionName::cases(),

            // 講師・運営: 枠の開講と予約状況の把握
            self::Staff => [
                PermissionName::DashboardView,
                PermissionName::MasterView,
                PermissionName::MasterManage,
                PermissionName::LessonSlotManage,
                PermissionName::ReservationManage,
            ],

            self::Viewer => [
                PermissionName::DashboardView,
                PermissionName::MasterView,
            ],

            // 会員: 管理画面には入れず、予約まわりだけができる
            self::Member => [
                PermissionName::ReservationBook,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function permissionValues(): array
    {
        return array_map(
            static fn (PermissionName $permission): string => $permission->value,
            $this->permissions(),
        );
    }

    /**
     * 新規登録したユーザーに割り当てる既定のロール。
     */
    public static function default(): self
    {
        return self::Viewer;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
