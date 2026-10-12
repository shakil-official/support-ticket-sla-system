<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Support Desk') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-gray-100 font-sans antialiased dark:bg-gray-900">
        <div class="flex min-h-screen flex-col items-center justify-center px-4 text-center">
            <h1 class="text-4xl font-bold text-gray-900 dark:text-white">{{ config('app.name', 'Support Desk') }}</h1>
            <p class="mt-3 max-w-xl text-gray-600 dark:text-gray-300">
                Support tickets with SLA tracking, business-hours deadlines, automatic assignment and escalation.
            </p>

            <div class="mt-8 flex gap-3">
                @auth
                    <a href="{{ route('dashboard') }}" class="rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500">Open dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500">Log in</a>
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="rounded-md bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-600">Register as customer</a>
                    @endif
                @endauth
            </div>
        </div>
    </body>
</html>
