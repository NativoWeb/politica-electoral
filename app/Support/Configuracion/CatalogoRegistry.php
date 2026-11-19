<?php

namespace App\Support\Configuracion;

// Lists of the forms that the superadmin edits in Centro de configuración (/admin/configuracion/listas). The options
// below are the values the screens had hardcoded before the Centro existed: the migration loads them once and from
// then on the table catalogo_opciones rules; they are also the fallback while that table does not exist.
//
// valor  = what the ficha stores (never edited: renaming changes only the etiqueta shown in the forms).
// fijo   = the code relies on the valores (filters, defaults, WhatsApp, agent API): options cannot be added or deleted,
//          only renamed, reordered, coloured or switched off.
// protegidos = valores the code writes itself (defaults): they cannot be switched off.
// uso    = [table, column] that stores the valor, to know whether an option is in use (in use = never deleted).
// codigo = a new option's valor is a slug of its etiqueta (the ficha stores a code) instead of the etiqueta itself.
class CatalogoRegistry
{
    public const DEFINICIONES = [
        'tipo_documento' => [
            'nombre' => 'Tipo de documento',
            'descripcion' => 'Fichas de Votantes y del Mapa político.',
            'uso' => ['lideres', 'tipo_documento'],
            'opciones' => [
                ['CC', 'CC'], ['CE', 'CE'], ['TI', 'TI'], ['PA', 'PA'], ['RC', 'RC'], ['NIP', 'NIP'], ['NUIP', 'NUIP'],
                ['NIT', 'NIT'],
                // Only arrive from the Electo migration (Normalizer::TIPO_DOCUMENTO); off as before.
                ['TE', 'TE', null, false], ['DE', 'DE', null, false],
            ],
        ],
        'genero' => [
            'nombre' => 'Género',
            'descripcion' => 'Fichas de Votantes y del Mapa político.',
            'uso' => ['lideres', 'genero'],
            'opciones' => [['Masculino', 'Masculino'], ['Femenino', 'Femenino'], ['Otro', 'Otro'], ['No binario', 'No binario']],
        ],
        'estado_civil' => [
            'nombre' => 'Estado civil',
            'descripcion' => 'Fichas de Votantes y del Mapa político.',
            'uso' => ['lideres', 'estado_civil'],
            'opciones' => [
                ['Soltero/a', 'Soltero/a'], ['Casado/a', 'Casado/a'], ['Unión libre', 'Unión libre'],
                ['Divorciado/a', 'Divorciado/a'], ['Viudo/a', 'Viudo/a'],
            ],
        ],
        'escolaridad' => [
            'nombre' => 'Escolaridad',
            'descripcion' => 'Fichas de Votantes y del Mapa político.',
            'uso' => ['lideres', 'escolaridad'],
            'opciones' => [
                ['Primaria', 'Primaria'], ['Secundaria', 'Secundaria'], ['Técnico', 'Técnico'], ['Tecnólogo', 'Tecnólogo'],
                ['Universitario', 'Universitario'], ['Posgrado', 'Posgrado'], ['Ninguno', 'Ninguno'],
                // Offered only by the old Votantes form; kept off so the fichas that have it show its name.
                ['Profesional', 'Profesional', null, false],
            ],
        ],
        'nivel_confianza' => [
            'nombre' => 'Nivel de confianza',
            'descripcion' => 'Seguimiento de Votantes. Los valores los usan los filtros y el asistente de WhatsApp.',
            'uso' => ['lideres', 'nivel_confianza'],
            'fijo' => true,
            'protegidos' => ['sin_llamar'],
            'opciones' => [
                ['sin_llamar', 'Sin llamar', '#4B5563'], ['contactado', 'Contactado', '#1D4ED8'],
                ['confirmado', 'Confirmado', '#047857'], ['comprometido', 'Comprometido', '#166534'],
                ['no_responde', 'No responde', '#DC2626'],
            ],
        ],
        'parentesco' => [
            'nombre' => 'Parentesco (familia y nexos)',
            'descripcion' => 'Pestaña Familia / Nexos de la ficha.',
            'uso' => ['nexos_familiares', 'parentesco'],
            'opciones' => [
                ['Esposa', 'Esposa'], ['Esposo', 'Esposo'], ['Hijo/a', 'Hijo/a'], ['Hermano/a', 'Hermano/a'],
                ['Padre', 'Padre'], ['Madre', 'Madre'], ['Sobrino/a', 'Sobrino/a'], ['Tío/a', 'Tío/a'],
                ['Primo/a', 'Primo/a'], ['Cuñado/a', 'Cuñado/a'], ['Suegro/a', 'Suegro/a'], ['Otro', 'Otro'],
            ],
        ],
        'tipo_contacto' => [
            'nombre' => 'Tipo de contacto',
            'descripcion' => 'Contactos de la ficha. Los envíos de WhatsApp y la unificación usan estos valores.',
            'uso' => ['lider_contactos', 'tipo'],
            'fijo' => true,
            'opciones' => [['celular', 'Celular'], ['fijo', 'Fijo'], ['whatsapp', 'WhatsApp'], ['email', 'Email'], ['otro', 'Otro']],
        ],
        'zona' => [
            'nombre' => 'Zona',
            'descripcion' => 'Ubicación de la ficha (rural o urbana).',
            'uso' => ['lideres', 'zona'],
            'fijo' => true,
            'opciones' => [['rural', 'Rural'], ['urbana', 'Urbana']],
        ],
        'rol_miembro' => [
            'nombre' => 'Rol de miembro de organización',
            'descripcion' => 'Miembros de empresas y gremios (Empresarios).',
            'uso' => ['organizacion_miembros', 'rol'],
            'codigo' => true,
            'protegidos' => ['representante_legal'],
            'opciones' => [
                ['representante_legal', 'Representante legal'], ['gerente', 'Gerente'], ['socio', 'Socio'],
                ['afiliado', 'Afiliado'], ['empleado', 'Empleado'], ['otro', 'Otro'],
            ],
        ],
    ];

