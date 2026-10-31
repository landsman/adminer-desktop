package mcpgate

import (
	"crypto/rand"
	"encoding/hex"
	"errors"
	"os"
	"path/filepath"
	"syscall"
	"unsafe"
)

var convertStringSD = syscall.NewLazyDLL("advapi32.dll").NewProc("ConvertStringSecurityDescriptorToSecurityDescriptorW")

// privateDir is a new directory only this account and SYSTEM can open, and what is created inside
// inherits that. Set at creation, so there is no moment with %TEMP%'s ACL, which a redirected TEMP
// may leave open to anyone. 0700 means nothing here: os.MkdirTemp would ignore it.
func privateDir() (string, error) {
	token, err := syscall.OpenCurrentProcessToken()
	if err != nil {
		return "", err
	}
	defer token.Close() //nolint:errcheck // read-only
	user, err := token.GetTokenUser()
	if err != nil {
		return "", err
	}
	sid, err := user.User.Sid.String()
	if err != nil {
		return "", err
	}
	sddl, err := syscall.UTF16PtrFromString("D:P(A;OICI;FA;;;" + sid + ")(A;OICI;FA;;;SY)")
	if err != nil {
		return "", err
	}
	var descriptor uintptr
	if ok, _, err := convertStringSD.Call(uintptr(unsafe.Pointer(sddl)), 1, uintptr(unsafe.Pointer(&descriptor)), 0); ok == 0 {
		return "", err
	}
	defer syscall.LocalFree(syscall.Handle(descriptor)) //nolint:errcheck // a few bytes
	attrs := syscall.SecurityAttributes{SecurityDescriptor: descriptor}
	attrs.Length = uint32(unsafe.Sizeof(attrs))
	for try := 0; ; try++ {
		suffix := make([]byte, 8)
		if _, err := rand.Read(suffix); err != nil {
			return "", err
		}
		dir := filepath.Join(os.TempDir(), "adminer-desktop-gate-"+hex.EncodeToString(suffix))
		path, err := syscall.UTF16PtrFromString(dir)
		if err != nil {
			return "", err
		}
		err = syscall.CreateDirectory(path, &attrs)
		if err == nil || !errors.Is(err, syscall.ERROR_ALREADY_EXISTS) || try == 9 {
			return dir, err
		}
	}
}
