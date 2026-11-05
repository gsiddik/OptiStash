import { useEffect, useRef, useState } from "react";
import type { ChangeEvent } from "react";
import { api, ApiError } from "./api";
import { Badge, Empty, ErrorBox, Loading, Pagination } from "./components";
import type { Page } from "./types";

type Job = {
  id: string;
  resource: string;
  filename: string;
  status: "PREVIEW" | "COMMITTED" | "DISCARDED";
  total_rows: number;
  rows_new: number;
  rows_update: number;
  rows_unchanged: number;
  rows_error: number;
  ignored_columns: string[];
  already_imported_at: string | null;
  result: {
    created: number;
    updated: number;
    unchanged: number;
    skipped_errors: number;
  } | null;
};
type JobRow = {
  row: number;
  action: "CREATE" | "UPDATE" | "NONE" | "ERROR";
  code: string | null;
  name: string | null;
  errors: Record<string, string[]> | null;
};

const ACTION: Record<JobRow["action"], string> = {
  CREATE: "Baru",
  UPDATE: "Ubah",
  NONE: "Tanpa perubahan",
  ERROR: "Gagal",
};

function RowsTable({ job }: { job: Job }) {
  const [filter, setFilter] = useState(job.rows_error ? "ERROR" : "");
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState<Page<JobRow>>();
  const [error, setError] = useState<string>();
  useEffect(() => {
    let live = true;
    api<Page<JobRow>>(
      `master/imports/${job.id}/rows?action=${filter}&page=${page}`,
    )
      .then((r) => live && setRows(r))
      .catch((e: unknown) => live && setError((e as Error).message));
    return () => {
      live = false;
    };
  }, [job.id, filter, page]);
  return (
    <>
      <select
        aria-label="Tampilkan baris"
        value={filter}
        onChange={(e) => {
          setFilter(e.target.value);
          setPage(1);
        }}
      >
        <option value="">Semua baris</option>
        {Object.entries(ACTION).map(([k, v]) => (
          <option key={k} value={k}>
            {v}
          </option>
        ))}
      </select>
      <ErrorBox error={error} />
      {!rows ? (
        <Loading />
      ) : (
        <>
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Baris</th>
                  <th>Kode</th>
                  <th>Nama</th>
                  <th>Hasil</th>
                  <th>Keterangan</th>
                </tr>
              </thead>
              <tbody>
                {rows.data.map((r) => (
                  <tr key={r.row}>
                    <td>{r.row}</td>
                    <td>{r.code}</td>
                    <td>{r.name}</td>
                    <td>{ACTION[r.action]}</td>
                    <td>
                      {r.errors ? Object.values(r.errors).flat().join(" ") : ""}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {!rows.data.length && <Empty>Tidak ada baris.</Empty>}
          <Pagination page={rows} onPage={setPage} />
        </>
      )}
    </>
  );
}

export function ImportDialog({
  uploadPath,
  label,
  onClose,
  onDone,
}: {
  uploadPath: string;
  label: string;
  onClose: () => void;
  onDone: () => void;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  const [job, setJob] = useState<Job>();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string>();
  const [skip, setSkip] = useState(false);
  useEffect(() => {
    const previous = document.activeElement as HTMLElement;
    const dialog = ref.current!;
    dialog.showModal();
    return () => {
      dialog.close();
      previous?.focus();
    };
  }, []);

  async function run<T>(work: () => Promise<T>) {
    setBusy(true);
    setError(undefined);
    try {
      return await work();
    } catch (e) {
      const fields =
        e instanceof ApiError ? Object.values(e.fields).flat() : [];
      setError(
        fields.length
          ? fields.join(" ")
          : e instanceof Error
            ? e.message
            : "Permintaan gagal.",
      );
    } finally {
      setBusy(false);
    }
  }

  async function pick(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    const form = new FormData();
    form.append("file", file);
    const r = await run(() => api<{ data: Job }>(uploadPath, "POST", form));
    if (r) setJob(r.data);
  }

  async function apply() {
    const r = await run(() =>
      api<{ data: Job }>(`master/imports/${job!.id}/commit`, "POST", {
        skip_errors: skip,
      }),
    );
    if (r) {
      setJob(r.data);
      onDone();
    }
  }

  async function close() {
    if (job?.status === "PREVIEW") {
      await api(`master/imports/${job.id}/discard`, "POST", {}).catch(
        () => undefined,
      );
    }
    onClose();
  }

  const blocked = !!job && job.rows_error > 0 && !skip;
  const nothing = !!job && job.rows_new + job.rows_update === 0;
  return (
    <dialog
      className="record-dialog"
      ref={ref}
      aria-labelledby="import-title"
      onCancel={() => void close()}
    >
      <h2 id="import-title">Impor {label} dari Excel / CSV</h2>
      <button
        type="button"
        className="close"
        onClick={() => void close()}
        aria-label="Tutup dialog"
      >
        ×
      </button>
      <ErrorBox error={error} />
      {!job && (
        <>
          <p>
            Unduh template, isi, lalu unggah berkas .xlsx atau .csv (maks. 5 MB,
            5.000 baris). Hasilnya ditampilkan dulu sebagai pratinjau; data baru
            ditulis setelah Anda menekan terapkan. Kode yang sudah ada
            diperbarui; sel kosong tidak mengubah nilai yang ada.
          </p>
          <label className="field">
            <span>Berkas</span>
            <input
              type="file"
              aria-label="Berkas impor"
              accept=".xlsx,.csv"
              disabled={busy}
              onChange={(e) => void pick(e)}
            />
          </label>
          {busy && <Loading />}
        </>
      )}
      {job && (
        <>
          <p>
            <strong>{job.filename}</strong> · {job.total_rows} baris ·{" "}
            <Badge value={job.status} />
          </p>
          {job.already_imported_at && job.status === "PREVIEW" && (
            <div className="alert" role="status">
              Berkas yang sama sudah pernah diterapkan pada{" "}
              {new Date(job.already_imported_at).toLocaleString("id-ID")}.
            </div>
          )}
          {job.ignored_columns.length > 0 && (
            <div className="alert" role="status">
              Kolom diabaikan: {job.ignored_columns.join(", ")}.
            </div>
          )}
          <ul className="summary">
            <li>Baru: {job.rows_new}</li>
            <li>Diubah: {job.rows_update}</li>
            <li>Tanpa perubahan: {job.rows_unchanged}</li>
            <li>Gagal: {job.rows_error}</li>
          </ul>
          {job.status === "COMMITTED" && job.result && (
            <div className="alert success" role="status">
              Diterapkan: {job.result.created} ditambahkan, {job.result.updated}{" "}
              diubah, {job.result.skipped_errors} dilewati.
            </div>
          )}
          {job.status === "PREVIEW" && (
            <>
              <RowsTable job={job} />
              {job.rows_error > 0 && (
                <>
                  <a
                    href={`/api/v1/master/imports/${job.id}/errors?format=xlsx`}
                  >
                    Unduh baris yang gagal
                  </a>
                  <label>
                    <input
                      type="checkbox"
                      checked={skip}
                      onChange={(e) => setSkip(e.target.checked)}
                    />{" "}
                    Lewati baris yang gagal dan terapkan sisanya
                  </label>
                </>
              )}
              <div className="actions">
                <button
                  className="primary"
                  disabled={busy || blocked || nothing}
                  onClick={() => void apply()}
                >
                  Terapkan impor
                </button>
                <button onClick={() => void close()}>Batal</button>
              </div>
            </>
          )}
          {job.status !== "PREVIEW" && (
            <div className="actions">
              <button onClick={onClose}>Tutup</button>
            </div>
          )}
        </>
      )}
    </dialog>
  );
}
