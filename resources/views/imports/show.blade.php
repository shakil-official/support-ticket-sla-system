<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Import: {{ $batch->original_name }}</h1>
            <div class="flex gap-2">
                <x-ui.button :href="route('imports.index')" variant="secondary">All imports</x-ui.button>
                <x-ui.button :href="route('imports.errors', $batch)" variant="secondary" id="errors-link">Download error report</x-ui.button>
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <x-ui.card class="p-6" id="progress-card" data-url="{{ route('imports.progress', $batch) }}" data-finished="{{ $batch->isFinished() ? '1' : '0' }}">
            <div class="mb-2 flex items-center justify-between text-sm">
                <span id="status" class="font-semibold">{{ ucfirst($batch->status) }}</span>
                <span id="percent">{{ $batch->percent() }}%</span>
            </div>
            <div class="h-3 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                <div id="bar" class="h-3 rounded-full bg-indigo-600 transition-all" style="width: {{ $batch->percent() }}%"></div>
            </div>

            <dl class="mt-6 grid grid-cols-2 gap-4 text-center sm:grid-cols-5">
                @foreach (['total' => $batch->total_rows, 'processed' => $batch->processed_rows, 'imported' => $batch->imported_rows, 'skipped' => $batch->skipped_rows, 'failed' => $batch->failed_rows] as $key => $value)
                    <div>
                        <dt class="text-xs uppercase text-gray-500">{{ $key }}</dt>
                        <dd id="n-{{ $key }}" class="text-2xl font-semibold">{{ number_format($value) }}</dd>
                    </div>
                @endforeach
            </dl>

            <p id="message" class="mt-4 text-sm text-red-600">{{ $batch->failure_message }}</p>
        </x-ui.card>

        <x-ui.card class="overflow-hidden">
            <div class="border-b border-gray-200 px-4 py-3 text-sm font-semibold dark:border-gray-700">
                Problems (first 100 rows; the full list is in the error report)
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-700/50">
                        <tr><th class="px-4 py-2">Row</th><th class="px-3 py-2">Problem</th><th class="px-3 py-2">Data</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($problems as $problem)
                            <tr>
                                <td class="px-4 py-2 font-mono">{{ $problem->row_number }}</td>
                                <td class="px-3 py-2">{{ $problem->message }}</td>
                                <td class="max-w-md truncate px-3 py-2 font-mono text-xs text-gray-500" title="{{ json_encode($problem->raw) }}">{{ json_encode($problem->raw) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-8 text-center text-gray-500">No problems recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>

    {{-- Polls the progress endpoint every 2 s until the batch finishes, then reloads once to show the error list. --}}
    <script>
        (() => {
            const card = document.getElementById('progress-card');
            if (card.dataset.finished === '1') return;

            const set = (id, text) => { document.getElementById(id).textContent = text; };
            const tick = async () => {
                try {
                    const res = await fetch(card.dataset.url, { headers: { Accept: 'application/json' } });
                    if (! res.ok) throw new Error(res.status);
                    const d = await res.json();

                    document.getElementById('bar').style.width = d.percent + '%';
                    set('percent', d.percent + '%');
                    set('status', d.status.charAt(0).toUpperCase() + d.status.slice(1));
                    ['total', 'processed', 'imported', 'skipped', 'failed'].forEach((k) => set('n-' + k, Number(d[k]).toLocaleString()));
                    set('message', d.message || '');

                    if (d.finished) { window.location.reload(); return; }
                } catch (e) { /* transient error: try again */ }
                setTimeout(tick, 2000);
            };
            setTimeout(tick, 1000);
        })();
    </script>
</x-app-layout>
