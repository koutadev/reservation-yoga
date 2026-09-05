<?php

namespace App\Models;

use App\Enums\ReminderChannel;
use App\Enums\ReminderType;
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
 * @property ReminderType $type
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
        'type',
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
            'type' => ReminderType::class,
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
     * これから送るもの（まだ送っておらず、無効にもなっていない）。
     *
     * 予約がキャンセルされた予定は is_active = false にして送らない。
     *
     * @param  Builder<Reminder>  $query
     * @return Builder<Reminder>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('sent_at')->where('is_active', true);
    }

    /**
     * まだ送っていない、送信予定が来たもの。
     *
     * 実際に送る処理（メール送信）は拡張点。このスコープで拾って送り、
     * 送れたら sent_at を入れる、という流れを想定している。
     *
     * @param  Builder<Reminder>  $query
     * @return Builder<Reminder>
     */
    public function scopeDue(Builder $query, ?Carbon $now = null): Builder
    {
        return $query->pending()->where('scheduled_at', '<=', $now ?? now());
    }

    /**
     * 種別で絞る。
     *
     * @param  Builder<Reminder>  $query
     * @return Builder<Reminder>
     */
    public function scopeOfType(Builder $query, ReminderType $type): Builder
    {
        return $query->where('type', $type->value);
    }
}
