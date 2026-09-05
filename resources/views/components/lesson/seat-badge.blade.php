@props(['lesson', 'availability' => null])

@php
    /** @var \App\Models\LessonSlot $lesson */
    $availability ??= \App\Support\Lessons\SlotAvailability::of($lesson);
@endphp

{{-- 空き状況のバッジ。締切は受付終了、満席は満席、マンツーマンは「個人」 --}}
@if ($lesson->isClosed())
    <x-badge tone="neutral">受付終了</x-badge>
@else
    <x-badge :tone="$availability->badgeTone()">{{ $availability->badgeLabel() }}</x-badge>
@endif
