import { useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api, useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Field, Form, Loading } from "./components";
import { can } from "./opsTypes";
import { Select } from "./Select";

type Option = { id: string; code: string; name: string };
type Row = {
  id: string;
  number: string;
  status: string;
  required_date: string;
  reference: string | null;
  customer_name: string;
};
type Line = {
  id: string;
  line_no: number;
  product_code: string;
  product_name: string;
  ordered_qty: number;
  allocated_qty: number;
  picked_qty: number;
  packed_qty: number;
};
type Order = Row & {
  customer_id: string;
  warehouse_id: string;
  ship_to: string;
  lines: Line[];
};

const today = () => new Date().toISOString().slice(0, 10);

export function OrderListPage() {
  const session = useSession();
  const navigate = useNavigate();
  const [status, setStatus] = useState("");
  const list = useData<{ data: Row[] }>(
    `outbound-orders${status ? `?status=${status}` : ""}`,
  );
  const customers = useData<{ data: (Option & { type: string })[] }>(
    "master/partners?q=&active=1",
  );
  const warehouses = useData<{ data: Option[] }>("master/warehouses/options");
  const products = useData<{ data: Option[] }>("master/products/options");
  const [creating, setCreating] = useState(false);
  const [customer, setCustomer] = useState("");
  const [warehouse, setWarehouse] = useState("");
  const [date, setDate] = useState(today());
  const [reference, setReference] = useState("");
  const [shipTo, setShipTo] = useState("");
  const [lines, setLines] = useState([{ product_id: "", ordered_qty: "" }]);
  const buyers = (
    (
      customers.data as unknown as
        { data: (Option & { type: string })[] } | undefined
    )?.data ?? []
  ).filter((p) => p.type === "CUSTOMER");
  return (
    <section className="card">
      <h1 tabIndex={-1}>Pesanan keluar</h1>
      <ErrorBox error={list.error} />
      <div className="actions">
        <select
          aria-label="Filter status"
          value={status}
          onChange={(e) => setStatus(e.target.value)}
        >
          <option value="">Semua status</option>
          {[
            "DRAFT",
            "RELEASED",
            "ALLOCATED",
            "PICKING",
            "PICKED",
            "PACKED",
            "DISPATCHED",
            "CANCELLED",
          ].map((s) => (
            <option key={s}>{s}</option>
          ))}
        </select>
        {can(session, "outbound.manage") && (
          <button className="primary" onClick={() => setCreating((v) => !v)}>
            {creating ? "Tutup" : "Pesanan baru"}
          </button>
        )}
      </div>
      {creating && (
        <Form
          label="Simpan draf"
          onSubmit={async () => {
            const res = await api<{ data: Order }>("outbound-orders", "POST", {
              customer_id: customer,
              warehouse_id: warehouse,
              required_date: date,
              reference: reference || null,
              ship_to: shipTo,
              lines: lines.map((l) => ({
                product_id: l.product_id,
                ordered_qty: Number(l.ordered_qty),
              })),
            });
            navigate(`/outbound/${res.data.id}`);
          }}
        >
          <Field label="Pelanggan">
            <Select
              value={customer}
              onChange={(e) => setCustomer(e.target.value)}
              required
            >
              <option value="">Pilih pelanggan…</option>
              {buyers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.code} · {c.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Gudang">
            <Select
              value={warehouse}
              onChange={(e) => setWarehouse(e.target.value)}
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
          <Field label="Dibutuhkan tanggal">
            <input
              type="date"
              min={today()}
              value={date}
              onChange={(e) => setDate(e.target.value)}
              required
            />
          </Field>
          <Field label="Referensi">
            <input
              value={reference}
              onChange={(e) => setReference(e.target.value)}
              maxLength={120}
            />
          </Field>
          <Field label="Alamat kirim">
            <textarea
              value={shipTo}
              onChange={(e) => setShipTo(e.target.value)}
              required
              maxLength={1000}
            />
          </Field>
          {lines.map((l, i) => (
            <div className="actions" key={i}>
              <Select
                aria-label={`Produk baris ${i + 1}`}
                value={l.product_id}
                onChange={(e) =>
                  setLines(
                    lines.map((x, j) =>
                      j === i ? { ...x, product_id: e.target.value } : x,
                    ),
                  )
                }
                required
              >
                <option value="">Pilih produk…</option>
                {products.data?.data.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.code} · {p.name}
                  </option>
                ))}
              </Select>
              <input
                aria-label={`Jumlah baris ${i + 1}`}
                type="number"
                min={1}
                value={l.ordered_qty}
                onChange={(e) =>
                  setLines(
                    lines.map((x, j) =>
                      j === i ? { ...x, ordered_qty: e.target.value } : x,
                    ),
                  )
                }
                required
              />
              {lines.length > 1 && (
                <button
                  type="button"
                  onClick={() => setLines(lines.filter((_, j) => j !== i))}
                >
                  Hapus
                </button>
              )}
            </div>
          ))}
          <button
            type="button"
            onClick={() =>
              setLines([...lines, { product_id: "", ordered_qty: "" }])
            }
          >
            Tambah baris
          </button>
        </Form>
      )}
      {list.loading ? (
        <Loading />
      ) : (list.data?.data ?? []).length === 0 ? (
        <Empty>Belum ada pesanan.</Empty>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Nomor</th>
              <th>Pelanggan</th>
              <th>Dibutuhkan</th>
              <th>Referensi</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {list.data!.data.map((o) => (
              <tr key={o.id}>
                <td>
                  <Link to={`/outbound/${o.id}`}>{o.number}</Link>
                </td>
                <td>{o.customer_name}</td>
                <td>{o.required_date}</td>
                <td>{o.reference}</td>
                <td>
                  <Badge value={o.status} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

type Pkg = {
  id: string;
  number: string;
  status: string;
  lines: { product_code: string; quantity: number }[];
};

/** One outbound order: release, allocate, cancel, and (once picked) pack verification. */
export function OrderDetailPage() {
  const { id } = useParams();
  const session = useSession();
  const order = useData<{ data: Order }>(`outbound-orders/${id}`);
  const [error, setError] = useState<unknown>();
  const [pkg, setPkg] = useState<Pkg>();
  const [scan, setScan] = useState("");
  const [qty, setQty] = useState("1");
  if (order.loading) return <Loading />;
  const o = order.data?.data;
  if (!o) return <ErrorBox error={order.error} />;
  const act = (path: string, body: unknown = {}) => {
    setError(undefined);
    api(`outbound-orders/${o.id}/${path}`, "POST", body)
      .then(order.refresh)
      .catch(setError);
  };
  const manage = can(session, "outbound.manage");
  const execute = can(session, "outbound.execute");
  const packing = async (run: () => Promise<{ data: Pkg }>) => {
    setError(undefined);
    try {
      setPkg((await run()).data);
      order.refresh();
    } catch (e) {
      setError(e);
    }
  };
  return (
    <section className="card">
      <h1 tabIndex={-1}>
        {o.number} <Badge value={o.status} />
      </h1>
      <p className="muted">
        {o.customer_name} · {o.ship_to} · dibutuhkan {o.required_date}
      </p>
      <ErrorBox error={error ?? order.error} />
      <div className="actions">
        {manage && o.status === "DRAFT" && (
          <button className="primary" onClick={() => act("release")}>
            Rilis
          </button>
        )}
        {manage && o.status === "RELEASED" && (
          <button className="primary" onClick={() => act("allocate")}>
            Alokasikan stok
          </button>
        )}
        {manage && ["DRAFT", "RELEASED", "ALLOCATED"].includes(o.status) && (
          <button
            onClick={() => {
              const r = window.prompt("Alasan pembatalan");
              if (r) act("cancel", { reason: r });
            }}
          >
            Batalkan
          </button>
        )}
      </div>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Produk</th>
            <th>Pesan</th>
            <th>Teralokasi</th>
            <th>Dipetik</th>
            <th>Dikemas</th>
          </tr>
        </thead>
        <tbody>
          {o.lines.map((l) => (
            <tr key={l.id}>
              <td>{l.line_no}</td>
              <td>
                {l.product_code} · {l.product_name}
              </td>
              <td>{l.ordered_qty}</td>
              <td>{l.allocated_qty}</td>
              <td>{l.picked_qty}</td>
              <td>{l.packed_qty}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {execute && o.status === "PICKED" && (
        <div className="card">
          <h2>Packing</h2>
          {!pkg || pkg.status === "CLOSED" ? (
            <button
              onClick={() =>
                void packing(() =>
                  api<{ data: Pkg }>(
                    `outbound-orders/${o.id}/packages`,
                    "POST",
                    {},
                  ),
                )
              }
            >
              Buka kemasan baru
            </button>
          ) : (
            <>
              <p>
                Kemasan <strong>{pkg.number}</strong>
              </p>
              <Form
                label="Tambah"
                onSubmit={() =>
                  packing(() =>
                    api<{ data: Pkg }>(`packages/${pkg.id}/items`, "POST", {
                      scan,
                      quantity: Number(qty),
                    }),
                  )
                }
              >
                <Field label="Pindai kode / barcode produk">
                  <input
                    value={scan}
                    onChange={(e) => setScan(e.target.value)}
                    required
                    autoFocus
                  />
                </Field>
                <Field label="Jumlah">
                  <input
                    type="number"
                    min={1}
                    value={qty}
                    onChange={(e) => setQty(e.target.value)}
                    required
                  />
                </Field>
              </Form>
              <ul>
                {pkg.lines.map((l, i) => (
                  <li key={i}>
                    {l.product_code} × {l.quantity}
                  </li>
                ))}
              </ul>
              <button
                onClick={() =>
                  void packing(() =>
                    api<{ data: Pkg }>(`packages/${pkg.id}/close`, "POST", {}),
                  )
                }
              >
                Tutup kemasan
              </button>
            </>
          )}
          <button
            className="primary"
            onClick={() => {
              const short = o.lines.some((l) => l.picked_qty < l.ordered_qty);
              const reason = short
                ? window.prompt(
                    "Pesanan kurang dari jumlah pesan. Alasan pengiriman parsial",
                  )
                : null;
              if (short && !reason) return;
              act("pack-complete", { partial_reason: reason });
            }}
          >
            Selesaikan packing
          </button>
        </div>
      )}
    </section>
  );
}

type Task = {
  id: string;
  sequence: number;
  slot: number | null;
  quantity: number;
  picked_qty: number;
  status: string;
  lpn: string;
  lot_no: string | null;
  location_code: string;
  product_code: string;
  product_name: string;
  order_number: string;
};

export function WavesPage() {
  const session = useSession();
  const navigate = useNavigate();
  const waves = useData<{
    data: { id: string; number: string; type: string; status: string }[];
  }>("waves");
  const allocated = useData<{ data: Row[] }>(
    "outbound-orders?status=ALLOCATED",
  );
  const [type, setType] = useState("WAVE");
  const [picked, setPicked] = useState<string[]>([]);
  return (
    <section className="card">
      <h1 tabIndex={-1}>Wave & picking</h1>
      <p className="muted">
        WAVE: satu jalur petik untuk semua pesanan. BATCH: satu produk untuk
        banyak pesanan sekaligus. CLUSTER: beberapa pesanan dipetik dalam satu
        putaran dengan slot tote.
      </p>
      <ErrorBox error={waves.error} />
      {can(session, "outbound.manage") && (
        <Form
          label="Buat wave"
          disabled={picked.length === 0}
          onSubmit={async () => {
            const res = await api<{ data: { id: string } }>("waves", "POST", {
              type,
              order_ids: picked,
            });
            navigate(`/waves/${res.data.id}`);
          }}
        >
          <Field label="Jenis">
            <select value={type} onChange={(e) => setType(e.target.value)}>
              <option>WAVE</option>
              <option>BATCH</option>
              <option>CLUSTER</option>
            </select>
          </Field>
          <fieldset>
            <legend>Pesanan teralokasi</legend>
            {(allocated.data?.data ?? []).length === 0 && (
              <Empty>Tidak ada pesanan yang siap.</Empty>
            )}
            {allocated.data?.data.map((o) => (
              <label key={o.id}>
                <input
                  type="checkbox"
                  checked={picked.includes(o.id)}
                  onChange={(e) =>
                    setPicked(
                      e.target.checked
                        ? [...picked, o.id]
                        : picked.filter((x) => x !== o.id),
                    )
                  }
                />{" "}
                {o.number} · {o.customer_name}
              </label>
            ))}
          </fieldset>
        </Form>
      )}
      {waves.loading ? (
        <Loading />
      ) : (waves.data?.data ?? []).length === 0 ? (
        <Empty>Belum ada wave.</Empty>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Nomor</th>
              <th>Jenis</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {waves.data!.data.map((w) => (
              <tr key={w.id}>
                <td>
                  <Link to={`/waves/${w.id}`}>{w.number}</Link>
                </td>
                <td>{w.type}</td>
                <td>
                  <Badge value={w.status} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

export function WaveDetailPage() {
  const { id } = useParams();
  const session = useSession();
  const wave = useData<{
    data: { number: string; type: string; status: string; tasks: Task[] };
  }>(`waves/${id}`);
  const [error, setError] = useState<unknown>();
  const [scans, setScans] = useState<Record<string, string>>({});
  if (wave.loading) return <Loading />;
  const w = wave.data?.data;
  if (!w) return <ErrorBox error={wave.error} />;
  const execute = can(session, "outbound.execute");
  const pick = (t: Task) => {
    const lpn = scans[t.id];
    if (!lpn) return;
    const answer = window.prompt(
      `Jumlah yang dipetik (tugas ${t.quantity})`,
      String(t.quantity),
    );
    if (answer === null) return;
    const quantity = Number(answer);
    const short =
      quantity < t.quantity
        ? window.prompt("Pick kurang dari tugas. Alasan")
        : null;
    if (quantity < t.quantity && !short) return;
    setError(undefined);
    api(`pick-tasks/${t.id}/pick`, "POST", {
      lpn,
      quantity,
      short_reason: short,
    })
      .then(wave.refresh)
      .catch(setError);
  };
  return (
    <section className="card">
      <h1 tabIndex={-1}>
        {w.number} · {w.type} <Badge value={w.status} />
      </h1>
      <ErrorBox error={error ?? wave.error} />
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Lokasi</th>
            <th>Produk</th>
            <th>Pesanan</th>
            {w.type === "CLUSTER" && <th>Slot</th>}
            <th>Jumlah</th>
            <th>Pindai LPN</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          {w.tasks.map((t) => (
            <tr key={t.id}>
              <td>{t.sequence}</td>
              <td>{t.location_code}</td>
              <td>
                {t.product_code} · {t.product_name}
              </td>
              <td>{t.order_number}</td>
              {w.type === "CLUSTER" && <td>{t.slot}</td>}
              <td>
                {t.status === "OPEN"
                  ? t.quantity
                  : `${t.picked_qty}/${t.quantity}`}
              </td>
              <td>
                {execute && t.status === "OPEN" ? (
                  <>
                    <input
                      aria-label={`LPN ${t.sequence}`}
                      value={scans[t.id] ?? ""}
                      onChange={(e) =>
                        setScans({ ...scans, [t.id]: e.target.value })
                      }
                    />
                    <button onClick={() => pick(t)}>Petik</button>
                  </>
                ) : (
                  t.lpn
                )}
              </td>
              <td>
                <Badge value={t.status} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

type Shipment = {
  id: string;
  number: string;
  mode: string;
  status: string;
  truck_plate: string | null;
  driver_name: string | null;
  orders: { id: string; number: string; status: string }[];
  carrier_status: string | null;
  carrier_status_at: string | null;
  carrier_reason: string | null;
  carrier_events: {
    status: string;
    reason: string | null;
    occurred_at: string;
  }[];
};

const carrierLabel: Record<string, string> = {
  ACCEPTED: "Diterima carrier",
  REJECTED: "Ditolak carrier",
  PICKED_UP: "Sudah dijemput",
  DELIVERED: "Sudah terkirim",
  CANCELLED: "Dibatalkan carrier",
};
type Manifest = {
  shipment: string;
  mode: string;
  truck_plate: string | null;
  driver: string | null;
  total_packages: number;
  total_weight_g: number;
  orders: {
    order: string;
    customer: string;
    ship_to: string;
    packages: number;
    lines: { product: string; name: string; shipped: number }[];
  }[];
};

function printManifest(m: Manifest) {
  const w = window.open("", "_blank", "width=800,height=900");
  if (!w) return;
  const esc = (v: string | number | null) =>
    String(v ?? "-").replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
  w.document.write(
    `<html><head><title>${esc(m.shipment)}</title></head><body style="font-family:sans-serif"><h1>Manifes ${esc(m.shipment)}</h1>` +
      `<p>Mode ${esc(m.mode)} · Plat ${esc(m.truck_plate)} · Pengemudi ${esc(m.driver)} · ${esc(m.total_packages)} kemasan · ${esc(m.total_weight_g / 1000)} kg</p>` +
      m.orders
        .map(
          (o) =>
            `<h3>${esc(o.order)} — ${esc(o.customer)}</h3><p>${esc(o.ship_to)} · ${esc(o.packages)} kemasan</p><ul>${o.lines.map((l) => `<li>${esc(l.product)} ${esc(l.name)} × ${esc(l.shipped)}</li>`).join("")}</ul>`,
        )
        .join("") +
      `</body></html>`,
  );
  w.document.close();
  w.print();
}

export function ShipmentsPage() {
  const session = useSession();
  const list = useData<{ data: Shipment[] }>("shipments");
  const warehouses = useData<{ data: Option[] }>("master/warehouses/options");
  const packed = useData<{ data: Row[] }>("outbound-orders?status=PACKED");
  const [warehouse, setWarehouse] = useState("");
  const [plate, setPlate] = useState("");
  const [driver, setDriver] = useState("");
  const [error, setError] = useState<unknown>();
  const execute = can(session, "outbound.execute");
  const act = (p: Promise<unknown>) =>
    p
      .then(() => {
        list.refresh();
        packed.refresh();
      })
      .catch(setError);
  return (
    <section className="card">
      <h1 tabIndex={-1}>Pengiriman & manifes</h1>
      <ErrorBox error={error ?? list.error} />
      {execute && (
        <Form
          label="Buat pengiriman"
          onSubmit={async () => {
            await api("shipments", "POST", {
              warehouse_id: warehouse,
              truck_plate: plate || null,
              driver_name: driver || null,
            });
            list.refresh();
          }}
        >
          <Field label="Gudang">
            <Select
              value={warehouse}
              onChange={(e) => setWarehouse(e.target.value)}
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
          <Field label="Plat kendaraan">
            <input
              value={plate}
              onChange={(e) => setPlate(e.target.value)}
              maxLength={30}
            />
          </Field>
          <Field label="Pengemudi">
            <input
              value={driver}
              onChange={(e) => setDriver(e.target.value)}
              maxLength={150}
            />
          </Field>
        </Form>
      )}
      {list.loading ? (
        <Loading />
      ) : (list.data?.data ?? []).length === 0 ? (
        <Empty>Belum ada pengiriman.</Empty>
      ) : (
        list.data!.data.map((s) => (
          <div className="card" key={s.id}>
            <h2>
              {s.number} <Badge value={s.status} /> <small>{s.mode}</small>
            </h2>
            <p className="muted">
              {s.truck_plate ?? "tanpa plat"} ·{" "}
              {s.driver_name ?? "tanpa pengemudi"}
            </p>
            {s.mode === "OPTIDELI" && s.status === "DISPATCHED" && (
              <p>
                <strong>Carrier (OptiDeli):</strong>{" "}
                {s.carrier_status ? (
                  <>
                    <Badge
                      value={carrierLabel[s.carrier_status] ?? s.carrier_status}
                    />{" "}
                    {s.carrier_reason && <span>{s.carrier_reason}</span>}
                  </>
                ) : (
                  <span className="muted">menunggu tanggapan</span>
                )}
                {(s.carrier_events ?? []).length > 1 && (
                  <details>
                    <summary>Riwayat status</summary>
                    <ul>
                      {s.carrier_events.map((e, n) => (
                        <li key={n}>
                          {new Date(e.occurred_at).toLocaleString("id-ID")} ·{" "}
                          {carrierLabel[e.status] ?? e.status}
                          {e.reason ? ` · ${e.reason}` : ""}
                        </li>
                      ))}
                    </ul>
                  </details>
                )}
              </p>
            )}
            <ul>
              {(s.orders ?? []).map((o) => (
                <li key={o.id}>
                  {o.number} <Badge value={o.status} />
                </li>
              ))}
            </ul>
            <div className="actions">
              {execute && s.status === "LOADING" && (
                <>
                  <select
                    aria-label={`Muat pesanan ke ${s.number}`}
                    value=""
                    onChange={(e) =>
                      e.target.value &&
                      void act(
                        api(`shipments/${s.id}/orders`, "POST", {
                          order_id: e.target.value,
                        }),
                      )
                    }
                  >
                    <option value="">Muat pesanan PACKED…</option>
                    {packed.data?.data.map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.number} · {o.customer_name}
                      </option>
                    ))}
                  </select>
                  <button
                    className="primary"
                    onClick={() =>
                      void act(api(`shipments/${s.id}/dispatch`, "POST", {}))
                    }
                  >
                    Kirim
                  </button>
                  <button
                    onClick={() => {
                      const r = window.prompt("Alasan pembatalan");
                      if (r)
                        void act(
                          api(`shipments/${s.id}/cancel`, "POST", {
                            reason: r,
                          }),
                        );
                    }}
                  >
                    Batalkan
                  </button>
                </>
              )}
              <button
                onClick={() =>
                  api<{ data: Manifest }>(`shipments/${s.id}/manifest`)
                    .then((r) => printManifest(r.data))
                    .catch(setError)
                }
              >
                Cetak manifes
              </button>
            </div>
          </div>
        ))
      )}
    </section>
  );
}
