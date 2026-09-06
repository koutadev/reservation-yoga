@php
    /** @var \App\Models\LessonSlot $slot */
    /** @var \App\Support\Lessons\SlotAvailability $availability */
@endphp

{{--
    レッスン詳細（会員）。

    下部の固定 CTA から、予約・キャンセル・キャンセル待ちの登録／取り消しを行う
    （片手で押せる位置に置く）。出すボタンは会員のいまの状態で決まるが、それは
    あくまで下ごしらえで、受け付けるかどうかは送信を受けたサーバが枠をロックして
    数え直したうえで決める（ReservationBooking / ReservationCancellation /
    WaitlistRegistration）。

    予約の一覧（マイ予約）は STEP6。
--}}
<x-member-layout>
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('lessons.index', ['date' => $slot->starts_at->toDateString()]) }}"
           class="mb-3 inline-flex min-h-11 items-center gap-1 text-sm text-gray-500 hover:text-gray-700 sm:min-h-0 dark:text-gray-400">
            ‹ レッスン一覧へ
        </a>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-wrap items-center gap-2">
                <x-badge tone="neutral">{{ $slot->lesson_type->label() }}</x-badge>
                <x-lesson.seat-badge :lesson="$slot" :availability="$availability"
                                     :reserved="$reservation !== null" :waiting="$waitlist !== null" />

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

                @if ($availability->isFull() || $waitingCount > 0)
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">キャンセル待ち</dt>
                        <dd class="font-medium text-gray-900 dark:text-gray-100">
                            現在 {{ $waitingCount }} 名
                            @if ($waitingRank !== null)
                                <span class="text-xs text-amber-700 dark:text-amber-300">（あなたは {{ $waitingRank }} 番目）</span>
                            @endif
                        </dd>
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

        {{-- 下部の固定 CTA（片手で操作が届く位置に置く） --}}
        <div class="sticky bottom-0 mt-4 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            @switch ($booking['action'])
                @case ('reserve')
                    <form method="POST" action="{{ route('lessons.reserve', $slot->id) }}">
                        @csrf

                        <x-button class="w-full" type="submit">{{ $booking['label'] }}</x-button>
                    </form>
                    @break

                @case ('waitlist')
                    <form method="POST" action="{{ route('lessons.waitlist', $slot->id) }}">
                        @csrf

                        <x-button class="w-full" type="submit" variant="secondary">{{ $booking['label'] }}</x-button>
                    </form>
                    @break

                @case ('cancel')
                    {{-- 取り消しは戻せないので、一度確認する --}}
                    <x-button class="w-full" type="button" variant="danger"
                              x-on:click="$dispatch('open-modal', 'cancel-reservation')">
                        {{ $booking['label'] }}
                    </x-button>

                    <x-confirm-dialog name="cancel-reservation" title="予約をキャンセルしますか？"
                                      :action="route('reservations.cancel', $reservation->id)"
                                      method="DELETE" confirm="キャンセルする" cancel="やめる">
                        空いた席は、キャンセル待ちの方へ順に繰り上げます。同じレッスンを取り直すこともできます。
                    </x-confirm-dialog>
                    @break

                @case ('withdraw')
                    <form method="POST" action="{{ route('waitlists.cancel', $waitlist->id) }}">
                        @csrf
                        @method('DELETE')

                        <x-button class="w-full" type="submit" variant="secondary">{{ $booking['label'] }}</x-button>
                    </form>
                    @break

                @default
                    <x-button class="w-full" type="button" variant="secondary" :disabled="true">
                        {{ $booking['label'] }}
                    </x-button>
            @endswitch

            @if ($booking['note'] !== '')
                <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">{{ $booking['note'] }}</p>
            @endif
        </div>
    </div>
</x-member-layout>
