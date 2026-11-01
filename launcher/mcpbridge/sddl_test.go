package mcpbridge

import "testing"

func TestSDDLPrivate(t *testing.T) {
	const me = "S-1-5-21-1-2-3-1001"
	for sddl, want := range map[string]bool{
		"O:" + me + "D:P(A;OICI;FA;;;" + me + ")(A;OICI;FA;;;SY)":            true,  // what the gate makes
		"O:" + me + "D:AI(A;ID;FA;;;" + me + ")(A;ID;FA;;;SY)(A;ID;FA;;;BA)": true,  // a socket inheriting, %TEMP%'s usual
		"O:BAD:P(A;OICI;FA;;;" + me + ")":                                    true,  // an elevated app
		"O:" + me + "D:P(A;OICI;FA;;;" + me + ")(A;OICIIO;GA;;;CO)":          true,  // grants nothing here
		"O:" + me + "D:P(A;OICI;FA;;;" + me + ")(D;;FA;;;WD)":                true,  // a deny grants nothing
		"O:S-1-5-21-1-2-3-1002D:P(A;;FA;;;" + me + ")":                       false, // someone else's
		"O:" + me + "D:P(A;OICI;FA;;;" + me + ")(A;OICI;FR;;;WD)":            false, // Everyone
		"O:" + me + "D:P(A;CIOI;FR;;;BU)":                                    false, // "IO" only across a pair
		"O:" + me + "D:P(A;;FA;;;AU)":                                        false, // authenticated users
		"O:" + me + "D:NO_ACCESS_CONTROL":                                    false, // a null DACL opens it to all
		"O:" + me + "D:P(XA;;FA;;;" + me + ";(x))":                           false, // conditional
		"O:LAD:P(A;;FA;;;LA)":                                                false, // LA is not me
		"":                                                                   false,
	} {
		if got := sddlPrivate(sddl, me); got != want {
			t.Errorf("%q: got %v", sddl, got)
		}
	}
	if !sddlPrivate("O:LAD:P(A;;FA;;;LA)", "S-1-5-21-1-2-3-500") {
		t.Error("LA is the built-in Administrator, whose SID ends in -500")
	}
}
