<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 組織マスタ(地域 > エリア > 店舗)の検証。
 *
 * 段の決まり(地域の親は無し / エリアの親は地域 / 店舗の親はエリア)を保存時に守ること、
 * 社員が店舗に紐付くこと、一覧・モーダルで階層が分かることを見る。
 */
class OrganizationMasterTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array{0: Organization, 1: Organization, 2: Organization}
     */
    private function tree(): array
    {
        $region = Organization::create(['name' => '東日本地域', 'type' => OrganizationType::Region, 'is_active' => true]);
        $area = Organization::create(['name' => '首都圏エリア', 'type' => OrganizationType::Area, 'parent_id' => $region->id, 'is_active' => true]);
        $store = Organization::create(['name' => '東京本店', 'type' => OrganizationType::Store, 'parent_id' => $area->id, 'is_active' => true]);

        return [$region, $area, $store];
    }

    private function organization(string $name, OrganizationType $type, ?Organization $parent = null, ?string $prefecture = null): Organization
    {
        return Organization::create([
            'name' => $name,
            'type' => $type,
            'parent_id' => $parent?->id,
            'prefecture' => $prefecture,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function organizations_are_numbered_and_keep_their_hierarchy(): void
    {
        [$region, $area, $store] = $this->tree();

        $this->assertSame('ORG-0001', $region->code);
        $this->assertSame('ORG-0003', $store->code);

        // 3 段の道筋をたどれる
        $store->refresh();

        $this->assertSame('東日本地域 > 首都圏エリア > 東京本店', $store->path());
        $this->assertSame([$area->id, $region->id], array_map(
            static fn (Organization $node): int => $node->id,
            $store->ancestors(),
        ));

        // 子をたどることもできる
        $this->assertSame([$area->id], $region->children->pluck('id')->all());
        $this->assertSame([$store->id], $area->children->pluck('id')->all());
    }

    #[Test]
    public function the_list_shows_the_hierarchy(): void
    {
        $this->actingAsRole(RoleName::Staff);
        [$region, , $store] = $this->tree();

        $this->get(route('masters.organizations.index', ['reset' => 1]))
            ->assertOk()
            ->assertSee('東日本地域')
            ->assertSee('東京本店')
            ->assertSee('地域')
            ->assertSee('店舗')
            // 上位組織は道筋で出す
            ->assertSee('東日本地域 &gt; 首都圏エリア', false);

        // 行クリックで開く詳細
        $this->get(route('masters.organizations.detail', $store->id))
            ->assertOk()
            ->assertSee('東日本地域 &gt; 首都圏エリア &gt; 東京本店', false)
            ->assertSee('所属社員');

        $this->assertSame(3, Organization::query()->count());
        $this->assertSame('東日本地域', $region->name);
    }

    #[Test]
    public function the_type_decides_which_parent_is_allowed(): void
    {
        $this->actingAsRole(RoleName::Admin);
        [$region, $area] = $this->tree();

        // 地域に親を付けようとすると弾かれる
        $this->from(route('masters.organizations.create'))
            ->post(route('masters.organizations.store'), [
                'name' => '誤った地域',
                'type' => OrganizationType::Region->value,
                'parent_id' => $area->id,
            ])
            ->assertSessionHasErrors('parent_id');

        // エリアの親は地域でなければならない
        $this->post(route('masters.organizations.store'), [
            'name' => '誤ったエリア',
            'type' => OrganizationType::Area->value,
            'parent_id' => $area->id,
        ])->assertSessionHasErrors('parent_id');

        // 店舗には親が要る
        $this->post(route('masters.organizations.store'), [
            'name' => '親なし店舗',
            'type' => OrganizationType::Store->value,
        ])->assertSessionHasErrors('parent_id');

        // 正しい組み合わせなら登録できる
        $this->post(route('masters.organizations.store'), [
            'name' => '北関東エリア',
            'type' => OrganizationType::Area->value,
            'parent_id' => $region->id,
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertSame(
            $region->id,
            Organization::query()->where('name', '北関東エリア')->firstOrFail()->parent_id,
        );
    }

    #[Test]
    public function an_employee_belongs_to_a_store(): void
    {
        $this->actingAsRole(RoleName::Admin);
        [, $area, $store] = $this->tree();

        $employee = Employee::factory()->create(['name' => '山田 太郎']);

        $this->put(route('masters.employees.update', $employee->id), [
            'name' => '山田 太郎',
            'organization_id' => $store->id,
            'employment_status' => 'active',
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertSame($store->id, $employee->fresh()?->organization_id);

        // 所属に選べるのは店舗だけ(エリアや地域は選べない)
        $this->put(route('masters.employees.update', $employee->id), [
            'name' => '山田 太郎',
            'organization_id' => $area->id,
            'employment_status' => 'active',
            'is_active' => '1',
        ])->assertSessionHasErrors('organization_id');
    }

    #[Test]
    public function the_master_hub_lists_organizations(): void
    {
        $this->actingAsRole(RoleName::Staff);
        $this->tree();

        $this->get(route('masters.index'))
            ->assertOk()
            ->assertSee('組織')
            ->assertSee(route('masters.organizations.index'));
    }

    #[Test]
    public function the_list_is_ordered_by_hierarchy(): void
    {
        $this->actingAsRole(RoleName::Staff);

        // 西日本を先に作り、コード順と階層順がずれる状態にする
        $west = $this->organization('西日本地域', OrganizationType::Region);
        $kansai = $this->organization('関西エリア', OrganizationType::Area, $west);
        $osaka = $this->organization('大阪本店', OrganizationType::Store, $kansai, '大阪府');

        [$east, $capital, $tokyo] = $this->tree();

        $html = $this->get(route('masters.organizations.index', ['reset' => 1]))->assertOk()->getContent();

        // 絞り込み欄にも組織名が出るので、表の中だけを見る
        $html = substr($html, (int) strpos($html, '<tbody'), (int) strpos($html, '</tbody>') - (int) strpos($html, '<tbody'));

        // 地域 → その配下エリア → その配下店舗、の順に並ぶ
        $order = ['西日本地域', '関西エリア', '大阪本店', '東日本地域', '首都圏エリア', '東京本店'];
        $positions = array_map(static fn (string $name): int => (int) strpos($html, $name), $order);

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, '階層をたどる順に並んでいる。');

        // 使っている変数(参照を明示するためのアサーション)
        $this->assertSame('東日本地域', $east->name);
        $this->assertSame('首都圏エリア', $capital->name);
        $this->assertSame('東京本店', $tokyo->name);
        $this->assertSame('大阪本店', $osaka->name);
    }

    #[Test]
    public function a_store_can_hold_its_prefecture(): void
    {
        $this->actingAsRole(RoleName::Admin);
        [$region, $area] = $this->tree();

        $this->post(route('masters.organizations.store'), [
            'name' => '大阪本店',
            'type' => OrganizationType::Store->value,
            'parent_id' => $area->id,
            'prefecture' => '大阪府',
            'is_active' => '1',
        ])->assertRedirect();

        $store = Organization::query()->where('name', '大阪本店')->firstOrFail();
        $this->assertSame('大阪府', $store->prefecture);

        // 一覧・詳細に出る
        $this->get(route('masters.organizations.index', ['reset' => 1]))->assertOk()->assertSee('大阪府');
        $this->get(route('masters.organizations.detail', $store->id))->assertOk()->assertSee('大阪府');

        // 地域・エリアには設定できない
        $this->post(route('masters.organizations.store'), [
            'name' => '誤った地域',
            'type' => OrganizationType::Region->value,
            'prefecture' => '大阪府',
        ])->assertSessionHasErrors('prefecture');

        // 一覧の都道府県は絞り込みにも使える(表の中だけを見る)
        $filtered = $this->get(route('masters.organizations.index', ['prefecture' => '大阪府']))
            ->assertOk()
            ->getContent();

        $body = substr($filtered, (int) strpos($filtered, '<tbody'), (int) strpos($filtered, '</tbody>') - (int) strpos($filtered, '<tbody'));

        // 大阪府の店舗 1 行だけになる(行の中の「上位組織」には親の名前が出るので、行数で見る)
        $this->assertStringContainsString('大阪本店', $body);
        $this->assertSame(1, substr_count($body, '<tr '));

        $this->assertSame('東日本地域', $region->name);
    }
}
