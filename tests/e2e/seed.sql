-- Demo data for the e2e run (tests/e2e/run.php, `mise run e2e`).
--
-- Idempotent: run.php applies it on every start, so editing this file and re-running the
-- e2e reseeds without recreating the container. Add tables here as the theme grows more
-- surfaces to test — keep the drops in dependency order (children first).

DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS users;

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
