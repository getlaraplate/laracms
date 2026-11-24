<?php

declare(strict_types=1);

namespace Modules\CMS\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Scope;
use Modules\CMS\Models\Comment;
use Modules\Core\Helpers\LocaleContext;

/**
 * List comments with at least one translation; eager-load resolved body for current locale or original.
 *
 * @implements Scope<Comment>
 */
final class CommentTranslationScope implements Scope
{
    /**
     * @param  Builder<covariant Comment>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $current_locale = LocaleContext::get();

        $builder->whereHas('translations');

        $builder->with(['translation' => static function (Relation $relation) use ($current_locale): void {
            $relation->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$current_locale])
                ->orderBy('created_at')
                ->orderBy('id');
        }]);
    }
}
