import { defineConfig } from "@playwright/test";
export default defineConfig({
  testDir: "./e2e",
  testMatch: "**/*.e2e.ts",
  workers: 1,
  timeout: 45000,
  use: {
    baseURL: process.env.OPTISTASH_E2E_BASE ?? "http://127.0.0.1:5185",
    headless: true,
    timezoneId: "Asia/Bangkok",
    launchOptions: {
      executablePath: process.env.CHROMIUM_PATH ?? "/usr/bin/chromium",
      args: [
        "--no-sandbox",
        ...(process.env.OPTISTASH_E2E_HOST_RULES
          ? [`--host-resolver-rules=${process.env.OPTISTASH_E2E_HOST_RULES}`]
          : []),
      ],
    },
    screenshot: "only-on-failure",
    trace: "off",
  },
  reporter: [["list"]],
  outputDir: "test-results",
});
