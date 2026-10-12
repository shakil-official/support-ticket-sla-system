<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">New ticket</h1>
    </x-slot>

    <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
        <x-ui.card class="p-6">
            <form method="POST" action="{{ route('tickets.store') }}" enctype="multipart/form-data">
                @csrf
                @include('tickets._form')

                <div class="mt-6 flex gap-3">
                    <x-ui.button type="submit">Create ticket</x-ui.button>
                    <x-ui.button :href="route('tickets.index')" variant="secondary">Cancel</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-app-layout>
