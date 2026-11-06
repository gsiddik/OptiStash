import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { seed } from "./seed";

/**
 * Every console page opens without the error screen, a script error or a failing API call, first empty and then with data
 * (e2e/seed.ts writes a small warehouse through the API). Needs a local-mode OptiStash (tenant demo, user warehouse_manager@optistash.test):
 *   OPTISTASH_E2E_BASE=http://127.0.0.1:5211 OPTISTASH_E2E_PASSWORD=... npx playwright test
 */
const PASSWORD = process.env.OPTISTASH_E2E_PASSWORD ?? "";
const MASTER = [
  "warehouses",
  "uoms",
  "product_categories",
  "products",
  "product_packaging",
  "partners",
];
const STATIC = [
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
  ...MASTER.map((m) => `/master/${m}`),
  "/integration",
  "/orchestrator",
  "/iot",
  "/access",
];

const problems: string[] = [];
let page: Page;

async function login(p: Page) {
  await p.goto("/");
  await p.getByLabel("Tenant").first().fill("demo");
  await p.getByLabel("Email").first().fill("warehouse_manager@optistash.test");
  await p.getByLabel("Kata sandi").first().fill(PASSWORD);
  await p.getByRole("button", { name: "Masuk", exact: true }).first().click();
  await expect(p.getByRole("navigation").first()).toBeVisible({
    timeout: 20000,
  });
}

export async function visit(p: Page, route: string) {
  const before = problems.length;
  await p.goto(route);
  await p.waitForLoadState("networkidle");
  await p.waitForTimeout(400);
  const body = (await p.locator("body").innerText()).slice(0, 4000);
  if (body.includes("Halaman ini tidak dapat ditampilkan"))
    problems.push(
      `${route}: error screen: ${body.match(/tidak dapat ditampilkan\.?\s*([^\n]*)/)?.[1] ?? ""}`,
    );
  if ((await p.locator("h1, h2").count()) === 0)
    problems.push(`${route}: no heading`);
  return problems.slice(before);
}

test.describe.configure({ mode: "serial" });
test.beforeAll(async ({ browser }) => {
  page = await browser.newPage();
  page.on("pageerror", (e) =>
    problems.push(`${page.url()}: script error: ${e.message}`),
  );
  page.on("console", (m) => {
    if (
      m.type() === "error" &&
      !/favicon|Failed to load resource: the server responded with a status of (401|403|404|409|422)/.test(
        m.text(),
      )
    )
      problems.push(`${page.url()}: console: ${m.text().slice(0, 200)}`);
  });
  page.on("response", (r) => {
    if (r.url().includes("/api/") && r.status() >= 500)
      problems.push(
        `${page.url()}: ${r.status()} ${r.request().method()} ${r.url().replace(/^https?:\/\/[^/]+/, "")}`,
      );
  });
  await login(page);
});

test("empty warehouse: every page opens", async () => {
  test.setTimeout(600000);
  const failed: string[] = [];
  for (const route of STATIC) {
    failed.push(...(await visit(page, route)));
    await page.waitForTimeout(700);
  }
  expect(failed, failed.join("\n")).toEqual([]);
});

test("with data: every page and every detail page opens", async () => {
  test.setTimeout(900000);
  const ids = await seed(
    process.env.OPTISTASH_E2E_BASE ?? "http://127.0.0.1:5185",
    PASSWORD,
  );

  const routes = [
    ...STATIC,
    `/asns/${ids.asn}`,
    `/receiving/${ids.asn}`,
    `/outbound/${ids.order}`,
    `/waves/${ids.wave}`,
    `/capas/${ids.capa}`,
    `/documents/${ids.document}`,
    `/stocktakes/${ids.stocktake}`,
  ];
  const failed: string[] = [];
  for (const route of routes) {
    failed.push(...(await visit(page, route)));
    await page.waitForTimeout(700);
  }
  expect(failed, failed.join("\n")).toEqual([]);
});

test("unknown address shows the not-found page, not the error screen", async () => {
  await page.goto("/tidak-ada");
  await expect(page.locator("body")).not.toContainText(
    "Halaman ini tidak dapat ditampilkan",
  );
});

test.afterAll(async () => {
  console.log(`problems found: ${problems.length}`);
  for (const p of problems) console.log(" - " + p);
});

test("shipments show what the carrier reported", async () => {
  test.skip(
    !process.env.OPTISTASH_E2E_EXPECT_CARRIER,
    "needs carrier events written for the dispatched shipment",
  );
  await page.goto("/shipments");
  await page.waitForLoadState("networkidle");
  await expect(page.locator("body")).toContainText("Carrier (OptiDeli)");
  await expect(page.locator("body")).toContainText("Sudah dijemput");
  await page.getByText("Riwayat status").first().click();
  await expect(page.locator("body")).toContainText("Diterima carrier");
});
