<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Modules\CMS\ApplicationContent\CmsApplicationContentRetrievalProvider;
use Modules\CMS\ApplicationContent\CmsContentEvidenceProjector;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Content;
use Modules\CMS\Tests\TestCase;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderRegistryInterface;
use Modules\Core\ApplicationContent\Data\ApplicationContentAuthorization;
use Modules\Core\ApplicationContent\Data\ApplicationContentQuery;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\Services\AdvancedSearchService;
use Modules\Core\Search\Services\EnsembleSearchService;
use Modules\Core\Search\Services\FallbackSearchPlanner;
use Modules\Core\Search\Services\SimpleQueryIntentParser;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\QueryBuilder;
use Modules\Core\Tests\Stubs\Search\FixedSearchStrategyResolver;

uses(TestCase::class, RefreshDatabase::class);

function cms_content_for_retrieval(
    string $title,
    string $body,
    string $locale = 'en',
    array $attributes = [],
): Content {
    setupCMSEntities([EntityType::Contents]);

    $content = Content::factory()->create(array_merge([
        'valid_from' => now()->subDay(),
        'valid_to' => null,
    ], $attributes));

    $content->setTranslation($locale, [
        'title' => $title,
        'slug' => Str::slug($title),
        'components' => [
            'content' => $body,
            'private_payload' => 'storage/private/never-project-this.txt',
        ],
    ])->save();

    return $content->fresh();
}

/**
 * @param  list<array{id: string, score: float, source: array<string, mixed>}>  $hits
 * @param  array<string, mixed>  $meta
 */
function cms_retrieval_provider_with_hits(array $hits, array $meta = []): CmsApplicationContentRetrievalProvider
{
    $hits = array_map(static function (array $hit): array {
        $hit['source'] = array_merge(['connection' => 'default'], $hit['source']);

        return $hit;
    }, $hits);

    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsOrchestratedSearch')->andReturnTrue();
    $engine->shouldReceive('supportsOrchestratedVectorSearch')->andReturnFalse();
    app(EngineManager::class)->extend('cms-application-content-test', static fn () => $engine);
    config()->set('scout.driver', 'cms-application-content-test');

    $ensemble = Mockery::mock(EnsembleSearchService::class);
    $ensemble->shouldReceive('search')->andReturn(new AdvancedSearchResult(
        hits: $hits,
        total: count($hits),
        page: 1,
        perPage: max(1, count($hits)),
        totalPages: $hits === [] ? 0 : 1,
        meta: $meta,
    ));

    $search = new AdvancedSearchService(
        new FixedSearchStrategyResolver(planner: new FallbackSearchPlanner, intent_parser: new SimpleQueryIntentParser),
        $ensemble,
        app(),
    );

    return new CmsApplicationContentRetrievalProvider(
        $search,
        app(AuthorizationService::class),
        app(QueryBuilder::class),
        new CmsContentEvidenceProjector,
    );
}

function cms_retrieval_query(string $locale = 'en', int $limit = 8): ApplicationContentQuery
{
    return new ApplicationContentQuery('cms.contents', 'visible phrase', $locale, $limit);
}

it('registers the cms contents source explicitly', function (): void {
    $provider = app(ApplicationContentRetrievalProviderRegistryInterface::class)->providerFor('cms.contents');

    expect($provider)->toBeInstanceOf(CmsApplicationContentRetrievalProvider::class)
        ->and($provider?->descriptor()->source)->toBe('cms.contents')
        ->and($provider?->descriptor()->module)->toBe('cms')
        ->and($provider?->descriptor()->entity)->toBe('contents');
});

