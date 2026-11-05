<?php

namespace App\Domain\Master;

use App\Domain\Import\Tabular;
use App\Domain\Shared\TenantStore;
use App\Domain\Tariff\TariffService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Spreadsheet import and export of the master data. An upload becomes a staged job: each row is converted the way people type it
 * in Excel, validated with the same rules as the form, and given the action it would cause (CREATE, UPDATE when a record with the
 * code exists and a value changes, NONE when nothing changes, ERROR). Nothing is written until the job is committed, and
 * committing the same job again changes nothing. A reference column holds the CODE of the referenced master.
 *
 * On an update a blank cell keeps the value the record has; to clear a value use the form.
 */
final class MasterImporter
{
    public function __construct(private readonly TenantStore $store, private readonly MasterService $service, private readonly ?TariffService $tariff = null) {}

    private static function key(string $text): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($text));
    }

    /** The header a person sees for a field (a reference column holds the code of the referenced master). */
    public static function header(array $field): string
    {
        return $field['kind'] === 'ref' ? $field['label'].' (kode)' : $field['label'];
    }

    // ---------------------------------------------------------------- template and export

    /** @return array{0: string, 1: string} path of the written file and its download name */
    public function template(string $resource, string $format): array
    {
        $def = MasterRegistry::find($resource) ?? abort(404);
        $fields = $def['fields'];
        $headers = array_map([self::class, 'header'], $fields);
        $guide = array_map(fn (array $f) => [self::header($f), ($f['required'] ?? false) ? 'Ya' : 'Tidak', $this->describe($f), $this->allowed($f)], $fields);
        $sheets = [['Data', $headers, []]];
        if ($format === 'xlsx') {
            $sheets[] = ['Petunjuk', ['Kolom', 'Wajib', 'Format', 'Nilai yang diizinkan'], $guide];
        }

        return [Tabular::write($format, $sheets), "template-{$resource}.{$format}"];
    }

    private function describe(array $f): string
    {
        $text = match ($f['kind']) {
            'number' => 'Bilangan bulat'.(isset($f['min'], $f['max']) ? " {$f['min']}–{$f['max']}" : ''),
            'decimal' => "Angka desimal {$f['min']}–{$f['max']} (titik atau koma)",
            'date' => 'Tanggal YYYY-MM-DD (DD/MM/YYYY juga diterima)',
            'boolean' => 'Ya atau Tidak',
            'select' => 'Pilih salah satu nilai',
            'ref' => 'Kode master '.(MasterRegistry::find($f['ref'])['label'] ?? $f['ref']).' yang sudah ada dan aktif',
            default => 'Teks'.(isset($f['max']) ? ", maks {$f['max']} karakter" : ''),
        };

        return trim($text.'. '.($f['hint'] ?? ''));
    }

    private function allowed(array $f): string
    {
        return match ($f['kind']) {
            'select' => implode('; ', array_map(fn ($k, $v) => "{$k} ({$v})", array_keys($f['options']), $f['options'])),
            'boolean' => 'Ya / Tidak',
            default => '',
        };
    }

    /** @return array{0: string, 1: string} */
    public function export(string $resource, string $format, ?string $search, ?string $active): array
    {
        $def = MasterRegistry::find($resource) ?? abort(404);
        $query = $this->store->query($resource);
        if ($search !== null && $search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($search)).'%';
            $query->where(function ($q) use ($def, $like) {
                foreach ($def['search'] as $column) {
                    $q->orWhereRaw("lower({$column}::text) like ?", [$like]);
                }
            });
        }
        if ($active === '1' || $active === '0') {
            $query->where('active', $active === '1');
        }
        $codes = [];
        foreach ($def['fields'] as $f) {
            if ($f['kind'] === 'ref') {
                $codes[$f['name']] = $this->store->query($f['ref'])->pluck('code', 'id')->all();
            }
        }
        $rows = (function () use ($query, $def, $codes) {
            foreach ($query->orderBy('name')->orderBy('code')->lazyById(500, 'id') as $row) {
                $line = [];
                foreach ($def['fields'] as $f) {
                    $value = $row->{$f['name']};
                    $line[] = match (true) {
                        $value === null => '',
                        $f['kind'] === 'ref' => $codes[$f['name']][$value] ?? '',
                        $f['kind'] === 'boolean' => $value ? 'Ya' : 'Tidak',
                        $f['kind'] === 'select' => $value,
                        $f['kind'] === 'decimal' => rtrim(rtrim((string) $value, '0'), '.'),
                        default => (string) $value,
                    };
                }
                $line[] = $row->active ? 'Aktif' : 'Nonaktif';

                yield $line;
            }
        })();
        $headers = array_merge(array_map([self::class, 'header'], $def['fields']), ['Status']);

        return [Tabular::write($format, [[$def['label'], $headers, $rows]]), "{$resource}-".now()->format('Ymd-His').".{$format}"];
    }

    // ---------------------------------------------------------------- preview

    /** @return array<int, string> column index => field name, plus the columns that were ignored */
    private function mapHeaders(string $resource, array $headers): array
    {
        $fields = MasterRegistry::fieldsOf($resource);
        $lookup = [];
        foreach ($fields as $f) {
            $lookup[self::key($f['name'])] = $f['name'];
            $lookup[self::key($f['label'])] = $f['name'];
            $lookup[self::key(self::header($f))] = $f['name'];
        }
        $map = [];
        $ignored = [];
        foreach ($headers as $index => $header) {
            if (trim($header) === '') {
                continue;
            }
            $name = $lookup[self::key($header)] ?? null;
            if ($name === null) {
                $ignored[] = $header;

                continue;
            }
            if (in_array($name, $map, true)) {
                throw ValidationException::withMessages(['file' => "Kolom \"{$header}\" muncul lebih dari sekali."]);
            }
            $map[$index] = $name;
        }
        $missing = array_filter($fields, fn ($f) => ($f['required'] ?? false) && ! in_array($f['name'], $map, true));
        if ($missing !== []) {
            throw ValidationException::withMessages(['file' => 'Kolom wajib tidak ditemukan: '.implode(', ', array_map([self::class, 'header'], $missing)).'. Unduh template untuk judul kolom yang benar.']);
        }

        return [$map, $ignored];
    }

    /**
     * Stages an uploaded file.
     *
     * @return array<string, mixed> the job with its counts
     */
    public function preview(string $resource, UploadedFile $file): array
    {
        MasterRegistry::find($resource) ?? abort(404);
        $table = Tabular::read($file);
        [$map, $ignored] = $this->mapHeaders($resource, $table['headers']);
        if ($table['rows'] === []) {
            throw ValidationException::withMessages(['file' => 'Berkas tidak berisi baris data.']);
        }
        $fields = MasterRegistry::fieldsOf($resource);

        $refs = [];
        foreach ($fields as $f) {
            if ($f['kind'] === 'ref') {
                $refs[$f['name']] = $this->store->query($f['ref'])->get(['id', 'code', 'active'])->mapWithKeys(fn ($r) => [mb_strtoupper($r->code) => $r])->all();
            }
        }
        $byField = fn (array $cells) => collect($map)->mapWithKeys(fn ($name, $i) => [$name => ($cells[$i] ?? '') === '' ? null : $cells[$i]])->all();
        $codes = array_values(array_unique(array_filter(array_map(fn ($r) => ($c = $byField($r[1])['code'] ?? null) === null ? null : mb_strtoupper($c), $table['rows']))));
        $existing = [];
        foreach (array_chunk($codes, 1000) as $chunk) {
            foreach ($this->store->query($resource)->whereIn('code', $chunk)->get() as $row) {
                $existing[mb_strtoupper($row->code)] = $row;
            }
        }

        $seenCodes = [];
        $seenUnique = [];
        $records = [];
        $counts = ['CREATE' => 0, 'UPDATE' => 0, 'NONE' => 0, 'ERROR' => 0];
        foreach ($table['rows'] as [$sheetRow, $cells]) {
            $raw = $byField($cells);
            $code = isset($raw['code']) ? mb_strtoupper($raw['code']) : null;
            $errors = [];
            if ($code === null && collect(MasterRegistry::fieldsOf($resource))->contains(fn ($f) => $f['name'] === 'code' && ($f['generated'] ?? false))) {
                // Without a code a row cannot be matched to the record it was imported before: a second import would add every row again.
                $errors['code'][] = 'Kode wajib diisi pada impor, agar impor ulang memperbarui data yang sama, bukan menggandakannya.';
            }
            if ($code !== null) {
                if (isset($seenCodes[$code])) {
                    $errors['code'][] = "Kode sama dengan baris {$seenCodes[$code]}.";
                }
                $seenCodes[$code] ??= $sheetRow;
            }
            foreach ($fields as $f) {
                $value = $raw[$f['name']] ?? null;
                if (($f['unique'] ?? false) && $value !== null) {
                    $k = mb_strtolower($value);
                    if (isset($seenUnique[$f['name']][$k]) && $seenUnique[$f['name']][$k] !== $code) {
                        $errors[$f['name']][] = "{$f['label']} sama dengan baris {$seenUnique[$f['name']][$k]}.";
                    }
                    $seenUnique[$f['name']][$k] ??= $sheetRow;
                }
            }
            [$action, $data, $rowErrors, $target] = $this->analyze($resource, $raw, $code !== null ? ($existing[$code] ?? null) : null, $refs);
            $errors = array_merge_recursive($errors, $rowErrors);
            if ($errors !== []) {
                $action = 'ERROR';
                $data = null;
            }
            $counts[$action]++;
            $records[] = [
                'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'row_number' => $sheetRow, 'action' => $action, 'target_id' => $action === 'UPDATE' ? $target : null,
                'raw' => json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'data' => $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR),
                'errors' => $errors === [] ? null : json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ];
        }

        return $this->stage($resource, $file, $table, ['map' => $map, 'ignored' => $ignored], $counts, $records);
    }

    /**
     * Writes a staged job with its rows; $meta is kept with the job (column map, ignored columns, anything the commit needs).
     *
     * @param  array{format: string, headers: list<string>}  $table
     * @return array<string, mixed>
     */
    public function stage(string $resource, UploadedFile $file, array $table, array $meta, array $counts, array $records): array
    {
        $hash = hash_file('sha256', $file->getRealPath());

        return DB::transaction(function () use ($resource, $file, $table, $meta, $hash, $counts, $records) {
            $jobId = (string) Str::uuid();
            DB::table('import_jobs')->insert([
                'id' => $jobId, 'tenant_id' => $this->store->tenantId, 'resource' => $resource, 'filename' => mb_substr($file->getClientOriginalName(), 0, 255), 'format' => $table['format'],
                'file_hash' => $hash, 'status' => 'PREVIEW', 'total_rows' => count($records), 'rows_new' => $counts['CREATE'], 'rows_update' => $counts['UPDATE'],
                'rows_unchanged' => $counts['NONE'], 'rows_error' => $counts['ERROR'],
                'headers' => json_encode(['headers' => $table['headers']] + $meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('import_rows')->insert(array_map(fn ($r) => $r + ['job_id' => $jobId], $chunk));
            }
            $this->store->event('master.import.previewed', $jobId, ['resource' => $resource, 'file' => $file->getClientOriginalName(), 'rows' => count($records)] + $counts);

            return $this->job($jobId);
        });
    }

    /**
     * One row: what it would do, the clean values to write, its errors, and the record it updates.
     *
     * @param  array<string, string|null>  $raw  the cells by field name (null = blank)
     * @return array{0: string, 1: ?array<string, mixed>, 2: array<string, list<string>>, 3: ?string}
     */
    private function analyze(string $resource, array $raw, ?object $existing, array $refs): array
    {
        $errors = [];
        $input = [];
        foreach (MasterRegistry::fieldsOf($resource) as $f) {
            $cell = $raw[$f['name']] ?? null;
            if ($cell === null) {
                continue;
            }
            if ($f['kind'] === 'ref') {
                $hit = $refs[$f['name']][mb_strtoupper($cell)] ?? null;
                if ($hit === null) {
                    $errors[$f['name']][] = 'Kode "'.$cell.'" tidak ditemukan di master '.(MasterRegistry::find($f['ref'])['label'] ?? $f['ref']).'.';

                    continue;
                }
                if (! $hit->active && ($existing === null || $existing->{$f['name']} !== $hit->id)) {
                    $errors[$f['name']][] = 'Kode "'.$cell.'" tidak aktif.';

                    continue;
                }
                $input[$f['name']] = $hit->id;

                continue;
            }
            $input[$f['name']] = $this->convert($f, $cell);
        }
        if ($errors !== []) {
            return ['ERROR', null, $errors, null];
        }

        if ($existing === null) {
            [$clean, $errors] = $this->service->check($resource, $input, true);

            return $errors === [] ? ['CREATE', $clean, [], null] : ['ERROR', null, $errors, null];
        }

        // An update starts from what the record has; the cells that are filled in overlay it.
        $merged = [];
        foreach (MasterRegistry::fieldsOf($resource) as $f) {
            if ($f['immutable'] ?? false) {
                $given = $input[$f['name']] ?? null;
                if ($given !== null && ($f['upper'] ?? false)) {
                    $given = mb_strtoupper($given);
                }
                if ($given !== null && ! $this->same($f, $existing->{$f['name']}, $given)) {
                    $errors[$f['name']][] = "{$f['label']} tidak dapat diubah setelah dibuat.";
                }

                continue;
            }
            $merged[$f['name']] = array_key_exists($f['name'], $input) ? $input[$f['name']] : $this->asInput($f, $existing->{$f['name']});
        }
        if ($errors !== []) {
            return ['ERROR', null, $errors, null];
        }
        [$clean, $errors] = $this->service->check($resource, $merged, false, $existing->id);
        if ($errors !== []) {
            return ['ERROR', null, $errors, null];
        }
        foreach (MasterRegistry::fieldsOf($resource) as $f) {
            if (array_key_exists($f['name'], $clean) && ! $this->same($f, $existing->{$f['name']}, $clean[$f['name']])) {
                return ['UPDATE', $clean, [], $existing->id];
            }
        }

        return ['NONE', $clean, [], $existing->id];
    }

    /** A stored value as the form would send it. */
    private function asInput(array $f, mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            $f['kind'] === 'boolean' => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    private function same(array $f, mixed $a, mixed $b): bool
    {
        if (($a === null || $a === '') && ($b === null || $b === '')) {
            return true;
        }

        return match ($f['kind']) {
            'decimal' => abs((float) $a - (float) $b) < 1e-9,
            'boolean' => (bool) $a === (bool) $b,
            'number' => (int) $a === (int) $b,
            default => (string) $a === (string) $b,
        };
    }

    /** What a person typed in Excel, as the value the form would send. */
    private function convert(array $f, string $cell): mixed
    {
        $lower = mb_strtolower($cell);

        return match ($f['kind']) {
            'select' => (function () use ($f, $lower, $cell) {
                foreach ($f['options'] as $key => $label) {
                    if (mb_strtolower($key) === $lower || mb_strtolower($label) === $lower) {
                        return $key;
                    }
                }

                return $cell;
            })(),
            'boolean' => in_array($lower, ['1', 'ya', 'y', 'true', 'yes', 'benar'], true) ? '1' : (in_array($lower, ['0', 'tidak', 't', 'n', 'no', 'false', 'salah'], true) ? '0' : $cell),
            'date' => preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $cell, $m) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : $cell,
            'number' => preg_match('/^\d{1,3}(\.\d{3})+$/', $cell) ? str_replace('.', '', $cell) : str_replace(' ', '', $cell),
            'decimal' => str_replace([' ', ','], ['', '.'], $cell),
            default => $cell,
        };
    }

    // ---------------------------------------------------------------- reading a job

    public function job(string $id): array
    {
        $job = $this->store->query('import_jobs')->where('id', $id)->first() ?? abort(404, 'Impor tidak ditemukan.');
        $headers = json_decode($job->headers, true);
        $duplicate = $this->store->query('import_jobs')->where('resource', $job->resource)->where('file_hash', $job->file_hash)->where('status', 'COMMITTED')->where('id', '!=', $id)->orderByDesc('committed_at')->first(['id', 'committed_at']);

        return [
            'id' => $job->id, 'resource' => $job->resource, 'filename' => $job->filename, 'format' => $job->format, 'status' => $job->status,
            'total_rows' => $job->total_rows, 'rows_new' => $job->rows_new, 'rows_update' => $job->rows_update, 'rows_unchanged' => $job->rows_unchanged, 'rows_error' => $job->rows_error,
            'ignored_columns' => $headers['ignored'] ?? [], 'result' => $job->result ? json_decode($job->result, true) : null, 'committed_at' => $job->committed_at,
            'already_imported_at' => $duplicate?->committed_at, 'created_at' => $job->created_at,
        ];
    }

    /** @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int} */
    public function rows(string $id, ?string $action, int $page, int $perPage = 100): array
    {
        $this->job($id);
        $query = DB::table('import_rows')->where('tenant_id', $this->store->tenantId)->where('job_id', $id);
        if (in_array($action, ['CREATE', 'UPDATE', 'NONE', 'ERROR'], true)) {
            $query->where('action', $action);
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('row_number')->forPage($page, $perPage)->get()->map(function ($r) {
            $raw = json_decode($r->raw, true);

            return ['row' => $r->row_number, 'action' => $r->action, 'code' => $raw['code'] ?? null, 'name' => $raw['name'] ?? null, 'errors' => $r->errors ? json_decode($r->errors, true) : null];
        })->all();

        return ['data' => $rows, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total];
    }

    /** The rejected rows, as the original file layout plus the reason, so they can be corrected and uploaded again. */
    public function rejected(string $id, string $format): array
    {
        $job = $this->store->query('import_jobs')->where('id', $id)->first() ?? abort(404, 'Impor tidak ditemukan.');
        $meta = json_decode($job->headers, true);
        $headers = $meta['headers'];
        $rows = DB::table('import_rows')->where('tenant_id', $this->store->tenantId)->where('job_id', $id)->where('action', 'ERROR')->orderBy('row_number')->get()->map(function ($r) use ($meta) {
            $raw = json_decode($r->raw, true);
            $line = [];
            foreach (array_keys($meta['headers']) as $i) {
                $line[] = isset($meta['map'][$i]) ? ($raw[$meta['map'][$i]] ?? '') : '';
            }
            $messages = [];
            foreach (json_decode($r->errors ?? '[]', true) as $field => $list) {
                $messages[] = implode(' ', $list);
            }
            $line[] = implode(' | ', $messages);

            return $line;
        })->all();

        return [Tabular::write($format, [['Baris gagal', array_merge($headers, ['Kesalahan']), $rows]]), "baris-gagal-{$job->resource}.{$format}"];
    }

    // ---------------------------------------------------------------- commit

    /** @return array<string, mixed> the job after it was applied; committing a committed job changes nothing */
    public function commit(string $id, bool $skipErrors): array
    {
        return DB::transaction(function () use ($id, $skipErrors) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $job = $this->store->query('import_jobs')->where('id', $id)->lockForUpdate()->first() ?? abort(404, 'Impor tidak ditemukan.');
            if ($job->status === 'COMMITTED') {
                return $this->job($id);
            }
            abort_unless($job->status === 'PREVIEW', 409, 'Impor ini sudah dibatalkan.');
            abort_if($job->rows_error > 0 && ! $skipErrors, 422, "Ada {$job->rows_error} baris yang gagal. Perbaiki berkasnya, atau pilih untuk melewati baris yang gagal.");

            $created = $updated = 0;
            $failed = [];
            $tableId = json_decode($job->headers, true)['table_id'] ?? null;
            foreach (DB::table('import_rows')->where('tenant_id', $this->store->tenantId)->where('job_id', $id)->whereIn('action', ['CREATE', 'UPDATE'])->orderBy('row_number')->cursor() as $row) {
                $data = json_decode($row->data, true);
                try {
                    $lines = $job->resource === 'rate_lines';
                    if ($row->action === 'CREATE') {
                        $lines ? $this->tariff->addLine($tableId, $data) : $this->service->create($job->resource, $data);
                        $created++;
                    } else {
                        $lines ? $this->tariff->updateLine($tableId, $row->target_id, $data) : $this->service->update($job->resource, $row->target_id, $data);
                        $updated++;
                    }
                } catch (ValidationException $e) {
                    $failed[] = ['row' => $row->row_number, 'errors' => $e->errors()];
                }
            }
            if ($failed !== [] && ! $skipErrors) {
                throw new HttpResponseException(response()->json(['message' => 'Data berubah sejak pratinjau; sebagian baris tidak lagi valid. Tidak ada yang diterapkan. Unggah ulang berkasnya.', 'rows' => array_slice($failed, 0, 50)], 409));
            }
            $result = ['created' => $created, 'updated' => $updated, 'unchanged' => $job->rows_unchanged, 'skipped_errors' => $job->rows_error + count($failed)];
            DB::table('import_jobs')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update([
                'status' => 'COMMITTED', 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'committed_by' => $this->store->actorId, 'committed_at' => now(), 'updated_at' => now(),
            ]);
            $this->store->event('master.import.committed', $id, ['resource' => $job->resource, 'file' => $job->filename] + $result);

            return $this->job($id);
        }, 3);
    }

    public function discard(string $id): array
    {
        return DB::transaction(function () use ($id) {
            $job = $this->store->query('import_jobs')->where('id', $id)->lockForUpdate()->first() ?? abort(404, 'Impor tidak ditemukan.');
            abort_if($job->status === 'COMMITTED', 409, 'Impor yang sudah diterapkan tidak dapat dibatalkan.');
            if ($job->status === 'PREVIEW') {
                DB::table('import_jobs')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'DISCARDED', 'updated_at' => now()]);
                DB::table('import_rows')->where('tenant_id', $this->store->tenantId)->where('job_id', $id)->delete();
            }

            return $this->job($id);
        });
    }
}
