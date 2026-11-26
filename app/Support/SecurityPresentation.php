<?php

namespace App\Support;

class SecurityPresentation
{
    public static function country(?string $code): string
    {
        if (! $code || ! preg_match('/^[A-Z]{2}$/D', $code)) {
            return __('security.unknown');
        }
        try {
            if (class_exists(\ResourceBundle::class)) {
                $bundle = new \ResourceBundle(app()->getLocale(), 'ICUDATA-region');
                $countries = $bundle->get('Countries');
                $name = $countries instanceof \ResourceBundle ? $countries->get($code) : null;
                if (is_string($name) && $name !== '') {
                    return $name;
                }
            }
        } catch (\Throwable) {
            // The ISO code remains useful if this ICU build lacks region names.
        }

        return $code;
    }
}
