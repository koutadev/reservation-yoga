{{-- 週カレンダーの凡例。ゲージの高さと色の意味を添える。 --}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-4 text-xs text-gray-500 dark:text-gray-400']) }}>
    @foreach (\App\Enums\AvailabilityState::cases() as $state)
        <span class="flex items-center gap-1.5">
            <span class="relative block h-4 w-5 overflow-hidden rounded border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <span class="absolute inset-x-0 bottom-0 {{ $state->fillClass() }}"
                      style="height: {{ match ($state) {
                          \App\Enums\AvailabilityState::Open => 45,
                          \App\Enums\AvailabilityState::Few => 80,
                          \App\Enums\AvailabilityState::Full => 100,
                      } }}%"></span>
            </span>
            {{ $state->label() }}
        </span>
    @endforeach

    <span class="text-gray-400 dark:text-gray-500">塗りの高さ ＝ 予約の埋まり具合（予約数 ÷ 定員）</span>
</div>
