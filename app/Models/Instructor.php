<?php

namespace App\Models;

use App\Models\Concerns\HasSequentialCode;
use Database\Factories\InstructorFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * インストラクター（マスタ）。
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $profile
 * @property int|null $user_id
 * @property-read User|null $user
 * @property-read Collection<int, LessonSlot> $lessonSlots
 */
class Instructor extends BaseModel
{
    /** @use HasFactory<InstructorFactory> */
    use HasFactory;

    use HasSequentialCode;

    protected $fillable = [
        'name',
        'profile',
        'user_id',
        'is_active',
    ];

    public static function codePrefix(): string
    {
        return 'INS';
    }

    /**
     * この講師としてログインするユーザー（任意）。
     *
     * staff ロールの利用者が「自分の枠」を判別するために使う。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 担当するレッスン枠。
     *
     * @return HasMany<LessonSlot, $this>
     */
    public function lessonSlots(): HasMany
    {
        return $this->hasMany(LessonSlot::class);
    }
}
