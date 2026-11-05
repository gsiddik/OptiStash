import { useState } from "react";
import type { FormEvent } from "react";
import { api, useData } from "./api";
import { useSession } from "./App";
import { Badge, ErrorBox, Field, Loading } from "./components";
import { can } from "./opsTypes";

type Permission = { code: string; group: string; description: string };
type Role = {
  id: string;
  name: string;
  description: string | null;
  is_system: boolean;
  permissions: string[];
  member_count: number;
};
type Member = {
  id: number;
  user_id: number;
  name: string;
  email: string;
  active: boolean;
  vendor_id: string | null;
  roles: { id: string; name: string }[];
};

/** Tenant roles and member assignment. The backend enforces every guard; this page only offers what it would accept. */
export function AccessPage() {
  const session = useSession();
  const roles = useData<{ data: Role[] }>("access/roles");
  const catalog = useData<{ data: Permission[] }>("access/permissions");
  // In OptiNexus mode roles and assignments live in OptiNexus; this page only shows them.
  const managed = session.identity_mode === "optinexus";
  const manageRoles = !managed && can(session, "access.role.manage");
  const manageUsers = !managed && can(session, "access.user.manage");
  return (
    <>
      <div className="page-heading">
        <div>
          <span className="eyebrow">GOVERNANCE</span>
          <h1 tabIndex={-1}>Akses & role</h1>
          <p>
            Role adalah kumpulan izin. Role sistem mengikuti katalog dan tidak
            dapat diubah; buat role kustom untuk kebutuhan lain.
          </p>
        </div>
      </div>
      {managed && (
        <div className="alert" role="status">
          Akses dikelola di OptiNexus. Izin Anda di OptiStash mengikuti role yang
          diberikan di OptiNexus.
        </div>
      )}
      <ErrorBox error={roles.error ?? catalog.error} />
      {manageRoles && catalog.data && (
        <RoleForm
          permissions={catalog.data.data}
          own={session.permissions}
          onDone={roles.refresh}
        />
      )}
      <section className="card">
        <h2>Role</h2>
        {roles.loading ? (
          <Loading />
        ) : (
          roles.data && (
            <div className="table-scroll">
              <table>
                <thead>
                  <tr>
                    <th>Role</th>
                    <th>Jenis</th>
                    <th>Anggota</th>
                    <th>Izin</th>
                    {manageRoles && <th>Aksi</th>}
                  </tr>
                </thead>
                <tbody>
                  {roles.data.data.map((r) => (
                    <tr key={r.id}>
                      <td>{r.name}</td>
                      <td>
                        <Badge value={r.is_system ? "SYSTEM" : "CUSTOM"} />
                      </td>
                      <td>{r.member_count}</td>
                      <td>{r.permissions.join(", ")}</td>
                      {manageRoles && (
                        <td>
                          {!r.is_system && r.member_count === 0 && (
                            <DeleteRole id={r.id} onDone={roles.refresh} />
                          )}
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )
        )}
      </section>
      {manageUsers && roles.data && (
        <Members roles={roles.data.data} selfId={session.id} />
      )}
    </>
  );
}

function RoleForm({
  permissions,
  own,
  onDone,
}: {
  permissions: Permission[];
  own: string[];
  onDone: () => void;
}) {
  const [name, setName] = useState("");
  const [selected, setSelected] = useState<string[]>([]);
  const [error, setError] = useState<unknown>();
  const [busy, setBusy] = useState(false);
  const grantable = permissions.filter((p) => own.includes(p.code));
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(undefined);
    try {
      await api("access/roles", "POST", { name, permissions: selected });
      setName("");
      setSelected([]);
      onDone();
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };
  return (
    <section className="card">
      <h2>Buat role kustom</h2>
      <form onSubmit={submit}>
        <ErrorBox error={error} />
        <Field label="Nama role">
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            minLength={3}
            maxLength={100}
            required
          />
        </Field>
        <fieldset className="permission-grid">
          <legend>Izin (hanya izin yang Anda miliki)</legend>
          {grantable.map((p) => (
            <label className="check" key={p.code}>
              <input
                type="checkbox"
                checked={selected.includes(p.code)}
                onChange={(e) =>
                  setSelected((v) =>
                    e.target.checked
                      ? [...v, p.code]
                      : v.filter((c) => c !== p.code),
                  )
                }
              />{" "}
              <span>
                <code>{p.code}</code>
                <br />
                {p.description}
              </span>
            </label>
          ))}
        </fieldset>
        <div className="actions">
          <button
            className="primary"
            disabled={busy || selected.length === 0}
            type="submit"
          >
            Simpan role
          </button>
        </div>
      </form>
    </section>
  );
}

function DeleteRole({ id, onDone }: { id: string; onDone: () => void }) {
  const [error, setError] = useState<unknown>();
  return (
    <>
      <button
        onClick={async () => {
          try {
            await api(`access/roles/${id}`, "DELETE");
            onDone();
          } catch (err) {
            setError(err);
          }
        }}
      >
        Hapus
      </button>
      <ErrorBox error={error} />
    </>
  );
}

function Members({ roles, selfId }: { roles: Role[]; selfId: number }) {
  const members = useData<{ data: Member[] }>("access/members");
  if (members.loading) return <Loading />;
  return (
    <section className="card">
      <h2>Anggota</h2>
      <ErrorBox error={members.error} />
      {members.data && (
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Nama</th>
                <th>Email</th>
                <th>Role</th>
              </tr>
            </thead>
            <tbody>
              {members.data.data.map((m) => (
                <tr key={m.id}>
                  <td>{m.name}</td>
                  <td>{m.email}</td>
                  <td>
                    {m.user_id === selfId ? (
                      m.roles.map((r) => r.name).join(", ")
                    ) : (
                      <MemberRoles
                        member={m}
                        roles={roles}
                        onDone={members.refresh}
                      />
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function MemberRoles({
  member,
  roles,
  onDone,
}: {
  member: Member;
  roles: Role[];
  onDone: () => void;
}) {
  const [selected, setSelected] = useState(member.roles.map((r) => r.id));
  const [error, setError] = useState<unknown>();
  return (
    <div>
      {roles.map((r) => (
        <label className="check" key={r.id}>
          <input
            type="checkbox"
            checked={selected.includes(r.id)}
            onChange={(e) =>
              setSelected((v) =>
                e.target.checked ? [...v, r.id] : v.filter((x) => x !== r.id),
              )
            }
          />{" "}
          {r.name}
        </label>
      ))}
      <button
        disabled={selected.length === 0}
        onClick={async () => {
          setError(undefined);
          try {
            await api(`access/members/${member.id}/roles`, "PUT", {
              role_ids: selected,
            });
            onDone();
          } catch (err) {
            setError(err);
          }
        }}
      >
        Simpan role {member.name}
      </button>
      <ErrorBox error={error} />
    </div>
  );
}
