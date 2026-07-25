//go:build linux

package main

/*
#cgo pkg-config: gtk+-3.0 webkit2gtk-4.1
extern int installDownloads(void *window);
*/
import "C"

import (
	"log"
	"unsafe"
)

// installDownloads makes WebKitGTK save an attachment response (Adminer's Export > save)
// through a native Save dialog instead of rendering the file into the window. webview_go
// wires no download handling, so without this the SQL renders in the page or the download
// fails with nowhere to write. The C half lives in download_linux.c.
func installDownloads(window unsafe.Pointer) {
	if C.installDownloads(window) != 1 {
		log.Print("downloads: could not reach the webview; Export > save will show the file in the window")
	}
}
