import { useState } from "react";
import { api, useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Field, Form, Loading } from "./components";
import { can } from "./opsTypes";
import { Select } from "./Select";

type Status = {
  deployment_mode: string;
  identity_mode: string;
  standalone: boolean;
  transport: { mode: string; api_url: string | null };
  outbox: {
    pending: number;
    failed: number;
    delivered: number;
    last_error: string | null;
  };
  iot: { gateways: number; devices: number; open_alerts: number };
};

/** Where events go: standalone switch, transport route, outbox figures, and the force sync. */
export function OrchestratorPage() {
  const session = useSession();
  const status = useData<{ data: Status }>("integration/status");
  const [error, setError] = useState<unknown>();
  const [message, setMessage] = useState<string>();
  const manage = can(session, "integration.manage");
  if (status.loading) return <Loading />;
  const s = status.data?.data;
  if (!s) return <ErrorBox error={status.error} />;
  return (
    <section className="card">
      <h1 tabIndex={-1}>Orkestrasi integrasi</h1>
      <ErrorBox error={error ?? status.error} />
      {message && <p role="status">{message}</p>}
      <table>
        <tbody>
          <tr>
            <th>Mode penerapan</th>
            <td>
              {s.deployment_mode === "saas"
                ? "SaaS (dikelola OptiNexus)"
                : "Mandiri"}
            </td>
          </tr>
          <tr>
            <th>Identitas</th>
            <td>{s.identity_mode === "optinexus" ? "OptiNexus" : "Lokal"}</td>
          </tr>
          <tr>
            <th>Transportasi</th>
            <td>
              {s.transport.mode}
              {s.transport.api_url && <> · {s.transport.api_url}</>}
            </td>
          </tr>
          <tr>
            <th>Antrean keluar</th>
            <td>
              {s.outbox.pending} menunggu · {s.outbox.failed} gagal ·{" "}
              {s.outbox.delivered} terkirim
              {s.outbox.last_error && <small> ({s.outbox.last_error})</small>}
            </td>
          </tr>
          <tr>
            <th>IoT</th>
            <td>
              {s.iot.gateways} gateway · {s.iot.devices} perangkat ·{" "}
              {s.iot.open_alerts} alarm terbuka
            </td>
          </tr>
        </tbody>
      </table>
      {manage && (
        <div className="actions">
          <label>
            <input
              type="checkbox"
              checked={s.standalone}
              onChange={(e) => {
                setError(undefined);
                api("integration/standalone", "PUT", {
                  standalone: e.target.checked,
                })
                  .then(status.refresh)
                  .catch(setError);
              }}
            />{" "}
            Mode mandiri (tidak ada data yang dikirim keluar)
          </label>
          <button
            className="primary"
            onClick={() => {
              setError(undefined);
              api<{
                data: {
                  delivered: number;
                  failed: number;
                  skipped: string | null;
                };
              }>("integration/sync", "POST", {})
                .then((r) => {
                  setMessage(
                    `Terkirim ${r.data.delivered}, gagal ${r.data.failed}${r.data.skipped ? ` (dilewati: ${r.data.skipped})` : ""}.`,
                  );
                  status.refresh();
                })
                .catch(setError);
            }}
          >
            Sinkronkan sekarang
          </button>
        </div>
      )}
    </section>
  );
}

type Gateway = {
  id: string;
  name: string;
  protocol: string;
  active: boolean;
  last_seen_at: string | null;
};
type Device = {
  id: string;
  code: string;
  name: string;
  external_id: string;
  metric: string;
  unit: string | null;
  min_value: string | null;
  max_value: string | null;
  binding_type: string;
  auto_lock: boolean;
  last_value: string | null;
  last_seen_at: string | null;
  active: boolean;
};
type Alert = {
  id: string;
  kind: string;
  value: string | null;
  limit_value: string | null;
  status: string;
  raised_at: string;
  occurrences: number;
  locked_units: number;
  device_code: string;
  device_name: string;
  unit: string | null;
};

