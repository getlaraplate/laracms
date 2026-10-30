<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\CMS\Enums\CMSTables;
use Modules\Core\Helpers\MigrateUtils;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $table_name = CMSTables::TagsTranslations->value;
        Schema::create($table_name, static function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('tag_id')->nullable(false)->constrained(CMSTables::Tags->value, 'id', "{$table_name}_tag_id_FK")->cascadeOnDelete()->comment('The tag that the translation belongs to');
            $table->string('locale', 10)->nullable(false)->index("{$table_name}_locale_IDX")->comment('The locale of the translation');
            $table->string('name')->nullable(false)->comment('The translated name of the tag');
            $table->string('slug')->nullable(false)->index("{$table_name}_slug_IDX")->comment('The translated slug of the tag');

            MigrateUtils::timestamps($table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
            );

            $table->unique(['tag_id', 'locale'], "{$table_name}_tag_locale_UN");
        });

        // One live tag per locale and slug, whatever source or editor writes it.
        MigrateUtils::liveUniqueIndex($table_name, ['locale', 'slug'], "{$table_name}_locale_slug_UN");

        MigrateUtils::fuzzyIndex($table_name, 'name');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(CMSTables::TagsTranslations->value);
    }
};
