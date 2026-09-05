<?php

namespace App\Http\Requests\Lessons;

use App\Enums\LessonSlotStatus;
use App\Enums\LessonType;
use App\Support\Lessons\RecurringSlots;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * 繰り返しでのレッスン枠の一括開講（STEP2-3）。
 *
 *   曜日（複数可）× 時間 × レッスン内容 × 講師 × 形式 × 定員 × URL × 期間
 *
 * 生成される枠は独立した 1 件ずつなので、開講後は単発の枠とまったく同じ扱いになる。
 */
class RecurringLessonSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'instructor_id' => ['required', 'integer', Rule::exists('instructors', 'id')->whereNull('deleted_at')],
            'title' => ['required', 'string', 'max:191'],
            'lesson_type' => ['required', Rule::enum(LessonType::class)],
            'capacity' => ['required', 'integer', 'min:1', 'max:100'],
            'online_url' => ['nullable', 'string', 'url', 'max:255'],
            'status' => ['required', Rule::enum(LessonSlotStatus::class)],

            // 繰り返しの条件
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(
            collect($this->all())
                ->map(static fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : $value)
                ->all()
        );

        if ($this->input('lesson_type') === LessonType::Personal->value) {
            $this->merge(['capacity' => LessonType::Personal->fixedCapacity()]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $count = count(RecurringSlots::dates($this->dateFrom(), $this->dateTo(), $this->weekdays()));

            if ($count === 0) {
                $validator->errors()->add('weekdays', '指定した期間に、選んだ曜日の日がありません。期間か曜日を見直してください。');

                return;
            }

            if ($count > RecurringSlots::MAX_SLOTS) {
                $validator->errors()->add('date_to', sprintf(
                    '一度に開講できるのは %d 件までです（この条件では %d 件になります）。期間を短くしてください。',
                    RecurringSlots::MAX_SLOTS,
                    $count,
                ));
            }
        });
    }

    /**
     * 選択された曜日（0=日 〜 6=土）。
     *
     * @return list<int>
     */
    public function weekdays(): array
    {
        /** @var list<mixed> $weekdays */
        $weekdays = $this->input('weekdays', []);

        return array_values(array_unique(array_map(static fn (mixed $day): int => (int) $day, $weekdays)));
    }

    public function dateFrom(): Carbon
    {
        return Carbon::parse((string) $this->input('date_from'))->startOfDay();
    }

    public function dateTo(): Carbon
    {
        return Carbon::parse((string) $this->input('date_to'))->startOfDay();
    }

    /**
     * 生成する枠に共通する項目。
     *
     * @return array<string, mixed>
     */
    public function slotAttributes(): array
    {
        return [
            'instructor_id' => (int) $this->input('instructor_id'),
            'title' => (string) $this->input('title'),
            'lesson_type' => LessonType::from((string) $this->input('lesson_type')),
            'capacity' => (int) $this->input('capacity'),
            'online_url' => $this->input('online_url'),
            'status' => LessonSlotStatus::from((string) $this->input('status')),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'instructor_id' => '講師',
            'title' => 'レッスン名',
            'lesson_type' => '形式',
            'capacity' => '定員',
            'online_url' => 'オンライン URL',
            'status' => '状態',
            'weekdays' => '曜日',
            'start_time' => '開始時刻',
            'end_time' => '終了時刻',
            'date_from' => '期間（開始日）',
            'date_to' => '期間（終了日）',
        ];
    }
}
