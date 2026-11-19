<?php

namespace App\Services;

use App\Support\FixedPoint;
use InvalidArgumentException;

class HospitalPriceFiles
{
    public const FILES = [
        'accommodation' => 'Accommodation - (Upload).csv',
        'investigation' => 'Investigation.csv',
        'medication' => 'Medication.csv',
        'imaging' => 'Medical Imaging (Scan).csv',
        'xray' => 'X-Ray.csv',
        'procedure' => 'Procedure.csv',
        'registration' => 'Registration.csv',
        'consultation' => 'Consultation Type.csv',
        'feeding' => 'Feeding Upload.csv',
    ];

    /** @return array{hash: string, sources: array, rows: array} */
    public function read(string $directory): array
    {
        $sources = $rows = [];
        foreach (self::FILES as $kind => $filename) {
            $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$filename;
            if (! is_file($path) || ! is_readable($path) || filesize($path) > 1048576) {
                throw new InvalidArgumentException('Each of the nine named CSV files must be readable and at most 1 MiB.');
            }
            $bytes = file_get_contents($path);
            if ($bytes === false || strlen($bytes) > 1048576) {
                throw new InvalidArgumentException('Cannot read the complete price file.');
            }
            $encoding = mb_check_encoding($bytes, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
            $sources[$filename] = ['sha256' => hash('sha256', $bytes), 'encoding' => $encoding, 'base64' => base64_encode($bytes)];
            $text = preg_replace('/\A\xEF\xBB\xBF/', '', mb_convert_encoding($bytes, 'UTF-8', $encoding));
            $stream = fopen('php://temp', 'w+');
            try {
                fwrite($stream, $text);
                rewind($stream);
                $group = '';
                $line = $count = 0;
                while (! feof($stream)) {
                    $offset = ftell($stream);
                    $cells = fgetcsv($stream, escape: '');
                    if ($cells === false) {
                        break;
                    }
                    $recordLine = $line + 1;
                    $line += max(1, substr_count(substr($text, $offset, ftell($stream) - $offset), "\n"));
                    $cells = array_map(fn ($cell): string => trim((string) $cell), $cells);
                    while ($cells !== [] && end($cells) === '') {
                        array_pop($cells);
                    }
                    if ($cells === []) {
                        continue;
                    }
                    if ($kind === 'accommodation' && $recordLine === 1 && $cells === ['Accommodation', 'Bed Name', 'Amount']) {
                        continue;
                    }
                    if ($kind !== 'accommodation' && count($cells) === 1 && $cells[0] !== '') {
                        $group = $cells[0];
                        continue;
                    }
                    $wide = in_array($kind, ['investigation', 'medication', 'imaging', 'xray', 'procedure'], true);
                    $name = preg_replace('/\s+/u', ' ', $cells[$wide || $kind === 'accommodation' ? 1 : 0] ?? '');
                    $price = $cells[$wide || $kind === 'accommodation' ? 2 : 1] ?? '';
                    $rowGroup = $kind === 'accommodation' ? ($cells[0] ?? '') : $group;
                    $issues = [];
                    if ($name === '' || mb_strlen($name) > 191 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
                        $issues[] = 'invalid_name';
                    }
                    if (count($cells) > ($wide || $kind === 'accommodation' ? 3 : 2) || ($wide && ($cells[0] ?? '') !== '')) {
                        $issues[] = 'unexpected_columns';
                    }
                    $amount = null;
                    try {
                        if (! preg_match('/\A(?:0|[1-9][0-9]*|[1-9][0-9]{0,2}(?:,[0-9]{3})+)(?:\.[0-9]{1,6})?\z/D', $price)) {
                            throw new InvalidArgumentException;
                        }
                        $amount = FixedPoint::parse(str_replace(',', '', $price));
                        if ($kind === 'accommodation' && ($amount % 10000 !== 0 || $rowGroup === '' || mb_strlen($rowGroup) > 191 || mb_strlen($name) > 64 || preg_match('/[\x00-\x1F\x7F]/u', $rowGroup))) {
                            $issues[] = 'invalid_bed_rate_or_identity';
                        }
                    } catch (InvalidArgumentException) {
                        $issues[] = 'invalid_amount';
                    }
                    $rows[] = [
                        'source_kind' => $kind, 'source_file' => $filename, 'source_line' => $recordLine,
                        'group_name' => $rowGroup, 'label' => $name, 'amount_micros' => $amount,
                        'currency' => 'NGN', 'basis' => $kind === 'accommodation' ? 'bed_day' : ($kind === 'medication' ? 'unconfirmed' : 'listed_item'),
                        'item_key' => hash('sha256', $kind.'|'.($kind === 'accommodation' ? $this->normalize($rowGroup).'|' : '').$this->normalize($name)),
                        'status' => $issues !== [] ? 'invalid' : ($kind === 'medication' ? 'pending_units' : 'ready'),
                        'issues' => $issues, 'raw_cells' => $cells, 'duplicate_line' => null,
                    ];
                    if (++$count > 10000) {
                        throw new InvalidArgumentException('A price file exceeds the 10,000-row import limit.');
                    }
                }
                if ($count === 0) {
                    throw new InvalidArgumentException('Every supplied file must contain priced rows.');
                }
            } finally {
                fclose($stream);
            }
        }
        $groups = [];
        foreach ($rows as $index => $row) {
            if ($row['status'] !== 'invalid') {
                $groups[$row['item_key']][] = $index;
            }
        }
        foreach ($groups as $indices) {
            $amounts = array_unique(array_map(fn (int $index): int => $rows[$index]['amount_micros'], $indices));
            foreach ($indices as $position => $index) {
                if (count($amounts) > 1) {
                    $rows[$index]['issues'][] = 'conflicting_prices';
                    if ($rows[$index]['source_kind'] !== 'medication') {
                        $rows[$index]['status'] = 'conflict';
                    }
                } elseif ($position > 0 && $rows[$index]['source_kind'] !== 'medication') {
                    $rows[$index]['status'] = 'duplicate';
                    $rows[$index]['duplicate_line'] = $rows[$indices[0]]['source_line'];
                }
            }
        }

        return ['hash' => hash('sha256', json_encode(['format' => 1, 'currency' => 'NGN', 'sources' => array_map(fn (array $source): string => $source['sha256'], $sources)], JSON_THROW_ON_ERROR)), 'sources' => $sources, 'rows' => $rows];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)), 'UTF-8');
    }
}
