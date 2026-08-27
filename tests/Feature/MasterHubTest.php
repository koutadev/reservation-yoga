<?php

namespace Tests\Feature;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Support\Masters\MasterCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * マスタ管理ハブ(2-A)の検証。
 */
class MasterHubTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    #[Test]
    public function the_hub_lists_every_master_with_its_count(): void
    {
        Employee::factory()->count(3)->create();
        Partner::factory()->count(2)->create();
        Product::factory()->create();

        $response = $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('masters.index'))
            ->assertOk();

        // 6 つのマスタがカードで並ぶ
        $response->assertSeeInOrder(['社員', '取引先', '商品', '部署', '役職', '商品分類']);

        // 説明と入口
        $response->assertSee('自社の社員。')
            ->assertSee(route('masters.employees.index'))
            ->assertSee(route('masters.partners.index'))
            ->assertSee('開く');

        $counts = $response->viewData('counts');

        $this->assertSame(3, $counts['employees']);
        $this->assertSame(2, $counts['partners']);
        $this->assertSame(1, $counts['products']);
        $this->assertSame(0, $counts['departments']);
    }

    #[Test]
    public function deleted_records_are_not_counted(): void
    {
        Employee::factory()->count(2)->create();
        Employee::factory()->create()->delete();

        $counts = app(MasterCatalog::class)->counts();

        $this->assertSame(2, $counts['employees'], '論理削除された社員は数えない。');
    }

    #[Test]
    public function all_the_counts_are_fetched_in_one_query(): void
    {
        Department::factory()->count(2)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(MasterCatalog::class)->counts();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries, 'マスタごとにクエリを投げない。');
    }

    #[Test]
    public function the_manage_links_are_only_shown_to_users_who_can_edit(): void
    {
        // 担当者は登録もできる
        $this->actingAs($this->userWithRole(RoleName::Staff))
            ->get(route('masters.index'))
            ->assertOk()
            ->assertSee('新規登録')
            ->assertSee(route('masters.employees.create'));

        // 閲覧者は「開く」だけ
        $this->actingAs($this->userWithRole(RoleName::Viewer))
            ->get(route('masters.index'))
            ->assertOk()
            ->assertSee('開く')
            ->assertDontSee('新規登録');
    }

    #[Test]
    public function the_hub_needs_the_master_view_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::DashboardView->value);

        $this->actingAs($user)->get(route('masters.index'))->assertForbidden();
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get(route('masters.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_sidebar_leads_to_the_hub_instead_of_each_master(): void
    {
        // ダッシュボードは KPI からマスタへ直接リンクするので、
        // ナビだけを見るためにマスタと関係のない画面で確認する
        $response = $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('profile.edit'))
            ->assertOk();

        $response->assertSee('マスタ管理')
            ->assertSee(route('masters.index'))
            // 個々のマスタはハブから入る(ナビには出さない)
            ->assertDontSee(route('masters.employees.index'))
            ->assertDontSee(route('masters.positions.index'));
    }

    #[Test]
    public function a_master_screen_still_shows_its_place_in_the_breadcrumbs(): void
    {
        $response = $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('masters.employees.index'))
            ->assertOk();

        // ナビに出していなくても現在地とパンくずは効く。マスタはハブへのリンクになる
        $response->assertSeeInOrder(['ダッシュボード', 'マスタ', '社員'])
            ->assertSee(route('masters.index'));
    }
}
