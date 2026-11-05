<?php

namespace App\Services\Eventos;

use App\Http\Requests\Eventos\ImportUploadRequest;
use App\Models\CampaignEvent;
use App\Models\ConsentText;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Attendee template of an event: sheet "Asistentes" with the fixed headers, cédula, phones and date formatted as
// text (Excel would otherwise show 1.09877E+09 or drop leading zeros) and a SÍ/NO list in "Autorizó"; sheet
// "Instrucciones" with how to fill it and the campaign's current authorization text with its version.
class AttendeeTemplate
{
    public const ROWS = 2000;

    public const TEXT_COLUMNS = ['C', 'D', 'E', 'F'];

    public function build(CampaignEvent $event, ?ConsentText $consent): string
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
        foreach (['A' => 22, 'B' => 22, 'C' => 16, 'D' => 15, 'E' => 15, 'F' => 22, 'G' => 30, 'H' => 22, 'I' => 16, 'J' => 20, 'K' => 26] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        foreach (self::TEXT_COLUMNS as $column) {
            $sheet->getStyle("{$column}2:{$column}".(self::ROWS + 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $validation = new DataValidation;
        $validation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Autorizó')
            ->setError('Escriba SÍ o NO.')
            ->setFormula1('"SÍ,NO"');
        $sheet->setDataValidation('I2:I'.(self::ROWS + 1), $validation);

        $this->instructions($book, $event, $consent);
        $book->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($book))->save('php://output');
        $content = ob_get_clean();
        $book->disconnectWorksheets();

        return $content;
    }

    private function instructions(Spreadsheet $book, CampaignEvent $event, ?ConsentText $consent): void
    {
        $sheet = $book->createSheet()->setTitle('Instrucciones');
        $sheet->getColumnDimension('A')->setWidth(110);

        $lines = [
            ['Planilla de asistentes — '.$event->nombre, 'title'],
            ['', null],
            ['Cómo llenarla', 'heading'],
            ['1. Escriba una persona por fila en la hoja «Asistentes», desde la fila 2. No cambie los encabezados.', null],
            ['2. Nombres, Apellidos, Cédula y Celular son obligatorios. La cédula va solo con números (5 a 10 dígitos), sin puntos.', null],
            ['3. El celular tiene 10 dígitos y empieza por 3. La fecha de nacimiento va como DD/MM/AAAA (por ejemplo 02/04/1980).', null],
            ['4. En «Autorizó» escriba SÍ solo si la persona firmó la autorización de datos en la planilla de papel; si no, escriba NO. Solo se cargan las personas con SÍ.', null],
            ['5. Lista sin autorización: si deja «Autorizó» vacío en TODAS las filas, solo se carga como lista del evento. Quien ya está en la plataforma (por cédula, o por teléfono y nombre que usted confirma) queda con la asistencia en su historial; quien no está, queda solo en la lista del evento y no pasa a Votantes hasta que autorice por QR u operador. En ese caso solo el nombre es obligatorio.', null],
            ['6. «Municipio» y «Liderazgo o actividad» son opcionales; el liderazgo o la actividad quedan como nota del asistente (no cambian la ficha).', null],
            ['7. Máximo '.AttendeeSheetReader::MAX_ROWS.' personas por archivo y '.ImportUploadRequest::maxMb().' MB. Guarde el archivo como .xlsx.', null],
            ['', null],
            ['Texto de autorización de datos vigente (Ley 1581 de 2012)', 'heading'],
            ['Versión: '.($consent?->version ?? 'sin texto'), 'bold'],
        ];
        if (! $consent?->approved) {
            $lines[] = ['ATENCIÓN: este texto es PROVISIONAL y todavía no está aprobado por la campaña. Las autorizaciones '
                .'recogidas con él no sirven: la plataforma no deja importar filas con SÍ hasta que la campaña apruebe su texto.', 'warning'];
        }
        $lines[] = [$consent?->cuerpo ?? 'La campaña no tiene un texto de autorización.', 'wrap'];

        foreach ($lines as $i => [$text, $style]) {
            $cell = 'A'.($i + 1);
            $sheet->setCellValueExplicit($cell, $text, DataType::TYPE_STRING);
            $font = $sheet->getStyle($cell)->getFont();
            $sheet->getStyle($cell)->getAlignment()->setWrapText(true)->setVertical('top');
            match ($style) {
                'title' => $font->setBold(true)->setSize(15)->getColor()->setRGB('003B71'),
                'heading' => $font->setBold(true)->setSize(13),
                'bold' => $font->setBold(true),
                'warning' => $this->warning($sheet->getStyle($cell)),
                default => null,
            };
        }
        $sheet->getRowDimension(count($lines))->setRowHeight(max(60, (int) ceil(mb_strlen($consent?->cuerpo ?? '') / 110) * 16));
    }

    private function warning($style): void
    {
        $style->getFont()->setBold(true)->getColor()->setRGB('7A2E00');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFE8A3');
    }
}
