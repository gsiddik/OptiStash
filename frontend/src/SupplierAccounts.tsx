import { useState } from "react";
import { api, useData } from "./api";
import { Badge, Empty, ErrorBox, Field, Form, Loading } from "./components";
import { Select } from "./Select";

type Partner = { id: string; code: string; name: string; type: string };
type Invitation = {
  id: string;
  name: string;
  email: string;
  expires_at: string;
};
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
  const invitations = useData<{ data: Invitation[] }>(
    partnerId ? `partners/${partnerId}/invitations` : "master/schema",
  );
  const [inviteName, setInviteName] = useState("");
  const [inviteEmail, setInviteEmail] = useState("");
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
      <ErrorBox
        error={error ?? partners.error ?? users.error ?? invitations.error}
      />
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
          <h2>Undang lewat email</h2>
          <p className="muted">
            Orang yang diundang menerima tautan satu kali (berlaku 72 jam) dan
            memilih kata sandinya sendiri.
          </p>
          {(
            (invitations.data as unknown as { data: Invitation[] } | undefined)
              ?.data ?? []
          ).length > 0 && (
            <table>
              <thead>
                <tr>
                  <th>Nama</th>
                  <th>Email</th>
                  <th>Berlaku sampai</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {(
                  invitations.data as unknown as { data: Invitation[] }
                ).data.map((i) => (
                  <tr key={i.id}>
                    <td>{i.name}</td>
                    <td>{i.email}</td>
                    <td>{new Date(i.expires_at).toLocaleString("id-ID")}</td>
                    <td>
                      <button
                        onClick={() => {
                          setError(undefined);
                          api(
                            `partners/${partnerId}/invitations/${i.id}`,
                            "DELETE",
                          )
                            .then(invitations.refresh)
                            .catch(setError);
                        }}
                      >
                        Batalkan
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <Form
            label="Kirim undangan"
            onSubmit={async () => {
              await api(`partners/${partnerId}/invitations`, "POST", {
                name: inviteName,
                email: inviteEmail,
              });
              setInviteName("");
              setInviteEmail("");
              invitations.refresh();
            }}
          >
            <Field label="Nama">
              <input
                value={inviteName}
                onChange={(e) => setInviteName(e.target.value)}
                required
                maxLength={150}
              />
            </Field>
            <Field label="Email">
              <input
                type="email"
                value={inviteEmail}
                onChange={(e) => setInviteEmail(e.target.value)}
                required
              />
            </Field>
          </Form>
          <h2>Tambah akun langsung</h2>
          <p className="muted">
            Tanpa undangan: administrator menetapkan kata sandi awal.
          </p>
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
