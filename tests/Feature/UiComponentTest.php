<?php

namespace Tests\Feature;

use App\Support\Ui\Toast;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 共通 UI 部品(1-B)の検証。
 *
 * 単体は Blade::render で、組み合わせはカタログページで確認する。
 */
class UiComponentTest extends TestCase
{
    #[Test]
    public function a_button_renders_its_variant_and_size(): void
    {
        $html = Blade::render('<x-button variant="danger" size="lg" type="button">削除</x-button>');

        $this->assertStringContainsString('削除', $html);
        $this->assertStringContainsString('bg-rose-600', $html);
        $this->assertStringContainsString('px-5', $html);
        $this->assertStringContainsString('type="button"', $html);
    }

    #[Test]
    public function a_button_can_be_disabled_or_loading(): void
    {
        $disabled = Blade::render('<x-button disabled>保存</x-button>');
        $this->assertStringContainsString('disabled', $disabled);
        $this->assertStringContainsString('pointer-events-none', $disabled);

        $loading = Blade::render('<x-button loading>保存</x-button>');
        $this->assertStringContainsString('animate-spin', $loading);
        $this->assertStringContainsString('aria-busy="true"', $loading);
        $this->assertStringContainsString('disabled', $loading, 'ローディング中は押せない。');
    }

    #[Test]
    public function a_button_with_href_renders_a_link(): void
    {
        $html = Blade::render('<x-button href="/dashboard" variant="secondary">一覧へ</x-button>');

        $this->assertStringContainsString('<a href="/dashboard"', $html);

        // 無効なリンクはフォーカスも当たらない
        $disabled = Blade::render('<x-button href="/dashboard" disabled>一覧へ</x-button>');
        $this->assertStringContainsString('aria-disabled="true"', $disabled);
        $this->assertStringContainsString('tabindex="-1"', $disabled);
    }

    #[Test]
    public function the_theme_color_is_used_by_the_primary_button(): void
    {
        $html = Blade::render('<x-button variant="primary">保存</x-button>');

        // 色は Tailwind のテーマトークン経由(= .env の THEME_PRIMARY に連動)
        $this->assertStringContainsString('bg-primary', $html);
        $this->assertStringNotContainsString('bg-indigo', $html);
    }

    #[Test]
    public function an_unknown_variant_falls_back_to_the_default(): void
    {
        $html = Blade::render('<x-button variant="unknown">保存</x-button>');

        $this->assertStringContainsString('bg-primary', $html, '未知の値でも壊れず既定に落ちる。');
    }

