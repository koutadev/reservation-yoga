@php
    use App\Enums\LessonSlotStatus;
    use App\Enums\LessonType;
    use App\Support\Lessons\RecurringSlots;

    /** @var \App\Models\LessonSlot $slot */
    $currentType = old('lesson_type', LessonType::Group->value);
    $selectedWeekdays = array_map('intval', (array) old('weekdays', []));

    // 0(日) 〜 6(土)。Carbon の dayOfWeek と同じ並び
    $weekdayLabels = ['日', '月', '火', '水', '木', '金', '土'];
@endphp

{{--
    繰り返し開講の入力項目（STEP2-3）。

      曜日（複数可）× 時間 × レッスン内容 × 講師 × 形式 × 定員 × URL × 期間

    生成される枠は 1 件ずつ独立していて、あとから個別に編集・中止できる。
    同じ講師・同じ日時の枠がすでにあれば作らずに飛ばす。
--}}
<div x-data="{ lessonType: @js($currentType) }"
     x-effect="if (lessonType === @js(LessonType::Personal->value)) { $refs.recurringCapacity.value = 1 }"
     class="space-y-5">

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <x-form.select name="instructor_id" label="講師" :options="$instructorOptions"
                       :selected="$slot->instructor_id" placeholder="選択してください" required />

        <x-form.text name="title" label="レッスン名" :value="null" required
                     placeholder="朝のベーシックヨガ" />
    </div>

    {{-- 曜日（複数可） --}}
    <div class="space-y-1">
        <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">
            曜日
            <span class="ms-1 text-xs font-normal text-rose-600 dark:text-rose-400">必須</span>
        </span>

        <div class="flex flex-wrap gap-2 pt-1" role="group" aria-label="曜日">
            @foreach ($weekdayLabels as $value => $label)
                <label for="weekday-{{ $value }}"
                       class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-gray-300 px-3 py-1.5 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary-soft dark:border-gray-600">
                    <input type="checkbox" id="weekday-{{ $value }}" name="weekdays[]" value="{{ $value }}"
                           @checked(in_array($value, $selectedWeekdays, true))
                           class="rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-900">
                    <span @class(['text-rose-600 dark:text-rose-400' => $value === 0, 'text-sky-600 dark:text-sky-400' => $value === 6])>
                        {{ $label }}
                    </span>
                </label>
            @endforeach
        </div>

        <x-input-error :messages="$errors->get('weekdays')" class="mt-1" />
    </div>

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <x-form.text name="start_time" type="time" label="開始時刻" :value="old('start_time', '07:30')" required />
        <x-form.text name="end_time" type="time" label="終了時刻" :value="old('end_time', '08:15')" required />
    </div>

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <x-form.date name="date_from" label="期間（開始日）"
                     :value="old('date_from', now()->addDay()->toDateString())" required />
        <x-form.date name="date_to" label="期間（終了日）"
                     :value="old('date_to', now()->addMonth()->toDateString())" required
                     :help="'一度に開講できるのは '.RecurringSlots::MAX_SLOTS.' 件までです。'" />
    </div>

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
        <x-form.select name="lesson_type" label="形式" :options="$lessonTypeOptions"
                       :selected="$currentType" x-model="lessonType" required />

        <x-form.number name="capacity" label="定員" :value="old('capacity', 10)"
                       :min="1" :max="100" required
                       x-ref="recurringCapacity"
                       x-bind:readonly="lessonType === @js(LessonType::Personal->value)"
                       :help="'マンツーマンは 1 名で固定です。'" />

        <x-form.select name="status" label="状態" :options="$statusOptions"
                       :selected="old('status', LessonSlotStatus::Open->value)" required />
    </div>

    <x-form.text name="online_url" type="url" label="オンライン URL" :value="old('online_url')"
                 placeholder="https://example.com/meet/xxxx"
                 help="生成するすべての枠に同じ URL を設定します（任意。あとから枠ごとに変更できます）。" />

    <p class="rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
        指定した期間の該当日ぶん、レッスン枠を 1 件ずつ作ります。作られた枠は独立しているので、
        あとから 1 枠だけ定員を変えたり中止にしたりできます。
        同じ講師・同じ日時の枠がすでにある日は作成せずに飛ばします。
    </p>
</div>
