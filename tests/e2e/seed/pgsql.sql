-- Demo data for the PostgreSQL suite (tests/e2e/behat.php, `make e2e`) and for `make demo`.
--
-- Applied on every run — the drops below are what makes that idempotent — so editing this file
-- reaches the database without dropping the container first. Add tables here as the app grows
-- more surfaces to test, keep seed/mysql.sql in step for anything features/data/ reads, and keep
-- the drops in dependency order (children first).

DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS big_child;
DROP TABLE IF EXISTS big_lookup;
DROP TABLE IF EXISTS documents;
DROP TABLE IF EXISTS long_values;
DROP TABLE IF EXISTS wide_rows;

CREATE TABLE users (
	id         serial PRIMARY KEY,
	name       text,
	email      text,
	created_at date,
	active     boolean
);

INSERT INTO users (name, email, created_at, active) VALUES
	('Anna Nováková',  'anna@example.com',  '2026-01-04', true),
	('Bára Dvořáková', 'bara@example.com',  '2026-02-11', true),
	('Cyril Kučera',   'cyril@example.com', '2026-03-19', false),
	('Dana Marková',   'dana@example.com',  '2026-04-27', true),
	('Emil Horák',     'emil@example.com',  '2026-05-30', true),
	('Filip Beneš',    'filip@example.com', '2026-06-08', false);

CREATE TABLE orders (
	id      serial PRIMARY KEY,
	user_id int REFERENCES users(id),
	total   numeric(10,2),
	status  text
);

INSERT INTO orders (user_id, total, status) VALUES
	(1, 1299.00, 'paid'),
	(1,   49.90, 'paid'),
	(2,  320.50, 'pending'),
	(3,   15.00, 'cancelled'),
	(4,  880.00, 'paid');

-- Wider than any window on one line, for features/shell/comments.feature: the structure page and
-- the list of tables have to wrap them rather than scroll sideways.
COMMENT ON TABLE orders IS 'Orders placed by users. A row is written when the basket is confirmed and is never deleted afterwards: a cancelled order keeps its row with the status set to cancelled, so the history of what a customer bought, and when, stays complete for invoicing.';
COMMENT ON COLUMN orders.status IS 'Where the order is: pending until the payment arrives, paid once it has, cancelled when the customer or an operator calls it off. Set by the payment webhook and by the back office, never by the storefront, which only reads it to show the customer how far their order got.';

-- A foreign key pointing at more rows than AdminerEditForeign is allowed to put in a dropdown
-- (PluginList::ARGUMENTS caps it at 100). orders.user_id is the other side of that test — six
-- users, comfortably under — and this is the side where the plugin has to give up and leave
-- Adminer's plain input alone instead of reading the whole table to fill a <select>.
CREATE TABLE big_lookup (
	id    serial PRIMARY KEY,
	label text
);
INSERT INTO big_lookup (label) SELECT 'label ' || g FROM generate_series(1, 150) g;

CREATE TABLE big_child (
	id        serial PRIMARY KEY,
	lookup_id int REFERENCES big_lookup(id)
);
INSERT INTO big_child (lookup_id) VALUES (1);

-- A jsonb column beside text ones, because the edit form gives JSON its own JUSH editor: it has
-- to pretty-print and sit on the form's width (features/pgsql/json-editor.feature), and
-- features/data/edit-form.feature resizes every field on it, that one included. `notes` is text
-- holding JSON, which Adminer leaves as stored, and `title` plain text. Both JSON values are nested and carry unicode. Fifty rows,
-- because a JSON editor is the one surface where a toy value hides everything worth seeing.
CREATE TABLE documents (
	id      serial PRIMARY KEY,
	title   text,
	payload jsonb,
	notes   text
);

-- The first two rows are written out because features/data/edit-form.feature opens id 1 by name —
-- generated ones would move under it the moment the generator changed.
INSERT INTO documents (title, payload, notes) VALUES
	('Smlouva', '{"customer":{"name":"Anna Nováková","id":1},"items":[{"sku":"A-1","qty":2}],"paid":true}', '{"author":{"name":"Bára Dvořáková"},"revision":3}'),
	('Faktura', '{"total":1299,"currency":"CZK"}', 'not json at all');

