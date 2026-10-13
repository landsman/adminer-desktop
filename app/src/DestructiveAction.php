<?php
declare(strict_types=1);

namespace Desktop;

/** Adminer's pages that change something — a table, a row, a routine, a user — or run what you
* type. The value is the query parameter adminer opens the page by (`?create=users`), so the
* page in view is the first case whose parameter is set (current()).
*
* What reads pages, the data list and the structure included, is not here: those are where you
* look, and these are where a slip against PROD costs something.
*/
enum DestructiveAction: string {
	case AlterTable = 'create';
	case EditRow = 'edit';
	case Indexes = 'indexes';
	case ForeignKey = 'foreign';
	case Check = 'check';
	case Trigger = 'trigger';
	case Procedure = 'procedure';
	case Function = 'function';
	case CallRoutine = 'call';
	case Event = 'event';
	case Sequence = 'sequence';
	case Type = 'type';
	case View = 'view';
	case User = 'user';
	case AlterDatabase = 'database';
	case AlterSchema = 'scheme';
	case SqlCommand = 'sql';
	case Import = 'import';

	/** The one this page is, if it is one of them. */
	static function current(): ?self {
		foreach (self::cases() as $action) {
			if (isset($_GET[$action->value])) {
				return $action;
			}
		}
		return null;
	}
}
