<?php

namespace App\Support\Spreadsheet;

use Generator;
use RuntimeException;

/**
 * Streaming CSV reader with the two habits Indonesian office exports demand:
 * a semicolon delimiter (what Excel writes under a comma-decimal locale) and a
 * UTF-8 BOM on the first cell.
 *
 * Both are sniffed rather than configured, because the person uploading the
 * file has no idea which one their Excel produced.
 */
class CsvReader
{
    private string $path;

    public function __construct(string $path)
    {
        if (! is_file($path)) {
            throw new RuntimeException("Berkas tidak ditemukan: {$path}");
        }

        $this->path = $path;
    }

    /** @return Generator<int, array<int, string>> */
    public function rows(): Generator
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Berkas CSV tidak bisa dibuka.');
        }

        $delimiter = $this->sniffDelimiter();
        $first = true;

        try {
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                // fgetcsv yields [null] for a blank line; skip it rather than
                // counting it as a failed row.
                if ($row === [null]) {
                    continue;
                }

                $row = array_map(fn ($value) => trim($this->utf8((string) $value)), $row);

                if ($first) {
                    // Strip the BOM so the first header does not read as
                    // "﻿nim" and fail to match.
                    $row[0] = preg_replace('/^\x{FEFF}/u', '', $row[0]) ?? $row[0];
                    $first = false;
                }

                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * "Save as CSV" in Windows Excel writes ANSI (Windows-1252), not UTF-8.
     * A name like "Agustinus Sinaga’s" or "Désa" then reaches MySQL as invalid
     * UTF-8 and the insert fails the whole import, so such cells are converted.
     */
    private function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    /**
     * Whichever of , ; or tab appears most often in the header line wins.
     */
    private function sniffDelimiter(): string
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            return ',';
        }

        $line = (string) fgets($handle, 8192);
        fclose($handle);

        $counts = [
            ',' => substr_count($line, ','),
            ';' => substr_count($line, ';'),
            "\t" => substr_count($line, "\t"),
        ];

        arsort($counts);

        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }
}