    #[Test]
    public function a_text_field_renders_label_help_and_errors(): void
    {
        $this->withViewErrors(['title' => ['件名は必須です。']]);

        $html = Blade::render('<x-form.text name="title" label="件名" required help="30 文字まで" />');

        $this->assertStringContainsString('件名', $html);
        $this->assertStringContainsString('必須', $html);
        $this->assertStringContainsString('30 文字まで', $html);
        $this->assertStringContainsString('件名は必須です。', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('border-rose-400', $html);
    }

    #[Test]
    public function form_controls_render_their_values(): void
    {
        $this->withViewErrors([]);

        $number = Blade::render('<x-form.number name="amount" label="金額" :value="11000" min="0" />');
        $this->assertStringContainsString('value="11000"', $number);
        $this->assertStringContainsString('type="number"', $number);

        // 日付は共通のカレンダー部品(x-datepicker)を使う
        $date = Blade::render('<x-form.date name="closed_on" label="予定日" value="2026-08-24" />');
        $this->assertStringContainsString('x-data="datepicker(', $date);
        $this->assertStringContainsString('2026-08-24', $date);

        $select = Blade::render(
            '<x-form.select name="status" label="状態" :options="$options" selected="won" placeholder="選択" />',
            ['options' => ['open' => '進行中', 'won' => '受注']],
        );
        $this->assertStringContainsString('<option value="won" selected>受注</option>', $select);
        $this->assertStringContainsString('選択', $select);

        $checkbox = Blade::render('<x-form.checkbox name="is_active" label="有効" :checked="true" />');
        $this->assertStringContainsString('type="checkbox"', $checkbox);
        $this->assertStringContainsString('checked', $checkbox);

        $radio = Blade::render(
            '<x-form.radio name="plan" label="プラン" :options="$options" selected="b" />',
            ['options' => ['a' => 'A プラン', 'b' => 'B プラン']],
        );
        $this->assertStringContainsString('type="radio"', $radio);
        $this->assertMatchesRegularExpression('/value="b"[^>]*checked/s', $radio, '選択中のラジオに checked が付く。');
        $this->assertStringContainsString('<legend', $radio);
    }

    #[Test]
    public function a_segment_marks_the_selected_choice(): void
    {
        $this->withViewErrors([]);

        $html = Blade::render(
            '<x-form.segment name="basis" label="基準日" :options="$options" selected="ordered_at" />',
            ['options' => ['expected_close_date' => '予定クローズ日', 'ordered_at' => '受注日']],
        );

        // 見た目はボタン、中身はラジオ(キーボードでも選べる)
        $this->assertStringContainsString('role="radiogroup"', $html);
        $this->assertStringContainsString('type="radio"', $html);
        $this->assertMatchesRegularExpression('/value="ordered_at"[^>]*checked/s', $html);
        $this->assertMatchesRegularExpression('/for="basis-ordered_at"[^>]*bg-primary/s', $html, '選択中だけ塗りつぶす。');
        $this->assertStringContainsString('予定クローズ日', $html);
    }

    #[Test]
    public function a_gauge_shows_the_achievement_rate_with_its_state(): void
    {
        // 未達(80% 未満)
        $behind = Blade::render('<x-gauge label="当月" :actual="4200000" :target="10000000" unit="円" />');
        $this->assertStringContainsString('42%', $behind);
        $this->assertStringContainsString('未達', $behind);
        $this->assertStringContainsString('bg-rose-600', $behind);
        $this->assertStringContainsString('width: 42%', $behind);
        $this->assertStringContainsString('残り', $behind);

        // 達成間近(80% 以上)
        $near = Blade::render('<x-gauge label="当月" :actual="8800000" :target="10000000" unit="円" />');
        $this->assertStringContainsString('達成間近', $near);
        $this->assertStringContainsString('bg-amber-600', $near);

        // 達成(100% 以上。棒は振り切れない)
        $done = Blade::render('<x-gauge label="当月" :actual="12500000" :target="10000000" unit="円" />');
        $this->assertStringContainsString('125%', $done);
        $this->assertStringContainsString('bg-emerald-600', $done);
        $this->assertStringContainsString('width: 100%', $done);

        // 目標が無いときは達成率を出さない
        $none = Blade::render('<x-gauge label="当月" :actual="3200000" :target="0" unit="円" />');
        $this->assertStringContainsString('目標未設定', $none);
        $this->assertStringNotContainsString('%</p>', $none);

        // 読み上げ
        $this->assertStringContainsString('role="progressbar"', $behind);
        $this->assertStringContainsString('aria-valuenow="42"', $behind);
        $this->assertStringContainsString('目標 10,000,000円 に対して実績 4,200,000円、達成率 42%（未達）', $behind);
    }

    #[Test]
    public function a_stacked_bar_shows_the_share_of_each_segment(): void
    {
        $html = Blade::render(
            '<x-stacked-bar unit="円" :segments="$segments" />',
            ['segments' => [
                ['label' => '受注', 'value' => 750, 'class' => 'bg-emerald-500'],
                ['label' => '失注', 'value' => 250, 'class' => 'bg-rose-500'],
                ['label' => '見込み', 'value' => 0, 'class' => 'bg-gray-400'],
            ]],
        );

        // 構成比が幅とラベルの両方に出る
        $this->assertStringContainsString('width: 75%', $html);
        $this->assertStringContainsString('width: 25%', $html);
        $this->assertStringContainsString('75%', $html);
        $this->assertStringContainsString('bg-emerald-500', $html);

        // 0 の区分は棒に出ないが、凡例には残る
        $this->assertSame(2, substr_count($html, 'style="width:'));
        $this->assertStringContainsString('見込み', $html);

        // 読み上げ用の説明
        $this->assertStringContainsString('aria-label="受注 750円、失注 250円、見込み 0円"', $html);
    }

    #[Test]
    public function an_empty_stacked_bar_says_so(): void
    {
        $html = Blade::render(
            '<x-stacked-bar :segments="$segments" empty="対象の商談がありません" />',
            ['segments' => [['label' => '受注', 'value' => 0, 'class' => 'bg-emerald-500']]],
        );

        $this->assertStringContainsString('対象の商談がありません', $html);
        $this->assertStringNotContainsString('style="width:', $html);
    }

    #[Test]
    public function a_kpi_card_keeps_long_numbers_inside_the_card(): void
    {
        $html = Blade::render('<x-kpi-card label="売上" :value="1084635562" unit="円" note="今月" />');

        // カード幅に合わせて縮み、入りきらなければ省略する(全桁はホバーで見せる)
        $this->assertStringContainsString('@container', $html);
        $this->assertStringContainsString('clamp(1.125rem,9cqi,1.875rem)', $html);
        $this->assertStringContainsString('truncate', $html);
        $this->assertStringContainsString('title="1,084,635,562 円"', $html);

        // 単位は数字と同じ行に留める
        $this->assertStringContainsString('shrink-0 whitespace-nowrap', $html);
        $this->assertStringContainsString('1,084,635,562', $html);
    }

    #[Test]
    public function a_badge_uses_the_tone_colors(): void
    {
        $this->assertStringContainsString('bg-emerald-100', Blade::render('<x-badge tone="success">受注</x-badge>'));
        $this->assertStringContainsString('bg-rose-100', Blade::render('<x-badge tone="danger">失注</x-badge>'));
        $this->assertStringContainsString('bg-primary-soft', Blade::render('<x-badge tone="primary">強調</x-badge>'));

        $dot = Blade::render('<x-badge tone="warning" dot>注意</x-badge>');
        $this->assertStringContainsString('bg-amber-500', $dot);
    }

    #[Test]
    public function a_kpi_card_becomes_a_link_when_a_href_is_given(): void
    {
        $plain = Blade::render('<x-kpi-card label="進行中の商談" :value="15" unit="件" note="受注・失注を除く" />');
        $this->assertStringContainsString('15', $plain);
        $this->assertStringContainsString('件', $plain);
        $this->assertStringContainsString('受注・失注を除く', $plain);
        $this->assertStringNotContainsString('<a ', $plain);

        $linked = Blade::render('<x-kpi-card label="今月の受注" :value="2334700" href="/deals" />');
        $this->assertStringContainsString('<a', $linked);
        $this->assertStringContainsString('href="/deals"', $linked);
        $this->assertStringContainsString('2,334,700', $linked, '整数は 3 桁区切りで表示する。');
    }

    #[Test]
    public function a_card_renders_its_slots(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-card title="社員" subtitle="全 32 名">
                <x-slot name="actions">操作</x-slot>
                本文
                <x-slot name="footer">補足</x-slot>
            </x-card>
        BLADE);

        foreach (['社員', '全 32 名', '操作', '本文', '補足'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    #[Test]
    public function tabs_render_buttons_and_panels(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-tabs :tabs="['overview' => '概要', 'detail' => '明細']">
                <x-tab-panel name="overview">概要の中身</x-tab-panel>
                <x-tab-panel name="detail">明細の中身</x-tab-panel>
            </x-tabs>
        BLADE);

        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('role="tab"', $html);
        $this->assertStringContainsString('role="tabpanel"', $html);
        $this->assertStringContainsString('概要の中身', $html);
        $this->assertStringContainsString('overview', $html);
    }

    #[Test]
    public function a_combobox_renders_the_selected_option_and_aria_attributes(): void
    {
        $this->withViewErrors([]);

        $html = Blade::render(
            '<x-form.combobox name="partner_id" label="顧客" :options="$options" selected="2" help="入力で絞り込み" />',
            ['options' => [1 => 'アオイ商事', 2 => 'イロハ物産']],
        );

        // 送信されるのは hidden の値、見えているのは検索用の入力欄
        $this->assertStringContainsString('<input type="hidden" name="partner_id"', $html);
        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('role="option"', $html);
        $this->assertStringContainsString('aria-autocomplete="list"', $html);
        $this->assertStringContainsString('aria-controls="partner_id-listbox"', $html);

        // ラベル・ヘルプ・選択中の値
        $this->assertStringContainsString('顧客', $html);
        $this->assertStringContainsString('入力で絞り込み', $html);
        $this->assertStringContainsString('イロハ物産', $html);
    }

    #[Test]
    public function a_combobox_switches_between_static_and_async_modes(): void
    {
        $this->withViewErrors([]);

        $static = Blade::render(
            '<x-form.combobox name="partner_id" :options="$options" />',
            ['options' => [1 => 'アオイ商事']],
        );
        $this->assertStringContainsString('アオイ商事', $static, '静的モードは候補を埋め込む。');
        $this->assertStringContainsString('source\u0022:null', $static);

        $async = Blade::render('<x-form.combobox name="partner_id" source="/_ui/options" />');
        $this->assertStringContainsString('data-source="/_ui/options"', $async, '非同期モードは問い合わせ先を持つ。');
        $this->assertStringContainsString('options\u0022:[]', $async);
    }

    #[Test]
    public function a_combobox_can_be_bound_to_the_surrounding_alpine_state(): void
    {
        $this->withViewErrors([]);

        $html = Blade::render(
            '<x-form.combobox name="product" :options="$options" unique-id'
            .' name-expression="`items[${index}][product_id]`"'
            .' model-expression="row.product_id"'
            .' options-expression="productsFor(row)"'
            .' on-select="applyProduct(index)" />',
            ['options' => [1 => 'ノートPC']],
        );

        // 繰り返し行でも使えるよう、送信名・id は実行時に決める
        $this->assertStringContainsString('<input type="hidden" :name="`items[${index}][product_id]`"', $html);
        $this->assertStringContainsString("x-id=\"['product']\"", $html);
        // Blade がクォートをエスケープするので、実際の出力は &#039; になる
        $this->assertStringContainsString(':id="$id(&#039;product&#039;)"', $html);

        // 外側の状態との連携(双方向の値・候補の差し替え・選択時の処理)
        $this->assertStringContainsString('x-modelable="value"', $html);
        $this->assertStringContainsString('x-model="row.product_id"', $html);
        $this->assertStringContainsString('x-effect="setOptions(productsFor(row))"', $html);
        $this->assertStringContainsString('x-on:combobox-selected="applyProduct(index)"', $html);
    }

    #[Test]
    public function an_async_combobox_shows_the_label_of_the_selected_value(): void
    {
        $this->withViewErrors([]);

        // 非同期モードは候補を持たないので、選択中の名前は呼び出し側から渡す
        $html = Blade::render(
            '<x-form.combobox name="partner_id" source="/_ui/options" selected="7" selected-label="キタムラ運輸株式会社" />'
        );

        $this->assertStringContainsString('data-source="/_ui/options"', $html);
        $this->assertStringContainsString('value\u0022:\u00227\u0022', $html);
        $this->assertStringContainsString('キタムラ運輸株式会社', $html);
    }

    #[Test]
    public function a_combobox_can_be_searched_with_hiragana_or_katakana(): void
    {
        $this->withViewErrors([]);

        $html = Blade::render(
            '<x-form.combobox name="partner_id" :options="$options" />',
            ['options' => [1 => 'アオイ商事', 2 => 'ｲﾛﾊ物産']],
        );

        // 候補には正規化済みの検索キーを持たせておき、入力側は JS が同じ規則で正規化する
        $this->assertStringContainsString('search\u0022:\u0022あおい商事', $html);
        $this->assertStringContainsString('search\u0022:\u0022いろは物産', $html);
    }

    #[Test]
    public function the_async_endpoint_matches_kana_input(): void
    {
        foreach (['あおい', 'アオイ', 'ｱｵｲ'] as $query) {
            $items = $this->getJson(route('ui.catalog.options', ['q' => $query]))->assertOk()->json();

            $this->assertSame('アオイ商事', $items[0]['label'] ?? null, "「{$query}」で見つかること");
        }
    }

    #[Test]
    public function the_toast_buttons_live_inside_an_alpine_scope(): void
    {
        // Alpine のスコープが無いと $dispatch のボタンが動かないため、
        // カタログのページ全体に x-data を置いている
        $this->get(route('ui.catalog'))
            ->assertOk()
            ->assertSee('<div x-data class="mx-auto', false)
            ->assertSee('window.toast(', false);

        $html = $this->get(route('ui.catalog'))->getContent();

        foreach (['success', 'danger', 'info', 'warning'] as $type) {
            $this->assertStringContainsString("type: '".$type."'", $html, $type.' のトーストを出すボタンがある');
        }
    }

    #[Test]
    public function a_combobox_shows_its_disabled_and_error_states(): void
    {
        $this->withViewErrors([]);

        $disabled = Blade::render('<x-form.combobox name="partner_id" :options="[]" disabled />');
        $this->assertStringContainsString('disabled', $disabled);

        $errored = Blade::render(
            '<x-form.combobox name="partner_id" :messages="[\'顧客を選択してください。\']" />'
        );
        $this->assertStringContainsString('顧客を選択してください。', $errored);
        $this->assertStringContainsString('aria-invalid="true"', $errored);
        $this->assertStringContainsString('border-rose-400', $errored);
    }

    #[Test]
    public function a_textarea_renders_its_value(): void
    {
        $this->withViewErrors([]);

        $html = Blade::render('<x-form.textarea name="note" label="メモ" rows="4" value="打ち合わせの記録" />');

        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringContainsString('rows="4"', $html);
        $this->assertStringContainsString('打ち合わせの記録', $html);
        $this->assertStringContainsString('メモ', $html);
    }

    #[Test]
    public function the_async_option_endpoint_filters_by_the_query(): void
    {
        $all = $this->getJson(route('ui.catalog.options'))->assertOk()->json();
        $this->assertNotEmpty($all);
        $this->assertArrayHasKey('value', $all[0]);
        $this->assertArrayHasKey('label', $all[0]);

        $filtered = $this->getJson(route('ui.catalog.options', ['q' => 'アオイ']))->assertOk()->json();

        $this->assertNotEmpty($filtered);

        foreach ($filtered as $item) {
            $this->assertStringContainsString('アオイ', $item['label']);
        }
    }

    #[Test]
    public function a_modal_renders_its_header_body_and_footer(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-modal name="employee-detail" title="社員の詳細" size="lg">
                本文
                <x-slot name="footer">操作</x-slot>
            </x-modal>
        BLADE);

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="employee-detail-title"', $html);
        $this->assertStringContainsString('社員の詳細', $html);
        $this->assertStringContainsString('本文', $html);
        $this->assertStringContainsString('操作', $html);
        $this->assertStringContainsString('sm:max-w-4xl', $html, 'size=lg は幅が広い。');

        // フォーカストラップと開閉のイベント
        $this->assertStringContainsString('trap($event)', $html);
        $this->assertStringContainsString('open-modal.window', $html);
        $this->assertStringContainsString('keydown.escape.window', $html);
    }

    #[Test]
    public function a_modal_can_refuse_to_close_on_escape_or_overlay(): void
    {
        $closable = Blade::render('<x-modal name="a" title="開閉できる">本文</x-modal>');
        $this->assertStringContainsString('closable\u0022:true', $closable);
        $this->assertStringContainsString('aria-label="閉じる"', $closable);

        $locked = Blade::render('<x-modal name="b" title="閉じない" :closable="false">本文</x-modal>');
        $this->assertStringContainsString('closable\u0022:false', $locked);
        $this->assertStringNotContainsString('aria-label="閉じる"', $locked, '閉じるボタンも出さない。');
    }

    #[Test]
    public function a_modal_is_closed_by_default_but_can_be_shown(): void
    {
        $closed = Blade::render('<x-modal name="edit-employee" title="編集">本文</x-modal>');
        $this->assertStringContainsString('show\u0022:false', $closed);
        $this->assertStringContainsString('style="display: none;"', $closed);

        $shown = Blade::render('<x-modal name="edit-employee" title="編集" :show="true">本文</x-modal>');
        $this->assertStringContainsString('show\u0022:true', $shown);
        $this->assertStringContainsString('style="display: block;"', $shown);
    }

    // 「送信 → エラー → 開いたまま戻る」は
    // the_edit_modal_stays_open_when_validation_fails で実際の往復を確認している。

    #[Test]
    public function the_modal_marker_writes_the_hidden_field(): void
    {
        $html = Blade::render('<x-modal-marker name="edit-employee" />');

        $this->assertStringContainsString('<input type="hidden" name="_modal" value="edit-employee">', $html);
    }

    #[Test]
    public function a_confirm_dialog_renders_a_form_with_the_given_method(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-confirm-dialog name="delete-employee" title="削除しますか？"
                              action="/masters/employees/1" method="DELETE" confirm="削除する">
                論理削除のためデータは残ります。
            </x-confirm-dialog>
        BLADE);

        $this->assertStringContainsString('削除しますか？', $html);
        $this->assertStringContainsString('論理削除のためデータは残ります。', $html);
        $this->assertStringContainsString('action="/masters/employees/1"', $html);
        $this->assertStringContainsString('name="_method" value="DELETE"', $html);
        $this->assertStringContainsString('削除する', $html);
        $this->assertStringContainsString('キャンセル', $html);

        // 誤操作防止のため Esc / オーバーレイでは閉じない
        $this->assertStringContainsString('closable\u0022:false', $html);
    }

