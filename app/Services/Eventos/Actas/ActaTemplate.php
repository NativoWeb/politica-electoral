<?php

namespace App\Services\Eventos\Actas;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Excel template of an acta (read back by ActaSheetReader): "Acta" (Campo | Valor), "Participantes", "Compromisos"
// and "Instrucciones" with a fictitious example. The data sheets carry no example rows, so nothing fake is loaded.
class ActaTemplate
{
    public const CAMPOS = [
        'Título', 'Fecha', 'Hora de inicio', 'Hora de fin', 'Municipio', 'Lugar', 'Objetivo / tema',
        'Orden del día', 'Desarrollo', 'Conclusiones', 'Cierre',
    ];

    public const PARTICIPANTES = ['Nombre', 'Cédula', 'Teléfono', 'Municipio', 'Perfil / cargo'];

    public const COMPROMISOS = ['Descripción', 'Responsable', 'Teléfono del responsable', 'Fecha límite', 'Recordar el'];

    private const ROWS = 300;

    public function build(): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Inteligencia Electoral')->setTitle('Plantilla de acta de reunión');

        $acta = $book->getActiveSheet()->setTitle('Acta');
        $this->headers($acta, ['Campo', 'Valor'], ['A' => 22, 'B' => 90]);
        foreach (self::CAMPOS as $i => $campo) {
            $acta->setCellValueExplicit('A'.($i + 2), $campo, DataType::TYPE_STRING);
            $acta->getStyle('A'.($i + 2))->getFont()->setBold(true);
        }
        $acta->getStyle('B2:B'.(count(self::CAMPOS) + 30))->getAlignment()->setWrapText(true)->setVertical('top');
        $acta->getStyle('B'.(array_search('Fecha', self::CAMPOS, true) + 2))->getNumberFormat()->setFormatCode('dd/mm/yyyy');

        $participantes = $book->createSheet()->setTitle('Participantes');
        $this->headers($participantes, self::PARTICIPANTES, ['A' => 34, 'B' => 16, 'C' => 16, 'D' => 20, 'E' => 30]);
        $participantes->getStyle('B2:C'.(self::ROWS + 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $compromisos = $book->createSheet()->setTitle('Compromisos');
        $this->headers($compromisos, self::COMPROMISOS, ['A' => 50, 'B' => 30, 'C' => 22, 'D' => 15, 'E' => 15]);
        $compromisos->getStyle('C2:C'.(self::ROWS + 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $compromisos->getStyle('D2:E'.(self::ROWS + 1))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $compromisos->getStyle('A2:B'.(self::ROWS + 1))->getAlignment()->setWrapText(true)->setVertical('top');

        $this->instructions($book);
        $book->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($book))->save('php://output');
        $content = ob_get_clean();
        $book->disconnectWorksheets();

        return $content;
    }

    private function headers(Worksheet $sheet, array $headers, array $widths): void
    {
        $sheet->fromArray($headers, null, 'A1');
        $last = chr(ord('A') + count($headers) - 1);
        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '003B71']],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->freezePane('A2');
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function instructions(Spreadsheet $book): void
    {
        $sheet = $book->createSheet()->setTitle('Instrucciones');
        $sheet->getColumnDimension('A')->setWidth(110);

        $lines = [
            ['Plantilla de acta de reunión', 'title'],
            ['', null],
            ['Cómo llenarla', 'heading'],
            ['1. Hoja «Acta»: escriba cada dato en la columna «Valor», al lado de su campo. No cambie los nombres de la columna «Campo» ni de las hojas.', null],
            ['2. La fecha va como DD/MM/AAAA (por ejemplo 08/11/2026) y las horas como HH:MM (por ejemplo 10:45 o 3:30 p. m.).', null],
            ['3. «Orden del día»: un punto por línea dentro de la celda (Alt + Enter para cambiar de línea), o repita la fila «Orden del día» una vez por punto.', null],
            ['4. «Desarrollo»: repita la fila «Desarrollo» una vez por cada punto del orden del día, en el mismo orden. «Conclusiones» queda como un punto más del desarrollo.', null],
            ['5. Hoja «Participantes»: una persona por fila. Solo el nombre es obligatorio; con la cédula o el celular el sistema le pregunta si ya está en la plataforma.', null],
            ['6. Hoja «Compromisos»: un compromiso por fila. Si tiene varios responsables, escriba uno por línea (o separados por «;») y sus teléfonos en el mismo orden.', null],
            ['7. Al subir el archivo el formulario se llena solo: revise todo antes de guardar. Nada se guarda hasta que usted pulse «Guardar borrador».', null],
            ['', null],
            ['Ejemplo (ficticio)', 'heading'],
            ['Acta → Título: REUNIÓN DE PRUEBA VEREDA EJEMPLO · Fecha: 08/11/2026 · Hora de inicio: 10:00 · Lugar: Salón comunal', null],
            ['Participantes → Nombre: Persona De Ejemplo · Cédula: 1234567 · Teléfono: 3000000000 · Municipio: Bucaramanga · Perfil / cargo: Presidente JAC', null],
            ['Compromisos → Descripción: Enviar el listado de necesidades · Responsable: Persona De Ejemplo · Fecha límite: 30/11/2026 · Recordar el: 25/11/2026', null],
        ];

        foreach ($lines as $i => [$text, $style]) {
            $cell = 'A'.($i + 1);
            $sheet->setCellValueExplicit($cell, $text, DataType::TYPE_STRING);
            $font = $sheet->getStyle($cell)->getFont();
            $sheet->getStyle($cell)->getAlignment()->setWrapText(true)->setVertical('top');
            match ($style) {
                'title' => $font->setBold(true)->setSize(15)->getColor()->setRGB('003B71'),
                'heading' => $font->setBold(true)->setSize(13),
                default => null,
            };
        }
    }
}