export function IotPage() {
  const session = useSession();
  const manage = can(session, "integration.manage");
  const gateways = useData<{ data: Gateway[] }>("iot/gateways");
  const devices = useData<{ data: Device[] }>("iot/devices");
  const alerts = useData<{ data: Alert[] }>("iot/alerts");
  const [error, setError] = useState<unknown>();
  const [token, setToken] = useState<{ name: string; token: string }>();
  const [gName, setGName] = useState("");
  const [protocol, setProtocol] = useState("REST");
  const [d, setD] = useState({
    code: "",
    name: "",
    external_id: "",
    metric: "TEMPERATURE",
    unit: "°C",
    min_value: "",
    max_value: "",
    gateway_id: "",
    binding_id: "",
    auto_lock: false,
  });
  const warehouses = useData<{
    data: { id: string; code: string; name: string }[];
  }>(manage ? "master/warehouses/options" : "master/schema");
  const [warehouseId, setWarehouseId] = useState("");
  const tree = useData<{ data: { id: string; code: string; level: string }[] }>(
    warehouseId ? `warehouses/${warehouseId}/locations` : "master/schema",
  );
  const locations = warehouseId
    ? ((
        tree.data as unknown as
          { data: { id: string; code: string; level: string }[] } | undefined
      )?.data ?? [])
    : [];
  const num = (v: string) => (v === "" ? null : Number(v));
  const note = (q: string) => window.prompt(q);
  const run = (p: Promise<unknown>) => {
    setError(undefined);
    p.then(() => {
      gateways.refresh();
      devices.refresh();
      alerts.refresh();
    }).catch(setError);
  };
  return (
    <section className="card">
      <h1 tabIndex={-1}>IoT & telemetri</h1>
      <ErrorBox
        error={error ?? gateways.error ?? devices.error ?? alerts.error}
      />
      <h2>Alarm</h2>
      {(alerts.data?.data ?? []).length === 0 ? (
        <Empty>Tidak ada alarm.</Empty>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Perangkat</th>
              <th>Jenis</th>
              <th>Nilai</th>
              <th>Batas</th>
              <th>Kejadian</th>
              <th>Stok dikunci</th>
              <th>Status</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {alerts.data!.data.map((a) => (
              <tr key={a.id}>
                <td>
                  {a.device_code} · {a.device_name}
                </td>
                <td>{a.kind}</td>
                <td>
                  {a.value} {a.unit}
                </td>
                <td>{a.limit_value}</td>
                <td>{a.occurrences}×</td>
                <td>{a.locked_units}</td>
                <td>
                  <Badge value={a.status} />
                </td>
                <td>
                  {manage && a.status === "OPEN" && (
                    <button
                      onClick={() => {
                        const n = note("Catatan pengakuan");
                        if (n)
                          run(
                            api(`iot/alerts/${a.id}/acknowledge`, "POST", {
                              note: n,
                            }),
                          );
                      }}
                    >
                      Akui
                    </button>
                  )}
                  {manage && a.status !== "CLOSED" && (
                    <button
                      onClick={() => {
                        const n = note(
                          "Catatan penutupan (tindakan yang diambil)",
                        );
                        if (n)
                          run(
                            api(`iot/alerts/${a.id}/close`, "POST", {
                              note: n,
                            }),
                          );
                      }}
                    >
                      Tutup
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <h2>Perangkat</h2>
      {(devices.data?.data ?? []).length === 0 ? (
        <Empty>Belum ada perangkat.</Empty>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Kode</th>
              <th>Nama</th>
              <th>ID eksternal</th>
              <th>Batas</th>
              <th>Terakhir</th>
              <th>Ikatan</th>
            </tr>
          </thead>
          <tbody>
            {devices.data!.data.map((v) => (
              <tr key={v.id}>
                <td>{v.code}</td>
                <td>{v.name}</td>
                <td>{v.external_id}</td>
                <td>
                  {v.min_value ?? "–"} … {v.max_value ?? "–"} {v.unit}
                </td>
                <td>
                  {v.last_value ?? "–"}{" "}
                  {v.last_seen_at && (
                    <small>
                      {new Date(v.last_seen_at).toLocaleString("id-ID")}
                    </small>
                  )}
                </td>
                <td>
                  {v.binding_type}
                  {v.auto_lock && " · kunci otomatis"}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {manage && (
        <Form
          label="Tambah perangkat"
          onSubmit={async () => {
            await api("iot/devices", "POST", {
              code: d.code,
              name: d.name,
              external_id: d.external_id,
              metric: d.metric,
              unit: d.unit || null,
              min_value: num(d.min_value),
              max_value: num(d.max_value),
              gateway_id: d.gateway_id || null,
              binding_type: d.binding_id ? "LOCATION" : "NONE",
              binding_id: d.binding_id || null,
              auto_lock: d.auto_lock,
            });
            setD({ ...d, code: "", name: "", external_id: "" });
            devices.refresh();
          }}
        >
          <Field label="Kode">
            <input
              value={d.code}
              onChange={(e) => setD({ ...d, code: e.target.value })}
              required
              maxLength={40}
            />
          </Field>
          <Field label="Nama">
            <input
              value={d.name}
              onChange={(e) => setD({ ...d, name: e.target.value })}
              required
              maxLength={200}
            />
          </Field>
          <Field label="ID eksternal (dikirim gateway)">
            <input
              value={d.external_id}
              onChange={(e) => setD({ ...d, external_id: e.target.value })}
              required
              maxLength={100}
            />
          </Field>
          <Field label="Metrik">
            <select
              value={d.metric}
              onChange={(e) => setD({ ...d, metric: e.target.value })}
            >
              {["TEMPERATURE", "HUMIDITY", "WEIGHT", "RFID", "OTHER"].map(
                (m) => (
                  <option key={m}>{m}</option>
                ),
              )}
            </select>
          </Field>
          <Field label="Batas bawah">
            <input
              type="number"
              step="any"
              value={d.min_value}
              onChange={(e) => setD({ ...d, min_value: e.target.value })}
            />
          </Field>
          <Field label="Batas atas">
            <input
              type="number"
              step="any"
              value={d.max_value}
              onChange={(e) => setD({ ...d, max_value: e.target.value })}
            />
          </Field>
          <Field label="Gateway">
            <Select
              value={d.gateway_id}
              onChange={(e) => setD({ ...d, gateway_id: e.target.value })}
            >
              <option value="">Gateway mana saja</option>
              {gateways.data?.data.map((g) => (
                <option key={g.id} value={g.id}>
                  {g.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Gudang (untuk mengikat ke lokasi)">
            <Select
              value={warehouseId}
              onChange={(e) => {
                setWarehouseId(e.target.value);
                setD({ ...d, binding_id: "" });
              }}
            >
              <option value="">Tidak diikat</option>
              {(
                warehouses.data as unknown as
                  | { data: { id: string; code: string; name: string }[] }
                  | undefined
              )?.data.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.code} · {w.name}
                </option>
              ))}
            </Select>
          </Field>
          {warehouseId && (
            <Field label="Lokasi yang diikat">
              <Select
                value={d.binding_id}
                onChange={(e) => setD({ ...d, binding_id: e.target.value })}
              >
                <option value="">Pilih lokasi…</option>
                {locations.map((l) => (
                  <option key={l.id} value={l.id}>
                    {l.code} ({l.level})
                  </option>
                ))}
              </Select>
            </Field>
          )}
          <label>
            <input
              type="checkbox"
              checked={d.auto_lock}
              onChange={(e) => setD({ ...d, auto_lock: e.target.checked })}
            />{" "}
            Kunci stok otomatis saat melewati batas
          </label>
        </Form>
      )}
      <h2>Gateway</h2>
      {token && (
        <p role="alert">
          Token gateway <strong>{token.name}</strong> (hanya ditampilkan
          sekali): <code>{token.token}</code>
        </p>
      )}
      {(gateways.data?.data ?? []).length === 0 ? (
        <Empty>Belum ada gateway.</Empty>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Nama</th>
              <th>Protokol</th>
              <th>Terakhir aktif</th>
              <th>Status</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {gateways.data!.data.map((g) => (
              <tr key={g.id}>
                <td>{g.name}</td>
                <td>{g.protocol}</td>
                <td>
                  {g.last_seen_at
                    ? new Date(g.last_seen_at).toLocaleString("id-ID")
                    : "belum pernah"}
                </td>
                <td>
                  <Badge value={g.active ? "AKTIF" : "NONAKTIF"} />
                </td>
                <td>
                  {manage && (
                    <>
                      <button
                        onClick={() => {
                          setError(undefined);
                          api<{ data: { name: string; token: string } }>(
                            `iot/gateways/${g.id}/rotate-token`,
                            "POST",
                            {},
                          )
                            .then((r) => setToken(r.data))
                            .catch(setError);
                        }}
                      >
                        Ganti token
                      </button>
                      <button
                        onClick={() =>
                          run(
                            api(`iot/gateways/${g.id}/status`, "POST", {
                              active: !g.active,
                            }),
                          )
                        }
                      >
                        {g.active ? "Nonaktifkan" : "Aktifkan"}
                      </button>
                    </>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {manage && (
        <Form
          label="Buat gateway"
          onSubmit={async () => {
            const r = await api<{ data: { name: string; token: string } }>(
              "iot/gateways",
              "POST",
              { name: gName, protocol },
            );
            setToken(r.data);
            setGName("");
            gateways.refresh();
          }}
        >
          <Field label="Nama">
            <input
              value={gName}
              onChange={(e) => setGName(e.target.value)}
              required
              maxLength={150}
            />
          </Field>
          <Field label="Protokol">
            <select
              value={protocol}
              onChange={(e) => setProtocol(e.target.value)}
            >
              <option value="REST">REST</option>
              <option value="MQTT_BRIDGE">Jembatan MQTT</option>
            </select>
          </Field>
        </Form>
      )}
    </section>
  );
}
