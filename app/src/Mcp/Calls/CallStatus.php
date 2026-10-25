<?php
declare(strict_types=1);

namespace Desktop\Mcp\Calls;

/** What became of a call, as the bridge writes it (launcher/mcpbridge record.go). Unknown is a
* line from before the bridge wrote one: the tab-separated format said nothing about the outcome.
*/
enum CallStatus: string {
	case Ok = 'ok';
	case Error = 'error';
	case Refused = 'refused';
	case Timeout = 'timeout';
	case WindowClosed = 'window closed';
	case NotRunning = 'not running';
	case SessionExpired = 'session expired';
	case Cancelled = 'cancelled';
	case Unknown = 'unknown';

	function label(\AdminerDesktop $desktop): string {
		return match ($this) {
			self::Ok => $desktop->t('mcp.call_ok'),
			self::Error => $desktop->t('mcp.call_error'),
			self::Refused => $desktop->t('mcp.call_refused'),
			self::Timeout => $desktop->t('mcp.call_timeout'),
			self::WindowClosed => $desktop->t('mcp.call_window_closed'),
			self::NotRunning => $desktop->t('mcp.call_not_running'),
			self::SessionExpired => $desktop->t('mcp.call_session_expired'),
			self::Cancelled => $desktop->t('mcp.call_cancelled'),
			self::Unknown => $desktop->t('mcp.call_unknown'),
		};
	}

	/** Whether to draw it as something that went wrong. */
	function failed(): bool {
		return $this !== self::Ok && $this !== self::Unknown;
	}
}
