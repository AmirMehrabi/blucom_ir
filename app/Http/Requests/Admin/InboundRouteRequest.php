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
            'destination_choice' => ['sometimes', 'string', 'regex:/^(extension|queue):[1-9][0-9]*$/'],
            'destination_type' => ['sometimes', Rule::in(array_keys(InboundRoute::availableDestinations()))],
            'destination_id' => ['required_without:destination_choice', 'integer'],
            'enabled' => ['sometimes', 'boolean'],
            'destination_choice' => ['sometimes', 'string', 'regex:/^(extension|queue):[1-9][0-9]*$/'],
        ] : [
            'enabled' => ['sometimes', 'boolean'],
            'destination_type' => ['sometimes', Rule::in(array_keys(InboundRoute::availableDestinations()))],
            'destination_id' => ['sometimes', 'integer'],
        ];
    }
}
