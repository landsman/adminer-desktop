<?php
declare(strict_types=1);

// Breaks upgrading.classes.Override on purpose: `make dresscode` fails unless it is reported, because
// a rule that needs types can go silent and still exit 0.

class OverrideMissingParent {
	function method(): void {
	}
}

class OverrideMissingChild extends OverrideMissingParent {
	function method(): void {
	}
}