it('rehydrates ranked authorized records and never projects raw search source', function (): void {
    $visible = cms_content_for_retrieval('Visible title', '<p>Visible <strong>phrase</strong></p>');
    $visible->setTranslation('en', [
        'title' => 'Visible title',
        'slug' => 'visible-title',
        'components' => [
            'content' => [
                'text' => '<p>Visible <strong>phrase</strong></p>',
                'private_payload' => 'nested-secret-must-not-be-projected',
            ],
        ],
    ])->save();
    $hidden = cms_content_for_retrieval('Hidden title', 'Hidden phrase');
    $authorization = new ApplicationContentAuthorization(
        'default.contents.select',
        new FiltersGroup([new Filter('id', $visible->getKey())]),
    );
    $provider = cms_retrieval_provider_with_hits([
        ['id' => (string) $hidden->getKey(), 'score' => 0.99, 'source' => ['secret' => 'raw-engine-secret']],
        ['id' => (string) $visible->getKey(), 'score' => 0.75, 'source' => ['secret' => 'raw-engine-secret']],
    ], ['strategies' => ['keyword']]);

    $result = $provider->retrieve(cms_retrieval_query(), $authorization);

    expect($result->strategy)->toBe('lexical')
        ->and($result->hits)->toHaveCount(1)
        ->and($result->hits[0]->recordKey)->toBe($visible->getKey())
        ->and($result->hits[0]->label)->toBe('Visible title')
        ->and($result->hits[0]->excerpt)->toContain('Visible phrase')
        ->and($result->hits[0]->excerpt)->not->toContain('<strong>')
        ->and($result->hits[0]->excerpt)->not->toContain('raw-engine-secret')
        ->and($result->hits[0]->excerpt)->not->toContain('never-project-this')
        ->and($result->hits[0]->excerpt)->not->toContain('nested-secret-must-not-be-projected')
        ->and((array) $result->hits[0])->not->toHaveKeys(['_source', 'components', 'media', 'filters', 'permissionName']);
});

it('drops invalid deleted and stale cross-connection hits during rehydration', function (): void {
    $valid = cms_content_for_retrieval('Current title', 'Current visible phrase');
    $scheduled = cms_content_for_retrieval('Scheduled title', 'Scheduled phrase', attributes: [
        'valid_from' => now()->addDay(),
    ]);
    $deleted = cms_content_for_retrieval('Deleted title', 'Deleted phrase');
    $deleted->delete();

    $provider = cms_retrieval_provider_with_hits([
        ['id' => '999999999', 'score' => 1.0, 'source' => []],
        ['id' => (string) $scheduled->getKey(), 'score' => 0.9, 'source' => []],
        ['id' => (string) $deleted->getKey(), 'score' => 0.8, 'source' => []],
        ['id' => (string) $valid->getKey(), 'score' => 0.75, 'source' => ['connection' => 'another-tenant']],
        ['id' => (string) $valid->getKey(), 'score' => 0.7, 'source' => []],
    ], ['strategies' => ['hybrid', 'vector', 'keyword']]);

    $result = $provider->retrieve(
        cms_retrieval_query(),
        new ApplicationContentAuthorization('default.contents.select', null),
    );

    expect($result->strategy)->toBe('hybrid')
        ->and($result->hits)->toHaveCount(1)
        ->and($result->hits[0]->recordKey)->toBe($valid->getKey())
        ->and($result->hits[0]->score)->toBe(0.7);
});

it('preserves engine ranking and normalizes scores', function (): void {
    $first = cms_content_for_retrieval('First title', 'First visible phrase');
    $second = cms_content_for_retrieval('Second title', 'Second visible phrase');
    $provider = cms_retrieval_provider_with_hits([
        ['id' => (string) $second->getKey(), 'score' => 14.0, 'source' => []],
        ['id' => (string) $first->getKey(), 'score' => -2.0, 'source' => []],
    ], ['strategies' => ['vector']]);

    $result = $provider->retrieve(
        cms_retrieval_query(),
        new ApplicationContentAuthorization('default.contents.select', null),
    );

    expect($result->strategy)->toBe('semantic')
        ->and(array_map(static fn ($hit) => $hit->recordKey, $result->hits))
        ->toBe([$second->getKey(), $first->getKey()])
        ->and($result->hits[0]->score)->toBe(1.0)
        ->and($result->hits[1]->score)->toBe(0.0);
});

