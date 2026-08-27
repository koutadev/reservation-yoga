<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Support\Ui\DateRange;
use App\Support\Ui\DateRangePreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 日付範囲ピッカー(1-E)の検証。
 *
 * 相対プリセットが「固定日付に焼き付かず、常に基準日から計算される」ことを中心に見る。
 */
class DateRangeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: string}
     */
    private function range(DateRangePreset $preset, string $asOf): array
    {
        [$from, $to] = $preset->range(Carbon::parse($asOf));

        return [$from->toDateString(), $to->toDateString()];
    }

    #[Test]
    public function the_relative_presets_are_calculated_from_the_given_day(): void
    {
        // 2026-08-24 は月曜日
        $this->assertSame(['2026-08-24', '2026-08-24'], $this->range(DateRangePreset::Today, '2026-08-24'));
        $this->assertSame(['2026-08-24', '2026-08-30'], $this->range(DateRangePreset::ThisWeek, '2026-08-24'));
        $this->assertSame(['2026-08-01', '2026-08-31'], $this->range(DateRangePreset::ThisMonth, '2026-08-24'));
        $this->assertSame(['2026-07-01', '2026-09-30'], $this->range(DateRangePreset::ThisQuarter, '2026-08-24'));
        $this->assertSame(['2026-04-01', '2027-03-31'], $this->range(DateRangePreset::ThisFiscalYear, '2026-08-24'));

        // 「過去 N 日」は今日を含む N 日間
        $this->assertSame(['2026-08-18', '2026-08-24'], $this->range(DateRangePreset::Last7Days, '2026-08-24'));
        $this->assertSame(['2026-07-26', '2026-08-24'], $this->range(DateRangePreset::Last30Days, '2026-08-24'));
        $this->assertSame(['2026-05-27', '2026-08-24'], $this->range(DateRangePreset::Last90Days, '2026-08-24'));
    }

    #[Test]
    public function the_fiscal_year_follows_the_configured_start_month(): void
    {
        // 4 月始まり(既定)。年度の前半は前の年に属する
        $this->assertSame(['2025-04-01', '2026-03-31'], $this->range(DateRangePreset::ThisFiscalYear, '2026-02-10'));

        // 暦年(1 月始まり)にもできる
        config(['ui.fiscal_year_start_month' => 1]);
        $this->assertSame(['2026-01-01', '2026-12-31'], $this->range(DateRangePreset::ThisFiscalYear, '2026-08-24'));
    }

    #[Test]
    public function the_week_start_can_be_switched_to_sunday(): void
    {
        config(['ui.week_starts_on' => 0]);

        $this->assertSame(['2026-08-23', '2026-08-29'], $this->range(DateRangePreset::ThisWeek, '2026-08-24'));
    }

    #[Test]
    public function a_preset_is_recalculated_every_time_and_not_frozen(): void
    {
        $request = Request::create('/deals', 'GET', ['closed_preset' => 'this_month']);

        $this->travelTo(Carbon::parse('2026-08-24'));
        $august = DateRange::fromRequest($request, 'closed');

        $this->travelTo(Carbon::parse('2026-09-02'));
        $september = DateRange::fromRequest($request, 'closed');

        $this->assertSame('2026-08-01', $august->from?->toDateString());
        $this->assertSame('2026-09-01', $september->from?->toDateString(), '月が替われば「今月」も変わる。');
        $this->assertSame(DateRangePreset::ThisMonth, $september->preset);

        // URL に載せるのはキーだけ(日付を焼き付けない)
        $this->assertSame(['closed_preset' => 'this_month'], $september->toQuery('closed'));
    }

    #[Test]
    public function a_custom_range_is_taken_as_is(): void
    {
        $range = DateRange::fromRequest(
            Request::create('/deals', 'GET', [
                'closed_preset' => 'custom',
                'closed_from' => '2026-04-01',
                'closed_to' => '2026-06-30',
            ]),
            'closed',
        );

        $this->assertSame(DateRangePreset::Custom, $range->preset);
        $this->assertSame('2026-04-01', $range->from?->toDateString());
        $this->assertSame('2026-06-30', $range->to?->toDateString());
        $this->assertSame('2026/04/01 〜 2026/06/30', $range->label());
    }

    #[Test]
    public function a_reversed_or_partial_range_is_handled(): void
    {
        $reversed = DateRange::fromRequest(
            Request::create('/deals', 'GET', ['closed_from' => '2026-06-30', 'closed_to' => '2026-04-01']),
            'closed',
        );

        $this->assertSame('2026-04-01', $reversed->from?->toDateString(), '逆に入れても入れ替える。');
        $this->assertSame('2026-06-30', $reversed->to?->toDateString());

        $openEnded = DateRange::fromRequest(
            Request::create('/deals', 'GET', ['closed_from' => '2026-04-01']),
            'closed',
        );

        $this->assertSame('2026/04/01 以降', $openEnded->label());

        $garbage = DateRange::fromRequest(
            Request::create('/deals', 'GET', ['closed_from' => 'not-a-date']),
            'closed',
        );

        $this->assertTrue($garbage->isEmpty(), '解釈できない値は全期間として扱う。');
        $this->assertSame('指定なし', $garbage->label());
        $this->assertSame([], $garbage->toQuery('closed'));
    }

    #[Test]
    public function an_empty_range_means_all_periods(): void
    {
        $range = DateRange::fromRequest(Request::create('/deals'), 'closed');

        $this->assertSame(DateRangePreset::None, $range->preset);
        $this->assertTrue($range->isEmpty());
    }

    #[Test]
    public function the_range_can_filter_a_query(): void
    {
        Employee::factory()->create(['name' => '範囲内'])->forceFill(['created_at' => '2026-08-10'])->saveQuietly();
        Employee::factory()->create(['name' => '範囲外'])->forceFill(['created_at' => '2026-07-31'])->saveQuietly();

        $range = DateRange::fromRequest(
            Request::create('/employees', 'GET', ['closed_preset' => 'this_month']),
            'closed',
            Carbon::parse('2026-08-24'),
        );

        $names = $range->apply(Employee::query(), 'created_at')->pluck('name')->all();

        $this->assertSame(['範囲内'], $names);
    }

    #[Test]
    public function the_component_renders_its_hidden_fields_and_presets(): void
    {
        $this->withViewErrors([]);
        $this->travelTo(Carbon::parse('2026-08-24'));

        $html = Blade::render(
            '<x-date-range name="closed" label="期間" basis-label="予定クローズ日" basis="expected_close_date" preset="this_month" />'
        );

        // 送信される 3 つ + 基準日
        $this->assertStringContainsString('name="closed_preset"', $html);
        $this->assertStringContainsString('name="closed_from"', $html);
        $this->assertStringContainsString('name="closed_to"', $html);
        $this->assertStringContainsString('name="closed_basis" value="expected_close_date"', $html);

        // 基準日ラベルと相対プリセット
        $this->assertStringContainsString('予定クローズ日', $html);
        $this->assertStringContainsString('今四半期', $html);
        $this->assertStringContainsString('今年度', $html);
        $this->assertStringContainsString('2026-08-01', $html, 'いまの「今月」が初期値として入る。');

        // aria
        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
    }

    #[Test]
    public function the_catalog_resolves_the_submitted_range_on_the_server(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24'));

        $this->get(route('ui.catalog'))
            ->assertOk()
            ->assertSee('日付範囲ピッカー')
            ->assertSee('解決結果');

        $this->get(route('ui.catalog', ['demo_range_preset' => 'this_month']))
            ->assertOk()
            ->assertSee('2026/08/01 〜 2026/08/31');

        $this->get(route('ui.catalog', [
            'demo_range_preset' => 'custom',
            'demo_range_from' => '2026-04-01',
            'demo_range_to' => '2026-06-30',
        ]))
            ->assertOk()
            ->assertSee('2026/04/01 〜 2026/06/30');
    }
}
