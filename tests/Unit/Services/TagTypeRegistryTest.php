<?php

declare(strict_types=1);

use Modules\CMS\Services\TagTypeDefinition;
use Modules\CMS\Services\TagTypeRegistry;
use Modules\CMS\Tests\TestCase;

uses(TestCase::class);

it('registers the six CMS types in order', function (): void {
    $types = array_map(
        static fn (TagTypeDefinition $definition): string => $definition->type,
        app(TagTypeRegistry::class)->all(),
    );

    expect($types)->toBe(['search', 'people', 'locations', 'organizations', 'topics', 'events']);
});

it('lets a module register its own type and replace one', function (): void {
    $registry = new TagTypeRegistry;
    $registry->register(new TagTypeDefinition('topics', 'cms.tag_types.topics', 'heroicon-o-hashtag', 'Hash'));
    $registry->register(new TagTypeDefinition('events', 'cms.tag_types.events', 'heroicon-o-calendar', 'Calendar'));
    $registry->register(new TagTypeDefinition('Brands', 'cms.tag_types.brands', 'heroicon-o-star', 'Star'));
    $registry->register(new TagTypeDefinition('topics', 'cms.tag_types.topics', 'heroicon-o-bookmark', 'Bookmark'));

    $all = $registry->all();

    expect(array_map(static fn (TagTypeDefinition $d): string => $d->type, $all))->toBe(['topics', 'events', 'brands'])
        ->and($all[0]->heroicon)->toBe('heroicon-o-bookmark');
});

it('resolves icons and letters', function (): void {
    $registry = app(TagTypeRegistry::class);

    expect($registry->heroiconFor('Search'))->toBe('heroicon-o-magnifying-glass')
        ->and($registry->heroiconFor(null))->toBe('heroicon-o-tag')
        ->and($registry->heroiconFor('musical-instrument'))->toBeNull()
        ->and($registry->letterFor('musical-instrument'))->toBe('M')
        ->and($registry->letterFor('people'))->toBeNull()
        ->and($registry->letterFor(null))->toBeNull();
});
