import { Head, Link, useForm, usePage } from '@inertiajs/react';

export default function ForgotPassword() {
    const { flash } = usePage().props;
    const status = flash?.status;
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function handleSubmit(e) {
        e.preventDefault();
        post('/olvide-password');
    }

    return (
        <>
            <Head title="Recuperar Contraseña — Inteligencia Electoral Santander" />

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
                                RECUPERAR CONTRASEÑA
                            </h1>
                            <p className="text-[11px] tracking-widest text-white/60 mt-0.5 uppercase">
                                Te enviaremos un enlace por correo
                            </p>
                        </div>

                        <form onSubmit={handleSubmit} className="px-8 py-7 space-y-5">
                            {status && (
                                <div className="px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-lg text-[13px] font-semibold text-emerald-700">
                                    {status}
                                </div>
                            )}

                            <p className="text-sm text-gray-600">
                                Ingresa tu correo electrónico y te enviaremos un enlace para restablecer tu contraseña.
                            </p>

                            <div>
                                <label
                                    htmlFor="email"
                                    className="block text-[11px] font-semibold tracking-wider text-gray-500 uppercase mb-1.5"
                                >
                                    Correo electrónico
                                </label>
                                <input
                                    id="email"
                                    type="email"
                                    autoComplete="email"
                                    autoFocus
                                    value={data.email}
                                    onChange={e => setData('email', e.target.value)}
                                    className={`
                                        w-full px-3 py-2.5 rounded-lg border text-sm text-gray-900
                                        focus:outline-none focus:ring-2 transition-colors
                                        ${errors.email
                                            ? 'border-red-400 bg-red-50 focus:ring-red-200'
                                            : 'border-gray-300 bg-gray-50 focus:ring-blue-200 focus:border-blue-400'
                                        }
                                    `}
                                    placeholder="usuario@ejemplo.com"
                                />
                                {errors.email && (
                                    <p className="mt-1.5 text-[11px] text-red-600">{errors.email}</p>
                                )}
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full py-3 rounded-lg text-sm font-bold tracking-wide text-white transition-opacity disabled:opacity-60"
                                style={{ background: 'var(--color-primary)' }}
                            >
                                {processing ? 'Enviando...' : 'Enviar enlace'}
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
