import { test, expect } from "@playwright/test";

// Sanity check that the saved auth session works — every CiviCRM install
// has /civicrm/dashboard, so this passes regardless of which extension you
// mount. Replace with real tests against your extension's UI.

test("logged-in user reaches the dashboard", async ({ page }) => {
  await page.goto("/civicrm/dashboard");
  await expect(page).not.toHaveURL(/\/civicrm\/login/);
});

// Example: test your own extension's settings page. Adjust the URL and
// the heading to whatever your extension renders.
//
// test("my extension settings page renders", async ({ page }) => {
//   await page.goto("/civicrm/admin/myextension/settings");
//   await expect(page.getByRole("heading", { name: "My Extension" })).toBeVisible();
// });
