// Playwright helper: log the preview postgres in and land on a table, ready to screenshot.
//
// Drive it through the Playwright MCP:
//   browser_run_code_unsafe { filename: "dev/preview.login.js" }
// then browser_take_screenshot. For dark mode, first run
//   async (page) => { await page.emulateMedia({ colorScheme: 'dark' }); }
// Requires dev/preview.sh (or `make preview`) running the server + demo DB.
async (page) => {
	await page.goto("http://127.0.0.1:18000/adminer.php");
	await page.selectOption('select[name="auth[driver]"]', "pgsql");
	await page.waitForTimeout(200);
	await page.fill('input[name="auth[server]"]', "127.0.0.1:55432");
	await page.fill('input[name="auth[username]"]', "postgres");
	await page.fill('input[name="auth[password]"]', "demo");
	await page.fill('input[name="auth[db]"]', "demo");
	await page.click('input[type="submit"]');
	await page.waitForLoadState("networkidle");
	// jump straight to the users table's data view
	const base = page.url().split("#")[0];
	await page.goto(base + (base.includes("?") ? "&" : "?") + "select=users");
	await page.waitForLoadState("networkidle");
	return page.url();
};
