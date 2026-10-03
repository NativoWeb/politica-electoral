import { Head, useForm } from '@inertiajs/react';

export default function ChangePassword() {
    const { data, setData, post, processing, errors } = useForm({
        new_password: '',
        new_password_confirmation: '',
    });

    function handleSubmit(e) {
        e.preventDefault();
        post('/forzar-cambio-password');
    }

    return (
        <>
            <Head title="Cambiar Contraseña — Inteligencia Electoral Santander" />

            <div
                className="min-h-screen flex items-center justify-center p-4"
                style={{
                    background: 'linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%)',
                }}
            >
                <div className="w-full max-w-sm">
                    <div className="bg-white rounded-2xl shadow-2xl overflow-hidden">
                        <div
                            className="px-8 pt-8 pb-6 text-center"
                            style={{ background: 'linear-gradient(160deg, var(--color-primary) 0%, var(--color-primary-dark) 100%)' }}
                        >
                            <div className="w-10 h-7 rounded overflow-hidden flex flex-col mx-auto mb-4">
                                <span className="flex-[2] bg-[#FCD116]"></span>
                                <span className="flex-1 bg-[#003893]"></span>
                                <span className="flex-1 bg-[#CE1126]"></span>
                            </div>

                            <h1
                                className="text-lg font-bold tracking-wide text-white leading-tight"
                                style={{ fontFamily: 'var(--font-display, Archivo, sans-serif)' }}
                            >
                                CAMBIO DE CONTRASEÑA
                            </h1>
                            <p className="text-[11px] tracking-widest text-white/60 mt-0.5 uppercase">
                                Requerido en tu primer ingreso
                            </p>
                        </div>

                        <form onSubmit={handleSubmit} className="px-8 py-7 space-y-5">
                            <p className="text-sm text-gray-600">
                                Por seguridad, debes establecer una nueva contraseña antes de continuar.
                            </p>

                            <div>
                                <label
                                    htmlFor="new_password"
                                    className="block text-[11px] font-semibold tracking-wider text-gray-500 uppercase mb-1.5"
                                >
                                    Nueva contraseña
                                </label>
                                <input
                                    id="new_password"
                                    type="password"
                                    autoFocus
                                    value={data.new_password}
                                    onChange={e => setData('new_password', e.target.value)}
                                    className={`
                                        w-full px-3 py-2.5 rounded-lg border text-sm text-gray-900
                                        focus:outline-none focus:ring-2 transition-colors
                                        ${errors.new_password
                                            ? 'border-red-400 bg-red-50 focus:ring-red-200'
                                            : 'border-gray-300 bg-gray-50 focus:ring-blue-200 focus:border-blue-400'
                                        }
                                    `}
                                    placeholder="Mínimo 8 caracteres"
                                />
                                {errors.new_password && (
                                    <p className="mt-1.5 text-[11px] text-red-600">{errors.new_password}</p>
                                )}
                            </div>

                            <div>
                                <label
                                    htmlFor="new_password_confirmation"
                                    className="block text-[11px] font-semibold tracking-wider text-gray-500 uppercase mb-1.5"
                                >
                                    Confirmar contraseña
                                </label>
                                <input
                                    id="new_password_confirmation"
                                    type="password"
                                    value={data.new_password_confirmation}
                                    onChange={e => setData('new_password_confirmation', e.target.value)}
                                    className={`
                                        w-full px-3 py-2.5 rounded-lg border text-sm text-gray-900
                                        focus:outline-none focus:ring-2 transition-colors
                                        ${errors.new_password_confirmation
                                            ? 'border-red-400 bg-red-50 focus:ring-red-200'
                                            : 'border-gray-300 bg-gray-50 focus:ring-blue-200 focus:border-blue-400'
                                        }
                                    `}
                                    placeholder="Repite la nueva contraseña"
                                />
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full py-3 rounded-lg text-sm font-bold tracking-wide text-white transition-opacity disabled:opacity-60"
                                style={{ background: 'var(--color-primary)' }}
                            >
                                {processing ? 'Guardando...' : 'Establecer Contraseña'}
                            </button>
                        </form>
                    </div>

                    <p className="text-center text-[11px] text-white/30 mt-6 tracking-wider">
                        SISTEMA DE USO EXCLUSIVO INTERNO
                    </p>
                </div>
            </div>
        </>
    );
}
