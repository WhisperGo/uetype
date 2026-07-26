<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/** Catalog of clan emblem icons and colors; the source of truth for badges. */
final class ClanEmblem
{
    public const DEFAULT_ICON = 'shield';

    public const DEFAULT_COLOR = 'gold';

    private const ICONS = [
        'shield' => 'M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6l7-3z',
        'bolt' => 'M13 2L4.5 13.5H11l-1 8.5L19.5 10H13l0-8z',
        'flame' => 'M12 2c1.5 3 4.5 4.5 4.5 8.5A4.5 4.5 0 0112 15a4.5 4.5 0 01-4.5-4.5C7.5 8 9 6.5 9 4.5 10 5.5 11 3.5 12 2z',
        'crown' => 'M4 8l3.5 3L12 5l4.5 6L20 8l-1.5 10h-13L4 8z',
        'star' => 'M12 3l2.6 5.6 6 .8-4.4 4.2 1.1 6L12 17l-5.3 2.6 1.1-6L3.4 9.4l6-.8L12 3z',
        'skull' => 'M12 3a8 8 0 00-5 14v3h3v-2h4v2h3v-3a8 8 0 00-5-14zm-3 8a1.5 1.5 0 110 3 1.5 1.5 0 010-3zm6 0a1.5 1.5 0 110 3 1.5 1.5 0 010-3z',
        'sword' => 'M14 3l7 7-3 1-1 3-7-7 1-3 3-1zM9 12l3 3-6 6H3v-3l6-6z',
        'rocket' => 'M12 2c3.5 2 5 5.5 5 9l-2.5 5h-5L7 11c0-3.5 1.5-7 5-9zm0 6a2 2 0 100 4 2 2 0 000-4zM8 18l-2 4 4-2m4 0l4 2-2-4z',
        'gem' => 'M6 3h12l3 6-9 12L3 9l3-6zm0 6h12M9 3l-1 6m7-6l1 6',
        'wolf' => 'M3 4l4 3 5-2 5 2 4-3-1 7 2 3-6 4-4-2-4 2-6-4 2-3L3 4z',
        'eagle' => 'M12 3l3 5 6-2-4 5 4 5-6-2-3 5-3-5-6 2 4-5-4-5 6 2 3-5z',
        'anchor' => 'M12 3a2 2 0 110 4 2 2 0 010-4zm0 4v13m0 0c-4 0-7-3-7-7m14 0c0 4-3 7-7 7M5 11H3m18 0h-2',
        'trophy' => 'M7 4h10v3a5 5 0 01-10 0V4zM5 5H3v2a3 3 0 003 3M19 5h2v2a3 3 0 01-3 3M9 14h6l-1 4h-4l-1-4zm-1 6h8v1H8v-1z',
        'target' => 'M12 3a9 9 0 100 18 9 9 0 000-18zm0 4a5 5 0 100 10 5 5 0 000-10zm0 4a1 1 0 100 2 1 1 0 000-2z',
        'moon' => 'M20 14a8 8 0 11-9.5-10 6.5 6.5 0 009.5 10z',
        'leaf' => 'M4 20c0-9 6-15 16-16C19 13 13 20 4 20zm3-3c3-4 6-6 9-8',
    ];

    private const COLORS = [
        'gold' => '#C69F68',
        'brand' => '#4C6FFF',
        'crimson' => '#B81B2C',
        'emerald' => '#2FBF88',
        'violet' => '#8B5CF6',
        'amber' => '#E0A106',
        'sky' => '#38BDF8',
        'rose' => '#F43F70',
    ];

    /** All available icon keys. */
    public static function iconKeys(): array
    {
        return array_keys(self::ICONS);
    }

    /** All available color keys. */
    public static function colorKeys(): array
    {
        return array_keys(self::COLORS);
    }

    /** Whether an icon key exists. */
    public static function isValidIcon(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::ICONS);
    }

    /** Whether a color key exists. */
    public static function isValidColor(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::COLORS);
    }

    /** SVG path for an icon key, falling back to the default. */
    public static function iconPath(?string $key): string
    {
        return self::ICONS[self::isValidIcon($key) ? $key : self::DEFAULT_ICON];
    }

    /** Hex color for a color key, falling back to the default. */
    public static function colorHex(?string $key): string
    {
        return self::COLORS[self::isValidColor($key) ? $key : self::DEFAULT_COLOR];
    }

    /**
     * Icon key -> SVG path map, for the picker grid.
     *
     * @return array<string, string>
     */
    public static function icons(): array
    {
        return self::ICONS;
    }

    /**
     * Color key -> hex map, for the picker swatches.
     *
     * @return array<string, string>
     */
    public static function colors(): array
    {
        return self::COLORS;
    }

    /**
     * Validation rules for clan identity, keyed by the caller's field names.
     *
     * Shared by create and edit so the two cannot drift -- a limit tightened in one place
     * would otherwise let edit save what create rejects. `$ignoreClanId` skips the unique
     * check for the clan being edited, without which saving an untouched name collides
     * with itself.
     *
     * @param  array{name:string, tag:string, emblem:string, color:string, description:string}  $fields
     */
    public static function identityRules(array $fields, ?int $ignoreClanId = null): array
    {
        $unique = Rule::unique('clans', 'name');

        if ($ignoreClanId !== null) {
            $unique->ignore($ignoreClanId);
        }

        return [
            $fields['name'] => ['required', 'string', 'min:3', 'max:40', $unique],
            $fields['tag'] => ['nullable', 'string', 'max:6'],
            $fields['emblem'] => ['required', 'string', Rule::in(self::iconKeys())],
            $fields['color'] => ['required', 'string', Rule::in(self::colorKeys())],
            $fields['description'] => ['nullable', 'string', 'max:160'],
        ];
    }
}
