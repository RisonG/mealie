<?php

namespace App\Support;

/**
 * RecipeIngredientBase._format_display (mealie/schema/recipe/recipe_ingredient.py:420-442) and the
 * three helpers above it. Used by shopping list items and, later, by recipe ingredients.
 *
 * The default locale handling is WITHOUT_UNIT (recipe_ingredient.py:426), which is what applies
 * here: no locale context is set on these routes.
 */
class IngredientDisplay
{
    private const PRECISION = 3;              // INGREDIENT_QTY_PRECISION

    private const MAX_DENOMINATOR = 32;       // MAX_INGREDIENT_DENOMINATOR

    private const SUPERSCRIPT = ['0' => '⁰', '1' => '¹', '2' => '²', '3' => '³', '4' => '⁴', '5' => '⁵', '6' => '⁶', '7' => '⁷', '8' => '⁸', '9' => '⁹'];

    private const SUBSCRIPT = ['0' => '₀', '1' => '₁', '2' => '₂', '3' => '₃', '4' => '₄', '5' => '₅', '6' => '₆', '7' => '₇', '8' => '₈', '9' => '₉'];

    /** $unit and $food are the serialized arrays, or null. */
    public static function build(?float $quantity, ?array $unit, ?array $food, ?string $note): string
    {
        $parts = [];

        if ($quantity) {
            $parts[] = self::quantity($quantity, $unit);
        }

        if ($quantity && $unit) {
            $parts[] = self::unit($quantity, $unit);
        }

        if ($food) {
            $parts[] = self::food($quantity, $unit, $food);
        }

        if ($note !== null && $note !== '') {
            $parts[] = $note;
        }

        return trim(implode(' ', $parts));
    }

    /** :348-376 — decimal when the unit says so, otherwise a limited-denominator fraction. */
    private static function quantity(float $quantity, ?array $unit): string
    {
        if ($unit !== null && ! ($unit['fraction'] ?? true)) {
            $qty = round($quantity, self::PRECISION);

            return $qty == (int) $qty ? (string) (int) $qty : self::pyFloat($qty);
        }

        [$numerator, $denominator] = self::limitDenominator($quantity, self::MAX_DENOMINATOR);

        if ($denominator === 1) {
            return (string) $numerator;
        }

        if ($numerator <= $denominator) {
            return self::fraction($numerator, $denominator);
        }

        // :370-376 — an improper fraction becomes a mixed one, e.g. 11/4 → "2 3/4".
        $whole = 0;
        while ($numerator > $denominator) {
            $whole++;
            $numerator -= $denominator;
        }

        return $whole.' '.self::fraction($numerator, $denominator);
    }

    /** :378-395 — plural only above 1, abbreviation only when the unit asks for it. */
    private static function unit(float $quantity, array $unit): string
    {
        $usePlural = $quantity > 1;
        $value = '';

        if ($unit['useAbbreviation'] ?? false) {
            $value = $usePlural
                ? ($unit['pluralAbbreviation'] ?: $unit['abbreviation'])
                : $unit['abbreviation'];
        }

        if (! $value) {
            $value = $usePlural ? ($unit['pluralName'] ?: $unit['name']) : $unit['name'];
        }

        return (string) $value;
    }

    /** :397-418 — WITHOUT_UNIT: plural only when there is no unit to carry the count. */
    private static function food(?float $quantity, ?array $unit, array $food): string
    {
        if ($quantity !== null && $quantity <= 1) {
            $usePlural = false;
        } else {
            $usePlural = ! ($quantity && $unit);
        }

        return (string) ($usePlural ? ($food['pluralName'] ?: $food['name']) : $food['name']);
    }

    /** display_fraction (:30-35) — superscript numerator, slash, subscript denominator. */
    private static function fraction(int $numerator, int $denominator): string
    {
        return strtr((string) $numerator, self::SUPERSCRIPT).'/'.strtr((string) $denominator, self::SUBSCRIPT);
    }

    /** Fraction(value).limit_denominator(max) — the continued-fraction algorithm Python uses. */
    private static function limitDenominator(float $value, int $max): array
    {
        if ($value == 0.0) {
            return [0, 1];
        }

        $sign = $value < 0 ? -1 : 1;
        $value = abs($value);

        [$p0, $q0, $p1, $q1] = [0, 1, 1, 0];
        $n = $value;
        while (true) {
            $a = (int) floor($n);
            $q2 = $q0 + $a * $q1;
            if ($q2 > $max) {
                break;
            }
            [$p0, $q0, $p1, $q1] = [$p1, $q1, $p0 + $a * $p1, $q2];
            if (abs($n - $a) < 1e-12) {
                break;
            }
            $n = 1 / ($n - $a);
        }

        $k = (int) (($max - $q0) / $q1);
        $bound1 = [$p0 + $k * $p1, $q0 + $k * $q1];
        $bound2 = [$p1, $q1];

        $d1 = abs($bound1[0] / $bound1[1] - $value);
        $d2 = abs($bound2[0] / $bound2[1] - $value);
        $best = $d2 <= $d1 ? $bound2 : $bound1;

        return [$sign * $best[0], $best[1]];
    }

    /** Python's float repr for a non-integer rounded quantity. */
    private static function pyFloat(float $value): string
    {
        return rtrim(rtrim(number_format($value, self::PRECISION, '.', ''), '0'), '.');
    }
}
