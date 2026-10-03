<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;

class LoginController extends Controller
{
    public function showLogin()
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $key = 'login:' . $request->ip();
        $maxAttempts = 5;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->withErrors([
                'email' => "Demasiados intentos. Intenta de nuevo en {$seconds} segundos.",
                'lockout_seconds' => $seconds,
            ]);
        }

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::clear($key);
            $request->session()->regenerate();

            if ($request->user()->must_change_password) {
                return redirect()->route('password.force-change');
            }

            return redirect()->intended('/');
        }

        RateLimiter::hit($key, 60);
        $remaining = $maxAttempts - RateLimiter::attempts($key);

        $msg = 'Las credenciales no coinciden con nuestros registros.';
        if ($remaining > 0) {
            $msg .= " Te quedan {$remaining} intento(s).";
        }

        return back()->withErrors([
            'email' => $msg,
            'remaining_attempts' => (string) $remaining,
        ])->onlyInput('email');
    }

    public function showForceChangePassword()
    {
        return Inertia::render('Auth/ChangePassword');
    }

    public function forceChangePassword(Request $request)
    {
        $request->validate([
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $request->user()->update([
            'password' => Hash::make($request->new_password),
            'must_change_password' => false,
        ]);

        return redirect('/')->with('success', 'Contraseña actualizada correctamente.');
    }

    public function showForgotPassword()
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            $token = Password::createToken($user);
            $resetUrl = url("/resetear-password/{$token}?email=" . urlencode($user->email));

            Mail::send('emails.password-reset', [
                'userName' => $user->name,
                'resetUrl' => $resetUrl,
            ], function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Restablecer Contraseña — Inteligencia Electoral');
            });
        }

        return back()->with('status', 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña.');
    }

    public function showResetPassword(Request $request, string $token)
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->update([
                    'password' => Hash::make($password),
                    'must_change_password' => false,
                    'remember_token' => Str::random(60),
                ]);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect('/login')->with('success', 'Contraseña restablecida correctamente. Ya puedes iniciar sesión.');
        }

        return back()->withErrors([
            'email' => match ($status) {
                Password::INVALID_TOKEN => 'El enlace ha expirado o no es válido. Solicita uno nuevo.',
                Password::INVALID_USER => 'No encontramos un usuario con ese correo.',
                default => 'No se pudo restablecer la contraseña.',
            },
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
