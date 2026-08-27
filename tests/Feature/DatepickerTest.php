<?php

namespace Tests\Feature;

use App\Support\Ui\Contracts\HolidayProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * カレンダー部品(x-datepicker)の検証。
 */
class DatepickerTest extends TestCase
{
    #[Test]
    public function it_renders_a_calendar_grid_instead_of_a_native_date_input(): void
    {
        $html = Blade::render('<x-datepicker name="closed_on" value="2026-08-24" />');

        // 送信されるのは hidden。見えている入力欄は表示・直接入力用
        $this->assertStringContainsString('<input type="hidden" name="closed_on"', $html);
        $this->assertStringNotContainsString('type="date"', $html);

        // カレンダーの骨組みと aria
        $this->assertStringContainsString('x-data="datepicker(', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('role="grid"', $html);
        $this->assertStringContainsString('role="gridcell"', $html);
        $this->assertStringContainsString('aria-label="カレンダーを開く"', $html);
        $this->assertStringContainsString('aria-label="前の月"', $html);

        // 初期値
        $this->assertStringContainsString('2026-08-24', $html);

        // 他の Alpine 部品から x-model で使える
        $this->assertStringContainsString('x-modelable="value"', $html);
    }

    #[Test]
    public function the_selectable_range_can_be_limited(): void
    {
        $html = Blade::render('<x-datepicker name="closed_on" min="2026-08-01" max="2026-08-31" />');

        $this->assertStringContainsString('min\u0022:\u00222026-08-01', $html);
        $this->assertStringContainsString('max\u0022:\u00222026-08-31', $html);
    }

    #[Test]
    public function special_days_come_from_the_holiday_provider(): void
    {
        // 既定では祝日なし
        $this->assertStringContainsString('holidays\u0022:[]', Blade::render('<x-datepicker name="a" value="2026-08-24" />'));

        // 差し替えるとカレンダーに渡る
        $this->app->bind(HolidayProvider::class, fn (): HolidayProvider => new class implements HolidayProvider
        {
            public function between(CarbonInterface $from, CarbonInterface $to): array
            {
                return ['2026-08-11' => '山の日'];
            }
        });

        $html = Blade::render('<x-datepicker name="a" value="2026-08-24" />');

        $this->assertStringContainsString('2026-08-11', $html);
        $this->assertStringContainsString('山の日', $html);
    }

    #[Test]
    public function the_date_form_field_uses_the_shared_calendar(): void
    {
        $this->withViewErrors([]);

        $html = Blade::render('<x-form.date name="closed_on" label="予定日" value="2026-08-24" required />');

        $this->assertStringContainsString('予定日', $html);
        $this->assertStringContainsString('必須', $html);
        $this->assertStringContainsString('x-data="datepicker(', $html);
        $this->assertStringNotContainsString('type="date"', $html);
    }

    #[Test]
    public function the_date_range_picker_uses_the_shared_calendar_for_custom_dates(): void
    {
        $this->withViewErrors([]);

        $html = Blade::render('<x-date-range name="closed" label="期間" />');

        // カスタム指定の開始日・終了日が共通のカレンダーになっている
        $this->assertSame(2, substr_count($html, 'x-data="datepicker('));
        $this->assertStringContainsString('x-model="from"', $html);
        $this->assertStringContainsString('x-model="to"', $html);
        $this->assertStringNotContainsString('type="date"', $html);
    }

    #[Test]
    public function the_catalog_shows_the_calendar_examples(): void
    {
        $this->get(route('ui.catalog'))
            ->assertOk()
            ->assertSee('カレンダー（日付選択）')
            ->assertSee('role="gridcell"', false)
            ->assertSee('今月以外は選べません（min / max）。');
    }
}
