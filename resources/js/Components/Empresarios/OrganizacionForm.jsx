import PersonaSearch, { inputCls } from '@/Components/Empresarios/PersonaSearch';

const labelCls = 'block text-[14px] font-semibold text-[var(--color-ink)]';

function Field({ label, error, children }) {
    return (
        <label className={labelCls}>
            {label}
            {children}
            {error && <span className="block mt-1 text-[13px] font-semibold text-red-700" role="alert">{error}</span>}
        </label>
    );
}

// Fields of an organization. errors: {field: message} from the server.
export default function OrganizacionForm({ form, setForm, errors = {}, catalogos = {}, municipios = [], gremios = [], rangos = [], disabled = false }) {
    const set = (field, value) => setForm(f => ({ ...f, [field]: value }));
    const active = list => (list ?? []).filter(c => c.is_active || c.id === form.sector_economico_id || c.id === form.sector_id);
    const toggleGremio = id => set('gremio_ids', form.gremio_ids.includes(id) ? form.gremio_ids.filter(g => g !== id) : [...form.gremio_ids, id]);

    return (
        <div className="space-y-4">
            <fieldset className="space-y-2">
                <legend className={labelCls}>Tipo</legend>
                <div className="flex gap-2">
                    {[['empresa', 'Empresa'], ['gremio', 'Gremio']].map(([v, l]) => (
                        <label key={v} className={`flex-1 min-h-[48px] flex items-center justify-center gap-2 rounded-xl border-2 text-[15px] font-bold cursor-pointer ${form.tipo === v ? 'border-[var(--color-primary)] bg-blue-50 text-[var(--color-primary)]' : 'border-gray-200 text-[var(--color-ink-soft)]'}`}>
                            <input type="radio" name="org-tipo" value={v} checked={form.tipo === v} disabled={disabled} onChange={() => set('tipo', v)} className="sr-only" />
                            {l}
                        </label>
                    ))}
                </div>
            </fieldset>

            <div className="grid sm:grid-cols-2 gap-3">
                <Field label="Nombre o razón social" error={errors.nombre}>
                    <input className={inputCls} value={form.nombre} disabled={disabled} maxLength={255} required onChange={e => set('nombre', e.target.value)} />
                </Field>
                <Field label="NIT (con o sin dígito de verificación)" error={errors.nit}>
                    <input className={inputCls} value={form.nit} disabled={disabled} maxLength={20} inputMode="numeric" placeholder="900.123.456-8" onChange={e => set('nit', e.target.value)} />
                </Field>
                <Field label="Teléfono o celular de la organización" error={errors.telefono}>
                    <input className={inputCls} value={form.telefono} disabled={disabled} maxLength={60} inputMode="tel" onChange={e => set('telefono', e.target.value)} />
                </Field>
                <Field label="Correo" error={errors.correo}>
                    <input type="email" className={inputCls} value={form.correo} disabled={disabled} maxLength={150} onChange={e => set('correo', e.target.value)} />
                </Field>
                <Field label="Sitio web (opcional)" error={errors.sitio_web}>
                    <input className={inputCls} value={form.sitio_web} disabled={disabled} maxLength={255} placeholder="www.empresa.com" onChange={e => set('sitio_web', e.target.value)} />
                </Field>
                <Field label="Sector económico" error={errors.sector_economico_id}>
                    <select className={inputCls} value={form.sector_economico_id} disabled={disabled} onChange={e => set('sector_economico_id', e.target.value)}>
                        <option value="">Sin elegir</option>
                        {active(catalogos.sector_economico).map(c => <option key={c.id} value={c.id}>{c.nombre}</option>)}
                    </select>
                </Field>
                <Field label="Sector" error={errors.sector_id}>
                    <select className={inputCls} value={form.sector_id} disabled={disabled} onChange={e => set('sector_id', e.target.value)}>
                        <option value="">Sin elegir</option>
                        {active(catalogos.sector).map(c => <option key={c.id} value={c.id}>{c.nombre}</option>)}
                    </select>
                </Field>
                <Field label="Número de empleados" error={errors.empleados || errors.empleados_rango}>
                    <div className="flex gap-2">
                        <input type="number" min="0" className={inputCls} value={form.empleados} disabled={disabled} placeholder="Ej.: 25" onChange={e => set('empleados', e.target.value)} aria-label="Número exacto de empleados" />
                        <select className={inputCls} value={form.empleados_rango} disabled={disabled} onChange={e => set('empleados_rango', e.target.value)} aria-label="O un rango de empleados">
                            <option value="">o un rango</option>
                            {rangos.map(r => <option key={r} value={r}>{r}</option>)}
                        </select>
                    </div>
                </Field>
            </div>

            <section className="border border-[var(--color-line)] rounded-xl p-3 space-y-3" aria-label="Representante legal">
                <h3 className="text-[15px] font-extrabold text-[var(--color-ink)]">Representante legal</h3>
                {form.representante_lider_id && (
                    <p className="flex flex-wrap items-center gap-2 text-[14px]">
                        <span className="px-2 py-0.5 rounded-full bg-emerald-50 border border-emerald-300 text-emerald-800 font-bold">Vinculado a su ficha en Votantes</span>
                        {!disabled && <button type="button" className="min-h-[44px] px-2 font-bold text-red-700 underline" onClick={() => set('representante_lider_id', null)}>Quitar vínculo</button>}
                    </p>
                )}
                {!disabled && !form.representante_lider_id && (
                    <PersonaSearch
                        id="org-representante-buscar"
                        label="Buscar en Votantes (opcional): queda como miembro con rol Representante legal"
                        onPick={l => setForm(f => ({ ...f, representante_lider_id: l.id, representante_nombre: l.nombre ?? f.representante_nombre, representante_cedula: l.cedula ?? f.representante_cedula, representante_celular: l.telefono ?? f.representante_celular }))}
                    />
                )}
                <div className="grid sm:grid-cols-3 gap-3">
                    <Field label="Nombre" error={errors.representante_nombre}>
                        <input className={inputCls} value={form.representante_nombre} disabled={disabled} maxLength={200} onChange={e => set('representante_nombre', e.target.value)} />
                    </Field>
                    <Field label="Cédula (opcional)" error={errors.representante_cedula}>
                        <input className={inputCls} value={form.representante_cedula} disabled={disabled} maxLength={20} inputMode="numeric" onChange={e => set('representante_cedula', e.target.value)} />
                    </Field>
                    <Field label="Celular" error={errors.representante_celular}>
                        <input className={inputCls} value={form.representante_celular} disabled={disabled} maxLength={60} inputMode="tel" onChange={e => set('representante_celular', e.target.value)} />
                    </Field>
                </div>
                {errors.representante_lider_id && <p className="text-[13px] font-semibold text-red-700" role="alert">{errors.representante_lider_id}</p>}
            </section>

            <div className="grid sm:grid-cols-2 gap-3">
                <Field label="Departamento" error={errors.departamento}>
                    <input className={inputCls} value={form.departamento} disabled={disabled} maxLength={80} onChange={e => set('departamento', e.target.value)} />
                </Field>
                <Field label="Municipio" error={errors.municipio_id}>
                    <select className={inputCls} value={form.municipio_id} disabled={disabled} onChange={e => set('municipio_id', e.target.value)}>
                        <option value="">Sin elegir</option>
                        {municipios.map(m => <option key={m.id} value={m.id}>{m.name}{m.provincia ? ` (${m.provincia})` : ''}</option>)}
                    </select>
                </Field>
                <Field label="Dirección" error={errors.direccion}>
                    <input className={inputCls} value={form.direccion} disabled={disabled} maxLength={255} onChange={e => set('direccion', e.target.value)} />
                </Field>
                <Field label="Barrio o vereda" error={errors.barrio}>
                    <input className={inputCls} value={form.barrio} disabled={disabled} maxLength={150} onChange={e => set('barrio', e.target.value)} />
                </Field>
            </div>

            {form.tipo === 'empresa' && (
                <fieldset className="space-y-2">
                    <legend className={labelCls}>Gremios a los que pertenece</legend>
                    {gremios.length === 0 && <p className="text-[14px] text-[var(--color-ink-faint)]">Todavía no hay gremios en su campaña.</p>}
                    <div className="flex flex-wrap gap-2">
                        {gremios.map(g => (
                            <label key={g.id} className={`min-h-[44px] px-3 inline-flex items-center gap-2 rounded-full border-2 text-[14px] font-semibold cursor-pointer ${form.gremio_ids.includes(g.id) ? 'border-[var(--color-primary)] bg-blue-50 text-[var(--color-primary)]' : 'border-gray-200 text-[var(--color-ink-soft)]'}`}>
                                <input type="checkbox" checked={form.gremio_ids.includes(g.id)} disabled={disabled} onChange={() => toggleGremio(g.id)} className="w-5 h-5 accent-[var(--color-primary)]" />
                                {g.nombre}
                            </label>
                        ))}
                    </div>
                    {errors.gremio_ids && <p className="text-[13px] font-semibold text-red-700" role="alert">{errors.gremio_ids}</p>}
                </fieldset>
            )}

            <Field label="Observaciones" error={errors.observaciones}>
                <textarea className={`${inputCls} min-h-[80px]`} value={form.observaciones} disabled={disabled} maxLength={5000} onChange={e => set('observaciones', e.target.value)} />
            </Field>

            <label className="flex items-center gap-3 min-h-[44px] text-[15px] font-semibold text-[var(--color-ink)] cursor-pointer">
                <input type="checkbox" checked={!!form.is_active} disabled={disabled} onChange={e => set('is_active', e.target.checked)} className="w-6 h-6 accent-[var(--color-primary)]" />
                Activa
            </label>
        </div>
    );
}
