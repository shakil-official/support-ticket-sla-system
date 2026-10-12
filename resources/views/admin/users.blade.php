<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Team &amp; roles</h1>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-4 px-4 py-6 sm:px-6 lg:px-8">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Agents are auto-assigned tickets of the categories ticked here (only while <em>available</em>).
            An agent with no category ticked receives nothing automatically.
        </p>

        <x-ui.card class="divide-y divide-gray-200 dark:divide-gray-700">
            @foreach ($users as $user)
                <form method="POST" action="{{ route('admin.users.update', $user) }}" class="grid gap-3 p-4 lg:grid-cols-12 lg:items-start">
                    @csrf @method('PUT')

                    <div class="lg:col-span-3">
                        <div class="font-medium">{{ $user->name }}</div>
                        <div class="text-sm text-gray-500">{{ $user->email }}</div>
                    </div>

                    <div class="lg:col-span-2">
                        <x-ui.select name="role" aria-label="Role">
                            @foreach ($roles as $role)
                                <option value="{{ $role->value }}" @selected($user->role === $role)>{{ $role->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <label class="flex items-center gap-2 text-sm lg:col-span-2 lg:pt-2">
                        <input type="hidden" name="is_available" value="0">
                        <input type="checkbox" name="is_available" value="1" class="rounded border-gray-300" @checked($user->is_available)>
                        Available
                    </label>

                    <div class="lg:col-span-4">
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                            @foreach ($categories as $category)
                                <label class="flex items-center gap-1">
                                    <input type="checkbox" name="categories[]" value="{{ $category->id }}" class="rounded border-gray-300"
                                        @checked($user->categories->contains('id', $category->id))>
                                    {{ $category->name }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="lg:col-span-1 lg:text-right">
                        <x-ui.button type="submit">Save</x-ui.button>
                    </div>
                </form>
            @endforeach
        </x-ui.card>

        <div>{{ $users->links() }}</div>
    </div>
</x-app-layout>
