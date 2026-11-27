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

it('pins the icon pair of each CMS type', function (): void {
    $pairs = [];

    foreach (app(TagTypeRegistry::class)->all() as $definition) {
        $pairs[$definition->type] = [$definition->heroicon, $definition->lucide];
    }

    expect($pairs)->toBe([
        'search' => ['heroicon-o-magnifying-glass', 'Search'],
        'people' => ['heroicon-o-users', 'Users'],
        'locations' => ['heroicon-o-map-pin', 'MapPin'],
        'organizations' => ['heroicon-o-building-office', 'Building2'],
        'topics' => ['heroicon-o-hashtag', 'Hash'],
        'events' => ['heroicon-o-calendar', 'Calendar'],
    ]);
});

it('pins the label key of each CMS type', function (): void {
    $labelKeys = array_map(
        static fn (TagTypeDefinition $definition): string => $definition->labelKey,
        app(TagTypeRegistry::class)->all(),
    );

    expect($labelKeys)->toBe([
        'cms.tag_types.search',
        'cms.tag_types.people',
        'cms.tag_types.locations',
        'cms.tag_types.organizations',
        'cms.tag_types.topics',
        'cms.tag_types.events',
    ]);
});