    #[Test]
    public function the_edit_modal_stays_open_when_validation_fails(): void
    {
        $this->from(route('ui.catalog'))
            ->post(route('ui.catalog.demo-form'), ['_modal' => 'demo-edit', 'demo_title' => ''])
            ->assertRedirect(route('ui.catalog'))
            ->assertSessionHasErrors('demo_title');

        // 戻り先ではモーダルが開いた状態で、エラーが出ている
        $this->followingRedirects()
            ->from(route('ui.catalog'))
            ->post(route('ui.catalog.demo-form'), ['_modal' => 'demo-edit', 'demo_title' => ''])
            ->assertOk()
            ->assertSee('show\u0022:true', false)
            ->assertSee('件名は必須です。');
    }

    #[Test]
    public function the_edit_modal_reports_success_with_a_toast(): void
    {
        $this->from(route('ui.catalog'))
            ->post(route('ui.catalog.demo-form'), ['_modal' => 'demo-edit', 'demo_title' => 'テスト件名'])
            ->assertRedirect(route('ui.catalog'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas(Toast::SESSION_KEY);
    }

    #[Test]
    public function a_flashed_toast_is_rendered(): void
    {
        session()->put(Toast::SESSION_KEY, Toast::success('保存しました。'));

        $html = Blade::render('<x-toast-container />');

        $this->assertStringContainsString('保存しました。', $html);
        $this->assertStringContainsString('$store.toast.push', $html);
    }

    #[Test]
    public function the_catalog_page_shows_every_component(): void
    {
        $response = $this->get(route('ui.catalog'))->assertOk();

        $response->assertSeeInOrder([
            'UI コンポーネントカタログ',
            'ボタン',
            'フォーム部品',
            'バッジ / ステータスチップ',
            'トースト通知',
            'タブ',
            'KPI カード',
            'ページネーション',
            'カード',
        ]);

        // モーダル(詳細 / 編集フォーム / 確認ダイアログ)
        $response->assertSee('モーダル')
            ->assertSee('role="dialog"', false)
            ->assertSee('社員の詳細')
            ->assertSee('name="_modal"', false)
            ->assertSee('この社員を削除しますか？');

        // コンボボックス(静的・非同期・無効・エラー)
        $response->assertSee('コンボボックス')
            ->assertSee('role="combobox"', false)
            ->assertSee('顧客を選択してください。')
            ->assertSee('data-source="'.route('ui.catalog.options').'"', false);

        // 状態の見本も出ている
        $response->assertSee('animate-spin', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('この項目は必須です。')
            // ページネーションは共通の見た目に差し替わっている
            ->assertSee('aria-label="ページ送り"', false)
            ->assertSee('全 137 件中');
    }
}
