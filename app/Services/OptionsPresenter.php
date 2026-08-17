<?php

namespace App\Services;

class OptionsPresenter
{
    /**
     * These four names are the coffee catalogue's sweetness/sweetener pair
     * (see OptionGroupSeeder). They are matched by name — group/value ids are
     * a database implementation detail — and are the only coffee-specific
     * special case here; nothing else about the option system depends on them.
     */
    private const SWEETNESS_GROUP = 'Ζάχαρη';

    private const SWEETENER_GROUP = 'Γλυκαντικό';

    private const PLAIN_VALUE = 'Σκέτος';

    private const DEFAULT_SWEETENER_VALUE = 'Ζάχαρη';

    /**
     * Once the coffee is plain, any Γλυκαντικό pick is meaningless — drop it
     * so "Σκέτος + Στέβια" can never reach a stored cart/order snapshot,
     * regardless of what the client submitted.
     *
     * @param  array<int, array{group?: string, value?: string}>  $selectedOptions
     * @return array<int, array{group?: string, value?: string}>
     */
    public static function canonicalize(array $selectedOptions): array
    {
        $isPlain = collect($selectedOptions)->contains(
            fn (array $option) => ($option['group'] ?? null) === self::SWEETNESS_GROUP
                && ($option['value'] ?? null) === self::PLAIN_VALUE
        );

        if (! $isPlain) {
            return $selectedOptions;
        }

        return array_values(array_filter(
            $selectedOptions,
            fn (array $option) => ($option['group'] ?? null) !== self::SWEETENER_GROUP
        ));
    }

    /**
     * Single source of truth for rendering a selected-options snapshot:
     * sweetness and sweetener collapse into one phrase ("Μέτριος με Στέβια"),
     * the redundant default sweetener ("... · Ζάχαρη") is dropped, and every
     * other option is joined exactly as before.
     *
     * @param  array<int, array{group?: string, value?: string}>  $selectedOptions
     */
    public static function format(array $selectedOptions): string
    {
        $options = self::canonicalize($selectedOptions);

        $hasSweetness = collect($options)->contains('group', self::SWEETNESS_GROUP);
        $sweetenerValue = collect($options)->firstWhere('group', self::SWEETENER_GROUP)['value'] ?? null;

        $parts = [];

        foreach ($options as $option) {
            $group = $option['group'] ?? null;
            $value = $option['value'] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if ($hasSweetness && $group === self::SWEETENER_GROUP) {
                // Folded into the sweetness entry below instead of listed on its own.
                continue;
            }

            if ($group === self::SWEETNESS_GROUP
                && $sweetenerValue !== null
                && $sweetenerValue !== self::DEFAULT_SWEETENER_VALUE) {
                $value .= ' με '.$sweetenerValue;
            }

            $parts[] = $value;
        }

        return implode(' · ', $parts);
    }
}
