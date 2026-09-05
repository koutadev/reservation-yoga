<x-master-index :table="$table" :resource-label="$resourceLabel" :route-name="$routeName"
                :initial-detail="$initialDetail ?? null" :manage-permission="$managePermission">
    @foreach ($table->items() as $instructor)
        <x-table.row :muted="$instructor->trashed()"
                     :detail-url="route($routeName.'.detail', $instructor->id)">
            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs">{{ $instructor->code }}</td>
            <td class="px-4 py-3 font-medium">{{ $instructor->name }}</td>

            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">
                @if ($instructor->user === null)
                    {{-- 未紐付けの講師は、講師本人がログインして自分の枠を編集できない --}}
                    <span class="text-xs text-gray-400">未紐付け</span>
                @else
                    {{ $instructor->user->name }}
                    <span class="ms-1 text-xs text-gray-400">{{ $instructor->user->email }}</span>
                @endif
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-center">
                <x-active-badge :active="$instructor->is_active" :trashed="$instructor->trashed()" />
            </td>

            <td class="whitespace-nowrap px-4 py-3 text-gray-500 dark:text-gray-400">
                {{ $instructor->updated_at?->format('Y/m/d H:i') }}
            </td>

            <x-master-row-actions :record="$instructor" :route-name="$routeName"
                                  :resource-label="$resourceLabel" :manage-permission="$managePermission" />
        </x-table.row>
    @endforeach
</x-master-index>
