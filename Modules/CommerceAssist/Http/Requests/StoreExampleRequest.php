<?php

namespace Modules\CommerceAssist\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_message' => ['required', 'string', 'max:20000'],
            'final_reply' => ['required', 'string', 'max:20000'],
            'intent' => ['nullable', 'string', 'max:80'],
            'subtype' => ['nullable', 'string', 'max:80'],
            'sentiment' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:80'],
        ];
    }
}
