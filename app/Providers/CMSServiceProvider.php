<?php

declare(strict_types=1);

namespace Modules\CMS\Providers;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Modules\CMS\ApplicationContent\CmsApplicationContentRetrievalProvider;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Graph\CmsGraphProvider;
use Modules\CMS\Import\CategoryImporter;
use Modules\CMS\Import\ContentImporter;
use Modules\CMS\Import\ContributorImporter;
use Modules\CMS\Import\TagImporter;
use Modules\CMS\Models\Comment;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\ContentReference;
use Modules\CMS\Models\Translations\ContentTranslation;
use Modules\CMS\Observers\ContentExtensionObserver;
use Modules\CMS\Observers\ContentReferenceObserver;
use Modules\CMS\Observers\ContentTranslationObserver;
use Modules\CMS\Observers\PlaceObserver;
use Modules\CMS\Services\CommentModerationAdapter;
use Modules\CMS\Services\ContentOwnerAuthorizer;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderRegistryInterface;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Graph\Contracts\GraphProviderRegistryInterface;
use Modules\Core\Import\Support\EntityImporterRegistry;
use Modules\Core\Models\Place;
use Modules\Core\Models\User;
use Modules\Core\Observers\HierarchyOwnerObserver;
use Modules\Core\Overrides\ModuleServiceProvider;
use Modules\Core\Search\OwnerAuthorizerRegistry;
use Modules\Core\Services\ModerationAdapterRegistry;
use Modules\Core\Support\Hierarchy\ClosureTableMaintainer;
use Modules\Core\Support\Hierarchy\DynamicEntityCascade;
use Modules\Core\Support\Hierarchy\OwnerCascade;
use Modules\Core\Support\Hierarchy\OwnerCascadeRegistry;
use Override;

/**
 * @property \Illuminate\Foundation\Application $app
 */
final class CMSServiceProvider extends ModuleServiceProvider
{
    #[Override]
    protected string $name = 'CMS';

    #[Override]
    protected string $nameLower = 'cms';

    /**
     * Register the service provider.
     *
     * @throws Exception
     */
    #[Override]
    public function register(): void
    {
        parent::register();

        $this->app->register(ImportServiceProvider::class);
    }

    /**
     * Boot the service provider.
     */
    #[Override]
    public function boot(): void
    {
        parent::boot();

        $this->app
            ->make(GraphProviderRegistryInterface::class)
            ->register($this->app->make(CmsGraphProvider::class), 'cms');

        $this->app
            ->make(ApplicationContentRetrievalProviderRegistryInterface::class)
            ->register($this->app->make(CmsApplicationContentRetrievalProvider::class));

        $this->app
            ->make(OwnerAuthorizerRegistry::class)
            ->register($this->app->make(ContentOwnerAuthorizer::class));

        // Observe Place model to dispatch geocoding jobs when address fields change.
        // Address fields (address, city, province, country) are stored on Place via HasPlace,
        // so we must watch Place saves rather than Location saves.
        Place::observe(PlaceObserver::class);

        // Observe ContentTranslation to incrementally re-embed the changed locale only
        // (or drop and reindex it on delete), instead of regenerating every locale.
        ContentTranslation::observe(ContentTranslationObserver::class);

        // A reference is part of its content's search document: re-send the content when one changes.
        ContentReference::observe(ContentReferenceObserver::class);

        // Content-extension seam: apply a direct content deletion to its extender (C9, C10).
        Content::observe(ContentExtensionObserver::class);

        // A content's hard delete removes its comments by cascade, with no event on them: Core's observer keeps
        // their closure in step through the cascades registered below.
        Content::observe(HierarchyOwnerObserver::class);
        $this->registerCommentOwnerCascades();

        $this->app->make(ModerationAdapterRegistry::class)
            ->register($this->app->make(CommentModerationAdapter::class));

        $importers = $this->app->make(EntityImporterRegistry::class);
        $importers->register($this->app->make(TagImporter::class));
        $importers->register($this->app->make(ContributorImporter::class));
        $importers->register($this->app->make(CategoryImporter::class));
        $importers->register($this->app->make(ContentImporter::class));
    }

    /**
     * The hard deletes that remove comments by cascading foreign keys: a user's and a content's directly, an
     * entity's, preset's, presettable's or template's through the contents they take along.
     */
    private function registerCommentOwnerCascades(): void
    {
        $cascades = $this->app->make(OwnerCascadeRegistry::class);
        $comments = CMSTables::Comments->value;
        $maintainer = static fn (Model $deleted): ClosureTableMaintainer => ClosureTableMaintainer::forModel(
            (new Comment)->setConnection($deleted->getConnectionName()),
        );

        $cascades->register(new OwnerCascade(
            User::class,
            CoreTables::Users->value,
            $comments,
            $maintainer,
            static fn (Builder $doomed, Model $user): Builder => $doomed->where('user_id', $user->getKey()),
        ));
        $cascades->register(new OwnerCascade(
            Content::class,
            CMSTables::Contents->value,
            $comments,
            $maintainer,
            static fn (Builder $doomed, Model $content): Builder => $doomed->where('content_id', $content->getKey()),
        ));

        foreach (DynamicEntityCascade::OWNERS as $owner => $owner_table) {
            $cascades->register(new OwnerCascade(
                $owner,
                $owner_table,
                $comments,
                $maintainer,
                static fn (Builder $doomed, Model $deleted): Builder => $doomed->whereIn(
                    'content_id',
                    DynamicEntityCascade::rowsTakenBy($deleted->getConnection()->table(CMSTables::Contents->value)->select('id'), $deleted),
                ),
            ));
        }
    }
}
