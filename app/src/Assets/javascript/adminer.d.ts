/**
 * window.adminer: one name for everything the page and the app share, in two halves. This is
 * the list of both, and `make qa` type-checks every page script against it (tsconfig.json), so
 * a member used or added without a line here fails the build. A new one is a line here first;
 * the README's list says what it is for.
 */
interface Window {
	adminer: {
		page: AdminerPage;
		/** Only in the app, never in a plain browser or the e2e, so always reached with ?. */
		native?: AdminerNative;
	};
}

/**
 * What our page scripts (and, for demo, PHP) offer each other. Plain scripts, not modules, so
 * what one file gives another goes here, and the giver sorts earlier by name. api.js creates it.
 */
interface AdminerPage {
	/** Our api.php actions (api.js). */
	api: { resize: string; sidebar: string };
	/** A string of ours, translated (api.js). */
	lang(id: string, fallback: string): string;
	/** Show or hide the activity indicator (busy.js). */
	busy(on?: boolean): void;
	/** Navigate, with the indicator up (busy.js). */
	go(href: string): void;
	/** A select page's rows, in place; false when it fell back to a full load, null when stopped
	 * (refresh.js). */
	refresh(href?: string): Promise<boolean | null>;
	/** Open a modal.latte dialog by id (modal.js). */
	openDialog(id: string): void;
	/** Open settings, unless another dialog is up (shortcuts.js). */
	openSettings(): void;
	/** Open settings on the shortcuts tab, unless another dialog is up (shortcuts.js). */
	openShortcuts(): void;
	/** A brief confirmation (toast.js). */
	toast(message: string): void;
	/** Open the table search (search.js), on a page that has it. */
	search?(): void;
	/** Keep settings open across the next load (settings-dialog.js), on a page that has it. */
	reopenSettings?(): void;
	/** Drag an edge to a width (drag-width.js). */
	dragWidth(
		edge: HTMLElement,
		how: { grab: () => number; set: (width: number) => void; done: () => void },
	): void;
	/** The `make demo` connection, set by AdminerDesktop::head() before any script runs. */
	demo?: string;
}

/** What the launcher binds, through bindNative() in launcher/main.go. */
interface AdminerNative {
	/** The loader has parsed: show the window (launcher/loader/loading.html). */
	loaderShown(): void;
	/** The page's colour, for the strip a fast resize uncovers. */
	setBackground(css: string): void;
	/** What the sidebar's material is drawn in. */
	setAppearance(mode: "light" | "dark" | "auto"): void;
	/** Move the window from a press on the toolbar band (macOS, frameless only). */
	dragWindow?(): void;
	/** Zoom or minimise, as the system setting says (macOS, frameless only). */
	titlebarDoubleClick?(): void;
}
