import { useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api, useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Field, Form, Loading } from "./components";
import { can } from "./opsTypes";
import { Select } from "./Select";

type Warehouse = { id: string; code: string; name: string };
type MapNode = {
  id: string;
  code: string;
  name: string;
  level: string;
  depth: number;
  quantity: number;
  units: number;
  locked_quantity: number;
  utilization: number | null;
  effective_blocked: boolean;
  active: boolean;
};
type StockUnit = {
  id: string;
  lpn: string;
  lot_no: string | null;
  expiry_date: string | null;
  quantity: number;
  status: string;
  locked: boolean;
  lock_reason: string | null;
  product_code: string;
  product_name: string;
  location_code: string;
};

function useWarehouses() {
  return useData<{ data: Warehouse[] }>("master/warehouses/options");
}

/** Stock map of a warehouse: quantity and fill level per location, with drill-down to the stock units and locking. */
export function StockMapPage() {
  const session = useSession();
  const warehouses = useWarehouses();
  const [warehouseId, setWarehouseId] = useState("");
  const map = useData<{ data: MapNode[] }>(
    warehouseId ? `inventory/map/${warehouseId}` : "master/schema",
  );
  const [node, setNode] = useState<string>();
  const units = useData<{ data: StockUnit[] }>(
    node ? `inventory/locations/${node}/units` : "master/schema",
  );
  const [error, setError] = useState<unknown>();
  const lock = can(session, "inventory.lock");
  const nodes = warehouseId
    ? ((map.data as unknown as { data: MapNode[] } | undefined)?.data ?? [])
    : [];
  const rows = node
    ? ((units.data as unknown as { data: StockUnit[] } | undefined)?.data ?? [])
    : [];
  const refresh = () => {
    map.refresh();
    units.refresh();
  };
  const toggle = (u: StockUnit) => {
    const reason = window.prompt(
      u.locked ? "Alasan membuka kunci" : "Alasan mengunci",
    );
    if (!reason) return;
    setError(undefined);
    api(`stock-units/${u.id}/lock`, "POST", { locked: !u.locked, reason })
      .then(refresh)
      .catch(setError);
  };
  return (
    <section className="card">
      <h1 tabIndex={-1}>Peta stok</h1>
      <ErrorBox error={error ?? map.error} />
      <Field label="Gudang">
        <Select
          value={warehouseId}
          onChange={(e) => {
            setWarehouseId(e.target.value);
            setNode(undefined);
          }}
        >
          <option value="">Pilih gudang…</option>
          {warehouses.data?.data.map((w) => (
            <option key={w.id} value={w.id}>
              {w.code} · {w.name}
            </option>
          ))}
        </Select>
      </Field>
      {warehouseId && map.loading && <Loading />}
      {warehouseId && !map.loading && nodes.length === 0 && (
        <Empty>Gudang ini belum memiliki lokasi.</Empty>
      )}
      {nodes.length > 0 && (
        <table>
          <thead>
            <tr>
              <th>Lokasi</th>
              <th>Stok</th>
              <th>Terkunci</th>
              <th>Isi</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {nodes.map((n) => (
              <tr key={n.id}>
                <td style={{ paddingLeft: `${n.depth * 1.5 + 0.5}rem` }}>
                  <button onClick={() => setNode(n.id)}>{n.code}</button>
                  {n.effective_blocked && <Badge value="DIBLOKIR" />}
                </td>
                <td>{n.quantity}</td>
                <td>{n.locked_quantity}</td>
                <td>
                  {n.utilization === null ? (
                    "–"
                  ) : (
                    <progress
                      max={100}
                      value={n.utilization}
                      aria-label={`${n.utilization}%`}
                    />
                  )}
                </td>
                <td>{n.units} unit</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {node && (
        <div className="card">
          <h2>Isi lokasi</h2>
          {rows.length === 0 ? (
            <Empty>Tidak ada stok.</Empty>
          ) : (
            <table>
              <thead>
                <tr>
                  <th>LPN</th>
                  <th>Produk</th>
                  <th>Lot</th>
                  <th>Exp</th>
                  <th>Jumlah</th>
                  <th>Lokasi</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {rows.map((u) => (
                  <tr key={u.id}>
                    <td>{u.lpn}</td>
                    <td>
                      {u.product_code} · {u.product_name}
                    </td>
                    <td>{u.lot_no}</td>
                    <td>{u.expiry_date}</td>
                    <td>{u.quantity}</td>
                    <td>{u.location_code}</td>
                    <td>
                      <Badge value={u.locked ? "TERKUNCI" : u.status} />
                    </td>
                    <td>
                      {lock && u.status === "AVAILABLE" && (
                        <button onClick={() => toggle(u)}>
                          {u.locked ? "Buka kunci" : "Kunci"}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}
    </section>
  );
}

type Take = {
  id: string;
  number: string;
  status: string;
  notes: string | null;
  created_at: string;
};
type TakeLine = {
  id: string;
  lpn: string;
  product_code: string;
  product_name: string;
  location_code: string | null;
  expected_qty: number;
  counted_qty: number | null;
  variance_reason: string | null;
};

export function StocktakeListPage() {
  const navigate = useNavigate();
  const takes = useData<{ data: Take[] }>("stocktakes");
  const warehouses = useWarehouses();
  const [warehouseId, setWarehouseId] = useState("");
  const [notes, setNotes] = useState("");
  return (
    <section className="card">
      <h1 tabIndex={-1}>Cycle count & stock take</h1>
      <ErrorBox error={takes.error} />
      <Form
        label="Mulai penghitungan"
        onSubmit={async () => {
          const res = await api<{ data: Take }>("stocktakes", "POST", {
            warehouse_id: warehouseId,
            notes: notes || null,
          });
          navigate(`/stocktakes/${res.data.id}`);
        }}
      >
        <Field label="Gudang">
          <Select
            value={warehouseId}
            onChange={(e) => setWarehouseId(e.target.value)}
            required
          >
            <option value="">Pilih gudang…</option>
            {warehouses.data?.data.map((w) => (
              <option key={w.id} value={w.id}>
                {w.code} · {w.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Catatan">
          <input
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            maxLength={500}
          />
        </Field>
      </Form>
      {takes.loading ? (
        <Loading />
      ) : (takes.data?.data ?? []).length === 0 ? (
        <Empty>Belum ada penghitungan.</Empty>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Nomor</th>
              <th>Catatan</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {takes.data!.data.map((t) => (
              <tr key={t.id}>
                <td>
                  <Link to={`/stocktakes/${t.id}`}>{t.number}</Link>
                </td>
                <td>{t.notes}</td>
                <td>
                  <Badge value={t.status} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

export function StocktakeDetailPage() {
  const { id } = useParams();
  const session = useSession();
  const take = useData<{ data: Take & { lines: TakeLine[] } }>(
    `stocktakes/${id}`,
  );
  const [error, setError] = useState<unknown>();
  const [counts, setCounts] = useState<Record<string, string>>({});
  if (take.loading) return <Loading />;
  const t = take.data?.data;
  if (!t) return <ErrorBox error={take.error} />;
  const manage = can(session, "stocktake.manage");
  const approve = can(session, "stocktake.approve");
  const act = (path: string, body?: unknown) => {
    setError(undefined);
    api(`stocktakes/${t.id}/${path}`, "POST", body ?? {})
      .then(take.refresh)
      .catch(setError);
  };
  const ask = (q: string) => window.prompt(q);
  const count = (l: TakeLine) => {
    const qty = counts[l.id];
    if (qty === undefined || qty === "") return;
    const reason =
      Number(qty) === l.expected_qty ? null : ask("Alasan selisih");
    if (Number(qty) !== l.expected_qty && !reason) return;
    setError(undefined);
    api(`stocktake-lines/${l.id}/count`, "POST", {
      counted_qty: Number(qty),
      reason,
    })
      .then(take.refresh)
      .catch(setError);
  };
  return (
    <section className="card">
      <h1 tabIndex={-1}>
        {t.number} <Badge value={t.status} />
      </h1>
      <ErrorBox error={error ?? take.error} />
      <div className="actions">
        {manage && t.status === "COUNTING" && (
          <>
            <button className="primary" onClick={() => act("submit")}>
              Ajukan hasil
            </button>
            <button
              onClick={() => {
                const r = ask("Alasan pembatalan");
                if (r) act("cancel", { reason: r });
              }}
            >
              Batalkan
            </button>
          </>
        )}
        {approve && t.status === "PENDING_APPROVAL" && (
          <>
            <button className="primary" onClick={() => act("approve")}>
              Setujui penyesuaian
            </button>
            <button
              onClick={() => {
                const r = ask("Alasan hitung ulang");
                if (r) act("reject", { reason: r });
              }}
            >
              Hitung ulang
            </button>
          </>
        )}
      </div>
      <table>
        <thead>
          <tr>
            <th>Lokasi</th>
            <th>LPN</th>
            <th>Produk</th>
            <th>Sistem</th>
            <th>Hitung</th>
            <th>Selisih</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {t.lines.map((l) => (
            <tr key={l.id}>
              <td>{l.location_code}</td>
              <td>{l.lpn}</td>
              <td>
                {l.product_code} · {l.product_name}
              </td>
              <td>{l.expected_qty}</td>
              <td>
                {manage && t.status === "COUNTING" ? (
                  <input
                    type="number"
                    min={0}
                    aria-label={`Hitung ${l.lpn}`}
                    value={counts[l.id] ?? l.counted_qty ?? ""}
                    onChange={(e) =>
                      setCounts({ ...counts, [l.id]: e.target.value })
                    }
                  />
                ) : (
                  (l.counted_qty ?? "–")
                )}
              </td>
              <td>
                {l.counted_qty === null ? "" : l.counted_qty - l.expected_qty}
                {l.variance_reason && <small> {l.variance_reason}</small>}
              </td>
              <td>
                {manage && t.status === "COUNTING" && (
                  <button onClick={() => count(l)}>Simpan</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

type Result = {
  product_id: string;
  code: string;
  name: string;
  consumption_qty: number;
  consumption_value: number;
  days_since_last_issue: number | null;
  abc: string;
  fsn: string;
  ved: string;
  hml: string;
  sde: string;
  xyz: string;
  eoq: number | null;
};
type Cell = { row: string; col: string; count: number; products: string[] };
const dims = ["abc", "fsn", "ved", "hml", "sde", "xyz"];

/** Inventory analysis: run a snapshot, browse the classifications and combine any two as a matrix. */
export function AnalysisPage() {
  const session = useSession();
  const configure = can(session, "inventory.analysis.configure");
  const results = useData<{ data: Result[] }>("analysis/results");
  const config = useData<{ data: Record<string, number> }>("analysis/config");
  const [row, setRow] = useState("abc");
  const [col, setCol] = useState("xyz");
  const matrix = useData<{ data: { cells: Cell[] } }>(
    `analysis/matrix?row=${row}&col=${col}`,
  );
  const [error, setError] = useState<unknown>();
  const [filter, setFilter] = useState<Record<string, string>>({});
  const noRun = results.error?.includes("Belum ada hasil");
  const data = (results.data?.data ?? []).filter((r) =>
    Object.entries(filter).every(
      ([d, v]) => !v || (r as unknown as Record<string, string>)[d] === v,
    ),
  );
  const cells = (
    matrix.data as unknown as { data: { cells: Cell[] } } | undefined
  )?.data.cells;
  const rowVals = [...new Set((cells ?? []).map((c) => c.row))];
  const colVals = [...new Set((cells ?? []).map((c) => c.col))];
  return (
    <section className="card">
      <h1 tabIndex={-1}>Analisis persediaan</h1>
      <p className="muted">
        ABC (nilai pemakaian), FSN (kecepatan), VED (kritikalitas), HML (harga),
        SDE (kelangkaan pasokan), XYZ (variasi permintaan), dan EOQ.
      </p>
      <ErrorBox error={error ?? (noRun ? undefined : results.error)} />
      {configure && (
        <div className="actions">
          <button
            className="primary"
            onClick={() => {
              setError(undefined);
              api("analysis/run", "POST", {})
                .then(() => {
                  results.refresh();
                  matrix.refresh();
                })
                .catch(setError);
            }}
          >
            Jalankan analisis
          </button>
        </div>
      )}
      {noRun && (
        <Empty>Belum ada hasil. Jalankan analisis terlebih dahulu.</Empty>
      )}
      {!noRun && !results.loading && (
        <>
          <div className="actions">
            {dims.map((d) => (
              <label key={d}>
                {d.toUpperCase()}{" "}
                <input
                  size={2}
                  maxLength={1}
                  value={filter[d] ?? ""}
                  onChange={(e) =>
                    setFilter({ ...filter, [d]: e.target.value.toUpperCase() })
                  }
                />
              </label>
            ))}
          </div>
          <table>
            <thead>
              <tr>
                <th>Produk</th>
                <th>Pemakaian</th>
                <th>Nilai (Rp)</th>
                <th>Hari sejak keluar</th>
                {dims.map((d) => (
                  <th key={d}>{d.toUpperCase()}</th>
                ))}
                <th>EOQ</th>
              </tr>
            </thead>
            <tbody>
              {data.map((r) => (
                <tr key={r.product_id}>
                  <td>
                    {r.code} · {r.name}
                  </td>
                  <td>{r.consumption_qty}</td>
                  <td>{Number(r.consumption_value).toLocaleString("id-ID")}</td>
                  <td>{r.days_since_last_issue ?? "–"}</td>
                  {dims.map((d) => (
                    <td key={d}>
                      {(r as unknown as Record<string, string>)[d]}
                    </td>
                  ))}
                  <td>{r.eoq ?? "–"}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <h2>Matriks gabungan</h2>
          <div className="actions">
            <select
              aria-label="Baris"
              value={row}
              onChange={(e) => setRow(e.target.value)}
            >
              {dims.map((d) => (
                <option key={d} value={d}>
                  {d.toUpperCase()}
                </option>
              ))}
            </select>
            ×
            <select
              aria-label="Kolom"
              value={col}
              onChange={(e) => setCol(e.target.value)}
            >
              {dims.map((d) => (
                <option key={d} value={d}>
                  {d.toUpperCase()}
                </option>
              ))}
            </select>
          </div>
          {row === col ? (
            <p className="muted">Pilih dua klasifikasi yang berbeda.</p>
          ) : (
            cells && (
              <table>
                <thead>
                  <tr>
                    <th />
                    {colVals.map((c) => (
                      <th key={c}>{c}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {rowVals.map((rv) => (
                    <tr key={rv}>
                      <th>{rv}</th>
                      {colVals.map((cv) => {
                        const cell = cells.find(
                          (c) => c.row === rv && c.col === cv,
                        );
                        return (
                          <td key={cv} title={cell?.products.join(", ")}>
                            {cell?.count ?? 0}
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            )
          )}
        </>
      )}
      {configure && config.data && (
        <ConfigForm
          values={config.data.data}
          onSaved={() => config.refresh()}
        />
      )}
    </section>
  );
}

function ConfigForm({
  values,
  onSaved,
}: {
  values: Record<string, number>;
  onSaved: () => void;
}) {
  const [draft, setDraft] = useState<Record<string, string>>(
    Object.fromEntries(Object.entries(values).map(([k, v]) => [k, String(v)])),
  );
  return (
    <details>
      <summary>Ambang klasifikasi</summary>
      <Form
        label="Simpan ambang"
        onSubmit={async () => {
          await api(
            "analysis/config",
            "PUT",
            Object.fromEntries(
              Object.entries(draft).map(([k, v]) => [k, Number(v)]),
            ),
          );
          onSaved();
        }}
      >
        {Object.keys(draft).map((k) => (
          <Field label={k} key={k}>
            <input
              type="number"
              step="any"
              value={draft[k]}
              onChange={(e) => setDraft({ ...draft, [k]: e.target.value })}
              required
            />
          </Field>
        ))}
      </Form>
    </details>
  );
}
