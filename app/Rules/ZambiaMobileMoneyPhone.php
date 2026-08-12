<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ZambiaMobileMoneyPhone implements ValidationRule
{
    public function __construct(private readonly string $operator) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('Please enter a valid phone number.');

            return;
        }

        $local = $this->localDigits($value);

        if (strlen($local) !== 9) {
            $fail('Please enter a valid Zambian mobile number.');

            return;
        }

        $prefix = substr($local, 0, 2);
        $operator = strtolower($this->operator);

        $validPrefixes = match ($operator) {
            'mtn' => ['96', '76'],
            'airtel' => ['97', '77', '57'],
            default => [],
        };

        if ($validPrefixes !== [] && ! in_array($prefix, $validPrefixes, true)) {
            $label = ucfirst($operator);
            $fail("This number does not match {$label}. Check the network you selected.");
        }
    }

    private function localDigits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '260')) {
            return substr($digits, 3);
        }

        if (str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }

        return $digits;
    }
}
