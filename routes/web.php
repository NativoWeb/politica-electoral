<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Admin\CatalogCrudController;
use App\Http\Controllers\Admin\EleccionCrudController;
use App\Http\Controllers\Admin\PersonCrudController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ComparadorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LegislativoController;
use App\Http\Controllers\MunicipioController;
use App\Http\Controllers\PartidoController;
use App\Http\Controllers\PersonaController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\LiderController;
use App\Http\Controllers\MapaPoliticoController;
use App\Http\Controllers\OfflineController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// Auth routes (guest only)
Route::get('/login', [LoginController::class, 'showLogin'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'login'])->middleware('guest');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// Public API (no auth required)
Route::get('/api/search', [SearchController::class, 'search'])->name('search');
Route::get('/api/comparar', [ComparadorController::class, 'comparar'])->name('comparar.api');

// Offline API (auth required)
Route::middleware('auth')->get('/api/offline/download/{municipioId}', [OfflineController::class, 'download'])->name('offline.download');

// Protected routes
Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/municipio/{id}', [MunicipioController::class, 'show'])->name('municipio.show');
    Route::get('/persona/{id}', [PersonaController::class, 'show'])->name('persona.show');
    Route::get('/partido/{id}', [PartidoController::class, 'show'])->name('partido.show');
    Route::get('/senado', [LegislativoController::class, 'senado'])->name('senado');
    Route::get('/senado/{id}', [LegislativoController::class, 'senadoMunicipio'])->name('senado.municipio');
    Route::get('/camara', [LegislativoController::class, 'camara'])->name('camara');
    Route::get('/camara/{id}', [LegislativoController::class, 'camaraMunicipio'])->name('camara.municipio');
    Route::get('/concejo', [LegislativoController::class, 'concejo'])->name('concejo');
    Route::get('/concejo/{id}', [LegislativoController::class, 'concejoMunicipio'])->name('concejo.municipio');
    Route::get('/gobernador', [LegislativoController::class, 'gobernador'])->name('gobernador');
    Route::get('/gobernador/{id}', [LegislativoController::class, 'gobernadorMunicipio'])->name('gobernador.municipio');
    Route::get('/asamblea', [LegislativoController::class, 'asamblea'])->name('asamblea');
    Route::get('/asamblea/{id}', [LegislativoController::class, 'asambleaMunicipio'])->name('asamblea.municipio');
    Route::get('/consultas', [LegislativoController::class, 'consultas'])->name('consultas');
    Route::get('/lideres', [LiderController::class, 'index'])->name('lideres');
    Route::get('/lideres/{id}', [LiderController::class, 'show'])->name('lideres.show');
    Route::get('/mapa-politico', [MapaPoliticoController::class, 'index'])->name('mapa-politico');
    Route::get('/mapa-politico/persona/{id}', [MapaPoliticoController::class, 'persona'])->name('mapa-politico.persona');

    // Write routes — require can.write middleware (#4 RBAC)
    Route::middleware('can.write')->group(function () {
        Route::post('/mapa-politico/crear-lider', [MapaPoliticoController::class, 'storeLider'])->name('mapa-politico.lider.store');
        Route::put('/mapa-politico/persona/{id}', [MapaPoliticoController::class, 'updatePersona'])->name('mapa-politico.persona.update');
        Route::post('/mapa-politico/persona/{id}/nexos', [MapaPoliticoController::class, 'storeNexo'])->name('mapa-politico.nexo.store');
        Route::put('/mapa-politico/nexos/{id}', [MapaPoliticoController::class, 'updateNexo'])->name('mapa-politico.nexo.update');
        Route::delete('/mapa-politico/nexos/{id}', [MapaPoliticoController::class, 'destroyNexo'])->name('mapa-politico.nexo.destroy');
    });
    Route::get('/analisis', [ComparadorController::class, 'index'])->name('comparador');

    // Exports
    Route::get('/exportar/excel', [ExportController::class, 'excel'])->name('export.excel');
    Route::get('/exportar/pdf', [ExportController::class, 'pdf'])->name('export.pdf');

    // PDF Reports
    Route::get('/reporte/persona/{id}', [ReportController::class, 'persona'])->name('reporte.persona');
    Route::get('/reporte/municipio/{id}', [ReportController::class, 'municipio'])->name('reporte.municipio');

    // Admin panel
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::get('/usuarios', [AdminController::class, 'users'])->name('users');
        Route::post('/usuarios', [AdminController::class, 'storeUser'])->name('users.store');
        Route::post('/usuarios/{id}/toggle', [AdminController::class, 'toggleUser'])->name('users.toggle');
        Route::get('/importaciones', [AdminController::class, 'imports'])->name('imports');
        Route::get('/catalogos', [AdminController::class, 'catalogs'])->name('catalogs');

        // Personas CRUD
        Route::get('/personas', [PersonCrudController::class, 'index'])->name('personas');
        Route::get('/personas/{id}/json', [PersonCrudController::class, 'show'])->name('personas.show');
        Route::post('/personas', [PersonCrudController::class, 'store'])->name('personas.store');
        Route::put('/personas/{id}', [PersonCrudController::class, 'update'])->name('personas.update');
        Route::delete('/personas/{id}', [PersonCrudController::class, 'destroy'])->name('personas.destroy');
        Route::post('/personas/{id}/aliases', [PersonCrudController::class, 'storeAlias'])->name('personas.alias.store');
        Route::delete('/personas/{id}/aliases/{aliasId}', [PersonCrudController::class, 'destroyAlias'])->name('personas.alias.destroy');
        Route::post('/personas/{id}/contacts', [PersonCrudController::class, 'storeContact'])->name('personas.contact.store');
        Route::delete('/personas/{id}/contacts/{contactId}', [PersonCrudController::class, 'destroyContact'])->name('personas.contact.destroy');

        // Catálogos CRUD
        Route::post('/catalogos/partidos', [CatalogCrudController::class, 'storePartido'])->name('catalogs.partido.store');
        Route::put('/catalogos/partidos/{id}', [CatalogCrudController::class, 'updatePartido'])->name('catalogs.partido.update');
        Route::delete('/catalogos/partidos/{id}', [CatalogCrudController::class, 'destroyPartido'])->name('catalogs.partido.destroy');
        Route::post('/catalogos/oficios', [CatalogCrudController::class, 'storeOficio'])->name('catalogs.oficio.store');
        Route::put('/catalogos/oficios/{id}', [CatalogCrudController::class, 'updateOficio'])->name('catalogs.oficio.update');
        Route::delete('/catalogos/oficios/{id}', [CatalogCrudController::class, 'destroyOficio'])->name('catalogs.oficio.destroy');
        Route::post('/catalogos/corporaciones', [CatalogCrudController::class, 'storeCorporacion'])->name('catalogs.corporacion.store');
        Route::put('/catalogos/corporaciones/{id}', [CatalogCrudController::class, 'updateCorporacion'])->name('catalogs.corporacion.update');
        Route::delete('/catalogos/corporaciones/{id}', [CatalogCrudController::class, 'destroyCorporacion'])->name('catalogs.corporacion.destroy');

        // Elecciones CRUD
        Route::get('/elecciones', [EleccionCrudController::class, 'index'])->name('elecciones');
        Route::get('/elecciones/{id}/json', [EleccionCrudController::class, 'showEvent'])->name('elecciones.show');
        Route::get('/elecciones/contest/{id}/json', [EleccionCrudController::class, 'showContest'])->name('elecciones.contest.show');
        Route::post('/elecciones/events', [EleccionCrudController::class, 'storeEvent'])->name('elecciones.event.store');
        Route::put('/elecciones/events/{id}', [EleccionCrudController::class, 'updateEvent'])->name('elecciones.event.update');
        Route::delete('/elecciones/events/{id}', [EleccionCrudController::class, 'destroyEvent'])->name('elecciones.event.destroy');
        Route::post('/elecciones/contests', [EleccionCrudController::class, 'storeContest'])->name('elecciones.contest.store');
        Route::post('/elecciones/candidacies', [EleccionCrudController::class, 'storeCandidacy'])->name('elecciones.candidacy.store');
        Route::put('/elecciones/candidacies/{id}', [EleccionCrudController::class, 'updateCandidacy'])->name('elecciones.candidacy.update');
        Route::post('/elecciones/results', [EleccionCrudController::class, 'storeResult'])->name('elecciones.result.store');
        Route::put('/elecciones/results/{id}', [EleccionCrudController::class, 'updateResult'])->name('elecciones.result.update');
    });
});
