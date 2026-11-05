import { useEffect, useState } from "react";
import { api, useData } from "./api";
import { useSession } from "./App";
import { ErrorBox, Field, Form, Loading } from "./components";
import { can } from "./opsTypes";

type Transport = {
  mode: "OPTIDELI" | "THIRD_PARTY_API" | "MANUAL";
  api_url: string | null;
};

const modes: Record<Transport["mode"], string> = {
  OPTIDELI: "OptiDeli (TMS ekosistem)",
  THIRD_PARTY_API: "API TMS pihak ketiga",
  MANUAL: "Manual (dicatat petugas)",
};

/** How outbound and inbound transport is handled for the tenant. */
export function IntegrationPage() {
  const session = useSession();
  const transport = useData<{ data: Transport }>("integration/transport");
  const [mode, setMode] = useState<Transport["mode"]>("MANUAL");
  const [url, setUrl] = useState("");
  const [saved, setSaved] = useState(false);
  useEffect(() => {
    if (transport.data) {
      setMode(transport.data.data.mode);
      setUrl(transport.data.data.api_url ?? "");
    }
  }, [transport.data]);
  if (transport.loading) return <Loading />;
  const manage = can(session, "integration.manage");
  return (
    <section className="card">
      <h1 tabIndex={-1}>Integrasi transportasi</h1>
      <p className="muted">
        Pilih cara pengiriman keluar dan kedatangan barang dikelola: lewat
        OptiDeli, API TMS pihak ketiga, atau dicatat manual.
      </p>
      <ErrorBox error={transport.error} />
      {saved && <p role="status">Pengaturan disimpan.</p>}
      <Form
        label="Simpan"
        disabled={!manage}
        onSubmit={async () => {
          await api("integration/transport", "PUT", {
            mode,
            api_url: mode === "THIRD_PARTY_API" ? url : null,
          });
          setSaved(true);
          transport.refresh();
        }}
      >
        <Field label="Mode">
          <select
            value={mode}
            disabled={!manage}
            onChange={(e) => {
              setSaved(false);
              setMode(e.target.value as Transport["mode"]);
            }}
          >
            {Object.entries(modes).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </Field>
        {mode === "THIRD_PARTY_API" && (
          <Field label="Alamat API (https)">
            <input
              type="url"
              value={url}
              disabled={!manage}
              onChange={(e) => setUrl(e.target.value)}
              required
              maxLength={300}
            />
          </Field>
        )}
      </Form>
    </section>
  );
}
