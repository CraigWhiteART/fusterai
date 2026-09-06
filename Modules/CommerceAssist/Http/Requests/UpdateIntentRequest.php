<?php

namespace Modules\CommerceAssist\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rules' => ['required', 'string', 'max:8000'],
            'always_human' => ['boolean'],
            'auto_send_allowed' => ['boolean'],
        ];
    }
}
