<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 05.2 §5.1, §17: UK VAT number, `GB` — or `XI` for Northern Ireland,
 * the same VRN under the NI prefix (02 §25.4) — + 9 or 12 digits,
 * checksum validated.
 * HMRC's check: weight the first seven digits 8..2, add the two check
 * digits; the total is valid if divisible by 97 (the original scheme)
 * or if it is divisible by 97 after adding 55 (the 9755 scheme). A
 * 12-digit number is a 9-digit number plus a 3-digit branch suffix.
 */
final class UkVatNumber implements ValidationRule
{
    /** Examples shown to users. Both pass the checksum, so copying one works. */
    public const EXAMPLE_GB = 'GB123456782';

    public const EXAMPLE_XI = 'XI123456782';

    public static function normalise(string $value): string
    {
        return strtoupper(preg_replace('/[\s.\-]/', '', $value) ?? '');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $vat = self::normalise((string) $value);

        if (preg_match('/^(?:GB|XI)(\d{9})(\d{3})?$/', $vat, $m) !== 1 || ! self::checksumValid($m[1])) {
            $fail('Enter a valid UK VAT number, e.g. '.self::EXAMPLE_GB.', or '.self::EXAMPLE_XI.' in Northern Ireland.');
        }
    }

    private static function checksumValid(string $nine): bool
    {
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += (int) $nine[$i] * (8 - $i);
        }
        $sum += (int) substr($nine, 7, 2);

        return $sum % 97 === 0 || ($sum + 55) % 97 === 0;
    }
}
