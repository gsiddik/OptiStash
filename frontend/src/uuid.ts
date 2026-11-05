/**
 * A random version 4 UUID, used as the idempotency key of a save.
 *
 * `crypto.randomUUID` exists only in a secure context (HTTPS or localhost). The local trial and many on-premise installations are
 * reached over plain http on a real host name (http://stash.localtest.me:5210), where it is undefined and calling it while a screen
 * renders blanks the whole page. `crypto.getRandomValues` is available everywhere, so it is the fallback.
 */
export function newId(): string {
  const c: Crypto | undefined = globalThis.crypto;
  if (typeof c?.randomUUID === "function") return c.randomUUID();
  const bytes = new Uint8Array(16);
  if (typeof c?.getRandomValues === "function") c.getRandomValues(bytes);
  else
    for (let i = 0; i < bytes.length; i++)
      bytes[i] = Math.floor(Math.random() * 256);
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const h = Array.from(bytes, (b) => b.toString(16).padStart(2, "0"));
  return `${h.slice(0, 4).join("")}-${h.slice(4, 6).join("")}-${h.slice(6, 8).join("")}-${h.slice(8, 10).join("")}-${h.slice(10).join("")}`;
}
