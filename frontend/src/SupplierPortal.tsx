import { useState } from "react";
import { api, useData } from "./api";
import { useSession, Brand } from "./App";
import { Badge, Empty, ErrorBox, Loading } from "./components";
import { AsnForm } from "./Inbound";
import type { Asn } from "./Inbound";

type Row = {
  id: string;
  number: string;
  status: string;
  expected_date: string;
  reference: string | null;
};
type Option = { id: string; code: string; name: string };

/** The supplier's own portal: announce deliveries (ASN), follow their status. Everything the server returns is limited to this supplier. */
export function SupplierPortal({
  onLogout,
}: {
  onLogout: () => Promise<void>;
}) {
  const session = useSession();
  const list = useData<{ data: Row[] }>("supplier/asns");
  const products = useData<{ data: Option[] }>("supplier/products");
  const warehouses = useData<{ data: Option[] }>("supplier/warehouses");
  const [open, setOpen] = useState<string>();
  const detail = useData<{ data: Asn }>(
    open ? `supplier/asns/${open}` : "supplier/products",
  );
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<unknown>();
  const asn = open
    ? (detail.data as unknown as { data: Asn } | undefined)?.data
    : undefined;
  const act = (path: string, body: unknown = {}) => {
    setError(undefined);
    api(`supplier/asns/${open}/${path}`, "POST", body)
      .then(() => {
        detail.refresh();
        list.refresh();
      })
      .catch(setError);
  };
  return (
    <main className="login">
      <section
        className="login-form"
        style={{ maxWidth: "60rem", width: "100%" }}
      >
        <Brand />
        <h1 tabIndex={-1}>Portal pemasok</h1>
        <p className="muted">
          {session.name} · {session.tenant.name}
        </p>
        <div className="actions">
          <button className="primary" onClick={() => setCreating((v) => !v)}>
            {creating ? "Tutup" : "ASN baru"}
          </button>
          <button onClick={() => void onLogout()}>Keluar</button>
        </div>
        <ErrorBox error={error ?? list.error} />
        {creating && (
          <AsnForm
            products={products.data?.data ?? []}
            warehouses={warehouses.data?.data ?? []}
            onSave={async (body) => {
              const res = await api<{ data: Asn }>(
                "supplier/asns",
                "POST",
                body,
              );
              setCreating(false);
              list.refresh();
              setOpen(res.data.id);
            }}
          />
        )}
        {list.loading ? (
          <Loading />
        ) : (list.data?.data ?? []).length === 0 ? (
          <Empty>Belum ada ASN.</Empty>
        ) : (
          <table>
            <thead>
              <tr>
                <th>Nomor</th>
                <th>Tiba</th>
                <th>Referensi</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {list.data!.data.map((a) => (
                <tr key={a.id}>
                  <td>
                    <button onClick={() => setOpen(a.id)}>{a.number}</button>
                  </td>
                  <td>{a.expected_date}</td>
                  <td>{a.reference}</td>
                  <td>
                    <Badge value={a.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {asn && (
          <div className="card">
            <h2>
              {asn.number} <Badge value={asn.status} />
            </h2>
            <table>
              <thead>
                <tr>
                  <th>Produk</th>
                  <th>Rencana</th>
                  <th>Diterima</th>
                </tr>
              </thead>
              <tbody>
                {asn.lines.map((l) => (
                  <tr key={l.id}>
                    <td>
                      {l.product_code} · {l.product_name}
                    </td>
                    <td>{l.expected_qty}</td>
                    <td>{l.received_qty}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className="actions">
              {asn.status === "DRAFT" && (
                <button className="primary" onClick={() => act("submit")}>
                  Ajukan
                </button>
              )}
              {["DRAFT", "SUBMITTED"].includes(asn.status) && (
                <button
                  onClick={() => {
                    const r = window.prompt("Alasan pembatalan");
                    if (r) act("cancel", { reason: r });
                  }}
                >
                  Batalkan
                </button>
              )}
            </div>
          </div>
        )}
      </section>
    </main>
  );
}
