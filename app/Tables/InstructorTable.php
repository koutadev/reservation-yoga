<?php

namespace App\Tables;

use App\Models\Instructor;
use App\Support\DataTable\Column;
use App\Support\DataTable\Filter;
use App\Support\DataTable\TableDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 講師（インストラクター）マスタ一覧の定義。
 *
 * 「担当ユーザー」は instructors.user_id の紐付け先。
 * ここが埋まっている講師だけが、staff としてログインして自分の枠を編集できる。
 */
class InstructorTable extends TableDefinition
{
    public function key(): string
    {
        return 'instructors';
    }

    public function routeName(): string
    {
        return 'masters.instructors';
    }

    public function query(): Builder
    {
        return Instructor::query()->with('user:id,name,email');
    }

    public function columns(): array
    {
        return [
            new Column('code', '講師コード', sortable: true, wrap: false, width: 'w-32'),
            new Column('name', '氏名', sortable: true),
            new Column('user_id', '担当ユーザー'),
            new Column('is_active', '状態', sortable: true, align: 'center'),
            new Column('updated_at', '更新日時', sortable: true, wrap: false),
        ];
    }

    public function searchable(): array
    {
        return ['code', 'name', 'profile'];
    }

    public function searchPlaceholder(): string
    {
        return '講師コード・氏名・プロフィールで検索';
    }

    public function filters(): array
    {
        return [
            Filter::activeFlag(),
        ];
    }

    public function defaultSort(): string
    {
        return 'code';
    }

    public function defaultDirection(): string
    {
        return 'asc';
    }

    public function toCsvRow(Model $model): array
    {
        /** @var Instructor $model */
        return [
            $model->code,
            $model->name,
            $model->user?->name,
            $model->activeLabel(),
            $model->updated_at?->format('Y/m/d H:i'),
        ];
    }
}
