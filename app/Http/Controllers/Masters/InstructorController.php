<?php

namespace App\Http\Controllers\Masters;

use App\Enums\PermissionName;
use App\Http\Requests\Masters\InstructorRequest;
use App\Models\BaseModel;
use App\Models\Instructor;
use App\Models\User;
use App\Support\DataTable\TableDefinition;
use App\Tables\InstructorTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 講師（インストラクター）マスタ。
 *
 * 一覧・行クリックのモーダル編集・CSV・論理削除は共通のマスタ基盤に載せている。
 * 共通マスタと違い、参照も更新も管理者だけ（instructor.manage）。
 *
 * ここで users と紐付けた講師は、staff としてログインして
 * 自分の枠だけを編集できるようになる（LessonSlotPolicy）。
 */
class InstructorController extends MasterController
{
    protected function definition(): TableDefinition
    {
        return new InstructorTable;
    }

    protected function viewPath(): string
    {
        return 'masters.instructors';
    }

    protected function modelClass(): string
    {
        return Instructor::class;
    }

    protected function resourceLabel(): string
    {
        return '講師';
    }

    protected function managePermission(): PermissionName
    {
        return PermissionName::InstructorManage;
    }

    public function create(): View
    {
        return view($this->viewPath().'.form', $this->formData(new Instructor));
    }

    public function store(InstructorRequest $request): RedirectResponse
    {
        // code は HasSequentialCode により INS-0001 形式で自動採番される
        Instructor::create($request->validated());

        return $this->redirectToIndex('講師を登録しました。');
    }

    public function edit(int $id): View
    {
        $instructor = Instructor::query()->findOrFail($id);

        return view($this->viewPath().'.form', $this->formData($instructor));
    }

    public function update(InstructorRequest $request, int $id): RedirectResponse
    {
        Instructor::query()->findOrFail($id)->update($request->validated());

        return $this->redirectToIndex('講師を更新しました。');
    }

    /**
     * @return array<string, string|null>
     */
    protected function detailRows(BaseModel $record): array
    {
        /** @var Instructor $record */
        return [
            '講師コード' => $record->code,
            '氏名' => $record->name,
            'プロフィール' => $record->profile,
            '担当ユーザー' => $record->user === null
                ? '未紐付け'
                : $record->user->name.'（'.$record->user->email.'）',
            '担当レッスン枠' => $record->lessonSlots()->count().' 件',
            '状態' => $record->activeLabel(),
            '登録日時' => $record->created_at?->format('Y/m/d H:i'),
            '最終更新' => $record->updated_at?->format('Y/m/d H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(BaseModel $instructor): array
    {
        /** @var Instructor $instructor */
        return array_merge($this->sharedViewData(), [
            'instructor' => $instructor,
            'userOptions' => $this->userOptions($instructor),
        ]);
    }

    /**
     * 担当ユーザーの候補。
     *
     * 紐付けは 1 対 1 なので、ほかの講師が既に押さえているユーザーは候補から外す
     * （削除済みの講師も user_id を持ったままなので、それも含める）。
     * 編集中の講師が紐付いているユーザーは当然そのまま選べる。
     *
     * @return array<int, string>
     */
    private function userOptions(Instructor $instructor): array
    {
        $taken = Instructor::query()
            ->withTrashed()
            ->whereNotNull('user_id')
            ->when($instructor->exists, fn ($query) => $query->whereKeyNot($instructor->id))
            ->pluck('user_id')
            ->all();

        return User::query()
            ->whereNotIn('id', $taken)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(static fn (User $user): array => [
                $user->id => $user->name.'（'.$user->email.'）',
            ])
            ->all();
    }
}
