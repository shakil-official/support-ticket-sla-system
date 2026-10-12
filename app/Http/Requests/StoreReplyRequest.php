<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAttachments;
use Illuminate\Foundation\Http\FormRequest;

class StoreReplyRequest extends FormRequest
{
    use ValidatesAttachments;

    public function authorize(): bool
    {
        $ability = $this->boolean('is_internal') ? 'addInternalNote' : 'reply';

        return $this->user()->can($ability, $this->route('ticket'));
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:10000'],
            'is_internal' => ['sometimes', 'boolean'],
        ] + $this->attachmentRules();
    }
}