    // Lists that already have their own screen: the Centro only links to them.
    public const ACCESOS_DIRECTOS = [
        ['titulo' => 'Partidos', 'descripcion' => 'Partidos y movimientos, con su logo', 'href' => '/admin/partidos'],
        ['titulo' => 'Cargos', 'descripcion' => 'Categorías de cargo del Mapa político', 'href' => '/admin/cargos'],
        ['titulo' => 'Tipos de evento', 'descripcion' => 'Se editan desde Eventos', 'href' => '/eventos'],
        ['titulo' => 'Sectores de empresarios', 'descripcion' => 'Sectores económicos y sectores, desde Empresarios', 'href' => '/empresarios'],
        ['titulo' => 'Grupos políticos', 'descripcion' => 'Grupos políticos, subgrupos y su equipo', 'href' => '/admin/campanas'],
    ];

    public static function existe(string $catalogo): bool
    {
        return isset(self::DEFINICIONES[$catalogo]);
    }

    public static function definicion(string $catalogo): array
    {
        $def = self::DEFINICIONES[$catalogo];

        return [
            'clave' => $catalogo,
            'nombre' => $def['nombre'],
            'descripcion' => $def['descripcion'],
            'uso' => $def['uso'],
            'fijo' => (bool) ($def['fijo'] ?? false),
            'codigo' => (bool) ($def['codigo'] ?? false),
            'protegidos' => $def['protegidos'] ?? [],
        ];
    }

    // The original options as rows (no id): what the migration inserts and the fallback without the table.
    public static function opcionesIniciales(string $catalogo): array
    {
        $rows = [];
        foreach (self::DEFINICIONES[$catalogo]['opciones'] as $i => $o) {
            $rows[] = [
                'id' => null,
                'valor' => $o[0],
                'etiqueta' => $o[1],
                'orden' => $i + 1,
                'activo' => $o[3] ?? true,
                'color' => $o[2] ?? null,
            ];
        }

        return $rows;
    }
}
