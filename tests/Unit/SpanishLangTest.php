<?php

namespace Tests\Unit;

use App\Http\Requests\Agent\AgentLinkRequest;
use App\Http\Requests\Agent\AgentStoreSeguimientoRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

// Production runs APP_LOCALE=es with APP_FALLBACK_LOCALE=en: every generic message must exist in Spanish so none
// falls back to English.
class SpanishLangTest extends TestCase
{
    private function keys(array $lines, string $prefix = ''): array
    {
        $keys = [];
        foreach ($lines as $key => $value) {
            if (is_array($value) && $value !== []) {
                $keys = array_merge($keys, $this->keys($value, "{$prefix}{$key}."));
            } else {
                $keys[] = $prefix.$key;
            }
        }

        return $keys;
    }

    public function test_every_english_validation_key_exists_in_spanish(): void
    {
        $en = require lang_path('en/validation.php');
        $es = require lang_path('es/validation.php');
        // custom holds the skeleton's placeholder and attributes are app specific.
        unset($en['custom'], $en['attributes']);

        $missing = array_values(array_diff($this->keys($en), $this->keys($es)));
        $this->assertSame([], $missing, 'Faltan en lang/es/validation.php: '.implode(', ', $missing));
        $this->assertArrayHasKey('custom', $es);
        $this->assertArrayHasKey('attributes', $es);
    }

    public function test_the_other_language_files_exist_in_spanish_with_the_same_keys(): void
    {
        foreach (['auth', 'pagination', 'passwords'] as $file) {
            $this->assertFileExists(lang_path("es/{$file}.php"));
            $missing = array_diff($this->keys(require lang_path("en/{$file}.php")), $this->keys(require lang_path("es/{$file}.php")));
            $this->assertSame([], array_values($missing), "Faltan en lang/es/{$file}.php");
        }
    }

    private function firstErrors(array $data, array $rules): array
    {
        app()->setLocale('es');

        return collect(Validator::make($data, $rules)->errors()->messages())->map(fn ($m) => $m[0])->all();
    }

    public function test_generic_messages_without_a_custom_text_come_out_in_spanish(): void
    {
        $this->assertSame([
            'phone' => 'El formato del campo teléfono no es válido.',
            'code' => 'El campo código debe tener 6 dígitos.',
        ], $this->firstErrors(['phone' => 'abc', 'code' => '12'], (new AgentLinkRequest)->rules()));

        $errors = $this->firstErrors(['tipo' => 'llamada', 'nota' => 'x', 'fecha' => now()->addYears(3)->toDateString()], (new AgentStoreSeguimientoRequest)->rules());
        $this->assertStringStartsWith('El campo fecha debe ser una fecha anterior o igual a ', $errors['fecha']);

        $this->assertSame(
            ['date' => 'El campo fecha debe tener el formato Y-m-d.'],
            $this->firstErrors(['date' => '31/12/2026'], ['date' => ['nullable', 'date_format:Y-m-d']]),
        );
    }
}