-- And 48 more, big enough to be worth looking at. A JSON editor is one of the places where the
-- only bug that matters is the one that needs a real document — a value that scrolls, that has
-- arrays of objects several levels down, that is long enough for the textarea to reach its
-- resize limit. Two toy rows never showed any of that. Every row is a different shape and a
-- different length, so clicking through them is not the same document forty-eight times.
INSERT INTO documents (title, payload, notes)
SELECT
	format('Objednávka %s/2026', lpad(g::text, 3, '0')),
	jsonb_build_object(
		'order_no', format('OBJ-2026-%s', lpad(g::text, 4, '0')),
		'paid', g % 3 = 0,
		'currency', (ARRAY['CZK', 'EUR', 'USD'])[1 + g % 3],
		'customer', jsonb_build_object(
			'id', g,
			'name', (ARRAY['Anna Nováková', 'Bára Dvořáková', 'Cyril Kučera', 'Dana Marková', 'Emil Horák'])[1 + g % 5],
			'vat_id', format('CZ%s', 10000000 + g * 7919),
			'address', jsonb_build_object(
				'street', format('Náměstí Míru %s', g),
				'city', (ARRAY['Praha', 'Brno', 'Ostrava', 'Plzeň', 'Olomouc'])[1 + g % 5],
				'zip', format('%s 00', 100 + g),
				'country', 'CZ'
			),
			'contacts', jsonb_build_array(
				jsonb_build_object('kind', 'email', 'value', format('zakaznik%s@example.com', g)),
				jsonb_build_object('kind', 'phone', 'value', format('+420 %s', 600000000 + g * 131))
			)
		),
		-- Three to ten items, each an object with a nested array of its own: this is the part
		-- that makes the value long, and the part a pretty-printer has to indent several levels.
		'items', (
			SELECT jsonb_agg(jsonb_build_object(
				'sku', format('%s-%s', (ARRAY['KBD', 'MON', 'DSK', 'CAB', 'GPU'])[1 + i % 5], lpad((g * 10 + i)::text, 5, '0')),
				'name', (ARRAY['Klávesnice mechanická', 'Monitor 27" QHD', 'Stůl výškově stavitelný', 'Kabel USB-C 2 m', 'Grafická karta'])[1 + i % 5],
				'qty', 1 + (g + i) % 6,
				'unit_price', round((199 + (g * i * 37) % 24000)::numeric / 10, 2),
				'vat_rate', (ARRAY[0.12, 0.21])[1 + i % 2],
				'tags', to_jsonb((ARRAY['skladem', 'akce', 'doprava-zdarma', 'poslední-kus'])[1 + i % 4 : 2 + i % 4]),
				'warehouse', jsonb_build_object('code', format('W%s', 1 + i % 4), 'shelf', format('%s-%s', chr(65 + i % 6), 10 + i))
			))
			FROM generate_series(1, 3 + g % 8) i
		),
		'history', (
			SELECT jsonb_agg(jsonb_build_object(
				'at', (date '2026-01-01' + (g * 3 + s))::text,
				'state', (ARRAY['new', 'confirmed', 'packed', 'shipped', 'delivered'])[1 + s],
				'by', format('operator%s', 1 + (g + s) % 4)
			))
			FROM generate_series(0, g % 5) s
		),
		'note', repeat(format('Poznámka k objednávce %s. ', g), 1 + g % 4)
	),
	-- Half of them text holding JSON, half plain text — both cases across the whole table, not
	-- only in the two rows written out above.
	CASE WHEN g % 2 = 0
		THEN jsonb_build_object(
			'author', jsonb_build_object('name', (ARRAY['Bára Dvořáková', 'Emil Horák'])[1 + g % 2], 'role', 'fakturace'),
			'revision', g % 7,
			'checks', jsonb_build_array('vat', 'address', 'stock')
		)::text
		ELSE format('Vyřízeno telefonicky, %s. položek', 3 + g % 8)
	END
FROM generate_series(3, 50) g;

-- Values longer than the MCP tools return by default (Tools::MAX_CELL, 2000 characters), for
-- features/shell/mcp.feature. `body` is exactly 5000 characters of Czech, so a cut counted in
-- bytes shows up as the wrong length; `payload` is a longer jsonb,
-- the type that stops parsing when cut, so whole_values is what makes it usable.
CREATE TABLE long_values (
	id      serial PRIMARY KEY,
	body    text,
	payload jsonb
);
INSERT INTO long_values (body, payload) VALUES (
	repeat('Příliš žluťoučký kůň úpěl ďábelské ódy. ', 125),
	(SELECT jsonb_build_object('lines', jsonb_agg(jsonb_build_object('n', g, 'text', format('Řádek %s: příliš žluťoučký kůň', g)))) FROM generate_series(1, 120) g)
);

