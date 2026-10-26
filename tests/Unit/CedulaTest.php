<?php

namespace Tests\Unit;

use App\Support\Cedula;
use PHPUnit\Framework\TestCase;

class CedulaTest extends TestCase
{
    public function test_formatted_cedulas_become_digits_only(): void
    {
        $this->assertSame('63525141', Cedula::normalize('63.525.141'));
        $this->assertSame('13952615', Cedula::normalize('13´952.615'));
        $this->assertSame('1097333182', Cedula::normalize("1.097'333.182"));
        $this->assertSame('1013656362', Cedula::normalize(' 1 013 656 362 '));
        $this->assertSame('91134978', Cedula::normalize('91-134-978'));
    }

    public function test_non_numeric_values_are_only_trimmed(): void
    {
        $this->assertSame('AB123456', Cedula::normalize(' AB123456 '));
        $this->assertSame('concejal cd', Cedula::normalize('concejal cd'));
        $this->assertSame('', Cedula::normalize(''));
        $this->assertNull(Cedula::normalize(null));
    }

    public function test_normalize_fields_touches_only_document_fields(): void
    {
        $out = Cedula::normalizeFields(['cedula' => '63.525.141', 'referente_documento' => '1.098.171.202', 'telefono' => '310.123']);
        $this->assertSame(['cedula' => '63525141', 'referente_documento' => '1098171202', 'telefono' => '310.123'], $out);
        $this->assertSame(['nombre' => 'x'], Cedula::normalizeFields(['nombre' => 'x']));
    }
}
