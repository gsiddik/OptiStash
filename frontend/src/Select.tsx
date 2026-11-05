import { Children, Fragment, isValidElement } from "react";
import type { ChangeEvent, ReactElement, ReactNode } from "react";
import { SearchSelect } from "./SearchSelect";
import type { SearchOption } from "./SearchSelect";

function textOf(node: ReactNode): string {
  if (node === null || node === undefined || typeof node === "boolean")
    return "";
  if (typeof node === "string" || typeof node === "number") return String(node);
  if (Array.isArray(node)) return node.map(textOf).join("");
  if (isValidElement<{ children?: ReactNode }>(node))
    return textOf(node.props.children);
  return "";
}

/** The <option> elements among the children, however they are produced (mapped, conditional, fragments). */
function optionsOf(children: ReactNode): SearchOption[] {
  const out: SearchOption[] = [];
  Children.forEach(children, (child) => {
    if (!isValidElement(child)) return;
    if (child.type === Fragment) {
      out.push(
        ...optionsOf(
          (child as ReactElement<{ children?: ReactNode }>).props.children,
        ),
      );
    } else if (child.type === "option") {
      const props = child.props as {
        value?: string | number;
        children?: ReactNode;
      };
      const label = textOf(props.children);
      out.push({ value: String(props.value ?? label), label });
    }
  });
  return out;
}

/**
 * A drop-in for a native <select> whose options can be searched by typing. It keeps the shape of the old element (value, onChange(e) with
 * e.target.value, <option> children, required, disabled, aria-label) so a screen only changes the tag. A first <option value=""> becomes the
 * "no choice" row.
 */
export function Select({
  value,
  onChange,
  children,
  required = false,
  disabled = false,
  id,
  "aria-label": ariaLabel,
  "aria-describedby": describedBy,
}: {
  value: string;
  onChange: (event: ChangeEvent<HTMLSelectElement>) => void;
  children?: ReactNode;
  required?: boolean;
  disabled?: boolean;
  id?: string;
  "aria-label"?: string;
  "aria-describedby"?: string;
}) {
  const all = optionsOf(children);
  const empty = all.find((o) => o.value === "");
  return (
    <SearchSelect
      id={id}
      value={value}
      required={required}
      disabled={disabled}
      clearable={empty !== undefined && !required}
      placeholder={empty?.label || "Pilih…"}
      options={all.filter((o) => o.value !== "")}
      ariaLabel={ariaLabel}
      aria-describedby={describedBy}
      onChange={(v) =>
        onChange({
          target: { value: v },
        } as unknown as ChangeEvent<HTMLSelectElement>)
      }
    />
  );
}
