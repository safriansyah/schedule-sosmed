<?php

namespace App\Services\Datasets;

use Generator;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use JsonMachine\JsonDecoder\PassThruDecoder;
use RuntimeException;

/**
 * Memory-constant streaming reader for large JSON files (5k–100k+ rows).
 *
 * Handles both observed shapes:
 *   A) a top-level array            ->  [ {...}, {...} ]
 *   B) a wrapper object with a list ->  { "total_input": 1120, "data": [ ... ] }
 *
 * Never loads the whole document into memory — items are yielded one by one.
 */
class JsonDatasetReader
{
    /** Candidate keys that may hold the row array inside a wrapper object. */
    private const ARRAY_KEYS = ['data', 'items', 'results', 'records', 'rows'];

    /**
     * Stream normalised-ready raw rows.
     *
     * @return Generator<int,array>
     */
    public function rows(string $path): Generator
    {
        $pointer = $this->resolvePointer($path);

        $items = Items::fromFile($path, [
            'pointer' => $pointer,
            'decoder' => new ExtJsonDecoder(true),
        ]);

        foreach ($items as $row) {
            if (is_array($row)) {
                yield $row;
            }
        }
    }

    /**
     * Fast, low-allocation row count (uses PassThru so PHP structures are
     * never built). Preferred only when metadata gives no total.
     */
    public function count(string $path): int
    {
        $count = 0;
        $items = Items::fromFile($path, [
            'pointer' => $this->resolvePointer($path),
            'decoder' => new PassThruDecoder(),
        ]);

        foreach ($items as $ignored) {
            $count++;
        }

        return $count;
    }

    /**
     * Cheap top-of-file metadata extraction for wrapper objects
     * (total_input / total_checked / total_valid / generated_at ...).
     */
    public function metadata(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 8192);

        if (! str_starts_with(ltrim($head), '{')) {
            return [];
        }

        // Stop scanning once the big array key begins.
        $cut = PHP_INT_MAX;
        foreach (self::ARRAY_KEYS as $key) {
            if (preg_match('/"'.preg_quote($key, '/').'"\s*:\s*\[/', $head, $m, PREG_OFFSET_CAPTURE)) {
                $cut = min($cut, $m[0][1]);
            }
        }
        $scope = $cut === PHP_INT_MAX ? $head : substr($head, 0, $cut);

        $meta = [];
        if (preg_match_all('/"([a-zA-Z0-9_]+)"\s*:\s*("(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?|true|false|null)/', $scope, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $pair) {
                $meta[$pair[1]] = json_decode($pair[2], true);
            }
        }

        return $meta;
    }

    /**
     * Determine the JSON Pointer to the row array.
     */
    private function resolvePointer(string $path): string
    {
        $head = ltrim((string) file_get_contents($path, false, null, 0, 8192));

        if ($head === '') {
            throw new RuntimeException('The uploaded JSON file is empty.');
        }

        if ($head[0] === '[') {
            return ''; // root array
        }

        if ($head[0] === '{') {
            foreach (self::ARRAY_KEYS as $key) {
                if (preg_match('/"'.preg_quote($key, '/').'"\s*:\s*\[/', $head)) {
                    return '/'.$key;
                }
            }

            throw new RuntimeException(
                'Could not locate a data array in the JSON. Expected a top-level array '
                .'or an object containing one of: '.implode(', ', self::ARRAY_KEYS).'.'
            );
        }

        throw new RuntimeException('The file does not contain a JSON array or object.');
    }
}
