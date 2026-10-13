<?php
declare(strict_types=1);

namespace Desktop\Connections;

/** Which environment a saved connection points at. The value is what is stored and what the
* body class is built from (env-prod), so it stays lowercase and stable; the label is the
* shouted form the badge shows, the same in every language.
*/
enum Environment: string {
	case Local = 'local';
	case Dev = 'dev';
	case Stage = 'stage';
	case Prod = 'prod';

	function label(): string {
		return strtoupper($this->value);
	}

	/** The colour a connection takes when the user picked an environment but no colour of their
	* own — so marking a server PROD is already enough to make it red. */
	function color(): string {
		return match ($this) {
			self::Local => '#667085',
			self::Dev => '#12b76a',
			self::Stage => '#f79009',
			self::Prod => '#f04438',
		};
	}
}
