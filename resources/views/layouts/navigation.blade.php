@php
    $authUser = auth()->user();
    $unread = $authUser->unreadNotifications()->count();
    $resolvedTab = request('view') === 'resolved';

    // [label, url, active?, visible?]
    $links = [
        ['Dashboard', route('dashboard'), request()->routeIs('dashboard'), true],
        ['Tickets', route('tickets.index'), request()->routeIs('tickets.index') && ! $resolvedTab, true],
        ['Resolved', route('tickets.index', ['view' => 'resolved']), request()->routeIs('tickets.index') && $resolvedTab, true],
        ['New ticket', route('tickets.create'), request()->routeIs('tickets.create'), true],
        ['Import', route('imports.index'), request()->routeIs('imports.*'), $authUser->can('import-tickets')],
        ['SLA settings', route('admin.settings.edit'), request()->routeIs('admin.settings.*'), $authUser->can('manage-settings')],
        ['Team', route('admin.users.index'), request()->routeIs('admin.users.*'), $authUser->can('manage-users')],
    ];
@endphp

<nav x-data="{ open: false }" class="border-b border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between">
            <div class="flex items-center gap-6">
                <a href="{{ route('dashboard') }}" class="text-lg font-bold text-indigo-600 dark:text-indigo-400">{{ config('app.name', 'Support Desk') }}</a>

                <div class="hidden items-center gap-1 lg:flex">
                    @foreach ($links as [$label, $url, $active, $visible])
                        @if ($visible)
                            <a href="{{ $url }}"
                               @class([
                                   'rounded-md px-3 py-2 text-sm font-medium',
                                   'bg-indigo-50 text-indigo-700 dark:bg-gray-700 dark:text-white' => $active,
                                   'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => ! $active,
                               ])>{{ $label }}</a>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="flex items-center gap-3">
                <a href="{{ route('notifications.index') }}" class="relative rounded-full p-2 text-gray-500 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" aria-label="Notifications">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>
                    @if ($unread > 0)
                        <span class="absolute -right-0.5 -top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-xs font-semibold text-white">{{ $unread > 99 ? '99+' : $unread }}</span>
                    @endif
                </a>

                <div class="relative hidden sm:block" x-data="{ menu: false }" @click.outside="menu = false">
                    <button type="button" @click="menu = !menu" class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                        <span>{{ $authUser->name }}</span>
                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $authUser->role->label() }}</span>
                    </button>
                    <div x-show="menu" x-cloak class="absolute right-0 z-20 mt-2 w-48 rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5 dark:bg-gray-700">
                        <a href="{{ route('profile.edit') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600">Profile</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600">Log out</button>
                        </form>
                    </div>
                </div>

                <button type="button" @click="open = !open" class="rounded-md p-2 text-gray-500 hover:bg-gray-100 lg:hidden dark:text-gray-300 dark:hover:bg-gray-700" aria-label="Menu">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                </button>
            </div>
        </div>
    </div>

    {{-- Mobile menu --}}
    <div x-show="open" x-cloak class="border-t border-gray-200 lg:hidden dark:border-gray-700">
        <div class="space-y-1 px-4 py-3">
            @foreach ($links as [$label, $url, $active, $visible])
                @if ($visible)
                    <a href="{{ $url }}" @class([
                        'block rounded-md px-3 py-2 text-base font-medium',
                        'bg-indigo-50 text-indigo-700 dark:bg-gray-700 dark:text-white' => $active,
                        'text-gray-600 dark:text-gray-300' => ! $active,
                    ])>{{ $label }}</a>
                @endif
            @endforeach
        </div>
        <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-700">
            <div class="text-sm font-medium">{{ $authUser->name }} <span class="text-gray-500">({{ $authUser->role->label() }})</span></div>
            <div class="mt-2 space-y-1">
                <a href="{{ route('profile.edit') }}" class="block rounded-md px-3 py-2 text-base text-gray-600 dark:text-gray-300">Profile</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="block w-full rounded-md px-3 py-2 text-left text-base text-gray-600 dark:text-gray-300">Log out</button>
                </form>
            </div>
        </div>
    </div>
</nav>

<style>[x-cloak] { display: none !important; }</style>
