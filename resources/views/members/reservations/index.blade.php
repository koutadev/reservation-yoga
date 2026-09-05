@php
    use App\Enums\ReservationStatus;

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Reservation> $upcoming */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Waitlist> $waiting */

    /**
     * 予約 1 件の見せ方（確定／繰り上がり／受講済み／キャンセル／中止）。
     *
     * @return array{label: string, tone: string}
     */
    $badge = static function (\App\Models\Reservation $reservation): array {
        $slot = $reservation->lessonSlot;

        if ($reservation->isCanceled()) {
            return ['label' => 'キャンセル', 'tone' => 'neutral'];
        }

        if ($slot?->isCanceled()) {
            return ['label' => '中止', 'tone' => 'danger'];
        }

        if ($slot !== null && $slot->starts_at->isPast()) {
            return ['label' => '受講済み', 'tone' => 'neutral'];
        }

        return $reservation->status === ReservationStatus::Promoted
            ? ['label' => '繰り上がりました', 'tone' => 'info']
            : ['label' => '確定', 'tone' => 'success'];
    };
@endphp

{{--
    マイ予約（会員）。

    予約中 / キャンセル待ち / 履歴 の 3 つに分ける。キャンセル待ちから席が回ってきた
    予約（繰上確定）は「繰り上がりました」と出して、自分で取った予約と区別する。

    キャンセルはこの画面とレッスン詳細の両方から行える（どちらも同じ処理を通り、
    受け付けるかどうかはサーバが枠を押さえて判断する）。
--}}
<x-member-layout>
    <h1 class="mb-4 text-xl font-semibold text-gray-900 dark:text-gray-100">マイ予約</h1>

    <x-flash />

    {{-- ===== 予約中 ===== --}}
    <section class="mb-6">
        <h2 class="mb-2 text-xs font-medium tracking-wider text-gray-500 dark:text-gray-400">予約中</h2>

        <div class="space-y-2.5">
            @forelse ($upcoming as $reservation)
                @php
                    $slot = $reservation->lessonSlot;
                    $state = $badge($reservation);
                    $promoted = $reservation->status === ReservationStatus::Promoted;
                    $cancelable = \App\Support\Reservations\ReservationCancellation::isWithinDeadline($slot);
                @endphp

                <div class="rounded-e-2xl border border-s-4 border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800
                            {{ $promoted ? 'border-s-sky-400' : 'border-s-emerald-400' }}">
                    <div class="flex items-start justify-between gap-3">
                        <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">
                            {{ $slot->starts_at->isoFormat('M月D日(ddd)') }}
                            {{ $slot->starts_at->format('H:i') }} – {{ $slot->ends_at->format('H:i') }}
                        </span>

                        <x-badge :tone="$state['tone']">{{ $state['label'] }}</x-badge>
                    </div>

                    <a href="{{ route('lessons.show', $slot->id) }}"
                       class="mt-1.5 block font-semibold text-gray-900 hover:underline dark:text-gray-100">
                        {{ $slot->title }}
                    </a>

                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ $slot->instructor?->name }}
                        <span class="mx-1 text-gray-300 dark:text-gray-600">/</span>
                        {{ $slot->lesson_type->label() }}
                    </p>

                    @if ($promoted)
                        <p class="mt-2 text-xs text-sky-700 dark:text-sky-300">
                            キャンセル待ちから繰り上がって確定しました。
                        </p>
                    @endif

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                        @if ($slot->online_url)
                            <a href="{{ $slot->online_url }}" target="_blank" rel="noopener noreferrer"
                               class="text-xs font-medium text-primary-text hover:underline">▶ オンラインで参加する</a>
                        @else
                            <span class="text-xs text-gray-400 dark:text-gray-500">参加 URL は当日までにご案内します</span>
                        @endif

                        @if ($cancelable)
                            <button type="button"
                                    x-on:click="$dispatch('open-modal', 'cancel-reservation-{{ $reservation->id }}')"
                                    class="text-xs text-rose-600 hover:underline dark:text-rose-400">
                                キャンセルする
                            </button>

                            <x-confirm-dialog name="cancel-reservation-{{ $reservation->id }}"
                                              title="予約をキャンセルしますか？"
                                              :action="route('reservations.cancel', $reservation->id)"
                                              method="DELETE" confirm="キャンセルする" cancel="やめる"
                                              :fields="['from' => 'my-reservations']">
                                {{ $slot->title }}（{{ $slot->starts_at->isoFormat('M月D日(ddd) HH:mm') }}）の予約を取り消します。
                                空いた席は、キャンセル待ちの方へ順に繰り上げます。
                            </x-confirm-dialog>
                        @else
                            <span class="text-xs text-gray-400 dark:text-gray-500">
                                キャンセル期限（開始 {{ $cancelDeadlineHours }} 時間前）を過ぎています
                            </span>
                        @endif
                    </div>
                </div>
            @empty
                <p class="rounded-2xl border border-dashed border-gray-300 bg-white px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400">
                    予約中のレッスンはありません。
                    <a href="{{ route('lessons.index') }}" class="text-primary-text underline">レッスンを探す</a>
                </p>
            @endforelse
        </div>
    </section>

    {{-- ===== キャンセル待ち ===== --}}
    @if ($waiting->isNotEmpty())
        <section class="mb-6">
            <h2 class="mb-2 text-xs font-medium tracking-wider text-gray-500 dark:text-gray-400">キャンセル待ち</h2>

            <div class="space-y-2.5">
                @foreach ($waiting as $entry)
                    @php $slot = $entry->lessonSlot; @endphp

                    <div class="rounded-e-2xl border border-s-4 border-gray-200 border-s-amber-400 bg-white p-4 dark:border-gray-700 dark:border-s-amber-400 dark:bg-gray-800">
                        <div class="flex items-start justify-between gap-3">
                            <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                {{ $slot->starts_at->isoFormat('M月D日(ddd)') }}
                                {{ $slot->starts_at->format('H:i') }} – {{ $slot->ends_at->format('H:i') }}
                            </span>

                            <x-badge tone="warning">待ち {{ $entry->waiting_rank }} 番目</x-badge>
                        </div>

                        <a href="{{ route('lessons.show', $slot->id) }}"
                           class="mt-1.5 block font-semibold text-gray-900 hover:underline dark:text-gray-100">
                            {{ $slot->title }}
                        </a>

                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ $slot->instructor?->name }}
                            <span class="mx-1 text-gray-300 dark:text-gray-600">/</span>
                            空きが出たら繰り上げます
                        </p>

                        <form method="POST" action="{{ route('waitlists.cancel', $entry->id) }}" class="mt-3 text-end">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="from" value="my-reservations">

                            <button type="submit" class="text-xs text-gray-500 hover:underline dark:text-gray-400">
                                キャンセル待ちを取り消す
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ===== 履歴 ===== --}}
    <section>
        <h2 class="mb-2 text-xs font-medium tracking-wider text-gray-500 dark:text-gray-400">履歴</h2>

        <div class="space-y-2.5">
            @forelse ($history as $reservation)
                @php
                    $slot = $reservation->lessonSlot;
                    $state = $badge($reservation);
                @endphp

                <div class="rounded-e-2xl border border-s-4 border-gray-200 border-s-gray-200 bg-white p-4 opacity-75 dark:border-gray-700 dark:border-s-gray-600 dark:bg-gray-800">
                    <div class="flex items-start justify-between gap-3">
                        <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">
                            {{ $slot->starts_at->isoFormat('M月D日(ddd)') }}
                            {{ $slot->starts_at->format('H:i') }}
                        </span>

                        <x-badge :tone="$state['tone']">{{ $state['label'] }}</x-badge>
                    </div>

                    <a href="{{ route('lessons.show', $slot->id) }}"
                       class="mt-1.5 block font-medium text-gray-800 hover:underline dark:text-gray-200">
                        {{ $slot->title }}
                    </a>

                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $slot->instructor?->name }}</p>
                </div>
            @empty
                <p class="rounded-2xl border border-dashed border-gray-300 bg-white px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400">
                    受講の履歴はまだありません。
                </p>
            @endforelse
        </div>

        <div class="mt-4">
            {{ $history->links() }}
        </div>
    </section>
</x-member-layout>
