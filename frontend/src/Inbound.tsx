import { useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api, useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Field, Form, Loading } from "./components";
import { can } from "./opsTypes";
import { Select } from "./Select";

export type Line = {
  id: string;
  line_no: number;
  product_id: string;
  product_code: string;
  product_name: string;
  track_lot: boolean;
  track_expiry: boolean;
  expected_qty: number;
  received_qty: number;
};
export type Asn = {
  id: string;
  number: string;
  status: string;
  source: string;
  supplier_id: string;
  supplier_name: string;
  warehouse_id: string;
  warehouse_name: string;
  expected_date: string;
  reference: string | null;
  truck_plate: string | null;
  notes: string | null;
  lines: Line[];
};
type Row = {
  id: string;
  number: string;
  status: string;
  source: string;
  expected_date: string;
  reference: string | null;
  supplier_name: string;
};
type Option = { id: string; code: string; name: string };

const today = () => new Date().toISOString().slice(0, 10);

/** The lines editor of an ASN: product + quantity rows. Shared by the staff screen and the supplier portal. */
export function AsnForm({
  products,
  warehouses,
  suppliers,
  initial,
  onSave,
  label = "Simpan draf",
}: {
  products: Option[];
  warehouses: Option[];
  suppliers?: Option[];
  initial?: Asn;
  onSave: (body: Record<string, unknown>) => Promise<void>;
  label?: string;
}) {
  const [supplier, setSupplier] = useState(initial?.supplier_id ?? "");
  const [warehouse, setWarehouse] = useState(initial?.warehouse_id ?? "");
  const [date, setDate] = useState(initial?.expected_date ?? today());
  const [reference, setReference] = useState(initial?.reference ?? "");
  const [plate, setPlate] = useState(initial?.truck_plate ?? "");
  const [lines, setLines] = useState<
    { product_id: string; expected_qty: string }[]
  >(
    initial?.lines.map((l) => ({
      product_id: l.product_id,
      expected_qty: String(l.expected_qty),
    })) ?? [{ product_id: "", expected_qty: "" }],
  );
  return (
    <Form
      label={label}
      onSubmit={() =>
        onSave({
          ...(suppliers ? { supplier_id: supplier } : {}),
          warehouse_id: warehouse,
          expected_date: date,
          reference: reference || null,
          truck_plate: plate || null,
          lines: lines.map((l) => ({
            product_id: l.product_id,
            expected_qty: Number(l.expected_qty),
          })),
        })
      }
    >
      {suppliers && (
        <Field label="Pemasok">
          <Select
            value={supplier}
            onChange={(e) => setSupplier(e.target.value)}
            required
          >
            <option value="">Pilih pemasok…</option>
            {suppliers.map((s) => (
              <option key={s.id} value={s.id}>
                {s.code} · {s.name}
              </option>
            ))}
          </Select>
        </Field>
      )}
      <Field label="Gudang tujuan">
        <Select
          value={warehouse}
          onChange={(e) => setWarehouse(e.target.value)}
          required
        >
          <option value="">Pilih gudang…</option>
          {warehouses.map((w) => (
            <option key={w.id} value={w.id}>
              {w.code} · {w.name}
            </option>
          ))}
        </Select>
      </Field>
      <Field label="Tanggal kedatangan">
        <input
          type="date"
          min={today()}
          value={date}
          onChange={(e) => setDate(e.target.value)}
          required
        />
      </Field>
      <Field label="Nomor referensi (PO / surat jalan)">
        <input
          value={reference}
          onChange={(e) => setReference(e.target.value)}
          maxLength={120}
        />
      </Field>
      <Field label="Plat truk">
        <input
          value={plate}
          onChange={(e) => setPlate(e.target.value)}
          maxLength={30}
        />
      </Field>
      <h3>Barang</h3>
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
            {products.map((p) => (
              <option key={p.id} value={p.id}>
                {p.code} · {p.name}
              </option>
            ))}
          </Select>
          <input
            aria-label={`Jumlah baris ${i + 1}`}
            type="number"
            min={1}
            placeholder="Jumlah"
            value={l.expected_qty}
            onChange={(e) =>
              setLines(
                lines.map((x, j) =>
                  j === i ? { ...x, expected_qty: e.target.value } : x,
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
          setLines([...lines, { product_id: "", expected_qty: "" }])
        }
      >
        Tambah baris
      </button>
    </Form>
  );
}

function useLookups() {
  const products = useData<{ data: Option[] }>("master/products/options");
  const warehouses = useData<{ data: Option[] }>("master/warehouses/options");
  const suppliers = useData<{ data: (Option & { type: string })[] }>(
    "master/partners/options",
  );
  return { products, warehouses, suppliers };
}

export function AsnListPage() {
  const session = useSession();
  const navigate = useNavigate();
  const [status, setStatus] = useState("");
  const list = useData<{ data: Row[]; total: number }>(
    `asns${status ? `?status=${status}` : ""}`,
  );
  const [creating, setCreating] = useState(false);
  const { products, warehouses, suppliers } = useLookups();
  return (
    <section className="card">
      <h1 tabIndex={-1}>ASN (pemberitahuan pengiriman)</h1>
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
            "SUBMITTED",
            "CONFIRMED",
            "ARRIVED",
            "RECEIVING",
            "RECEIVED",
            "CANCELLED",
          ].map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
        </select>
        {can(session, "asn.manage") && (
          <button className="primary" onClick={() => setCreating((v) => !v)}>
            {creating ? "Tutup" : "ASN baru"}
          </button>
        )}
      </div>
      {creating && (
        <AsnForm
          products={products.data?.data ?? []}
          warehouses={warehouses.data?.data ?? []}
          suppliers={(suppliers.data?.data ?? []) as Option[]}
          onSave={async (body) => {
            const res = await api<{ data: Asn }>("asns", "POST", body);
            navigate(`/asns/${res.data.id}`);
          }}
        />
      )}
      {list.loading ? (
        <Loading />
      ) : (list.data?.data ?? []).length === 0 ? (
        <Empty>Belum ada ASN.</Empty>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Nomor</th>
              <th>Pemasok</th>
              <th>Tiba</th>
              <th>Referensi</th>
              <th>Sumber</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {list.data!.data.map((a) => (
              <tr key={a.id}>
                <td>
                  <Link to={`/asns/${a.id}`}>{a.number}</Link>
                </td>
                <td>{a.supplier_name}</td>
                <td>{a.expected_date}</td>
                <td>{a.reference}</td>
                <td>{a.source === "PORTAL" ? "Portal" : "Internal"}</td>
                <td>
                  <Badge value={a.status} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

export function AsnDetailPage() {
  const { id } = useParams();
  const session = useSession();
  const asn = useData<{ data: Asn }>(`asns/${id}`);
  const [error, setError] = useState<unknown>();
  const [editing, setEditing] = useState(false);
  const { products, warehouses, suppliers } = useLookups();
  if (asn.loading) return <Loading />;
  const a = asn.data?.data;
  if (!a) return <ErrorBox error={asn.error} />;
  const act = (path: string, body?: unknown) => {
    setError(undefined);
    api(`asns/${a.id}/${path}`, "POST", body ?? {})
      .then(asn.refresh)
      .catch(setError);
  };
  const reason = (q: string) => window.prompt(q);
  return (
    <section className="card">
      <h1 tabIndex={-1}>
        {a.number} <Badge value={a.status} />
      </h1>
      <p className="muted">
        {a.supplier_name} → {a.warehouse_name} · tiba {a.expected_date}
        {a.reference && <> · ref. {a.reference}</>}
        {a.truck_plate && <> · {a.truck_plate}</>}
      </p>
      <ErrorBox error={error ?? asn.error} />
      <div className="actions">
        {a.status === "DRAFT" && can(session, "asn.manage") && (
          <>
            <button onClick={() => setEditing((v) => !v)}>
              {editing ? "Tutup ubah" : "Ubah"}
            </button>
            <button className="primary" onClick={() => act("submit")}>
              Ajukan
            </button>
          </>
        )}
        {a.status === "SUBMITTED" && can(session, "asn.approve") && (
          <button className="primary" onClick={() => act("confirm")}>
            Konfirmasi
          </button>
        )}
        {["DRAFT", "SUBMITTED", "CONFIRMED"].includes(a.status) &&
          can(session, "asn.approve") && (
            <button
              onClick={() => {
                const r = reason("Alasan pembatalan");
                if (r) act("cancel", { reason: r });
              }}
            >
              Batalkan
            </button>
          )}
        {["ARRIVED", "RECEIVING"].includes(a.status) &&
          can(session, "receiving.execute") && (
            <>
              <Link className="button" to={`/receiving/${a.id}`}>
                Terima barang
              </Link>
              <button
                onClick={() => {
                  const r = a.lines.every(
                    (l) => l.received_qty >= l.expected_qty,
                  )
                    ? ""
                    : reason("Ada kekurangan; alasan penutupan");
                  if (r !== null) act("complete", { reason: r || null });
                }}
              >
                Tutup penerimaan
              </button>
            </>
          )}
      </div>
      {editing && (
        <AsnForm
          initial={a}
          products={products.data?.data ?? []}
          warehouses={warehouses.data?.data ?? []}
          suppliers={(suppliers.data?.data ?? []) as Option[]}
          onSave={async (body) => {
            await api(`asns/${a.id}`, "PUT", body);
            setEditing(false);
            asn.refresh();
          }}
        />
      )}
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Produk</th>
            <th>Rencana</th>
            <th>Diterima</th>
          </tr>
        </thead>
        <tbody>
          {a.lines.map((l) => (
            <tr key={l.id}>
              <td>{l.line_no}</td>
              <td>
                {l.product_code} · {l.product_name}
              </td>
              <td>{l.expected_qty}</td>
              <td>{l.received_qty}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

type Booking = {
  id: string;
  status: string;
  slot_start: string;
  slot_end: string;
  truck_plate: string | null;
  dock_code: string;
  asn_number: string | null;
};

export function DockPage() {
  const session = useSession();
  const manage = can(session, "dock.manage");
  const bookings = useData<{ data: Booking[] }>("dock-bookings");
  const docks = useData<{ data: Option[] }>("master/docks/options");
  const confirmed = useData<{ data: Row[] }>("asns?status=CONFIRMED");
  const [dock, setDock] = useState("");
  const [asnId, setAsnId] = useState("");
  const [start, setStart] = useState("");
  const [end, setEnd] = useState("");
  const [error, setError] = useState<unknown>();
  const act = (path: string, body: unknown = {}) => {
    setError(undefined);
    api(`dock-bookings/${path}`, "POST", body)
      .then(bookings.refresh)
      .catch(setError);
  };
  return (
    <section className="card">
      <h1 tabIndex={-1}>Dock & gate</h1>
      <ErrorBox error={error ?? bookings.error} />
      {manage && (
        <Form
          label="Jadwalkan"
          onSubmit={async () => {
            await api("dock-bookings", "POST", {
              dock_id: dock,
              asn_id: asnId,
              slot_start: new Date(start).toISOString(),
              slot_end: new Date(end).toISOString(),
            });
            bookings.refresh();
            confirmed.refresh();
          }}
        >
          <Field label="Dock">
            <Select
              value={dock}
              onChange={(e) => setDock(e.target.value)}
              required
            >
              <option value="">Pilih dock…</option>
              {docks.data?.data.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.code} · {d.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="ASN terkonfirmasi">
            <Select
              value={asnId}
              onChange={(e) => setAsnId(e.target.value)}
              required
            >
              <option value="">Pilih ASN…</option>
              {confirmed.data?.data.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.number} · {a.supplier_name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Mulai">
            <input
              type="datetime-local"
              value={start}
              onChange={(e) => setStart(e.target.value)}
              required
            />
          </Field>
          <Field label="Selesai">
            <input
              type="datetime-local"
              value={end}
              onChange={(e) => setEnd(e.target.value)}
              required
            />
          </Field>
        </Form>
      )}
      {bookings.loading ? (
        <Loading />
      ) : (bookings.data?.data ?? []).length === 0 ? (
        <Empty>Belum ada jadwal.</Empty>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Dock</th>
              <th>ASN</th>
              <th>Slot</th>
              <th>Truk</th>
              <th>Status</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {bookings.data!.data.map((b) => (
              <tr key={b.id}>
                <td>{b.dock_code}</td>
                <td>{b.asn_number}</td>
                <td>
                  {new Date(b.slot_start).toLocaleString("id-ID")} –{" "}
                  {new Date(b.slot_end).toLocaleTimeString("id-ID")}
                </td>
                <td>{b.truck_plate}</td>
                <td>
                  <Badge value={b.status} />
                </td>
                <td>
                  {manage && b.status === "BOOKED" && (
                    <>
                      <button
                        onClick={() =>
                          act(`${b.id}/gate-in`, {
                            truck_plate:
                              b.truck_plate ?? window.prompt("Plat truk"),
                          })
                        }
                      >
                        Gate-in
                      </button>
                      <button
                        onClick={() => {
                          const r = window.prompt("Alasan pembatalan");
                          if (r) act(`${b.id}/cancel`, { reason: r });
                        }}
                      >
                        Batalkan
                      </button>
                    </>
                  )}
                  {manage && b.status === "GATE_IN" && (
                    <button onClick={() => act(`${b.id}/gate-out`)}>
                      Gate-out
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

type Unit = {
  id: string;
  lpn: string;
  lot_no: string | null;
  expiry_date: string | null;
  quantity: number;
  status: string;
  product_code: string;
  product_name: string;
  location_code: string | null;
};
type Label = {
  barcode: string;
  lpn: string;
  product_code: string;
  product_name: string;
  lot_no: string | null;
  expiry_date: string | null;
  quantity: number;
};

function printLabel(l: Label) {
  const w = window.open("", "_blank", "width=420,height=320");
  if (!w) return;
  const esc = (v: string | number | null) =>
    String(v ?? "-").replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
  w.document.write(
    `<html><head><title>${esc(l.lpn)}</title></head><body style="font-family:sans-serif"><h2>${esc(l.product_code)} · ${esc(l.product_name)}</h2>` +
      `<p>Lot: ${esc(l.lot_no)} · Exp: ${esc(l.expiry_date)} · Qty: ${esc(l.quantity)}</p>` +
      `<p style="font:700 28px monospace;letter-spacing:3px">*${esc(l.barcode)}*</p><p>${esc(l.lpn)}</p></body></html>`,
  );
  w.document.close();
  w.print();
}

/** Receiving at the dock: pick a truck-arrived ASN, receive its lines by pallet/carton, print the LPN label. */
export function ReceivingPage() {
  const { id } = useParams();
  const arrived = useData<{ data: Row[] }>("asns?status=ARRIVED");
  const receiving = useData<{ data: Row[] }>("asns?status=RECEIVING");
  const navigate = useNavigate();
  const asn = useData<{ data: Asn }>(id ? `asns/${id}` : "master/schema");
  const [lineId, setLineId] = useState("");
  const [qty, setQty] = useState("");
  const [lot, setLot] = useState("");
  const [expiry, setExpiry] = useState("");
  const [bad, setBad] = useState(false);
  const [category, setCategory] = useState("DAMAGED");
  const [description, setDescription] = useState("");
  const [last, setLast] = useState<Unit>();
  const [error, setError] = useState<unknown>();
  const open = [...(arrived.data?.data ?? []), ...(receiving.data?.data ?? [])];
  const a = id ? asn.data?.data : undefined;
  const line = a?.lines.find((l) => l.id === lineId);
  return (
    <section className="card">
      <h1 tabIndex={-1}>Penerimaan barang</h1>
      <ErrorBox error={error} />
      <Field label="ASN yang sudah tiba">
        <Select
          value={id ?? ""}
          onChange={(e) => {
            setLineId("");
            setLast(undefined);
            navigate(
              e.target.value ? `/receiving/${e.target.value}` : "/receiving",
            );
          }}
        >
          <option value="">Pilih ASN…</option>
          {open.map((r) => (
            <option key={r.id} value={r.id}>
              {r.number} · {r.supplier_name}
            </option>
          ))}
        </Select>
      </Field>
      {a && (
        <>
          <table>
            <thead>
              <tr>
                <th>Produk</th>
                <th>Rencana</th>
                <th>Diterima</th>
              </tr>
            </thead>
            <tbody>
              {a.lines.map((l) => (
                <tr key={l.id}>
                  <td>
                    {l.product_code} · {l.product_name}
                  </td>
                  <td>{l.expected_qty}</td>
                  <td>{l.received_qty}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <Form
            label="Terima"
            onSubmit={async () => {
              setError(undefined);
              const res = await api<{ data: Unit }>(
                `asn-lines/${lineId}/receive`,
                "POST",
                {
                  quantity: Number(qty),
                  lot_no: lot || null,
                  expiry_date: expiry || null,
                  nonconforming: bad,
                  category: bad ? category : null,
                  description: bad ? description : null,
                },
              );
              setLast(res.data);
              setQty("");
              setDescription("");
              asn.refresh();
            }}
          >
            <Field label="Baris">
              <Select
                value={lineId}
                onChange={(e) => setLineId(e.target.value)}
                required
              >
                <option value="">Pilih baris…</option>
                {a.lines
                  .filter((l) => l.received_qty < l.expected_qty)
                  .map((l) => (
                    <option key={l.id} value={l.id}>
                      {l.product_code} · sisa {l.expected_qty - l.received_qty}
                    </option>
                  ))}
              </Select>
            </Field>
            <Field label="Jumlah (per palet/karton)">
              <input
                type="number"
                min={1}
                value={qty}
                onChange={(e) => setQty(e.target.value)}
                required
              />
            </Field>
            <Field label={`Nomor lot${line?.track_lot ? " (wajib)" : ""}`}>
              <input
                value={lot}
                onChange={(e) => setLot(e.target.value)}
                required={line?.track_lot}
                maxLength={60}
              />
            </Field>
            <Field
              label={`Tanggal kedaluwarsa${line?.track_expiry ? " (wajib)" : ""}`}
            >
              <input
                type="date"
                value={expiry}
                onChange={(e) => setExpiry(e.target.value)}
                required={line?.track_expiry}
              />
            </Field>
            <label>
              <input
                type="checkbox"
                checked={bad}
                onChange={(e) => setBad(e.target.checked)}
              />{" "}
              Tidak sesuai (masuk karantina, ISO 9001 §8.7)
            </label>
            {bad && (
              <>
                <Field label="Kategori">
                  <select
                    value={category}
                    onChange={(e) => setCategory(e.target.value)}
                  >
                    {[
                      "DAMAGED",
                      "EXPIRED",
                      "WRONG_ITEM",
                      "QUANTITY",
                      "TEMPERATURE",
                      "OTHER",
                    ].map((c) => (
                      <option key={c}>{c}</option>
                    ))}
                  </select>
                </Field>
                <Field label="Uraian">
                  <textarea
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    required
                    minLength={5}
                    maxLength={1000}
                  />
                </Field>
              </>
            )}
          </Form>
        </>
      )}
      {last && (
        <p role="status">
          Diterima: <strong>{last.lpn}</strong> ({last.status}){" "}
          <button
            onClick={() =>
              api<{ data: Label }>(`stock-units/${last.id}/label`)
                .then((r) => printLabel(r.data))
                .catch(setError)
            }
          >
            Cetak label
          </button>
        </p>
      )}
    </section>
  );
}

type Task = {
  id: string;
  lpn: string;
  quantity: number;
  lot_no: string | null;
  product_code: string;
  product_name: string;
  recommended_location_id: string | null;
  recommended_code: string | null;
};
export function PutawayPage() {
  const session = useSession();
  const tasks = useData<{ data: Task[] }>("putaway-tasks");
  const [error, setError] = useState<unknown>();
  const [override, setOverride] = useState<string>();
  const [reason, setReason] = useState("");
  const manage = can(session, "putaway.execute");
  const done = (p: Promise<unknown>) =>
    p
      .then(() => {
        setOverride(undefined);
        setReason("");
        tasks.refresh();
      })
      .catch(setError);
  return (
    <section className="card">
      <h1 tabIndex={-1}>Putaway</h1>
      <p className="muted">
        Lokasi disarankan sistem; memilih lokasi lain memerlukan alasan.
      </p>
      <ErrorBox error={error ?? tasks.error} />
      {tasks.loading ? (
        <Loading />
      ) : (tasks.data?.data ?? []).length === 0 ? (
        <Empty>Tidak ada tugas putaway.</Empty>
      ) : (
        <table>
          <thead>
            <tr>
              <th>LPN</th>
              <th>Produk</th>
              <th>Jumlah</th>
              <th>Lokasi disarankan</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {tasks.data!.data.map((t) => (
              <tr key={t.id}>
                <td>{t.lpn}</td>
                <td>
                  {t.product_code} · {t.product_name}
                  {t.lot_no && <> · lot {t.lot_no}</>}
                </td>
                <td>{t.quantity}</td>
                <td>{t.recommended_code ?? <em>tidak ada</em>}</td>
                <td>
                  {manage && (
                    <>
                      <button
                        disabled={!t.recommended_location_id}
                        onClick={() =>
                          void done(
                            api(`putaway-tasks/${t.id}/complete`, "POST", {}),
                          )
                        }
                      >
                        Simpan di sini
                      </button>
                      <button
                        onClick={() =>
                          setOverride(override === t.id ? undefined : t.id)
                        }
                      >
                        Lokasi lain
                      </button>
                    </>
                  )}
                  {override === t.id && (
                    <OverrideForm
                      taskId={t.id}
                      reason={reason}
                      setReason={setReason}
                      onDone={done}
                    />
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

function OverrideForm({
  taskId,
  reason,
  setReason,
  onDone,
}: {
  taskId: string;
  reason: string;
  setReason: (v: string) => void;
  onDone: (p: Promise<unknown>) => Promise<void>;
}) {
  const locations = useData<{
    data: { id: string; code: string; recommended: boolean }[];
  }>(`putaway-tasks/${taskId}/locations`);
  const [target, setTarget] = useState("");
  return (
    <Form
      label="Pindahkan"
      onSubmit={() =>
        onDone(
          api(`putaway-tasks/${taskId}/complete`, "POST", {
            location_id: target,
            override_reason: reason,
          }),
        )
      }
    >
      <ErrorBox error={locations.error} />
      <Field label="Lokasi tujuan">
        <Select
          value={target}
          onChange={(e) => setTarget(e.target.value)}
          required
        >
          <option value="">Pilih lokasi…</option>
          {locations.data?.data.map((l) => (
            <option key={l.id} value={l.id}>
              {l.code}
              {l.recommended ? " (disarankan)" : ""}
            </option>
          ))}
        </Select>
      </Field>
      <Field label="Alasan">
        <input
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          required
          minLength={5}
          maxLength={500}
        />
      </Field>
    </Form>
  );
}

type Nc = {
  id: string;
  number: string;
  category: string;
  description: string;
  disposition: string;
  status: string;
  stock_unit_id: string | null;
};

/** Quality control: units in quarantine, decisions (ISO 9001 §8.7) and the non-conformance register. */
export function QcPage() {
  const session = useSession();
  const quarantine = useData<{ data: Unit[] }>("stock-units?status=QUARANTINE");
  const pending = useData<{ data: Unit[] }>(
    "stock-units?status=PENDING_PUTAWAY",
  );
  const ncs = useData<{ data: Nc[] }>(
    can(session, "quality.view") ? "nonconformances" : "master/schema",
  );
  const [error, setError] = useState<unknown>();
  const inspect = can(session, "qc.inspect");
  const decide = (u: Unit, decision: string) => {
    const reason = window.prompt("Alasan keputusan");
    if (!reason) return;
    setError(undefined);
    api(`stock-units/${u.id}/decision`, "POST", { decision, reason })
      .then(() => {
        quarantine.refresh();
        pending.refresh();
        ncs.refresh();
      })
      .catch(setError);
  };
  const table = (
    title: string,
    rows: Unit[],
    actions: (u: Unit) => React.ReactNode,
  ) => (
    <>
      <h2>{title}</h2>
      {rows.length === 0 ? (
        <Empty>Tidak ada.</Empty>
      ) : (
        <table>
          <thead>
            <tr>
              <th>LPN</th>
              <th>Produk</th>
              <th>Lot</th>
              <th>Exp</th>
              <th>Jumlah</th>
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
                <td>{inspect && actions(u)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </>
  );
  return (
    <section className="card">
      <h1 tabIndex={-1}>QC & karantina</h1>
      <ErrorBox error={error} />
      {table("Karantina", quarantine.data?.data ?? [], (u) => (
        <>
          <button onClick={() => decide(u, "RELEASE")}>Lepas</button>
          <button onClick={() => decide(u, "REJECT")}>Tolak</button>
        </>
      ))}
      {table("Menunggu putaway", pending.data?.data ?? [], (u) => (
        <button onClick={() => decide(u, "QUARANTINE")}>Karantina</button>
      ))}
      {can(session, "quality.view") && (
        <>
          <h2>Register ketidaksesuaian</h2>
          {(ncs.data?.data ?? []).length === 0 ? (
            <Empty>Belum ada.</Empty>
          ) : (
            <table>
              <thead>
                <tr>
                  <th>Nomor</th>
                  <th>Kategori</th>
                  <th>Uraian</th>
                  <th>Disposisi</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {ncs.data!.data.map((n) => (
                  <tr key={n.id}>
                    <td>{n.number}</td>
                    <td>{n.category}</td>
                    <td>{n.description}</td>
                    <td>{n.disposition}</td>
                    <td>
                      <Badge value={n.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </>
      )}
    </section>
  );
}
