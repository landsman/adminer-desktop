# The activity indicator

The spinner over the page while it waits on the server, so a table or a schema that takes a
second to come does not look stuck. It is [busy.latte](busy.latte), printed on every page,
[css/busy.css](css/busy.css) and [javascript/busy.js](javascript/busy.js).

## Drawn before the page leaves

WKWebView draws nothing more of a page once it starts navigating away, so an indicator shown in
the same moment as the navigation never appears. busy.js therefore holds every navigation it
handles until the indicator has been drawn, and what moves while the page waits, the fade-in and
the turn, is a CSS animation of opacity and transform, which the compositor keeps running.

So whether a wait shows the indicator depends on whether busy.js gets in ahead of the
navigation. That is decided by how the navigation starts, not by which page it goes to, so the
waits are listed that way.

## How a wait starts

| How it starts | For example | What shows the indicator |
|---|---|---|
| A link in the content followed | a connection's card; the lists of databases and of tables; Select data | nothing: busy.js takes every one, but a download and an anchor on the same page |
| A link elsewhere followed | the sidebar's tables on a gallery design | `data-busy` on it or on an element around it |
| A form in the content sent, GET or POST | a SQL command; Select over a table's rows | nothing: busy.js takes every one ahead of adminer's own handler, but an export saved to a file |
| A form with `data-busy-here` sent | logging in | its own `ad-waiting` class instead: the login modal turns Login into a Cancel (`connections.js`) |
| A form elsewhere sent | none yet | `data-busy` on it or on an element around it |
| A script setting `location` | the sidebar's tables on our design (`table-nav.js`); the search | `window.desktopGo(href)` in its place |
| Adminer's `form.submit()` on `#dbs` | the database and schema menus | a change listener in busy.js |
| Adminer's `form.submit()` elsewhere | the language | nothing yet |
| Rows fetched in place | paging and sorting (`refresh.js`) | `window.desktopBusy()`, then `desktopBusy(false)` |
| A download | an export saved to a file | never, see below |

## A SQL command, while it runs

Adminer sends the page as it runs each query, the query first and its result once it is done. The
indicator from the page left behind turns until WebKit paints the new one, and it does that before
a long query ends, about 5 s in, with the sidebar, and so the indicator and busy.js, still to come.
[css/busy.css](css/busy.css) therefore marks the running query from the stylesheet: a spinner under
the query with nothing after it yet.

## Only for what leaves the page

Nothing but the next page takes the indicator down, so mark only what navigates this one. A
download leaves the page where it is, and so do a link to another window and a click with a
modifier, which busy.js lets through. An export is told from the form, not the answer: adminer
saves it to a file for any `output` but `text`, and that is the one form busy.js lets go.

For a spinner inside something else, a button say, `Icon::Spinner->html()` is the same icon,
turning, sized by the font.
