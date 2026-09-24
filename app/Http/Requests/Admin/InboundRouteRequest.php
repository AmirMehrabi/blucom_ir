<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class InboundRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return $this->isMethod('post') ? [
            'sip_number_id' => ['required', 'integer'],
            'destination_id' => ['required', 'integer'],
            'enabled' => ['sometimes', 'boolean'],
        ] : [
            'enabled' => ['sometimes', 'boolean'],
            'destination_id' => ['sometimes', 'integer'],
        ];
    }
}
