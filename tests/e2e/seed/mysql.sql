-- Demo data for the MySQL suite (tests/e2e/behat.yml, `make e2e`).
--
-- The same tables as seed/pgsql.sql, in this driver's own types, because features/data/ runs
-- against both and a scenario cannot know which one it is on. What the two files do not share is
-- how they are built: no generate_series, no DO block, and JSON rather than jsonb.
--
-- Applied on every run — the drops below are what makes that idempotent — so editing this file
-- reaches the database without dropping the container first.

DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS big_child;
DROP TABLE IF EXISTS big_lookup;
DROP TABLE IF EXISTS documents;

CREATE TABLE users (
	id         int AUTO_INCREMENT PRIMARY KEY,
	name       text,
	email      text,
	created_at date,
	active     tinyint(1)
);

INSERT INTO users (name, email, created_at, active) VALUES
	('Anna Nováková',  'anna@example.com',  '2026-01-04', 1),
	('Bára Dvořáková', 'bara@example.com',  '2026-02-11', 1),
	('Cyril Kučera',   'cyril@example.com', '2026-03-19', 0),
	('Dana Marková',   'dana@example.com',  '2026-04-27', 1),
	('Emil Horák',     'emil@example.com',  '2026-05-30', 1),
	('Filip Beneš',    'filip@example.com', '2026-06-08', 0);

CREATE TABLE orders (
	id      int AUTO_INCREMENT PRIMARY KEY,
	user_id int,
	total   decimal(10,2),
	status  text,
	FOREIGN KEY (user_id) REFERENCES users(id)
);

INSERT INTO orders (user_id, total, status) VALUES
	(1, 1299.00, 'paid'),
	(1,   49.90, 'paid'),
	(2,  320.50, 'pending'),
	(3,   15.00, 'cancelled'),
	(4,  880.00, 'paid');

-- A foreign key pointing at more rows than AdminerEditForeign is allowed to put in a dropdown
-- (PluginList::ARGUMENTS caps it at 100), and orders.user_id is the other side of that.
CREATE TABLE big_lookup (
	id    int AUTO_INCREMENT PRIMARY KEY,
	label text
);
INSERT INTO big_lookup (label)
WITH RECURSIVE seq (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 150)
SELECT CONCAT('label ', n) FROM seq;

CREATE TABLE big_child (
	id        int AUTO_INCREMENT PRIMARY KEY,
	lookup_id int,
	FOREIGN KEY (lookup_id) REFERENCES big_lookup(id)
);
INSERT INTO big_child (lookup_id) VALUES (1);

-- Fifty documents, because the data-list features count on it: ten pages at five to a page, and a
-- payload long enough that the column arrives cut off, which is what makes widening it fetch more.
-- The titles are deliberately not in id order, so sorting by one visibly reorders the rows, and
-- they carry accents, which is what a broken encoding loses.
CREATE TABLE documents (
	id      int AUTO_INCREMENT PRIMARY KEY,
	title   text,
	payload json,
	notes   text
);

INSERT INTO documents (title, payload, notes) VALUES
	('Smlouva', '{"customer":{"name":"Anna Nováková","id":1},"items":[{"sku":"A-1","qty":2}],"paid":true}', '{"author":{"name":"Bára Dvořáková"},"revision":3}'),
	('Faktura', '{"total":1299,"currency":"CZK"}', 'not json at all');

