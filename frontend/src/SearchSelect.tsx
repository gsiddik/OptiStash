import { useEffect, useId, useMemo, useRef, useState } from "react";
import type { KeyboardEvent } from "react";

export type SearchOption = { value: string; label: string };

/**
 * A dropdown whose options can be searched by typing (a combobox with a listbox). It replaces a native select where the list is long
 * (cities, zones, customers, locations). The page owner gives it `id` and `aria-describedby` through Field; a hidden native input keeps
 * the browser's `required` check working inside a form.
 */
export function SearchSelect({
  id,
  value,
  onChange,
  options,
  required = false,
  clearable = !required,
  placeholder = "Pilih…",
  disabled = false,
  ariaLabel,
  "aria-describedby": describedBy,
}: {
  id?: string;
  value: string;
  onChange: (value: string) => void;
  options: SearchOption[];
  required?: boolean;
  /** Offers a first row that clears the choice (default: when the field is not required). */
  clearable?: boolean;
  placeholder?: string;
  disabled?: boolean;
  ariaLabel?: string;
  "aria-describedby"?: string;
}) {
  const generated = useId();
  const inputId = id ?? generated;
  const listId = `${inputId}-list`;
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [active, setActive] = useState(0);
  const box = useRef<HTMLDivElement>(null);

  const selected = options.find((o) => o.value === value);
  const shown = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return needle === ""
      ? options
      : options.filter((o) => o.label.toLowerCase().includes(needle));
  }, [options, query]);
  // The first row is "no choice" for an optional field, so it can be cleared again.
  const rows: SearchOption[] = clearable
    ? [{ value: "", label: placeholder }, ...shown]
    : shown;

  useEffect(() => {
    if (!open) return;
    const away = (e: MouseEvent) => {
      if (!box.current?.contains(e.target as Node)) close();
    };
    document.addEventListener("mousedown", away);
    return () => document.removeEventListener("mousedown", away);
  }, [open]);

  function close() {
    setOpen(false);
    setQuery("");
  }
  function pick(option: SearchOption) {
    onChange(option.value);
    close();
  }
  function key(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      e.preventDefault();
      if (!open) {
        setOpen(true);
        return;
      }
      const step = e.key === "ArrowDown" ? 1 : -1;
      setActive((a) => Math.min(Math.max(a + step, 0), rows.length - 1));
    } else if (e.key === "Enter" && open) {
      e.preventDefault(); // the form is not submitted by choosing a row
      if (rows[active]) pick(rows[active]);
    } else if (e.key === "Escape" && open) {
      e.preventDefault();
      e.stopPropagation(); // a dialog around this field is not closed by it
      close();
    }
  }

  return (
    <div className="search-select" ref={box}>
      <input
        id={inputId}
        role="combobox"
        aria-expanded={open}
        aria-controls={listId}
        aria-autocomplete="list"
        aria-activedescendant={open ? `${inputId}-opt-${active}` : undefined}
        aria-describedby={describedBy}
        aria-label={ariaLabel}
        disabled={disabled}
        autoComplete="off"
        placeholder={placeholder}
        value={open ? query : (selected?.label ?? "")}
        onFocus={() => {
          setQuery("");
          setActive(0);
          setOpen(true);
        }}
        onChange={(e) => {
          setQuery(e.target.value);
          setActive(clearable ? 1 : 0);
          setOpen(true);
        }}
        onKeyDown={key}
        onClick={() => setOpen(true)}
      />
      {/* Not focusable and not read out: only there so a required, still empty field blocks the form like a native select. */}
      <input
        className="search-select-required"
        tabIndex={-1}
        aria-hidden="true"
        required={required}
        value={value}
        onChange={() => undefined}
      />
      {open && (
        <ul id={listId} role="listbox" className="search-select-list">
          {rows.map((o, i) => (
            <li
              key={`${o.value}-${i}`}
              id={`${inputId}-opt-${i}`}
              role="option"
              data-value={o.value}
              aria-selected={o.value === value}
              className={i === active ? "active" : undefined}
              onMouseDown={(e) => {
                e.preventDefault(); // keeps the focus in the input until the row is chosen
                pick(o);
              }}
              onMouseEnter={() => setActive(i)}
            >
              {o.label}
            </li>
          ))}
          {shown.length === 0 && (
            <li role="presentation" className="empty">
              Tidak ada yang cocok
            </li>
          )}
        </ul>
      )}
    </div>
  );
}
