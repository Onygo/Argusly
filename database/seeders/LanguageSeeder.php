<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $languages = [
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'is_ui_enabled' => true, 'is_content_enabled' => true, 'is_default' => true, 'sort_order' => 10],
            ['code' => 'nl', 'name' => 'Dutch', 'native_name' => 'Nederlands', 'is_ui_enabled' => true, 'is_content_enabled' => true, 'is_default' => false, 'sort_order' => 20],
            ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'is_ui_enabled' => false, 'is_content_enabled' => true, 'is_default' => false, 'sort_order' => 30],
            ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'is_ui_enabled' => false, 'is_content_enabled' => true, 'is_default' => false, 'sort_order' => 40],
            ['code' => 'es', 'name' => 'Spanish', 'native_name' => 'Español', 'is_ui_enabled' => false, 'is_content_enabled' => true, 'is_default' => false, 'sort_order' => 50],
        ];

        foreach ($languages as $language) {
            Language::query()->updateOrCreate(
                ['code' => $language['code']],
                $language,
            );
        }
    }
}