it('uses the requested translation and the configured default fallback only', function (): void {
    config()->set('app.locale', 'en');
    app()->setLocale('en');
    $localized = cms_content_for_retrieval('English title', 'English visible phrase');
    $localized->setTranslation('it', [
        'title' => 'Titolo italiano',
        'slug' => 'titolo-italiano',
        'components' => ['content' => 'Testo italiano visibile'],
    ])->save();
    $fallback = cms_content_for_retrieval('Fallback title', 'Fallback visible phrase');
    $fallback->translations()->where('locale', 'it')->forceDelete();
    $provider = cms_retrieval_provider_with_hits([
        ['id' => (string) $localized->getKey(), 'score' => 0.9, 'source' => []],
        ['id' => (string) $fallback->getKey(), 'score' => 0.8, 'source' => []],
    ], ['strategies' => ['keyword']]);

    $result = $provider->retrieve(
        new ApplicationContentQuery('cms.contents', 'testo', 'it', 8),
        new ApplicationContentAuthorization('default.contents.select', null),
    );

    expect($result->hits[0]->locale)->toBe('it')
        ->and($result->hits[0]->label)->toBe('Titolo italiano')
        ->and($result->hits[1]->locale)->toBe('en')
        ->and($result->hits[1]->label)->toBe('Fallback title');
});

it('bounds excerpts and emits safe canonical application references', function (): void {
    config()->set('application-content.max_excerpt_chars', 40);
    $content = cms_content_for_retrieval('Bounded title', str_repeat('é', 100));
    $provider = cms_retrieval_provider_with_hits([
        ['id' => (string) $content->getKey(), 'score' => 0.5, 'source' => []],
    ], ['strategies' => ['keyword']]);

    $hit = $provider->retrieve(
        cms_retrieval_query(),
        new ApplicationContentAuthorization('default.contents.select', null),
    )->hits[0];

    expect(mb_strlen($hit->excerpt))->toBeLessThanOrEqual(40)
        ->and($hit->truncated)->toBeTrue()
        ->and($hit->canonicalReference)->toBe('/app/cms/contents/' . $content->getKey());
});

it('degrades to an authorized deterministic lexical title search', function (): void {
    $content = cms_content_for_retrieval('Visible phrase fallback', 'Fallback body');
    $excluded = cms_content_for_retrieval('Visible phrase excluded', 'Excluded body');
    $authorization = new ApplicationContentAuthorization(
        'default.contents.select',
        new FiltersGroup([new Filter('id', $content->getKey())]),
    );

    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsOrchestratedSearch')->andReturnTrue();
    $engine->shouldReceive('supportsOrchestratedVectorSearch')->andReturnFalse();
    app(EngineManager::class)->extend('cms-application-content-failing-test', static fn () => $engine);
    config()->set('scout.driver', 'cms-application-content-failing-test');

    $ensemble = Mockery::mock(EnsembleSearchService::class);
    $ensemble->shouldReceive('search')->once()->andThrow(new RuntimeException('Search backend details'));
    $provider = new CmsApplicationContentRetrievalProvider(
        new AdvancedSearchService(
            new FixedSearchStrategyResolver(planner: new FallbackSearchPlanner, intent_parser: new SimpleQueryIntentParser),
            $ensemble,
            app(),
        ),
        app(AuthorizationService::class),
        app(QueryBuilder::class),
        new CmsContentEvidenceProjector,
    );

    $result = $provider->retrieve(cms_retrieval_query(), $authorization);

    expect($result->strategy)->toBe('lexical')
        ->and($result->hits)->toHaveCount(1)
        ->and($result->hits[0]->recordKey)->toBe($content->getKey())
        ->and($result->hits[0]->recordKey)->not->toBe($excluded->getKey());
});

