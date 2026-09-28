<?php

namespace App\Domain\Storefront;

use InvalidArgumentException;

/**
 * `#rrggbb` brand colours (05.15 §3.1): validation, the WCAG 2.1 contrast
 * ratio that the settings page enforces (≥ 4.5:1 against white, so white
 * button text stays legible, 07 §8), and the HSL channel triplet that the
 * Tailwind `--primary` token takes.
 *
 * Presentation only: floats are fine here. This is not a money path.
 */
final class Colour
{
    public const MIN_CONTRAST_ON_WHITE = 4.5;

    public static function isHex(string $value): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /** WCAG 2.1 contrast ratio between two colours, 1.0 to 21.0. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** White or near-black, whichever reads better on the colour. */
    public static function readableOn(string $hex): string
    {
        return self::contrast($hex, '#ffffff') >= self::contrast($hex, '#0a0a0b') ? '#ffffff' : '#0a0a0b';
    }

    /** "#1d4ed8" → "224 76% 48%", the `hsl(var(--primary))` channel format. */
    public static function hslChannels(string $hex): string
    {
        [$r, $g, $b] = array_map(fn (int $c) => $c / 255, self::rgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d == 0.0) {
            return sprintf('0 0%% %d%%', (int) round($l * 100));
        }

        $s = $d / (1 - abs(2 * $l - 1));
        $h = match ($max) {
            $r => fmod(($g - $b) / $d + 6, 6),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        } * 60;

        return sprintf('%d %d%% %d%%', (int) round($h), (int) round($s * 100), (int) round($l * 100));
    }

    private static function luminance(string $hex): float
    {
        $channel = static function (int $c): float {
            $s = $c / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = self::rgb($hex);

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        if (! self::isHex($hex)) {
            throw new InvalidArgumentException("Not a #rrggbb colour: {$hex}");
        }

        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }
}
