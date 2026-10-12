{{--
    Shared by create and edit.
    Customers may only edit subject + description of an existing ticket; priority/category are
    staff-only on edit (the UpdateTicketRequest ignores them for customers anyway).
--}}
@php
    $editing = isset($ticket);
    $showMeta = ! $editing || auth()->user()->isStaff();
    $maxKb = config('tickets.attachments.max_kb');
    $maxFiles = config('tickets.attachments.max_files');
    $mimes = implode(', ', config('tickets.attachments.mimes'));
@endphp

<div class="space-y-5">
    <div>
        <x-ui.label for="subject">Subject</x-ui.label>
        <x-ui.input id="subject" name="subject" :value="old('subject', $ticket->subject ?? '')" required maxlength="255" />
        <x-ui.error for="subject" />
    </div>

    <div>
        <x-ui.label for="description">Description</x-ui.label>
        <x-ui.textarea id="description" name="description" rows="8" required maxlength="10000">{{ old('description', $ticket->description ?? '') }}</x-ui.textarea>
        <x-ui.error for="description" />
    </div>

    @if ($showMeta)
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <x-ui.label for="priority">Priority</x-ui.label>
                <x-ui.select id="priority" name="priority" required>
                    @foreach ($priorities as $priority)
                        <option value="{{ $priority->value }}" @selected(old('priority', ($ticket->priority ?? \App\Enums\TicketPriority::Medium)->value) === $priority->value)>{{ $priority->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.error for="priority" />
                @if ($editing)
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Changing the priority recalculates the SLA deadline.</p>
                @endif
            </div>

            <div>
                <x-ui.label for="category_id">Category</x-ui.label>
                <x-ui.select id="category_id" name="category_id" required>
                    <option value="">Select a category…</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) old('category_id', $ticket->category_id ?? '') === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.error for="category_id" />
            </div>
        </div>
    @endif

    @unless ($editing)
        <div>
            <x-ui.label for="attachments">Attachments (optional)</x-ui.label>
            <input id="attachments" type="file" name="attachments[]" multiple class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:text-gray-200 dark:file:bg-gray-700">
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Up to {{ $maxFiles }} files, {{ number_format($maxKb / 1024) }} MB each. Allowed: {{ $mimes }}.</p>
            <x-ui.error for="attachments" />
            @foreach ($errors->get('attachments.*') as $messages)
                @foreach ($messages as $message)
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @endforeach
            @endforeach
        </div>
    @endunless
</div>
