<?php

namespace App\Domain\Master;

/**
 * The master data of the warehouse, described once. The same definition drives validation, the API, the audit
 * trail and the generic screen (`GET master/schema`), so adding a master or a field is a change here and a migration.
 *
 * Field keys: name, label, kind (text, textarea, number, decimal, select, boolean, date, ref), required, max, min,
 * options (value => label, for select), ref (resource name, for ref), list (shown in the table), immutable (cannot change after
 * creation), unique (unique per tenant besides the code), upper (stored upper case), digits (stored as digits only),
 * rules (extra Laravel rules), hint.
 */
final class MasterRegistry
{
    public const GROUPS = [
        'warehouse' => 'Gudang',
        'product' => 'Produk',
        'partner' => 'Mitra',
    ];

    private const CODE = ['name' => 'code', 'label' => 'Kode', 'kind' => 'text', 'required' => true, 'max' => 40, 'immutable' => true, 'upper' => true, 'list' => true,
        'rules' => ['regex:/^[A-Z0-9][A-Z0-9._\/-]*$/'], 'hint' => 'Huruf besar, angka, titik, garis miring, garis bawah atau strip. Tidak dapat diubah setelah dibuat.'];

    private const NAME = ['name' => 'name', 'label' => 'Nama', 'kind' => 'text', 'required' => true, 'max' => 200, 'list' => true];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'warehouses' => [
                'label' => 'Gudang', 'singular' => 'gudang', 'group' => 'warehouse', 'search' => ['code', 'name', 'city'],
                'fields' => [
                    self::CODE, self::NAME,
                    ['name' => 'city', 'label' => 'Kota', 'kind' => 'text', 'max' => 100, 'list' => true],
                    ['name' => 'address', 'label' => 'Alamat', 'kind' => 'textarea', 'max' => 1000],
                    ['name' => 'granularity', 'label' => 'Tingkat terdalam struktur penyimpanan', 'kind' => 'select', 'required' => true, 'default' => 'BIN', 'list' => true,
                        'options' => ['ZONE' => 'Zona', 'AISLE' => 'Lorong', 'RACK' => 'Rak', 'SHELF' => 'Tingkat', 'BIN' => 'Bin'],
                        'hint' => 'Batas kerincian lokasi gudang ini. Tidak dapat dipersempit bila sudah ada lokasi yang lebih dalam.'],
                ],
            ],
            'docks' => [
                'label' => 'Dock', 'singular' => 'dock', 'group' => 'warehouse', 'search' => ['code', 'name'],
                'fields' => [
                    self::CODE, self::NAME,
                    ['name' => 'warehouse_id', 'label' => 'Gudang', 'kind' => 'ref', 'ref' => 'warehouses', 'required' => true, 'immutable' => true, 'list' => true],
                ],
            ],
            'uoms' => [
                'label' => 'Satuan (UoM)', 'singular' => 'satuan', 'group' => 'product', 'search' => ['code', 'name'],
                'fields' => [self::CODE, self::NAME],
            ],
            'product_categories' => [
                'label' => 'Kategori produk', 'singular' => 'kategori', 'group' => 'product', 'search' => ['code', 'name'],
                'fields' => [self::CODE, self::NAME],
            ],
            'products' => [
                'label' => 'Produk (SKU)', 'singular' => 'produk', 'group' => 'product', 'search' => ['code', 'name', 'barcode'],
                'fields' => [
                    self::CODE, self::NAME,
                    ['name' => 'category_id', 'label' => 'Kategori', 'kind' => 'ref', 'ref' => 'product_categories', 'list' => true],
                    ['name' => 'base_uom_id', 'label' => 'Satuan dasar', 'kind' => 'ref', 'ref' => 'uoms', 'required' => true, 'immutable' => true, 'list' => true],
                    ['name' => 'barcode', 'label' => 'Barcode / GTIN', 'kind' => 'text', 'max' => 60, 'list' => true],
                    ['name' => 'rotation_policy', 'label' => 'Kebijakan rotasi', 'kind' => 'select', 'required' => true, 'default' => 'FIFO', 'list' => true,
                        'options' => ['FEFO' => 'FEFO (kedaluwarsa terdekat)', 'FIFO' => 'FIFO (masuk pertama)', 'LIFO' => 'LIFO (masuk terakhir)']],
                    ['name' => 'track_lot', 'label' => 'Lacak nomor lot', 'kind' => 'boolean', 'default' => true],
                    ['name' => 'track_expiry', 'label' => 'Lacak tanggal kedaluwarsa', 'kind' => 'boolean', 'default' => false, 'hint' => 'Wajib aktif untuk kebijakan FEFO.'],
                    ['name' => 'shelf_life_days', 'label' => 'Umur simpan (hari)', 'kind' => 'number', 'min' => 1, 'max' => 36500],
                    ['name' => 'min_remaining_shelf_life_days', 'label' => 'Sisa umur simpan minimum saat diterima (hari)', 'kind' => 'number', 'min' => 1, 'max' => 36500],
                    ['name' => 'temperature_class', 'label' => 'Kelas suhu', 'kind' => 'select', 'required' => true, 'default' => 'AMBIENT',
                        'options' => ['AMBIENT' => 'Suhu ruang', 'CHILLED' => 'Dingin', 'FROZEN' => 'Beku']],
                    ['name' => 'hazmat', 'label' => 'Bahan berbahaya', 'kind' => 'boolean', 'default' => false],
                    ['name' => 'unit_cost', 'label' => 'Harga pokok per satuan dasar (Rp)', 'kind' => 'number', 'min' => 0, 'max' => 1000000000, 'hint' => 'Dipakai analisis ABC, HML, dan EOQ.'],
                    ['name' => 'criticality', 'label' => 'Kritikalitas (VED)', 'kind' => 'select', 'required' => true, 'default' => 'D', 'options' => ['V' => 'Vital', 'E' => 'Esensial', 'D' => 'Diinginkan']],
                    ['name' => 'lead_time_days', 'label' => 'Lead time pasokan (hari)', 'kind' => 'number', 'min' => 0, 'max' => 365, 'hint' => 'Dipakai klasifikasi SDE.'],
                    ['name' => 'weight_g', 'label' => 'Berat (gram)', 'kind' => 'number', 'min' => 1, 'max' => 100000000],
                    ['name' => 'length_mm', 'label' => 'Panjang (mm)', 'kind' => 'number', 'min' => 1, 'max' => 20000],
                    ['name' => 'width_mm', 'label' => 'Lebar (mm)', 'kind' => 'number', 'min' => 1, 'max' => 20000],
                    ['name' => 'height_mm', 'label' => 'Tinggi (mm)', 'kind' => 'number', 'min' => 1, 'max' => 20000],
                ],
            ],
            'product_packaging' => [
                'label' => 'Kemasan produk', 'singular' => 'kemasan', 'group' => 'product', 'search' => ['code', 'name', 'barcode'],
                'fields' => [
                    self::CODE, self::NAME,
                    ['name' => 'product_id', 'label' => 'Produk', 'kind' => 'ref', 'ref' => 'products', 'required' => true, 'immutable' => true, 'list' => true],
                    ['name' => 'uom_id', 'label' => 'Satuan kemasan', 'kind' => 'ref', 'ref' => 'uoms', 'required' => true, 'list' => true],
                    ['name' => 'base_quantity', 'label' => 'Isi (satuan dasar)', 'kind' => 'number', 'required' => true, 'min' => 1, 'max' => 1000000, 'list' => true,
                        'hint' => 'Berapa satuan dasar produk dalam satu kemasan ini, misalnya 1 karton = 12.'],
                    ['name' => 'barcode', 'label' => 'Barcode / GTIN kemasan', 'kind' => 'text', 'max' => 60],
                ],
            ],
            'partners' => [
                'label' => 'Mitra (pemasok, pelanggan, ekspedisi)', 'singular' => 'mitra', 'group' => 'partner', 'search' => ['code', 'name', 'email'],
                'fields' => [
                    self::CODE, self::NAME,
                    ['name' => 'type', 'label' => 'Jenis', 'kind' => 'select', 'required' => true, 'immutable' => true, 'list' => true,
                        'options' => ['SUPPLIER' => 'Pemasok', 'CUSTOMER' => 'Pelanggan', 'CARRIER' => 'Ekspedisi']],
                    ['name' => 'contact_name', 'label' => 'Kontak', 'kind' => 'text', 'max' => 150],
                    ['name' => 'email', 'label' => 'Email', 'kind' => 'text', 'max' => 254, 'rules' => ['email']],
                    ['name' => 'phone', 'label' => 'Telepon', 'kind' => 'text', 'max' => 40],
                    ['name' => 'address', 'label' => 'Alamat', 'kind' => 'textarea', 'max' => 1000],
                    ['name' => 'tax_id', 'label' => 'NPWP', 'kind' => 'text', 'max' => 32, 'digits' => true, 'rules' => ['regex:/^\d{15,16}$/']],
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $resource): ?array
    {
        return self::all()[$resource] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public static function fieldsOf(string $resource): array
    {
        return self::find($resource)['fields'] ?? [];
    }

    /** What the screen needs to draw every master, without server-only rules. */
    public static function schema(): array
    {
        $resources = [];
        foreach (self::all() as $key => $definition) {
            $resources[] = [
                'key' => $key, 'label' => $definition['label'], 'singular' => $definition['singular'], 'group' => $definition['group'],
                'fields' => array_map(fn (array $f) => array_diff_key($f, ['rules' => 1]), $definition['fields']),
            ];
        }

        return ['groups' => self::GROUPS, 'resources' => $resources];
    }
}
