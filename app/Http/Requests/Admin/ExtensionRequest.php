<?php

namespace App\Http\Requests\Admin;

use App\Services\BlucomOwner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        if ($this->isMethod('post')) {
            return [
                'extension' => ['required', 'string', 'regex:/^[1-9]\\d{2,8}$/', 'max:10', Rule::unique('sip_extensions', 'extension')->where('tenant_id', app(BlucomOwner::class)->get()->id)],
                'display_name' => ['nullable', 'string', 'max:100'],
                'password' => ['nullable', 'string', 'min:8', 'max:64'],
            ];
        }

        return [
            'enabled' => ['sometimes', 'boolean'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'min:8', 'max:64'],
            'generate_password' => ['sometimes', 'boolean'],
        ];
    }
}
