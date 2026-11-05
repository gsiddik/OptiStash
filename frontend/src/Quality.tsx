import { useState } from "react";
import { Link, useParams } from "react-router-dom";
import { api, useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Field, Form, Loading } from "./components";
import { can } from "./opsTypes";
import { Select } from "./Select";

type Person = { id: number; name: string };
type Capa = {
  id: string;
  number: string;
  type: string;
  title: string;
  description: string;
  status: string;
  due_date: string;
  overdue: boolean;
  owner_id: number;
  root_cause: string | null;
  rca_method: string | null;
  rca: { whys?: string[]; causes?: Record<string, string[]> } | null;
  verification_notes: string | null;
  actions: {
    id: string;
    description: string;
    assignee_id: number;
    assignee_name: string;
    due_date: string;
    done_at: string | null;
  }[];
};
const today = () => new Date().toISOString().slice(0, 10);
const categories = [
  "DAMAGED",
  "EXPIRED",
  "WRONG_ITEM",
  "QUANTITY",
  "TEMPERATURE",
  "OTHER",
];
const fishbone = [
  "MAN",
  "MACHINE",
  "METHOD",
  "MATERIAL",
  "MEASUREMENT",
  "ENVIRONMENT",
];

export function CapaListPage() {
  const session = useSession();
  const list = useData<{ data: Capa[] }>("capas");
  const people = useData<{ data: Person[] }>("quality/people");
  const ncs = useData<{
    data: {
      id: string;
      number: string;
      title: string | null;
      category: string;
    }[];
  }>("nonconformances");
  const manage = can(session, "capa.manage");
  const [open, setOpen] = useState(false);
  const [type, setType] = useState("CORRECTIVE");
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [owner, setOwner] = useState("");
  const [due, setDue] = useState(today());
  const [nc, setNc] = useState("");
  return (
    <section className="card">
      <h1 tabIndex={-1}>CAPA & ketidaksesuaian</h1>
      <ErrorBox error={list.error} />
      {manage && (
        <button className="primary" onClick={() => setOpen((v) => !v)}>
          {open ? "Tutup" : "CAPA baru"}
        </button>
      )}
      {open && (
        <Form
          label="Buat CAPA"
          onSubmit={async () => {
            await api("capas", "POST", {
              type,
              title,
              description,
              owner_id: Number(owner),
              due_date: due,
              nonconformance_id: nc || null,
            });
            setOpen(false);
            list.refresh();
          }}
        >
          <Field label="Jenis">
            <select value={type} onChange={(e) => setType(e.target.value)}>
              <option value="CORRECTIVE">Korektif</option>
              <option value="PREVENTIVE">Preventif</option>
            </select>
          </Field>
          <Field label="Judul">
            <input
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              required
              maxLength={200}
            />
          </Field>
          <Field label="Uraian">
            <textarea
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              required
              maxLength={2000}
            />
          </Field>
          <Field label="Penanggung jawab">
            <Select
              value={owner}
              onChange={(e) => setOwner(e.target.value)}
              required
            >
              <option value="">Pilih…</option>
              {people.data?.data.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Tenggat">
            <input
              type="date"
              min={today()}
              value={due}
              onChange={(e) => setDue(e.target.value)}
              required
            />
          </Field>
          <Field label="Ketidaksesuaian terkait (opsional)">
            <Select value={nc} onChange={(e) => setNc(e.target.value)}>
              <option value="">Tidak ada</option>
              {ncs.data?.data.map((n) => (
                <option key={n.id} value={n.id}>
                  {n.number} · {n.title ?? n.category}
                </option>
              ))}
            </Select>
          </Field>
        </Form>
      )}
      {manage && <NcForm onSaved={() => ncs.refresh()} />}
      {list.loading ? (
        <Loading />
      ) : (list.data?.data ?? []).length === 0 ? (
        <Empty>Belum ada CAPA.</Empty>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Nomor</th>
              <th>Judul</th>
              <th>Jenis</th>
              <th>Tenggat</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {list.data!.data.map((c) => (
              <tr key={c.id}>
                <td>
                  <Link to={`/capas/${c.id}`}>{c.number}</Link>
                </td>
                <td>{c.title}</td>
                <td>{c.type}</td>
                <td>
                  {c.due_date}
                  {c.overdue && <Badge value="TERLAMBAT" />}
                </td>
                <td>
                  <Badge value={c.status} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

function NcForm({ onSaved }: { onSaved: () => void }) {
  const [open, setOpen] = useState(false);
  const [source, setSource] = useState("AUDIT");
  const [category, setCategory] = useState("OTHER");
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  return (
    <details
      open={open}
      onToggle={(e) => setOpen((e.target as HTMLDetailsElement).open)}
    >
      <summary>Catat ketidaksesuaian</summary>
      <Form
        label="Catat"
        onSubmit={async () => {
          await api("nonconformances", "POST", {
            source,
            category,
            title,
            description,
          });
          setTitle("");
          setDescription("");
          onSaved();
        }}
      >
        <Field label="Sumber">
          <select value={source} onChange={(e) => setSource(e.target.value)}>
            {["STOCKTAKE", "OUTBOUND", "AUDIT", "OTHER"].map((s) => (
              <option key={s}>{s}</option>
            ))}
          </select>
        </Field>
        <Field label="Kategori">
          <select
            value={category}
            onChange={(e) => setCategory(e.target.value)}
          >
            {categories.map((s) => (
              <option key={s}>{s}</option>
            ))}
          </select>
        </Field>
        <Field label="Judul">
          <input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            required
            maxLength={200}
          />
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
      </Form>
    </details>
  );
}

export function CapaDetailPage() {
  const { id } = useParams();
  const session = useSession();
  const capa = useData<{ data: Capa }>(`capas/${id}`);
  const people = useData<{ data: Person[] }>("quality/people");
  const [error, setError] = useState<unknown>();
  const [method, setMethod] = useState("FIVE_WHY");
  const [whys, setWhys] = useState(["", "", ""]);
  const [causes, setCauses] = useState<Record<string, string>>({});
  const [root, setRoot] = useState("");
  const [action, setAction] = useState("");
  const [assignee, setAssignee] = useState("");
  const [due, setDue] = useState(today());
  const [notes, setNotes] = useState("");
  if (capa.loading) return <Loading />;
  const c = capa.data?.data;
  if (!c) return <ErrorBox error={capa.error} />;
  const manage = can(session, "capa.manage");
  const run = (p: Promise<unknown>) => {
    setError(undefined);
    p.then(capa.refresh).catch(setError);
  };
  return (
    <section className="card">
      <h1 tabIndex={-1}>
        {c.number} <Badge value={c.status} />
        {c.overdue && <Badge value="TERLAMBAT" />}
      </h1>
      <p>
        {c.title} · {c.type} · tenggat {c.due_date}
      </p>
      <p className="muted">{c.description}</p>
      <ErrorBox error={error ?? capa.error} />
      {c.rca && (
        <div className="card">
          <h2>Analisis akar masalah ({c.rca_method})</h2>
          {c.rca.whys && (
            <ol>
              {c.rca.whys.map((w, i) => (
                <li key={i}>{w}</li>
              ))}
            </ol>
          )}
          {c.rca.causes &&
            Object.entries(c.rca.causes).map(([k, v]) => (
              <p key={k}>
                <strong>{k}</strong>: {v.join("; ")}
              </p>
            ))}
          <p>
            <strong>Akar masalah:</strong> {c.root_cause}
          </p>
        </div>
      )}
      {manage && ["OPEN", "IN_PROGRESS"].includes(c.status) && (
        <Form
          label="Simpan analisis"
          onSubmit={async () => {
            const rca =
              method === "FIVE_WHY"
                ? { whys: whys.filter(Boolean) }
                : {
                    causes: Object.fromEntries(
                      Object.entries(causes)
                        .filter(([, v]) => v.trim())
                        .map(([k, v]) => [k, v.split("\n").filter(Boolean)]),
                    ),
                  };
            await api(`capas/${c.id}/analysis`, "POST", {
              method,
              rca,
              root_cause: root,
            });
            capa.refresh();
          }}
        >
          <Field label="Metode">
            <select value={method} onChange={(e) => setMethod(e.target.value)}>
              <option value="FIVE_WHY">5-Why</option>
              <option value="FISHBONE">Fishbone</option>
            </select>
          </Field>
          {method === "FIVE_WHY" ? (
            <>
              {whys.map((w, i) => (
                <Field label={`Mengapa ${i + 1}`} key={i}>
                  <input
                    value={w}
                    onChange={(e) =>
                      setWhys(
                        whys.map((x, j) => (j === i ? e.target.value : x)),
                      )
                    }
                  />
                </Field>
              ))}
              {whys.length < 7 && (
                <button type="button" onClick={() => setWhys([...whys, ""])}>
                  Tambah
                </button>
              )}
            </>
          ) : (
            fishbone.map((k) => (
              <Field label={`${k} (satu penyebab per baris)`} key={k}>
                <textarea
                  value={causes[k] ?? ""}
                  onChange={(e) =>
                    setCauses({ ...causes, [k]: e.target.value })
                  }
                />
              </Field>
            ))
          )}
          <Field label="Akar masalah">
            <input
              value={root}
              onChange={(e) => setRoot(e.target.value)}
              required
              minLength={5}
              maxLength={1000}
            />
          </Field>
        </Form>
      )}
      <h2>Tindakan</h2>
      {c.actions.length === 0 ? (
        <Empty>Belum ada tindakan.</Empty>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Tindakan</th>
              <th>Penerima</th>
              <th>Tenggat</th>
              <th>Selesai</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {c.actions.map((a) => (
              <tr key={a.id}>
                <td>{a.description}</td>
                <td>{a.assignee_name}</td>
                <td>{a.due_date}</td>
                <td>
                  {a.done_at
                    ? new Date(a.done_at).toLocaleDateString("id-ID")
                    : "–"}
                </td>
                <td>
                  {!a.done_at && a.assignee_id === session.id && (
                    <button
                      onClick={() =>
                        run(api(`capa-actions/${a.id}/complete`, "POST", {}))
                      }
                    >
                      Selesai
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {manage && c.status === "IN_PROGRESS" && (
        <>
          <Form
            label="Tambah tindakan"
            onSubmit={async () => {
              await api(`capas/${c.id}/actions`, "POST", {
                description: action,
                assignee_id: Number(assignee),
                due_date: due,
              });
              setAction("");
              capa.refresh();
            }}
          >
            <Field label="Tindakan">
              <input
                value={action}
                onChange={(e) => setAction(e.target.value)}
                required
                maxLength={500}
              />
            </Field>
            <Field label="Penerima">
              <Select
                value={assignee}
                onChange={(e) => setAssignee(e.target.value)}
                required
              >
                <option value="">Pilih…</option>
                {people.data?.data.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Tenggat">
              <input
                type="date"
                min={today()}
                value={due}
                onChange={(e) => setDue(e.target.value)}
                required
              />
            </Field>
          </Form>
          <Form
            label="Verifikasi efektivitas"
            onSubmit={async () => {
              await api(`capas/${c.id}/verify`, "POST", { notes });
              capa.refresh();
            }}
          >
            <Field label="Catatan verifikasi (bukan oleh penanggung jawab)">
              <textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                required
                minLength={5}
                maxLength={1000}
              />
            </Field>
          </Form>
        </>
      )}
      {manage && c.status === "VERIFIED" && (
        <button
          className="primary"
          onClick={() => run(api(`capas/${c.id}/close`, "POST", {}))}
        >
          Tutup CAPA
        </button>
      )}
    </section>
  );
}

type Instrument = {
  id: string;
  code: string;
  name: string;
  kind: string;
  interval_days: number;
  last_calibrated_on: string | null;
  next_due_on: string | null;
  active: boolean;
  usability: { usable: boolean; reason: string | null };
};

export function InstrumentsPage() {
  const session = useSession();
  const list = useData<{ data: Instrument[] }>("instruments");
  const manage = can(session, "calibration.manage");
  const [error, setError] = useState<unknown>();
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [kind, setKind] = useState("THERMOMETER");
  const [interval, setInterval] = useState("180");
  const calibrate = (i: Instrument) => {
    const certificate = window.prompt("Nomor sertifikat kalibrasi");
    const by = certificate
      ? window.prompt("Dilakukan oleh (lembaga/teknisi)")
      : null;
    if (!certificate || !by) return;
    const pass = window.confirm("Hasil kalibrasi LULUS? (Batal = GAGAL)");
    setError(undefined);
    api(`instruments/${i.id}/calibrations`, "POST", {
      calibrated_on: today(),
      performed_by: by,
      certificate_no: certificate,
      result: pass ? "PASS" : "FAIL",
    })
      .then(list.refresh)
      .catch(setError);
  };
  return (
    <section className="card">
      <h1 tabIndex={-1}>Alat ukur & kalibrasi</h1>
      <p className="muted">
        Alat yang kalibrasinya kedaluwarsa, gagal, atau belum pernah dilakukan
        diblokir dari penerimaan barang berpendingin.
      </p>
      <ErrorBox error={error ?? list.error} />
      {manage && (
        <Form
          label="Daftarkan alat"
          onSubmit={async () => {
            await api("instruments", "POST", {
              code,
              name,
              kind,
              interval_days: Number(interval),
            });
            setCode("");
            setName("");
            list.refresh();
          }}
        >
          <Field label="Kode">
            <input
              value={code}
              onChange={(e) => setCode(e.target.value)}
              required
              maxLength={40}
            />
          </Field>
          <Field label="Nama">
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
              maxLength={200}
            />
          </Field>
          <Field label="Jenis">
            <select value={kind} onChange={(e) => setKind(e.target.value)}>
              {["THERMOMETER", "SCALE", "HYGROMETER", "OTHER"].map((k) => (
                <option key={k}>{k}</option>
              ))}
            </select>
          </Field>
          <Field label="Interval kalibrasi (hari)">
            <input
              type="number"
              min={1}
              max={1825}
              value={interval}
              onChange={(e) => setInterval(e.target.value)}
              required
            />
          </Field>
        </Form>
      )}
      {list.loading ? (
        <Loading />
      ) : (list.data?.data ?? []).length === 0 ? (
        <Empty>Belum ada alat ukur.</Empty>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Kode</th>
              <th>Nama</th>
              <th>Terakhir</th>
              <th>Jatuh tempo</th>
              <th>Status</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {list.data!.data.map((i) => (
              <tr key={i.id}>
                <td>{i.code}</td>
                <td>{i.name}</td>
                <td>{i.last_calibrated_on ?? "–"}</td>
                <td>{i.next_due_on ?? "–"}</td>
                <td>
                  <Badge
                    value={i.usability.usable ? "SIAP PAKAI" : "DIBLOKIR"}
                  />
                  {i.usability.reason && <small> {i.usability.reason}</small>}
                </td>
                <td>
                  {manage && i.active && (
                    <button onClick={() => calibrate(i)}>
                      Catat kalibrasi
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

type Doc = {
  id: string;
  code: string;
  title: string;
  category: string;
  effective_version: number | null;
  effective_on: string | null;
};
type Version = {
  id: string;
  version_no: number;
  status: string;
  content: string;
  checksum: string;
  change_summary: string;
  effective_on: string | null;
  author_id: number;
  author_name: string;
  approver_name: string | null;
};

export function DocumentsPage() {
  const session = useSession();
  const list = useData<{ data: Doc[] }>("documents");
  const manage = can(session, "sop.manage");
  const [code, setCode] = useState("");
  const [title, setTitle] = useState("");
  const [category, setCategory] = useState("SOP");
  const [content, setContent] = useState("");
  return (
    <section className="card">
      <h1 tabIndex={-1}>Dokumen terkendali (SOP)</h1>
      <ErrorBox error={list.error} />
      {manage && (
        <Form
          label="Buat dokumen"
          onSubmit={async () => {
            await api("documents", "POST", {
              code,
              title,
              category,
              content,
              change_summary: "Versi awal",
            });
            setCode("");
            setTitle("");
            setContent("");
            list.refresh();
          }}
        >
          <Field label="Kode">
            <input
              value={code}
              onChange={(e) => setCode(e.target.value)}
              required
              maxLength={40}
            />
          </Field>
          <Field label="Judul">
            <input
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              required
              maxLength={200}
            />
          </Field>
          <Field label="Kategori">
            <select
              value={category}
              onChange={(e) => setCategory(e.target.value)}
            >
              {["SOP", "WORK_INSTRUCTION", "POLICY", "FORM"].map((c) => (
                <option key={c}>{c}</option>
              ))}
            </select>
          </Field>
          <Field label="Isi">
            <textarea
              value={content}
              onChange={(e) => setContent(e.target.value)}
              required
            />
          </Field>
        </Form>
      )}
      {list.loading ? (
        <Loading />
      ) : (list.data?.data ?? []).length === 0 ? (
        <Empty>Belum ada dokumen.</Empty>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Kode</th>
              <th>Judul</th>
              <th>Kategori</th>
              <th>Versi berlaku</th>
            </tr>
          </thead>
          <tbody>
            {list.data!.data.map((d) => (
              <tr key={d.id}>
                <td>
                  <Link to={`/documents/${d.id}`}>{d.code}</Link>
                </td>
                <td>{d.title}</td>
                <td>{d.category}</td>
                <td>
                  {d.effective_version
                    ? `v${d.effective_version} (${d.effective_on})`
                    : "belum ada"}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

export function DocumentDetailPage() {
  const { id } = useParams();
  const session = useSession();
  const doc = useData<{ data: Doc & { versions: Version[] } }>(
    `documents/${id}`,
  );
  const [error, setError] = useState<unknown>();
  const [content, setContent] = useState("");
  if (doc.loading) return <Loading />;
  const d = doc.data?.data;
  if (!d) return <ErrorBox error={doc.error} />;
  const manage = can(session, "sop.manage");
  const run = (p: Promise<unknown>) => {
    setError(undefined);
    p.then(doc.refresh).catch(setError);
  };
  const open = d.versions.some((v) =>
    ["DRAFT", "IN_REVIEW"].includes(v.status),
  );
  return (
    <section className="card">
      <h1 tabIndex={-1}>
        {d.code} · {d.title}
      </h1>
      <ErrorBox error={error ?? doc.error} />
      {manage && !open && (
        <Form
          label="Versi baru"
          onSubmit={async () => {
            await api(`documents/${d.id}/versions`, "POST", {
              content,
              change_summary: "Revisi",
            });
            setContent("");
            doc.refresh();
          }}
        >
          <Field label="Isi versi baru">
            <textarea
              value={content}
              onChange={(e) => setContent(e.target.value)}
              required
            />
          </Field>
        </Form>
      )}
      {d.versions.map((v) => (
        <div className="card" key={v.id}>
          <h2>
            v{v.version_no} <Badge value={v.status} />
          </h2>
          <p className="muted">
            {v.change_summary} · penulis {v.author_name}
            {v.approver_name && <> · disetujui {v.approver_name}</>} · sha256{" "}
            {v.checksum.slice(0, 12)}…
          </p>
          <pre style={{ whiteSpace: "pre-wrap" }}>{v.content}</pre>
          {manage && v.status === "DRAFT" && v.author_id === session.id && (
            <button
              className="primary"
              onClick={() =>
                run(api(`document-versions/${v.id}/submit`, "POST", {}))
              }
            >
              Ajukan review
            </button>
          )}
          {manage && v.status === "IN_REVIEW" && v.author_id !== session.id && (
            <>
              <button
                className="primary"
                onClick={() =>
                  run(api(`document-versions/${v.id}/approve`, "POST", {}))
                }
              >
                Setujui
              </button>
              <button
                onClick={() => {
                  const r = window.prompt("Alasan mengembalikan");
                  if (r)
                    run(
                      api(`document-versions/${v.id}/return`, "POST", {
                        reason: r,
                      }),
                    );
                }}
              >
                Kembalikan
              </button>
            </>
          )}
        </div>
      ))}
    </section>
  );
}

type Evt = {
  id: string;
  action: string;
  subject_id: string;
  metadata: Record<string, unknown>;
  created_at: string;
  actor_name: string;
};

export function AuditPage() {
  const [action, setAction] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const query = new URLSearchParams({
    page: String(page),
    ...(action ? { action } : {}),
    ...(from ? { from } : {}),
    ...(to ? { to } : {}),
  }).toString();
  const events = useData<{ data: Evt[]; last_page: number; total: number }>(
    `audit?${query}`,
  );
  const exportQuery = new URLSearchParams({
    ...(action ? { action } : {}),
    ...(from ? { from } : {}),
    ...(to ? { to } : {}),
  }).toString();
  return (
    <section className="card">
      <h1 tabIndex={-1}>Jejak audit</h1>
      <p className="muted">
        Tidak dapat diubah atau dihapus; dijaga oleh database.
      </p>
      <ErrorBox error={events.error} />
      <div className="actions">
        <label>
          Aksi diawali{" "}
          <input
            value={action}
            onChange={(e) => {
              setAction(e.target.value);
              setPage(1);
            }}
            placeholder="mis. capa."
          />
        </label>
        <label>
          Dari{" "}
          <input
            type="date"
            value={from}
            onChange={(e) => {
              setFrom(e.target.value);
              setPage(1);
            }}
          />
        </label>
        <label>
          Sampai{" "}
          <input
            type="date"
            value={to}
            onChange={(e) => {
              setTo(e.target.value);
              setPage(1);
            }}
          />
        </label>
        <a className="button" href={`/api/v1/audit/export?${exportQuery}`}>
          Ekspor CSV
        </a>
      </div>
      {events.loading ? (
        <Loading />
      ) : (events.data?.data ?? []).length === 0 ? (
        <Empty>Tidak ada peristiwa.</Empty>
      ) : (
        <>
          <table>
            <thead>
              <tr>
                <th>Waktu</th>
                <th>Aktor</th>
                <th>Aksi</th>
                <th>Rincian</th>
              </tr>
            </thead>
            <tbody>
              {events.data!.data.map((e) => (
                <tr key={e.id}>
                  <td>{new Date(e.created_at).toLocaleString("id-ID")}</td>
                  <td>{e.actor_name}</td>
                  <td>{e.action}</td>
                  <td>
                    <code>{JSON.stringify(e.metadata)}</code>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="actions">
            <button disabled={page <= 1} onClick={() => setPage(page - 1)}>
              Sebelumnya
            </button>
            <span>
              Halaman {page} dari {events.data!.last_page} ·{" "}
              {events.data!.total} peristiwa
            </span>
            <button
              disabled={page >= events.data!.last_page}
              onClick={() => setPage(page + 1)}
            >
              Berikutnya
            </button>
          </div>
        </>
      )}
    </section>
  );
}

type Clause = {
  clause: string;
  title: string;
  evidence: string;
  metrics: Record<string, number>;
};

export function IsoMapPage() {
  const map = useData<{ data: Clause[] }>("quality/iso-map");
  return (
    <section className="card">
      <h1 tabIndex={-1}>Pemetaan ISO 9001:2015</h1>
      <p className="muted">
        Fungsi OptiStash yang menjadi bukti tiap klausul, dengan angka terkini
        dari data tenant.
      </p>
      <ErrorBox error={map.error} />
      {map.loading ? (
        <Loading />
      ) : (
        <table>
          <thead>
            <tr>
              <th>Klausul</th>
              <th>Judul</th>
              <th>Bukti</th>
              <th>Angka</th>
            </tr>
          </thead>
          <tbody>
            {map.data?.data.map((c) => (
              <tr key={c.clause}>
                <td>{c.clause}</td>
                <td>{c.title}</td>
                <td>{c.evidence}</td>
                <td>
                  {Object.entries(c.metrics).map(([k, v]) => (
                    <div key={k}>
                      {k.replace(/_/g, " ")}: <strong>{v}</strong>
                    </div>
                  ))}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
