<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class CustomerMobile
{
    public static function normalize(string $mobile): string
    {
        $value = strtr($mobile, array_combine(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ));
        $value = preg_replace('/[\s\-()]/', '', $value);
        if (str_starts_with($value, '00989')) {
            $value = '+'.substr($value, 2);
        } elseif (str_starts_with($value, '09')) {
            $value = '+98'.substr($value, 1);
        } elseif (str_starts_with($value, '989')) {
            $value = '+'.$value;
        }

        if (! preg_match('/^\+989\d{9}$/', $value)) {
            throw ValidationException::withMessages(['mobile' => 'شماره موبایل معتبر نیست.']);
        }

        return $value;
    }
}
