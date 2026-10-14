The app's own icons, one file each, listed by `Desktop\Assets\Icon` and drawn by `css/icons.css`
as a CSS mask over the text colour: only a file's shape counts, so each is drawn in
`currentColor` and the page tints it.

`db-*.svg` are the database logos for the saved-connection cards, one per adminer driver id
(`db-server` is MySQL, `db-mariadb` the same driver when the server reports MariaDB). From
[simple-icons](https://simpleicons.org) 13.21.0, CC0, with the `<title>` removed. `database.svg`
is ours: the fallback for a driver with no logo here (MS SQL — simple-icons dropped Microsoft's).

The rest are ours too: `edit`, `logout` and `forget` on a card, `close` in a modal's header,
`spinner` in the activity indicator.
