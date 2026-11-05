import { Link } from "react-router-dom";
import { useSession } from "./App";
import { Brand } from "./App";
import { can } from "./opsTypes";

/** Landing page of an internal user. Module tiles appear as the modules are delivered; each is shown only to those allowed to use it. */
export function Dashboard() {
  const session = useSession();
  const tiles = [
    {
      to: "/master",
      title: "Master data",
      text: "Gudang, struktur penyimpanan, produk, kemasan, dan mitra.",
      show: can(session, "master.view"),
    },
    {
      to: "/access",
      title: "Akses & role",
      text: "Peran pengguna sesuai matriks hak akses gudang.",
      show: can(session, "access.role.view"),
    },
  ].filter((t) => t.show);
  return (
    <section className="card">
      <h1 tabIndex={-1}>Ringkasan</h1>
      <p className="muted">
        {session.tenant.name} · mode{" "}
        {session.deployment_mode === "saas" ? "SaaS (OptiNexus)" : "mandiri"}
      </p>
      <div className="stages">
        {tiles.map((t) => (
          <Link key={t.to} to={t.to}>
            <strong>{t.title}</strong>
            <br />
            <small>{t.text}</small>
          </Link>
        ))}
      </div>
    </section>
  );
}

/** Home of a supplier's portal user (the portal itself arrives with the Inbound module). */
export function SupplierHome({ onLogout }: { onLogout: () => Promise<void> }) {
  const session = useSession();
  return (
    <main className="login">
      <section className="login-form">
        <Brand />
        <h1 tabIndex={-1}>Portal pemasok</h1>
        <p>
          {session.name} · {session.tenant.name}
        </p>
        <p className="muted">
          Pemberitahuan pengiriman (ASN) akan tersedia di portal ini.
        </p>
        <button onClick={() => void onLogout()}>Keluar</button>
      </section>
    </main>
  );
}
