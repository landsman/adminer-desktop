-- Demo data for the e2e run (tests/e2e/run.php, `mise run e2e`).
--
-- Applied once, when the container is created — fixture.php reuses one that is already
-- running and does not reseed it. So editing this file and re-running the e2e changes
-- nothing until `make destroy` drops the container. Idempotent anyway, for the reseed that
-- follows. Add tables here as the app grows more surfaces to test — keep the drops in
-- dependency order (children first).

DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS big_child;
DROP TABLE IF EXISTS big_lookup;
DROP TABLE IF EXISTS documents;

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

-- The JSON plugins (AdminerPrettyJsonColumn, AdminerJsonColumn) only do anything to a value that
-- starts with { or [ and parses, so all three columns are the check (json-column.test.php).
--
-- `notes` is text holding JSON on purpose, and it is the column that proves pretty-json-column:
-- the plugins sniff the value, not the column type, whereas Adminer already gives a real jsonb
-- column its own jush-js editor. So `payload` shows the plugins on the type they are named for,
-- `notes` is where only a plugin can be doing the work, and `title` is the plain text they have
-- to leave alone. Both JSON values are nested and carry unicode — pretty-printing is the whole
-- point, and the stored value is one line. Fifty rows, because a JSON editor is the one surface
-- where a toy value hides everything worth seeing.
CREATE TABLE documents (
	id      serial PRIMARY KEY,
	title   text,
	payload jsonb,
	notes   text
);

-- The first two rows are written out because json-column.test.php asserts on id 1 by name —
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
