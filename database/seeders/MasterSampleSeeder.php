<?php

namespace Database\Seeders;

use App\Enums\OrganizationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Partner;
use App\Models\Position;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

/**
 * 動作確認用のマスタサンプルデータ(本番環境では実行しない)。
 *
 * 一覧のページング(1ページ20件)や検索を確認できるよう、
 * 各マスタを 20 件以上作成する。
 */
class MasterSampleSeeder extends Seeder
{
    public function run(): void
    {
        // 既にサンプルが入っている場合は二重登録しない
        if (Employee::query()->exists()) {
            return;
        }

        // 組織(地域 > エリア > 店舗)。乱数を使わないので、他のサンプルの並びに影響しない
        $stores = $this->createOrganizations();

        $departments = Department::factory()->count(5)->create();
        $positions = Position::factory()->count(5)->create();
        $categories = ProductCategory::factory()->count(5)->create();

        Employee::factory()
            ->count(28)
            ->create()
            ->each(function (Employee $employee, int $index) use ($departments, $positions, $stores): void {
                $employee->forceFill([
                    'department_id' => $departments->random()->id,
                    'position_id' => $positions->random()->id,
                    // 5 店舗ずつ飛ばして配属し、どの地域にも担当者が行き渡るようにする
                    'organization_id' => $stores[($index * 5) % count($stores)]->id,
                ])->saveQuietly();
            });

        // 無効・退職のデータも混ぜて、絞り込みを確認できるようにする
        Employee::factory()->retired()->count(4)->create();

        Partner::factory()->count(26)->create();

        Product::factory()
            ->count(24)
            ->create()
            ->each(function (Product $product) use ($categories): void {
                $product->forceFill(['product_category_id' => $categories->random()->id])->saveQuietly();
            });
    }

    /**
     * 組織の階層(地域 > エリア > 店舗)を作り、最下層の店舗を返す。
     *
     * 名前は固定なので、何度シードし直しても同じ組織ができる。
     *
     * @return list<Organization>
     */
    private function createOrganizations(): array
    {
        // [店舗名 => 所在都道府県]。同じ都道府県に複数店舗があるケースも入れてある
        /** @var array<string, array<string, array<string, string>>> $tree */
        $tree = [
            '東日本地域' => [
                '首都圏エリア' => [
                    '東京本店' => '東京都',
                    '東京西支店' => '東京都',
                    '横浜支店' => '神奈川県',
                    'さいたま支店' => '埼玉県',
                ],
                '北関東エリア' => [
                    '大宮支店' => '埼玉県',
                    '宇都宮支店' => '栃木県',
                ],
            ],
            '中日本地域' => [
                '東海エリア' => [
                    '名古屋支店' => '愛知県',
                    '静岡支店' => '静岡県',
                ],
                '北陸エリア' => [
                    '金沢支店' => '石川県',
                ],
            ],
            '西日本地域' => [
                '関西エリア' => [
                    '大阪本店' => '大阪府',
                    '大阪南支店' => '大阪府',
                    '神戸支店' => '兵庫県',
                    '京都支店' => '京都府',
                ],
                '九州エリア' => [
                    '福岡支店' => '福岡県',
                ],
            ],
        ];

        $stores = [];

        foreach ($tree as $regionName => $areas) {
            $region = Organization::create([
                'name' => $regionName,
                'type' => OrganizationType::Region,
                'is_active' => true,
            ]);

            foreach ($areas as $areaName => $storeNames) {
                $area = Organization::create([
                    'name' => $areaName,
                    'type' => OrganizationType::Area,
                    'parent_id' => $region->id,
                    'is_active' => true,
                ]);

                foreach ($storeNames as $storeName => $prefecture) {
                    $stores[] = Organization::create([
                        'name' => $storeName,
                        'type' => OrganizationType::Store,
                        'prefecture' => $prefecture,
                        'parent_id' => $area->id,
                        'is_active' => true,
                    ]);
                }
            }
        }

        return $stores;
    }
}
