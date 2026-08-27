<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Enums\RoleName;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Support\Ui\Toast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 一覧の行クリック → モーダル詳細 → 編集・削除(2-B)の検証。
 *
 * 仕組みは共通(MasterController + masters/_detail)なので、
 * 代表として社員と、サブマスタの部署で確かめる。
 */
class MasterDetailModalTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    #[Test]
    public function each_row_links_to_its_detail(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWithRole(RoleName::Staff))
            ->get(route('masters.employees.index'))
            ->assertOk()
            ->assertSee(route('masters.employees.detail', $employee->id), false)
            ->assertSee('open-detail', false)
            // 一覧に 1 つだけモーダルと確認ダイアログが置かれる
            ->assertSee('master-detail')
            ->assertSee('master-delete');
    }

    #[Test]
    public function the_detail_fragment_shows_the_record_and_the_edit_form(): void
    {
        $department = Department::factory()->create(['name' => '営業部']);
        $employee = Employee::factory()->create([
            'name' => '山田 花子',
            'department_id' => $department->id,
        ]);

        $response = $this->actingAs($this->userWithRole(RoleName::Staff))
            ->get(route('masters.employees.detail', $employee->id))
            ->assertOk();

        // 詳細の項目
        $response->assertSee('社員 — 山田 花子')
            ->assertSee('社員コード')
            ->assertSee($employee->code)
            ->assertSee('営業部')
            ->assertSee('在籍');

        // その場で編集・削除できる
        $response->assertSee('編集')
            ->assertSee('削除')
            ->assertSee(route('masters.employees.update', $employee->id), false)
            ->assertSee('name="_modal_record"', false)
            // 入力項目はフルページのフォームと共有している
            ->assertSee('name="employment_status"', false);
    }

    #[Test]
    public function a_viewer_only_sees_the_detail(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWithRole(RoleName::Viewer))
            ->get(route('masters.employees.detail', $employee->id))
            ->assertOk()
            ->assertSee($employee->code)
            ->assertDontSee('master-detail-form')
            ->assertDontSee('open-delete');
    }

    #[Test]
    public function saving_from_the_modal_reports_success_with_a_toast(): void
    {
        $employee = Employee::factory()->create(['name' => '変更前']);

        $this->actingAs($this->userWithRole(RoleName::Staff))
            ->from(route('masters.employees.index'))
            ->put(route('masters.employees.update', $employee->id), [
                '_modal' => 'master-detail',
                '_modal_record' => $employee->id,
                'name' => '変更後',
                'employment_status' => EmploymentStatus::Active->value,
                'is_active' => '1',
            ])
            ->assertRedirect(route('masters.employees.index'))
            ->assertSessionHas(Toast::SESSION_KEY);

        $this->assertSame('変更後', $employee->refresh()->name);
    }

    #[Test]
    public function a_validation_error_keeps_the_modal_open_with_the_record(): void
    {
        $employee = Employee::factory()->create(['name' => '変更前']);

        $this->actingAs($this->userWithRole(RoleName::Staff))
            ->from(route('masters.employees.index'))
            ->put(route('masters.employees.update', $employee->id), [
                '_modal' => 'master-detail',
                '_modal_record' => $employee->id,
                'name' => '',
                'employment_status' => EmploymentStatus::Active->value,
            ])
            ->assertRedirect(route('masters.employees.index'))
            ->assertSessionHasErrors('name');

        // 戻り先ではモーダルが開き、そのレコードの編集フォームがエラー付きで出る
        $this->actingAs($this->userWithRole(RoleName::Staff));

        $response = $this->followingRedirects()
            ->from(route('masters.employees.index'))
            ->put(route('masters.employees.update', $employee->id), [
                '_modal' => 'master-detail',
                '_modal_record' => $employee->id,
                'name' => '',
                'employment_status' => EmploymentStatus::Active->value,
            ])
            ->assertOk();

        $response->assertSee('show\u0022:true', false)
            ->assertSee('editing: true', false)
            ->assertSee($employee->code)
            ->assertSee('氏名は必須です。');

        $this->assertSame('変更前', $employee->refresh()->name, '保存はされていない。');
    }

    #[Test]
    public function deleting_from_the_modal_soft_deletes_and_reports_it(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWithRole(RoleName::Staff))
            ->delete(route('masters.employees.destroy', $employee->id))
            ->assertRedirect(route('masters.employees.index'))
            ->assertSessionHas(Toast::SESSION_KEY);

        $this->assertSoftDeleted($employee);
    }

    #[Test]
    public function an_administrator_can_restore_from_the_detail(): void
    {
        $employee = Employee::factory()->create();
        $employee->delete();

        $response = $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('masters.employees.detail', $employee->id))
            ->assertOk();

        // 削除済みは編集ではなく復元を出す
        $response->assertSee('削除済み')
            ->assertSee('復元する')
            ->assertSee(route('masters.employees.restore', $employee->id), false)
            ->assertDontSee('master-detail-form');
    }

    #[Test]
    public function the_same_mechanism_works_for_a_sub_master(): void
    {
        $department = Department::factory()->create(['name' => '開発部']);

        $this->actingAs($this->userWithRole(RoleName::Staff))
            ->get(route('masters.departments.index'))
            ->assertOk()
            ->assertSee(route('masters.departments.detail', $department->id), false);

        $this->actingAs($this->userWithRole(RoleName::Staff))
            ->get(route('masters.departments.detail', $department->id))
            ->assertOk()
            ->assertSee('部署 — 開発部')
            ->assertSee('部署コード')
            ->assertSee('部署名');
    }

    #[Test]
    public function the_list_keeps_its_search_sort_and_pagination(): void
    {
        Employee::factory()->count(25)->create();
        Employee::factory()->create(['name' => '検索対象 太郎']);

        $user = $this->userWithRole(RoleName::Admin);

        // 検索
        $this->actingAs($user)
            ->get(route('masters.employees.index', ['q' => '検索対象']))
            ->assertOk()
            ->assertSee('検索対象 太郎')
            ->assertSee('全 1 件');

        // 並び替えとページング
        $this->actingAs($user)
            ->get(route('masters.employees.index', ['reset' => 1, 'sort' => 'code', 'direction' => 'desc']))
            ->assertOk()
            ->assertSee('aria-sort="descending"', false)
            ->assertSee('aria-label="ページ送り"', false);
    }
}
