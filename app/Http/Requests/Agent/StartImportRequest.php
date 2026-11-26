<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-settings') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mailbox_id' => [
                'required',
                'integer',
                Rule::exists('mailboxes', 'id')->where('workspace_id', $this->user()->workspace_id),
            ],
            'months' => ['required', 'integer', 'min:1', 'max:120'],
            'dry_run' => ['boolean'],
            'include_unanswered' => ['boolean'],
        ];
    }
}
