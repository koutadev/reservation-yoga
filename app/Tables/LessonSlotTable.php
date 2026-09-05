<?php

namespace App\Tables;

use App\Enums\LessonSlotStatus;
use App\Enums\LessonType;
use App\Models\Instructor;
use App\Models\LessonSlot;
use App\Support\DataTable\Column;
use App\Support\DataTable\Filter;
use App\Support\DataTable\TableDefinition;
use App\Support\DataTable\TableState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * レッスン枠一覧の定義（管理側）。
 *
 * 既定は「今後の枠を日時の早い順」。過去の枠は絞り込みで切り替えて見る。
 * 予約数は枠に持たせていないので、一覧でも都度数える（withCount）。
 */
class LessonSlotTable extends TableDefinition
{
    /** 表示する期間（セレクト 1 つでは表せないので extra パラメータで持つ） */
    public const PERIOD_UPCOMING = 'upcoming';

    public const PERIOD_PAST = 'past';

    public const PERIOD_ALL = 'all';

    /** @var array<string, string> */
    public const PERIODS = [
        self::PERIOD_UPCOMING => '今後の枠',
        self::PERIOD_PAST => '過去の枠',
        self::PERIOD_ALL => 'すべて',
    ];

    public function key(): string
    {
        return 'lesson_slots';
    }

    public function routeName(): string
    {
        return 'lesson-slots';
    }

    public function query(): Builder
    {
        return LessonSlot::query()
            ->with('instructor:id,code,name')
            // 残枠・予約数は保持せず都度算出する（設計書 6.）
            ->withCount('activeReservations as reserved_count');
    }

    public function columns(): array
    {
        return [
            new Column('code', 'コード', sortable: true, wrap: false, width: 'w-36'),
            new Column('starts_at', '日時', sortable: true, wrap: false),
            new Column('title', 'レッスン', sortable: true),
            new Column('instructor', '講師', wrap: false),
            new Column('lesson_type', '形式', sortable: true, align: 'center'),
            new Column('capacity', '定員', sortable: true, align: 'right', wrap: false),
            new Column('reserved_count', '予約', align: 'right', wrap: false),
            new Column('online_url', 'URL', align: 'center', wrap: false),
            new Column('status', '状態', sortable: true, align: 'center'),
        ];
    }

    public function searchable(): array
    {
        return ['code', 'title'];
    }

    public function searchPlaceholder(): string
    {
        return 'コード・レッスン名で検索';
    }

    public function filters(): array
    {
        return [
            new Filter('instructor_id', '講師', $this->cachedOptions(
                'instructors',
                static fn (): array => Instructor::query()->orderBy('code')->pluck('name', 'id')->all(),
            )),
            new Filter('lesson_type', '形式', LessonType::options()),
            new Filter('status', '状態', LessonSlotStatus::options()),
        ];
    }

    public function statefulParameters(): array
    {
        return ['period'];
    }

    /**
     * 表示する期間で絞り込む。既定は「今後の枠」。
     */
    public function applyExtraFilters(Builder $query, TableState $state): void
    {
        match (self::period($state)) {
            self::PERIOD_UPCOMING => $query->where('starts_at', '>=', now()),
            self::PERIOD_PAST => $query->where('starts_at', '<', now()),
            default => null,
        };
    }

    /**
     * いま選ばれている期間（未指定・不正な値は「今後の枠」）。
     */
    public static function period(TableState $state): string
    {
        $period = $state->extra('period');

        return array_key_exists($period, self::PERIODS) ? $period : self::PERIOD_UPCOMING;
    }

    public function defaultSort(): string
    {
        return 'starts_at';
    }

    public function defaultDirection(): string
    {
        return 'asc';
    }

    public function exportFileName(): string
    {
        return 'lesson-slots';
    }

    public function toCsvRow(Model $model): array
    {
        /** @var LessonSlot $model */
        return [
            $model->code,
            $model->starts_at->format('Y/m/d H:i').'〜'.$model->ends_at->format('H:i'),
            $model->title,
            $model->instructor?->name,
            $model->lesson_type->label(),
            $model->capacity,
            $model->reservedCount(),
            $model->online_url === null ? '未設定' : '設定済',
            $model->status->label(),
        ];
    }
}
