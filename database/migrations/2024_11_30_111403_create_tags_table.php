<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\CMS\Enums\CMSTables;
use Modules\Core\Helpers\MigrateUtils;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = CMSTables::Tags->value;
        Schema::create($table_name, static function (Blueprint $table) use ($table_name): void {
            $table->id();

            // The identity of a tag is its slug per locale: the unique constraint is on tags_translations (locale, slug).
            $table->string('type')->nullable()->index("{$table_name}_type_IDX")->comment('The type of the tag');
            $table->integer('order_column')->nullable(false)->default(0)->index("{$table_name}_order_column_IDX")->comment('The order of the tag');

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(CMSTables::Tags->value);
    }
};
