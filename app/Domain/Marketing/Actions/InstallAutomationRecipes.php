<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Automation\RecipeCatalog;
use App\Domain\Marketing\Models\Automation;

/** Creates the prebuilt recipes for the current tenant (missing ones only). */
class InstallAutomationRecipes
{
    public function handle(?bool $activate = null): int
    {
        $created = 0;
        foreach (RecipeCatalog::all() as $key => $recipe) {
            if (Automation::where('recipe', $key)->exists()) {
                continue;
            }
            Automation::create([
                'name' => __('marketing.automations.recipes.'.$key.'.name'),
                'recipe' => $key,
                'trigger' => $recipe['trigger'],
                'trigger_config' => $recipe['config'],
                'steps' => $recipe['steps'],
                'is_active' => $activate ?? $recipe['active'],
            ]);
            $created++;
        }

        return $created;
    }
}
