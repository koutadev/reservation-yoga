@php
    /** @var \App\Models\LessonSlot $slot */
    $editing = $slot->exists;

    // 繰り返しの入力でエラーになったときは、繰り返しタブを開いた状態で戻す
    $initialMode = old('_mode') === 'recurring' ? 'recurring' : 'single';
@endphp

{{--
    レッスン枠の開講 / 編集。

    新規は「単発」と「繰り返し」を切り替えられる。
    一覧の行クリックからはモーダルで編集するので、この画面は
    新規開講と、URL を直接開いたときの編集に使う。
--}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
            レッスン枠 &mdash; {{ $editing ? '編集' : '開講' }}
        </h2>
    </x-slot>

    <x-slot name="breadcrumb">{{ $editing ? '編集' : '開講' }}</x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <x-flash />

            <div x-data="{ mode: @js($initialMode) }" class="space-y-4">
                @unless ($editing)
                    {{-- 単発 / 繰り返しの切り替え --}}
                    <div class="inline-flex rounded-md border border-gray-300 p-0.5 dark:border-gray-700" role="tablist">
                        @foreach (['single' => '単発', 'recurring' => '繰り返し'] as $value => $label)
                            <button type="button" role="tab"
                                    :aria-selected="mode === @js($value)"
                                    x-on:click="mode = @js($value)"
                                    :class="mode === @js($value)
                                        ? 'bg-primary text-white'
                                        : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                                    class="rounded px-4 py-1.5 text-xs font-medium transition-colors motion-reduce:transition-none">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                @endunless

                {{-- 単発 --}}
                <div x-show="mode === 'single'" x-cloak>
                    <form method="POST"
                          action="{{ $editing ? route('lesson-slots.update', $slot->id) : route('lesson-slots.store') }}"
                          class="space-y-6 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        @csrf
                        @if ($editing)
                            @method('PUT')
                        @endif

                        @if ($editing)
                            <div>
                                <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">コード</span>
                                <p class="mt-1 font-mono text-sm text-gray-900 dark:text-gray-100">{{ $slot->code }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">自動採番のため変更できません。</p>
                            </div>
                        @endif

                        @include('lessons.slots.fields')

                        <div class="flex items-center gap-3 border-t border-gray-100 pt-6 dark:border-gray-700">
                            <x-primary-button type="submit">保存</x-primary-button>

                            <a href="{{ route('lesson-slots.index') }}"
                               class="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">
                                キャンセル
                            </a>
                        </div>
                    </form>
                </div>

                {{-- 繰り返し（新規のみ） --}}
                @unless ($editing)
                    <div x-show="mode === 'recurring'" x-cloak>
                        <form method="POST" action="{{ route('lesson-slots.store-recurring') }}"
                              class="space-y-6 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                            @csrf
                            <input type="hidden" name="_mode" value="recurring">

                            @include('lessons.slots.recurring-fields')

                            <div class="flex items-center gap-3 border-t border-gray-100 pt-6 dark:border-gray-700">
                                <x-primary-button type="submit">まとめて開講する</x-primary-button>

                                <a href="{{ route('lesson-slots.index') }}"
                                   class="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">
                                    キャンセル
                                </a>
                            </div>
                        </form>
                    </div>
                @endunless
            </div>
        </div>
    </div>
</x-app-layout>
