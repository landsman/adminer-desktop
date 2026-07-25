<?php
declare(strict_types=1);

// Native-shell strings, English — the base locale. Keys are stable dotted IDs (topic.name), not
// the English text, so the wording can be reworded here without touching every call site; English
// is a translation like any other, and the canonical set of IDs plus their order lives here. One
// file per language (see cs.php); a translation that omits an ID degrades to the English here.
// Desktop\I18n\Generator turns these into each platform's own native format (macOS .strings, a C
// table for the Linux/Windows launcher).
//
// This is NOT adminer's own UI (localised upstream) nor our PHP plugin UI (localised by
// AdminerDesktop::$translations). dialog.cancel here is a button; the plugin's own 'Cancel' is the
// settings dialog's close ("Zavřít"), a different string.
//
// Placeholders are macOS-style (%@, %1$@); the generator rewrites them to printf (%s, %1$s) for
// the C table.

return [
	// Menu bar (menu_darwin.m). Adminer and Editor are product names and stay untranslated.
	'menu.about' => 'About Adminer Desktop',
	'menu.open_logs' => 'Open Logs',
	'menu.quit' => 'Quit Adminer Desktop',
	'menu.edit' => 'Edit',
	'menu.undo' => 'Undo',
	'menu.redo' => 'Redo',
	'menu.cut' => 'Cut',
	'menu.copy' => 'Copy',
	'menu.paste' => 'Paste',
	'menu.select_all' => 'Select All',
	'menu.help' => 'Help',
	'menu.adminer_website' => 'Adminer Website',
	'menu.github' => 'adminer-desktop on GitHub',
	'menu.report_issue' => 'Report an Issue',

	// About panel credits (menu_darwin.m). %1$@ adminer version, %2$@ frankenphp version,
	// %3$@ app version. macOS-only; the Linux/Windows launcher has no About panel.
	'about.credits' => "Adminer %1\$@ — by Jakub Vrana and contributors\nApache-2.0 / GPL-2.0 · https://www.adminer.org\n\nRuntime: FrankenPHP %2\$@ (Caddy + PHP)\nMIT · https://frankenphp.dev\n\nadminer-desktop %3\$@ — MIT\nhttps://github.com/landsman/adminer-desktop\n\nThis app does not modify Adminer. It downloads the official release at a pinned version, verifies its checksum, and runs it in a native window.",

	// JavaScript alert/confirm/prompt buttons (dialogs_darwin.m).
	'dialog.ok' => 'OK',
	'dialog.cancel' => 'Cancel',

	// Export download UI (download_darwin.m, download_linux.c). %@ is the saved file's name.
	'download.save_title' => 'Save Export',
	'download.saved' => 'Saved %@',
	'download.cancel_confirm' => 'Cancel this download?',
	'download.cancel_button' => 'Cancel Download',
	'download.keep' => 'Keep Downloading',
];
