import {
  Component,
  cloneElement,
  useEffect,
  useId,
  useRef,
  useState,
} from "react";
import type { ReactElement, ReactNode, FormEvent } from "react";
import { ApiError } from "./api";
import type { Page } from "./types";
export function ErrorBox({ error }: { error: unknown }) {
  if (!error) return null;
  const message = error instanceof Error ? error.message : String(error);
  const fields = error instanceof ApiError ? Object.entries(error.fields) : [];
  return (
    <div className="alert danger" role="alert">
      <strong>{message}</strong>
      {fields.length > 0 && (
        <ul>
          {fields.map(([name, errors]) => (
            <li key={name}>{errors.join(" ")}</li>
          ))}
        </ul>
      )}
    </div>
  );
}
/**
 * Keeps a screen that fails to render from blanking the whole application: the navigation stays, the failure is shown with its
 * message, and moving to another screen (a new `key`) or reloading tries again.
 */
export class ErrorBoundary extends Component<
  { children: ReactNode },
  { error: Error | null }
> {
  state: { error: Error | null } = { error: null };
  static getDerivedStateFromError(error: Error) {
    return { error };
  }
  componentDidCatch(error: Error) {
    console.error("OptiStash screen failed to render", error);
  }
  render() {
    if (!this.state.error) return this.props.children;
    return (
      <div className="alert danger" role="alert">
        <strong>Halaman ini tidak dapat ditampilkan.</strong>
        <p>{this.state.error.message}</p>
        <div className="actions">
          <button onClick={() => window.location.reload()}>
            Muat ulang halaman
          </button>
        </div>
      </div>
    );
  }
}
export function Loading() {
  return (
    <div className="empty" role="status">
      Memuat data…
    </div>
  );
}
export function Empty({ children }: { children: ReactNode }) {
  return <div className="empty">{children}</div>;
}
export function Badge({ value }: { value: string }) {
  return (
    <span className={`badge ${value.toLowerCase()}`}>
      {value.replaceAll("_", " ")}
    </span>
  );
}
export function Field({
  label,
  children,
  hint,
}: {
  label: string;
  children: ReactElement<{ id?: string; "aria-describedby"?: string }>;
  hint?: string;
}) {
  const id = useId();
  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      {cloneElement(children, {
        id,
        ...(hint ? { "aria-describedby": `${id}-hint` } : {}),
      })}
      {hint && <small id={`${id}-hint`}>{hint}</small>}
    </div>
  );
}
export function Pagination<T>({
  page,
  onPage,
}: {
  page: Page<T>;
  onPage: (v: number) => void;
}) {
  return (
    <div className="pagination">
      <span>
        {page.total} data · Halaman {page.current_page} dari {page.last_page}
      </span>
      <div className="actions">
        <button
          disabled={page.current_page <= 1}
          onClick={() => onPage(page.current_page - 1)}
        >
          Sebelumnya
        </button>
        <button
          disabled={page.current_page >= page.last_page}
          onClick={() => onPage(page.current_page + 1)}
        >
          Berikutnya
        </button>
      </div>
    </div>
  );
}
export function Form({
  children,
  onSubmit,
  label = "Simpan",
  disabled = false,
}: {
  children: ReactNode;
  onSubmit: () => Promise<void>;
  label?: string;
  disabled?: boolean;
}) {
  const [error, setError] = useState<unknown>();
  const [busy, setBusy] = useState(false);
  async function submit(e: FormEvent) {
    e.preventDefault();
    if (busy) return;
    setBusy(true);
    setError(undefined);
    try {
      await onSubmit();
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }
  return (
    <form onSubmit={submit}>
      <ErrorBox error={error} />
      {children}
      <div className="form-footer">
        <button className="primary" disabled={busy || disabled} type="submit">
          {busy ? "Memproses…" : label}
        </button>
      </div>
    </form>
  );
}
export function Confirm({
  title,
  children,
  onConfirm,
  onClose,
}: {
  title: string;
  children: ReactNode;
  onConfirm: () => Promise<void>;
  onClose: () => void;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const previous = document.activeElement as HTMLElement;
    const d = dialog.current!;
    d.showModal();
    return () => {
      d.close();
      previous?.focus();
    };
  }, []);
  return (
    <dialog ref={dialog} onCancel={onClose} aria-labelledby="confirm-title">
      <h2 id="confirm-title">{title}</h2>
      <Form
        label="Ya, lanjutkan"
        onSubmit={async () => {
          await onConfirm();
          onClose();
        }}
      >
        {children}
        <button
          className="close"
          type="button"
          onClick={onClose}
          aria-label="Tutup dialog"
        >
          ×
        </button>
      </Form>
    </dialog>
  );
}
export function Icon({ name }: { name: string }) {
  const paths: Record<string, string> = {
    home: "M3 11 12 3l9 8M5 10v11h14V10M9 21v-7h6v7",
    orders: "M6 3h12v18H6zM9 7h6M9 11h6M9 15h4",
    dispatch:
      "M3 6h11v12H3zM14 10h4l3 4v4h-7M7 21a2 2 0 1 0 0-4 2 2 0 0 0 0 4M18 21a2 2 0 1 0 0-4 2 2 0 0 0 0 4",
    fleet:
      "M5 17H3v-6l2-7h14l2 7v6h-2M3 11h18M7 17h10M6 14h1m10 0h1M5 17v3m14-3v3",
    audit: "M4 4h16v17H4zM8 8h8M8 12h8M8 16h5",
    roadmap: "M4 6h16M4 12h16M4 18h16M8 3v6m8 0v6m-8 0v6",
    master:
      "M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3zM4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6",
    menu: "M4 6h16M4 12h16M4 18h16",
    back: "m10 5-7 7 7 7M3 12h18",
  };
  return (
    <svg
      width="21"
      height="21"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.7"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d={paths[name] ?? paths.roadmap} />
    </svg>
  );
}
