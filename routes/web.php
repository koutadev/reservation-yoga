<?php

use App\Enums\PermissionName;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Masters\DepartmentController;
use App\Http\Controllers\Masters\EmployeeController;
use App\Http\Controllers\Masters\MasterHubController;
use App\Http\Controllers\Masters\OrganizationController;
use App\Http\Controllers\Masters\PartnerController;
use App\Http\Controllers\Masters\PositionController;
use App\Http\Controllers\Masters\ProductCategoryController;
use App\Http\Controllers\Masters\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SavedViewController;
use App\Http\Controllers\UserController;
use App\Support\DataTable\Column;
use App\Support\Routing\MasterRoutes;
use App\Support\Ui\DateRange;
use App\Support\Ui\SearchText;
use App\Support\Ui\Toast;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // 環境の疎通確認用(STEP 1 から継続)
    try {
        $pdo = DB::connection()->getPdo();

        $database = [
            'connected' => true,
            'message' => sprintf(
                '%s / %s',
                DB::connection()->getDatabaseName(),
                $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            ),
        ];
    } catch (Throwable $e) {
        $database = [
            'connected' => false,
            'message' => $e->getMessage(),
        ];
    }

    return view('welcome', [
        'database' => $database,
        'status' => [
            'Laravel' => app()->version(),
            'PHP' => PHP_VERSION,
            'Database' => config('database.default'),
        ],
    ]);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:'.PermissionName::DashboardView->value)
        ->name('dashboard');

    // 一覧の保存ビュー(マイビュー)。自分のぶんだけ作れて、消せる
    Route::post('/saved-views', [SavedViewController::class, 'store'])->name('saved-views.store');
    Route::delete('/saved-views/{savedView}', [SavedViewController::class, 'destroy'])
        ->whereNumber('savedView')
        ->name('saved-views.destroy');

    Route::get('/activity-logs', [ActivityLogController::class, 'index'])
        ->middleware('permission:'.PermissionName::ActivityLogView->value)
        ->name('activity-logs.index');

    // --- 共通マスタ -------------------------------------------------------
    // 一覧 / CSV は master.view、登録・編集・削除・復元は master.manage が必要
    Route::prefix('masters')->name('masters.')->group(function () {
        // 各マスタへの入口(ハブ)
        Route::get('/', [MasterHubController::class, 'index'])
            ->middleware('permission:'.PermissionName::MasterView->value)
            ->name('index');

        MasterRoutes::register('organizations', OrganizationController::class, 'organizations');
        MasterRoutes::register('employees', EmployeeController::class, 'employees');
        MasterRoutes::register('partners', PartnerController::class, 'partners');
        MasterRoutes::register('products', ProductController::class, 'products');

        // サブマスタ
        MasterRoutes::register('departments', DepartmentController::class, 'departments');
        MasterRoutes::register('positions', PositionController::class, 'positions');
        MasterRoutes::register('product-categories', ProductCategoryController::class, 'product-categories');
    });

    // --- ユーザー管理(ロールの付け替え) ----------------------------------
    Route::middleware('permission:'.PermissionName::UserManage->value)
        ->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/{id}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{id}', [UserController::class, 'update'])->name('users.update');
        });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| UI コンポーネントカタログ(開発・デモ用)
