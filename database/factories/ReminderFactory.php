<?php

namespace Database\Factories;

use App\Enums\ReminderChannel;
use App\Models\LessonSlot;
use App\Models\Reminder;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    protected $model = Reminder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_slot_id' => LessonSlot::factory(),
            'reservation_id' => Reservation::factory(),
            'scheduled_at' => now()->addDay(),
            'sent_at' => null,
            'channel' => ReminderChannel::Email,
            'is_active' => true,
        ];
    }

    public function sent(): self
    {
        return $this->state(fn (): array => ['sent_at' => now()]);
    }
}
