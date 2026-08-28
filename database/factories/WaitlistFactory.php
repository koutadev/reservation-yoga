<?php

namespace Database\Factories;

use App\Enums\WaitlistStatus;
use App\Models\LessonSlot;
use App\Models\User;
use App\Models\Waitlist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Waitlist>
 */
class WaitlistFactory extends Factory
{
    protected $model = Waitlist::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_slot_id' => LessonSlot::factory(),
            'user_id' => User::factory(),
            'position' => 1,
            'status' => WaitlistStatus::Waiting,
            'requested_at' => now(),
            'is_active' => true,
        ];
    }

    public function promoted(): self
    {
        return $this->state(fn (): array => ['status' => WaitlistStatus::Promoted]);
    }

    public function canceled(): self
    {
        return $this->state(fn (): array => ['status' => WaitlistStatus::Canceled]);
    }
}
