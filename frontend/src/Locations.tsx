import { useState } from "react";
import { api, useData } from "./api";
import { useSession } from "./App";
import { Badge, Empty, ErrorBox, Field, Form, Loading } from "./components";
import { can } from "./opsTypes";
import { Select } from "./Select";

type Node = {
  id: string;
  parent_id: string | null;
  level: "ZONE" | "AISLE" | "RACK" | "SHELF" | "BIN";
  code: string;
  name: string;
  active: boolean;
  blocked: boolean;
  blocked_reason: string | null;
  effective_blocked: boolean;
  depth: number;
};
type Warehouse = {
  id: string;
  code: string;
  name: string;
  granularity: string;
};

const levelLabel: Record<string, string> = {
  ZONE: "Zona",
  AISLE: "Lorong",
  RACK: "Rak",
  SHELF: "Tingkat",
  BIN: "Bin",
};
const levels = Object.keys(levelLabel);

/** Storage hierarchy of one warehouse: Zone > Aisle > Rack > Shelf > Bin, down to the warehouse's own granularity. */
export function LocationsPage() {
  const session = useSession();
  const warehouses = useData<{ data: Warehouse[] }>(
    "master/warehouses?active=1",
  );
  const [warehouseId, setWarehouseId] = useState("");
  const warehouse = warehouses.data?.data.find((w) => w.id === warehouseId);
  const tree = useData<{ data: Node[] }>(
    warehouseId ? `warehouses/${warehouseId}/locations` : "master/schema",
  );
  const [parent, setParent] = useState("");
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [prefix, setPrefix] = useState("");
  const [count, setCount] = useState("10");
  const [error, setError] = useState<unknown>();
  const manage = can(session, "master.manage");
  const block = can(session, "location.block");
  const nodes = warehouseId ? (tree.data?.data ?? []) : [];
  const parentNode = nodes.find((n) => n.id === parent);
  const nextLevel = parentNode
    ? levels[levels.indexOf(parentNode.level) + 1]
    : "ZONE";
  const allowed =
    nextLevel !== undefined &&
    warehouse !== undefined &&
    levels.indexOf(nextLevel) <= levels.indexOf(warehouse.granularity);

  async function act(run: () => Promise<unknown>) {
    setError(undefined);
    try {
      await run();
      tree.refresh();
    } catch (e) {
      setError(e);
    }
  }

  return (
    <section className="card">
      <h1 tabIndex={-1}>Struktur gudang</h1>
      <p className="muted">
        Zona, lorong, rak, tingkat, dan bin. Setiap gudang dibatasi sampai
        tingkat terdalam yang dipilihnya.
      </p>
      <ErrorBox error={error ?? warehouses.error ?? tree.error} />
      <Field label="Gudang">
        <Select
          value={warehouseId}
          onChange={(e) => {
            setWarehouseId(e.target.value);
            setParent("");
          }}
        >
          <option value="">Pilih gudang…</option>
          {warehouses.data?.data.map((w) => (
            <option key={w.id} value={w.id}>
              {w.code} · {w.name}
            </option>
          ))}
        </Select>
      </Field>
      {warehouseId && tree.loading && <Loading />}
      {warehouseId && !tree.loading && (
        <>
          {manage && (
            <div className="card">
              <h2>Tambah lokasi</h2>
              <Field label="Induk">
                <Select
                  value={parent}
                  onChange={(e) => setParent(e.target.value)}
                >
                  <option value="">(Tingkat zona, tanpa induk)</option>
                  {nodes
                    .filter(
                      (n) =>
                        n.active &&
                        levels.indexOf(n.level) <
                          levels.indexOf(warehouse?.granularity ?? "BIN"),
                    )
                    .map((n) => (
                      <option key={n.id} value={n.id}>
                        {levelLabel[n.level]} {n.code}
                      </option>
                    ))}
                </Select>
              </Field>
              {!allowed ? (
                <p className="muted">
                  Tingkat di bawah induk ini tidak tersedia untuk gudang ini.
                </p>
              ) : (
                <>
                  <p className="muted">
                    Akan dibuat: <strong>{levelLabel[nextLevel]}</strong>
                  </p>
                  <Form
                    label="Tambah satu"
                    onSubmit={async () => {
                      await api(`warehouses/${warehouseId}/locations`, "POST", {
                        parent_id: parent || null,
                        code,
                        name,
                      });
                      setCode("");
                      setName("");
                      tree.refresh();
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
                  </Form>
                  <Form
                    label="Buat banyak sekaligus"
                    onSubmit={async () => {
                      await api(
                        `warehouses/${warehouseId}/locations/generate`,
                        "POST",
                        {
                          parent_id: parent || null,
                          prefix,
                          count: Number(count),
                        },
                      );
                      tree.refresh();
                    }}
                  >
                    <Field
                      label="Awalan kode"
                      hint="Hasil: kode induk-awalan-nomor, misalnya Z1-A01, Z1-A02."
                    >
                      <input
                        value={prefix}
                        onChange={(e) => setPrefix(e.target.value)}
                        required
                        maxLength={20}
                      />
                    </Field>
                    <Field label="Jumlah (maks. 500)">
                      <input
                        type="number"
                        min={1}
                        max={500}
                        value={count}
                        onChange={(e) => setCount(e.target.value)}
                        required
                      />
                    </Field>
                  </Form>
                </>
              )}
            </div>
          )}
          {nodes.length === 0 ? (
            <Empty>Gudang ini belum memiliki lokasi.</Empty>
          ) : (
            <table>
              <thead>
                <tr>
                  <th>Lokasi</th>
                  <th>Tingkat</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {nodes.map((n) => (
                  <tr key={n.id}>
                    <td style={{ paddingLeft: `${n.depth * 1.5 + 0.5}rem` }}>
                      <strong>{n.code}</strong>
                      {n.name !== n.code && <> · {n.name}</>}
                    </td>
                    <td>{levelLabel[n.level]}</td>
                    <td>
                      {!n.active ? (
                        <Badge value="NONAKTIF" />
                      ) : n.effective_blocked ? (
                        <Badge value="DIBLOKIR" />
                      ) : (
                        <Badge value="AKTIF" />
                      )}
                      {n.blocked_reason && <small> {n.blocked_reason}</small>}
                    </td>
                    <td>
                      {block && n.active && (
                        <button
                          onClick={() => {
                            const reason = window.prompt(
                              n.blocked
                                ? "Alasan membuka blokir"
                                : "Alasan memblokir",
                            );
                            if (reason)
                              void act(() =>
                                api(`locations/${n.id}/block`, "POST", {
                                  blocked: !n.blocked,
                                  reason,
                                }),
                              );
                          }}
                        >
                          {n.blocked ? "Buka blokir" : "Blokir"}
                        </button>
                      )}
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
