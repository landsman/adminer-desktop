package main

import (
	"os"
	"path/filepath"
	"runtime"
	"strings"
)

// dataDir holds everything that must outlive a run: the remembered app choice, and the
// key behind adminer's permanent login.
//
// os.UserConfigDir already knows the right place on each OS — Library/Application
// Support, ~/.config, %AppData% — so there is no per-platform code here. This replaced
// an NSUserDefaults implementation: once three platforms are in play, one portable file
// is less total code than one native store per OS.
func dataDir() (string, error) {
	base, err := os.UserConfigDir()
	if err != nil {
		return "", err
	}
	dir := filepath.Join(base, "Adminer Desktop")
	// 0700, because this directory holds the key that decrypts saved database passwords.
	return dir, os.MkdirAll(dir, 0o700)
}

// logDir is the one place worth a per-OS branch: on macOS ~/Library/Logs is where users
// and Console.app look, and putting the log anywhere else means nobody finds it.
func logDir() (string, error) {
	if runtime.GOOS == "darwin" {
		home, err := os.UserHomeDir()
		if err != nil {
			return "", err
		}
		dir := filepath.Join(home, "Library", "Logs", "Adminer Desktop")
		return dir, os.MkdirAll(dir, 0o755)
	}
	base, err := dataDir()
	if err != nil {
		return "", err
	}
	dir := filepath.Join(base, "logs")
	return dir, os.MkdirAll(dir, 0o755)
}

func lastAppFile() string {
	dir, err := dataDir()
	if err != nil {
		return ""
	}
	return filepath.Join(dir, "last-app")
}

// lastApp is the remembered choice, or "" the first time the app is ever run.
func lastApp() string {
	b, err := os.ReadFile(lastAppFile())
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(b))
}

func setLastApp(name string) {
	if path := lastAppFile(); path != "" {
		_ = os.WriteFile(path, []byte(name), 0o600)
	}
}
