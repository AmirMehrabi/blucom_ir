<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class NumberNormalizer
{
    /**
     * Normalize a phone number to canonical E.164 (+<digits>).
     *
     * Accepts forms such as 982191093464, 00982191093464, +982191093464,
     * 02191093464, and locally formatted numbers.
     */
    public function normalize(string $input): ?string
    {
        $input = strtr($input, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $clean = preg_replace('/[\s\-().]/', '', trim($input));

        if ($clean === null || $clean === '' || ! preg_match('/^\+?\d+$/', $clean)) {
            return null;
        }

        if (str_starts_with($clean, '+')) {
            $digits = substr($clean, 1);
        } elseif (str_starts_with($clean, '00')) {
            $digits = substr($clean, 2);
        } elseif (str_starts_with($clean, '0')) {
            $country = (string) config('voip.country_code', '98');
            $digits = $country.substr($clean, 1);
        } else {
            $digits = $clean;
        }

        if (strlen($digits) < 4 || strlen($digits) > 15) {
            return null;
        }

        return '+'.$digits;
    }

    /**
     * Variants of a normalized number that FreeSWITCH may present as destination_number.
     *
     * @return list<string>
     */
    public function destinationPatterns(string $normalized): array
    {
        if (! str_starts_with($normalized, '+')) {
            return [$normalized];
        }

        $digits = substr($normalized, 1);

        return [$digits, $normalized, '00'.$digits];
    }

    /**
     * Build a dialplan regex matching every accepted presentation of the number.
     */
    public function destinationExpression(string $normalized): string
    {
        $patterns = array_map(
            static fn (string $variant): string => preg_quote($variant, '/'),
            $this->destinationPatterns($normalized),
        );

        return '^('.implode('|', $patterns).')$';
    }

    /**
     * @throws ValidationException
     */
    public function normalizeOrFail(string $input, string $field = 'number'): string
    {
        $normalized = $this->normalize($input);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                $field => 'شماره معتبر نیست.',
            ]);
        }

        return $normalized;
    }
}
