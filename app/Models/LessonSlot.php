<?php

namespace App\Models;

use App\Enums\LessonSlotStatus;
use App\Enums\LessonType;
use App\Enums\ReservationStatus;
use App\Models\Concerns\HasYearlySequentialCode;
use Database\Factories\LessonSlotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * レッスン枠。
 *
 * 残枠は「定員 − 席を占める予約数」で都度求める（枠側に予約数を持たない）。
 * 予約の確定・キャンセル待ちの繰り上げは STEP4 / STEP5 で、
 * この行をロックしたうえで件数を数えて判定する。
 *
 * @property int $id
 * @property string $code
 * @property int $instructor_id
 * @property string $title
 * @property LessonType $lesson_type
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $capacity
 * @property string|null $online_url
 * @property LessonSlotStatus $status
 * @property-read Instructor|null $instructor
 * @property-read Collection<int, Reservation> $reservations
 * @property-read Collection<int, Waitlist> $waitlists
 * @property-read Collection<int, Reminder> $reminders
 */
class LessonSlot extends BaseModel
{
    /** @use HasFactory<LessonSlotFactory> */
    use HasFactory;

    use HasYearlySequentialCode;

    protected $fillable = [
        'instructor_id',
        'title',
        'lesson_type',
        'starts_at',
        'ends_at',
        'capacity',
        'online_url',
        'status',
        'is_active',
    ];

    public static function codePrefix(): string
    {
        return 'LSN';
    }

    /**
     * 採番は「開催日の年」で区切る（2026 年のレッスンは LSN-2026-xxxx）。
     */
    public function codeYear(): int
    {
        $startsAt = $this->getAttribute('starts_at');

        if ($startsAt === null) {
            return (int) now()->year;
        }

        return (int) Carbon::parse($startsAt)->year;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lesson_type' => LessonType::class,
            'status' => LessonSlotStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Instructor, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * 席を占めている予約（予約中・繰上確定）。残枠の計算に使う。
     *
     * @return HasMany<Reservation, $this>
     */
    public function activeReservations(): HasMany
    {
        return $this->reservations()->whereIn('status', ReservationStatus::activeValues());
    }

    /**
     * @return HasMany<Waitlist, $this>
     */
    public function waitlists(): HasMany
    {
        return $this->hasMany(Waitlist::class);
    }

    /**
     * @return HasMany<Reminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /**
     * これから始まる枠。
     *
     * @param  Builder<LessonSlot>  $query
     * @return Builder<LessonSlot>
     */
    public function scopeUpcoming(Builder $query, ?Carbon $from = null): Builder
    {
        return $query->where('starts_at', '>=', $from ?? now());
    }

    /**
     * 予約を受け付けている枠。
     *
     * @param  Builder<LessonSlot>  $query
     * @return Builder<LessonSlot>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', LessonSlotStatus::Open->value);
    }

    /**
     * 残枠（定員 − 席を占める予約数）。
     *
     * 画面表示はこの値でよいが、予約の確定時はサーバ側で数え直す（STEP4）。
     */
    public function remainingSeats(): int
    {
        $reserved = $this->relationLoaded('activeReservations')
            ? $this->activeReservations->count()
            : $this->activeReservations()->count();

        return max(0, $this->capacity - $reserved);
    }

    public function isFull(): bool
    {
        return $this->remainingSeats() === 0;
    }
}
