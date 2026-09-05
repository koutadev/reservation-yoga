<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\LessonSlot;
use App\Models\User;

/**
 * レッスン枠を操作してよいかの判定（設計書 8. 権限設計）。
 *
 *   - admin  : すべての枠を操作できる
 *   - staff  : 自分（＝自分に紐付いた講師）の枠だけ編集できる
 *   - その他 : lesson_slot.manage を持たないので管理画面に入れない
 *
 * 「自分の枠か」は instructors.user_id で判断する。
 */
class LessonSlotPolicy
{
    /**
     * 管理画面（一覧・詳細）を開けるか。
     */
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::LessonSlotManage->value);
    }

    public function view(User $user, LessonSlot $lessonSlot): bool
    {
        return $this->viewAny($user);
    }

    /**
     * 枠を開講できるか。
     *
     * 運営としてほかの講師の枠を立てることもあるため、開講は権限だけで判断する
     * （立てた枠をあとから直せるのは、自分の枠であるか admin のときだけ）。
     */
    public function create(User $user): bool
    {
        return $user->can(PermissionName::LessonSlotManage->value);
    }

    /**
     * 枠を編集（定員変更・中止を含む）できるか。
     */
    public function update(User $user, LessonSlot $lessonSlot): bool
    {
        if (! $user->can(PermissionName::LessonSlotManage->value)) {
            return false;
        }

        return $user->isAdmin() || $lessonSlot->isOwnedBy($user);
    }
}
