<?php

namespace App\Models;

use App\Enums\WaitlistStatus;
use Database\Factories\WaitlistFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * キャンセル待ち。
 *
 * 待機中のものだけが position（1 が先頭）を持ち、空きが出たら先頭を 1 件繰り上げる（STEP5）。
 *
 * @property int $id
 * @property int $lesson_slot_id
 * @property int $user_id
 * @property int $position
 * @property WaitlistStatus $status
 * @property Carbon $requested_at
 * @property-read LessonSlot|null $lessonSlot
 * @property-read User|null $user
 */
class Waitlist extends BaseModel
{
    /** @use HasFactory<WaitlistFactory> */
    use HasFactory;

    protected $fillable = [
        'lesson_slot_id',
        'user_id',
        'position',
        'status',
        'requested_at',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WaitlistStatus::class,
            'position' => 'integer',
            'requested_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LessonSlot, $this>
     */
    public function lessonSlot(): BelongsTo
    {
        return $this->belongsTo(LessonSlot::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 待機中のものを待ち順に。
     *
     * @param  Builder<Waitlist>  $query
     * @return Builder<Waitlist>
     */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', WaitlistStatus::Waiting->value)->orderBy('position');
    }
}
