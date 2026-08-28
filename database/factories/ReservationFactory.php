<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_slot_id' => LessonSlot::factory(),
            'user_id' => User::factory(),
            'status' => ReservationStatus::Reserved,
            'reserved_at' => now(),
            'canceled_at' => null,
            'is_active' => true,
        ];
    }

    public function canceled(): self
    {
        return $this->state(fn (): array => [
            'status' => ReservationStatus::Canceled,
            'canceled_at' => now(),
        ]);
    }

    public function promoted(): self
    {
        return $this->state(fn (): array => ['status' => ReservationStatus::Promoted]);
    }
}
