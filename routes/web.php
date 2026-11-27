<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CMS\Http\Controllers\InsightsController;
use Modules\CMS\Http\Controllers\LocationsController;
use Modules\CMS\Http\Controllers\TagTypesController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::middleware('auth')->prefix('cms/locations')->group(function (): void {
    Route::get('/geocode', [LocationsController::class, 'geocode'])->name('locations.geocode');
});

Route::middleware('auth')->prefix('cms/insights')->group(function (): void {
    Route::get('/graph/tags', [InsightsController::class, 'tagGraph'])->name('insights.tag-graph');
});

Route::middleware('auth')->prefix('cms/tag-types')->group(function (): void {
    Route::get('/', [TagTypesController::class, 'index'])->name('tag-types.index');
});
