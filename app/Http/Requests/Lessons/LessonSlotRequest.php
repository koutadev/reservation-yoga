<?php

namespace App\Http\Requests\Lessons;

use App\Enums\LessonSlotStatus;
use App\Enums\LessonType;
use App\Models\LessonSlot;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * レッスン枠 1 件の開講・編集。
 *
 * 権限はルート（lesson_slot.manage）と LessonSlotPolicy で見るため、ここでは常に許可する。
 *
 * ここで守る業務ルール（STEP2-2）
 *   - マンツーマンの定員は 1 名で固定する
 *   - すでに入っている予約の数より小さい定員には変更できない
 */
class LessonSlotRequest extends FormRequest
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
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['required', 'integer', 'min:1', 'max:100'],
            'online_url' => ['nullable', 'string', 'url', 'max:255'],
            'status' => ['required', Rule::enum(LessonSlotStatus::class)],
        ];
    }

    /**
     * 入力値の正規化。
     *
     *   - 空文字は null にする（未入力のオンライン URL など）
     *   - マンツーマンは定員 1 名で固定する（画面で何を送られても 1 に直す）
     *
     * 枠の状態は status（開講／締切／中止）で表すため、共通の有効フラグ（is_active）は
     * この画面では扱わない（DB の既定値のまま）。
     */
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
            $this->ensureCapacityFitsReservations($validator);
        });
    }

    /**
     * すでに入っている予約より小さい定員には変更できない。
     *
     * 予約数は枠に持たせていないので、ここでも都度数える（STEP1 の occupying()）。
     */
    private function ensureCapacityFitsReservations(Validator $validator): void
    {
        $slot = $this->slot();

        if ($slot === null || $validator->errors()->has('capacity')) {
            return;
        }

        $reserved = $slot->activeReservations()->count();
        $capacity = (int) $this->input('capacity');

        if ($capacity >= $reserved) {
            return;
        }

        $validator->errors()->add('capacity', sprintf(
            'この枠にはすでに %d 件の予約が入っています。定員を %d 名より少なくすることはできません。',
            $reserved,
            $reserved,
        ));
    }

    /**
     * 編集中の枠（新規開講なら null）。
     */
    private function slot(): ?LessonSlot
    {
        $id = $this->route('id');

        return is_numeric($id) ? LessonSlot::query()->find((int) $id) : null;
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
            'starts_at' => '開始日時',
            'ends_at' => '終了日時',
            'capacity' => '定員',
            'online_url' => 'オンライン URL',
            'status' => '状態',
        ];
    }
}
