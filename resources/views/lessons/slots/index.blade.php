@php
    /** @var \App\Support\DataTable\Table $table */
@endphp

{{--
    レッスン枠一覧（管理/講師）。

    行をクリックすると詳細・編集のモーダルが開くので、一覧に編集ボタンは置かない
    （共通マスタと同じ操作感）。既定では「今後の枠」を日時の早い順に出し、
    過去の枠は期間の絞り込みで切り替える。
--}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                レッスン枠
            </h2>

            <a href="{{ route('lesson-slots.create') }}"
               class="inline-flex min-h-11 items-center rounded-md bg-primary px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition hover:bg-primary-hover sm:min-h-0">
                ＋ 枠を開講
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <x-flash />

            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                行をクリックすると、その枠の詳細と編集フォームが開きます。
            </p>

            <x-data-table :table="$table" :actions="false">
                <x-slot name="extraFilters">
                    <div>
                        <label for="dt-period" class="block text-xs font-medium text-gray-600 dark:text-gray-400">期間</label>
                        <select id="dt-period" name="period"
                                class="mt-1 block min-h-11 w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary sm:min-h-0 sm:text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            @foreach ($periodOptions as $value => $label)
                                <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </x-slot>

                @foreach ($table->items() as $slot)
                    <x-table.row :detail-url="route('lesson-slots.detail', $slot->id)">
                        <td class="whitespace-nowrap px-4 py-3 font-mono text-xs">{{ $slot->code }}</td>

                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="font-medium">{{ $slot->starts_at->format('n/j') }}</span>
                            <span class="text-gray-500 dark:text-gray-400">({{ $slot->starts_at->isoFormat('ddd') }})</span>
                            <span class="tabular-nums">{{ $slot->starts_at->format('H:i') }}</span>
                            <span class="text-gray-400">–</span>
                            <span class="tabular-nums text-gray-500 dark:text-gray-400">{{ $slot->ends_at->format('H:i') }}</span>
                        </td>

                        <td class="px-4 py-3 font-medium">{{ $slot->title }}</td>

                        <td class="whitespace-nowrap px-4 py-3 text-gray-600 dark:text-gray-400">
                            {{ $slot->instructor?->name ?? '—' }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-center">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $slot->lesson_type->badgeClass() }}">
                                {{ $slot->lesson_type->label() }}
                            </span>
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ $slot->capacity }}</td>

                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                            {{-- 予約数は保持せず都度算出（定員 − 席を占める予約数 = 残枠） --}}
                            <span @class(['font-semibold text-rose-600 dark:text-rose-400' => $slot->isFull()])>
                                {{ $slot->reservedCount() }}
                            </span>
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-center text-xs">
                            @if ($slot->online_url === null)
                                <span class="text-gray-400">未設定</span>
                            @else
                                <span class="text-primary-text">設定済</span>
                            @endif
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-center">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $slot->status->badgeClass() }}">
                                {{ $slot->status->label() }}
                            </span>
                        </td>
                    </x-table.row>
                @endforeach
            </x-data-table>

            {{-- 行クリックで開く詳細・編集モーダル（枠は論理削除せず「中止」で扱うので削除ダイアログは置かない） --}}
            <x-master-detail-modal :initial-detail="$initialDetail"
                                   resource-label="レッスン枠"
                                   detail-view="lessons.slots._detail"
                                   :deletable="false" />
        </div>
    </div>
</x-app-layout>
