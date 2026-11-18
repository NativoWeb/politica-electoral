import { fmt } from '@/lib/electoral';

// "Ver más" at the end of a Mapa Político section the server cut: how many are left and a button for the next page.
export default function SectionLoadMore({ remaining, loading = false, error = false, onLoad, shown }) {
    if (!remaining || remaining <= 0) return null;

    return (
        <div className="flex flex-col sm:flex-row items-center justify-between gap-2 px-4 py-3 bg-amber-50 border-t border-amber-200">
            <p className="text-[13px] text-[var(--color-ink-soft)]">
                {shown > 0 ? `Mostrando ${fmt(shown)}. ` : ''}Faltan <span className="font-bold">{fmt(remaining)}</span>. Usa los filtros para encontrar a alguien más rápido.
                {error && <span className="block text-red-600 font-semibold">No se pudieron cargar. Revisa tu conexión e intenta otra vez.</span>}
            </p>
            <button
                type="button"
                onClick={onLoad}
                disabled={loading}
                className="px-4 py-2.5 bg-[var(--color-primary)] text-white text-[14px] font-bold rounded-lg disabled:opacity-60 flex-shrink-0"
            >
                {loading ? 'Cargando...' : 'Ver más'}
            </button>
        </div>
    );
}
