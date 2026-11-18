<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// An expired form (CSRF token mismatch) goes back to the form with a message instead of a blank 419 page.
class ExpiredFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->post('/_test/expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
    }

    // Stands for the login form: the real route name with a handler that fails like an expired token.
    private function asLogin(): void
    {
        Route::middleware('web')->post('/_test/login-expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'))->name('login.attempt');
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function test_the_login_form_goes_back_with_a_message(): void
    {
        $this->asLogin();
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/login')
            ->post('/_test/login-expired', ['email' => 'a@b.co', 'password' => 'secreto'], ['X-Inertia' => 'true'])
            ->assertStatus(303)
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email')
            ->assertSessionHasInput('email', 'a@b.co')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_other_forms_keep_the_plain_419(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/partidos')
            ->post('/_test/expired', ['nombre' => 'X'])
            ->assertStatus(419);
        $this->withoutMiddleware(ValidateCsrfToken::class)->postJson('/_test/expired')->assertStatus(419);
    }
}
