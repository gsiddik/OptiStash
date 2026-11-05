import { useCallback, useEffect, useState } from "react";
export class ApiError extends Error {
  status: number;
  fields: Record<string, string[]>;
  constructor(
    message: string,
    status: number,
    fields: Record<string, string[]> = {},
  ) {
    super(message);
    this.status = status;
    this.fields = fields;
  }
}
let csrfToken: string | undefined;
export async function csrf() {
  const res = await fetch("/api/v1/csrf", {
    credentials: "same-origin",
    headers: { Accept: "application/json" },
  });
  if (!res.ok)
    throw new ApiError(
      "Tidak dapat menyiapkan sesi aman. Coba lagi.",
      res.status,
    );
  csrfToken = ((await res.json()) as { token: string }).token;
}
export async function api<T>(
  path: string,
  method = "GET",
  body?: unknown,
  key?: string,
  signal?: AbortSignal,
): Promise<T> {
  if (method !== "GET" && !csrfToken) await csrf();
  const res = await fetch(`/api/v1/${path}`, {
    method,
    credentials: "same-origin",
    signal,
    headers: {
      Accept: "application/json",
      ...(body !== undefined && !(body instanceof FormData)
        ? { "Content-Type": "application/json" }
        : {}),
      ...(method !== "GET" ? { "X-CSRF-TOKEN": csrfToken! } : {}),
      ...(key ? { "Idempotency-Key": key } : {}),
    },
    ...(body !== undefined
      ? { body: body instanceof FormData ? body : JSON.stringify(body) }
      : {}),
  });
  if (res.status === 204) {
    if (path === "auth/logout") csrfToken = undefined;
    return undefined as T;
  }
  const data = (await res.json().catch(() => {
    throw new ApiError(
      "Respons server tidak valid. Coba muat ulang atau hubungi administrator.",
      res.status,
    );
  })) as {
    message?: string;
    errors?: Record<string, string[]>;
  };
  if (!res.ok) {
    if (res.status === 419) csrfToken = undefined;
    throw new ApiError(
      data.message ?? `Permintaan gagal (${res.status}).`,
      res.status,
      data.errors,
    );
  }
  if (path === "auth/logout") csrfToken = undefined;
  return data as T;
}
export function useData<T>(path: string) {
  const [data, setData] = useState<T>();
  const [error, setError] = useState<string>();
  const [loading, setLoading] = useState(true);
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(undefined);
    setData(undefined);
    api<T>(path, "GET", undefined, undefined, controller.signal)
      .then(setData)
      .catch((e: unknown) => {
        if (!controller.signal.aborted)
          setError(e instanceof Error ? e.message : "Tidak dapat memuat data.");
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [path, revision]);
  const refresh = useCallback(() => setRevision((v) => v + 1), []);
  return { data, error, loading, refresh };
}
