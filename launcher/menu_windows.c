#define WIN32_LEAN_AND_MEAN
#define _WIN32_WINNT 0x0A00
#include <windows.h>
#include "menu_windows.h"
#include <stdio.h>
#include "i18n_gen.h"

extern void goMenuCommand(int id);

static WNDPROC webviewProc;

// adTr picks its language from LANG, which Windows does not set; a value already there still wins.
static void useUILanguage(void) {
	wchar_t wide[LOCALE_NAME_MAX_LENGTH];
	char name[LOCALE_NAME_MAX_LENGTH];
	if (getenv("LANG") || getenv("LC_MESSAGES") ||
		!LCIDToLocaleName(GetUserDefaultUILanguage(), wide, LOCALE_NAME_MAX_LENGTH, 0) ||
		!WideCharToMultiByte(CP_UTF8, 0, wide, -1, name, sizeof name, NULL, NULL)) {
		return;
	}
	_putenv_s("LANG", name);
}

static wchar_t *wideText(const char *text) {
	int size = MultiByteToWideChar(CP_UTF8, 0, text, -1, NULL, 0);
	wchar_t *wide = malloc(size * sizeof(wchar_t));
	MultiByteToWideChar(CP_UTF8, 0, text, -1, wide, size);
	return wide;
}

static void addText(HMENU menu, int id, const char *text) {
	wchar_t *wide = wideText(text);
	AppendMenuW(menu, MF_STRING, id, wide);
	free(wide);
}

static void addItem(HMENU menu, int id, const char *key) { addText(menu, id, adTr(key)); }

static void addSubmenu(HMENU bar, HMENU menu, const char *title) {
	wchar_t *wide = wideText(title);
	AppendMenuW(bar, MF_POPUP, (UINT_PTR)menu, wide);
	free(wide);
}

// webview owns the window procedure and has no case for WM_COMMAND, so the menu's clicks are
// taken here and everything else goes through to it.
static LRESULT CALLBACK menuProc(HWND hwnd, UINT message, WPARAM wparam, LPARAM lparam) {
	if (message == WM_COMMAND && HIWORD(wparam) == 0) {
		goMenuCommand(LOWORD(wparam));
		return 0;
	}
	return CallWindowProcW(webviewProc, hwnd, message, wparam, lparam);
}

int adMenuInstall(void *hwnd) {
	useUILanguage();

	HMENU app = CreatePopupMenu();
	addText(app, AD_MENU_ADMINER, "Adminer");
	addText(app, AD_MENU_EDITOR, "Editor");
	AppendMenuW(app, MF_SEPARATOR, 0, NULL);
	// The tab is how Windows shows a shortcut in the item. The page handles Ctrl+, itself.
	char settings[128];
	snprintf(settings, sizeof settings, "%s\tCtrl+,", adTr("menu.settings"));
	addText(app, AD_MENU_SETTINGS, settings);
	AppendMenuW(app, MF_SEPARATOR, 0, NULL);
	addItem(app, AD_MENU_LOGS, "menu.open_logs");
	AppendMenuW(app, MF_SEPARATOR, 0, NULL);
	addItem(app, AD_MENU_QUIT, "menu.quit");

	HMENU help = CreatePopupMenu();
	addItem(help, AD_MENU_SHORTCUTS, "menu.shortcuts");
	AppendMenuW(help, MF_SEPARATOR, 0, NULL);
	addItem(help, AD_MENU_SITE, "menu.adminer_website");
	addItem(help, AD_MENU_REPO, "menu.github");
	addItem(help, AD_MENU_ISSUES, "menu.report_issue");
	AppendMenuW(help, MF_SEPARATOR, 0, NULL);
	addItem(help, AD_MENU_ABOUT, "menu.about");

	HMENU bar = CreateMenu();
	addSubmenu(bar, app, "Adminer Desktop");
	addSubmenu(bar, help, adTr("menu.help"));

	webviewProc = (WNDPROC)SetWindowLongPtrW((HWND)hwnd, GWLP_WNDPROC, (LONG_PTR)menuProc);
	return webviewProc && SetMenu((HWND)hwnd, bar);
}

const char *adMenuText(const char *id) { return adTr(id); }

void adMenuAbout(void *hwnd, const char *text) {
	wchar_t *wide = wideText(text);
	MessageBoxW((HWND)hwnd, wide, L"Adminer Desktop", MB_OK | MB_ICONINFORMATION);
	free(wide);
}

void adMenuClose(void *hwnd) { PostMessageW((HWND)hwnd, WM_CLOSE, 0, 0); }
