@php
    use App\Support\Lessons\SlotAvailability;

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\LessonSlot> $slots */

    $query = static fn (string $date): array => ['date' => $date];
@endphp

{{--
    予約状況ダッシュボード（管理／講師）。

    その日の稼働を 1 画面で掴むための画面。KPI カードは共通基盤の部品をそのまま使い、
    集計は「その日の枠を 1 回引いた結果」から組み立てる（枠や予約が増えても
    クエリ本数は変わらない）。

    PC は一覧性を優先したテーブル、スマホではカードに切り替える。
--}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">予約状況</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            <x-flash />

            {{-- 日付の移動 --}}
            <div class="flex flex-wrap items-center gap-2">
                <x-button size="sm" variant="secondary"
                          :href="route('reservations.dashboard', $query($previousDate))" aria-label="前の日">‹</x-button>

                <span class="text-sm font-medium tabular-nums text-gray-700 dark:text-gray-300">
                    {{ $date->isoFormat('Y年M月D日(ddd)') }}
                </span>

                <x-button size="sm" variant="secondary"
                          :href="route('reservations.dashboard', $query($nextDate))" aria-label="次の日">›</x-button>

                <x-button size="sm" variant="ghost"
                          :href="route('reservations.dashboard', $query($today->toDateString()))">本日</x-button>

                @if ($shiftedToNextDay)
                    <span class="text-xs text-amber-700 dark:text-amber-300">
                        本日は開講がないため、次に開講がある日を表示しています。
                    </span>
                @endif
            </div>

            {{-- KPI --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($kpis as $kpi)
                    <x-dashboard.kpi-card :kpi="$kpi" />
                @endforeach
            </div>

            {{-- その日の枠 --}}
            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">
                        {{ $date->isoFormat('M月D日(ddd)') }}の枠一覧（{{ $slots->count() }} 件）
                    </h3>

                    @if ($canManageSlots)
                        <span class="text-xs text-gray-400 dark:text-gray-500">行をクリックすると枠の詳細が開きます</span>
                    @endif
                </div>

                @if ($slots->isEmpty())
                    <p class="px-5 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                        この日に開講しているレッスンはありません。
                    </p>
                @else
                    {{-- PC: テーブル --}}
                    <div class="hidden md:block">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
                                <tr>
                                    <th class="px-4 py-3 text-start font-medium">時間</th>
                                    <th class="px-4 py-3 text-start font-medium">レッスン</th>
                                    <th class="px-4 py-3 text-start font-medium">講師</th>
                                    <th class="px-4 py-3 text-start font-medium">形式</th>
                                    <th class="px-4 py-3 text-end font-medium">予約 / 定員</th>
                                    <th class="px-4 py-3 text-start font-medium">稼働</th>
                                    <th class="px-4 py-3 text-end font-medium">待ち</th>
                                    <th class="px-4 py-3 text-center font-medium">状態</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($slots as $slot)
                                    @php $availability = SlotAvailability::of($slot); @endphp

                                    <x-table.row :muted="$slot->isCanceled()"
                                                 :detail-url="$canManageSlots ? route('lesson-slots.detail', $slot->id) : null">
                                        <td class="whitespace-nowrap px-4 py-3 tabular-nums text-gray-600 dark:text-gray-400">
                                            {{ $slot->starts_at->format('H:i') }}
                                        </td>

                                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $slot->title }}</td>

                                        <td class="whitespace-nowrap px-4 py-3 text-gray-600 dark:text-gray-400">
                                            {{ $slot->instructor?->name }}
                                        </td>

                                        <td class="whitespace-nowrap px-4 py-3 text-gray-600 dark:text-gray-400">
                                            {{ $slot->lesson_type->label() }}
                                        </td>

                                        <td class="whitespace-nowrap px-4 py-3 text-end tabular-nums">
                                            {{ $slot->reserved_count }} / {{ $slot->capacity }}
                                        </td>

                                        <td class="px-4 py-3">
                                            <span class="inline-block h-2 w-24 overflow-hidden rounded-full bg-gray-100 align-middle dark:bg-gray-700"
                                                  role="img"
                                                  aria-label="稼働 {{ $availability->fillPercent() }}%">
                                                <span class="block h-full {{ $availability->state->barClass() }}"
                                                      style="width: {{ $availability->fillPercent() }}%"></span>
                                            </span>
                                            <span class="ms-2 text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                                {{ $availability->fillPercent() }}%
                                            </span>
                                        </td>

                                        <td class="whitespace-nowrap px-4 py-3 text-end tabular-nums text-gray-600 dark:text-gray-400">
                                            {{ $slot->waiting_count > 0 ? $slot->waiting_count.' 名' : '—' }}
                                        </td>

                                        <td class="whitespace-nowrap px-4 py-3 text-center">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $slot->status->badgeClass() }}">
                                                {{ $slot->status->label() }}
                                            </span>
                                        </td>
                                    </x-table.row>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- スマホ: カード --}}
                    <ul class="divide-y divide-gray-100 md:hidden dark:divide-gray-700">
                        @foreach ($slots as $slot)
                            @php $availability = SlotAvailability::of($slot); @endphp

                            <li class="px-4 py-3 {{ $slot->isCanceled() ? 'opacity-60' : '' }}">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                        {{ $slot->starts_at->format('H:i') }} – {{ $slot->ends_at->format('H:i') }}
                                    </span>

                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $slot->status->badgeClass() }}">
                                        {{ $slot->status->label() }}
                                    </span>
                                </div>

                                <p class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ $slot->title }}</p>

                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $slot->instructor?->name }}
                                    <span class="mx-1 text-gray-300 dark:text-gray-600">/</span>
                                    {{ $slot->lesson_type->label() }}
                                </p>

                                <div class="mt-2 flex items-center gap-2">
                                    <span class="inline-block h-2 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                        <span class="block h-full {{ $availability->state->barClass() }}"
                                              style="width: {{ $availability->fillPercent() }}%"></span>
                                    </span>

                                    <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                        {{ $slot->reserved_count }} / {{ $slot->capacity }}
                                        @if ($slot->waiting_count > 0)
                                            <span class="ms-1 text-amber-700 dark:text-amber-300">待ち {{ $slot->waiting_count }}</span>
                                        @endif
                                    </span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($canManageSlots)
                {{-- 行クリックで開く枠の詳細（レッスン枠一覧と同じモーダル） --}}
                <x-master-detail-modal :deletable="false" detail-view="lessons.slots._detail" />
            @endif
        </div>
    </div>
</x-app-layout>
