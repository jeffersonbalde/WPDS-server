<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A grading-period value: a number with up to two decimals, or "NG" (No Grade).
 */
class PeriodGrade implements ValidationRule
{
    public function __construct(private string $label = 'Grade') {}

    public static function isNoGrade(mixed $value): bool
    {
        return is_string($value) && strtoupper(trim($value)) === 'NG';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::isNoGrade($value)) {
            return;
        }

        if (! is_numeric($value) || ! preg_match('/^\d+(\.\d{1,2})?$/', (string) $value)) {
            $fail("{$this->label} must be a valid grade number or NG.");
        }
    }
}
