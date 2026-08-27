<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\SavedView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 一覧の保存ビュー(マイビュー)の検証。
 *
 * よく使う絞り込みに名前を付けて保存し、プルダウンから呼び出す。
 * 呼び出しは ?view=<id> を付けるだけで、条件の解釈は通常のリクエストと同じ経路を通る。
 */
class SavedViewTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStaff(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Staff->value);

        $this->actingAs($user);

        return $user;
    }

    private function seedEmployees(): void
    {
        Employee::factory()->create(['name' => '在籍 太郎', 'employment_status' => EmploymentStatus::Active]);
        Employee::factory()->create(['name' => '退職 花子', 'employment_status' => EmploymentStatus::Retired]);
    }

    #[Test]
    public function a_view_can_be_saved_named_and_called(): void
    {
        $user = $this->actingAsStaff();
        $this->seedEmployees();

        $this->post(route('saved-views.store'), [
            'table_key' => 'employees',
            'name' => '在籍中だけ',
            'redirect_to' => '/masters/employees',
            'conditions' => ['employment_status' => EmploymentStatus::Active->value, 'sort' => 'name'],
        ])->assertRedirect();

        $view = SavedView::query()->firstOrFail();
        $this->assertSame($user->id, $view->user_id);
        $this->assertSame('在籍中だけ', $view->name);
        $this->assertSame(['employment_status' => 'active', 'sort' => 'name'], $view->conditions);

        // 呼び出すと条件が復元される
        $this->get(route('masters.employees.index', ['view' => $view->id]))
            ->assertOk()
            ->assertSee('在籍 太郎')
            ->assertDontSee('退職 花子')
            ->assertSee('在籍中だけ');
    }

    #[Test]
    public function the_applied_view_survives_sorting_and_paging(): void
    {
        $user = $this->actingAsStaff();
        $this->seedEmployees();

        $view = SavedView::factory()->for($user)->create([
            'table_key' => 'employees',
            'name' => '在籍中だけ',
            'conditions' => ['employment_status' => EmploymentStatus::Active->value],
        ]);

        $html = $this->get(route('masters.employees.index', ['view' => $view->id]))->assertOk()->getContent();

        // 並び替え・CSV のリンクにビューが残る
        $this->assertStringContainsString('view='.$view->id, $html);

        // 並び替えてもビューの条件は効いたまま
        $this->get(route('masters.employees.index', ['view' => $view->id, 'sort' => 'name', 'direction' => 'desc']))
            ->assertOk()
            ->assertSee('在籍 太郎')
            ->assertDontSee('退職 花子');
    }

    #[Test]
    public function the_view_is_remembered_until_the_conditions_change(): void
    {
        $user = $this->actingAsStaff();
        $this->seedEmployees();

        $view = SavedView::factory()->for($user)->create([
            'table_key' => 'employees',
            'name' => '在籍中だけ',
            'conditions' => ['employment_status' => EmploymentStatus::Active->value],
        ]);

        $this->get(route('masters.employees.index', ['view' => $view->id]))->assertOk();

        // 条件を指定せず開き直しても、前回のまま
        $this->get(route('masters.employees.index'))
            ->assertOk()
            ->assertDontSee('退職 花子');

        // 絞り込みを自分で変えると、ビューの選択は外れる
        $this->get(route('masters.employees.index', ['q' => '退職']))
            ->assertOk()
            ->assertSee('退職 花子');
    }

    #[Test]
    public function the_default_view_is_applied_on_the_first_visit(): void
    {
        $user = $this->actingAsStaff();
        $this->seedEmployees();

        SavedView::factory()->for($user)->create([
            'table_key' => 'employees',
            'name' => '在籍中だけ',
            'conditions' => ['employment_status' => EmploymentStatus::Active->value],
            'is_default' => true,
        ]);

        // 条件も前回の記憶もない状態では既定ビューで開く
        $this->get(route('masters.employees.index'))
            ->assertOk()
            ->assertSee('在籍 太郎')
            ->assertDontSee('退職 花子');

        // 「条件をクリア」すれば全件に戻り、その状態が覚えられる
        $this->get(route('masters.employees.index', ['reset' => 1]))
            ->assertOk()
            ->assertSee('退職 花子');

        $this->get(route('masters.employees.index'))
            ->assertOk()
            ->assertSee('退職 花子');
    }

    #[Test]
    public function only_one_view_can_be_the_default(): void
    {
        $user = $this->actingAsStaff();

        $old = SavedView::factory()->for($user)->create([
            'table_key' => 'employees',
            'name' => '古い既定',
            'is_default' => true,
        ]);

        $this->post(route('saved-views.store'), [
            'table_key' => 'employees',
            'name' => '新しい既定',
            'is_default' => '1',
            'conditions' => [],
        ])->assertRedirect();

        $this->assertFalse($old->fresh()?->is_default);
        $this->assertTrue(SavedView::query()->where('name', '新しい既定')->firstOrFail()->is_default);
    }

    #[Test]
    public function saving_with_the_same_name_overwrites_the_view(): void
    {
        $user = $this->actingAsStaff();

        SavedView::factory()->for($user)->create([
            'table_key' => 'employees',
            'name' => '在籍中だけ',
            'conditions' => ['q' => '古い条件'],
        ]);

        $this->post(route('saved-views.store'), [
            'table_key' => 'employees',
            'name' => '在籍中だけ',
            'conditions' => ['q' => '新しい条件'],
        ])->assertRedirect();

        $this->assertSame(1, SavedView::query()->count());
        $this->assertSame(['q' => '新しい条件'], SavedView::query()->firstOrFail()->conditions);
    }

    #[Test]
    public function a_view_can_be_deleted(): void
    {
        $user = $this->actingAsStaff();

        $view = SavedView::factory()->for($user)->create(['table_key' => 'employees']);

        $this->delete(route('saved-views.destroy', $view->id), ['redirect_to' => '/masters/employees'])
            ->assertRedirect();

        $this->assertSame(0, SavedView::query()->count());
    }

    #[Test]
    public function views_are_private_to_each_user(): void
    {
        $owner = User::factory()->create();
        $view = SavedView::factory()->for($owner)->create([
            'table_key' => 'employees',
            'name' => '他人のビュー',
            'conditions' => ['q' => '在籍'],
        ]);

        $this->actingAsStaff();
        $this->seedEmployees();

        // 一覧のプルダウンに出てこない
        $this->get(route('masters.employees.index', ['reset' => 1]))
            ->assertOk()
            ->assertDontSee('他人のビュー');

        // ID を直接指定しても適用されない(条件なしで開いたのと同じ)
        $this->get(route('masters.employees.index', ['view' => $view->id]))
            ->assertOk()
            ->assertSee('退職 花子');

        // 削除もできない
        $this->delete(route('saved-views.destroy', $view->id))->assertNotFound();
        $this->assertSame(1, SavedView::query()->count());
    }

    #[Test]
    public function the_saved_conditions_are_cleaned_up(): void
    {
        $this->actingAsStaff();

        $this->post(route('saved-views.store'), [
            'table_key' => 'employees',
            'name' => '掃除されるビュー',
            'conditions' => [
                'q' => '在籍',
                'page' => '5',                       // ページ番号は持たない
                'view' => '99',                      // ビュー自身も持たない
                'too_long' => str_repeat('あ', 200),  // 長すぎる値は捨てる
                'empty' => '',
            ],
        ])->assertRedirect();

        $this->assertSame(['q' => '在籍'], SavedView::query()->firstOrFail()->conditions);
    }

    #[Test]
    public function a_view_needs_a_name(): void
    {
        $this->actingAsStaff();

        $this->post(route('saved-views.store'), ['table_key' => 'employees', 'name' => ''])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function lists_without_saved_views_are_untouched(): void
    {
        $this->actingAsStaff();

        // 取引先一覧は保存ビューを使わない設定なので、ビュー欄は出ない
        $this->get(route('masters.partners.index', ['reset' => 1]))
            ->assertOk()
            ->assertDontSee('現在の条件を保存');

        $this->get(route('masters.employees.index', ['reset' => 1]))
            ->assertOk()
            ->assertSee('現在の条件を保存');
    }
}
