<?php

namespace App\Rules;

use App\Support\Iin;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidIin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $v = (string) $value;
        if (! preg_match('/^\d{12}$/', $v)) {
            $fail('ИИН должен состоять из 12 цифр');

            return;
        }
        if (! Iin::hasValidChecksum($v)) {
            $fail('ИИН не прошёл проверку контрольной суммы, проверьте цифры');

            return;
        }
        if (Iin::birthDate($v) === null) {
            $fail('В ИИН неверная дата рождения или признак века');
        }
    }
}
