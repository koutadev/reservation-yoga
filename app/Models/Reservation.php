<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Models\Concerns\HasYearlySequentialCode;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 予約。
 *
 * 「予約中」「繰上確定」は席を占め、「キャンセル」は席を空ける。
 * 二重予約は DB の部分ユニークインデックスでも防いでいる（マイグレーション参照）。
 *
 * @property int $id
 * @property string $code
 * @property int $lesson_slot_id
 * @property int $user_id
 * @property ReservationStatus $status
 * @property Carbon $reserved_at
 * @property Carbon|null $canceled_at
 * @property-read LessonSlot|null $lessonSlot
 * @property-read User|null $user
 * @property-read Collection<int, Reminder> $reminders
 */
class Reservation extends BaseModel
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    use HasYearlySequentialCode;

    protected $fillable = [
        'lesson_slot_id',
        'user_id',
        'status',
        'reserved_at',
        'canceled_at',
        'is_active',
    ];

    public static function codePrefix(): string
    {
        return 'RSV';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'reserved_at' => 'datetime',
            'canceled_at' => 'datetime',
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
     * @return HasMany<Reminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /**
     * 席を占めている予約だけ（予約中・繰上確定）。
     *
     * 共通基盤の active() は「有効フラグ」の意味なので、名前を分けている。
     *
     * @param  Builder<Reservation>  $query
     * @return Builder<Reservation>
     */
    public function scopeOccupying(Builder $query): Builder
    {
        return $query->whereIn('status', ReservationStatus::activeValues());
    }

    public function isCanceled(): bool
    {
        return $this->status === ReservationStatus::Canceled;
    }
}
