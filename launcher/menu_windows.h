// Shared between menu_windows.go and menu_windows.c: what a click on an item reports to Go.
enum {
	AD_MENU_ADMINER = 1,
	AD_MENU_EDITOR,
	AD_MENU_SETTINGS,
	AD_MENU_LOGS,
	AD_MENU_QUIT,
	AD_MENU_SHORTCUTS,
	AD_MENU_SITE,
	AD_MENU_REPO,
	AD_MENU_ISSUES,
	AD_MENU_ABOUT,
};

int adMenuInstall(void *hwnd); // 1 when the menu was attached
const char *adMenuText(const char *id);
void adMenuAbout(void *hwnd, const char *text);
void adMenuClose(void *hwnd);
