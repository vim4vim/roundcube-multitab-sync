# Multi-Tab Sync for Roundcube

Open the same mailbox in two Roundcube tabs and only one of them keeps up. New
mail lands in whichever tab happened to poll first; the other keeps showing a
stale list, even though its unread counter and folder tree update correctly.
Which tab wins is a race, so it looks arbitrary.

This plugin makes every tab behave as if it were the only one open.

## How it works

Roundcube does not ask "what is in this folder" on every poll — it asks "has
anything changed since last time". The reference value for *last time* lives in
the PHP session:

```php
// rcube_imap::folder_status()
$old = $this->get_folder_stats($folder);                 // reads  $_SESSION['folders'][$folder]
$this->countmessages($folder, 'ALL', true, true, true);  // WRITES cnt + maxuid there
$new = $this->get_folder_stats($folder);                 // reads what was just written

if ($new['maxuid'] > $old['maxuid']) { $result++; }      // only then are rows sent
```

All tabs of one browser share one session, so the first tab to poll consumes
the difference *and* advances the reference. Every other tab then compares
against the already-advanced value, is told nothing changed, and its list stops
moving. The unread counter still updates everywhere because it is sent outside
that check, which is why the symptom looks like a display bug rather than a
state bug.

The plugin keeps those reference values **per tab** instead of per session. Each
tab generates an id in `sessionStorage` and sends it with every poll; the plugin
swaps that tab's own values in before Roundcube's folder loop (`check_recent`
hook) and stores them again afterwards (`refresh` hook). Nothing in the core
changes — Roundcube just sees each tab as its own client.

Two smaller pieces cut the delay rather than fix correctness:

- Tabs announce their own deletes, moves and flag changes over a
  `BroadcastChannel`, so the other tabs pick them up in about a second instead
  of waiting for their next poll.
- A tab polls once when it becomes visible again. Browsers freeze background
  tabs, and a frozen tab does not poll at all.

## Requirements

- Roundcube 1.6+

## Installation

The plugin directory **must** be named `multitab_sync` — Roundcube loads
`plugins/<name>/<name>.php`, so a directory named after this repository fails
with "Failed to load plugin file". Clone it with an explicit target:

```sh
git clone https://github.com/vim4vim/roundcube-multitab-sync.git plugins/multitab_sync
```

Then add it to your `config/config.inc.php`:

```php
$config['plugins'] = [
    // ...
    'multitab_sync',
];
```

## Configuration

Optional. Copy `config.inc.php.dist` to `config.inc.php` to change anything:

```php
$config['multitab_sync_enabled']  = true;  // false disables the plugin
$config['multitab_sync_ttl']      = 3600;  // seconds before a tab's state is dropped
$config['multitab_sync_max_tabs'] = 20;    // tabs tracked per session
```

State is kept in one top-level session variable per tab. That is deliberate:
`rcube_session::fixvars()` merges stored and new session data per top-level
variable, so two tabs polling at the same moment both survive, where a single
shared array would lose one of the two writes.

## Tests

`tests/multitab_sync_test.php` runs standalone — no Roundcube, no PHPUnit:

```sh
php tests/multitab_sync_test.php
```

It stubs the plugin API, copies `rcube_imap::folder_status()` verbatim, and then
reproduces the bug with the plugin off before checking that two tabs both see a
new message, a deletion reaches both, a late-joining tab seeds quietly, buckets
are garbage-collected, and a malformed tab id falls back to stock behaviour.

## Limits

- A tab that has just opened has no reference values yet, so its first poll only
  seeds them. Mail arriving between the page rendering and that first poll
  (about a second) is not pushed into this tab and shows up on a later change or
  a manual refresh.
- Switching folders or paging updates Roundcube's session-wide folder stats but not
  this tab's own copy. Returning to a folder that received mail meanwhile can
  therefore cost one redundant list refresh on the next poll. It corrects itself,
  and keeping the copies in step through every listing action would add more
  moving parts than that one refresh is worth.
- Only Roundcube's own mutating actions are announced between tabs (`move`,
  `delete`, `copy`, `mark`, `expunge`, `purge`). Actions added by other plugins
  are not — those tabs catch up on their next poll instead.
- Roundcube keeps other view state session-wide as well: current page, sort
  order, and the active search. Searching or re-sorting in one tab can still
  affect another. That is a separate issue and out of scope here.
- Without `BroadcastChannel` the cross-tab notifications are skipped. The main
  fix does not depend on them.

## See also

Undo for deleted mail in [undo-delete](https://github.com/vim4vim/roundcube-undo-delete),
keyboard shortcuts in [mail-shortcuts](https://github.com/vim4vim/roundcube-mail-shortcuts),
and `A` to archive in [archive-hover](https://github.com/vim4vim/roundcube-archive-hover).

## License

GPL-3.0-or-later
