<?php

/** Adapt Adminer's defaults to running as a desktop app
* @author adminer-desktop
* @license https://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
*/
class AdminerDesktop extends Adminer\Plugin {
	function loginFormField($name, $heading, $value) {
		// Adminer ships the Server field empty, which means "connect over a Unix socket".
		// That is right for a server deployment and wrong for a desktop one: here the
		// database is nearly always in Docker or remote, and Docker publishes TCP only —
		// it never creates a socket. The stock default fails with
		// `connection to server on socket "/tmp/.s.PGSQL.5432" failed` while the server is
		// answering perfectly well on 127.0.0.1.
		// Only fill in a blank field; never override a server the user has already chosen.
		if ($name == "server" && Adminer\SERVER == "") {
			// Rewrite the value in Adminer's own markup rather than restating it, so this
			// doesn't silently drift when the login form changes upstream.
			return str_replace('value=""', 'value="127.0.0.1"', $value);
		}
		return null; // let adminer handle every other field
	}

	protected $translations = array(
		'cs' => array('' => 'Přizpůsobí výchozí hodnoty pro desktopovou aplikaci'),
	);
}
