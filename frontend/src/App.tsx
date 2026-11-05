import { createContext, useContext, useEffect, useState } from "react";
import {
  Link,
  NavLink,
  Navigate,
  Outlet,
  Route,
  Routes,
  useLocation,
  useNavigate,
} from "react-router-dom";
import { api, ApiError, csrf, useData } from "./api";
import {
  ErrorBox,
  ErrorBoundary,
  Field,
  Form,
  Icon,
  Loading,
} from "./components";
import type { Session } from "./types";
import { roles } from "./types";
import { can, isSupplierSession } from "./opsTypes";
import { AccessPage } from "./Access";
import { PlatformApp } from "./Platform";
import { MasterPage } from "./Master";
import { Dashboard } from "./Overview";
import { SupplierPortal } from "./SupplierPortal";
import {
  AnalysisPage,
  StockMapPage,
  StocktakeDetailPage,
  StocktakeListPage,
} from "./Inventory";
import {
  AsnDetailPage,
  AsnListPage,
  DockPage,
  PutawayPage,
  QcPage,
  ReceivingPage,
} from "./Inbound";
import { LocationsPage } from "./Locations";
import { IntegrationPage } from "./Integration";
import { SupplierAccountsPage } from "./SupplierAccounts";
const SessionContext = createContext<Session | null>(null);
export function useSession() {
  const s = useContext(SessionContext);
  if (!s) throw new Error("Sesi diperlukan");
  return s;
}
export function Brand() {
  const [missing, setMissing] = useState(false);
  return (
    <Link to="/" className="brand" aria-label="OptiStash beranda">
      {!missing ? (
        <div className="brand-image">
          <img
            src="/brand/optistash-logo.png"
            alt="OptiStash"
            onError={() => setMissing(true)}
          />
        </div>
      ) : (
        <span>
          Opti<span>Stash</span>
        </span>
      )}
    </Link>
  );
}
const ssoMessages: Record<string, string> = {
  access_denied:
    "Organisasi atau akun Anda belum mendapat akses OptiStash di OptiNexus.",
  tenant_not_linked:
    "Organisasi Anda belum ditautkan ke OptiStash. Hubungi Superadmin.",
  tenant_inactive: "Tenant OptiStash organisasi Anda sedang tidak aktif.",
  no_permissions: "Akun Anda belum memiliki izin OptiStash di OptiNexus.",
  membership_inactive: "Keanggotaan Anda di tenant ini tidak aktif.",
  user_inactive: "Akun Anda tidak aktif.",
  email_not_verified:
    "Email akun OptiNexus belum terverifikasi, sehingga akun lama tidak dapat ditautkan.",
  account_conflict:
    "Email ini sudah dipakai akun lain di OptiStash. Hubungi administrator.",
  sso_unavailable:
    "OptiNexus sedang tidak dapat dihubungi. Coba lagi sebentar.",
  sso_disabled: "Masuk melalui OptiNexus belum diaktifkan.",
  sso_failed: "Masuk melalui OptiNexus gagal. Silakan coba lagi.",
};
function Login({ onLogin }: { onLogin: () => void }) {
  const status = useData<{
    data: { sso_enabled: boolean; password_login: boolean };
  }>("auth/sso/status");
  // If the status call fails the password form stays available; the API remains the authority.
  const sso = status.data?.data ?? { sso_enabled: false, password_login: true };
  const ssoError = new URLSearchParams(useLocation().search).get("sso_error");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [tenant, setTenant] = useState("");
  return (
    <main className="login">
      <section className="login-story">
        <Brand />
        <span className="eyebrow">WAREHOUSE MANAGEMENT SYSTEM</span>
        <h1>
          Satu gudang.
          <br />
          Setiap stok terkendali.
        </h1>
        <p>
          Kelola penerimaan, penyimpanan, persediaan, dan pengeluaran barang
          dengan kontrol mutu yang jelas.
        </p>
        <div className="stages">
          <span>Inbound</span>
          <span>Inventory</span>
          <span>Outbound</span>
        </div>
      </section>
      <section className="login-form">
        <span className="eyebrow">SELAMAT DATANG</span>
        <h2>Masuk ke ruang gudang</h2>
        <p className="muted">
          {sso.sso_enabled
            ? "Masuk dengan akun OptiNexus perusahaan Anda."
            : "Gunakan akun dan tenant perusahaan Anda."}
        </p>
        <ErrorBox
          error={ssoError && (ssoMessages[ssoError] ?? ssoMessages.sso_failed)}
        />
        {sso.sso_enabled && (
          <a
            className="button primary sso-button"
            href="/api/v1/auth/sso/redirect"
          >
            Masuk dengan OptiNexus
          </a>
        )}
        {sso.password_login && (
          <Form
            label="Masuk"
            onSubmit={async () => {
              await api("auth/login", "POST", { email, password, tenant });
              await csrf();
              onLogin();
            }}
          >
            <Field label="Tenant">
              <input
                autoComplete="organization"
                value={tenant}
                onChange={(e) => setTenant(e.target.value)}
                required
                maxLength={80}
              />
            </Field>
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
        )}
        {sso.sso_enabled && (
          <details className="external-login">
            <summary>Masuk sebagai pemasok</summary>
            <p className="muted">
              Akun pemasok dibuat oleh tenant dan masuk dengan kata sandinya
              sendiri.
            </p>
            <Form
              label="Masuk"
              onSubmit={async () => {
                await api("auth/external-login", "POST", {
                  email,
                  password,
                  tenant,
                });
                await csrf();
                onLogin();
              }}
            >
              <Field
                label="Kode tenant"
                hint="Kode tenant dari OptiNexus (misalnya TEN-…) atau nama singkat tenant."
              >
                <input
                  autoComplete="organization"
                  value={tenant}
                  onChange={(e) => setTenant(e.target.value)}
                  required
                  maxLength={80}
                />
              </Field>
              <Field label="Email akun">
                <input
                  autoComplete="username"
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                />
              </Field>
              <Field label="Kata sandi akun">
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
          </details>
        )}
        <p>
          <Link to="/platform">Masuk sebagai Superadmin</Link>
        </p>
        <small className="muted">
          Akses dibatasi sesuai tenant dan kewenangan akun.
        </small>
      </section>
    </main>
  );
}
const navigation: {
  group: string;
  items: { to: string; label: string; icon: string; permission?: string }[];
}[] = [
  {
    group: "GUDANG",
    items: [
      { to: "/", label: "Ringkasan", icon: "home" },
      {
        to: "/locations",
        label: "Struktur gudang",
        icon: "master",
        permission: "master.view",
      },
      {
        to: "/supplier-accounts",
        label: "Akun pemasok",
        icon: "fleet",
        permission: "partner.provision",
      },
    ],
  },
  {
    group: "INBOUND",
    items: [
      { to: "/asns", label: "ASN", icon: "orders", permission: "inbound.view" },
      {
        to: "/docks",
        label: "Dock & gate",
        icon: "dispatch",
        permission: "inbound.view",
      },
      {
        to: "/receiving",
        label: "Penerimaan",
        icon: "orders",
        permission: "receiving.execute",
      },
      {
        to: "/qc",
        label: "QC & karantina",
        icon: "audit",
        permission: "inbound.view",
      },
      {
        to: "/putaway",
        label: "Putaway",
        icon: "fleet",
        permission: "inbound.view",
      },
    ],
  },
  {
    group: "PERSEDIAAN",
    items: [
      {
        to: "/stock-map",
        label: "Peta stok",
        icon: "master",
        permission: "inventory.view",
      },
      {
        to: "/stocktakes",
        label: "Cycle count",
        icon: "audit",
        permission: "stocktake.manage",
      },
      {
        to: "/analysis",
        label: "Analisis persediaan",
        icon: "roadmap",
        permission: "inventory.analysis.view",
      },
    ],
  },
  {
    group: "MASTER DATA",
    items: [{ to: "/master", label: "Master data", icon: "master" }],
  },
  {
    group: "INTEGRASI",
    items: [
      {
        to: "/integration",
        label: "Transportasi",
        icon: "roadmap",
        permission: "integration.view",
      },
    ],
  },
  {
    group: "GOVERNANCE",
    items: [
      {
        to: "/access",
        label: "Akses & role",
        icon: "audit",
        permission: "access.role.view",
      },
    ],
  },
];
function Shell({ onLogout }: { onLogout: () => Promise<void> }) {
  const session = useSession();
  const location = useLocation();
  const navigate = useNavigate();
  const [compact, setCompact] = useState(() => {
    try {
      return localStorage.getItem("optistash.sidebar") === "compact";
    } catch {
      return false;
    }
  });
  const [mobile, setMobile] = useState(false);
  const [groups, setGroups] = useState<Record<string, boolean>>({});
  const [query, setQuery] = useState("");
  const [logoutError, setLogoutError] = useState<unknown>();
  const masterSchema = useData<{
    data: {
      groups?: Record<string, string>;
      resources?: { key: string; label: string; group: string }[];
    };
  }>("master/schema");
  // The Master data menu is built from the same schema as the pages it opens, so a new master list appears here by itself.
  const masterNav = Object.entries(masterSchema.data?.data.groups ?? {})
    .map(([key, label]) => ({
      label,
      items: (masterSchema.data?.data.resources ?? [])
        .filter(
          (r) =>
            r.group === key &&
            (r.label.toLowerCase().includes(query.toLowerCase()) ||
              label.toLowerCase().includes(query.toLowerCase())),
        )
        .map((r) => ({ to: `/master/${r.key}`, label: r.label })),
    }))
    .filter((g) => g.items.length > 0);
  const masterLabel = masterSchema.data?.data.resources?.find(
    (r) => `/master/${r.key}` === location.pathname,
  )?.label;
  useEffect(() => {
    setMobile(false);
    document.querySelector<HTMLElement>("main h1")?.focus();
  }, [location.pathname]);
  useEffect(() => {
    try {
      localStorage.setItem(
        "optistash.sidebar",
        compact ? "compact" : "expanded",
      );
    } catch {
      /* Preference is optional. */
    }
  }, [compact]);
  const section = navigation
    .flatMap((g) => g.items)
    .find((i) => i.to !== "/" && location.pathname.startsWith(i.to));
  const current = masterLabel ?? section?.label ?? "Ringkasan";
  return (
    <div className={`shell ${compact ? "compact" : ""}`}>
      <a href="#content" className="skip">
        Lewati navigasi
      </a>
      {mobile && (
        <button
          className="overlay"
          aria-label="Tutup menu"
          onClick={() => setMobile(false)}
        />
      )}
      <aside className={mobile ? "open" : ""}>
        <Brand />
        <div className="sidebar-tools">
          <button
            onClick={() => setCompact((v) => !v)}
            aria-label={compact ? "Perluas sidebar" : "Perkecil sidebar"}
            aria-expanded={!compact}
          >
            <Icon name="menu" />
            <span>{compact ? "Perluas" : "Perkecil menu"}</span>
          </button>
        </div>
        <div className="nav-search">
          <input
            aria-label="Cari menu"
            placeholder="Cari menu…"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </div>
        <nav aria-label="Navigasi utama">
          {navigation.map((group) => {
            const own = group.items.filter(
              (i) =>
                (!i.permission || can(session, i.permission)) &&
                i.label.toLowerCase().includes(query.toLowerCase()),
            );
            const tree = group.group === "MASTER DATA" && masterNav.length > 0;
            const items = tree
              ? masterNav.flatMap((g) => g.items)
              : group.group === "MASTER DATA" && query
                ? []
                : own;
            return (
              items.length > 0 && (
                <div className="nav-group" key={group.group}>
                  <button
                    className="group-toggle"
                    onClick={() =>
                      setGroups((v) => ({
                        ...v,
                        [group.group]: !(v[group.group] ?? true),
                      }))
                    }
                    aria-expanded={groups[group.group] ?? true}
                  >
                    <span>{group.group}</span>
                    <span>{(groups[group.group] ?? true) ? "−" : "+"}</span>
                  </button>
                  {((groups[group.group] ?? true) || query || compact) &&
                    (tree
                      ? masterNav.map((sub) => (
                          <div
                            className="nav-sub"
                            role="group"
                            aria-label={sub.label}
                            key={sub.label}
                          >
                            <span className="nav-sub-title">{sub.label}</span>
                            {sub.items.map((item) => (
                              <NavLink
                                className="sub-link"
                                title={`${sub.label}: ${item.label}`}
                                to={item.to}
                                key={item.to}
                              >
                                <span>{item.label}</span>
                              </NavLink>
                            ))}
                          </div>
                        ))
                      : own.map((item) => (
                          <NavLink
                            end={item.to === "/"}
                            title={item.label}
                            to={item.to}
                            key={item.to}
                          >
                            <Icon name={item.icon} />
                            <span>{item.label}</span>
                          </NavLink>
                        )))}
                </div>
              )
            );
          })}
        </nav>
        <div className="sidebar-foot">
          <span className="status-dot" />
          <span>Workspace gudang</span>
        </div>
      </aside>
      <div className="workspace">
        <header>
          <div className="header-left">
            <button
              className="mobile-toggle"
              onClick={() => setMobile((v) => !v)}
              aria-expanded={mobile}
              aria-label="Buka menu"
            >
              <Icon name="menu" />
            </button>
            <strong>{session.tenant.name}</strong>
            <span className="header-separator" />
            <span className="muted">Warehouse Management</span>
          </div>
          <div className="identity">
            <div>
              <strong>{session.name}</strong>
              <small>{roles[session.role] ?? session.role}</small>
            </div>
            <button
              onClick={() => {
                setLogoutError(undefined);
                void onLogout().catch(setLogoutError);
              }}
            >
              Keluar
            </button>
          </div>
        </header>
        <main id="content">
          <div className="breadcrumb-row">
            <nav className="breadcrumb" aria-label="Breadcrumb">
              <Link to="/">Beranda</Link>
              {section && (
                <>
                  <span>/</span>
                  <Link to={section.to}>{section.label}</Link>
                </>
              )}
              {current !== section?.label && location.pathname !== "/" && (
                <>
                  <span>/</span>
                  <span aria-current="page">{current}</span>
                </>
              )}
            </nav>
            {location.pathname !== "/" && (
              <button
                className="back"
                onClick={() => {
                  if (location.key !== "default") navigate(-1);
                  else
                    navigate(
                      section?.to === location.pathname
                        ? "/"
                        : (section?.to ?? "/"),
                    );
                }}
              >
                <Icon name="back" /> Kembali
              </button>
            )}
          </div>
          <ErrorBox error={logoutError} />
          <ErrorBoundary key={location.pathname}>
            <Outlet />
          </ErrorBoundary>
        </main>
        <footer>
          OptiStash <span>Inbound · Inventory · Outbound</span>
        </footer>
      </div>
    </div>
  );
}
/** Ends the local session and, for an OptiNexus sign-in, the OptiNexus session too (so the next visit is not signed straight back in). */
async function signOut(done: () => void) {
  const res = await api<{ data?: { logout_url?: string | null } } | undefined>(
    "auth/logout",
    "POST",
  );
  const url = res?.data?.logout_url;
  if (url && /^https?:\/\//.test(url)) window.location.assign(url);
  else done();
}
export default function App() {
  const location = useLocation();
  return location.pathname.startsWith("/platform") ? (
    <ErrorBoundary>
      <PlatformApp />
    </ErrorBoundary>
  ) : (
    <TenantApp />
  );
}

function TenantApp() {
  const auth = useData<{ data: Session }>("auth/me");
  const [loggedOut, setLoggedOut] = useState(false);
  if (auth.loading) return <Loading />;
  if (loggedOut || !auth.data) {
    if (
      auth.error &&
      !loggedOut &&
      !auth.error.includes("Unauthenticated") &&
      !auth.error.includes("Sesi telah berakhir")
    )
      return (
        <div className="login-error">
          <ErrorBox error={auth.error} />
          <div className="actions">
            <button onClick={auth.refresh}>Coba lagi</button>
            <button
              onClick={() => {
                void api("auth/logout", "POST")
                  .then(() => setLoggedOut(true))
                  .catch(() => auth.refresh());
              }}
            >
              Kembali ke login
            </button>
          </div>
        </div>
      );
    return (
      <Login
        onLogin={() => {
          setLoggedOut(false);
          auth.refresh();
        }}
      />
    );
  }
  if (isSupplierSession(auth.data.data))
    return (
      <SessionContext.Provider value={auth.data.data}>
        <SupplierPortal onLogout={() => signOut(() => setLoggedOut(true))} />
      </SessionContext.Provider>
    );
  return (
    <SessionContext.Provider value={auth.data.data}>
      <Routes>
        <Route
          element={<Shell onLogout={() => signOut(() => setLoggedOut(true))} />}
        >
          <Route index element={<Dashboard />} />
          <Route path="master" element={<MasterPage />} />
          <Route path="master/:resource" element={<MasterPage />} />
          <Route path="asns" element={<AsnListPage />} />
          <Route path="asns/:id" element={<AsnDetailPage />} />
          <Route path="docks" element={<DockPage />} />
          <Route path="receiving" element={<ReceivingPage />} />
          <Route path="receiving/:id" element={<ReceivingPage />} />
          <Route path="qc" element={<QcPage />} />
          <Route path="putaway" element={<PutawayPage />} />
          <Route path="stock-map" element={<StockMapPage />} />
          <Route path="stocktakes" element={<StocktakeListPage />} />
          <Route path="stocktakes/:id" element={<StocktakeDetailPage />} />
          <Route path="analysis" element={<AnalysisPage />} />
          <Route path="locations" element={<LocationsPage />} />
          <Route path="supplier-accounts" element={<SupplierAccountsPage />} />
          <Route path="integration" element={<IntegrationPage />} />
          <Route path="access" element={<AccessPage />} />
          <Route path="*" element={<NotFound />} />
        </Route>
        <Route path="login" element={<Navigate to="/" replace />} />
      </Routes>
    </SessionContext.Provider>
  );
}
function NotFound() {
  return (
    <section className="card">
      <h1 tabIndex={-1}>Halaman tidak ditemukan</h1>
      <p>Alamat ini tidak tersedia.</p>
      <Link to="/">Kembali ke ringkasan</Link>
    </section>
  );
}
// Exported for tests which exercise loading/error states without bypassing the server authorization boundary.
export { ApiError };
