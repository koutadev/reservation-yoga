<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\RoleName;
use App\Models\Concerns\LogsActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    // ロール / 権限 (spatie/laravel-permission)
    use HasRoles;

    // ユーザー自身の作成・更新・削除も操作ログに残す
    use LogsActivity;
    use Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * このユーザーに紐付いた社員(任意)。
     *
     * @return HasOne<Employee, $this>
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * このユーザーが担当するインストラクター(任意)。
     *
     * staff ロールの利用者が「自分の枠」だけを編集できるようにするための紐付け。
     *
     * @return HasOne<Instructor, $this>
     */
    public function instructor(): HasOne
    {
        return $this->hasOne(Instructor::class);
    }

    /**
     * 管理者かどうか。
     *
     * 削除済みマスタの表示 / 復元など、権限(master.view / master.manage)では
     * 表現しない管理者限定の操作の判定に使う。
     */
    public function isAdmin(): bool
    {
        return $this->hasRole(RoleName::Admin->value);
    }

    /**
     * 会員としての予約。
     *
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * 会員としてのキャンセル待ち。
     *
     * @return HasMany<Waitlist, $this>
     */
    public function waitlists(): HasMany
    {
        return $this->hasMany(Waitlist::class);
    }
}
