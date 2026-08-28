<?php

namespace App\Models;

use App\Enums\ReminderChannel;
use Database\Factories\ReminderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * リマインド（送信予定・送信済み）。
 *
 * 実際の送信は拡張点。ここでは予定と実績だけを持つ。
 *
 * @property int $id
 * @property int $lesson_slot_id
 * @property int $reservation_id
 * @property Carbon $scheduled_at
 * @property Carbon|null $sent_at
 * @property ReminderChannel $channel
 * @property-read LessonSlot|null $lessonSlot
 * @property-read Reservation|null $reservation
 */
class Reminder extends BaseModel
{
    /** @use HasFactory<ReminderFactory> */
    use HasFactory;

    protected $fillable = [
        'lesson_slot_id',
        'reservation_id',
        'scheduled_at',
        'sent_at',
        'channel',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ReminderChannel::class,
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
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
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * まだ送っていない、送信予定が来たもの。
     *
     * @param  Builder<Reminder>  $query
     * @return Builder<Reminder>
     */
    public function scopeDue(Builder $query, ?Carbon $now = null): Builder
    {
        return $query->whereNull('sent_at')->where('scheduled_at', '<=', $now ?? now());
    }
}
