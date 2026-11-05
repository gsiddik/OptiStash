import { useEffect, useState } from "react";
import type { FormEvent } from "react";
import { Navigate, useParams } from "react-router-dom";
import { api, useData } from "./api";
import { useSession } from "./App";
import {
  Badge,
  Confirm,
  Empty,
  ErrorBox,
  Field,
  Loading,
  Pagination,
} from "./components";
import { ImportDialog } from "./MasterImport";
import { can } from "./opsTypes";
import { Action, EditorDialog, submitRecord } from "./RecordEditor";
import type { EditorField } from "./RecordEditor";
import type { Page } from "./types";

type SchemaField = {
  name: string;
  label: string;
  kind:
    | "text"
    | "textarea"
    | "number"
    | "decimal"
    | "select"
    | "boolean"
    | "date"
    | "ref";
  required?: boolean;
  options?: Record<string, string>;
  ref?: string;
  list?: boolean;
  immutable?: boolean;
  /** The system fills it in when the record is saved (customer and project codes): it is not asked for on a new record. */
  generated?: boolean;
  hint?: string;
  default?: string | number | boolean;
};
type Resource = {
  key: string;
  label: string;
  singular: string;
  group: string;
  fields: SchemaField[];
};
type Schema = { groups: Record<string, string>; resources: Resource[] };
type Row = Record<string, unknown> & { id: string; active: boolean };
type RefOption = { id: string; code: string; name: string };

const YES_NO = [
  { value: "1", label: "Ya" },
  { value: "0", label: "Tidak" },
];

function cell(field: SchemaField, row: Row): string {
  const value = row[field.name];
  if (field.kind === "ref") return String(row[`${field.name}_label`] ?? "—");
  if (value === null || value === undefined || value === "") return "—";
  if (field.kind === "select")
    return field.options?.[String(value)] ?? String(value);
  if (field.kind === "boolean") return value ? "Ya" : "Tidak";
  return String(value);
}

/** The editor fields of a master, from its schema; a reference becomes a choice among the active rows of the master it points to. */
function editorFields(
  resource: Resource,
  refs: Record<string, RefOption[]>,
  row?: Row,
): EditorField[] {
  return resource.fields
    .filter((f) => !(row && f.immutable))
    .filter((f) => !(!row && f.generated))
    .map((f): EditorField => {
      const base = {
        name: f.name,
        label: f.label,
        hint: f.hint,
        required: f.required === true,
      };
      const current = row ? row[f.name] : f.default;
      const text = (): string =>
        f.kind === "boolean"
          ? current === undefined || current === null
            ? ""
            : current
              ? "1"
              : "0"
          : current === undefined || current === null
            ? ""
            : String(current);
      switch (f.kind) {
        case "ref": {
          const options = (refs[f.ref ?? ""] ?? []).map((o) => ({
            value: o.id,
            label: `${o.code} · ${o.name}`,
          }));
          const held = row?.[f.name];
          if (
            typeof held === "string" &&
            !options.some((o) => o.value === held)
          )
            options.push({
              value: held,
              label: String(row?.[`${f.name}_label`] ?? held),
            });
          return {
            ...base,
            kind: "select",
            options,
            value: text(),
            searchable: true,
          };
        }
        case "select":
          return {
            ...base,
            kind: "select",
            options: Object.entries(f.options ?? {}).map(([value, label]) => ({
              value,
              label,
            })),
            value: text(),
            searchable: true,
          };
        case "boolean":
          return {
            ...base,
            kind: "select",
            options: YES_NO,
            value: text(),
            searchable: true,
          };
        default:
          return { ...base, kind: f.kind, value: text() };
      }
    });
}

function MasterDialog({
  resource,
  row,
  onClose,
  onDone,
}: {
  resource: Resource;
  row?: Row;
  onClose: () => void;
  onDone: () => void;
}) {
  const [refs, setRefs] = useState<Record<string, RefOption[]>>();
  const [error, setError] = useState<string>();
  useEffect(() => {
    const names = [
      ...new Set(
        resource.fields.filter((f) => f.kind === "ref").map((f) => f.ref!),
      ),
    ];
    Promise.all(
      names.map((n) => api<{ data: RefOption[] }>(`master/${n}/options`)),
    )
      .then((all) =>
        setRefs(Object.fromEntries(names.map((n, i) => [n, all[i].data]))),
      )
      .catch((e: unknown) =>
        setError(
          e instanceof Error ? e.message : "Tidak dapat memuat pilihan.",
        ),
      );
  }, [resource]);
  if (error) return <ErrorBox error={error} />;
  if (!refs) return <Loading />;
  return (
    <EditorDialog
      title={
        row
          ? `Ubah ${resource.singular} ${String(row.code)}`
          : `Tambah ${resource.singular}`
      }
      fields={editorFields(resource, refs, row)}
      note={
        !row && resource.fields.some((f) => f.generated)
          ? "Kode dibuat otomatis oleh sistem saat data disimpan."
          : undefined
      }
      onClose={onClose}
      onSubmit={async (body, key) => {
        await submitRecord(
          row ? `master/${resource.key}/${row.id}` : `master/${resource.key}`,
          body,
          key,
          {},
          row ? "PUT" : "POST",
        );
        onClose();
        onDone();
      }}
    />
  );
}