INSERT INTO documents (title, payload, notes)
WITH RECURSIVE seq (n) AS (SELECT 3 UNION ALL SELECT n + 1 FROM seq WHERE n < 50)
SELECT
	CONCAT('Objednávka ', LPAD(n, 3, '0'), '/2026'),
	JSON_OBJECT(
		'order_no', CONCAT('OBJ-2026-', LPAD(n, 4, '0')),
		'paid', n % 3 = 0,
		'currency', ELT(1 + n % 3, 'CZK', 'EUR', 'USD'),
		'customer', JSON_OBJECT(
			'id', n,
			'name', ELT(1 + n % 5, 'Anna Nováková', 'Bára Dvořáková', 'Cyril Kučera', 'Dana Marková', 'Emil Horák'),
			'vat_id', CONCAT('CZ', 10000000 + n * 7919),
			'address', JSON_OBJECT(
				'street', CONCAT('Náměstí Míru ', n),
				'city', ELT(1 + n % 5, 'Praha', 'Brno', 'Ostrava', 'Plzeň', 'Olomouc'),
				'zip', CONCAT(100 + n, ' 00'),
				'country', 'CZ'
			),
			'contacts', JSON_ARRAY(
				JSON_OBJECT('kind', 'email', 'value', CONCAT('zakaznik', n, '@example.com')),
				JSON_OBJECT('kind', 'phone', 'value', CONCAT('+420 ', 600000000 + n * 131))
			)
		),
		-- The part that makes the value long, and that a pretty-printer has to indent several
		-- levels. Three items rather than pgsql's three-to-ten: MySQL has no jsonb_agg, and a
		-- correlated aggregate here would be a subquery per row for no assertion's benefit.
		'items', JSON_ARRAY(
			JSON_OBJECT(
				'sku', CONCAT('KBD-', LPAD(n * 10 + 1, 5, '0')),
				'name', 'Klávesnice mechanická',
				'qty', 1 + n % 6,
				'unit_price', ROUND((199 + (n * 37) % 24000) / 10, 2),
				'warehouse', JSON_OBJECT('code', CONCAT('W', 1 + n % 4), 'shelf', CONCAT(CHAR(65 + n % 6), '-', 10 + n))
			),
			JSON_OBJECT(
				'sku', CONCAT('MON-', LPAD(n * 10 + 2, 5, '0')),
				'name', 'Monitor 27" QHD',
				'qty', 1 + (n + 1) % 6,
				'unit_price', ROUND((199 + (n * 71) % 24000) / 10, 2),
				'warehouse', JSON_OBJECT('code', CONCAT('W', 1 + (n + 1) % 4), 'shelf', CONCAT(CHAR(65 + (n + 1) % 6), '-', 11 + n))
			),
			JSON_OBJECT(
				'sku', CONCAT('GPU-', LPAD(n * 10 + 3, 5, '0')),
				'name', 'Grafická karta',
				'qty', 1 + (n + 2) % 6,
				'unit_price', ROUND((199 + (n * 113) % 24000) / 10, 2),
				'warehouse', JSON_OBJECT('code', CONCAT('W', 1 + (n + 2) % 4), 'shelf', CONCAT(CHAR(65 + (n + 2) % 6), '-', 12 + n))
			)
		),
		'note', REPEAT(CONCAT('Poznámka k objednávce ', n, '. '), 1 + n % 4)
	),
	-- Half text holding JSON, half plain text, so both cases are in the table rather than only in
	-- the two rows written out above.
	IF(n % 2 = 0,
		-- CAST, not JSON_UNQUOTE: the column is text holding JSON, and what goes in it is the
		-- object's own serialisation rather than a string that once was one.
		CAST(JSON_OBJECT(
			'author', JSON_OBJECT('name', ELT(1 + n % 2, 'Bára Dvořáková', 'Emil Horák'), 'role', 'fakturace'),
			'revision', n % 7,
			'checks', JSON_ARRAY('vat', 'address', 'stock')
		) AS CHAR),
		CONCAT('Vyřízeno telefonicky, ', 3 + n % 8, '. položek')
	)
FROM seq;

-- Filler tables. Two tables fit in the sidebar without scrolling, which hides every problem that
-- only shows on a real database. Forty is enough to overflow the panel at any window size, and
-- they sort ahead of users and orders so those sit below the fold.
--
-- A procedure, because MySQL has no anonymous DO block: written, called and dropped again, so the
-- database is left holding only tables.
DROP PROCEDURE IF EXISTS make_filler;
DELIMITER //
CREATE PROCEDURE make_filler()
BEGIN
	DECLARE i INT DEFAULT 1;
	WHILE i <= 40 DO
		SET @filler = CONCAT('filler_', LPAD(i, 2, '0'));
		SET @sql = CONCAT('DROP TABLE IF EXISTS ', @filler);
		PREPARE run FROM @sql; EXECUTE run; DEALLOCATE PREPARE run;
		SET @sql = CONCAT('CREATE TABLE ', @filler, ' (id int AUTO_INCREMENT PRIMARY KEY, note text)');
		PREPARE run FROM @sql; EXECUTE run; DEALLOCATE PREPARE run;
		SET @sql = CONCAT('INSERT INTO ', @filler, " (note) VALUES ('first row'), ('second row')");
		PREPARE run FROM @sql; EXECUTE run; DEALLOCATE PREPARE run;
		SET i = i + 1;
	END WHILE;
END //
DELIMITER ;
CALL make_filler();
DROP PROCEDURE make_filler;
