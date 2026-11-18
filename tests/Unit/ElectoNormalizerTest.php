<?php

namespace Tests\Unit;

use App\Console\Commands\Electo\CargarDivipola;
use App\Services\Electo\CsvReader;
use App\Services\Electo\Normalizer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

// Value normalization of the Electo import (fictitious values only).
class ElectoNormalizerTest extends TestCase
{
    public function test_cedula_keeps_digits_without_leading_zeros(): void
    {
        $this->assertSame(['91234567', null], Normalizer::cedula(' 00091.234.567 ', '13'));
        $this->assertSame(['1098765432', null], Normalizer::cedula("1098 765 432\n", '13'));
        $this->assertSame(['AB123456', null], Normalizer::cedula('ab-123456', '41'));
    }

    public function test_cedula_rejects_placeholders_and_short_documents(): void
    {
        $this->assertSame([null, 'documento_sin_tipo'], Normalizer::cedula('00012345', '0'));
        $this->assertSame([null, 'documento_invalido'], Normalizer::cedula('0001234', '13'));
        $this->assertSame([null, 'documento_invalido'], Normalizer::cedula('ABC', '13'));
        $this->assertSame([null, null], Normalizer::cedula('  ', '13'));
        $this->assertSame(['98765432', null], Normalizer::cedula('98765432', '0'));
    }

    public function test_cedula_key_matches_stored_values_with_zeros_or_dots(): void
    {
        $this->assertSame('7123456', Normalizer::cedulaKey('07.123.456'));
        $this->assertSame(Normalizer::cedula('7123456', '13')[0], Normalizer::cedulaKey('0007123456'));
        $this->assertNull(Normalizer::cedulaKey('000'));
    }

    public function test_dates_drop_fillers_future_and_impossible_values(): void
    {
        $today = CarbonImmutable::parse('2026-11-18');
        $this->assertSame(['1980-05-05', false], Normalizer::date('1980-05-05 00:00:00.000 -0500'));
        $this->assertSame([null, true], Normalizer::date('1900-01-01'));
        $this->assertSame([null, true], Normalizer::date('0001-01-01 00:00:00.000'));
        $this->assertSame([null, true], Normalizer::date('19800-01-01'));
        $this->assertSame([null, true], Normalizer::date('2023-02-30'));
        $this->assertSame([null, false], Normalizer::date(''));
        $this->assertSame([null, true], Normalizer::birthDate('2027-01-01', $today));
        $this->assertSame([null, true], Normalizer::birthDate('1910-01-01', $today));
        $this->assertSame(['2010-01-01', false], Normalizer::birthDate('2010-01-01', $today));
        $this->assertTrue(Normalizer::isMinor('2010-01-01', $today));
        $this->assertFalse(Normalizer::isMinor('2008-11-18', $today));
        $this->assertSame('2025-03-10 15:30:00', Normalizer::dateTime('2025-03-10 15:30:00.000 -0500'));
    }

    public function test_phones_and_emails(): void
    {
        $this->assertSame('3001112222', Normalizer::phone('+57 300 111 2222'));
        $this->assertSame('6076345678', Normalizer::phone('607-634-5678'));
        $this->assertSame('6345678', Normalizer::phone('634 5678'));
        $this->assertNull(Normalizer::phone('12345'));
        $this->assertNull(Normalizer::phone('30011122223333'));
        $this->assertTrue(Normalizer::isMobile('3001112222'));
        $this->assertFalse(Normalizer::isMobile('6076345678'));
        $this->assertSame('ana@example.com', Normalizer::email(' ANA@Example.COM '));
        $this->assertNull(Normalizer::email('no-es-email'));
    }

    public function test_catalogs_and_gender(): void
    {
        $this->assertSame('Femenino', Normalizer::genero('1'));
        $this->assertSame('Masculino', Normalizer::genero('2'));
        $this->assertSame('Otro', Normalizer::genero('3'));
        $this->assertNull(Normalizer::genero('0'));
        $this->assertSame('Universitario', Normalizer::escolaridad('PROFESIONAL'));
        $this->assertSame('Posgrado', Normalizer::escolaridad('MAESTRÍA'));
        $this->assertSame('Técnico', Normalizer::escolaridad('TÉCNICO'));
        $this->assertNull(Normalizer::escolaridad('SIN DEFINIR'));
        $this->assertNull(Normalizer::estadoCivil('No Aplica'));
        $this->assertSame('Unión libre', Normalizer::estadoCivil('Unión libre'));
        $this->assertNull(Normalizer::profesion('NO APLICA'));
        $this->assertSame('CC', Normalizer::tipoDocumento('13'));
        $this->assertNull(Normalizer::tipoDocumento('0'));
    }

    public function test_names_text_and_divipola(): void
    {
        $this->assertSame('ANA MARÍA PRUEBA UNO', Normalizer::name("ana  maría\n", 'prueba uno'));
        $this->assertNull(Normalizer::name(' ', ''));
        $this->assertSame(Normalizer::personKey('PRUEBA SIETE, Pedro'), Normalizer::personKey('pedro prueba siete'));
        [$text, $cut] = Normalizer::text("a\r\n\n\n\nb");
        $this->assertSame("a\n\nb", $text);
        $this->assertFalse($cut);
        [$text, $cut] = Normalizer::text(str_repeat('x', 5000));
        $this->assertTrue($cut);
        $this->assertSame(Normalizer::OBSERVACION_MAX, mb_strlen($text));
        $this->assertSame('68001', Normalizer::divipola('68001'));
        $this->assertSame('05001', Normalizer::divipola('5001'));
        $this->assertNull(Normalizer::divipola('00000'));
        $this->assertNull(Normalizer::divipola('68A01'));
        $this->assertSame('Bogotá, D.C.', CargarDivipola::pretty('BOGOTÁ, D.C.'));
        $this->assertSame('Archipiélago de San Andrés, Providencia y Santa Catalina', CargarDivipola::pretty('ARCHIPIÉLAGO DE SAN ANDRÉS, PROVIDENCIA Y SANTA CATALINA'));
    }

    public function test_csv_reader_keeps_line_breaks_inside_quoted_fields(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'electo');
        file_put_contents($path, "\xEF\xBB\xBF\"id\",\"observacion\",\"nombres\"\r\n1,\"linea uno\r\nlinea dos\",\"ANA\"\r\n2,\"termina en \\\",\"LUIS\"\r\n3,,\"\"\r\n");
        try {
            $rows = iterator_to_array(CsvReader::rows($path), false);
        } finally {
            unlink($path);
        }

        $this->assertCount(3, $rows);
        $this->assertSame("linea uno\r\nlinea dos", $rows[0]['observacion']);
        $this->assertSame('termina en \\', $rows[1]['observacion']);
        $this->assertSame('LUIS', $rows[1]['nombres']);
        $this->assertSame('', $rows[2]['nombres']);
    }

    public function test_csv_reader_rejects_a_row_with_another_width(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'electo');
        file_put_contents($path, "\"id\",\"nombre\"\n1,\"a\",\"extra\"\n");
        $this->expectException(\UnexpectedValueException::class);
        try {
            iterator_to_array(CsvReader::rows($path));
        } finally {
            unlink($path);
        }
    }
}
