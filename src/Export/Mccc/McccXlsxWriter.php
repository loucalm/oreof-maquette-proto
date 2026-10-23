<?php

declare(strict_types=1);

namespace App\Export\Mccc;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Écrit un McccTable en classeur Excel : une feuille par McccSheet, même
 * disposition que les annexes MCCC d'ORéOF v2 (en-tête de formation, tableau
 * regroupé par niveaux, volumes horaires, une colonne par type de MCCC, totaux).
 */
final class McccXlsxWriter
{
    private const HEADER_FILL = 'FFD9E1F2';
    private const TOTAL_FILL = 'FFD9D9D9';

    /** Écrit le classeur dans un flux (php://output, fichier…). */
    public function write(McccTable $table, string $target): void
    {
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('ORéOF')->setTitle($table->title);
        $book->removeSheetByIndex(0);

        foreach ($table->sheets as $sheet) {
            $ws = $book->createSheet();
            $ws->setTitle(self::sheetTitle($sheet->title, $book));
            $this->fill($ws, $table, $sheet);
        }
        if ([] === $table->sheets) {
            $book->createSheet()->setTitle('MCCC')->setCellValue('A1', 'Aucun élément à exporter.');
        }
        $book->setActiveSheetIndex(0);

        (new Xlsx($book))->save($target);
        $book->disconnectWorksheets();
    }

