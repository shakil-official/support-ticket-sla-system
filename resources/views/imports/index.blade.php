<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">CSV import</h1>
            <x-ui.button :href="route('imports.template')" variant="secondary">Download template</x-ui.button>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <x-ui.card class="p-6">
            <h2 class="mb-1 text-lg font-semibold">Upload a file</h2>
            <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                The file is stored, then processed in the background in chunks of {{ config('tickets.import.chunk_size') }} rows,
                so large files never exhaust memory and the page never blocks. Invalid and duplicate rows are skipped and reported.
            </p>

            <form method="POST" action="{{ route('imports.store') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="min-w-64 flex-1">
                    <x-ui.label for="file">CSV file (max {{ (int) (config('tickets.import.max_kb') / 1024) }} MB)</x-ui.label>
                    <x-ui.input id="file" type="file" name="file" accept=".csv,.txt" required />
                    <x-ui.error for="file" />
                </div>
                <x-ui.button type="submit">Start import</x-ui.button>
            </form>

            <div class="mt-6 text-sm">
                <h3 class="mb-2 font-semibold">Columns</h3>
                <ul class="flex flex-wrap gap-2">
                    @foreach ($columns as $column)
                        <li class="rounded bg-gray-100 px-2 py-1 font-mono text-xs dark:bg-gray-700">
                            {{ $column }}@if (in_array($column, $required, true)) <span class="text-red-600">*</span>@endif
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-gray-600 dark:text-gray-400">
                    <span class="text-red-600">*</span> required. <code>external_id</code> must be unique: a row whose
                    external_id already exists is skipped as a duplicate (so re-uploading the same file is safe).
                </p>
            </div>
        </x-ui.card>

        <x-ui.card class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-700/50 dark:text-gray-300">
                        <tr>
                            <th class="px-4 py-3">File</th>
                            <th class="px-3 py-3">Status</th>
                            <th class="px-3 py-3">Rows</th>
                            <th class="px-3 py-3">Imported</th>
                            <th class="px-3 py-3">Skipped</th>
                            <th class="px-3 py-3">Failed</th>
                            <th class="px-3 py-3">By</th>
                            <th class="px-3 py-3">Uploaded</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($batches as $batch)
                            <tr>
                                <td class="px-4 py-3"><a class="text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('imports.show', $batch) }}">{{ $batch->original_name }}</a></td>
                                <td class="px-3 py-3">{{ ucfirst($batch->status) }}</td>
                                <td class="px-3 py-3">{{ number_format($batch->total_rows) }}</td>
                                <td class="px-3 py-3">{{ number_format($batch->imported_rows) }}</td>
                                <td class="px-3 py-3">{{ number_format($batch->skipped_rows) }}</td>
                                <td class="px-3 py-3">{{ number_format($batch->failed_rows) }}</td>
                                <td class="px-3 py-3">{{ $batch->user?->name }}</td>
                                <td class="whitespace-nowrap px-3 py-3"><x-ui.datetime :value="$batch->created_at" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-10 text-center text-gray-500">No imports yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <div>{{ $batches->links() }}</div>
    </div>
</x-app-layout>
