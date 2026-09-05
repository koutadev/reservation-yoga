@props(['lesson', 'reserved' => false, 'waiting' => false])

@php
    /** @var \App\Models\LessonSlot $lesson */
    $availability = \App\Support\Lessons\SlotAvailability::of($lesson);
@endphp

{{-- スマホのレッスンリストの 1 件。タップで詳細へ。 --}}
<a href="{{ route('lessons.show', $lesson->id) }}"
   class="block rounded-2xl border border-gray-200 bg-white p-4 transition hover:border-primary hover:shadow-sm motion-reduce:transition-none dark:border-gray-700 dark:bg-gray-800 {{ $lesson->isClosed() ? 'opacity-70' : '' }}">
    <div class="flex items-start justify-between gap-3">
        <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">
            {{ $lesson->starts_at->format('H:i') }} – {{ $lesson->ends_at->format('H:i') }}
        </span>

        <x-lesson.seat-badge :lesson="$lesson" :availability="$availability"
                             :reserved="$reserved" :waiting="$waiting" />
    </div>

    <p class="mt-1.5 font-semibold text-gray-900 dark:text-gray-100">{{ $lesson->title }}</p>

    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
        {{ $lesson->instructor?->name }}
        <span class="mx-1 text-gray-300 dark:text-gray-600">/</span>
        {{ $lesson->lesson_type->label() }}
    </p>
</a>
