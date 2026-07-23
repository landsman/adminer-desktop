--
-- A pg_dump in miniature. Every row below is a shape that comes out of a real dump and
-- that adminer's parser reads as SQL: an apostrophe, a double quote, a dollar sign, the
-- two comment openers. The apostrophes alone are what breaks a 3 MB import.
--

SET standard_conforming_strings = on;

--
-- Name: shows; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.shows (
	id text NOT NULL,
	title text,
	note text
);

--
-- Data for Name: shows; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.shows (id, title, note) FROM stdin;
1	Equestria's divided	a "quoted" title
2	O'Dowd	costs $100 -- and this is not a comment /* nor is this
3	\N	a backslash \\ and a tab \t are escapes and stay
4	Kids' TV	the third apostrophe, so they no longer pair up and the block runs past its own end
\.

--
-- An apostrophe out here is a string, and has to be left as one.
--

SELECT 'outside the block';
