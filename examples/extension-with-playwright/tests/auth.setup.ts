import { test as setup, expect } from "@playwright/test";

// Log in once and save the session to .auth/admin.json. DEMO_USER / DEMO_PASS
// override the image's admin / admin.

const authFile = ".auth/admin.json";
const DEMO_USER = process.env.DEMO_USER ?? "admin";
const DEMO_PASS = process.env.DEMO_PASS ?? "admin";

setup("authenticate as admin", async ({ page }) => {
  await page.goto("/civicrm/login");

  // crmLogin Angular module renders fields with ARIA labels.
  await page.getByRole("textbox", { name: "Username" }).fill(DEMO_USER);
  await page.getByRole("textbox", { name: "Password" }).fill(DEMO_PASS);
  await page.getByRole("button", { name: /log in/i }).click();

  await expect(page).not.toHaveURL(/\/civicrm\/login/, { timeout: 15_000 });
  // CiviCRM's menubar renders only for a logged-in user.
  await expect(page.getByRole("navigation").getByRole("link", { name: "Home" })).toBeVisible({
    timeout: 15_000,
  });

  await page.context().storageState({ path: authFile });
});
