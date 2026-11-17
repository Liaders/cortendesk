<?php

namespace App\Rules;

use App\Support\OrganizationIdentity;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class OrganizationInn implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! OrganizationIdentity::validInn($value)) {
            $fail(__('organization_import.inn_invalid'));
        }
    }
}
