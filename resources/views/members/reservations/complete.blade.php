@php
    /** @var \App\Models\Reservation $reservation */
    /** @var \App\Models\LessonSlot $slot */
@endphp

{{--
    予約完了（簡易）。

    予約の一覧・キャンセルはマイ予約（STEP6）で作るため、ここでは
    「何がいつ確定したか」と当日の入り口だけを見せる。
--}}
<x-member-layout>
    <div class="mx-auto max-w-2xl">
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-center dark:border-emerald-800 dark:bg-emerald-900/30">
            <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-200">予約が確定しました</p>
            <p class="mt-1 font-mono text-xs text-emerald-700 dark:text-emerald-300">{{ $reservation->code }}</p>
        </div>

        <div class="mt-4 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $slot->title }}</h1>

            <dl class="mt-4 space-y-3 border-t border-gray-100 pt-4 text-sm dark:border-gray-700">
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
                    <dt class="text-gray-500 dark:text-gray-400">形式</dt>
                    <dd class="font-medium text-gray-900 dark:text-gray-100">オンライン（{{ $slot->lesson_type->label() }}）</dd>
                </div>

                @if ($slot->online_url)
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">参加 URL</dt>
                        <dd class="min-w-0 text-end">
                            <a href="{{ $slot->online_url }}" rel="noopener noreferrer" target="_blank"
                               class="break-all text-primary-text underline">{{ $slot->online_url }}</a>
                        </dd>
                    </div>
                @endif

                <div class="flex items-start justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">キャンセル期限</dt>
                    <dd class="font-medium text-gray-900 dark:text-gray-100">開始 {{ $cancelDeadlineHours }} 時間前まで</dd>
                </div>
            </dl>

            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                レッスン前日にリマインドをお送りします。キャンセルの受付とマイ予約は準備中です。
            </p>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <x-button :href="route('lessons.index', ['date' => $slot->starts_at->toDateString()])">ほかのレッスンを探す</x-button>
            <x-button variant="secondary" :href="route('lessons.show', $slot->id)">このレッスンの詳細</x-button>
        </div>
    </div>
</x-member-layout>
