<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Notifications</h1>
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">Mark all as read</x-ui.button>
            </form>
        </div>
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-4 px-4 py-6 sm:px-6 lg:px-8">
        <x-ui.card class="divide-y divide-gray-200 overflow-hidden dark:divide-gray-700">
            @forelse ($notifications as $notification)
                <form method="POST" action="{{ route('notifications.open', $notification->id) }}">
                    @csrf
                    <button type="submit" @class([
                        'block w-full px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-gray-700/40',
                        'bg-indigo-50/60 dark:bg-indigo-900/20' => $notification->unread(),
                    ])>
                        <div class="flex items-center justify-between gap-3">
                            <span class="font-medium">{{ $notification->data['title'] ?? 'Notification' }}</span>
                            <span class="shrink-0 text-xs text-gray-500">{{ $notification->created_at->diffForHumans() }}</span>
                        </div>
                        <div class="text-sm text-gray-600 dark:text-gray-300">{{ $notification->data['message'] ?? '' }}</div>
                        <div class="mt-0.5 font-mono text-xs text-indigo-600 dark:text-indigo-400">{{ $notification->data['ticket_number'] ?? '' }}</div>
                    </button>
                </form>
            @empty
                <p class="px-4 py-10 text-center text-gray-500">You have no notifications.</p>
            @endforelse
        </x-ui.card>

        <div>{{ $notifications->links() }}</div>
    </div>
</x-app-layout>
