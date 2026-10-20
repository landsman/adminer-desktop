<?php
declare(strict_types=1);

namespace Desktop\Assets;

/** The app's own icons: one case per file in icons/, so a typo is a type error and the whole set
* is this list.
*
* Each is a CSS mask (css/icons.css): a span the stylesheet paints in the text colour through the
* file's shape, so the theme colours every icon in one place and the browser caches each file
* once. A new case needs its .ad-icon-<value> rule there.
*/
enum Icon: string {
	case Close = "close";
	case Spinner = "spinner";
	case Edit = "edit";
	case Logout = "logout";
	case Forget = "forget";

	// The sidebar's actions (AdminerDesktop::menuActions()).
	case Sql = "sql";
	case Import = "import";
	case Export = "export";
	case CreateTable = "create-table";
	// Each table in the sidebar's list, and the path bar's last step (the theme's sidebar.css,
	// window.css).
	case Table = "table";

	// The saved connections' database logos, by adminer driver id (forDriver()).
	case Database = "database";
	case ClickHouse = "db-clickhouse";
	case Elasticsearch = "db-elastic";
	case MariaDb = "db-mariadb";
	case MongoDb = "db-mongo";
	case Oracle = "db-oracle";
	case PostgreSql = "db-pgsql";
	case MySql = "db-server";
	case Sqlite = "db-sqlite";

	/** The logo of an adminer driver, by its id (`server` is MySQL), or the plain database for a
	* driver without one. */
	static function forDriver(string $driver): self {
		return self::tryFrom("db-$driver") ?? self::Database;
	}

	/** The icon in the page. Hidden from screen readers: what holds it carries the name. */
	function html(): string {
		return "<span class='ad-icon ad-icon-$this->value' aria-hidden='true'></span>";
	}

	/** The file, as css/icons.css asks for it: the same URL, so a preload is the request it uses. */
	function url(): string {
		return "src/Assets/icons/$this->value.svg";
	}

	/** Print a <link rel=preload> for the icons needed at a moment nothing more can load. The
	* activity indicator's is shown as the page navigates away, when WKWebView fetches and draws
	* nothing more for it; a mask is fetched only once its element shows, so without this it came
	* too late. crossorigin, because a CSS mask is fetched in cors mode and a preload is used only
	* by a request made the same way. Called from the head() hook. */
	static function preload(): void {
		// The sidebar's too: a mask fetched only once its element is painted left the sidebar's icons
		// blank for a frame on every page, which going back and forth fast showed as blinking — the
		// server sends them with no Cache-Control, so WebKit fetches them again for each page.
		foreach ([self::Spinner, self::Sql, self::Import, self::Export, self::CreateTable, self::Table] as $icon) {
			echo "<link rel='preload' href='" . $icon->url() . "' as='image' crossorigin>\n";
		}
	}
}
