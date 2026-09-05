@props(['lesson', 'availability' => null, 'reserved' => false, 'waiting' => false])

@php
    /** @var \App\Models\LessonSlot $lesson */
    $availability ??= \App\Support\Lessons\SlotAvailability::of($lesson);
@endphp

{{-- 空き状況のバッジ。自分の予約・キャンセル待ちがあれば、そちらを優先して出す --}}
@if ($reserved)
    <x-badge tone="info">予約済み</x-badge>
@elseif ($waiting)
    <x-badge tone="warning">キャンセル待ち</x-badge>
@elseif ($lesson->isClosed())
    <x-badge tone="neutral">受付終了</x-badge>
@else
    <x-badge :tone="$availability->badgeTone()">{{ $availability->badgeLabel() }}</x-badge>
@endif
