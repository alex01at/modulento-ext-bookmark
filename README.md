# Bookmark

An addon for [Modulento](https://github.com/alex01at/modulento) that lets a
logged-in account bookmark an offer or a provider and find them again under
"My bookmarks". As an addon, it can be active together with any other
addon and with the installation's one app.

- A star on an offer's and a provider's public page, for whoever is logged
  in - no permission of its own, every account only ever manages its own
  bookmarks.
- **`/account/bookmarks`**: the account's own bookmarked offers and
  providers. One that is since gone, or no longer public, simply does not
  show - the bookmark itself is never force-deleted by anything the core
  does to its target.

## Installing

Clone or symlink this repository into `extensions/bookmark` of a Modulento
installation, then enable it under Administration → Extensions.

## Running its tests

```
MODULENTO_CORE=/path/to/modulento php tests/run.php
```
