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
| A link followed | a connection's card; the sidebar's tables on a gallery design | `data-busy` on it or on an element around it |
| A POST form sent | logging in | `data-busy` on it or on an element around it |
| A script setting `location` | the sidebar's tables on our design (`table-nav.js`); the search | `window.desktopGo(href)` in its place |
| Adminer's `form.submit()` on `#dbs` | the database and schema menus | a change listener in busy.js |
| Adminer's `form.submit()` elsewhere | the language | nothing yet |
| Rows fetched in place | paging and sorting (`refresh.js`) | `window.desktopBusy()`, then `desktopBusy(false)` |
| Adminer's GET forms | the filter and search over a table's rows, Select | nothing yet: adminer sends them itself, before busy.js hears the submit |
| A download | an export saved to a file | never, see below |

## Only for what leaves the page

Nothing but the next page takes the indicator down, so mark only what navigates this one. A
download leaves the page where it is, and so do a link to another window and a click with a
modifier, which busy.js lets through.

For a spinner inside something else, a button say, `Icon::Spinner->html()` is the same icon,
turning, sized by the font.
