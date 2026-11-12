<?php

namespace App\Services\Empresarios;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Http\Requests\Empresarios\OrganizacionRules;
use App\Models\Organizacion;
use App\Models\OrganizacionImport;
use App\Models\OrganizacionImportRow;
use App\Models\User;
use App\Services\Eventos\AttendeeSheetReader;
use App\Services\Eventos\ImportColumns;
use App\Support\Nit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Excel load of organizations (docs/empresarios.md): columns found by their header, every row validated as the form
// does and staged for review; nothing is written until confirm(). A row that already exists in the campaign (same NIT,
// or same name in the same municipio) is "ya existe": by default it only FILLS the empty fields of the existing one
// (never overwrites a value) and adds the gremio; the user may choose "saltar" instead.
class OrganizacionImportService
{
    use ScopesTerritory;

    // field => [template header, synonyms (normalized)]
    public const COLUMNS = [
        'tipo' => ['Tipo (empresa o gremio)', ['tipo', 'tipo de organizacion', 'clase']],
        'nombre' => ['Nombre o razón social', ['nombre', 'nombre o razon social', 'razon social', 'empresa', 'organizacion', 'nombre de la empresa']],
        'nit' => ['NIT', ['nit', 'no nit', 'numero nit', 'nit empresa']],
        'representante_nombre' => ['Representante legal', ['representante', 'representante legal', 'nombre representante', 'nombre del representante']],
        'representante_cedula' => ['Cédula representante', ['cedula representante', 'cedula del representante', 'cc representante']],
        'telefono' => ['Celular', ['celular', 'telefono', 'telefonos', 'cel', 'movil', 'contacto', 'whatsapp']],
        'correo' => ['Correo', ['correo', 'email', 'e mail', 'correo electronico', 'mail']],
        'sector_economico' => ['Sector económico', ['sector economico', 'actividad economica', 'actividad']],
        'sector' => ['Sector', ['sector', 'naturaleza']],
        'departamento' => ['Departamento', ['departamento', 'depto']],
        'municipio' => ['Municipio', ['municipio', 'mpio', 'ciudad']],
        'direccion' => ['Dirección', ['direccion', 'dir', 'domicilio']],
        'barrio' => ['Barrio o vereda', ['barrio', 'vereda', 'barrio o vereda', 'barrio vereda']],
        'empleados' => ['Número de empleados', ['empleados', 'numero de empleados', 'no empleados', 'n empleados', 'trabajadores']],
        'observaciones' => ['Observaciones', ['observaciones', 'observacion', 'notas', 'nota']],
        'gremio' => ['Gremio al que pertenece', ['gremio', 'gremio al que pertenece', 'gremios', 'afiliado a']],
    ];

    public function __construct(private AttendeeSheetReader $reader) {}

