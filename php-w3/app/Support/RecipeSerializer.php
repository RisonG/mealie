<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Shapes for mealie/routes/recipe/, verified against the oracle.
 *
 * The recipes table has no household_id column; the household comes from the owning user, as it
 * does for meal plans.
 *
 * Two datetime renderings are in play. GET /recipes and GET /recipes/suggestions return
 * orjson.dumps(model_dump(...)) directly (recipe_crud_routes.py:559-563) and so emit "+00:00";
 * every other recipe route goes through pydantic and emits "Z". $tz picks which.
 */
class RecipeSerializer
{
    /** RecipeSummary — 25 keys, the shape GET /recipes returns. */
    public static function summary(object $r, string $tz = 'Z'): array
    {
        $db = DB::connection('mealie');
        $householdId = $db->table('users')->where('id', $r->user_id)->value('household_id');

        return [
            'id' => Guid::dash($r->id),
            'userId' => Guid::dash($r->user_id),
            'householdId' => Guid::dash($householdId),
            'groupId' => Guid::dash($r->group_id),
            'name' => $r->name,
            'slug' => $r->slug,
            'image' => $r->image,
            'recipeServings' => (float) $r->recipe_servings,
            'recipeYieldQuantity' => (float) $r->recipe_yield_quantity,
            'recipeYield' => $r->recipe_yield,
            'totalTime' => $r->total_time,
            'prepTime' => $r->prep_time,
            'cookTime' => $r->cook_time,
            'performTime' => $r->perform_time,
            'description' => $r->description,
            'recipeCategory' => self::linked($r->id, 'recipes_to_categories', 'categories', 'category_id'),
            'tags' => self::linked($r->id, 'recipes_to_tags', 'tags', 'tag_id'),
            'tools' => self::linked($r->id, 'recipes_to_tools', 'tools', 'tool_id'),
            'rating' => $r->rating === null ? null : (float) $r->rating,
            'orgURL' => $r->org_url,
            'dateAdded' => Dates::dateOut($r->date_added),
            'dateUpdated' => self::stamp($r->date_updated, $tz),
            'createdAt' => self::stamp($r->created_at, $tz),
            'updatedAt' => self::stamp($r->update_at, $tz),
            'lastMade' => self::stamp($r->last_made, $tz),
        ];
    }

    /** Recipe — the summary plus eight more keys. */
    public static function full(object $r): array
    {
        $db = DB::connection('mealie');

        $ingredients = $db->table('recipes_ingredients')->where('recipe_id', $r->id)
            ->orderBy('position')->get()->map(fn ($i) => self::ingredient($i))->all();

        $instructions = $db->table('recipe_instructions')->where('recipe_id', $r->id)
            ->orderBy('position')->get()->map(fn ($i) => [
                'id' => Guid::dash($i->id),
                'title' => $i->title ?? '',
                'summary' => $i->summary ?? '',
                'text' => $i->text ?? '',
                'ingredientReferences' => [],
                'noteReferences' => [],
            ])->all();

        $assets = $db->table('recipe_assets')->where('recipe_id', $r->id)->get()
            ->map(fn ($a) => ['name' => $a->name, 'icon' => $a->icon, 'fileName' => $a->file_name])->all();

        $notes = $db->table('notes')->where('recipe_id', $r->id)->get()
            ->map(fn ($n) => ['title' => $n->title, 'text' => $n->text])->all();

        return self::summary($r) + [
            'recipeIngredient' => $ingredients,
            'recipeInstructions' => $instructions,
            'nutrition' => self::nutrition($db->table('recipe_nutrition')->where('recipe_id', $r->id)->first()),
            'settings' => self::settings($db->table('recipe_settings')->where('recipe_id', $r->id)->first()),
            'assets' => $assets,
            'notes' => $notes,
            'extras' => new \stdClass,
            'comments' => [],
        ];
    }

