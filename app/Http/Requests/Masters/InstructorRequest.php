<?php

namespace App\Http\Requests\Masters;

use Illuminate\Validation\Rule;

/**
 * 講師（インストラクター）マスタの登録・編集。
 *
 * 担当ユーザーの紐付けは 1 対 1（instructors.user_id はテーブル側でも一意）。
 * 未紐付けのままでもよい（講師本人がログインしない運用）。
 */
class InstructorRequest extends MasterRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $id = $this->recordId();

        return [
            'name' => ['required', 'string', 'max:100'],
            'profile' => ['nullable', 'string', 'max:2000'],

            // bail + integer は、数値でない値のまま exists へ渡して
            // PostgreSQL の型エラー(500)になるのを防ぐため
            'user_id' => [
                'bail',
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
                // instructors.user_id は削除済みの行も含めて一意（テーブル側の制約に合わせる）
                Rule::unique('instructors', 'user_id')->ignore($id),
            ],

            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => '氏名',
            'profile' => 'プロフィール',
            'user_id' => '担当ユーザー',
            'is_active' => '状態',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.unique' => 'このユーザーは既に別の講師に紐付いています（削除済みの講師を含みます）。',
        ];
    }
}