|--------------------------------------------------------------------------
|
| 共通部品の見た目と状態を 1 ページで確認するためのページ。
| 本番環境では登録しない。
|
*/
if (! app()->environment('production')) {
    Route::get('/_ui', function () {
        // ページネーションの見た目を確認するためのダミー
        $paginator = new LengthAwarePaginator(
            items: range(1, 20),
            total: 137,
            perPage: 20,
            currentPage: 3,
            options: ['path' => url('/_ui')],
        );

        $customers = [
            1 => '株式会社アオイ商事', 2 => '有限会社イロハ物産', 3 => 'ウエノ電機株式会社',
            4 => '株式会社エダサキ工業', 5 => 'オオトリ製作所', 6 => '株式会社カシワギシステムズ',
            7 => 'キタムラ運輸株式会社', 8 => '株式会社クスノキ設計', 9 => 'ケヤキ食品株式会社',
            10 => '株式会社コウヨウホールディングス',
        ];

        // テーブルの見本(このページ自身でソートを試せるようにする)
        $tableSort = in_array(request('sort'), ['code', 'name', 'amount'], true) ? (string) request('sort') : 'code';
        $tableDirection = request('direction') === 'desc' ? 'desc' : 'asc';

        $tableRows = collect([
            ['code' => 'EMP-0001', 'name' => '青山 彩', 'department' => '営業部', 'amount' => 1320000, 'status' => '受注', 'tone' => 'success'],
            ['code' => 'EMP-0002', 'name' => '井川 亮', 'department' => 'システム開発部', 'amount' => 880000, 'status' => '提案中', 'tone' => 'info'],
            ['code' => 'EMP-0003', 'name' => '上野 千夏', 'department' => '人材事業部', 'amount' => 2475000, 'status' => '見積提示', 'tone' => 'warning'],
            ['code' => 'EMP-0004', 'name' => '江原 拓真', 'department' => '営業部', 'amount' => 396000, 'status' => '失注', 'tone' => 'danger'],
        ])
            ->sortBy($tableSort, SORT_REGULAR, $tableDirection === 'desc')
            ->values()
            ->all();

        $tableColumns = [
            Column::fromArray(['key' => 'code', 'label' => '社員コード', 'sortable' => true, 'wrap' => false, 'width' => 'w-32']),
            Column::fromArray(['key' => 'name', 'label' => '氏名', 'sortable' => true]),
            Column::fromArray(['key' => 'department', 'label' => '部署']),
            Column::fromArray(['key' => 'amount', 'label' => '金額', 'sortable' => true, 'align' => 'right', 'wrap' => false]),
            Column::fromArray(['key' => 'status', 'label' => '状態', 'align' => 'center']),
        ];

        $tableSortUrl = function (Column $column) use ($tableSort, $tableDirection): string {
            $direction = $tableSort === $column->key && $tableDirection === 'asc' ? 'desc' : 'asc';

            return route('ui.catalog', ['sort' => $column->key, 'direction' => $direction]);
        };

        return view('ui.catalog', [
            'tableColumns' => $tableColumns,
            'tableRows' => $tableRows,
            'tableSort' => $tableSort,
            'tableDirection' => $tableDirection,
            'tableSortUrl' => $tableSortUrl,
            'paginator' => $paginator,
            'customers' => $customers,
            // 日付範囲ピッカーの送信値をサーバ側で解決した結果(見本)
            'demoRange' => DateRange::fromRequest(request(), 'demo_range'),
        ]);
    })->name('ui.catalog');

    // 編集フォーム用モーダルの見本(バリデーションエラーで開き直す)
    Route::post('/_ui/demo-form', function () {
        request()->validate(
            ['demo_title' => ['required', 'string', 'max:20']],
            [],
            ['demo_title' => '件名'],
        );

        return back()->with(
            Toast::SESSION_KEY,
            Toast::success('保存しました(デモなので実際には保存していません)。'),
        );
    })->name('ui.catalog.demo-form');

    // コンボボックスの非同期モードの見本(?q= で絞り込み、[{value,label}] を返す)
    Route::get('/_ui/options', function () {
        // ひらがなで入力してもカタカナの候補に当たることを確かめられる並び
        $companies = ['アオイ商事', 'イロハ物産', 'ウエノ電機', 'エダサキ工業', 'オオトリ製作所',
            'カシワギシステムズ', 'キタムラ運輸', 'クスノキ設計', 'ケヤキ食品', 'コウヨウ商会',
            'サカタ精機', 'シラハマ物流', 'スミレ印刷', 'セノオ建設', 'ソラチ農産',
            'タチバナ電子', 'チトセ工房', 'ツバキ製薬', 'テラオカ通信', 'トウカイ商事',
            'ナガレヤマ技研', 'ニシキ製作', 'ヌマタ運送', 'ネギシ工業', 'ノザワ商店'];

        $query = trim((string) request('q'));

        return collect($companies)
            ->when($query !== '', fn ($items) => $items->filter(
                // 入力と候補の両方を同じ形に正規化してから比較する
                fn (string $name): bool => SearchText::matches($name, $query)
            ))
            ->take(20)
            ->values()
            ->map(fn (string $name, int $index): array => ['value' => (string) ($index + 1), 'label' => $name])
            ->all();
    })->name('ui.catalog.options');
}

require __DIR__.'/auth.php';