it('marks result truncation only when another authorized projected hit exists', function (): void {
    $first = cms_content_for_retrieval('First limit title', 'First body');
    $second = cms_content_for_retrieval('Second limit title', 'Second body');
    $third = cms_content_for_retrieval('Third limit title', 'Third body');
    $authorization = new ApplicationContentAuthorization('default.contents.select', null);

    $exact = cms_retrieval_provider_with_hits([
        ['id' => (string) $first->getKey(), 'score' => 1.0, 'source' => []],
        ['id' => (string) $second->getKey(), 'score' => 0.8, 'source' => []],
    ], ['strategies' => ['keyword']])->retrieve(cms_retrieval_query(limit: 2), $authorization);

    $overflow = cms_retrieval_provider_with_hits([
        ['id' => (string) $first->getKey(), 'score' => 1.0, 'source' => []],
        ['id' => (string) $second->getKey(), 'score' => 0.8, 'source' => []],
        ['id' => (string) $third->getKey(), 'score' => 0.6, 'source' => []],
    ], ['strategies' => ['keyword']])->retrieve(cms_retrieval_query(limit: 2), $authorization);

    expect($exact->hits)->toHaveCount(2)
        ->and($exact->truncated)->toBeFalse()
        ->and($overflow->hits)->toHaveCount(2)
        ->and($overflow->truncated)->toBeTrue();
});

/**
 * A provider whose engine records the filters of every search it receives and finds nothing.
 *
 * @param  list<FiltersGroup|null>  $received
 */
function cms_retrieval_provider_recording_filters(array &$received): CmsApplicationContentRetrievalProvider
{
    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsOrchestratedSearch')->andReturnTrue();
    $engine->shouldReceive('supportsOrchestratedVectorSearch')->andReturnFalse();
    app(EngineManager::class)->extend('cms-application-content-test', static fn () => $engine);
    config()->set('scout.driver', 'cms-application-content-test');

    $ensemble = Mockery::mock(EnsembleSearchService::class);
    $ensemble->shouldReceive('search')->andReturnUsing(static function (Model $model, string $query, array $plan, ?array $vector, int $page, int $perPage, ?FiltersGroup $filters = null) use (&$received): AdvancedSearchResult {
        $received[] = $filters;

        return AdvancedSearchResult::empty($page, $perPage);
    });

    return new CmsApplicationContentRetrievalProvider(
        new AdvancedSearchService(
            new FixedSearchStrategyResolver(planner: new FallbackSearchPlanner, intent_parser: new SimpleQueryIntentParser),
            $ensemble,
            app(),
        ),
        app(AuthorizationService::class),
        app(QueryBuilder::class),
        new CmsContentEvidenceProjector,
    );
}

/**
 * The column filters of a tree, at any depth, as `[property, value]` pairs.
 *
 * @return list<array{0: string, 1: mixed}>
 */
function cms_retrieval_column_filters(?FiltersGroup $filters): array
{
    if (! $filters instanceof FiltersGroup) {
        return [];
    }

    $columns = [];

    foreach ($filters->filters as $node) {
        if ($node instanceof FiltersGroup) {
            $columns = [...$columns, ...cms_retrieval_column_filters($node)];
        } elseif ($node instanceof Filter) {
            $columns[] = [$node->property, $node->value];
        }
    }

    return $columns;
}

it('keeps extended contents out of the engine query of the assistant retrieval, with the authorization filters', function (): void {
    $received = [];
    $authorization = new ApplicationContentAuthorization(
        'default.contents.select',
        new FiltersGroup([new Filter('id', 42)]),
    );

    cms_retrieval_provider_recording_filters($received)->retrieve(cms_retrieval_query(), $authorization);

    expect($received)->not->toBeEmpty();

    foreach ($received as $filters) {
        $columns = cms_retrieval_column_filters($filters);

        expect($columns)->toContain(['extended_type', null])
            ->and($columns)->toContain(['id', 42])
            ->and(array_filter($columns, static fn (array $column): bool => $column[0] === 'extended_type'))->toHaveCount(1);
    }
});
