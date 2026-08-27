<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Support\DataTable\TableDefinition;
use App\Support\DataTable\TableState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 一覧の「セレクト以外の絞り込み」(期間フィルタなど)が、
 * 他の条件と同じように保持され、URL にも引き継がれることの検証。
 */
class TableStateExtrasTest extends TestCase
{
    private function definition(): TableDefinition
    {
        return new class extends TableDefinition
        {
            public function key(): string
            {
                return 'examples';
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
                return [];
            }

            public function searchable(): array
            {
                return ['name'];
            }

            public function toCsvRow(Model $model): array
            {
                return [];
            }

            public function statefulParameters(): array
            {
                return ['period_basis', 'period_preset', 'period_from', 'period_to'];
            }
        };
    }

    private function resolve(Request $request): TableState
    {
        return TableState::resolve($request, $this->definition(), false);
    }

    private function request(array $query): Request
    {
        $request = Request::create('/examples', 'GET', $query);
        $request->setLaravelSession($this->app['session.store']);

        return $request;
    }

    #[Test]
    public function extra_parameters_are_kept_and_carried_into_links(): void
    {
        $state = $this->resolve($this->request([
            'period_basis' => 'ordered_at',
            'period_preset' => 'this_month',
            'q' => '山田',
        ]));

        $this->assertSame('ordered_at', $state->extra('period_basis'));
        $this->assertSame('this_month', $state->extra('period_preset'));
        $this->assertSame('', $state->extra('period_from'));
        $this->assertTrue($state->hasConditions());

        // 並び替え・ページ送り・CSV に引き継ぐため toQuery に含まれる
        $this->assertSame('ordered_at', $state->toQuery()['period_basis']);
        $this->assertSame('this_month', $state->toQuery()['period_preset']);
    }

    #[Test]
    public function extra_parameters_survive_navigating_away_and_back(): void
    {
        $this->resolve($this->request(['period_preset' => 'last_30_days']));

        // 条件なしで開き直しても、前回の絞り込みが復元される
        $restored = $this->resolve($this->request([]));

        $this->assertSame('last_30_days', $restored->extra('period_preset'));
    }

    #[Test]
    public function extra_parameters_can_be_cleared(): void
    {
        $this->resolve($this->request(['period_preset' => 'this_month']));

        $cleared = $this->resolve($this->request(['period_preset' => '', 'q' => '山田']));

        $this->assertSame('', $cleared->extra('period_preset'));
        $this->assertArrayNotHasKey('period_preset', $cleared->toQuery());
    }

    #[Test]
    public function a_definition_without_extra_parameters_is_unaffected(): void
    {
        $definition = new class extends TableDefinition
        {
            public function key(): string
            {
                return 'plain';
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
                return [];
            }

            public function searchable(): array
            {
                return ['name'];
            }

            public function toCsvRow(Model $model): array
            {
                return [];
            }
        };

        $request = $this->request(['period_preset' => 'this_month']);
        $state = TableState::resolve($request, $definition, false);

        $this->assertSame([], $state->extras);
        $this->assertArrayNotHasKey('period_preset', $state->toQuery());
    }
}
