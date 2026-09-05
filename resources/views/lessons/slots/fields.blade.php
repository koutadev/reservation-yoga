@php
    use App\Enums\LessonSlotStatus;
    use App\Enums\LessonType;

    /** @var \App\Models\LessonSlot $slot */
    $currentType = old('lesson_type', $slot->lesson_type?->value ?? LessonType::Group->value);
@endphp

{{--
    レッスン枠の入力項目。フルページのフォームとモーダル編集で共有する。

    マンツーマンは定員 1 名で固定する（画面でも 1 に固定し、サーバ側でも 1 に直す）。
--}}
<div x-data="{ lessonType: @js($currentType) }"
     x-effect="if (lessonType === @js(LessonType::Personal->value)) { $refs.capacity.value = 1 }"
     class="space-y-5">

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <x-form.select name="instructor_id" label="講師" :options="$instructorOptions"
                       :selected="$slot->instructor_id" placeholder="選択してください" required />

        <x-form.text name="title" label="レッスン名" :value="$slot->title" required
                     placeholder="朝のベーシックヨガ" />
    </div>

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <x-form.text name="starts_at" type="datetime-local" label="開始日時"
                     :value="$slot->starts_at?->format('Y-m-d\TH:i')" required />

        <x-form.text name="ends_at" type="datetime-local" label="終了日時"
                     :value="$slot->ends_at?->format('Y-m-d\TH:i')" required />
    </div>

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
        <x-form.select name="lesson_type" label="形式" :options="$lessonTypeOptions"
                       :selected="$currentType" x-model="lessonType" required />

        <x-form.number name="capacity" label="定員" :value="$slot->capacity ?? 10"
                       :min="1" :max="100" required
                       x-ref="capacity"
                       x-bind:readonly="lessonType === @js(LessonType::Personal->value)"
                       :help="'マンツーマンは 1 名で固定です。'" />

        <x-form.select name="status" label="状態" :options="$statusOptions"
                       :selected="old('status', $slot->status?->value ?? LessonSlotStatus::Open->value)" required />
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        「{{ LessonSlotStatus::Canceled->label() }}」にすると、この枠の予約とキャンセル待ちもまとめて取り消されます。
        すでに入っている予約より少ない定員には変更できません。
    </p>

    <x-form.text name="online_url" type="url" label="オンライン URL" :value="$slot->online_url"
                 placeholder="https://example.com/meet/xxxx"
                 help="Zoom などの参加 URL（任意）。会員には予約後に表示します。" />
</div>
