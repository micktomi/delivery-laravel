<?php

namespace App\Services;

/**
 * Renders/cleans a selected-options snapshot (cart line or order item) using
 * only the keys already present in the snapshot — never a live product or
 * option-group lookup, and never a group/option name or business vocabulary.
 * That is what lets an already-placed order keep rendering exactly as it did
 * even if the catalogue changes or a group is renamed later.
 *
 * A group can declare, via option-group metadata copied into each entry at
 * capture time, that it is irrelevant once a specific value is picked
 * elsewhere (hidden_when_option_value_id) and/or that its own pick should be
 * folded into another group's displayed phrase instead of listed on its own
 * (combine_display_with_option_group_id). Entries without these keys — every
 * group that never opts in, and any snapshot captured before this existed —
 * behave exactly as a plain, unlinked option always has.
 *
 * @phpstan-type SelectedOption array{
 *     group?: string,
 *     value?: string,
 *     price_delta?: float,
 *     option_value_id?: int,
 *     option_group_id?: int,
 *     is_default_value?: bool,
 *     hidden_when_option_value_id?: int|null,
 *     combine_display_with_option_group_id?: int|null,
 * }
 */
class OptionsPresenter
{
    /**
     * Drops any entry whose own hidden_when_option_value_id names a value
     * that is also present (by option_value_id) elsewhere in the same
     * snapshot — e.g. so "plain + a sweetener pick" can never survive into a
     * stored cart/order snapshot, regardless of what the client submitted.
     *
     * @param  array<int, array<string, mixed>>  $selectedOptions
     * @return array<int, array<string, mixed>>
     */
    public static function canonicalize(array $selectedOptions): array
    {
        $selectedValueIds = array_values(array_filter(
            array_column($selectedOptions, 'option_value_id'),
            fn (mixed $id): bool => $id !== null,
        ));

        return array_values(array_filter(
            $selectedOptions,
            function (array $option) use ($selectedValueIds): bool {
                $gateValueId = $option['hidden_when_option_value_id'] ?? null;

                return $gateValueId === null || ! in_array((int) $gateValueId, $selectedValueIds, true);
            },
        ));
    }

    /**
     * Single source of truth for rendering a selected-options snapshot: an
     * entry that combines into another group's phrase is folded in ("Μέτριος
     * με Στέβια") and dropped from its own default value, and every other
     * option is joined exactly as before.
     *
     * @param  array<int, array<string, mixed>>  $selectedOptions
     */
    public static function format(array $selectedOptions): string
    {
        $options = self::canonicalize($selectedOptions);

        $selectedGroupIds = array_values(array_filter(
            array_column($options, 'option_group_id'),
            fn (mixed $id): bool => $id !== null,
        ));

        // First pass: work out which entries fold into a host group's phrase,
        // and what (if anything) they contribute to it.
        $modifierByHostGroupId = [];
        $foldedGroupIds = [];

        foreach ($options as $option) {
            $combineTargetId = $option['combine_display_with_option_group_id'] ?? null;
            $groupId = $option['option_group_id'] ?? null;
            $value = $option['value'] ?? null;

            if ($combineTargetId === null || $groupId === null || $value === null || $value === '') {
                continue;
            }

            if (! in_array((int) $combineTargetId, $selectedGroupIds, true)) {
                continue; // Host group has no selection of its own: show standalone below.
            }

            $foldedGroupIds[] = $groupId;

            if (! ($option['is_default_value'] ?? false)) {
                $modifierByHostGroupId[(int) $combineTargetId] = $value;
            }
        }

        // Second pass: emit every entry once, folded entries skipped and
        // host entries carrying their modifier's value.
        $parts = [];

        foreach ($options as $option) {
            $groupId = $option['option_group_id'] ?? null;
            $value = $option['value'] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if ($groupId !== null && in_array($groupId, $foldedGroupIds, true)) {
                continue;
            }

            if ($groupId !== null && isset($modifierByHostGroupId[$groupId])) {
                $value .= ' με '.$modifierByHostGroupId[$groupId];
            }

            $parts[] = $value;
        }

        return implode(' · ', $parts);
    }
}
