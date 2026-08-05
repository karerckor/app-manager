<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AppTelemetryEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['event' => ['nullable', 'uuid']];
    }

    public function eventId(): ?string
    {
        return $this->validated('event');
    }
}
