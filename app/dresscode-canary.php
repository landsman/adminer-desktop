<?php
declare(strict_types=1);

// Breaks upgrading.classes.Override on purpose: `make dresscode` fails unless it is reported, because
// a rule that needs types can go silent and still exit 0. In app/ because that is what DressCode can
// take for foreign code. Excluded in dresscode.neon, and deleted from what ships.

class DresscodeCanaryParent {
	function method(): void {
	}
}

class DresscodeCanaryChild extends DresscodeCanaryParent {
	function method(): void {
	}
}
