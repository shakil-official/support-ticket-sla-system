<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Edit <span class="font-mono text-base">{{ $ticket->ticket_number }}</span></h1>
    </x-slot>

    <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
        <x-ui.card class="p-6">
            <form method="POST" action="{{ route('tickets.update', $ticket) }}">
                @csrf
                @method('PUT')
                @include('tickets._form')

                <div class="mt-6 flex gap-3">
                    <x-ui.button type="submit">Save changes</x-ui.button>
                    <x-ui.button :href="route('tickets.show', $ticket)" variant="secondary">Cancel</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-app-layout>
