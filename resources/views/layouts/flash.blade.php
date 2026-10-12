{{-- Flash messages. Breeze reuses the "status" key for its own inline messages (profile page), so those are skipped here. --}}
@php
    $status = session('status');
    $breezeKeys = ['profile-updated', 'password-updated', 'verification-link-sent'];
@endphp

<div class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
    @if ($status && ! in_array($status, $breezeKeys, true))
        <div role="status" class="mb-2 rounded-md bg-green-50 p-3 text-sm text-green-800 ring-1 ring-green-200 dark:bg-green-900/40 dark:text-green-200 dark:ring-green-800">{{ $status }}</div>
    @endif

    @if (session('error'))
        <div role="alert" class="mb-2 rounded-md bg-red-50 p-3 text-sm text-red-800 ring-1 ring-red-200 dark:bg-red-900/40 dark:text-red-200 dark:ring-red-800">{{ session('error') }}</div>
    @endif
</div>
