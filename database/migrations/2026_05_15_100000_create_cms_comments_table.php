<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\CMS\Enums\CMSTables;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\MigrateUtils;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = CMSTables::Comments->value;
        Schema::create($table_name, static function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('content_id')
                ->constrained(CMSTables::Contents->value, 'id', "{$table_name}_content_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained(CoreTables::Users->value, 'id', "{$table_name}_user_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable(true)->constrained($table_name, 'id', "{$table_name}_parent_id_FK")->nullOnDelete()->comment('The parent comment');

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
            );

            $table->index(['content_id', 'created_at'], "{$table_name}_content_created_IDX");
            MigrateUtils::prefixIndex($table, 'user_id');
            MigrateUtils::prefixIndex($table, 'parent_id');
        });

        MigrateUtils::closureTable($table_name);

        $translations_table = CMSTables::CommentsTranslations->value;
        Schema::create($translations_table, static function (Blueprint $table) use ($translations_table): void {
            $table->id();
            $table->foreignId('comment_id')
                ->constrained(CMSTables::Comments->value, 'id', "{$translations_table}_comment_id_FK")
                ->cascadeOnDelete();
            $table->string('locale', 10);
            $table->text('body');

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
            );

            $table->unique(['comment_id', 'locale'], "{$translations_table}_comment_locale_UN");
            $table->index(['comment_id', 'created_at'], "{$translations_table}_comment_created_IDX");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(CMSTables::CommentsClosure->value);
        Schema::dropIfExists(CMSTables::CommentsTranslations->value);
        Schema::dropIfExists(CMSTables::Comments->value);
    }
};
