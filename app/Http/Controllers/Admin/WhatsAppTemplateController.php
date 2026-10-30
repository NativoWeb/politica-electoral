<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppTemplate;
use App\Support\CurrentCampaign;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WhatsAppTemplateController extends Controller
{
    /**
     * Filtros que se pueden configurar por plantilla.
     */
    private const ALLOWED_FILTERS = [
        'provincia',
        'municipio',
        'tipo',
        'cargo',
        'partido',
        'profesion',
        'barrio',
        'estado',
        'cumpleanos',
    ];

    // Templates belong to the user's own campaign; another campaign's template does not exist for them.
    // meta_name stays unique across campaigns on purpose: every campaign sends through the same WhatsApp Business
    // account (one n8n webhook), where a template name is unique. A second WABA would need it per campaign.
    private function ownCampaignId(): string
    {
        return app(CurrentCampaign::class)->ownId();
    }

    private function authorizeTemplate(WhatsAppTemplate $template): void
    {
        abort_unless($template->campaign_id === $this->ownCampaignId(), 404);
    }

    public function index()
    {
        $templates = WhatsAppTemplate::query()
            ->where('campaign_id', $this->ownCampaignId())
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/WhatsAppTemplates', [
            'templates' => $templates,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'meta_name' => [
                'required',
                'string',
                'max:255',
                'unique:whatsapp_templates,meta_name',
            ],
            'body_text' => ['required', 'string'],
            'variables' => ['nullable', 'array'],
            'filters' => ['nullable', 'array'],
            'filters.*' => [
                'string',
                'distinct',
                'in:' . implode(',', self::ALLOWED_FILTERS),
            ],
            'header_type' => ['nullable', 'string', 'in:text,image'],
            'header_text' => ['nullable', 'string', 'max:255'],
            'language' => ['required', 'string', 'max:20'],
            'category' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['filters'] = array_values(
            array_unique($validated['filters'] ?? [])
        );

        (new WhatsAppTemplate($validated))->forceFill(['campaign_id' => $this->ownCampaignId()])->save();

        return redirect()
            ->route('admin.whatsapp.templates')
            ->with('success', 'Plantilla creada correctamente.');
    }

    public function update(
        Request $request,
        WhatsAppTemplate $whatsappTemplate
    ) {
        $this->authorizeTemplate($whatsappTemplate);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'meta_name' => [
                'required',
                'string',
                'max:255',
                'unique:whatsapp_templates,meta_name,' . $whatsappTemplate->id,
            ],
            'body_text' => ['required', 'string'],
            'variables' => ['nullable', 'array'],
            'filters' => ['nullable', 'array'],
            'filters.*' => [
                'string',
                'distinct',
                'in:' . implode(',', self::ALLOWED_FILTERS),
            ],
            'header_type' => ['nullable', 'string', 'in:text,image'],
            'header_text' => ['nullable', 'string', 'max:255'],
            'language' => ['required', 'string', 'max:20'],
            'category' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['filters'] = array_values(
            array_unique($validated['filters'] ?? [])
        );

        $whatsappTemplate->update($validated);

        return redirect()
            ->route('admin.whatsapp.templates')
            ->with('success', 'Plantilla actualizada correctamente.');
    }

    public function destroy(WhatsAppTemplate $whatsappTemplate)
    {
        $this->authorizeTemplate($whatsappTemplate);
        $whatsappTemplate->delete();

        return redirect()
            ->route('admin.whatsapp.templates')
            ->with('success', 'Plantilla eliminada correctamente.');
    }
}