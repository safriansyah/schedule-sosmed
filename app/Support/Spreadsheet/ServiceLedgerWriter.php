<?php

namespace App\Support\Spreadsheet;

use RuntimeException;
use ZipArchive;

/**
 * "Buku Besar Daftar Layanan" as an .xlsx laid out like the printed form the
 * front desk used to fill by hand: the form code box top right, the title,
 * UNIT and MASA REGISTRASI, the two-row header with "Proses Penyelesaian"
 * spanning its five columns, and every visitor's drawn paraf embedded as a
 * picture in its own cell.
 *
 * Hand-rolled for the same reason as XlsxWriter (no spreadsheet package in
 * this app); it only adds the parts that writer does not need — styles with
 * borders, merged cells, column widths, print setup and a drawing part for
 * the pictures.
 */
class ServiceLedgerWriter
{
    /** Columns A–J, in the printed form's order: heading => width. */
    private const COLUMNS = [
        'No.' => 5,
        'Tgl.' => 11,
        'Nama / NIM / Instansi Mahasiswa' => 30,
        'Permintaan Layanan' => 24,
        'Proses Layanan' => 12,
        'Penyelesaian' => 16,
        'Tgl. Selesai' => 11,
        'Nama Mahasiswa atau yang Mewakili' => 20,
        'Paraf Mahasiswa atau yang Mewakili' => 18,
        'Nama Petugas' => 20,
    ];

    /** Column (0-based) the paraf pictures go in: I. */
    private const SIGNATURE_COLUMN = 8;

    /** Rows 7–8 are the header; data starts on row 9. */
    private const FIRST_DATA_ROW = 9;

    /** Data row height in points, tall enough for a paraf. */
    private const ROW_HEIGHT_PT = 36;

    /** Style ids, see styles(). */
    private const S_TITLE = 1;
    private const S_LABEL = 2;
    private const S_VALUE = 3;
    private const S_CODE = 4;
    private const S_HEAD = 5;
    private const S_CELL = 6;
    private const S_CELL_CENTER = 7;

