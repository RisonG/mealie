<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Shapes for mealie/routes/households/controller_shopping_lists.py, all verified against the oracle.
 *
 * Shopping items embed the full unit and food objects rather than ids, so this round has to build
 * the IngredientUnit and IngredientFood shapes that round 8 otherwise owns.
 */
class ShoppingSerializer
{
    /** IngredientUnit — 14 keys in this order. */
    public static function unit(?object $u): ?array
    {
        if ($u === null) {
            return null;
        }

        return [
            'id' => Guid::dash($u->id),
            'name' => $u->name,
            'pluralName' => $u->plural_name,
            'description' => $u->description ?? '',
            'extras' => new \stdClass,
            'fraction' => (bool) $u->fraction,
            'abbreviation' => $u->abbreviation ?? '',
            'pluralAbbreviation' => $u->plural_abbreviation,
            'useAbbreviation' => (bool) $u->use_abbreviation,
            'aliases' => self::aliases('ingredient_units_aliases', 'unit_id', $u->id),
            'standardQuantity' => $u->standard_quantity === null ? null : (float) $u->standard_quantity,
            'standardUnit' => $u->standard_unit,
            'createdAt' => Dates::out($u->created_at),
            'updatedAt' => Dates::out($u->update_at),
        ];
    }

    /** IngredientFood — 12 keys. */
    public static function food(?object $f): ?array
    {
        if ($f === null) {
            return null;
        }

        $label = $f->label_id
            ? DB::connection('mealie')->table('multi_purpose_labels')->where('id', $f->label_id)->first()
            : null;

        return [
            'id' => Guid::dash($f->id),
            'name' => $f->name,
            'pluralName' => $f->plural_name,
            'description' => $f->description ?? '',
            'extras' => new \stdClass,
            'labelId' => Guid::dash($f->label_id),
            'aliases' => self::aliases('ingredient_foods_aliases', 'food_id', $f->id),
            'substitutions' => [],
            'householdsWithIngredientFood' => [],
            'label' => $label ? GroupSerializer::label($label) : null,
            'createdAt' => Dates::out($f->created_at),
            'updatedAt' => Dates::out($f->update_at),
        ];
    }

    /**
     * ShoppingListItemOut — 20 keys, verified.
     *
     * group_id and household_id are not columns on shopping_list_items; they come from the parent
     * list and its owner.
     */
    public static function item(object $i): array
    {
        $db = DB::connection('mealie');
        $unit = $i->unit_id ? self::unit($db->table('ingredient_units')->where('id', $i->unit_id)->first()) : null;
        $food = $i->food_id ? self::food($db->table('ingredient_foods')->where('id', $i->food_id)->first()) : null;
        $label = $i->label_id ? $db->table('multi_purpose_labels')->where('id', $i->label_id)->first() : null;

        $list = $db->table('shopping_lists')->where('id', $i->shopping_list_id)->first();
        $owner = $list ? $db->table('users')->where('id', $list->user_id)->first() : null;

        $quantity = $i->quantity === null ? null : (float) $i->quantity;

        return [
            'quantity' => $quantity,
            'unit' => $unit,
            'food' => $food,
            'referencedRecipe' => null,
            'note' => $i->note,
            'display' => IngredientDisplay::build($quantity, $unit, $food, $i->note),
            'shoppingListId' => Guid::dash($i->shopping_list_id),
            'checked' => (bool) $i->checked,
            'position' => (int) $i->position,
            'foodId' => Guid::dash($i->food_id),
            'labelId' => Guid::dash($i->label_id),
            'unitId' => Guid::dash($i->unit_id),
            'extras' => new \stdClass,
            'id' => Guid::dash($i->id),
            'groupId' => Guid::dash($list?->group_id),
            'householdId' => Guid::dash($owner?->household_id),
            'label' => $label ? GroupSerializer::label($label) : null,
            'recipeReferences' => [],
            'createdAt' => Dates::out($i->created_at),
            'updatedAt' => Dates::out($i->update_at),
        ];
    }

    /** ShoppingListSummary — the list endpoint's shape, with no listItems. */
    public static function listSummary(object $l): array
    {
        $db = DB::connection('mealie');
        $owner = $db->table('users')->where('id', $l->user_id)->first();

        return [
            'name' => $l->name,
            'extras' => new \stdClass,
            'createdAt' => Dates::out($l->created_at),
            'updatedAt' => Dates::out($l->update_at),
            'groupId' => Guid::dash($l->group_id),
            'userId' => Guid::dash($l->user_id),
            'id' => Guid::dash($l->id),
            'householdId' => Guid::dash($owner?->household_id),
            'recipeReferences' => [],
            'labelSettings' => self::labelSettings($l->id),
        ];
    }

    /** ShoppingListOut — the summary with listItems inserted after id. */
    public static function listOut(object $l): array
    {
        $summary = self::listSummary($l);
        $items = DB::connection('mealie')->table('shopping_list_items')
            ->where('shopping_list_id', $l->id)->orderBy('position')
            ->get()->map(fn ($i) => self::item($i))->all();

        $out = [];
        foreach ($summary as $key => $value) {
            $out[$key] = $value;
            if ($key === 'id') {
                $out['listItems'] = $items;
            }
        }

        return $out;
    }

    /** ShoppingListMultiPurposeLabelOut */
    public static function labelSettings(string $listId): array
    {
        $db = DB::connection('mealie');

        return $db->table('shopping_lists_multi_purpose_labels')->where('shopping_list_id', $listId)
            ->orderBy('position')->get()->map(function ($s) use ($db) {
                $label = $db->table('multi_purpose_labels')->where('id', $s->label_id)->first();

                return [
                    'shoppingListId' => Guid::dash($s->shopping_list_id),
                    'labelId' => Guid::dash($s->label_id),
                    'position' => (int) $s->position,
                    'id' => Guid::dash($s->id),
                    'label' => $label ? GroupSerializer::label($label) : null,
                ];
            })->all();
    }

    private static function aliases(string $table, string $column, string $ownerId): array
    {
        return DB::connection('mealie')->table($table)->where($column, $ownerId)
            ->get()->map(fn ($a) => ['name' => $a->name])->all();
    }
}
