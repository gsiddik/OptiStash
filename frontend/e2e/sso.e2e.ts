import { randomBytes } from "node:crypto";
import fs from "node:fs";
import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";

/**
 * Sign-in through OptiNexus (OIDC) as a tenant administrator, then every module of the application opens: the OptiStash permissions that
 * OptiNexus grants reach the console, every menu entry is there, and no page answers "not allowed". Needs the account written by the
 * operator to a file (email, temporary_password or password): OPTISTASH_E2E_ACCOUNT_FILE. The first run activates the account (chooses a
 * password) and rewrites the file with it.
 *   OPTISTASH_E2E_BASE=http://stash.localtest.me:5210 OPTISTASH_E2E_ACCOUNT_FILE=/state/stash-demo-admin.txt npx playwright test sso
 */
const FILE = process.env.OPTISTASH_E2E_ACCOUNT_FILE ?? "";
const ROUTES = [
  "/",
  "/locations",
  "/supplier-accounts",
  "/asns",
  "/docks",
  "/receiving",
  "/qc",
  "/putaway",
  "/outbound",
  "/waves",
  "/shipments",
  "/stock-map",
  "/stocktakes",
  "/analysis",
  "/capas",
  "/instruments",
  "/documents",
  "/iso-map",
  "/audit",
  "/master",
  "/master/warehouses",
  "/master/uoms",
  "/master/product_categories",
  "/master/products",
  "/master/product_packaging",
  "/master/partners",
  "/integration",
  "/orchestrator",
  "/iot",
  "/access",
];
/** One permission per module and per area: if OptiNexus does not grant it, the matching menu or page is missing. */
const MUST_HAVE = [
  "wms.access",
  "master.manage",
  "partner.provision",
  "asn.manage",
  "receiving.execute",
  "putaway.execute",
  "outbound.manage",
  "wave.approve",
  "inventory.lock",
  "stocktake.manage",
  "inventory.analysis.configure",
  "capa.manage",
  "sop.manage",
  "calibration.manage",
  "audit.view",
  "integration.manage",
  "iot.manage",
];

const problems: string[] = [];
let page: Page;

function account() {
  const kv = Object.fromEntries(
    fs
      .readFileSync(FILE, "utf8")
      .split(/\r?\n/)
      .filter((l) => l.includes("="))
      .map((l) => [l.slice(0, l.indexOf("=")), l.slice(l.indexOf("=") + 1)]),
  );
  return kv as Record<string, string>;
}

function strongPassword() {
  return "Qa!" + randomBytes(12).toString("base64url") + "9aZ";
}

test.describe.configure({ mode: "serial" });

test.beforeAll(async ({ browser }) => {
  page = await browser.newPage();
  page.on("pageerror", (e) =>
    problems.push(`${page.url()}: script error: ${e.message}`),
  );
  page.on("response", (r) => {
    if (r.url().includes("/api/") && r.status() >= 500)
      problems.push(
        `${page.url()}: ${r.status()} ${r.request().method()} ${r.url().replace(/^https?:\/\/[^/]+/, "")}`,
      );
  });
});

test("sign in through OptiNexus (first time: choose a password)", async () => {
  test.setTimeout(180000);
  const a = account();
  await page.goto("/");
  await page.getByRole("link", { name: /Masuk dengan OptiNexus/ }).click();
  await page.waitForURL(/\/oidc\/login/);
  await page.locator("#email").fill(a.email);
  await page.locator("#password").fill(a.password ?? a.temporary_password);
  await page.getByRole("button", { name: "Sign in" }).click();
  if (a.temporary_password && !a.password) {
    await page.waitForURL(/\/oidc\/activate/);
    const fresh = strongPassword();
    await page
      .locator('input[name="temporary_password"]')
      .fill(a.temporary_password);
    await page.locator('input[name="password"]').fill(fresh);
    await page.locator('input[name="password_confirmation"]').fill(fresh);
    await page.getByRole("button", { name: /Save password/ }).click();
    fs.writeFileSync(
      FILE,
      `tenant=${a.tenant ?? ""}\nemail=${a.email}\npassword=${fresh}\nnote=activated by the SSO QA; the temporary password is gone\n`,
    );
  }
  // OptiNexus may need one more round trip to hand the code to the console.
  await page.waitForLoadState("networkidle");
  if (!page.url().startsWith(process.env.OPTISTASH_E2E_BASE ?? "")) {
    await page.goto("/");
    const sso = page.getByRole("link", { name: /Masuk dengan OptiNexus/ });
    if (await sso.count()) await sso.click();
  }
  await expect(page.getByRole("navigation").first()).toBeVisible({
    timeout: 30000,
  });
});

