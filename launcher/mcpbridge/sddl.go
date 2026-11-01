package mcpbridge

import (
	"regexp"
	"strings"
)

var (
	sddlShape = regexp.MustCompile(`^O:(\S+?)D:[PAIR]*((?:\([^()]*\))*)$`)
	sddlACE   = regexp.MustCompile(`\(([^()]*)\)`)
)

// sddlPrivate is whether an "O:…D:…" security descriptor is owned and readable only by user,
// SYSTEM and the Administrators, who can read everything anyway. Whatever it does not recognise
// (an empty DACL's NO_ACCESS_CONTROL, a conditional entry) counts as open.
func sddlPrivate(sddl, user string) bool {
	parts := sddlShape.FindStringSubmatch(sddl)
	if parts == nil || !trustedSID(parts[1], user) {
		return false
	}
	for _, ace := range sddlACE.FindAllStringSubmatch(parts[2], -1) {
		fields := strings.Split(ace[1], ";")
		if len(fields) < 6 {
			return false
		}
		switch {
		case fields[0] == "D", inheritOnly(fields[1]):
			// A deny grants nothing, and an inherit-only entry grants nothing on this object:
			// what a child inherits from it is checked on the child.
		case fields[0] != "A" || !trustedSID(fields[5], user):
			return false
		}
	}
	return true
}

func trustedSID(sid, user string) bool {
	// SDDL prints the built-in Administrator account as LA, not as its SID.
	return sid == user || sid == "SY" || sid == "BA" || (sid == "LA" && strings.HasSuffix(user, "-500"))
}

// inheritOnly reads the flags in pairs: "CIOI" holds "IO" without having it.
func inheritOnly(flags string) bool {
	for i := 0; i+2 <= len(flags); i += 2 {
		if flags[i:i+2] == "IO" {
			return true
		}
	}
	return false
}
