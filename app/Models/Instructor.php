<?php

namespace App\Models;

use App\Models\Concerns\HasSequentialCode;
use Database\Factories\InstructorFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * インストラクター（マスタ）。
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $profile
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
        'is_active',
    ];

    public static function codePrefix(): string
    {
        return 'INS';
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
