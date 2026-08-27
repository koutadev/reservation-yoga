<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\User;
use App\Support\DataTable\Column;
use App\Support\DataTable\Filter;
use App\Support\DataTable\Table;
use App\Support\DataTable\TableDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 一覧テーブル部品(1-F)の検証。
 *
 * 単体の描画と、既存の共通一覧基盤(x-data-table)に載せた状態の両方を見る。
 */
class TableComponentTest extends TestCase
{
    use RefreshDatabase;

    private function columns(): string
    {
        return "[
            ['key' => 'code', 'label' => '社員コード', 'sortable' => true, 'wrap' => false, 'width' => 'w-32'],
            ['key' => 'name', 'label' => '氏名', 'sortable' => true],
            ['key' => 'amount', 'label' => '金額', 'align' => 'right'],
        ]";
    }

    #[Test]
    public function the_header_shows_the_sort_state(): void
    {
        $html = Blade::render(
            '<x-table :columns="$columns" sort="name" direction="desc" :sort-url="$url" actions>行</x-table>',
            [
                'columns' => eval('return '.$this->columns().';'),
                'url' => fn ($column): string => '/employees?sort='.$column->key,
            ],
        );

        // 並び替えできる列はリンクになり、現在の並びは aria-sort と記号で示す
        $this->assertStringContainsString('href="/employees?sort=code"', $html);
        $this->assertStringContainsString('aria-sort="descending"', $html);
        $this->assertStringContainsString('aria-sort="none"', $html);
        $this->assertStringContainsString('▼', $html);

        // 見出し・整列・幅・操作列
        $this->assertStringContainsString('社員コード', $html);
        $this->assertStringContainsString('w-32', $html);
        $this->assertStringContainsString('text-right', $html);
        $this->assertStringContainsString('操作', $html);
    }

    #[Test]
    public function a_filter_can_be_shown_as_a_combobox(): void
    {
        $this->withViewErrors([]);

        Employee::factory()->create(['name' => 'アオイ 太郎']);

        $definition = new class extends TableDefinition
        {
            public function key(): string
            {
                return 'combo-examples';
            }

            public function routeName(): string
            {
                return 'masters.employees';
            }

            public function query(): Builder
            {
                return Employee::query();
            }

            public function columns(): array
            {
                return [new Column('name', '氏名')];
            }

            public function searchable(): array
            {
                return ['name'];
            }

            public function toCsvRow(Model $model): array
            {
                assert($model instanceof Employee);

                return [$model->name];
            }

            public function filters(): array
            {
                return [
                    // 候補が少ないものは今までどおりセレクト
                    new Filter('employment_status', '在籍状況', ['active' => '在籍']),
                    // 候補が多いものはコンボボックス(ここでは非同期モード)
                    new Filter(
                        name: 'department_id',
                        label: '部署',
                        options: [],
                        source: '/_ui/options',
                        labelResolver: static fn (string $value): ?string => $value === '7' ? '第一営業部' : null,
                    ),
                ];
            }
        };

        $request = Request::create('/examples', 'GET', ['department_id' => '7']);
        $request->setLaravelSession($this->app['session.store']);

        $table = Table::make($definition, $request, false);
        $html = Blade::render('<x-data-table :table="$table" />', ['table' => $table]);

        // セレクトとコンボボックスが同じ絞り込み欄に並ぶ
        $this->assertStringContainsString('<select id="dt-employment_status"', $html);
        $this->assertStringNotContainsString('<select id="dt-department_id"', $html);
        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('data-source="/_ui/options"', $html);

        // 非同期モードでも、選択中の値の名前が出る
        $this->assertStringContainsString('第一営業部', $html);
    }

    #[Test]
    public function a_column_without_a_sort_url_is_plain_text(): void
    {
        $html = Blade::render(
            '<x-table :columns="$columns">行</x-table>',
            ['columns' => eval('return '.$this->columns().';')],
        );

        $this->assertStringNotContainsString('<a href', $html);
        $this->assertStringContainsString('氏名', $html);
    }

    #[Test]
    public function the_empty_state_is_shown_instead_of_the_rows(): void
    {
        $html = Blade::render(
            '<x-table :columns="$columns" is-empty empty="条件に一致するデータがありません。">中身</x-table>',
            ['columns' => eval('return '.$this->columns().';')],
        );

        $this->assertStringContainsString('条件に一致するデータがありません。', $html);
        $this->assertStringNotContainsString('中身', $html);
        $this->assertStringContainsString('colspan="3"', $html);
    }

    #[Test]
    public function the_loading_state_shows_skeleton_rows(): void
    {
        $html = Blade::render(
            '<x-table :columns="$columns" loading :loading-rows="3">中身</x-table>',
            ['columns' => eval('return '.$this->columns().';')],
        );

        $this->assertSame(9, substr_count($html, 'animate-pulse'), '3 行 × 3 列ぶんの骨組み。');
        $this->assertStringContainsString('motion-reduce:animate-none', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringNotContainsString('中身', $html);
    }

    #[Test]
    public function a_row_can_link_or_open_a_modal(): void
    {
        $link = Blade::render('<x-table.row href="/masters/employees/1"><td>行</td></x-table.row>');
        $this->assertStringContainsString('role="link"', $link);
        $this->assertStringContainsString('tabindex="0"', $link);
        $this->assertStringContainsString('/masters/employees/1', $link);
        // セル内のボタンを押したときは反応しない
        $this->assertStringContainsString('closest(', $link);

        $modal = Blade::render('<x-table.row modal="employee-detail"><td>行</td></x-table.row>');
        $this->assertStringContainsString('open-modal', $modal);
        $this->assertStringContainsString('employee-detail', $modal);

        $plain = Blade::render('<x-table.row><td>行</td></x-table.row>');
        $this->assertStringNotContainsString('role="link"', $plain);
        $this->assertStringNotContainsString('cursor-pointer', $plain);
    }

    #[Test]
    public function a_deleted_row_is_dimmed(): void
    {
        $html = Blade::render('<x-table.row :muted="true"><td>行</td></x-table.row>');

        $this->assertStringContainsString('opacity-60', $html);
    }

    #[Test]
    public function a_cell_follows_its_alignment_options(): void
    {
        $this->assertStringContainsString('text-right tabular-nums', Blade::render('<x-table.cell align="right">1</x-table.cell>'));
        $this->assertStringContainsString('whitespace-nowrap', Blade::render('<x-table.cell :wrap="false">1</x-table.cell>'));
        $this->assertStringContainsString('font-mono', Blade::render('<x-table.cell mono>EMP-0001</x-table.cell>'));
        $this->assertStringContainsString('text-gray-500', Blade::render('<x-table.cell muted>—</x-table.cell>'));
    }

    #[Test]
    public function the_existing_master_list_runs_on_the_new_table(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Admin->value);

        // ページャも出るように 1 ページ(20 件)を超える件数にする
        Employee::factory()->count(25)->create();

        $response = $this->actingAs($user)->get(route('masters.employees.index'))->assertOk();

        // 共通一覧基盤(検索・ソート・ページング)がそのまま新しいテーブルに載っている
        $response->assertSee('aria-sort=', false)
            ->assertSee('odd:bg-white', false)
            ->assertSee('aria-label="ページ送り"', false)
            ->assertSee('CSV出力');
    }

    #[Test]
    public function the_empty_message_reflects_the_search_conditions(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Admin->value);

        $this->actingAs($user)
            ->get(route('masters.employees.index', ['reset' => 1]))
            ->assertOk()
            ->assertSee('データが登録されていません。');

        $this->actingAs($user)
            ->get(route('masters.employees.index', ['q' => '存在しない社員']))
            ->assertOk()
            ->assertSee('条件に一致するデータがありません。');
    }

    #[Test]
    public function the_catalog_shows_the_table_examples(): void
    {
        $response = $this->get(route('ui.catalog'))->assertOk();

        $response->assertSee('テーブル')
            ->assertSee('aria-sort=', false)
            ->assertSee('animate-pulse', false)
            ->assertSee('条件に一致するデータがありません。')
            ->assertSee('role="link"', false);

        // 見出しクリックで並び替わる
        $this->get(route('ui.catalog', ['sort' => 'amount', 'direction' => 'desc']))
            ->assertOk()
            ->assertSeeInOrder(['2,475,000', '1,320,000', '880,000', '396,000']);
    }
}
