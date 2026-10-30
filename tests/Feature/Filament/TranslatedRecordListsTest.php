<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Modules\CMS\Filament\Resources\Tags\Pages\ListTags;
use Modules\CMS\Models\Tag;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Tests\Support\HttpContext;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    setupCMSEntities();

    $this->untranslated = Tag::factory()->create();
    $this->untranslated->setTranslation('en', ['name' => 'Only English', 'slug' => 'only-english']);
    $this->untranslated->translations()->where('locale', '!=', 'en')->forceDelete();

    $this->translated = Tag::factory()->create();
    $this->translated->setTranslation('en', ['name' => 'English', 'slug' => 'english']);
    $this->translated->setTranslation('de', ['name' => 'Deutsch', 'slug' => 'deutsch']);

    // The backoffice works in a content locale of its own; the page test does not run the panel middleware.
    LocaleContext::setContentLocale('de');
    HttpContext::panelActorWithoutApproval(new Tag, ['select']);
});

it('shows the default text, marked with its locale, where the content locale has no translation', function (): void {
    Livewire::test(ListTags::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$this->untranslated, $this->translated])
        ->assertTableColumnStateSet('name', 'Only English', $this->untranslated)
        ->assertTableColumnFormattedStateSet('name', new HtmlString(
            'Only English <span class="fi-ta-text-description" title="' . e(__('app.tables.shownInLocale', ['locale' => 'EN'])) . '">(EN)</span>',
        ), $this->untranslated)
        ->assertTableColumnDoesNotHaveDescription('name', __('app.tables.shownInLocale', ['locale' => 'EN']), $this->untranslated)
        ->assertTableColumnStateSet('name', 'Deutsch', $this->translated)
        ->assertTableColumnFormattedStateSet('name', 'Deutsch', $this->translated);
});

it('filters the records with no translation in the content locale', function (): void {
    Livewire::test(ListTags::class)
        ->loadTable()
        ->filterTable('missing_translation')
        ->assertCanSeeTableRecords([$this->untranslated])
        ->assertCanNotSeeTableRecords([$this->translated]);
});
