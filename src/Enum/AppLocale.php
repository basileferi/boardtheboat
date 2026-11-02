<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The locales the interface is translated into. Also the values accepted in the
 * {_locale} route prefix and stored on User::$locale.
 */
enum AppLocale: string
{
    case French = 'fr';
    case English = 'en';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
