<?php

namespace App\Http\Requests\Users;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Reset an employee's password on their behalf (Owner/Admin action). The target
 * must be one the actor may manage (enforced by the UserPolicy in the
 * controller). No current-password confirmation is required — this is an
 * administrative reset, not the user changing their own password.
 */
class ResetUserPasswordRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
