@php
    /** @var \App\Models\LessonSlot $slot */
    $updateUrl = route('lesson-slots.update', $slot->id);

    // 直前の送信がこの枠の編集だった場合は、編集フォームを開いた状態で戻す
    $openEditor = (string) old('_modal_record') === (string) $slot->id && $errors->any();
@endphp

{{--
    一覧の行クリックで開くモーダルの中身。

    詳細と編集フォームを 1 つに収め、Alpine で切り替える（共通マスタの masters/_detail と同じ作り）。
    レッスン枠は論理削除せず「中止」で扱うため、削除の導線は置かない。
--}}
<div x-data="{ editing: {{ $openEditor ? 'true' : 'false' }} }">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">
            レッスン枠 — {{ $slot->title }}
        </p>

        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $slot->status->badgeClass() }}">
            {{ $slot->status->label() }}
        </span>
    </div>

    {{-- 詳細 --}}
    <div x-show="! editing">
        <dl class="grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2">
            @foreach ($rows as $label => $value)
                <div>
                    <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="mt-0.5 text-sm text-gray-900 dark:text-gray-100">{{ $value ?: '—' }}</dd>
                </div>
            @endforeach
        </dl>

        @unless ($canManage)
            <p class="mt-4 rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
                この枠は担当の講師（または管理者）だけが編集できます。
            </p>
        @endunless

        <div class="mt-5 flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-700">
            <x-button type="button" variant="secondary" x-on:click="$dispatch('close')">閉じる</x-button>

            @if ($canManage)
                <x-button type="button" x-on:click="editing = true">編集</x-button>
            @endif
        </div>
    </div>

    {{-- 編集フォーム --}}
    @if ($canManage)
        <div x-show="editing" x-cloak>
            <form method="POST" action="{{ $updateUrl }}" id="lesson-slot-detail-form" class="space-y-5">
                @csrf
                @method('PUT')

                {{-- エラーで戻ってきたときに、このモーダル・この枠を開き直すための目印 --}}
                <x-modal-marker name="master-detail" />
                <input type="hidden" name="_modal_record" value="{{ $slot->id }}">

                @include('lessons.slots.fields')
            </form>

            <div class="mt-5 flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-700">
                <x-button type="button" variant="secondary" x-on:click="editing = false">詳細に戻る</x-button>
                <x-button type="submit" form="lesson-slot-detail-form">保存</x-button>
            </div>
        </div>
    @endif
</div>
