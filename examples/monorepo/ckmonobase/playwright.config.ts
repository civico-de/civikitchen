import { defineConfig, devices } from "@playwright/test";

// The starter suite of examples/extension-with-playwright against this
// extension's stack, so the monorepo self-test covers the CI browser job.
export default defineConfig({
  testDir: "../../extension-with-playwright/tests",
  reporter: process.env.CI ? [["github"], ["html", { open: "never" }]] : "list",
  use: {
    baseURL: process.env.CIVICRM_BASE_URL ?? "http://localhost:8080",
    trace: "retain-on-failure",
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
