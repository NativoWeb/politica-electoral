import { Head, useForm } from '@inertiajs/react';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function handleSubmit(e) {
        e.preventDefault();
        post('/login');
    }

    return (
        <>
            <Head title="Iniciar Sesión — Inteligencia Electoral Santander" />

            <div
                className="min-h-screen flex items-center justify-center p-4"
                style={{
                    background: 'linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%)',
                }}
            >
                <div className="w-full max-w-sm">
                    {/* Card */}
                    <div className="bg-white rounded-2xl shadow-2xl overflow-hidden">
                        {/* Card header */}
                        <div
                            className="px-8 pt-8 pb-6 text-center"
                            style={{ background: 'linear-gradient(160deg, var(--color-primary) 0%, var(--color-primary-dark) 100%)' }}
                        >
                            {/* Colombia flag */}
                            <div className="w-10 h-7 rounded overflow-hidden flex flex-col mx-auto mb-4">
                                <span className="flex-[2] bg-[#FCD116]"></span>
                                <span className="flex-1 bg-[#003893]"></span>
                                <span className="flex-1 bg-[#CE1126]"></span>
                            </div>

                            <h1
                                className="text-lg font-bold tracking-wide text-white leading-tight"
                                style={{ fontFamily: 'var(--font-display, Archivo, sans-serif)' }}
                            >
                                INTELIGENCIA ELECTORAL
                            </h1>
                            <p className="text-[11px] tracking-widest text-white/60 mt-0.5 uppercase">
                                Santander &middot; Colombia
                            </p>
                        </div>

                        {/* Form */}
                        <form onSubmit={handleSubmit} className="px-8 py-7 space-y-5">
                            {/* Email */}
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

                            {/* Password */}
                            <div>
                                <label
                                    htmlFor="password"
                                    className="block text-[11px] font-semibold tracking-wider text-gray-500 uppercase mb-1.5"
                                >
                                    Contraseña
                                </label>
                                <input
                                    id="password"
                                    type="password"
                                    autoComplete="current-password"
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
                                    placeholder="••••••••"
                                />
                                {errors.password && (
                                    <p className="mt-1.5 text-[11px] text-red-600">{errors.password}</p>
                                )}
                            </div>

                            {/* Remember */}
                            <div className="flex items-center gap-2">
                                <input
                                    id="remember"
                                    type="checkbox"
                                    checked={data.remember}
                                    onChange={e => setData('remember', e.target.checked)}
                                    className="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-200 cursor-pointer"
                                />
                                <label
                                    htmlFor="remember"
                                    className="text-sm text-gray-600 cursor-pointer select-none"
                                >
                                    Recordar sesión
                                </label>
                            </div>

                            {/* Submit */}
                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full py-3 rounded-lg text-sm font-bold tracking-wide text-white transition-opacity disabled:opacity-60"
                                style={{ background: 'var(--color-primary)' }}
                            >
                                {processing ? 'Verificando...' : 'Iniciar Sesión'}
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
