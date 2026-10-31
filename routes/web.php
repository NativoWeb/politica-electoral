<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Controllers\Admin\CatalogCrudController;
use App\Http\Controllers\Admin\EleccionCrudController;
use App\Http\Controllers\Admin\PersonCrudController;
use App\Http\Controllers\Admin\WhatsAppController;
use App\Http\Controllers\Admin\WhatsAppTemplateController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CargoCategoryController;
use App\Http\Controllers\ComparadorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\LegislativoController;
use App\Http\Controllers\LiderController;
use App\Http\Controllers\MapaPoliticoController;
use App\Http\Controllers\MunicipioController;
use App\Http\Controllers\OfflineController;
use App\Http\Controllers\PartidoController;
use App\Http\Controllers\PartidoCrudController;
use App\Http\Controllers\PersonaController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TerritoryController;
use App\Http\Controllers\VotanteController;
use App\Http\Controllers\WhatsAppLinkController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Auth routes (guest only)
Route::get('/login', [LoginController::class, 'showLogin'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'login'])->name('login.attempt')->middleware('guest');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware(['auth', 'audit']);

// Password reset (guest only)
Route::middleware('guest')->group(function () {
    Route::get('/olvide-password', [LoginController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/olvide-password', [LoginController::class, 'sendResetLink'])->name('password.email');
    Route::get('/resetear-password/{token}', [LoginController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/resetear-password', [LoginController::class, 'resetPassword'])->name('password.store');
});

// Force password change (auth but exempt from password.changed middleware)
Route::middleware('auth')->group(function () {
    Route::get('/forzar-cambio-password', [LoginController::class, 'showForceChangePassword'])->name('password.force-change');
    Route::post('/forzar-cambio-password', [LoginController::class, 'forceChangePassword'])->name('password.force-change.store');
});

// Public API (no auth required)
Route::middleware('auth')->get('/api/search', [SearchController::class, 'search'])->name('search');
Route::middleware('auth')->get('/api/comparar', [ComparadorController::class, 'comparar'])->name('comparar.api');

// Offline API: it packs a municipio's Mapa Politico data and only the map downloads it
Route::middleware(['auth', 'password.changed', 'audit', 'permission:view.mapa'])->get('/api/offline/download/{municipioId}', [OfflineController::class, 'download'])->name('offline.download');

// Protected routes
Route::middleware(['auth', 'password.changed', 'audit'])->group(function () {
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
    Route::get('/gobernador', [LegislativoController::class, 'gobernador'])->name('gobernador')->middleware('permission:view.gobernador');
    Route::get('/gobernador/{id}', [LegislativoController::class, 'gobernadorMunicipio'])->name('gobernador.municipio')->middleware('permission:view.gobernador');
    Route::get('/asamblea', [LegislativoController::class, 'asamblea'])->name('asamblea');
    Route::get('/asamblea/{id}', [LegislativoController::class, 'asambleaMunicipio'])->name('asamblea.municipio');
    Route::get('/consultas', [LegislativoController::class, 'consultas'])->name('consultas');
    // Lideres are shown by the map and by Votantes: either view lets them in, nothing else does.
    Route::middleware('permission:view.mapa|view.votantes')->group(function () {
        Route::get('/lideres', [LiderController::class, 'index'])->name('lideres');
        Route::get('/lideres/{id}', [LiderController::class, 'show'])->name('lideres.show');

        // Búsqueda de líderes para referidos (persona panel of the map and VotanteDetail)
        Route::get('/mapa-politico/search-lideres', [MapaPoliticoController::class, 'searchLideres'])->name('mapa-politico.search-lideres');
    });

    Route::middleware('permission:view.mapa')->group(function () {
        Route::get('/mapa-politico', [MapaPoliticoController::class, 'index'])->name('mapa-politico');
        Route::get('/mapa-politico/persona/{id}', [MapaPoliticoController::class, 'persona'])->name('mapa-politico.persona');
        Route::get('/mapa-politico/persona/{id}/posibles-duplicados', [MapaPoliticoController::class, 'posiblesDuplicados'])->name('mapa-politico.persona.duplicados');
    });

    // Votantes
    Route::middleware('permission:view.votantes')->group(function () {
        Route::get('/votantes', [VotanteController::class, 'index'])->name('votantes.index');
        Route::get('/votantes/{id}', [VotanteController::class, 'show'])->name('votantes.show');
        Route::get('/votantes/{id}/referidos', [VotanteController::class, 'referidos'])->name('votantes.referidos');
    });

    // Write routes — require can.write middleware (#4 RBAC)
    Route::middleware('can.write')->group(function () {
        Route::middleware('permission:view.mapa')->group(function () {
            Route::post('/mapa-politico/crear-lider', [MapaPoliticoController::class, 'storeLider'])->name('mapa-politico.lider.store');
            Route::put('/mapa-politico/persona/{id}', [MapaPoliticoController::class, 'updatePersona'])->name('mapa-politico.persona.update');
            Route::post('/mapa-politico/persona/{id}/nexos', [MapaPoliticoController::class, 'storeNexo'])->name('mapa-politico.nexo.store');
            Route::put('/mapa-politico/nexos/{id}', [MapaPoliticoController::class, 'updateNexo'])->name('mapa-politico.nexo.update');
            Route::delete('/mapa-politico/nexos/{id}', [MapaPoliticoController::class, 'destroyNexo'])->name('mapa-politico.nexo.destroy');
            Route::delete('/mapa-politico/persona/{id}', [MapaPoliticoController::class, 'destroyLider'])->name('mapa-politico.persona.destroy');
            Route::post('/mapa-politico/persona/{id}/unificar', [MapaPoliticoController::class, 'mergeLider'])->name('mapa-politico.persona.merge');
            Route::post('/mapa-politico/persona/{id}/municipios-influencia', [MapaPoliticoController::class, 'storeMunicipioInfluencia'])->name('mapa-politico.influencia.store');
            Route::delete('/mapa-politico/persona/{id}/municipios-influencia/{influenciaId}', [MapaPoliticoController::class, 'destroyMunicipioInfluencia'])->name('mapa-politico.influencia.destroy');
        });

        // Shared by both screens: VotanteDetail toggles and links referidos through the map's endpoints,
        // and the map's persona panel edits contactos and info politica through the Votantes ones.
        Route::middleware('permission:view.mapa|view.votantes')->group(function () {
            Route::post('/mapa-politico/persona/{id}/toggle', [MapaPoliticoController::class, 'toggleLider'])->name('mapa-politico.persona.toggle');
            Route::post('/mapa-politico/persona/{id}/add-referido', [MapaPoliticoController::class, 'addReferido'])->name('mapa-politico.referido.add');
            Route::delete('/mapa-politico/persona/{id}/referido/{referidoId}', [MapaPoliticoController::class, 'removeReferido'])->name('mapa-politico.referido.remove');

            Route::post('/votantes/{id}/contactos', [VotanteController::class, 'storeContacto'])->name('votantes.contactos.store');
            Route::put('/votantes/contactos/{id}', [VotanteController::class, 'updateContacto'])->name('votantes.contactos.update');
            Route::delete('/votantes/contactos/{id}', [VotanteController::class, 'destroyContacto'])->name('votantes.contactos.destroy');
            Route::post('/votantes/{id}/info-politica', [VotanteController::class, 'storeInfoPolitica'])->name('votantes.info-politica.store');
            Route::put('/votantes/info-politica/{id}', [VotanteController::class, 'updateInfoPolitica'])->name('votantes.info-politica.update');
            Route::delete('/votantes/info-politica/{id}', [VotanteController::class, 'destroyInfoPolitica'])->name('votantes.info-politica.destroy');
        });

        Route::middleware('permission:view.votantes')->group(function () {
            Route::post('/votantes', [VotanteController::class, 'store'])->name('votantes.store');
            Route::put('/votantes/{id}', [VotanteController::class, 'update'])->name('votantes.update');
            Route::delete('/votantes/{id}', [VotanteController::class, 'destroy'])->name('votantes.destroy');
        });
    });

    // Change own password
    Route::post('/cambiar-password', function (Request $request) {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if (! Hash::check($request->current_password, $request->user()->password)) {
            return back()->withErrors(['current_password' => 'La contraseña actual no es correcta']);
        }

        $request->user()->update(['password' => Hash::make($request->new_password)]);

        return back()->with('success', 'Contraseña actualizada');
    })->name('password.update');

    Route::get('/perfil', fn () => Inertia::render('Perfil'))->name('perfil');

    // WhatsApp agent linking (the one-time code is returned once, as JSON)
    Route::post('/perfil/whatsapp/codigo', [WhatsAppLinkController::class, 'store'])
        ->middleware('throttle:10,1')->name('perfil.whatsapp.codigo');
    Route::delete('/perfil/whatsapp', [WhatsAppLinkController::class, 'destroy'])->name('perfil.whatsapp.destroy');

    Route::get('/analisis', [ComparadorController::class, 'index'])->name('comparador');

    // Exports
    Route::get('/exportar/excel', [ExportController::class, 'excel'])->name('export.excel');
    Route::get('/exportar/pdf', [ExportController::class, 'pdf'])->name('export.pdf');

    // PDF Reports
    Route::get('/reporte/persona/{id}', [ReportController::class, 'persona'])->name('reporte.persona');
    Route::get('/reporte/municipio/{id}', [ReportController::class, 'municipio'])->name('reporte.municipio');

    // Campaigns: superadmin only, outside view.admin so any other role gets a 403 and not a redirect
    Route::prefix('admin/campanas')->name('admin.campaigns.')->middleware('superadmin')->group(function () {
        Route::get('/', [CampaignController::class, 'index'])->name('index');
        Route::get('/candidatos', [CampaignController::class, 'candidates'])->name('candidates');
        Route::post('/', [CampaignController::class, 'store'])->name('store');
        Route::put('/{id}', [CampaignController::class, 'update'])->name('update')->whereUuid('id');
        Route::post('/{id}/toggle', [CampaignController::class, 'toggle'])->name('toggle')->whereUuid('id');
    });

    // Admin panel — requires view.admin permission
    Route::prefix('admin')->name('admin.')->middleware('permission:view.admin')->group(function () {
        Route::get('/auditoria', [AuditLogController::class, 'index'])->name('auditoria');
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::middleware('permission:manage.users')->group(function () {
            Route::get('/usuarios', [AdminController::class, 'users'])->name('users');
            Route::post('/usuarios', [AdminController::class, 'storeUser'])->name('users.store');
            Route::put('/usuarios/{id}', [AdminController::class, 'updateUser'])->name('users.update');
            Route::post('/usuarios/{id}/toggle', [AdminController::class, 'toggleUser'])->name('users.toggle');
            Route::delete('/usuarios/{id}', [AdminController::class, 'destroyUser'])->name('users.destroy');
        });

        // Platform screens (shared electoral data): read and write only for whoever edits it, the rest get a 403.
        Route::get('/territorio', [TerritoryController::class, 'index'])->name('territory')->middleware('electoral.platform');
        Route::post('/territorio/departamento', [TerritoryController::class, 'storeDepartamento'])->name('territory.departamento.store')->middleware('electoral.platform');
        Route::post('/territorio/provincia', [TerritoryController::class, 'storeProvincia'])->name('territory.provincia.store')->middleware('electoral.platform');
        Route::post('/territorio/municipio', [TerritoryController::class, 'storeMunicipio'])->name('territory.municipio.store')->middleware('electoral.platform');
        Route::put('/territorio/{id}', [TerritoryController::class, 'update'])->name('territory.update')->middleware('electoral.platform');
        Route::post('/territorio/{id}/toggle', [TerritoryController::class, 'toggleActive'])->name('territory.toggle')->middleware('electoral.platform');

        Route::get('/partidos', [PartidoCrudController::class, 'index'])->name('partidos')->middleware('electoral.platform');
        Route::post('/partidos', [PartidoCrudController::class, 'store'])->name('partidos.store')->middleware('electoral.platform');
        Route::put('/partidos/{id}', [PartidoCrudController::class, 'update'])->name('partidos.update')->middleware('electoral.platform');
        Route::delete('/partidos/{id}', [PartidoCrudController::class, 'destroy'])->name('partidos.destroy')->middleware('electoral.platform');
        Route::post('/partidos/{id}/restore', [PartidoCrudController::class, 'restore'])->name('partidos.restore')->middleware('electoral.platform');
        Route::delete('/partidos/{id}/force', [PartidoCrudController::class, 'forceDelete'])->name('partidos.forceDelete')->middleware('electoral.platform');

        Route::get('/importaciones', [AdminController::class, 'imports'])->name('imports')->middleware('electoral.platform');
        Route::get('/catalogos', [AdminController::class, 'catalogs'])->name('catalogs')->middleware('electoral.platform');

        // Personas CRUD
        Route::get('/personas', [PersonCrudController::class, 'index'])->name('personas')->middleware('electoral.platform');
        Route::get('/personas/{id}/json', [PersonCrudController::class, 'show'])->name('personas.show')->middleware('electoral.platform');
        Route::post('/personas', [PersonCrudController::class, 'store'])->name('personas.store')->middleware('electoral.platform');
        Route::put('/personas/{id}', [PersonCrudController::class, 'update'])->name('personas.update')->middleware('electoral.platform');
        Route::delete('/personas/{id}', [PersonCrudController::class, 'destroy'])->name('personas.destroy')->middleware('electoral.platform');
        Route::post('/personas/{id}/aliases', [PersonCrudController::class, 'storeAlias'])->name('personas.alias.store')->middleware('electoral.platform');
        Route::delete('/personas/{id}/aliases/{aliasId}', [PersonCrudController::class, 'destroyAlias'])->name('personas.alias.destroy')->middleware('electoral.platform');
        Route::post('/personas/{id}/contacts', [PersonCrudController::class, 'storeContact'])->name('personas.contact.store')->middleware('electoral.platform');
        Route::delete('/personas/{id}/contacts/{contactId}', [PersonCrudController::class, 'destroyContact'])->name('personas.contact.destroy')->middleware('electoral.platform');

        // WhatsApp: templates, recipients and sends of the user's own campaign, if it has WhatsApp enabled
        Route::middleware('campaign.whatsapp')->group(function () {
            Route::get('/whatsapp', [WhatsAppController::class, 'index'])->name('whatsapp');

            Route::get('/whatsapp/sends/{send}/status', [WhatsAppController::class, 'status'])
                ->name('whatsapp.sends.status');

            Route::get('/whatsapp/birthdays/history', [WhatsAppController::class, 'birthdayHistory'])
                ->name('whatsapp.birthdays.history');

            Route::post('/whatsapp/prepare', [WhatsAppController::class, 'prepare'])
                ->name('whatsapp.prepare');

            Route::get('/whatsapp/templates', [WhatsAppTemplateController::class, 'index'])
                ->name('whatsapp.templates');

            Route::post('/whatsapp/templates', [WhatsAppTemplateController::class, 'store'])
                ->name('whatsapp.templates.store');

            Route::put('/whatsapp/templates/{whatsappTemplate}', [WhatsAppTemplateController::class, 'update'])
                ->name('whatsapp.templates.update');

            Route::delete('/whatsapp/templates/{whatsappTemplate}', [WhatsAppTemplateController::class, 'destroy'])
                ->name('whatsapp.templates.destroy');
        });

        // Cargos CRUD
        Route::get('/cargos', [CargoCategoryController::class, 'index'])->name('cargos')->middleware('electoral.platform');
        Route::middleware('can.write')->group(function () {
            Route::post('/cargos', [CargoCategoryController::class, 'store'])->name('cargos.store')->middleware('electoral.platform');
            Route::put('/cargos/{id}', [CargoCategoryController::class, 'update'])->name('cargos.update')->middleware('electoral.platform');
            Route::post('/cargos/reorder', [CargoCategoryController::class, 'reorder'])->name('cargos.reorder')->middleware('electoral.platform');
            Route::post('/cargos/graduar', [CargoCategoryController::class, 'graduar'])->name('cargos.graduar')->middleware('electoral.platform');
        });

        // Catálogos CRUD
        Route::post('/catalogos/partidos', [CatalogCrudController::class, 'storePartido'])->name('catalogs.partido.store')->middleware('electoral.platform');
        Route::put('/catalogos/partidos/{id}', [CatalogCrudController::class, 'updatePartido'])->name('catalogs.partido.update')->middleware('electoral.platform');
        Route::delete('/catalogos/partidos/{id}', [CatalogCrudController::class, 'destroyPartido'])->name('catalogs.partido.destroy')->middleware('electoral.platform');
        Route::post('/catalogos/oficios', [CatalogCrudController::class, 'storeOficio'])->name('catalogs.oficio.store')->middleware('electoral.platform');
        Route::put('/catalogos/oficios/{id}', [CatalogCrudController::class, 'updateOficio'])->name('catalogs.oficio.update')->middleware('electoral.platform');
        Route::delete('/catalogos/oficios/{id}', [CatalogCrudController::class, 'destroyOficio'])->name('catalogs.oficio.destroy')->middleware('electoral.platform');
        Route::post('/catalogos/corporaciones', [CatalogCrudController::class, 'storeCorporacion'])->name('catalogs.corporacion.store')->middleware('electoral.platform');
        Route::put('/catalogos/corporaciones/{id}', [CatalogCrudController::class, 'updateCorporacion'])->name('catalogs.corporacion.update')->middleware('electoral.platform');
        Route::delete('/catalogos/corporaciones/{id}', [CatalogCrudController::class, 'destroyCorporacion'])->name('catalogs.corporacion.destroy')->middleware('electoral.platform');

        // Elecciones CRUD
        Route::get('/elecciones', [EleccionCrudController::class, 'index'])->name('elecciones')->middleware('electoral.platform');
        Route::get('/elecciones/{id}/json', [EleccionCrudController::class, 'showEvent'])->name('elecciones.show')->middleware('electoral.platform');
        Route::get('/elecciones/contest/{id}/json', [EleccionCrudController::class, 'showContest'])->name('elecciones.contest.show')->middleware('electoral.platform');
        Route::post('/elecciones/events', [EleccionCrudController::class, 'storeEvent'])->name('elecciones.event.store')->middleware('electoral.platform');
        Route::put('/elecciones/events/{id}', [EleccionCrudController::class, 'updateEvent'])->name('elecciones.event.update')->middleware('electoral.platform');
        Route::delete('/elecciones/events/{id}', [EleccionCrudController::class, 'destroyEvent'])->name('elecciones.event.destroy')->middleware('electoral.platform');
        Route::post('/elecciones/contests', [EleccionCrudController::class, 'storeContest'])->name('elecciones.contest.store')->middleware('electoral.platform');
        Route::post('/elecciones/candidacies', [EleccionCrudController::class, 'storeCandidacy'])->name('elecciones.candidacy.store')->middleware('electoral.platform');
        Route::put('/elecciones/candidacies/{id}', [EleccionCrudController::class, 'updateCandidacy'])->name('elecciones.candidacy.update')->middleware('electoral.platform');
        Route::post('/elecciones/results', [EleccionCrudController::class, 'storeResult'])->name('elecciones.result.store')->middleware('electoral.platform');
        Route::put('/elecciones/results/{id}', [EleccionCrudController::class, 'updateResult'])->name('elecciones.result.update')->middleware('electoral.platform');
    });
});
