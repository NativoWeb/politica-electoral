import { Head, Link, useForm } from '@inertiajs/react';

export default function ResetPassword({ token, email }) {
    const { data, setData, post, processing, errors } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    function handleSubmit(e) {
        e.preventDefault();
        post('/resetear-password');
    }

    return (
        <>
            <Head title="Restablecer Contraseña — Inteligencia Electoral Santander" />

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
                                NUEVA CONTRASEÑA
                            </h1>
                            <p className="text-[11px] tracking-widest text-white/60 mt-0.5 uppercase">
                                Establece tu nueva contraseña
                            </p>
                        </div>

                        <form onSubmit={handleSubmit} className="px-8 py-7 space-y-5">
                            {errors.email && (
                                <div className="px-4 py-3 bg-red-50 border border-red-200 rounded-lg text-[13px] font-semibold text-red-700">
                                    {errors.email}
                                </div>
                            )}

                            <input type="hidden" value={data.token} />

                            <div>
                                <label className="block text-[11px] font-semibold tracking-wider text-gray-500 uppercase mb-1.5">
                                    Correo electrónico
                                </label>
                                <input
                                    type="email"
                                    value={data.email}
                                    onChange={e => setData('email', e.target.value)}
                                    className="w-full px-3 py-2.5 rounded-lg border border-gray-300 bg-gray-100 text-sm text-gray-600 focus:outline-none"
                                    readOnly
                                />
                            </div>

                            <div>
                                <label htmlFor="password" className="block text-[11px] font-semibold tracking-wider text-gray-500 uppercase mb-1.5">
                                    Nueva contraseña
                                </label>
                                <input
                                    id="password"
                                    type="password"
                                    autoFocus
                                    value={data.password}
                                    onChange={e => setData('password', e.target.value)}
                                    className={`
                                        w-full px-3 py-2.5 rounded-lg border text-sm text-gray-900
                                        focus:outline-none focus:ring-2 transition-colors
                                        ${errors.password
                                            ? 'border-red-400 bg-red-50 focus:ring-red-200'
                                            : 'border-gray-300 bg-gray-50 focus:ring-blue-200 focus:border-blue-400'
                                        }
                                    `}
                                    placeholder="Mínimo 8 caracteres"
                                />
                                {errors.password && (
                                    <p className="mt-1.5 text-[11px] text-red-600">{errors.password}</p>
                                )}
                            </div>

                            <div>
                                <label htmlFor="password_confirmation" className="block text-[11px] font-semibold tracking-wider text-gray-500 uppercase mb-1.5">
                                    Confirmar contraseña
                                </label>
                                <input
                                    id="password_confirmation"
                                    type="password"
                                    value={data.password_confirmation}
                                    onChange={e => setData('password_confirmation', e.target.value)}
                                    className="w-full px-3 py-2.5 rounded-lg border border-gray-300 bg-gray-50 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-400 transition-colors"
                                    placeholder="Repite la nueva contraseña"
                                />
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full py-3 rounded-lg text-sm font-bold tracking-wide text-white transition-opacity disabled:opacity-60"
                                style={{ background: 'var(--color-primary)' }}
                            >
                                {processing ? 'Guardando...' : 'Restablecer Contraseña'}
                            </button>

                            <div className="text-center">
                                <Link href="/login" className="text-sm text-[var(--color-primary)] font-semibold hover:underline">
                                    Volver al inicio de sesión
                                </Link>
                            </div>
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
