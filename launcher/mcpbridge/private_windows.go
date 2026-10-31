package mcpbridge

import (
	"os"
	"path/filepath"
	"syscall"
	"unsafe"
)

const (
	fileObject = 1 // SE_FILE_OBJECT
	ownerDACL  = 1 | 4
)

var (
	advapi32        = syscall.NewLazyDLL("advapi32.dll")
	getSecurityInfo = advapi32.NewProc("GetNamedSecurityInfoW")
	toSDDL          = advapi32.NewProc("ConvertSecurityDescriptorToStringSecurityDescriptorW")
)

// private is whether the socket and its directory admit this account and nobody else who could
// not already read everything. Both, because Windows lets any account traverse a directory it
// cannot list: the directory alone does not stop a connect to a path that is known, and mcp.json
// names it.
func private(socket string) bool {
	dir := filepath.Dir(socket)
	info, err := os.Lstat(dir)
	if err != nil || !info.IsDir() {
		return false
	}
	file, err := os.Lstat(socket)
	if err != nil || file.Mode()&os.ModeSocket == 0 {
		return false
	}
	user := currentSID()
	return user != "" && admitsOnly(dir, user) && admitsOnly(socket, user)
}

func admitsOnly(path, user string) bool {
	sddl, ok := securityOf(path)
	return ok && sddlPrivate(sddl, user)
}

func currentSID() string {
	token, err := syscall.OpenCurrentProcessToken()
	if err != nil {
		return ""
	}
	defer token.Close() //nolint:errcheck // read-only
	info, err := token.GetTokenUser()
	if err != nil {
		return ""
	}
	sid, err := info.User.Sid.String()
	if err != nil {
		return ""
	}
	return sid
}

// securityOf is a path's owner and DACL in SDDL: text, so they are read by a function every
// platform can test instead of by walking ACL structures in memory.
func securityOf(path string) (string, bool) {
	name, err := syscall.UTF16PtrFromString(path)
	if err != nil {
		return "", false
	}
	var owner, dacl, descriptor uintptr
	code, _, _ := getSecurityInfo.Call(uintptr(unsafe.Pointer(name)), fileObject, ownerDACL,
		uintptr(unsafe.Pointer(&owner)), 0, uintptr(unsafe.Pointer(&dacl)), 0, uintptr(unsafe.Pointer(&descriptor)))
	if code != 0 {
		return "", false
	}
	defer syscall.LocalFree(syscall.Handle(descriptor)) //nolint:errcheck // a few bytes
	var text *uint16
	ok, _, _ := toSDDL.Call(descriptor, 1, ownerDACL, uintptr(unsafe.Pointer(&text)), 0)
	if ok == 0 {
		return "", false
	}
	defer syscall.LocalFree(syscall.Handle(unsafe.Pointer(text))) //nolint:errcheck // a few bytes
	length := 0
	for *(*uint16)(unsafe.Add(unsafe.Pointer(text), length*2)) != 0 {
		length++
	}
	return syscall.UTF16ToString(unsafe.Slice(text, length)), true
}
