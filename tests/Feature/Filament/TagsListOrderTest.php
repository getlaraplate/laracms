<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\CMS\Filament\Resources\Tags\Pages\ListTags;
use Modules\CMS\Models\Tag;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Tests\Support\HttpContext;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    setupCMSEntities();
});

it('lists the tags by the slug they show, the content locale first and the default locale where it is missing', function (): void {
    $tag_with_slugs = static function (array $slugs_by_locale): Tag {
        $tag = Tag::factory()->create();

        foreach ($slugs_by_locale as $locale => $slug) {
            $tag->setTranslation($locale, ['name' => ucfirst($slug), 'slug' => $slug]);
        }

        $tag->translations()->whereNotIn('locale', array_keys($slugs_by_locale))->forceDelete();

        return $tag;
    };

    $charlie = $tag_with_slugs(['en' => 'charlie']);
    $zulu = $tag_with_slugs(['en' => 'alpha', 'de' => 'zulu']);
    $bravo = $tag_with_slugs(['en' => 'bravo']);

    // The backoffice works in a content locale of its own; the page test does not run the panel middleware.
    LocaleContext::setContentLocale('de');
    HttpContext::panelActorWithoutApproval(new Tag, ['select']);

    Livewire::test(ListTags::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$bravo, $charlie, $zulu], inOrder: true);
});
