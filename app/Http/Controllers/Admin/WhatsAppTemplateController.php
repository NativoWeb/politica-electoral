<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WhatsAppTemplateController extends Controller
{
    public function index()
    {
        $templates = WhatsAppTemplate::query()
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
            'meta_name' => ['required', 'string', 'max:255', 'unique:whatsapp_templates,meta_name'],
            'body_text' => ['required', 'string'],
            'variables' => ['nullable', 'array'],
            'header_type' => ['nullable', 'string', 'in:text,image'],
            'header_text' => ['nullable', 'string', 'max:255'],
            'language' => ['required', 'string', 'max:20'],
            'category' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);

        WhatsAppTemplate::create($validated);

        return redirect()
            ->route('admin.whatsapp.templates')
            ->with('success', 'Plantilla creada correctamente.');
    }

    public function update(Request $request, WhatsAppTemplate $whatsappTemplate)
    {
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
            'header_type' => ['nullable', 'string', 'in:text,image'],
            'header_text' => ['nullable', 'string', 'max:255'],
            'language' => ['required', 'string', 'max:20'],
            'category' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);

        $whatsappTemplate->update($validated);

        return redirect()
            ->route('admin.whatsapp.templates')
            ->with('success', 'Plantilla actualizada correctamente.');
    }

    public function destroy(WhatsAppTemplate $whatsappTemplate)
    {
        $whatsappTemplate->delete();

        return redirect()
            ->route('admin.whatsapp.templates')
            ->with('success', 'Plantilla eliminada correctamente.');
    }
}