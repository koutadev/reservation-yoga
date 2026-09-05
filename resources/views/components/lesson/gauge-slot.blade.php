@props(['lesson', 'reserved' => false])

@php
    /** @var \App\Models\LessonSlot $lesson */
    $availability = \App\Support\Lessons\SlotAvailability::of($lesson);
    $state = $availability->state;
    $closed = $lesson->isClosed();
@endphp

{{--
    週カレンダーのコマ。

    予約数 ÷ 定員を下から塗り（面積）、状態で色を変え、数値も併記する。
    色だけに頼らずに埋まり具合が分かるようにするための三重表示（DEC-014）。
--}}
<a href="{{ route('lessons.show', $lesson->id) }}"
   title="{{ $lesson->starts_at->format('H:i') }} {{ $lesson->title }}／{{ $reserved ? '予約済み' : ($closed ? '受付終了' : $availability->description()) }}"
   aria-label="{{ $lesson->starts_at->format('n月j日 H:i') }} {{ $lesson->title }} {{ $lesson->instructor?->name }} {{ $reserved ? '予約済み' : ($closed ? '受付終了' : $availability->description()) }}"
   class="relative block flex-1 overflow-hidden rounded-lg border bg-white transition hover:ring-2 hover:ring-primary/40 motion-reduce:transition-none dark:bg-gray-800 {{ $closed ? 'border-gray-200 opacity-70 dark:border-gray-700' : $state->borderClass() }}">
    <span aria-hidden="true"
          class="absolute inset-x-0 bottom-0 {{ $closed ? 'bg-gray-300/40' : $state->fillClass() }}"
          style="height: {{ $availability->fillPercent() }}%"></span>

    <span class="relative block px-2 py-1.5">
        <span class="block truncate text-xs font-semibold text-gray-900 dark:text-gray-100">{{ $lesson->title }}</span>

        <span class="mt-0.5 block text-[11px] tabular-nums {{ $reserved ? 'font-semibold text-primary' : ($closed ? 'text-gray-500 dark:text-gray-400' : $state->metaClass()) }}">
            {{ $reserved ? '予約済み' : ($closed ? '受付終了' : $availability->gaugeLabel()) }}
        </span>
    </span>
</a>
