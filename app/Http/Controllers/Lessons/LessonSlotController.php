<?php

namespace App\Http\Controllers\Lessons;

use App\Enums\LessonSlotStatus;
use App\Enums\LessonType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lessons\LessonSlotRequest;
use App\Http\Requests\Lessons\RecurringLessonSlotRequest;
use App\Models\Instructor;
use App\Models\LessonSlot;
use App\Support\DataTable\CsvExporter;
use App\Support\DataTable\Table;
use App\Support\DataTable\TableBuilder;
use App\Support\DataTable\TableState;
use App\Support\Lessons\RecurringSlots;
use App\Support\Lessons\SlotCancellation;
use App\Support\Ui\Toast;
use App\Tables\LessonSlotTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * レッスン枠の開講・管理（管理/講師）。
 *
 * 一覧は共通の一覧基盤（TableDefinition）に載せ、編集は行クリックのモーダルで行う
 * ため、一覧に編集ボタンは置かない（共通マスタと同じ操作感）。
 *
 * 業務ルールは次の 3 つ（設計書 6. / STEP2-2）。
 *   1. すでに入っている予約より小さい定員には変更できない（LessonSlotRequest）
 *   2. 枠を中止したら、その枠の予約とキャンセル待ちも連鎖してキャンセルする（SlotCancellation）
 *   3. 予約数・残枠は保持せず都度算出する（LessonSlotTable / LessonSlot::remainingSeats）
 */
class LessonSlotController extends Controller
{
    /**
     * 一覧。既定は「今後の枠を日時順」。
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', LessonSlot::class);

        $table = Table::make(new LessonSlotTable, $request);

        return view('lessons.slots.index', [
            'table' => $table,
            'period' => LessonSlotTable::period($table->state),
            'periodOptions' => LessonSlotTable::PERIODS,
            // 保存に失敗したときは、その枠の詳細を開いた状態で描き直す
            'initialDetail' => $this->initialDetail(),
        ]);
    }

    /**
     * 現在の絞り込みのまま CSV に出す。
     */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', LessonSlot::class);

        $definition = new LessonSlotTable;
        $state = TableState::resolve($request, $definition, false);

        return (new CsvExporter($definition, new TableBuilder($definition, $state)))->download();
    }

    /**
     * 行クリックで開くモーダルの中身（HTML の断片）。
     */
    public function detail(int $id): View
    {
        $slot = $this->findSlot($id);

        Gate::authorize('view', $slot);

        return view('lessons.slots._detail', $this->detailData($slot));
    }

    /**
     * 開講フォーム（単発／繰り返しを切り替えられる）。
     */
    public function create(Request $request): View
    {
        Gate::authorize('create', LessonSlot::class);

        $startsAt = now()->addDay()->setTime(10, 0);

        $slot = new LessonSlot([
            'lesson_type' => LessonType::Group->value,
            'capacity' => 10,
            'status' => LessonSlotStatus::Open->value,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            // 自分に紐付いた講師があれば初期値にしておく
            'instructor_id' => $request->user()?->instructor?->id,
        ]);

        return view('lessons.slots.form', $this->formData($slot));
    }

    public function store(LessonSlotRequest $request): RedirectResponse
    {
        Gate::authorize('create', LessonSlot::class);

        $slot = LessonSlot::create($request->validated());

        return $this->backToIndex('レッスン枠 '.$slot->code.' を開講しました。');
    }

    /**
     * 繰り返し開講（曜日 × 時間 × 期間）。
     */
    public function storeRecurring(RecurringLessonSlotRequest $request): RedirectResponse
    {
        Gate::authorize('create', LessonSlot::class);

        $result = RecurringSlots::generate(
            attributes: $request->slotAttributes(),
            weekdays: $request->weekdays(),
            from: $request->dateFrom(),
            to: $request->dateTo(),
            startTime: (string) $request->input('start_time'),
            endTime: (string) $request->input('end_time'),
        );

        return $this->backToIndex($result->message());
    }

    /**
     * フルページの編集フォーム（URL 直打ち・モーダルを使わない導線用）。
     */
    public function edit(int $id): View
    {
        $slot = $this->findSlot($id);

        Gate::authorize('update', $slot);

        return view('lessons.slots.form', $this->formData($slot));
    }

    /**
     * 更新。中止に変えたときは、予約とキャンセル待ちも連鎖してキャンセルする。
     */
    public function update(LessonSlotRequest $request, int $id): RedirectResponse
    {
        $slot = $this->findSlot($id);

        Gate::authorize('update', $slot);

        $cancellation = DB::transaction(function () use ($slot, $request) {
            $slot->update($request->validated());

            return $slot->isCanceled() ? SlotCancellation::apply($slot) : null;
        });

        $message = 'レッスン枠 '.$slot->code.' を更新しました。';

        if ($cancellation !== null && ! $cancellation->isEmpty()) {
            $message .= $cancellation->message();
        }

        return $this->backToIndex($message);
    }

    /**
     * モーダルに渡すデータ。
     *
     * @return array<string, mixed>
     */
    private function detailData(LessonSlot $slot): array
    {
        return array_merge($this->formData($slot), [
            'rows' => $this->detailRows($slot),
            'canManage' => request()->user()?->can('update', $slot) ?? false,
        ]);
    }

    /**
     * モーダルの詳細に出す項目。
     *
     * 予約数・残枠・キャンセル待ちは、ここでも都度数える。
     *
     * @return array<string, string|null>
     */
    private function detailRows(LessonSlot $slot): array
    {
        return [
            'コード' => $slot->code,
            '日時' => $slot->starts_at->format('Y/m/d(D) H:i').' 〜 '.$slot->ends_at->format('H:i'),
            'レッスン名' => $slot->title,
            '講師' => $slot->instructor?->name,
            '形式' => $slot->lesson_type->label(),
            '定員' => $slot->capacity.' 名',
            '予約数' => $slot->reservedCount().' 名',
            '残枠' => $slot->remainingSeats().' 名',
            'キャンセル待ち' => $slot->waitingList()->count().' 名',
            'オンライン URL' => $slot->online_url ?? '未設定',
            '状態' => $slot->status->label(),
            '最終更新' => $slot->updated_at?->format('Y/m/d H:i'),
        ];
    }

    /**
     * フォームに渡す選択肢など。
     *
     * @return array<string, mixed>
     */
    private function formData(LessonSlot $slot): array
    {
        return [
            'slot' => $slot,
            'instructorOptions' => Instructor::query()->active()->orderBy('code')->pluck('name', 'id')->all(),
            'lessonTypeOptions' => LessonType::options(),
            'statusOptions' => LessonSlotStatus::options(),
        ];
    }

    /**
     * 直前の送信がモーダルの編集フォームだった場合に、描き直す詳細。
     *
     * @return array<string, mixed>|null
     */
    private function initialDetail(): ?array
    {
        $id = old('_modal_record');

        if (! is_numeric($id)) {
            return null;
        }

        $slot = LessonSlot::query()->with('instructor')->find((int) $id);

        return $slot === null ? null : $this->detailData($slot);
    }

    private function findSlot(int $id): LessonSlot
    {
        return LessonSlot::query()->with('instructor')->findOrFail($id);
    }

    private function backToIndex(string $message): RedirectResponse
    {
        return redirect()
            ->route('lesson-slots.index')
            ->with(Toast::SESSION_KEY, Toast::success($message));
    }
}