    private function fill(Worksheet $ws, McccTable $table, McccSheet $sheet): void
    {
        $columns = $this->columns($table);
        $last = \count($columns);
        $lastCol = Coordinate::stringFromColumnIndex($last);

        // en-tête de formation
        $ws->setCellValue('A1', mb_strtoupper($table->title));
        $ws->mergeCells('A1:'.$lastCol.'1');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $ws->setCellValue('A2', $sheet->subtitle);
        $ws->mergeCells('A2:'.$lastCol.'2');
        $ws->getStyle('A2')->getFont()->setBold(true)->setSize(12);

        $row = 4;
        foreach ($table->info as [$label, $value]) {
            $ws->setCellValue('A'.$row, $label.' :');
            $ws->getStyle('A'.$row)->getFont()->setBold(true);
            $ws->setCellValue('C'.$row, $value);
            $ws->mergeCells('A'.$row.':B'.$row);
            $ws->mergeCells('C'.$row.':'.Coordinate::stringFromColumnIndex(min($last, 10)).$row);
            ++$row;
        }

        // en-têtes du tableau : catégories puis colonnes
        $head = $row + 1;
        $categories = [];
        foreach ($columns as $i => $c) {
            $categories[$c['category'] ?? ''][] = $i + 1;
        }
        foreach ($categories as $category => $indexes) {
            $from = Coordinate::stringFromColumnIndex(min($indexes));
            $to = Coordinate::stringFromColumnIndex(max($indexes));
            if ('' === $category) {
                foreach ($indexes as $i) {
                    $col = Coordinate::stringFromColumnIndex($i);
                    $ws->setCellValue($col.$head, $columns[$i - 1]['label']);
                    $ws->mergeCells($col.$head.':'.$col.($head + 1));
                }
                continue;
            }
            $ws->setCellValue($from.$head, $category);
            if ($from !== $to) {
                $ws->mergeCells($from.$head.':'.$to.$head);
            }
            foreach ($indexes as $i) {
                $ws->setCellValue(Coordinate::stringFromColumnIndex($i).($head + 1), $columns[$i - 1]['label']);
            }
        }
        $headRange = 'A'.$head.':'.$lastCol.($head + 1);
        $ws->getStyle($headRange)->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
        ]);
        $ws->getRowDimension($head + 1)->setRowHeight(42);

        // lignes
        $first = $head + 2;
        $row = $first;
        $merge = []; // colonne → [début, valeur]
        foreach ($sheet->lines as $line) {
            if ('total' === $line['type']) {
                $this->flushMerges($ws, $merge, $row);
                $this->writeTotal($ws, $columns, $line, $row, $lastCol);
                ++$row;
                continue;
            }
            foreach ($columns as $i => $c) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $value = ($c['value'])($line);
                if (isset($c['group'])) {
                    // cellules de regroupement fusionnées tant que le regroupement ne change pas
                    $groupId = $line['groups'][$c['group']][0] ?? '';
                    if (isset($merge[$col]) && $merge[$col][1] === $groupId) {
                        continue;
                    }
                    $this->flushMerge($ws, $col, $merge, $row);
                    $merge[$col] = [$row, $groupId];
                }
                if (null !== $value && '' !== $value) {
                    $ws->setCellValue($col.$row, $value);
                }
            }
            ++$row;
        }
        $this->flushMerges($ws, $merge, $row);

        if ($row > $first) {
            $ws->getStyle('A'.$first.':'.$lastCol.($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        }
        $ws->getStyle('A'.$head.':'.$lastCol.max($head + 1, $row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        foreach ($columns as $i => $c) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $ws->getColumnDimension($col)->setWidth($c['width']);
            if ($c['numeric'] ?? false) {
                $ws->getStyle($col.$first.':'.$col.max($first, $row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        }

        $ws->freezePane('A'.$first);
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $ws->getHeaderFooter()->setOddFooter('&L&BDocument généré depuis ORéOF&R&P / &N');
    }

    /**
     * @param list<array<string, mixed>> $columns
     * @param array<string, mixed>       $line
     */
    private function writeTotal(Worksheet $ws, array $columns, array $line, int $row, string $lastCol): void
    {
        // libellé sur toutes les colonnes descriptives (celles qui précèdent la première colonne chiffrée)
        $labelEnd = 1;
        foreach ($columns as $i => $c) {
            if ($c['numeric'] ?? false) {
                $labelEnd = max(1, $i);
                break;
            }
        }
        foreach ($columns as $i => $c) {
            $value = isset($c['total']) ? ($c['total'])($line) : null;
            if (null !== $value) {
                $ws->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$row, $value);
            }
        }
        $ws->setCellValue('A'.$row, $line['label']);
        if ($labelEnd > 1) {
            $ws->mergeCells('A'.$row.':'.Coordinate::stringFromColumnIndex($labelEnd).$row);
        }
        $ws->getStyle('A'.$row.':'.$lastCol.$row)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::TOTAL_FILL]],
        ]);
    }

    /** @param array<string, array{0: int, 1: string}> $merge */
    private function flushMerges(Worksheet $ws, array &$merge, int $row): void
    {
        foreach (array_keys($merge) as $col) {
            $this->flushMerge($ws, $col, $merge, $row);
        }
    }

    /** @param array<string, array{0: int, 1: string}> $merge */
    private function flushMerge(Worksheet $ws, string $col, array &$merge, int $row): void
    {
        if (isset($merge[$col]) && $row - 1 > $merge[$col][0]) {
            $ws->mergeCells($col.$merge[$col][0].':'.$col.($row - 1));
        }
        unset($merge[$col]);
    }

    /**
     * Colonnes du tableau : libellé, catégorie (en-tête fusionné), largeur, valeur d'une ligne, total.
     *
     * @return list<array<string, mixed>>
     */
    private function columns(McccTable $table): array
    {
        $cols = [];
        foreach ($table->groupLevels as ['key' => $key, 'label' => $label]) {
            $cols[] = ['label' => $label, 'width' => 12, 'group' => $key, 'value' => static fn (array $l) => $l['groups'][$key][0] ?? ''];
            if ($table->withGroupLabels[$key] ?? false) {
                $cols[] = ['label' => 'Intitulé ('.$label.')', 'width' => 28, 'group' => $key, 'value' => static fn (array $l) => $l['groups'][$key][1] ?? ''];
            }
        }
        $element = 'Élément constitutif';
        $cols[] = ['label' => 'Réf.', 'category' => $element, 'width' => 11, 'value' => static fn (array $l) => $l['ref'] ?? ''];
        $cols[] = ['label' => 'Code', 'category' => $element, 'width' => 12, 'value' => static fn (array $l) => $l['code'] ?? ''];
        $cols[] = ['label' => 'Intitulé', 'category' => $element, 'width' => 34, 'value' => static fn (array $l) => $l['label'] ?? ''];
        $cols[] = ['label' => 'Type', 'category' => $element, 'width' => 14, 'value' => static fn (array $l) => $l['kind'] ?? ''];
        $cols[] = ['label' => 'Nature', 'category' => $element, 'width' => 16, 'value' => static fn (array $l) => $l['nature'] ?? ''];
        $cols[] = ['label' => 'Compétence(s) mobilisée(s)', 'category' => $element, 'width' => 16, 'value' => static fn (array $l) => $l['competencies'] ?? ''];
        $cols[] = ['label' => 'ECTS', 'width' => 7, 'numeric' => true, 'value' => static fn (array $l) => self::num($l['ects'] ?? null), 'total' => static fn (array $l) => self::num($l['ects'] ?? null)];

        foreach (['pres' => 'Présentiel', 'dist' => 'Distanciel'] as $place => $placeLabel) {
            foreach (['Cm' => 'CM', 'Td' => 'TD', 'Tp' => 'TP'] as $m => $mLabel) {
                $k = $place.$m;
                $cols[] = ['label' => $mLabel, 'category' => $placeLabel, 'width' => 7, 'numeric' => true, 'value' => static fn (array $l) => self::num($l['hours'][$k] ?? null), 'total' => static fn (array $l) => self::num($l['hours'][$k] ?? null)];
            }
            $sum = static fn (array $l) => self::num(($l['hours'][$place.'Cm'] ?? 0) + ($l['hours'][$place.'Td'] ?? 0) + ($l['hours'][$place.'Tp'] ?? 0));
            $cols[] = ['label' => 'Total', 'category' => $placeLabel, 'width' => 8, 'numeric' => true, 'value' => $sum, 'total' => $sum];
        }
        $teach = static fn (array $l) => self::num(array_sum(array_intersect_key($l['hours'] ?? [], array_flip(['presCm', 'presTd', 'presTp', 'distCm', 'distTd', 'distTp']))));
        $cols[] = ['label' => 'Total horaire', 'width' => 9, 'numeric' => true, 'value' => $teach, 'total' => $teach];
        $te = static fn (array $l) => self::num($l['hours']['te'] ?? null);
        $cols[] = ['label' => 'Travail étudiant', 'width' => 10, 'numeric' => true, 'value' => $te, 'total' => $te];

        foreach ($table->mcccColumns as $key => $label) {
            $cols[] = ['label' => $label, 'category' => 'Modalités de contrôle', 'width' => 24, 'value' => static fn (array $l) => $l['mccc'][$key] ?? ''];
        }

        return $cols;
    }

    private static function num(mixed $v): int|float|null
    {
        if (null === $v || '' === $v || 0.0 === (float) $v) {
            return null;
        }
        $f = (float) $v;

        return floor($f) === $f ? (int) $f : $f;
    }

    /** Titre de feuille Excel : 31 caractères, sans []:*?/\, unique dans le classeur. */
    private static function sheetTitle(string $title, Spreadsheet $book): string
    {
        $base = mb_substr(trim((string) preg_replace('#[\[\]:*?/\\\\]#', ' ', $title)) ?: 'Feuille', 0, 28);
        $name = $base;
        for ($i = 2; null !== $book->getSheetByName($name); ++$i) {
            $name = $base.' '.$i;
        }

        return $name;
    }
}
