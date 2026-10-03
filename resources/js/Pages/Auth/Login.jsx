import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function Login() {
    const { flash } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const [countdown, setCountdown] = useState(0);

    useEffect(() => {
        if (errors.lockout_seconds) {
            setCountdown(parseInt(errors.lockout_seconds, 10));
        }
    }, [errors.lockout_seconds]);

    useEffect(() => {
        if (countdown <= 0) return;
        const timer = setInterval(() => {
            setCountdown(prev => {
                if (prev <= 1) {
                    clearInterval(timer);
                    return 0;
                }
                return prev - 1;
            });
        }, 1000);
        return () => clearInterval(timer);
    }, [countdown]);

    function handleSubmit(e) {
        e.preventDefault();
        if (countdown > 0) return;
        post('/login');
    }

    const isLocked = countdown > 0;

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
                                INTELIGENCIA ELECTORAL
                            </h1>
                            <p className="text-[11px] tracking-widest text-white/60 mt-0.5 uppercase">
                                Santander &middot; Colombia
                            </p>
                        </div>

                        <form onSubmit={handleSubmit} className="px-8 py-7 space-y-5">
                            {flash?.success && (
                                <div className="px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-lg text-[13px] font-semibold text-emerald-700">
                                    {flash.success}
                                </div>
                            )}

                            {isLocked && (
                                <div className="px-4 py-3 bg-amber-50 border border-amber-200 rounded-lg text-center">
                                    <p className="text-[13px] font-semibold text-amber-700">
                                        Cuenta bloqueada temporalmente
                                    </p>
                                    <p className="text-2xl font-bold text-amber-600 mt-1 tabular-nums">
                                        {String(Math.floor(countdown / 60)).padStart(2, '0')}:{String(countdown % 60).padStart(2, '0')}
                                    </p>
                                    <p className="text-[11px] text-amber-600 mt-0.5">
                                        Espera para intentar de nuevo
                                    </p>
                                </div>
                            )}

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
                                        ${errors.email && !isLocked
                                            ? 'border-red-400 bg-red-50 focus:ring-red-200'
                                            : 'border-gray-300 bg-gray-50 focus:ring-blue-200 focus:border-blue-400'
                                        }
                                    `}
                                    placeholder="usuario@ejemplo.com"
                                />
                                {errors.email && !isLocked && !errors.lockout_seconds && (
                                    <p className="mt-1.5 text-[11px] text-red-600">{errors.email}</p>
                                )}
                            </div>

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

                            <div className="flex items-center justify-between">
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
                                        Recordar
                                    </label>
                                </div>

                                <Link
                                    href="/olvide-password"
                                    className="text-sm text-[var(--color-primary)] font-semibold hover:underline"
                                >
                                    ¿Olvidaste tu contraseña?
                                </Link>
                            </div>

                            <button
                                type="submit"
                                disabled={processing || isLocked}
                                className="w-full py-3 rounded-lg text-sm font-bold tracking-wide text-white transition-opacity disabled:opacity-60"
                                style={{ background: 'var(--color-primary)' }}
                            >
                                {processing ? 'Verificando...' : isLocked ? 'Bloqueado' : 'Iniciar Sesión'}
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
