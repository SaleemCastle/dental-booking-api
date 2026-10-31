<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProvisionTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The platform middleware authorizes before validation.
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'branding' => ['sometimes', 'array:display_name,logo_url,primary_color'],
            'branding.display_name' => ['nullable', 'string', 'max:255'],
            'branding.logo_url' => ['nullable', 'url:https', 'max:2048'],
            'branding.primary_color' => ['nullable', 'regex:/\A#[0-9A-Fa-f]{6}\z/'],
            'admin' => ['required', 'array:name,email,password'],
            'admin.name' => ['required', 'string', 'max:255'],
            'admin.email' => ['required', 'email', 'max:255'],
            'admin.password' => ['required', 'string', 'min:12', 'max:72'],
        ];
    }
}
