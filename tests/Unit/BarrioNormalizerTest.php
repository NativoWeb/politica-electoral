<?php

namespace Tests\Unit;

use App\Services\Geo\BarrioNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BarrioNormalizerTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'roman numeral' => ['CORDONCILLO I', 'CORDONCILLO 1'],
            'arabic numeral' => ['Cordoncillo 1', 'CORDONCILLO 1'],
            'roman II' => ['Diamante II', 'DIAMANTE 2'],
            'leading zeros' => ['05 DE ENERO', '5 DE ENERO'],
            'accents and ñ' => ['Cañaveral', 'CANAVERAL'],
            'barrio prefix' => ['BARRIO EL SOL 1', 'EL SOL 1'],
            'b/ prefix' => ['B/ San Alonso', 'SAN ALONSO'],
            'b. prefix' => ['B. La Joya', 'LA JOYA'],
            'conjunto and casa' => ['CONJUNTO CAÑAVERAL DE LA RIVIERA, CASA 43', 'CANAVERAL DE LA RIVIERA'],
            'conjunto residencial' => ['Conjunto Residencial Pinares Campestre', 'PINARES CAMPESTRE'],
            'casa without comma' => ['MIRADOR DE FATIMA CASA 33', 'MIRADOR DE FATIMA'],
            'torre and apto' => ['San Francisco Torre 2 Apto 301', 'SAN FRANCISCO'],
            'torre letter' => ['Provenza torre B', 'PROVENZA'],
            'casa as part of a name' => ['Casa Blanca', 'CASA BLANCA'],
            'vereda prefix' => ['Vereda El Pablón', 'EL PABLON'],
            'punctuation' => ['LA PAZ, FLORIDABLANCA', 'LA PAZ FLORIDABLANCA'],
            'single roman token stays' => ['V', 'V'],
            'empty' => ['   ', ''],
            'null' => [null, ''],
        ];
    }

    #[DataProvider('cases')]
    public function test_it_normalizes_barrio_texts(?string $text, string $expected): void
    {
        $this->assertSame($expected, BarrioNormalizer::normalize($text));
    }

    public function test_it_removes_the_own_municipio_anywhere(): void
    {
        $strip = ['BUCARAMANGA', 'B MANGA'];

        $this->assertSame('SAN ALONSO', BarrioNormalizer::normalize('San Alonso, Bucaramanga', $strip));
        $this->assertSame('SAN ALONSO', BarrioNormalizer::normalize('SAN ALONSO B/MANGA', $strip));
        $this->assertSame('', BarrioNormalizer::normalize('Bucaramanga', $strip));
    }

    public function test_it_is_idempotent(): void
    {
        foreach (array_column(self::cases(), 1) as $normalized) {
            $this->assertSame($normalized, BarrioNormalizer::normalize($normalized));
        }
    }

    public function test_contains_phrase_matches_whole_words_only(): void
    {
        $this->assertTrue(BarrioNormalizer::containsPhrase('LA PAZ FLORIDABLANCA', 'FLORIDABLANCA'));
        $this->assertTrue(BarrioNormalizer::containsPhrase('LA PAZ FLORIDABLANCA', 'LA PAZ'));
        $this->assertFalse(BarrioNormalizer::containsPhrase('MARIA PAZ', 'LA PAZ'));
        $this->assertFalse(BarrioNormalizer::containsPhrase('GIRONDA', 'GIRON'));
    }
}
