import { useState } from "react";
import { api, useData } from "./api";
import { ErrorBox, Field, Form, Loading } from "./components";

type Preview = {
  name: string;
  email: string;
  supplier: string;
  tenant: string;
};

/** The page an invited supplier person opens from the email: they choose their own password once. */
export function AcceptInvitationPage({ token }: { token: string }) {
  const preview = useData<{ data: Preview }>(`auth/invitations/${token}`);
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [done, setDone] = useState<{ email: string; tenant: string }>();
  if (preview.loading) return <Loading />;
  const info = preview.data?.data;
  return (
    <main className="login">
      <section className="login-form">
        <h1 tabIndex={-1}>Undangan portal pemasok</h1>
        {done ? (
          <>
            <p>
              Akun <strong>{done.email}</strong> sudah dibuat. Masuk dengan kode
              tenant <strong>{done.tenant}</strong> dan kata sandi yang baru
              Anda pilih.
            </p>
            <a className="button" href="/">
              Ke halaman masuk
            </a>
          </>
        ) : !info ? (
          <>
            <ErrorBox error={preview.error} />
            <p className="muted">
              Undangan tidak ditemukan, sudah dipakai, atau kedaluwarsa. Minta
              undangan baru kepada administrator gudang.
            </p>
          </>
        ) : (
          <>
            <p>
              Halo <strong>{info.name}</strong>. {info.tenant} mengundang Anda
              memakai portal pemasok untuk <strong>{info.supplier}</strong>.
              Email akun: <strong>{info.email}</strong>.
            </p>
            <Form
              label="Buat akun"
              disabled={password === "" || password !== confirmation}
              onSubmit={async () => {
                const res = await api<{
                  data: { email: string; tenant: string };
                }>(`auth/invitations/${token}/accept`, "POST", {
                  password,
                  password_confirmation: confirmation,
                });
                setDone(res.data);
              }}
            >
              <Field
                label="Kata sandi"
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
              <Field label="Ulangi kata sandi">
                <input
                  type="password"
                  autoComplete="new-password"
                  value={confirmation}
                  onChange={(e) => setConfirmation(e.target.value)}
                  required
                  maxLength={1024}
                />
              </Field>
            </Form>
          </>
        )}
      </section>
    </main>
  );
}
