<?php

namespace App\Services;

class PhoneNumberNormalizer
{
    /**
     * Normalize a Malaysian phone number to the consistent +60XXXXXXXXX format.
     */
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = '60'.substr($digits, 1);
        } elseif (! str_starts_with($digits, '60')) {
            $digits = '60'.$digits;
        }

        return '+'.$digits;
    }
}
