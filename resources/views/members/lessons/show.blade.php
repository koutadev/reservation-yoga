@php
    /** @var \App\Models\LessonSlot $slot */
    /** @var \App\Support\Lessons\SlotAvailability $availability */
@endphp

{{--
    レッスン詳細（会員）。

    下部の固定 CTA から予約する（片手で押せる位置に置く）。押せるかどうかの表示は
    あくまで下ごしらえで、受け付けるかどうかは送信を受けたサーバが枠をロックして
    数え直したうえで決める（ReservationBooking）。

    キャンセル待ちの登録は STEP5、マイ予約は STEP6。
--}}
<x-member-layout>
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('lessons.index', ['date' => $slot->starts_at->toDateString()]) }}"
           class="mb-3 inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">
            ‹ レッスン一覧へ
        </a>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-wrap items-center gap-2">
                <x-badge tone="neutral">{{ $slot->lesson_type->label() }}</x-badge>
                <x-lesson.seat-badge :lesson="$slot" :availability="$availability" :reserved="$reservation !== null" />

                @if ($slot->isCanceled())
                    <x-badge tone="danger">中止</x-badge>
                @endif
            </div>

            <h1 class="mt-3 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $slot->title }}</h1>

            @if ($slot->instructor?->profile)
                <p class="mt-2 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ $slot->instructor->profile }}</p>
            @endif

            <dl class="mt-5 space-y-3 border-t border-gray-100 pt-4 text-sm dark:border-gray-700">
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">日時</dt>
                    <dd class="text-end font-medium text-gray-900 dark:text-gray-100">
                        {{ $slot->starts_at->isoFormat('M月D日(ddd)') }}
                        <span class="tabular-nums">{{ $slot->starts_at->format('H:i') }} – {{ $slot->ends_at->format('H:i') }}</span>
                    </dd>
                </div>

                <div class="flex items-start justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">講師</dt>
                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $slot->instructor?->name }}</dd>
                </div>

                <div class="flex items-start justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">残り枠</dt>
                    <dd class="font-medium tabular-nums {{ $availability->isFull() ? 'text-gray-400 dark:text-gray-500' : 'text-emerald-700 dark:text-emerald-300' }}">
                        {{ $availability->remaining }} / {{ $availability->capacity }}
                    </dd>
                </div>

                @if ($availability->isFull())
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">キャンセル待ち</dt>
                        <dd class="font-medium text-gray-900 dark:text-gray-100">現在 {{ $waitingCount }} 名</dd>
                    </div>
                @endif

                <div class="flex items-start justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">形式</dt>
                    <dd class="font-medium text-gray-900 dark:text-gray-100">
                        オンライン（{{ $slot->lesson_type->label() }}）
                    </dd>
                </div>

                <div class="flex items-start justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">キャンセル期限</dt>
                    <dd class="font-medium text-gray-900 dark:text-gray-100">開始 {{ $cancelDeadlineHours }} 時間前まで</dd>
                </div>

                <div class="flex items-start justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">レッスンコード</dt>
                    <dd class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $slot->code }}</dd>
                </div>
            </dl>
        </div>

        {{-- 下部の固定 CTA（片手で予約まで届く位置に置く） --}}
        <div class="sticky bottom-0 mt-4 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            @if ($booking['bookable'])
                <form method="POST" action="{{ route('lessons.reserve', $slot->id) }}">
                    @csrf

                    <x-button class="w-full" type="submit">予約する</x-button>
                </form>
            @else
                <x-button class="w-full" type="button" variant="secondary" :disabled="true">
                    {{ $booking['label'] }}
                </x-button>
            @endif

            @if ($booking['note'] !== '')
                <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">{{ $booking['note'] }}</p>
            @endif
        </div>
    </div>
</x-member-layout>
