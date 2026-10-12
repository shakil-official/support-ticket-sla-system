{{-- Download links always go through AttachmentController (policy-checked); files are not publicly reachable. --}}
<ul class="space-y-1 text-sm">
    @foreach ($attachments as $attachment)
        <li class="flex items-center gap-2">
            <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13"/></svg>
            <a href="{{ route('attachments.download', $attachment) }}" class="truncate text-indigo-600 hover:underline dark:text-indigo-400">{{ $attachment->original_name }}</a>
            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $attachment->humanSize() }}</span>
        </li>
    @endforeach
</ul>