-- Wider than the content panel, so the data list scrolls sideways: the pinned row-actions
-- column, the action bar under the table and the sticky breadcrumb all only show their
-- behaviour there. Many columns of very different widths — a flag, a two-digit number, a
-- sentence, a jsonb — since that is what column sizing and the scroll have to cope with. A uuid
-- first, like most real tables, and more than 50 rows so the pager is in the bar too. Every
-- value derives from the row number (the uuid through md5), so a reseed gives the same rows.
CREATE TABLE wide_rows (
	uuid           uuid PRIMARY KEY,
	active         boolean NOT NULL,
	code           text NOT NULL,
	grade          char(1) NOT NULL,
	name           text NOT NULL,
	category       text NOT NULL,
	description    text NOT NULL,
	quantity       integer NOT NULL,
	unit           text NOT NULL,
	unit_price     numeric(10, 2) NOT NULL,
	vat_rate       smallint NOT NULL,
	total_price    numeric(12, 2) NOT NULL,
	currency       char(3) NOT NULL,
	warehouse      text NOT NULL,
	shelf          text NOT NULL,
	supplier_email text NOT NULL,
	supplier_note  text,
	tags           text[] NOT NULL,
	attributes     jsonb NOT NULL,
	expires_on     date,
	created_at     timestamptz NOT NULL,
	updated_at     timestamptz NOT NULL
);
INSERT INTO wide_rows
SELECT
	md5(g::text)::uuid,
	g % 5 <> 0,
	format('WR-%s', lpad(g::text, 4, '0')),
	chr(65 + g % 4),
	format('Položka číslo %s s dlouhým názvem', g),
	(ARRAY['chemicals', 'packaging', 'tools', 'spare parts'])[1 + g % 4],
	format('Popis položky %s, dost dlouhý na to, aby sloupec něco vážil a bylo vidět, jak se chová dlouhý text v úzkém okně', g),
	g * 7 % 500,
	(ARRAY['ks', 'kg', 'l', 'bal.'])[1 + g % 4],
	round((g * 13.37 % 1000)::numeric, 2),
	(ARRAY[0, 12, 21])[1 + g % 3],
	round((g * 7 % 500) * (g * 13.37 % 1000)::numeric, 2),
	'CZK',
	(ARRAY['Praha', 'Brno', 'Ostrava'])[1 + g % 3],
	format('%s-%s', chr(65 + g % 6), g % 20),
	format('supplier%s@example.com', g % 9),
	CASE WHEN g % 3 = 0 THEN NULL ELSE format('Dodavatel %s dodává jen ve všední dny', g % 9) END,
	ARRAY[(ARRAY['fragile', 'hazmat', 'cold'])[1 + g % 3], 'stock'],
	jsonb_build_object('color', (ARRAY['red', 'green', 'blue'])[1 + g % 3], 'weight_g', g * 25, 'origin', 'CZ'),
	CASE WHEN g % 4 = 0 THEN NULL ELSE date '2027-01-01' + g END,
	timestamptz '2026-01-01 08:00+00' + g * interval '1 day',
	timestamptz '2026-06-01 08:00+00' + g * interval '3 hours'
FROM generate_series(1, 60) g;

-- Filler tables. Two tables fit in the sidebar without scrolling, which hides every problem
-- that only shows on a real database: the sidebar keeping its scroll position across a
-- navigation, the page transition, and how a long list reads. Forty is enough to overflow the
-- panel at any window size. They sort ahead of users and orders, so those sit below the fold
-- and are only reachable scrolled — which is exactly the case worth testing.
DO $$
DECLARE
	filler text;
BEGIN
	FOR i IN 1..40 LOOP
		filler := format('filler_%s', lpad(i::text, 2, '0'));
		EXECUTE format('DROP TABLE IF EXISTS %I', filler);
		EXECUTE format('CREATE TABLE %I (id serial PRIMARY KEY, note text)', filler);
		EXECUTE format('INSERT INTO %I (note) VALUES (''first row''), (''second row'')', filler);
	END LOOP;
END $$;
