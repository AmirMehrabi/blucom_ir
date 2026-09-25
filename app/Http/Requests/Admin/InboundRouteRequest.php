<?php

namespace App\Http\Requests\Admin;

use App\Models\InboundRoute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'destination_type' => ['sometimes', Rule::in(array_keys(InboundRoute::availableDestinations()))],
            'destination_id' => ['required', 'integer'],
            'enabled' => ['sometimes', 'boolean'],
        ] : [
            'enabled' => ['sometimes', 'boolean'],
            'destination_type' => ['sometimes', Rule::in(array_keys(InboundRoute::availableDestinations()))],
            'destination_id' => ['sometimes', 'integer'],
        ];
    }
}
