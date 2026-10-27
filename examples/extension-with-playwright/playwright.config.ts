import { defineConfig, devices } from "@playwright/test";

// UI test starter for a CiviCRM extension. Boot the compose stack in this
// directory (`docker compose up -d`), then `ck playwright npm run test:e2e`.
//
// The `setup` project logs in once and saves the session to `.auth/admin.json`.
// The `chromium` project loads that storage state, so individual tests start
// already authenticated — no per-test login boilerplate.

export default defineConfig({
  testDir: "./tests",
  timeout: 30_000,
  fullyParallel: true,
  reporter: process.env.CI ? [["github"], ["html", { open: "never" }]] : "list",
  use: {
    baseURL: process.env.CIVICRM_BASE_URL ?? "http://localhost:8080",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [
    { name: "setup", testMatch: /.*\.setup\.ts/ },
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"], storageState: ".auth/admin.json" },
      dependencies: ["setup"],
      testIgnore: /.*\.setup\.ts/,
    },
  ],
});