test("OptiNexus grants the console every permission of the modules the tenant subscribes to", async () => {
  const me = await page.evaluate(() =>
    fetch("/api/v1/auth/me").then((r) => r.json()),
  );
  const permissions: string[] = me.data.permissions;
  console.log(
    `granted permissions: ${permissions.length} | tenant ${me.data.tenant.name} | role label ${me.data.role}`,
  );
  const missing = MUST_HAVE.filter((p) => !permissions.includes(p));
  expect(missing, `not granted: ${missing.join(", ")}`).toEqual([]);
  expect(permissions).not.toContain("supplier.portal"); // an internal person never becomes a supplier
  expect(me.data.tenant.supplier).toBe(false);
});

test("every menu entry is there and every page opens without a refusal", async () => {
  test.setTimeout(600000);
  const hrefs = await page
    .locator("nav a")
    .evaluateAll((els) =>
      els.map((e) => (e as HTMLAnchorElement).getAttribute("href")),
    );
  const absent = ROUTES.filter((r) => r !== "/master" && !hrefs.includes(r)); // the master data menu is a tree of /master/<resource> entries;
  expect(absent, `menu entries missing: ${absent.join(", ")}`).toEqual([]);
  const failed: string[] = [];
  for (const route of ROUTES) {
    const before = problems.length;
    await page.goto(route);
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(500);
    const body = await page.locator("body").innerText();
    if (body.includes("Halaman ini tidak dapat ditampilkan"))
      failed.push(`${route}: error screen`);
    const refusal = body.match(
      /(Akun Anda tidak boleh[^\n]*|tidak memiliki izin[^\n]*|Active membership required|Gunakan portal Anda)/,
    );
    if (refusal) failed.push(`${route}: refused: ${refusal[0]}`);
    failed.push(...problems.slice(before));
    await page.waitForTimeout(2200);
  }
  expect(failed, failed.join("\n")).toEqual([]);
});

test("signing out ends the session in the console and password sign-in stays closed", async () => {
  const closed = await page.evaluate(() =>
    fetch("/api/v1/csrf")
      .then((r) => r.json())
      .then((c) =>
        fetch("/api/v1/auth/login", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-TOKEN": c.token,
          },
          body: JSON.stringify({
            email: "x@x.test",
            password: "x",
            tenant: "x",
          }),
        }).then((r) => r.status),
      ),
  );
  expect(closed).toBe(403);
  const trail: string[] = [];
  page.on("framenavigated", (f) => {
    if (f === page.mainFrame()) trail.push(f.url().split("?")[0]);
  });
  await page
    .getByRole("button", { name: /Keluar/ })
    .first()
    .click();
  await page.waitForTimeout(8000);
  console.log("after Keluar: " + trail.join(" -> "));
  await page.goto("/");
  const sso = page.getByRole("link", { name: /Masuk dengan OptiNexus/ });
  await expect(sso).toBeVisible({ timeout: 20000 });
  // The OptiNexus session ended too: signing in again asks for the credentials instead of walking straight in.
  await sso.click();
  await page.waitForLoadState("networkidle");
  console.log("sign in again lands on: " + page.url().split("?")[0]);
  await expect(page).toHaveURL(/\/oidc\/login/);
});
