{{-- 講師の入力項目。フルページのフォームとモーダル編集で共有する。 --}}
<x-form-field name="name" label="氏名" :required="true">
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                  :value="old('name', $instructor->name)" required autofocus />
</x-form-field>

<x-form-field name="profile" label="プロフィール"
              help="会員向けの紹介文。担当クラスや指導歴など。">
    <textarea id="profile" name="profile" rows="4"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 sm:text-sm">{{ old('profile', $instructor->profile) }}</textarea>
</x-form-field>

<x-form-field name="user_id" label="担当ユーザー"
              help="この講師としてログインするユーザー。紐付けると、その人（staff）が自分の枠だけを編集できるようになります。1 人につき 1 講師まで。">
    <x-select-input id="user_id" name="user_id" class="mt-1 block w-full"
                    :options="$userOptions"
                    :selected="old('user_id', $instructor->user_id)"
                    placeholder="紐付けない" />
</x-form-field>

<div>
    <x-active-checkbox :record="$instructor" />
</div>
