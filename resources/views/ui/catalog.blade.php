@php
    use App\Support\Ui\Size;
    use App\Support\Ui\Tone;
    use App\Support\Ui\Variant;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>UI コンポーネントカタログ — {{ \App\Support\Theme\Theme::name() }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @include('partials.theme')
    </head>

    <body class="bg-gray-100 font-sans antialiased dark:bg-gray-900">
        {{-- ページ全体を Alpine のスコープにする（$dispatch を使うボタンのため） --}}
        <div x-data class="mx-auto max-w-5xl space-y-10 px-4 py-10 sm:px-6">

            <header class="space-y-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-primary-text">Design System</p>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">UI コンポーネントカタログ</h1>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    共通基盤の再利用部品を一覧で確認するページ（開発・デモ用）。
                    配色は <code class="rounded bg-gray-200 px-1 text-xs dark:bg-gray-700">.env</code> の
                    <code class="rounded bg-gray-200 px-1 text-xs dark:bg-gray-700">THEME_PRIMARY</code> に連動します（現在:
                    <span class="inline-flex items-center gap-1">
                        <span class="inline-block h-3 w-3 rounded-full bg-primary align-middle"></span>
                        <code class="text-xs">{{ config('theme.colors.primary') }}</code>
                    </span>）。
                </p>
            </header>

            {{-- ボタン --}}
            <x-card title="ボタン" subtitle="variant × size / 無効 / ローディング / アイコン / リンク">
                <div class="space-y-6">
                    @foreach (Variant::cases() as $variant)
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="w-24 shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $variant->value }}</span>

                            @foreach (Size::cases() as $size)
                                <x-button :variant="$variant" :size="$size" type="button">{{ $size->label() }}</x-button>
                            @endforeach

                            <x-button :variant="$variant" type="button" disabled>無効</x-button>
                            <x-button :variant="$variant" type="button" loading>保存中</x-button>
                        </div>
                    @endforeach

                    <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4 dark:border-gray-700">
                        <span class="w-24 shrink-0 text-xs text-gray-500 dark:text-gray-400">応用</span>
                        <x-button icon="employees" type="button">アイコン付き</x-button>
                        <x-button variant="secondary" href="#catalog-form">リンクとして</x-button>
                        <x-button variant="ghost" size="sm" icon="close" type="button">閉じる</x-button>
                    </div>
                </div>
            </x-card>

            {{-- フォーム部品 --}}
            <x-card title="フォーム部品" subtitle="ラベル・必須マーク・ヘルプ・エラー表示" id="catalog-form">
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <x-form.text name="catalog_name" label="氏名" required
                                 value="山田 太郎" help="姓と名の間は空白で区切ります。" />

                    <x-form.text name="catalog_email" label="メールアドレス" type="email"
                                 placeholder="you@example.com" />

                    <x-form.number name="catalog_amount" label="金額（税込）" :value="11000"
                                   min="0" help="円単位の整数で入力します。" />

                    <x-form.date name="catalog_date" label="予定日" :value="now()->toDateString()"
                                 help="共通のカレンダー部品（x-datepicker）を使っています。" />

                    <x-form.select name="catalog_status" label="ステータス" required
                                   :options="['open' => '進行中', 'won' => '受注', 'lost' => '失注']"
                                   selected="open" placeholder="選択してください" />

                    <x-form.text name="catalog_disabled" label="無効な入力欄" value="編集できません" disabled />

                    <x-form.radio name="catalog_plan" label="プラン"
                                  :options="['standard' => 'スタンダード', 'premium' => 'プレミアム']"
                                  selected="standard" />

                    <x-form.segment name="catalog_basis" label="基準日"
                                    :options="['expected_close_date' => '予定クローズ日', 'ordered_at' => '受注日']"
                                    selected="expected_close_date"
                                    help="2〜4 個程度の切り替えに使います。" />

                    <x-form.checkbox name="catalog_active" label="有効" :checked="true"
                                     help="無効にすると選択肢に出なくなります。" />

                    <div class="sm:col-span-2">
                        <x-form.textarea name="catalog_note" label="メモ" rows="3"
                                         placeholder="打ち合わせの内容などを記録します" />
                    </div>

                    {{-- エラー表示の例 --}}
                    <div class="sm:col-span-2">
                        <x-form.text name="catalog_error" label="エラーがある入力欄" required
                                     :messages="['この項目は必須です。', '100 文字以内で入力してください。']" />
                    </div>
                </div>
            </x-card>

            {{-- カレンダー --}}
            <x-card title="カレンダー（日付選択）" subtitle="ブラウザ標準の date input ではなく共通部品">
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">基本</span>
                        <x-datepicker name="catalog_picker" :value="now()->toDateString()" class="mt-1" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            今日は枠線、選択日は塗りつぶし。土曜は青、日曜は赤。
                        </p>
                    </div>

                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">選択できる範囲を制限</span>
                        <x-datepicker name="catalog_picker_limited"
                                      :min="now()->startOfMonth()->toDateString()"
                                      :max="now()->endOfMonth()->toDateString()" class="mt-1" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            今月以外は選べません（min / max）。
                        </p>
                    </div>

                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">未選択の状態</span>
                        <x-datepicker name="catalog_picker_empty" class="mt-1" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            直接入力もできます（2026/08/24・2026-08-24・20260824）。
                        </p>
                    </div>

                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">無効</span>
                        <x-datepicker name="catalog_picker_disabled" :value="now()->toDateString()" disabled class="mt-1" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            祝日のハイライトは HolidayProvider を差し替えると有効になります（既定は祝日なし）。
                        </p>
                    </div>
                </div>

                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    キーボード：↑↓←→ で日を移動、PageUp / PageDown で月送り、Enter で決定、Esc で閉じる。
                </p>
            </x-card>

            {{-- 達成率ゲージ --}}
            <x-card title="達成率ゲージ" subtitle="目標に対する実績。未達 / 達成間近 / 達成 を色で示す">
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
                    <x-gauge label="当月（未達）" :actual="4200000" :target="10000000" unit="円" />
                    <x-gauge label="当月（達成間近）" :actual="8800000" :target="10000000" unit="円" />
                    <x-gauge label="当月（達成）" :actual="12500000" :target="10000000" unit="円" />
                </div>

                <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-3">
                    <x-gauge label="目標未設定" :actual="3200000" :target="0" unit="円" />
                    <x-gauge label="件数でも使える" :actual="18" :target="20" unit="件" size="sm" />
                    <x-gauge label="注記つき" :actual="9500000" :target="10000000" unit="円" size="lg"
                             note="受注日ベース" />
                </div>

                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    100% を超えても棒は振り切れません（数値では超過ぶんも分かります）。
                    読み上げには「目標 … に対して実績 …、達成率 …」を渡しています。
                </p>
            </x-card>

            {{-- 構成比バー --}}
            <x-card title="構成比バー" subtitle="内訳を横棒で見せる（一覧のサマリなど）">
                <x-stacked-bar unit="円"
                               :segments="[
                                   ['label' => '見込み', 'value' => 3200000, 'class' => 'bg-gray-400'],
                                   ['label' => '提案中', 'value' => 5400000, 'class' => 'bg-sky-500'],
                                   ['label' => '見積提示', 'value' => 2800000, 'class' => 'bg-amber-500'],
                                   ['label' => '受注', 'value' => 7600000, 'class' => 'bg-emerald-500'],
                                   ['label' => '失注', 'value' => 900000, 'class' => 'bg-rose-500'],
                               ]" />

                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    値が 0 の区分は棒に出ません。読み上げ用に全体の内訳を aria-label に入れています。
                </p>
            </x-card>

            {{-- コンボボックス --}}
            <x-card title="コンボボックス" subtitle="入力で候補を絞る。静的モードと非同期モードの両対応">
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <x-form.combobox name="catalog_customer" label="顧客（静的モード）"
                                     :options="$customers" selected="3"
                                     help="「あおい」と入力すると「アオイ商事」に当たります（ひらがな/カタカナ・全角半角・大文字小文字を無視）。" />

                    <x-form.combobox name="catalog_prefecture" label="取引先（非同期モード）"
                                     :source="route('ui.catalog.options')"
                                     placeholder="「あおい」などと入力"
                                     help="サーバ側でも同じ正規化を使うので、かな入力でも漢字の候補に当たります（250ms のデバウンスつき）。" />

                    {{-- 連動する候補(顧客を選ぶと先方担当が変わる) --}}
                    <div class="sm:col-span-2"
                         x-data="{
                             owner: '',
                             contacts: {
                                 1: [{ id: '11', name: '青井 一郎（営業部）' }, { id: '12', name: '青井 二郎（購買部）' }],
                                 2: [{ id: '21', name: '色羽 三郎（総務部）' }],
                                 3: [{ id: '31', name: '上野 四郎（情報システム部）' }, { id: '32', name: '上野 五郎（経理部）' }],
                             },
                             contactsFor() { return this.contacts[this.owner] ?? []; },
                         }">
                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <x-form.combobox name="catalog_owner" label="顧客（連動元）"
                                             :options="$customers" model-expression="owner"
                                             on-select="$dispatch('toast', { message: `${$event.detail.label || '未選択'} を選びました`, tone: 'info' })" />

                            <x-form.combobox name="catalog_owner_contact" label="先方担当（顧客に連動）"
                                             options-expression="contactsFor()"
                                             help="上の顧客を選ぶと候補が入れ替わります（1〜3 社目に担当者を用意してあります）。" />
                        </div>
                    </div>

                    <x-form.combobox name="catalog_combo_disabled" label="無効"
                                     :options="$customers" selected="1" disabled />

                    <x-form.combobox name="catalog_combo_error" label="エラー" :options="$customers" required
                                     :messages="['顧客を選択してください。']" />
                </div>
            </x-card>

            {{-- テーブル --}}
            <x-card title="テーブル" subtitle="ソート / 行クリック / 空状態 / ローディング">
                <div class="space-y-6">
                    <div>
                        <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                            見出しをクリックすると並び替わります（このページ自身に <code>?sort=…</code> を付けて再読み込み）。
                            行をクリックするとモーダルが開きます（セル内のボタンを押したときは反応しません）。
                        </p>

                        <x-table :columns="$tableColumns"
                                 :sort="$tableSort"
                                 :direction="$tableDirection"
                                 :sort-url="$tableSortUrl"
                                 actions>
                            @foreach ($tableRows as $row)
                                <x-table.row modal="demo-detail">
                                    <x-table.cell mono :wrap="false">{{ $row['code'] }}</x-table.cell>
                                    <x-table.cell strong>{{ $row['name'] }}</x-table.cell>
                                    <x-table.cell muted>{{ $row['department'] }}</x-table.cell>
                                    <x-table.cell align="right" :wrap="false">{{ number_format($row['amount']) }}</x-table.cell>
                                    <x-table.cell align="center">
                                        <x-badge :tone="$row['tone']">{{ $row['status'] }}</x-badge>
                                    </x-table.cell>
                                    <x-table.cell align="right" :wrap="false">
                                        <x-button size="sm" variant="ghost" type="button"
                                                  x-on:click="$dispatch('toast', { type: 'info', message: '{{ $row['name'] }} を編集（デモ）' })">
                                            編集
                                        </x-button>
                                    </x-table.cell>
                                </x-table.row>
                            @endforeach
                        </x-table>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">空状態</p>
                            <x-table :columns="$tableColumns" is-empty empty="条件に一致するデータがありません。" />
                        </div>

                        <div>
                            <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">ローディング</p>
                            <x-table :columns="$tableColumns" loading :loading-rows="4" />
                        </div>
                    </div>

                    <div>
                        <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                            行全体をリンクにする例（<code>href</code> を渡す）
                        </p>

                        <x-table :columns="[['label' => '遷移先'], ['label' => '説明']]">
                            <x-table.row href="{{ route('ui.catalog') }}#catalog-form">
                                <x-table.cell strong>フォーム部品へ</x-table.cell>
                                <x-table.cell muted>この行のどこを押してもページ内リンクへ移動します。</x-table.cell>
                            </x-table.row>
                        </x-table>
                    </div>
                </div>
            </x-card>

            {{-- 日付範囲ピッカー --}}
            <x-card title="日付範囲ピッカー" subtitle="相対プリセット + カスタム期間 + 指定なし">
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <x-date-range name="catalog_closed" label="期間（相対プリセット）"
                                  basis-label="予定クローズ日" preset="this_month"
                                  help="「今月」などのキーだけを送るので、月が替わっても指定し直す必要がありません。" />

                    <x-date-range name="catalog_custom" label="期間（カスタム指定）"
                                  basis-label="受注日" from="2026-04-01" to="2026-06-30"
                                  help="開始日・終了日を直接指定した状態。" />

                    <x-date-range name="catalog_none" label="期間（指定なし）"
                                  help="何も選んでいない状態。全期間が対象になります。" />

                    <x-date-range name="catalog_basis" label="基準日を一緒に送る例"
                                  basis-label="受注日" basis="ordered_at" preset="this_fiscal_year"
                                  help="basis を渡すと catalog_basis_basis として送られ、基準日の切替 UI と連携できます。" />
                </div>

                {{-- サーバ側での解決を確かめる小さなフォーム --}}
                <form method="GET" action="{{ route('ui.catalog') }}"
                      class="mt-6 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4 dark:border-gray-700">
                    <div class="w-72">
                        <x-date-range name="demo_range" label="送信して解決結果を見る" basis-label="予定クローズ日" />
                    </div>

                    <x-button type="submit" size="sm" variant="secondary">この期間で絞り込む</x-button>

                    <p class="text-xs text-gray-600 dark:text-gray-400">
                        解決結果：
                        <span class="font-medium text-gray-800 dark:text-gray-200">{{ $demoRange->preset->label() }}</span>
                        ／ <span class="tabular-nums">{{ $demoRange->label() }}</span>
                    </p>
                </form>
            </x-card>

            {{-- モーダル --}}
            <x-card title="モーダル" subtitle="詳細表示 / 編集フォーム / 確認ダイアログ"
                    x-data
                    @confirmed.window="$store.toast.push({ type: 'success', message: '実行しました（デモ）。' })">
                <div class="flex flex-wrap gap-3">
                    <x-button type="button" variant="secondary"
                              x-on:click="$dispatch('open-modal', 'demo-detail')">詳細を開く</x-button>

                    <x-button type="button" variant="secondary"
                              x-on:click="$dispatch('open-modal', 'demo-edit')">編集フォームを開く</x-button>

                    <x-button type="button" variant="danger"
                              x-on:click="$dispatch('open-modal', 'demo-confirm')">削除（確認ダイアログ）</x-button>
                </div>

                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    Esc とオーバーレイのクリックで閉じます（確認ダイアログは誤操作防止のため閉じません）。
                    開いているあいだは Tab がモーダル内を循環し、閉じると元のボタンにフォーカスが戻ります。
                </p>

                {{-- (1) 詳細表示 --}}
                <x-modal name="demo-detail" title="社員の詳細" size="md">
                    <dl class="grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2">
                        @foreach ([
                            '社員コード' => 'EMP-0007',
                            '氏名' => '山田 花子',
                            '部署' => '営業部',
                            '役職' => '課長',
                            'メールアドレス' => 'yamada@example.com',
                            '在籍状態' => '在籍',
                        ] as $label => $value)
                            <div>
                                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="mt-0.5 text-sm text-gray-900 dark:text-gray-100">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <x-slot name="footer">
                        <x-button type="button" variant="secondary" x-on:click="$dispatch('close')">閉じる</x-button>
                        <x-button type="button">編集</x-button>
                    </x-slot>
                </x-modal>

                {{-- (2) 編集フォーム(送信してエラーなら開いたまま戻る) --}}
                <x-modal name="demo-edit" title="社員の編集" size="md">
                    <form method="POST" action="{{ route('ui.catalog.demo-form') }}" id="demo-edit-form" class="space-y-4">
                        @csrf
                        <x-modal-marker name="demo-edit" />

                        <x-form.text name="demo_title" label="件名" required
                                     help="空のまま、または 21 文字以上で送るとエラーになります（モーダルは開いたまま）。" />

                        <x-form.select name="demo_department" label="部署"
                                       :options="['sales' => '営業部', 'dev' => 'システム開発部']" selected="sales" />
                    </form>

                    <x-slot name="footer">
                        <x-button type="button" variant="secondary" x-on:click="$dispatch('close')">キャンセル</x-button>
                        <x-button type="submit" form="demo-edit-form">保存</x-button>
                    </x-slot>
                </x-modal>

                {{-- (3) 確認ダイアログ --}}
                <x-confirm-dialog name="demo-confirm" title="この社員を削除しますか？" confirm="削除する">
                    論理削除のため、データは残ります（管理者は復元できます）。
                </x-confirm-dialog>
            </x-card>

            {{-- バッジ --}}
            <x-card title="バッジ / ステータスチップ" subtitle="意味（tone）で指定する">
                <div class="flex flex-wrap items-center gap-3">
                    @foreach (Tone::cases() as $tone)
                        <x-badge :tone="$tone">{{ $tone->label() }}</x-badge>
                    @endforeach
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4 dark:border-gray-700">
                    @foreach (Tone::cases() as $tone)
                        <x-badge :tone="$tone" dot>{{ $tone->label() }}</x-badge>
                    @endforeach
                </div>
            </x-card>

            {{-- トースト --}}
            <x-card title="トースト通知" subtitle="種別ごとに表示され、数秒で自動的に消える（画面右下）">
                <div class="flex flex-wrap gap-3">
                    <x-button type="button" variant="secondary"
                              x-on:click="$dispatch('toast', { type: 'success', message: '保存しました。' })">
                        成功
                    </x-button>

                    <x-button type="button" variant="secondary"
                              x-on:click="$dispatch('toast', { type: 'danger', message: '保存に失敗しました。' })">
                        エラー
                    </x-button>

                    <x-button type="button" variant="secondary"
                              x-on:click="$dispatch('toast', { type: 'info', message: 'CSV の作成を開始しました。' })">
                        情報
                    </x-button>

                    <x-button type="button" variant="secondary"
                              x-on:click="$dispatch('toast', { type: 'warning', message: '在庫が残りわずかです。' })">
                        注意
                    </x-button>

                    <x-button type="button" variant="ghost"
                              x-on:click="['success', 'danger', 'info'].forEach((type, index) => $dispatch('toast', { type, message: `まとめて表示 ${index + 1} 件目` }))">
                        3 件まとめて出す
                    </x-button>

                    <x-button type="button" variant="ghost" onclick="window.toast('Alpine を使わずに出したトースト', 'info')">
                        素の JS から出す
                    </x-button>
                </div>

                <div class="mt-4 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                    <p>サーバ側（リダイレクト後に表示）：<code>return back()-&gt;with('toast', Toast::success('保存しました'));</code></p>
                    <p>画面側（Alpine）：<code>$dispatch('toast', { type: 'success', message: '…' })</code></p>
                    <p>「編集フォームを開く」→ 正しい値で保存すると、リダイレクト後に成功トーストが出ます。</p>
                </div>
            </x-card>

            {{-- タブ --}}
            <x-card title="タブ" subtitle="左右キーでも切り替えられる">
                <x-tabs :tabs="['overview' => '概要', 'detail' => '明細', 'history' => '活動履歴']">
                    <x-tab-panel name="overview">概要タブの中身。</x-tab-panel>
                    <x-tab-panel name="detail">明細タブの中身。</x-tab-panel>
                    <x-tab-panel name="history">活動履歴タブの中身。</x-tab-panel>
                </x-tabs>
            </x-card>

            {{-- KPI カード --}}
            <x-card title="KPI カード" subtitle="href を渡すとカード全体がリンクになる">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-kpi-card label="今月の受注（税込）" :value="2334700" unit="円" note="2026年8月に受注した商談" />
                    <x-kpi-card label="進行中の商談" :value="15" unit="件" note="受注・失注を除く" href="#catalog-form" />
                    <x-kpi-card label="受注残（税込）" :value="2925900" unit="円" />
                    <x-kpi-card label="達成率" value="86.4%" note="目標 2,700,000 円に対して" />
                </div>
            </x-card>

            {{-- ページネーション --}}
            <x-card title="ページネーション" subtitle="$items->links() もこの見た目になる">
                <x-pagination :paginator="$paginator" />
            </x-card>

            {{-- カード --}}
            <x-card title="カード" subtitle="見出し・本文・アクション・フッター">
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <x-card title="社員マスタ" subtitle="全 32 名">
                        <x-slot name="actions">
                            <x-button size="sm" type="button">追加</x-button>
                        </x-slot>

                        本文をここに書きます。表や説明文をそのまま入れられます。

                        <x-slot name="footer">最終更新 2026/08/24 10:00</x-slot>
                    </x-card>

                    <x-card>
                        見出しなしのカード。区切りだけ欲しいときに使います。
                    </x-card>
                </div>
            </x-card>

            <footer class="pb-10 text-xs text-gray-500 dark:text-gray-400">
                このページは開発・デモ用です（本番環境では表示されません）。今後追加する部品もここに並べていきます。
            </footer>
        </div>

        <x-toast-container />
    </body>
</html>
