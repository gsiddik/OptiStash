import { useState } from "react";
import { Link } from "react-router-dom";
import { api, csrf, useData } from "./api";
import { Badge, ErrorBox, Field, Form, Loading } from "./components";
import { Action } from "./RecordEditor";
import { Brand } from "./App";

type PlatformSession = {
  id: number;
  name: string;
  permissions: string[];
  deployment_mode: string;
  identity_mode: string;
  standalone_tenant: string | null;
};
type TenantRow = {
  id: string;
  slug: string;
  name: string;
  active: boolean;
  optinexus_tenant_id: string | null;
  active_members: number;
};

/** Superadmin console (/platform): separate login and session from the tenant workspace. */
export function PlatformApp() {
  const me = useData<{ data: PlatformSession }>("platform/auth/me");
  const [loggedOut, setLoggedOut] = useState(false);
  if (me.loading) return <Loading />;
  if (loggedOut || !me.data)
    return (
      <PlatformLogin
        onLogin={() => {
          setLoggedOut(false);
          me.refresh();
        }}
      />
    );
  return (
    <PlatformConsole
      session={me.data.data}
      onLogout={async () => {
        await api("auth/logout", "POST");
        setLoggedOut(true);
      }}
    />
  );
}

function PlatformLogin({ onLogin }: { onLogin: () => void }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  return (
    <main className="login">
      <section className="login-story">
        <Brand />
        <span className="eyebrow">PLATFORM ADMINISTRATION</span>
        <h1>
          Satu platform.
          <br />
          Setiap tenant.
        </h1>
        <p>
          Kelola tenant, status langganan operasional, dan administrator tenant
          dari satu konsol yang terpisah dari ruang operasional.
        </p>
      </section>
      <section className="login-form">
        <span className="eyebrow">PLATFORM</span>
        <h2>Masuk sebagai Superadmin</h2>
        <p className="muted">
          Konsol platform untuk mengelola tenant dan administratornya.
        </p>
        <Form
          label="Masuk"
          onSubmit={async () => {
            await api("platform/auth/login", "POST", { email, password });
            await csrf();
            onLogin();
          }}
        >
          <Field label="Email">
            <input
              autoComplete="username"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
          </Field>
          <Field label="Kata sandi">
            <input
              autoComplete="current-password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              maxLength={1024}
            />
          </Field>
        </Form>
        <Link to="/">Masuk ke ruang operasional tenant</Link>
      </section>
    </main>
  );
}

function PlatformConsole({
  session,
  onLogout,
}: {
  session: PlatformSession;
  onLogout: () => Promise<void>;
}) {
  const tenants = useData<{ data: TenantRow[] }>("platform/tenants");
  const can = (p: string) => session.permissions.includes(p);
  return (
    <main className="platform-console">
      <div className="page-heading">
        <div>
          <span className="eyebrow">PLATFORM · SUPERADMIN</span>
          <h1 tabIndex={-1}>Tenant</h1>
          <p>
            {session.deployment_mode === "standalone"
              ? `Mode standalone: hanya tenant "${session.standalone_tenant}" yang dapat dipakai.`
              : "Mode SaaS: setiap tenant terisolasi."}
          </p>
        </div>
        <div className="actions">
          <span>{session.name}</span>
          {can("platform.tenant.manage") && (
            <Action
              label="Buat tenant"
              path="platform/tenants"
              fields={[
                {
                  name: "slug",
                  label: "Slug tenant (huruf kecil, angka, tanda hubung)",
                },
                { name: "name", label: "Nama perusahaan" },
              ]}
              onDone={tenants.refresh}
              primary
            />
          )}
          <button onClick={() => void onLogout()}>Keluar</button>
        </div>
      </div>
      <ErrorBox error={tenants.error} />
      <section className="card">
        {tenants.loading ? (
          <Loading />
        ) : (
          tenants.data && (
            <div className="table-scroll">
              <table>
                <thead>
                  <tr>
                    <th>Tenant</th>
                    <th>Slug</th>
                    <th>Status</th>
                    <th>Anggota aktif</th>
                    <th>OptiNexus</th>
                    <th>Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  {tenants.data.data.map((t) => (
                    <tr key={t.id}>
                      <td>{t.name}</td>
                      <td>{t.slug}</td>
                      <td>
                        <Badge value={t.active ? "ACTIVE" : "SUSPENDED"} />
                      </td>
                      <td>{t.active_members}</td>
                      <td>
                        {t.optinexus_tenant_id ? (
                          <code>{t.optinexus_tenant_id}</code>
                        ) : (
                          <span className="muted">Belum ditautkan</span>
                        )}
                      </td>
                      <td>
                        <div className="actions">
                          {can("platform.tenant.manage") && (
                            <Action
                              label={
                                t.active
                                  ? `Tangguhkan ${t.slug}`
                                  : `Aktifkan ${t.slug}`
                              }
                              path={`platform/tenants/${t.id}/status`}
                              fixed={{ active: !t.active }}
                              fields={[
                                { name: "reason", label: "Alasan (min. 10)" },
                              ]}
                              onDone={tenants.refresh}
                            />
                          )}
                          {can("platform.tenant.manage") && (
                            <Action
                              label={`Tautkan ${t.slug}`}
                              path={`platform/tenants/${t.id}/optinexus`}
                              fields={[
                                {
                                  name: "optinexus_tenant_id",
                                  label:
                                    "ID tenant OptiNexus (UUID; kosongkan untuk melepas)",
                                  value: t.optinexus_tenant_id ?? "",
                                  required: false,
                                },
                              ]}
                              onDone={tenants.refresh}
                            />
                          )}
                          {session.identity_mode !== "optinexus" &&
                            can("platform.tenant_admin.create") && (
                              <Action
                                label={`Admin ${t.slug}`}
                                path={`platform/tenants/${t.id}/administrators`}
                                fields={[
                                  { name: "name", label: "Nama administrator" },
                                  { name: "email", label: "Email akun baru" },
                                  {
                                    name: "password",
                                    label:
                                      "Kata sandi awal (min. 12, huruf besar/kecil, angka, simbol)",
                                    kind: "password",
                                  },
                                ]}
                                onDone={tenants.refresh}
                              />
                            )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )
        )}
      </section>
    </main>
  );
}
