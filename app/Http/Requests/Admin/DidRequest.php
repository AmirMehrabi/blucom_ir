<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        if ($this->isMethod('post')) {
            return [
                'number' => ['required', 'string', 'max:32'],
                'label' => ['nullable', 'string', 'max:255'],
                'enabled' => ['required', 'boolean'],
                'inbound_enabled' => ['required', 'boolean'],
                'outbound_enabled' => ['required', 'boolean'],
            ];
        }

        return [
            'label' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'inbound_enabled' => ['sometimes', 'boolean'],
            'outbound_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