function MasterList({
  resource,
  canWrite,
}: {
  resource: Resource;
  canWrite: boolean;
}) {
  const [draft, setDraft] = useState("");
  const [q, setQ] = useState("");
  const [active, setActive] = useState("");
  const [page, setPage] = useState(1);
  const state = useData<Page<Row>>(
    `master/${resource.key}?q=${encodeURIComponent(q)}&active=${active}&page=${page}`,
  );
  const session = useSession();
  const [dialog, setDialog] = useState<{ row?: Row } | null>(null);
  const [deactivating, setDeactivating] = useState<Row | null>(null);
  const [reason, setReason] = useState("");
  const [failure, setFailure] = useState<string>();
  const [notice, setNotice] = useState<string>();
  const [loadingRegions, setLoadingRegions] = useState(false);
  const [importing, setImporting] = useState(false);
  const regional = resource.key === "regions" || resource.key === "cities";
  const columns = resource.fields.filter((f) => f.list);

  async function activate(row: Row) {
    setFailure(undefined);
    try {
      await api(`master/${resource.key}/${row.id}/status`, "POST", {
        active: true,
      });
      state.refresh();
    } catch (e) {
      setFailure(e instanceof Error ? e.message : "Tidak dapat mengaktifkan.");
    }
  }

  return (
    <>
      <div className="page-heading">
        <div>
          <h2>{resource.label}</h2>
        </div>
        <div className="actions">
          <a href={`/api/v1/master/${resource.key}/template?format=xlsx`}>
            Template Excel
          </a>
          <a
            href={`/api/v1/master/${resource.key}/export?format=xlsx&q=${encodeURIComponent(q)}&active=${active}`}
          >
            Ekspor Excel
          </a>
          <a
            href={`/api/v1/master/${resource.key}/export?format=csv&q=${encodeURIComponent(q)}&active=${active}`}
          >
            Ekspor CSV
          </a>
        </div>
        {canWrite && (
          <div className="actions">
            <button onClick={() => setImporting(true)}>
              Impor Excel / CSV
            </button>
            {regional && (
              <button onClick={() => setLoadingRegions(true)}>
                Muat data wilayah resmi
              </button>
            )}
            <button className="primary" onClick={() => setDialog({})}>
              + Tambah {resource.singular}
            </button>
          </div>
        )}
      </div>
      <form
        className="actions"
        role="search"
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          setQ(draft.trim());
          setPage(1);
        }}
      >
        <input
          aria-label={`Cari ${resource.label}`}
          placeholder="Cari kode atau nama…"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
        />
        <select
          aria-label="Status"
          value={active}
          onChange={(e) => {
            setActive(e.target.value);
            setPage(1);
          }}
        >
          <option value="">Semua status</option>
          <option value="1">Aktif</option>
          <option value="0">Nonaktif</option>
        </select>
        <button type="submit">Cari</button>
      </form>
      <ErrorBox error={state.error ?? failure} />
      {notice && (
        <div className="alert success" role="status">
          {notice}
        </div>
      )}
      <section className="card">
        {state.loading ? (
          <Loading />
        ) : (
          state.data && (
            <>
              <div className="table-scroll">
                <table>
                  <thead>
                    <tr>
                      {columns.map((f) => (
                        <th key={f.name}>{f.label}</th>
                      ))}
                      <th>Status</th>
                      {canWrite && <th>Aksi</th>}
                    </tr>
                  </thead>
                  <tbody>
                    {state.data.data.map((row) => (
                      <tr key={row.id}>
                        {columns.map((f) => (
                          <td key={f.name}>{cell(f, row)}</td>
                        ))}
                        <td>
                          <Badge value={row.active ? "ACTIVE" : "INACTIVE"} />
                        </td>
                        {canWrite && (
                          <td>
                            <div className="actions">
                              <button onClick={() => setDialog({ row })}>
                                Ubah
                              </button>
                              {resource.key === "customers" &&
                                row.active &&
                                can(session, "customer.portal.provision") && (
                                  <Action
                                    label={`Akun portal ${row.code}`}
                                    path={`customers/${row.id}/portal-account`}
                                    fields={[
                                      {
                                        name: "name",
                                        label: "Nama pengguna pelanggan",
                                      },
                                      {
                                        name: "email",
                                        label: "Email akun baru",
                                      },
                                      {
                                        name: "password",
                                        label:
                                          "Kata sandi awal (min. 12 karakter, huruf besar/kecil, angka, simbol)",
                                        kind: "password",
                                      },
                                    ]}
                                    onDone={state.refresh}
                                  />
                                )}
                              {row.active ? (
                                <button
                                  onClick={() => {
                                    setReason("");
                                    setDeactivating(row);
                                  }}
                                >
                                  Nonaktifkan
                                </button>
                              ) : (
                                <button onClick={() => void activate(row)}>
                                  Aktifkan
                                </button>
                              )}
                            </div>
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!state.data.data.length && (
                <Empty>
                  {q || active
                    ? "Tidak ada data yang cocok dengan pencarian."
                    : `Belum ada ${resource.singular}.`}
                </Empty>
              )}
              <Pagination page={state.data} onPage={setPage} />
            </>
          )
        )}
      </section>
      {loadingRegions && (
        <Confirm
          title="Muat data wilayah resmi?"
          onClose={() => setLoadingRegions(false)}
          onConfirm={async () => {
            const result = await api<{
              data: {
                regions_created: number;
                regions_existing: number;
                cities_created: number;
                cities_existing: number;
              };
            }>("master/regional-reference", "POST", {});
            const r = result.data;
            setNotice(
              `Ditambahkan ${r.regions_created} provinsi dan ${r.cities_created} kota/kabupaten; ${r.regions_existing + r.cities_existing} sudah ada dan tidak diubah.`,
            );
            state.refresh();
          }}
        >
          <p>
            Menambahkan 38 provinsi dan 514 kabupaten/kota dengan kode
            Kemendagri (Kepmendagri No 300.2.2-2138 Tahun 2025). Data yang sudah
            ada tidak diubah; aman dijalankan berulang.
          </p>
        </Confirm>
      )}
      {importing && (
        <ImportDialog
          uploadPath={`master/${resource.key}/imports`}
          label={resource.label}
          onClose={() => setImporting(false)}
          onDone={state.refresh}
        />
      )}
      {dialog && (
        <MasterDialog
          resource={resource}
          row={dialog.row}
          onClose={() => setDialog(null)}
          onDone={state.refresh}
        />
      )}
      {deactivating && (
        <Confirm
          title={`Nonaktifkan ${resource.singular} ${String(deactivating.code)}?`}
          onClose={() => setDeactivating(null)}
          onConfirm={async () => {
            await api(
              `master/${resource.key}/${deactivating.id}/status`,
              "POST",
              {
                active: false,
                reason,
              },
            );
            state.refresh();
          }}
        >
          <p>
            Data yang sudah dipakai tidak dihapus; ia hanya tidak lagi muncul
            sebagai pilihan.
          </p>
          <Field label="Alasan (minimal 5 karakter)">
            <textarea
              required
              minLength={5}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
          </Field>
        </Confirm>
      )}
    </>
  );
}

export function MasterPage() {
  const session = useSession();
  const params = useParams();
  const schema = useData<{ data: Schema }>("master/schema");
  const canWrite = can(session, "master.manage");
  const resources = schema.data?.data.resources ?? [];
  const resource = resources.find((r) => r.key === params.resource);
  // The list is chosen in the sidebar (Master data > group > list); the bare address opens the first one.
  if (schema.data && !resource && resources.length > 0)
    return <Navigate to={`/master/${resources[0].key}`} replace />;
  const group = resource ? schema.data?.data.groups[resource.group] : undefined;
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">
            MASTER DATA{group ? ` · ${group.toUpperCase()}` : ""}
          </span>
          <h1 tabIndex={-1}>{resource?.label ?? "Master data"}</h1>
          <p>
            Pelanggan, lokasi, wilayah, tipe armada, barang, layanan, dan kode
            biaya yang menjadi acuan order, tarif, dan biaya. Pilih daftar lewat
            menu Master data di samping.
          </p>
        </div>
      </div>
      <ErrorBox error={schema.error} />
      {schema.loading && <Loading />}
      {schema.data && resource && (
        <MasterList
          key={resource.key}
          resource={resource}
          canWrite={canWrite}
        />
      )}
    </>
  );
}
