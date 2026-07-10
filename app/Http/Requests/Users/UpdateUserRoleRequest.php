<?php

namespace App\Http\Requests\Users;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change an existing employee's role. Only the role is mutable here — the name
 * is immutable, and the target must be one the actor may manage (enforced by the
 * UserPolicy in the controller). The new role is constrained to those the actor
 * is allowed to assign.
 */
class UpdateUserRoleRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in($this->assignableRoleValues())],
        ];
    }

    /**
     * @return list<string>
     */
    private function assignableRoleValues(): array
    {
        return array_map(
            fn ($role) => $role->value,
            $this->user()->role->assignableRoles(),
        );
    }
}
