<?php

declare(strict_types=1);

namespace Modules\CMS\Providers;

use Exception;
use Modules\CMS\ApplicationContent\CmsApplicationContentRetrievalProvider;
use Modules\CMS\Graph\CmsGraphProvider;
use Modules\CMS\Import\CategoryImporter;
use Modules\CMS\Import\ContentImporter;
use Modules\CMS\Import\ContributorImporter;
use Modules\CMS\Import\TagImporter;
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
use Modules\Core\Graph\Contracts\GraphProviderRegistryInterface;
use Modules\Core\Import\Support\EntityImporterRegistry;
use Modules\Core\Models\Place;
use Modules\Core\Overrides\ModuleServiceProvider;
use Modules\Core\Search\OwnerAuthorizerRegistry;
use Modules\Core\Services\ModerationAdapterRegistry;
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

        $this->app->make(ModerationAdapterRegistry::class)
            ->register($this->app->make(CommentModerationAdapter::class));

        $importers = $this->app->make(EntityImporterRegistry::class);
        $importers->register($this->app->make(TagImporter::class));
        $importers->register($this->app->make(ContributorImporter::class));
        $importers->register($this->app->make(CategoryImporter::class));
        $importers->register($this->app->make(ContentImporter::class));
    }
}
