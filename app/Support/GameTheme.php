<?php

namespace App\Support;

/**
 * Market-specific betting labels/colors, switched from a single "region"
 * setting on Game (same settings page as plasada) rather than hardcoded
 * per view. Philippines is red/MERON vs blue/WALA; Mexico is red/ROJO vs
 * green/VERDE.
 *
 * Every Tailwind class used across regions must appear as a literal
 * string somewhere in the codebase for Tailwind's build-time scanner to
 * include it in the compiled CSS — keeping every combination spelled out
 * here (rather than building class names like "bg-{$color}-900" at
 * runtime) is what makes that work.
 */
class GameTheme
{
    private const THEMES = [
        'philippines' => [
            'meron' => [
                'label' => 'Meron',
                'panel' => 'bg-red-900',
                'btn' => 'bg-red-700 hover:bg-red-600',
                'reglahan' => 'bg-red-700 text-yellow-300',
                'soft_text' => 'text-red-300',
                'declare_btn' => 'bg-red-600 hover:bg-red-500',
                'hex' => '#dc2626',
            ],
            'wala' => [
                'label' => 'Wala',
                'panel' => 'bg-blue-900',
                'btn' => 'bg-blue-700 hover:bg-blue-600',
                'reglahan' => 'bg-blue-700 text-yellow-300',
                'soft_text' => 'text-sky-300',
                'declare_btn' => 'bg-sky-600 hover:bg-sky-500',
                'hex' => '#2563eb',
            ],
            'draw' => [
                'label' => 'Draw',
            ],
        ],
        'mexico' => [
            'meron' => [
                'label' => 'Rojo',
                'panel' => 'bg-red-900',
                'btn' => 'bg-red-700 hover:bg-red-600',
                'reglahan' => 'bg-red-700 text-yellow-300',
                'soft_text' => 'text-red-300',
                'declare_btn' => 'bg-red-600 hover:bg-red-500',
                'hex' => '#dc2626',
            ],
            'wala' => [
                'label' => 'Verde',
                'panel' => 'bg-green-900',
                'btn' => 'bg-green-700 hover:bg-green-600',
                'reglahan' => 'bg-green-700 text-yellow-300',
                'soft_text' => 'text-green-300',
                'declare_btn' => 'bg-green-600 hover:bg-green-500',
                'hex' => '#16a34a',
            ],
            'draw' => [
                'label' => 'Empate',
            ],
        ],
    ];

    /**
     * @return array{meron: array, wala: array, draw: array, currency: string}
     */
    public static function for(?string $region): array
    {
        $theme = self::THEMES[$region] ?? self::THEMES['philippines'];
        $theme['currency'] = self::currencySymbol($region);

        return $theme;
    }

    /**
     * Mexico prices in pesos, so a bare "$" is ambiguous with USD — every
     * other region keeps the plain "$" it already used.
     */
    public static function currencySymbol(?string $region): string
    {
        return $region === 'mexico' ? 'Mex$' : '$';
    }

    /**
     * @return array<int, string>
     */
    public static function regions(): array
    {
        return array_keys(self::THEMES);
    }

    public static function regionLabel(string $region): string
    {
        return match ($region) {
            'mexico' => 'Mexico (Rojo / Verde)',
            default => 'Philippines (Meron / Wala)',
        };
    }
}
