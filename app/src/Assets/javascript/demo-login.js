/**
 * Auto-login for `make demo`.
 *
 * The launcher forwards ADMINER_DESKTOP_DEMO and desktop.php turns it into
 * window.desktopDemo — a throwaway "driver server username password db". On the login page
 * we fill those in and submit, so `make demo` drops straight into the seeded database.
 *
 * Only `make demo` ever sets that global; a shipped build never defines it, so this stays
 * inert everywhere else. It also runs at most once per tab: a wrong connection lands back
 * on the login form to fix by hand rather than resubmitting forever.
 */

const desktopDemo = window.desktopDemo;
const driver = document.querySelector('[name="auth[driver]"]');

if (desktopDemo && driver && !sessionStorage.getItem("desktop-demo-done")) {
	sessionStorage.setItem("desktop-demo-done", "1");
	const [driverValue, server, username, password, db] = desktopDemo.split(" ");
	const form = driver.form;

	driver.value = driverValue;
	// Adminer rebuilds the auth fields when the driver changes, so fill and submit only
	// after that settles — otherwise the rebuild wipes the values back out.
	driver.dispatchEvent(new Event("change", { bubbles: true }));
	setTimeout(() => {
		for (const [name, value] of Object.entries({
			server,
			username,
			password,
			db,
		})) {
			const field = form.querySelector(`[name="auth[${name}]"]`);
			if (field) {
				field.value = value;
			}
		}
		form.requestSubmit();
	}, 500);
}
