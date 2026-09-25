<?php

namespace App\Support\Spreadsheet;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Streaming reader for the one thing we need from .xlsx: the first worksheet,
 * as rows of strings.
 *
 * Written by hand rather than pulled in as a dependency because the project
 * has no Composer packages beyond Laravel itself, installs offline, and this
 * is the whole of the requirement. An .xlsx is a zip of XML; the parts that
 * matter are the shared-string table and one sheet.
 *
 * Streaming (XMLReader, not simplexml_load_file) is the point — the student
 * list is expected to reach thousands of rows, and loading a whole sheet DOM
 * into memory is how an import dies at 40 %.
 *
 * Known limits, deliberate:
 *  - values come back as strings, exactly as stored. A date cell yields its
 *    serial number, because resolving it needs the style table and no column
 *    we import is a date.
 *  - only the first worksheet is read.
 */
class XlsxReader
{
    /** Shared-string table, loaded once per file. */
    private array $strings = [];

    private string $path;

    /** Tab to read. Null means the first one. */
    private ?string $sheet;

    public function __construct(string $path, ?string $sheet = null)
    {
        if (! is_file($path)) {
            throw new RuntimeException("Berkas tidak ditemukan: {$path}");
        }

        $this->path = $path;
        $this->sheet = $sheet;
    }

    /**
     * Every row of the first sheet, including the header row.
     *
     * @return Generator<int, array<int, string>>
     */
    public function rows(): Generator
    {
        $zip = new ZipArchive;

        if ($zip->open($this->path) !== true) {
            throw new RuntimeException('Berkas .xlsx tidak bisa dibuka — kemungkinan rusak.');
        }

        $sheetName = $this->firstSheetPath($zip);
        $this->strings = $this->sharedStrings($zip);

        // XMLReader cannot read from inside the archive, so the sheet is
        // streamed out through the zip:// wrapper instead of being extracted
        // to a temp file.
        $stream = 'zip://'.$this->path.'#'.$sheetName;

        $reader = new XMLReader;

        if (! @$reader->open($stream)) {
            $zip->close();

            throw new RuntimeException('Lembar kerja pertama tidak bisa dibaca.');
        }

        try {
            // Walk forward to the first <row>.
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row') {
                    break;
                }
            }

            // Then hop from <row> to <row>. next('row') lands on the NEXT
            // sibling row, so calling read() as well would step over one and
            // silently return every other line.
            while ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row') {
                $xml = $reader->readOuterXml();

                if ($xml !== '') {
                    yield $this->parseRow($xml);
                }

