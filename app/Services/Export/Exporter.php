<?php

namespace App\Services\Export;

use App\Support\Spreadsheet\XlsxWriter;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a filtered query into a downloadable file.
 *
 * One class for all three formats so a module gets Excel, CSV and JSON by
 * describing its columns once. CSV and JSON stream — the response starts
 * before the query has finished, and memory stays flat on a 50.000-row export.
 * XLSX cannot stream (a zip's central directory is written last), so it is
 * built in the temp directory and sent with deleteFileAfterSend.
 *
 * Every export runs through the SAME scope the screen used, so what the admin
 * sees and what they download cannot differ.
 */
class Exporter
{
    public const FORMATS = ['xlsx', 'csv', 'json'];

    /**
     * @param  array<string, string>  $columns  heading => attribute path or closure key
     * @param  callable(object): array<int, mixed>  $mapper  row → values in column order
     */
    public function download(
        string $format,
        string $filename,
        array $columns,
        Builder $query,
        callable $mapper,
        int $chunk = 500,
    ): Response|StreamedResponse|BinaryFileResponse {
        $format = in_array($format, self::FORMATS, true) ? $format : 'csv';
        $headings = array_values($columns);
        $keys = array_keys($columns);

        return match ($format) {
            'json' => $this->json($filename, $keys, $query, $mapper, $chunk),
            'xlsx' => $this->xlsx($filename, $headings, $query, $mapper, $chunk),
            default => $this->csv($filename, $headings, $query, $mapper, $chunk),
        };
    }

    /* -----------------------------------------------------------------
     | Formats
     * ----------------------------------------------------------------- */

    private function csv(string $filename, array $headings, Builder $query, callable $mapper, int $chunk): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $query, $mapper, $chunk) {
            $out = fopen('php://output', 'wb');

            // BOM first: without it Excel on a Windows machine reads UTF-8 as
            // Latin-1 and every "Pangkalpinang" with an accent turns to mojibake.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headings, ',', '"', '\\');

            foreach ($this->rows($query, $mapper, $chunk) as $row) {
                fputcsv($out, array_map(fn ($v) => $this->scalar($v), $row), ',', '"', '\\');
            }

            fclose($out);
        }, $this->name($filename, 'csv'), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function json(string $filename, array $keys, Builder $query, callable $mapper, int $chunk): StreamedResponse
    {
        return response()->streamDownload(function () use ($keys, $query, $mapper, $chunk) {
            echo '[';
            $first = true;

            foreach ($this->rows($query, $mapper, $chunk) as $row) {
                echo $first ? '' : ',';
                $first = false;

                // Re-key positionally against the column keys so the JSON is
                // self-describing rather than an array of arrays.
                $object = [];

                foreach ($keys as $index => $key) {
                    $object[$key] = $this->scalar($row[$index] ?? null);
                }

                echo json_encode($object, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            echo ']';
        }, $this->name($filename, 'json'), [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    private function xlsx(string $filename, array $headings, Builder $query, callable $mapper, int $chunk): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'export_').'.xlsx';

        $rows = (function () use ($query, $mapper, $chunk): Generator {
            foreach ($this->rows($query, $mapper, $chunk) as $row) {
                yield array_map(fn ($v) => $this->scalar($v), $row);
            }
        })();

        (new XlsxWriter)->write($path, $headings, $rows);

        return response()
            ->download($path, $this->name($filename, 'xlsx'), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * Walks the query in keyset-paginated chunks, yielding as it goes.
     *
     * Hand-rolled rather than chunkById() because that takes a callback and so
     * cannot yield — collecting its results into an array first would hold the
     * entire export in memory, which is the one thing this class exists to
     * avoid.
     *
     * Keyset (`id > last`), not OFFSET: an operator editing rows while a long
     * export runs shifts OFFSET pages underneath it, and rows get skipped.
     *
     * @return Generator<int, array<int, mixed>>
     */
    private function rows(Builder $query, callable $mapper, int $chunk): Generator
    {
        $model = $query->getModel();
        $key = $model->getQualifiedKeyName();
        $lastId = 0;

        while (true) {
            $page = (clone $query)
                ->reorder()
                ->where($key, '>', $lastId)
                ->orderBy($key)
                ->limit($chunk)
                ->get();

            if ($page->isEmpty()) {
                return;
            }

            foreach ($page as $row) {
                yield $mapper($row);
            }

            $lastId = $page->last()->getKey();
        }
    }

    /** Enums, dates and nulls all have to come out as something printable. */
    private function scalar(mixed $value): string|int|float
    {
        return match (true) {
            $value === null => '',
            $value instanceof \BackedEnum => method_exists($value, 'label') ? $value->label() : $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i'),
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            is_scalar($value) => $value,
            default => (string) $value,
        };
    }

    private function name(string $base, string $extension): string
    {
        return sprintf('%s-%s.%s', $base, now()->format('Ymd-His'), $extension);
    }
}
