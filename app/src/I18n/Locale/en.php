<?php
declare(strict_types=1);

// Native-shell strings, English — the base locale: the canonical set of keys, and the source of
// their order in the generated output. One file per language (see cs.php); a translation that
// omits a key degrades to the English here. Desktop\I18n\Generator turns these into each
// platform's own native format (macOS .strings, a C table for the Linux/Windows launcher).
//
// This is NOT adminer's own UI (localised upstream) nor our PHP plugin UI (localised by
// AdminerDesktop::$translations). 'Cancel' here is a button; the plugin's own 'Cancel' is the
// settings dialog's close ("Zavřít"), a different string.
//
// Placeholders are macOS-style (%@, %1$@); the generator rewrites them to printf (%s, %1$s) for
// the C table.

return [
	// Menu bar (menu_darwin.m). Adminer and Editor are product names and stay untranslated.
	'About Adminer Desktop' => 'About Adminer Desktop',
	'Open Logs' => 'Open Logs',
	'Quit Adminer Desktop' => 'Quit Adminer Desktop',
	'Edit' => 'Edit',
	'Undo' => 'Undo',
	'Redo' => 'Redo',
	'Cut' => 'Cut',
	'Copy' => 'Copy',
	'Paste' => 'Paste',
	'Select All' => 'Select All',
	'Help' => 'Help',
	'Adminer Website' => 'Adminer Website',
	'adminer-desktop on GitHub' => 'adminer-desktop on GitHub',
	'Report an Issue' => 'Report an Issue',

	// About panel credits (menu_darwin.m). %1$@ adminer version, %2$@ frankenphp version,
	// %3$@ app version. macOS-only; the Linux/Windows launcher has no About panel.
	'credits.format' => "Adminer %1\$@ — by Jakub Vrana and contributors\nApache-2.0 / GPL-2.0 · https://www.adminer.org\n\nRuntime: FrankenPHP %2\$@ (Caddy + PHP)\nMIT · https://frankenphp.dev\n\nadminer-desktop %3\$@ — MIT\nhttps://github.com/landsman/adminer-desktop\n\nThis app does not modify Adminer. It downloads the official release at a pinned version, verifies its checksum, and runs it in a native window.",

	// JavaScript alert/confirm/prompt buttons (dialogs_darwin.m).
	'OK' => 'OK',
	'Cancel' => 'Cancel',

	// Export download UI (download_darwin.m, download_linux.c). %@ is the saved file's name.
	'Save Export' => 'Save Export',
	'Saved %@' => 'Saved %@',
	'Cancel this download?' => 'Cancel this download?',
	'Cancel Download' => 'Cancel Download',
	'Keep Downloading' => 'Keep Downloading',
];