                if (! $reader->next('row')) {
                    break;
                }
            }
        } finally {
            $reader->close();
            $zip->close();
        }
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /** @return array<int, string> */
    private function parseRow(string $xml): array
    {
        $row = [];

        $node = @simplexml_load_string($xml);

        if ($node === false) {
            return $row;
        }

        foreach ($node->c as $cell) {
            $ref = (string) ($cell['r'] ?? '');
            $index = $ref === '' ? count($row) : self::columnIndex($ref);
            $type = (string) ($cell['t'] ?? '');

            $value = match ($type) {
                // Shared string: <v> holds an index into the string table.
                's' => $this->strings[(int) $cell->v] ?? '',
                // Inline string: the text sits in <is><t>, sometimes split
                // across several <t> runs when the cell is rich text.
                'inlineStr' => $this->richText($cell->is),
                default => isset($cell->v) ? (string) $cell->v : '',
            };

            $row[$index] = trim($value);
        }

        if ($row === []) {
            return [];
        }

        // Fill the gaps left by empty cells so column positions still line up
        // with the header row.
        $max = max(array_keys($row));

        for ($i = 0; $i <= $max; $i++) {
            $row[$i] ??= '';
        }

        ksort($row);

        return array_values($row);
    }

    private function richText(mixed $node): string
    {
        if ($node === null) {
            return '';
        }

        $text = '';

        foreach ($node->t as $run) {
            $text .= (string) $run;
        }

        // A rich-text cell nests its runs one level deeper, in <r><t>.
        foreach ($node->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        // A sheet made entirely of numbers and inline strings has no table at
        // all, which is valid.
        if ($xml === false || $xml === '') {
            return [];
        }

        $doc = @simplexml_load_string($xml);

        if ($doc === false) {
            return [];
        }

        $strings = [];

        foreach ($doc->si as $item) {
            $strings[] = $this->richText($item);
        }

        return $strings;
    }

    /**
     * The worksheet to read: the one named in the constructor, or the first tab.
     *
     * Resolved through workbook.xml → workbook.xml.rels, NOT by guessing at
     * "sheet1.xml". A real workbook proved why: its tabs are DASHBOARD and
     * DATA INDUK, and the 11 MB of student records live in sheet2.xml while
     * sheet1.xml holds a six-cell summary. Reading "the first file" would have
     * imported the summary and reported the spreadsheet as empty.
     */
    private function firstSheetPath(ZipArchive $zip): string
    {
        $sheets = self::sheetsIn($zip);

        if ($sheets === []) {
            throw new RuntimeException('Tidak ada lembar kerja di dalam berkas .xlsx.');
        }

        if ($this->sheet !== null) {
            foreach ($sheets as $name => $path) {
                if (mb_strtolower(trim($name)) === mb_strtolower(trim($this->sheet))) {
                    return $path;
                }
            }

            throw new RuntimeException("Lembar kerja “{$this->sheet}” tidak ada di dalam berkas.");
        }

        return reset($sheets);
    }

    /**
     * Tab name => internal path, in the order the tabs appear.
     *
     * @return array<string, string>
     */
    public static function sheetsIn(ZipArchive $zip): array
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false || $relsXml === false) {
            return self::scanForSheets($zip);
        }

        $doc = @simplexml_load_string($workbook);
        $rels = @simplexml_load_string($relsXml);

        if ($doc === false || $rels === false || ! isset($doc->sheets)) {
            return self::scanForSheets($zip);
        }

        $targets = [];

        foreach ($rels->Relationship as $rel) {
            $targets[(string) $rel['Id']] = ltrim((string) $rel['Target'], '/');
        }

        $sheets = [];

        foreach ($doc->sheets->sheet as $sheet) {
            $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
            $target = $targets[$id] ?? null;

            if ($target === null) {
                continue;
            }

            // Targets are relative to xl/ unless already absolute.
            $path = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;

            if ($zip->locateName($path) !== false) {
                $sheets[(string) $sheet['name']] = $path;
            }
        }

        return $sheets ?: self::scanForSheets($zip);
    }

    /**
     * Last resort for a workbook whose relationships we cannot read: every
     * worksheet part, keyed by its file name.
     *
     * @return array<string, string>
     */
    private static function scanForSheets(ZipArchive $zip): array
    {
        $sheets = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (str_starts_with($name, 'xl/worksheets/') && str_ends_with($name, '.xml')) {
                $sheets[basename($name, '.xml')] = $name;
            }
        }

        ksort($sheets);

        return $sheets;
    }

    /**
     * The tab names in this file, for a UI that lets someone choose.
     *
     * @return array<int, string>
     */
    public function sheetNames(): array
    {
        $zip = new ZipArchive;

        if ($zip->open($this->path) !== true) {
            return [];
        }

        try {
            return array_keys(self::sheetsIn($zip));
        } finally {
            $zip->close();
        }
    }

    /**
     * "B7" → 1. Column letters are base-26 with no zero, so AA follows Z.
     */
    public static function columnIndex(string $ref): int
    {
        $letters = rtrim($ref, '0123456789');
        $index = 0;

        foreach (str_split($letters) as $char) {
            $index = $index * 26 + (ord(strtoupper($char)) - 64);
        }

        return max(0, $index - 1);
    }
}