    /**
     * @param  array{unit: string, period: string, code: string, code_date: string}  $meta
     * @param  iterable<int, array{cells: array<int, string|int|null>, signature: ?string}>  $rows
     *         `cells` are the ten column values except the paraf (pass null
     *         there); `signature` is the absolute path of the PNG, if any.
     */
    public function write(string $path, array $meta, iterable $rows): string
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Tidak bisa menulis berkas: {$path}");
        }

        $sheetRows = '';
        $pictures = [];
        $r = self::FIRST_DATA_ROW;

        foreach ($rows as $row) {
            $cells = '';

            foreach (array_values($row['cells']) as $i => $value) {
                $style = in_array($i, [0, 1, 4, 6], true) ? self::S_CELL_CENTER : self::S_CELL;
                $cells .= $this->cell($i, $r, $value, $style);
            }

            $sheetRows .= '<row r="'.$r.'" ht="'.self::ROW_HEIGHT_PT.'" customHeight="1">'.$cells.'</row>';

            if ($row['signature'] && is_file($row['signature']) && ($size = @getimagesize($row['signature']))) {
                $pictures[] = ['row' => $r - 1, 'path' => $row['signature'], 'w' => $size[0], 'h' => $size[1]];
            }

            $r++;
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes($pictures !== []));
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($meta, $sheetRows, $pictures !== []));

        if ($pictures !== []) {
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $this->rels([
                'rId1' => ['http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing', '../drawings/drawing1.xml'],
            ]));

            $imageRels = [];

            foreach ($pictures as $n => $picture) {
                $zip->addFile($picture['path'], 'xl/media/paraf'.($n + 1).'.png');
                $imageRels['rId'.($n + 1)] = ['http://schemas.openxmlformats.org/officeDocument/2006/relationships/image', '../media/paraf'.($n + 1).'.png'];
            }

            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $this->rels($imageRels));
            $zip->addFromString('xl/drawings/drawing1.xml', $this->drawing($pictures));
        }

        $zip->close();

        return $path;
    }

    /* -----------------------------------------------------------------
     | Sheet
     * ----------------------------------------------------------------- */

    /** @param array{unit: string, period: string, code: string, code_date: string} $meta */
    private function sheet(array $meta, string $dataRows, bool $hasDrawing): string
    {
        $headings = array_keys(self::COLUMNS);

        $cols = '';
        foreach (array_values(self::COLUMNS) as $i => $width) {
            $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$width.'" customWidth="1"/>';
        }

        // Every cell of a bordered merge is written, or Excel draws the
        // border only around the first one.
        $rows = '<row r="1">'.$this->cell(8, 1, $meta['code'], self::S_CODE).$this->cell(9, 1, null, self::S_CODE).'</row>'
            .'<row r="2">'.$this->cell(8, 2, $meta['code_date'], self::S_CODE).$this->cell(9, 2, null, self::S_CODE).'</row>'
            .'<row r="3" ht="24" customHeight="1">'.$this->cell(0, 3, 'BUKU BESAR DAFTAR LAYANAN', self::S_TITLE).'</row>'
            .'<row r="4">'.$this->cell(2, 4, 'UNIT', self::S_LABEL).$this->underline(4, $meta['unit']).'</row>'
            .'<row r="5">'.$this->cell(2, 5, 'MASA REGISTRASI', self::S_LABEL).$this->underline(5, $meta['period']).'</row>';

        $top = $bottom = '';
        foreach ($headings as $i => $heading) {
            // A–E span both header rows; F–J sit under "Proses Penyelesaian".
            $top .= $this->cell($i, 7, $i < 5 ? $heading : ($i === 5 ? 'Proses Penyelesaian' : null), self::S_HEAD);
            $bottom .= $this->cell($i, 8, $i < 5 ? null : $heading, self::S_HEAD);
        }
        $rows .= '<row r="7" ht="18" customHeight="1">'.$top.'</row>'
            .'<row r="8" ht="48" customHeight="1">'.$bottom.'</row>';

        $merges = ['I1:J1', 'I2:J2', 'A3:J3', 'D4:G4', 'D5:G5', 'A7:A8', 'B7:B8', 'C7:C8', 'D7:D8', 'E7:E8', 'F7:J7'];

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            .'<sheetViews><sheetView workbookViewId="0" showGridLines="0">'
            .'<pane ySplit="8" topLeftCell="A9" activePane="bottomLeft" state="frozen"/>'
            .'</sheetView></sheetViews>'
            .'<cols>'.$cols.'</cols>'
            .'<sheetData>'.$rows.$dataRows.'</sheetData>'
            .'<mergeCells count="'.count($merges).'">'
            .implode('', array_map(fn ($m) => '<mergeCell ref="'.$m.'"/>', $merges))
            .'</mergeCells>'
            .'<printOptions horizontalCentered="1"/>'
            .'<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            .'<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            .($hasDrawing ? '<drawing r:id="rId1"/>' : '')
            .'</worksheet>';
    }

    /** The "UNIT ____" / "MASA REGISTRASI ____" line: D–G, underlined. */
    private function underline(int $row, string $value): string
    {
        return $this->cell(3, $row, $value, self::S_VALUE)
            .$this->cell(4, $row, null, self::S_VALUE)
            .$this->cell(5, $row, null, self::S_VALUE)
            .$this->cell(6, $row, null, self::S_VALUE);
    }

    private function cell(int $col, int $row, string|int|null $value, int $style): string
    {
        $ref = XlsxWriter::columnLetter($col).$row;

        if ($value === null || $value === '') {
            return '<c r="'.$ref.'" s="'.$style.'"/>';
        }

        if (is_int($value)) {
            return '<c r="'.$ref.'" s="'.$style.'"><v>'.$value.'</v></c>';
        }

        return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$this->escape($value).'</t></is></c>';
    }

    private function escape(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /* -----------------------------------------------------------------
     | Paraf pictures
     * ----------------------------------------------------------------- */

    /** @param array<int, array{row: int, path: string, w: int, h: int}> $pictures */
    private function drawing(array $pictures): string
    {
        $emu = 9525; // per pixel
        // Column I is 18 characters ≈ 131 px; a 36 pt row is 48 px.
        [$cellW, $cellH] = [131, 48];
        [$maxW, $maxH] = [120, 42];

        $anchors = '';

        foreach ($pictures as $n => $p) {
            $scale = min($maxW / max(1, $p['w']), $maxH / max(1, $p['h']), 1);
            $w = (int) round($p['w'] * $scale);
            $h = (int) round($p['h'] * $scale);
            $cx = $w * $emu;
            $cy = $h * $emu;

            $anchors .= '<xdr:oneCellAnchor>'
                .'<xdr:from><xdr:col>'.self::SIGNATURE_COLUMN.'</xdr:col><xdr:colOff>'.(int) (($cellW - $w) / 2 * $emu).'</xdr:colOff>'
                .'<xdr:row>'.$p['row'].'</xdr:row><xdr:rowOff>'.(int) (($cellH - $h) / 2 * $emu).'</xdr:rowOff></xdr:from>'
                .'<xdr:ext cx="'.$cx.'" cy="'.$cy.'"/>'
                .'<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="'.($n + 2).'" name="Paraf '.($n + 1).'"/>'
                .'<xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
                .'<xdr:blipFill><a:blip r:embed="rId'.($n + 1).'"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                .'<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm>'
                .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>'
                .'<xdr:clientData/></xdr:oneCellAnchor>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" '
            .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .$anchors.'</xdr:wsDr>';
    }

    /* -----------------------------------------------------------------
     | Package parts
     * ----------------------------------------------------------------- */

    private function contentTypes(bool $hasDrawing): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Default Extension="png" ContentType="image/png"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .($hasDrawing ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '')
            .'</Types>';
    }

    private function rootRels(): string
    {
        return $this->rels([
            'rId1' => ['http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument', 'xl/workbook.xml'],
        ]);
    }

    private function workbook(): string
    {
        // Rows 7–8 repeat on every printed page, like the paper form.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Buku Besar" sheetId="1" r:id="rId1"/></sheets>'
            .'<definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">\'Buku Besar\'!$7:$8</definedName></definedNames>'
            .'</workbook>';
    }

    private function workbookRels(): string
    {
        return $this->rels([
            'rId1' => ['http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet', 'worksheets/sheet1.xml'],
            'rId2' => ['http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles', 'styles.xml'],
        ]);
    }

    /** @param array<string, array{0: string, 1: string}> $relations id => [type, target] */
    private function rels(array $relations): string
    {
        $xml = '';
        foreach ($relations as $id => [$type, $target]) {
            $xml .= '<Relationship Id="'.$id.'" Type="'.$type.'" Target="'.$target.'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$xml.'</Relationships>';
    }

    /**
     * 0 default · 1 title · 2 label · 3 underlined value · 4 code box ·
     * 5 header · 6 data cell · 7 data cell, centred.
     */
    private function styles(): string
    {
        $center = '<alignment horizontal="center" vertical="center" wrapText="1"/>';
        $left = '<alignment vertical="center" wrapText="1"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="3">'
            .'<font><sz val="10"/><name val="Arial"/></font>'
            .'<font><b/><sz val="10"/><name val="Arial"/></font>'
            .'<font><b/><sz val="14"/><name val="Arial"/></font>'
            .'</fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="3">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border>'
            .'<border><left/><right/><top/><bottom style="dotted"/><diagonal/></border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="8">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1">'.$center.'</xf>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="2" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1">'.$center.'</xf>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'.$center.'</xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'.$left.'</xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'.$center.'</xf>'
            .'</cellXfs>'
            .'</styleSheet>';
    }
}
