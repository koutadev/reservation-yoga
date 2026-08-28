<?php

namespace Database\Factories;

use App\Enums\LessonSlotStatus;
use App\Enums\LessonType;
use App\Models\Instructor;
use App\Models\LessonSlot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<LessonSlot>
 */
class LessonSlotFactory extends Factory
{
    protected $model = LessonSlot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = Carbon::now()->addDays(fake()->numberBetween(1, 14))->setTime(fake()->numberBetween(7, 20), 0);

        return [
            'instructor_id' => Instructor::factory(),
            'title' => 'ベーシックヨガ',
            'lesson_type' => LessonType::Group,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(60),
            'capacity' => 8,
            'online_url' => 'https://example.com/meet/'.fake()->bothify('???-####'),
            'status' => LessonSlotStatus::Open,
            'is_active' => true,
        ];
    }

    /**
     * マンツーマン（定員 1 名）。
     */
    public function personal(): self
    {
        return $this->state(fn (): array => [
            'lesson_type' => LessonType::Personal,
            'capacity' => 1,
            'title' => 'パーソナルヨガ（60分）',
        ]);
    }

    public function closed(): self
    {
        return $this->state(fn (): array => ['status' => LessonSlotStatus::Closed]);
    }

    public function canceled(): self
    {
        return $this->state(fn (): array => ['status' => LessonSlotStatus::Canceled]);
    }

    /**
     * 過去の枠（履歴の確認用）。
     */
    public function past(): self
    {
        return $this->state(function (): array {
            $startsAt = Carbon::now()->subDays(fake()->numberBetween(1, 30))->setTime(fake()->numberBetween(7, 20), 0);

            return [
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes(60),
                'status' => LessonSlotStatus::Closed,
            ];
        });
    }
}