    public function template(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Organizaciones');
        $headers = array_column(self::COLUMNS, 0);
        $sheet->fromArray($headers, null, 'A1');
        $last = chr(ord('A') + count($headers) - 1);
        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '003B71']],
        ]);
        $sheet->freezePane('A2');
        foreach (range('A', $last) as $col) {
            $sheet->getColumnDimension($col)->setWidth(22);
        }
        // NIT, cédula and phone as text: Excel would drop leading zeros or show 9,00123E+08.
        foreach (['C', 'E', 'F'] as $col) {
            $sheet->getStyle("{$col}2:{$col}2001")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }
        $info = $book->createSheet()->setTitle('Instrucciones');
        $info->getColumnDimension('A')->setWidth(110);
        $lines = [
            'Cargue de empresas y gremios',
            '1. Una organización por fila en la hoja «Organizaciones», desde la fila 2. Los encabezados se reconocen por su nombre: puede cambiar el orden.',
            '2. Obligatorios: Tipo (empresa o gremio) y Nombre o razón social.',
            '3. NIT: solo números, con o sin el dígito de verificación después de un guion (ej.: 900123456-8). Si trae el dígito se revisa.',
            '4. Sector económico y Sector deben ser valores de la lista de la plataforma (ej.: Comercio; Privado). El municipio, uno de Santander.',
            '5. Número de empleados: un número (25) o un rango (1-10, 11-50, 51-200, 201-500, 500+).',
            '6. Gremio al que pertenece: el nombre de un gremio que ya esté en la plataforma o que venga en este mismo archivo.',
            '7. Si una organización ya existe (mismo NIT, o mismo nombre en el mismo municipio) solo se completan los datos que tenga vacíos; nunca se borra ni se cambia un dato que ya tenga.',
            '8. Máximo '.AttendeeSheetReader::MAX_ROWS.' filas por archivo. Antes de guardar verá la vista previa para corregir.',
        ];
        foreach ($lines as $i => $text) {
            $info->setCellValueExplicit('A'.($i + 1), $text, DataType::TYPE_STRING);
            $info->getStyle('A'.($i + 1))->getAlignment()->setWrapText(true);
        }
        $info->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $book->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($book))->save('php://output');
        $content = ob_get_clean();
        $book->disconnectWorksheets();

        return $content;
    }

    // Reads the file into a staged import of the user's own campaign. Throws \RuntimeException with a message.
    public function stage(string $path, string $extension, string $fileName, User $user): OrganizacionImport
    {
        $sheet = $this->reader->read($path, $extension, null, fn (array $cells) => self::score($cells));
        $mapping = self::detect($sheet['headers']);
        if ($mapping['nombre'] === null || $mapping['tipo'] === null) {
            throw new \RuntimeException('No se encontraron las columnas «Tipo» y «Nombre o razón social». Use la plantilla o revise los encabezados.');
        }

        return DB::transaction(function () use ($sheet, $mapping, $fileName, $user) {
            $import = (new OrganizacionImport)->forceFill([
                'campaign_id' => $this->campaign()->ownId(), 'user_id' => $user->id, 'archivo_nombre' => mb_substr($fileName, 0, 255), 'estado' => 'revision',
            ]);
            $import->save();
            foreach ($sheet['rows'] as $row) {
                $datos = [];
                foreach ($mapping as $field => $index) {
                    $value = $index !== null ? ($row['cells'][$index] ?? null) : null;
                    $datos[$field] = $value === null ? null : self::cellText($value);
                }
                (new OrganizacionImportRow)->forceFill([
                    'import_id' => $import->id, 'row_number' => $row['row_number'], 'datos' => $datos, 'accion' => 'error',
                ])->save();
            }
            $this->evaluate($import);

            return $import;
        });
    }

    // Re-checks every row (after a correction, too): errors, duplicates in the file and in the campaign.
    public function evaluate(OrganizacionImport $import): void
    {
        $this->withFileGremios($import);
        $ctx = $this->context($import->campaign_id);
        $seen = [];
        foreach ($import->rows()->get() as $row) {
            [$input, $errors] = $this->resolve($row->datos, $ctx);
            $key = $this->keys($input);
            foreach ($key as $k) {
                if (isset($seen[$k])) {
                    $errors['fila'] = "Repetida en el archivo (igual a la fila {$seen[$k]}).";
                }
            }
            if (! isset($errors['fila'])) {
                foreach ($key as $k) {
                    $seen[$k] = $row->row_number;
                }
            }
            $existing = $errors === [] ? $this->existing($ctx, $input) : null;
            if (isset($existing['conflicto'])) {
                $errors['nit'] = $existing['conflicto'];
                $existing = null;
            }
            $accion = match (true) {
                $errors !== [] => 'error',
                $existing === null => 'crear',
                ! $existing['editable'] => 'saltar',
                in_array($row->accion, ['completar', 'saltar'], true) => $row->accion,
                default => 'completar',
            };
            if ($existing && ! $existing['editable']) {
                $errors = [];
            }
            $row->forceFill(['errores' => $errors ?: null, 'existente_id' => $existing['id'] ?? null, 'accion' => $accion])->save();
        }
    }

    // Writes the rows: crear → a new organization; completar → only the empty fields of the existing one (and its
    // gremio); saltar/error → nothing. Returns the counts.
    public function confirm(OrganizacionImport $import, User $user, OrganizacionService $service): array
    {
        $counts = ['creadas' => 0, 'completadas' => 0, 'saltadas' => 0, 'con_error' => 0, 'gremios' => 0];
        DB::transaction(function () use ($import, $user, $service, &$counts) {
            $import = OrganizacionImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($import->estado !== 'revision') {
                throw new \RuntimeException('Esta carga ya se confirmó o se descartó.');
            }
            $this->evaluate($import);
            $ctx = $this->withFileGremios($import)->context($import->campaign_id);
            $rows = $import->rows()->get();
            $pendingGremios = [];

            foreach ($rows as $row) {
                if (in_array($row->accion, ['error', 'saltar'], true)) {
                    $counts[$row->accion === 'error' ? 'con_error' : 'saltadas']++;

                    continue;
                }
                [$input] = $this->resolve($row->datos, $ctx);
                $gremio = $input['_gremio'] ?? null;
                unset($input['_gremio'], $input['gremio_ids']);
                if ($row->accion === 'crear') {
                    [$org] = $service->save(null, $input, $user);
                    $counts['creadas']++;
                } else {
                    $org = Organizacion::findOrFail($row->existente_id);
                    $fill = array_filter($input, fn ($v, $k) => $v !== null && $v !== '' && in_array($k, OrganizacionService::FIELDS, true)
                        && ! in_array($k, ['nit', 'nit_dv'], true) && ($org->{$k} === null || $org->{$k} === ''), ARRAY_FILTER_USE_BOTH);
                    // NIT and its verification digit go together: the digit only completes the same NIT.
                    if (! empty($input['nit']) && empty($org->nit)) {
                        $fill += ['nit' => $input['nit'], 'nit_dv' => $input['nit_dv'] ?? null];
                    } elseif (! empty($input['nit_dv']) && $org->nit === ($input['nit'] ?? null) && empty($org->nit_dv)) {
                        $fill['nit_dv'] = $input['nit_dv'];
                    }
                    if ($fill !== []) {
                        $org->forceFill($fill + ['updated_by' => $user->id])->save();
                    }
                    $counts['completadas']++;
                }
                $row->forceFill(['organizacion_id' => $org->id])->save();
                if ($gremio && $org->tipo === 'empresa') {
                    $pendingGremios[] = [$org->id, $gremio];
                }
            }

            // Gremios: one already in the campaign or one created by this same file.
            foreach ($pendingGremios as [$empresaId, $name]) {
                $gremioId = DB::table('organizaciones')->where('campaign_id', $import->campaign_id)->where('tipo', 'gremio')->whereNull('deleted_at')
                    ->get(['id', 'nombre'])->first(fn ($g) => ImportColumns::normalize($g->nombre) === ImportColumns::normalize($name))?->id;
                if ($gremioId && ! DB::table('organizacion_gremios')->where('empresa_id', $empresaId)->where('gremio_id', $gremioId)->exists()) {
                    DB::table('organizacion_gremios')->insert(['id' => (string) Str::uuid(), 'empresa_id' => $empresaId, 'gremio_id' => $gremioId, 'created_at' => now()]);
                    $counts['gremios']++;
                }
            }
            $import->forceFill(['estado' => 'confirmada', 'confirmed_at' => now(), 'resumen' => $counts])->save();
        });

        return $counts;
    }

    // [input for OrganizacionRules (ids resolved from names), errors]
    public function resolve(array $datos, array $ctx): array
    {
        $errors = [];
        $tipo = ImportColumns::normalize($datos['tipo'] ?? '');
        $input = [
            'tipo' => str_starts_with($tipo, 'gremio') ? 'gremio' : (str_starts_with($tipo, 'empresa') ? 'empresa' : ($tipo === '' ? null : $tipo)),
            'nombre' => $datos['nombre'] ?? null,
            'nit' => Nit::unglue($datos['nit'] ?? null),
            'representante_nombre' => $datos['representante_nombre'] ?? null,
            'representante_cedula' => $datos['representante_cedula'] ?? null,
            'telefono' => $datos['telefono'] ?? null,
            'correo' => $datos['correo'] ?? null,
            'departamento' => $datos['departamento'] ?? null,
            'direccion' => $datos['direccion'] ?? null,
            'barrio' => $datos['barrio'] ?? null,
            'observaciones' => $datos['observaciones'] ?? null,
        ];
        foreach (['sector_economico', 'sector'] as $cat) {
            $name = $datos[$cat] ?? null;
            if ($name !== null && $name !== '') {
                $input[$cat.'_id'] = $ctx['catalog'][$cat][ImportColumns::normalize($name)] ?? null;
                if (! $input[$cat.'_id']) {
                    $errors[$cat.'_id'] = "«{$name}» no está en la lista de ".($cat === 'sector' ? 'sectores' : 'sectores económicos').'.';
                }
            }
        }
        $mun = $datos['municipio'] ?? null;
        if ($mun !== null && $mun !== '') {
            $input['municipio_id'] = $ctx['municipios'][ImportColumns::normalize($mun)] ?? null;
            if (! $input['municipio_id']) {
                $errors['municipio_id'] = "«{$mun}» no es un municipio de su territorio.";
            }
        } elseif ($this->allowedGeoIds() !== null) {
            $errors['municipio_id'] = 'Escriba el municipio (debe estar dentro de su territorio).';
        }
        $emp = trim((string) ($datos['empleados'] ?? ''));
        if ($emp !== '') {
            $rango = str_replace(' ', '', $emp);
            if (preg_match('/^\d+$/', preg_replace('/[.,]/', '', $emp))) {
                $input['empleados'] = (int) preg_replace('/[.,]/', '', $emp);
            } elseif (in_array($rango, Organizacion::RANGOS, true)) {
                $input['empleados_rango'] = $rango;
            } else {
                $errors['empleados'] = 'Empleados: un número o un rango (1-10, 11-50, 51-200, 201-500, 500+).';
            }
        }
        [$data, $ruleErrors] = OrganizacionRules::check($input);
        $errors = $ruleErrors + $errors;
        $gremio = trim((string) ($datos['gremio'] ?? ''));
        if ($gremio !== '' && ($data['tipo'] ?? null) === 'empresa') {
            $known = isset($ctx['gremios'][ImportColumns::normalize($gremio)]) || in_array(ImportColumns::normalize($gremio), $ctx['file_gremios'], true);
            if (! $known) {
                $errors['gremio'] = "El gremio «{$gremio}» no está en la plataforma ni en el archivo.";
            }
            $data['_gremio'] = $gremio;
        }

        return [$data, $errors];
    }

    // Catalogs, municipios of the user's territory and gremios of the campaign, by normalized name.
    private function context(string $campaignId): array
    {
        $catalog = ['sector_economico' => [], 'sector' => []];
        foreach (DB::table('org_catalogos')->where('is_active', true)->where(fn ($q) => $q->whereNull('campaign_id')->orWhere('campaign_id', $campaignId))
            ->orderByRaw('CASE WHEN campaign_id IS NULL THEN 1 ELSE 0 END')->get(['id', 'tipo', 'nombre']) as $c) {
            $catalog[$c->tipo][ImportColumns::normalize($c->nombre)] ??= $c->id;
        }
        $allowed = $this->allowedGeoIds();
        $municipios = [];
        foreach (DB::table('geographic_units')->where('type', 'municipality')->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->get(['id', 'canonical_name']) as $m) {
            $municipios[ImportColumns::normalize($m->canonical_name)] ??= $m->id;
        }
        // The campaign's organizations, loaded once for every row: by NIT, by name+municipio and the gremios by name.
        $byNit = [];
        $byName = [];
        $gremios = [];
        foreach (DB::table('organizaciones')->where('campaign_id', $campaignId)->whereNull('deleted_at')->get(['id', 'nombre', 'nit', 'municipio_id', 'tipo']) as $o) {
            $name = ImportColumns::normalize($o->nombre);
            if ($o->nit) {
                $byNit[$o->nit] ??= $o;
            }
            $byName[$name.'|'.($o->municipio_id ?? '')] ??= $o;
            if ($o->tipo === 'gremio') {
                $gremios[$name] = $o->id;
            }
        }

        return ['catalog' => $catalog, 'municipios' => $municipios, 'gremios' => $gremios, 'file_gremios' => $this->fileGremios ?? [],
            'by_nit' => $byNit, 'by_name' => $byName];
    }

    private ?array $fileGremios = null;

    // Names of the gremios that come in the file being evaluated (an empresa may name one of them).
    public function withFileGremios(OrganizacionImport $import): self
    {
        $this->fileGremios = $import->rows()->get()->filter(fn ($r) => str_starts_with(ImportColumns::normalize($r->datos['tipo'] ?? ''), 'gremio'))
            ->map(fn ($r) => ImportColumns::normalize($r->datos['nombre'] ?? ''))->filter()->values()->all();

        return $this;
    }

    // Keys that make two rows the same organization: the NIT, or the name in the municipio.
    private function keys(array $input): array
    {
        $keys = [];
        if (! empty($input['nit'])) {
            $keys[] = 'nit:'.$input['nit'];
        }
        if (! empty($input['nombre'])) {
            $keys[] = 'nm:'.ImportColumns::normalize($input['nombre']).'|'.($input['municipio_id'] ?? '');
        }

        return $keys;
    }

    // The organization of the campaign this row already is: {id, editable (inside the user's territory)}, null, or
    // {conflicto: message} when the name matches one in the same municipio that has ANOTHER NIT (probably two
    // different organizations, or a mistyped NIT: the user must decide).
    private function existing(array $ctx, array $input): ?array
    {
        $found = ! empty($input['nit']) ? ($ctx['by_nit'][$input['nit']] ?? null) : null;
        if (! $found && ! empty($input['nombre'])) {
            $found = $ctx['by_name'][ImportColumns::normalize($input['nombre']).'|'.($input['municipio_id'] ?? '')] ?? null;
            if ($found && ! empty($input['nit']) && $found->nit && $found->nit !== $input['nit']) {
                return ['conflicto' => "Mismo nombre que «{$found->nombre}» en el municipio, pero otro NIT: revise el NIT o cambie el nombre."];
            }
        }
        if (! $found) {
            return null;
        }
        $allowed = $this->allowedGeoIds();

        return ['id' => $found->id, 'editable' => $allowed === null || in_array($found->municipio_id, $allowed, true)];
    }

    public static function detect(array $headers): array
    {
        $normalized = array_map(fn ($h) => ImportColumns::normalize((string) $h), $headers);
        $mapping = array_fill_keys(array_keys(self::COLUMNS), null);
        $used = [];
        foreach (self::COLUMNS as $field => [$label, $synonyms]) {
            foreach ([ImportColumns::normalize($label), ...$synonyms] as $syn) {
                foreach ($normalized as $i => $h) {
                    if (! isset($used[$i]) && $h === $syn) {
                        $mapping[$field] = $i;
                        $used[$i] = true;
                        break 2;
                    }
                }
            }
        }

        return $mapping;
    }

    public static function score(array $cells): int
    {
        $all = [];
        foreach (self::COLUMNS as [$label, $synonyms]) {
            $all = [...$all, ImportColumns::normalize($label), ...$synonyms];
        }

        return count(array_filter($cells, fn ($c) => is_string($c) && in_array(ImportColumns::normalize($c), $all, true)));
    }

    // A number typed in Excel comes back as int/float: "900123456", never "9.00123456E+8".
    private static function cellText($value): string
    {
        if (is_float($value) && floor($value) === $value) {
            return number_format($value, 0, '', '');
        }

        return trim((string) $value);
    }
}
