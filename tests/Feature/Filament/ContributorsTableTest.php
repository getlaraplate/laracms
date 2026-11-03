<?php

declare(strict_types=1);

use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Filament\Resources\Contributors\Tables\ContributorsTable;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake(config('media-library.disk_name'));

    setupCMSEntities([EntityType::Contributors]);

    $this->table = Table::make($this->createStub(HasTable::class))
        ->query(fn () => Contributor::query());

    ContributorsTable::configure($this->table);
});

it('places the avatar after the text columns', function (): void {
    $names = array_keys($this->table->getColumns());

    expect(array_search('cover', $names, true))
        ->toBeGreaterThan(array_search('name', $names, true))
        ->toBeGreaterThan(array_search('components.email', $names, true));
});

it('shows the low quality thumbnail of the contributor cover', function (): void {
    $file = UploadedFile::fake()->image('avatar.jpg');
    $contributor = Contributor::factory()->create();
    $contributor->addMedia($file->getRealPath())
        ->usingFileName('avatar.jpg')
        ->toMediaCollection('cover');

    $column = $this->table->getColumns()['cover']->record($contributor->fresh());

    expect($column->getState())->toBe($contributor->getFirstMedia('cover')->getUrl('thumb-low'));
});

it('keeps the avatar row as tall as a text row', function (): void {
    $contributor = Contributor::factory()->create();

    $html = $this->table->getColumns()['cover']->record($contributor->fresh())->toEmbeddedHtml();

    expect($html)
        ->toContain('height: 2rem')
        ->toContain('padding-block: 0.625rem');
});

it('falls back to the generated avatar when the contributor has no cover', function (): void {
    $contributor = Contributor::factory()->create();

    $column = $this->table->getColumns()['cover']->record($contributor->fresh());

    expect($column->getState())->toBeNull()
        ->and($column->getDefaultImageUrl())->toStartWith('https://ui-avatars.com/api/');
});
