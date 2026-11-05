<?php

namespace App\Domain\Import;

use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvReaderOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Options as CsvWriterOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Reads and writes spreadsheets for import and export: XLSX and CSV. CSV is written with a semicolon and a byte order mark, which
 * is what Excel in an Indonesian locale opens correctly; on import the delimiter (comma, semicolon or tab) is detected. Cells are
 * read as plain text so a value keeps the way it was typed (a date becomes Y-m-d, a whole number has no decimals).
 */
final class Tabular
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_ROWS = 5000;

    /**
     * @return array{format: string, headers: list<string>, rows: list<array{0: int, 1: list<string>}>} data rows with their sheet row number
     */
    public static function read(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            throw ValidationException::withMessages(['file' => 'Gunakan berkas .xlsx atau .csv.']);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Berkas melebihi 5 MB.']);
        }

        $reader = $extension === 'xlsx' ? new XlsxReader : new CsvReader(self::csvOptions($file->getRealPath()));
        $headers = [];
        $rows = [];
        try {
            $reader->open($file->getRealPath());
            foreach ($reader->getSheetIterator() as $sheet) {
                $number = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    $cells = array_map([self::class, 'text'], $row->toArray());
                    if ($number === 1) {
                        $headers = array_map(fn ($h) => preg_replace('/^\xEF\xBB\xBF/', '', $h), $cells);

                        continue;
                    }
                    if (implode('', $cells) === '') {
                        continue;
                    }
                    $rows[] = [$number, $cells];
                    if (count($rows) > self::MAX_ROWS) {
                        throw ValidationException::withMessages(['file' => 'Berkas melebihi '.self::MAX_ROWS.' baris data. Pecah menjadi beberapa berkas.']);
                    }
                }
                break; // only the first sheet carries data
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'Berkas tidak dapat dibaca. Pastikan itu berkas Excel (.xlsx) atau CSV yang valid.']);
        } finally {
            $reader->close();
        }
        if (array_filter($headers, fn ($h) => $h !== '') === []) {
            throw ValidationException::withMessages(['file' => 'Baris pertama harus berisi judul kolom.']);
        }

        return ['format' => $extension, 'headers' => $headers, 'rows' => $rows];
    }

    private static function csvOptions(string $path): CsvReaderOptions
    {
        $line = (string) strtok((string) file_get_contents($path, false, null, 0, 8192), "\n");
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($counts);
        $options = new CsvReaderOptions;
        $options->FIELD_DELIMITER = reset($counts) > 0 ? array_key_first($counts) : ',';
        $options->ENCODING = 'UTF-8';

        return $options;
    }

    /** One cell as the text a person typed. */
    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
            $value instanceof DateTimeInterface => $value->format('H:i:s') === '00:00:00' ? $value->format('Y-m-d') : $value->format('Y-m-d H:i'),
            default => trim((string) $value),
        };
    }

    /**
     * Writes a file and returns its path (the caller sends it and deletes it). $sheets are [name, headers, rows]; CSV carries the first.
     *
     * @param  list<array{0: string, 1: list<string>, 2: iterable<list<scalar|null>>}>  $sheets
     */
    public static function write(string $format, array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'optistash');
        if ($format === 'csv') {
            $options = new CsvWriterOptions;
            $options->FIELD_DELIMITER = ';';
            $options->SHOULD_ADD_BOM = true;
            $writer = new CsvWriter($options);
            $writer->openToFile($path);
            [, $headers, $rows] = $sheets[0];
            $writer->addRow(Row::fromValues($headers));
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
            $writer->close();

            return $path;
        }
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        foreach ($sheets as $index => [$name, $headers, $rows]) {
            if ($index > 0) {
                $writer->addNewSheetAndMakeItCurrent();
            }
            $writer->getCurrentSheet()->setName($name);
            $writer->addRow(Row::fromValues($headers));
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }
        $writer->close();

        return $path;
    }
}
