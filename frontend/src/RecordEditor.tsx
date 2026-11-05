import { useEffect, useRef, useState } from "react";
import { api } from "./api";
import { newId } from "./uuid";
import { Field, Form } from "./components";
import { minor } from "./commercial";
import { SearchSelect } from "./SearchSelect";
export type EditorField = {
  name: string;
  label: string;
  kind?:
    | "text"
    | "textarea"
    | "number"
    | "decimal"
    | "money"
    | "signedMoney"
    | "datetime"
    | "date"
    | "select"
    | "file"
    | "password";
  value?: string;
  required?: boolean;
  options?: { value: string; label: string }[];
  hint?: string;
  /** Every select can be searched by typing; false keeps a plain native select. */
  searchable?: boolean;
};
export function RecordEditor({
  fields,
  onSubmit,
  label = "Simpan",
}: {
  fields: EditorField[];
  onSubmit: (body: Record<string, unknown>, key: string) => Promise<void>;
  label?: string;
}) {
  const [values, setValues] = useState<Record<string, string | File | null>>(
    () => Object.fromEntries(fields.map((f) => [f.name, f.value ?? ""])),
  );
  const [key, setKey] = useState(() => newId());
  function change(name: string, value: string | File | null) {
    setValues((v) => ({ ...v, [name]: value }));
    setKey(newId());
  }
  return (
    <Form
      label={label}
      onSubmit={async () => {
        const body: Record<string, unknown> = {};
        for (const f of fields) {
          const v = values[f.name];
          if (
            !f.required &&
            f.required !== undefined &&
            (v === "" || v === null)
          ) {
            body[f.name] = null;
            continue;
          }
          switch (f.kind) {
            case "money":
              body[f.name] = minor(String(v));
              break;
            case "signedMoney": {
              const raw = String(v);
              body[f.name] = raw.startsWith("-")
                ? -minor(raw.slice(1))
                : minor(raw);
              break;
            }
            case "number":
            case "decimal":
              body[f.name] = Number(v);
              break;
            case "datetime":
              body[f.name] = new Date(String(v)).toISOString();
              break;
            case "file":
              if (!(v instanceof File) || v.size > 10 * 1024 * 1024)
                throw new Error("Lampirkan PDF/JPEG/PNG maksimal 10 MB.");
              body[f.name] = v;
              break;
            default:
              body[f.name] = v;
          }
        }
        await onSubmit(body, key);
      }}
    >
      <div className="form-grid">
        {fields.map((f) => (
          <Field key={f.name} label={f.label} hint={f.hint}>
            {f.kind === "select" && f.searchable !== false ? (
              <SearchSelect
                required={f.required !== false}
                value={String(values[f.name] ?? "")}
                options={f.options ?? []}
                onChange={(v) => change(f.name, v)}
              />
            ) : f.kind === "select" ? (
              <select
                required={f.required !== false}
                value={String(values[f.name] ?? "")}
                onChange={(e) => change(f.name, e.target.value)}
              >
                <option value="">Pilih…</option>
                {f.options?.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            ) : f.kind === "textarea" ? (
              <textarea
                required={f.required !== false}
                value={String(values[f.name] ?? "")}
                onChange={(e) => change(f.name, e.target.value)}
              />
            ) : f.kind === "file" ? (
              <input
                type="file"
                required={f.required !== false}
                accept=".pdf,.jpg,.jpeg,.png"
                onChange={(e) => change(f.name, e.target.files?.[0] ?? null)}
              />
            ) : (
              <input
                required={f.required !== false}
                autoComplete={f.kind === "password" ? "new-password" : "off"}
                type={
                  f.kind === "number" || f.kind === "decimal"
                    ? "number"
                    : f.kind === "datetime"
                      ? "datetime-local"
                      : f.kind === "date"
                        ? "date"
                        : f.kind === "password"
                          ? "password"
                          : "text"
                }
                inputMode={
                  ["money", "signedMoney"].includes(f.kind ?? "")
                    ? "decimal"
                    : undefined
                }
                min={f.kind === "number" ? 0 : undefined}
                step={
                  f.kind === "number"
                    ? 1
                    : f.kind === "decimal"
                      ? "any"
                      : undefined
                }
                value={String(values[f.name] ?? "")}
                onChange={(e) => change(f.name, e.target.value)}
              />
            )}
          </Field>
        ))}
      </div>
    </Form>
  );
}
export async function submitRecord(
  path: string,
  body: Record<string, unknown>,
  key: string,
  fixed: Record<string, unknown> = {},
  method: "POST" | "PUT" = "POST",
) {
  const data = { ...body, ...fixed };
  if (Object.values(data).some((v) => v instanceof File)) {
    const form = new FormData();
    for (const [k, v] of Object.entries(data)) {
      if (v !== null && v !== undefined)
        form.append(k, v instanceof File ? v : String(v));
    }
    return api(path, method, form, key);
  }
  return api(path, method, data, key);
}
export function Action({
  label,
  path,
  fields = [],
  fixed = {},
  onDone,
  primary = false,
}: {
  label: string;
  path: string;
  fields?: EditorField[];
  fixed?: Record<string, unknown>;
  onDone: () => void;
  primary?: boolean;
}) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button
        className={primary ? "primary" : ""}
        onClick={() => setOpen(true)}
      >
        {label}
      </button>
      {open && (
        <EditorDialog
          title={label}
          fields={fields}
          onClose={() => setOpen(false)}
          onSubmit={async (body, key) => {
            await submitRecord(path, body, key, fixed);
            setOpen(false);
            onDone();
          }}
        />
      )}
    </>
  );
}
export function EditorDialog({
  title,
  fields,
  onClose,
  onSubmit,
  note,
}: {
  title: string;
  fields: EditorField[];
  onClose: () => void;
  onSubmit: (body: Record<string, unknown>, key: string) => Promise<void>;
  note?: string;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const previous = document.activeElement as HTMLElement;
    const dialog = ref.current!;
    dialog.showModal();
    return () => {
      dialog.close();
      previous?.focus();
    };
  }, []);
  return (
    <dialog
      className="record-dialog"
      ref={ref}
      aria-labelledby="record-title"
      onCancel={onClose}
    >
      <h2 id="record-title">{title}</h2>
      <button
        type="button"
        className="close"
        onClick={onClose}
        aria-label="Tutup dialog"
      >
        ×
      </button>
      {note && <p className="muted">{note}</p>}
      <RecordEditor
        fields={fields}
        onSubmit={onSubmit}
        label="Simpan & lanjutkan"
      />
    </dialog>
  );
}
export const noteField: EditorField = {
  name: "note",
  label: "Catatan tinjauan",
  kind: "textarea",
  hint: "Jelaskan dasar pemeriksaan dan keputusan Anda.",
};
