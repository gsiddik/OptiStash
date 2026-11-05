import { useState } from "react";
import { api, useData } from "./api";
import { Badge, Empty, ErrorBox, Field, Form, Loading } from "./components";
import { Select } from "./Select";

type Partner = { id: string; code: string; name: string; type: string };
type PortalUser = {
  membership_id: number;
  name: string;
  email: string;
  active: boolean;
};

/** Portal accounts of suppliers: who may announce deliveries (ASN) for a supplier. */
export function SupplierAccountsPage() {
  const partners = useData<{ data: Partner[] }>("master/partners?q=&active=1");
  const [partnerId, setPartnerId] = useState("");
  const users = useData<{ data: PortalUser[] }>(
    partnerId ? `partners/${partnerId}/portal-users` : "master/schema",
  );
  const [error, setError] = useState<unknown>();
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const suppliers =
    (partners.data as unknown as { data: Partner[] } | undefined)?.data.filter(
      (p) => p.type === "SUPPLIER",
    ) ?? [];
  return (
    <section className="card">
      <h1 tabIndex={-1}>Akun portal pemasok</h1>
      <p className="muted">
        Akun pemasok hanya dapat membuka portal pemasok, tidak pernah layar
        internal gudang.
      </p>
      <ErrorBox error={error ?? partners.error ?? users.error} />
      <Field label="Pemasok">
        <Select
          value={partnerId}
          onChange={(e) => setPartnerId(e.target.value)}
        >
          <option value="">Pilih pemasok…</option>
          {suppliers.map((p) => (
            <option key={p.id} value={p.id}>
              {p.code} · {p.name}
            </option>
          ))}
        </Select>
      </Field>
      {partnerId && users.loading && <Loading />}
      {partnerId && !users.loading && (
        <>
          {((users.data as unknown as { data: PortalUser[] })?.data ?? [])
            .length === 0 ? (
            <Empty>Belum ada akun portal untuk pemasok ini.</Empty>
          ) : (
            <table>
              <thead>
                <tr>
                  <th>Nama</th>
                  <th>Email</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {(users.data as unknown as { data: PortalUser[] }).data.map(
                  (u) => (
                    <tr key={u.membership_id}>
                      <td>{u.name}</td>
                      <td>{u.email}</td>
                      <td>
                        <Badge value={u.active ? "AKTIF" : "NONAKTIF"} />
                      </td>
                      <td>
                        <button
                          onClick={() => {
                            setError(undefined);
                            api(
                              `partners/${partnerId}/portal-users/${u.membership_id}/status`,
                              "POST",
                              { active: !u.active },
                            )
                              .then(users.refresh)
                              .catch(setError);
                          }}
                        >
                          {u.active ? "Nonaktifkan" : "Aktifkan"}
                        </button>
                      </td>
                    </tr>
                  ),
                )}
              </tbody>
            </table>
          )}
          <h2>Tambah akun</h2>
          <Form
            label="Buat akun"
            onSubmit={async () => {
              await api(`partners/${partnerId}/portal-users`, "POST", {
                name,
                email,
                password,
              });
              setName("");
              setEmail("");
              setPassword("");
              users.refresh();
            }}
          >
            <Field label="Nama">
              <input
                value={name}
                onChange={(e) => setName(e.target.value)}
                required
                maxLength={150}
              />
            </Field>
            <Field label="Email">
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
              />
            </Field>
            <Field
              label="Kata sandi awal"
              hint="Minimal 12 karakter dengan huruf besar, kecil, angka, dan simbol."
            >
              <input
                type="password"
                autoComplete="new-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                maxLength={1024}
              />
            </Field>
          </Form>
        </>
      )}
    </section>
  );
}
