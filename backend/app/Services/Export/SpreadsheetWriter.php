<?php

namespace App\Services\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Outil commun d'écriture des classeurs Excel : feuille avec titre,
 * sous-titre, en-têtes stylés, lignes alternées et ligne de total.
 *
 * Colonnes : [['key' => ..., 'header' => ..., 'width' => 16, 'money' => bool, 'pct' => bool], ...]
 */
class SpreadsheetWriter
{
    public const MONEY_FORMAT = '#,##0.00" DT"';

    public function __construct(
        private readonly string $headerColor = '1D4ED8',
        private readonly string $titleColor = '1E3A8A',
    ) {}

    public function table(Spreadsheet $book, string $sheetName, string $title, string $subtitle, array $cols, array $rows, ?array $totals = null): Worksheet
    {
        $ws = $book->getSheetCount() === 1 && $book->getActiveSheet()->getTitle() === 'Worksheet'
            ? $book->getActiveSheet()
            : $book->createSheet();
        $ws->setTitle(mb_substr($sheetName, 0, 31));

        $n = count($cols);
        $last = Coordinate::stringFromColumnIndex(max(1, $n));

        // Titre et sous-titre fusionnés sur toute la largeur.
        $ws->setCellValue('A1', $title)->mergeCells("A1:{$last}1");
        $ws->setCellValue('A2', $subtitle)->mergeCells("A2:{$last}2");
        $ws->getStyle("A1:{$last}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Arial'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $this->titleColor]],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        ]);
        $ws->getStyle("A2:{$last}2")->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '1E40AF'], 'name' => 'Arial'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DBEAFE']],
            'alignment' => ['horizontal' => 'center'],
        ]);
        $ws->getRowDimension(1)->setRowHeight(26);
        $ws->getRowDimension(2)->setRowHeight(18);

        // En-têtes (ligne 4).
        foreach (array_values($cols) as $i => $col) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $ws->setCellValue("{$letter}4", $col['header']);
            $ws->getColumnDimension($letter)->setWidth($col['width'] ?? 16);
        }
        $ws->getStyle("A4:{$last}4")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Arial'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $this->headerColor]],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        ]);
        $ws->getRowDimension(4)->setRowHeight(22);

        // Données (ligne 5 et suivantes).
        $r = 5;
        foreach ($rows as $index => $row) {
            foreach (array_values($cols) as $i => $col) {
                $ws->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$r, $row[$col['key']] ?? '');
            }
            $ws->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $index % 2 === 0 ? 'FFFFFF' : 'F0F9FF']],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
            ]);
            $r++;
        }
        $lastDataRow = $r - 1;

        // Formats monétaires et pourcentages.
        foreach (array_values($cols) as $i => $col) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            if ($lastDataRow >= 5 && ! empty($col['money'])) {
                $ws->getStyle("{$letter}5:{$letter}{$lastDataRow}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => '15803D']],
                    'numberFormat' => ['formatCode' => self::MONEY_FORMAT],
                ]);
            }
            if ($lastDataRow >= 5 && ! empty($col['pct'])) {
                $ws->getStyle("{$letter}5:{$letter}{$lastDataRow}")->getNumberFormat()->setFormatCode('0.00"%"');
            }
        }

        if ($totals !== null) {
            foreach (array_values($cols) as $i => $col) {
                $letter = Coordinate::stringFromColumnIndex($i + 1);
                $value = $totals[$col['key']] ?? '';
                $ws->setCellValue("{$letter}{$r}", $value);
                if (! empty($col['money']) && is_numeric($value)) {
                    $ws->getStyle("{$letter}{$r}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
                }
            }
            $ws->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Arial'],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $this->headerColor]],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '93C5FD']]],
            ]);
        }

        $ws->freezePane('A5');

        return $ws;
    }

    /** Contenu binaire du classeur au format .xlsx. */
    public function toBinary(Spreadsheet $book): string
    {
        $book->setActiveSheetIndex(0);
        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }

    public static function round(float $n): float
    {
        return round($n, 2);
    }
}
