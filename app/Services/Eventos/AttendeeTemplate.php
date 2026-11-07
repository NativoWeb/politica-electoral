<?php

namespace App\Services\Eventos;

use App\Http\Requests\Eventos\ImportUploadRequest;
use App\Models\CampaignEvent;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Attendee template of an event: sheet "Asistentes" with the fixed headers and cédula, phones and date formatted as
// text (Excel would otherwise show 1.09877E+09 or drop leading zeros); sheet "Instrucciones" with how to fill it.
class AttendeeTemplate
{
    public const ROWS = 2000;

    public const TEXT_COLUMNS = ['C', 'D', 'E', 'F'];

    public function build(CampaignEvent $event): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Inteligencia Electoral')->setTitle('Asistentes — '.$event->nombre);

        $sheet = $book->getActiveSheet()->setTitle('Asistentes');
        $headers = ImportColumns::templateHeaders();
        $sheet->fromArray($headers, null, 'A1');
        $last = chr(ord('A') + count($headers) - 1);
        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '003B71']],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->freezePane('A2');
        foreach (['A' => 22, 'B' => 22, 'C' => 16, 'D' => 15, 'E' => 15, 'F' => 22, 'G' => 30, 'H' => 22, 'I' => 20, 'J' => 26] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        foreach (self::TEXT_COLUMNS as $column) {
            $sheet->getStyle("{$column}2:{$column}".(self::ROWS + 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $this->instructions($book, $event);
        $book->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($book))->save('php://output');
        $content = ob_get_clean();
        $book->disconnectWorksheets();

        return $content;
    }

    private function instructions(Spreadsheet $book, CampaignEvent $event): void
    {
        $sheet = $book->createSheet()->setTitle('Instrucciones');
        $sheet->getColumnDimension('A')->setWidth(110);

        $lines = [
            ['Planilla de asistentes — '.$event->nombre, 'title'],
            ['', null],
            ['Cómo llenarla', 'heading'],
            ['1. Escriba una persona por fila en la hoja «Asistentes», desde la fila 2. No cambie los encabezados.', null],
            ['2. Solo el nombre es obligatorio; escriba también la cédula o el celular para saber si la persona ya está en la plataforma. La cédula va solo con números (5 a 10 dígitos), sin puntos.', null],
            ['3. El celular tiene 10 dígitos y empieza por 3. La fecha de nacimiento va como DD/MM/AAAA (por ejemplo 02/04/1980).', null],
            ['4. Quien ya está en la plataforma (por cédula, o por teléfono y nombre que usted confirma) queda con la asistencia en su historial; quien no está queda en el evento pendiente de aprobar para pasar a Votantes.', null],
            ['5. «Municipio» y «Liderazgo o actividad» son opcionales; el liderazgo o la actividad quedan como nota del asistente (no cambian la ficha).', null],
            ['6. Máximo '.AttendeeSheetReader::MAX_ROWS.' personas por archivo y '.ImportUploadRequest::maxMb().' MB. Guarde el archivo como .xlsx.', null],
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
