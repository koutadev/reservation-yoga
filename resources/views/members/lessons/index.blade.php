@php
    /** @var \App\Support\Lessons\WeekCalendar $calendar */
    /** @var list<\App\Models\LessonSlot> $daySlots */

    $dayTone = static fn (\Illuminate\Support\Carbon $day): string => match ((int) $day->dayOfWeek) {
        0 => 'text-rose-600 dark:text-rose-300',
        6 => 'text-sky-700 dark:text-sky-300',
        default => 'text-gray-700 dark:text-gray-300',
    };
@endphp

{{--
    空き枠の一覧（会員）。

    同じ 1 週間ぶんのデータを、幅で出し分けている。
      - スマホ: 日付チップで日を切り替え、その日のレッスンをリストで見る
      - PC:     週カレンダー（時刻 × 日〜土。コマは予約数 ÷ 定員のゲージ）

    出る枠は「これから始まる、開講または締切の枠」。締切は「受付終了」と出して
    予約できないことを示し、中止の枠は一覧に出さない（DEC-016）。
--}}
<x-member-layout>
    <div class="mb-4">
        <p class="text-xs text-gray-500 dark:text-gray-400">こんにちは、{{ auth()->user()?->name }}さん</p>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">レッスンを探す</h1>
    </div>

    <x-flash />

    {{-- 絞り込み（講師・形式）。日付と週はそのまま引き継ぐ --}}
    <form method="GET" action="{{ route('lessons.index') }}"
          class="mb-4 flex flex-wrap items-end gap-3 rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
        <input type="hidden" name="date" value="{{ $selectedDate->toDateString() }}">

        <div class="min-w-40 flex-1">
            <label for="instructor_id" class="block text-xs font-medium text-gray-600 dark:text-gray-400">講師</label>
            <x-select-input id="instructor_id" name="instructor_id" class="mt-1 block w-full text-sm"
                            :options="$instructorOptions"
                            :selected="$filters['instructor_id'] ?? null"
                            placeholder="すべての講師" />
        </div>

        <div class="min-w-32 flex-1">
            <label for="lesson_type" class="block text-xs font-medium text-gray-600 dark:text-gray-400">形式</label>
            <x-select-input id="lesson_type" name="lesson_type" class="mt-1 block w-full text-sm"
                            :options="$lessonTypeOptions"
                            :selected="$filters['lesson_type'] ?? null"
                            placeholder="すべての形式" />
        </div>

        <div class="flex items-center gap-2">
            <x-button type="submit" size="sm">絞り込む</x-button>

            @if ($filters !== [])
                <a href="{{ route('lessons.index', ['date' => $selectedDate->toDateString()]) }}"
                   class="text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400">条件をクリア</a>
            @endif
        </div>
    </form>

    {{-- 週の移動 --}}
    <div class="mb-3 flex items-center gap-2">
        @if ($previousWeekDate !== null)
            <x-button size="sm" variant="secondary"
                      :href="route('lessons.index', $filters + ['date' => $previousWeekDate])"
                      aria-label="前の週">‹</x-button>
        @else
            <x-button size="sm" type="button" variant="secondary" :disabled="true" aria-label="前の週">‹</x-button>
        @endif

        <span class="text-sm font-medium tabular-nums text-gray-700 dark:text-gray-300">{{ $calendar->rangeLabel() }}</span>

        <x-button size="sm" variant="secondary"
                  :href="route('lessons.index', $filters + ['date' => $nextWeekDate])"
                  aria-label="次の週">›</x-button>

        <x-button size="sm" variant="ghost" class="ms-auto"
                  :href="route('lessons.index', $filters + ['date' => $today->toDateString()])">今日</x-button>
    </div>

    {{-- ===== スマホ: 日付チップ ＋ その日のリスト ===== --}}
    <div id="day-list" class="lg:hidden">
        <div class="mb-4 grid grid-cols-7 gap-1.5">
            @foreach ($calendar->days() as $day)
                @php
                    $isSelected = $day->isSameDay($selectedDate);
                    $isPast = $day->lt($today);
                @endphp

                @if ($isPast)
                    <span class="rounded-xl border border-gray-100 py-2 text-center text-gray-300 dark:border-gray-800 dark:text-gray-600">
                        <span class="block text-[11px]">{{ $day->isoFormat('ddd') }}</span>
                        <span class="block text-base font-semibold tabular-nums">{{ $day->format('j') }}</span>
                    </span>
                @else
                    <a href="{{ route('lessons.index', $filters + ['date' => $day->toDateString()]) }}"
                       @if ($isSelected) aria-current="date" @endif
                       class="rounded-xl border py-2 text-center transition motion-reduce:transition-none
                              {{ $isSelected
                                  ? 'border-primary bg-primary text-white'
                                  : 'border-gray-200 bg-white '.$dayTone($day).' hover:border-primary dark:border-gray-700 dark:bg-gray-800' }}">
                        <span class="block text-[11px]">{{ $day->isoFormat('ddd') }}</span>
                        <span class="block text-base font-semibold tabular-nums">{{ $day->format('j') }}</span>
                    </a>
                @endif
            @endforeach
        </div>

        <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
            {{ $selectedDate->isoFormat('M月D日(ddd)') }}のレッスン {{ count($daySlots) }} 件
        </p>

        <div class="space-y-2.5">
            @forelse ($daySlots as $slot)
                <x-lesson.card :lesson="$slot"
                               :reserved="in_array($slot->id, $reservedSlotIds, true)"
                               :waiting="in_array($slot->id, $waitingSlotIds, true)" />
            @empty
                <p class="rounded-2xl border border-dashed border-gray-300 bg-white px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400">
                    この日のレッスンはありません。ほかの日を選んでください。
                </p>
            @endforelse
        </div>
    </div>

    {{-- ===== PC: 週カレンダー ===== --}}
    <div id="week-calendar" class="hidden lg:block">
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            @if ($calendar->isEmpty())
                <p class="px-4 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                    この週に予約できるレッスンはありません。次の週を見てください。
                </p>
            @else
                <div class="grid grid-cols-[56px_repeat(7,minmax(0,1fr))]">
                    {{-- 曜日の見出し --}}
                    <div class="border-b border-e border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/40"></div>

                    @foreach ($calendar->days() as $day)
                        <div class="border-b border-gray-200 bg-gray-50 px-1 py-2 text-center text-xs dark:border-gray-700 dark:bg-gray-900/40 {{ $loop->last ? '' : 'border-e' }}">
                            <span class="font-semibold {{ $dayTone($day) }}">{{ $day->isoFormat('ddd') }}</span>
                            <span class="block text-[11px] tabular-nums {{ $day->isSameDay($today) ? 'font-semibold text-primary' : 'text-gray-400 dark:text-gray-500' }}">
                                {{ $day->format('j') }}
                            </span>
                        </div>
                    @endforeach

                    {{-- 開講のある時刻だけを行にする（1 週間がスクロールなしに収まる） --}}
                    @foreach ($calendar->rows() as $row)
                        <div data-row-time="{{ $row['time'] }}"
                             class="border-b border-e border-gray-200 px-1.5 pt-2 text-end text-[11px] tabular-nums text-gray-400 dark:border-gray-700 dark:text-gray-500">
                            {{ $row['time'] }}
                        </div>

                        @foreach ($row['cells'] as $index => $cell)
                            <div class="flex min-h-20 flex-col gap-1 border-b border-gray-200 p-1 dark:border-gray-700 {{ $index === 6 ? '' : 'border-e' }}">
                                {{-- 同じ日・同じ時刻に複数あるときは上下に積む --}}
                                @foreach ($cell as $slot)
                                    <x-lesson.gauge-slot :lesson="$slot"
                                                         :reserved="in_array($slot->id, $reservedSlotIds, true)"
                                                         :waiting="in_array($slot->id, $waitingSlotIds, true)" />
                                @endforeach
                            </div>
                        @endforeach
                    @endforeach
                </div>
            @endif
        </div>

        <x-lesson.legend class="mt-3" />
    </div>
</x-member-layout>