    /** RecipeTimelineEventOut — 12 keys, verified. */
    public static function timelineEvent(object $e): array
    {
        $db = DB::connection('mealie');
        $recipe = $db->table('recipes')->where('id', $e->recipe_id)->first();
        $householdId = $db->table('users')->where('id', $e->user_id)->value('household_id');

        return [
            'recipeId' => Guid::dash($e->recipe_id),
            'userId' => Guid::dash($e->user_id),
            // The column stores a translation key; the API returns the translated string.
            'subject' => self::translate($e->subject),
            'eventType' => $e->event_type,
            'eventMessage' => $e->message,
            'image' => $e->image,
            'timestamp' => Dates::out($e->timestamp),
            'id' => Guid::dash($e->id),
            'groupId' => Guid::dash($recipe?->group_id),
            'householdId' => Guid::dash($householdId),
            'createdAt' => Dates::out($e->created_at),
            'updatedAt' => Dates::out($e->update_at),
        ];
    }

    /**
     * Timeline subjects are stored as message keys (e.g. "recipe.recipe-created") and rendered
     * through the locale provider on the way out. Only en-US is loaded in this rewrite, which the
     * contract records; an unknown key is passed through unchanged, as a missing translation would be.
     */
    private static function translate(?string $key): ?string
    {
        static $messages = null;
        if ($messages === null) {
            $path = dirname(base_path()).'/mealie/lang/messages/en-US.json';
            $messages = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
        }

        if ($key === null || ! str_contains($key, '.')) {
            return $key;
        }

        $node = $messages;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return $key;
            }
            $node = $node[$segment];
        }

        return is_string($node) ? $node : $key;
    }

    /** RecipeIngredient — the shopping item shape plus title, originalText, substitutions, referenceId. */
    private static function ingredient(object $i): array
    {
        $db = DB::connection('mealie');
        $unit = $i->unit_id ? ShoppingSerializer::unit($db->table('ingredient_units')->where('id', $i->unit_id)->first()) : null;
        $food = $i->food_id ? ShoppingSerializer::food($db->table('ingredient_foods')->where('id', $i->food_id)->first()) : null;
        $quantity = $i->quantity === null ? null : (float) $i->quantity;

        return [
            'quantity' => $quantity,
            'unit' => $unit,
            'food' => $food,
            'referencedRecipe' => null,
            'note' => $i->note,
            'display' => IngredientDisplay::build($quantity, $unit, $food, $i->note),
            'title' => $i->title,
            'originalText' => $i->original_text,
            'substitutions' => [],
            'referenceId' => Guid::dash($i->reference_id),
        ];
    }

    private static function nutrition(?object $n): array
    {
        $fields = [
            'calories', 'carbohydrateContent', 'cholesterolContent', 'fatContent', 'fiberContent',
            'proteinContent', 'saturatedFatContent', 'sodiumContent', 'sugarContent',
            'transFatContent', 'unsaturatedFatContent',
        ];

        $out = [];
        foreach ($fields as $camel) {
            $column = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $camel));
            $out[$camel] = $n?->{$column} ?? null;
        }

        return $out;
    }

    /** RecipeSettings — note disable_amount is a column but not part of this response. */
    private static function settings(?object $s): array
    {
        return [
            'public' => (bool) ($s->public ?? false),
            'showNutrition' => (bool) ($s->show_nutrition ?? false),
            'showAssets' => (bool) ($s->show_assets ?? false),
            'landscapeView' => (bool) ($s->landscape_view ?? false),
            'disableComments' => (bool) ($s->disable_comments ?? false),
            'locked' => (bool) ($s->locked ?? false),
        ];
    }

    private static function linked(string $recipeId, string $joinTable, string $table, string $column): array
    {
        $db = DB::connection('mealie');
        $ids = $db->table($joinTable)->where('recipe_id', $recipeId)->pluck($column);

        return $db->table($table)->whereIn('id', $ids)->orderBy('name')->get()
            ->map(fn ($t) => ['id' => Guid::dash($t->id), 'name' => $t->name, 'slug' => $t->slug])->all();
    }

    /** "Z" through pydantic, "+00:00" through orjson's model_dump path. */
    private static function stamp(?string $value, string $tz): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $iso = Dates::out($value);

        return $tz === 'Z' ? $iso : substr($iso, 0, -1).'+00:00';
    }
}
