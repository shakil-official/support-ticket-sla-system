<?php

namespace App\Http\Requests\Concerns;

trait ValidatesAttachments
{
    /**
     * Limits come from config/tickets.php. `mimes` checks the type detected from the file
     * CONTENT (not the client supplied extension); only the whitelisted types are accepted,
     * so executables/scripts can never be uploaded.
     *
     * @return array<string, list<string>>
     */
    protected function attachmentRules(): array
    {
        $config = config('tickets.attachments');

        return [
            'attachments' => ['nullable', 'array', 'max:'.$config['max_files']],
            'attachments.*' => ['file', 'max:'.$config['max_kb'], 'mimes:'.implode(',', $config['mimes'])],
        ];
    }
}
