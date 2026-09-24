<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OutboundRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return $this->isMethod('post') ? [
            'sip_number_id' => ['required', 'integer'],
            'sip_extension_id' => ['required', 'integer'],
            'gateway_id' => ['required', 'integer'],
            'enabled' => ['sometimes', 'boolean'],
        ] : [
            'enabled' => ['sometimes', 'boolean'],
            'gateway_id' => ['sometimes', 'integer'],
            'sip_number_id' => ['sometimes', 'integer'],
        ];
    }
}
