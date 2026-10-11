<!-- Part of the Monocle documentation - see README.md -->

# Database

Part of [what gets recorded](../lenses.md).

![The Queries tab](../images/33-lens-database-light.webp#gh-light-mode-only)
![The Queries tab](../images/33-lens-database-dark.webp#gh-dark-mode-only)

Section `queries`: every query with its SQL, bindings, a ready-to-run version with the values filled in, connection, duration, type (select, insert, ...), tables and the transaction it ran in.

Transaction statements - `BEGIN TRANSACTION`, `COMMIT`, `ROLLBACK` and savepoints - are listed in between, in the order they ran. Repeated queries are recognised even when their values differ, so N+1 problems and duplicates show up as warnings with the line that caused them. A select that looks like an N+1 carries `n_plus_one`: how often its shape ran. The exact same query with the same values counts as a duplicate (`duplicate_of` points at the first one, which carries `repeated`: how many later queries repeated it), not as an N+1. Transactions appear in the timeline with their outcome (committed, rolled back, or left open when the capture ended first).

Totals: time spent, queries, and how many were selects, inserts, updates and deletes, transactions, slow queries and duplicates.

Warnings: slow queries, duplicate queries and possible N+1 queries.

Options:

- `slow_ms` (default 70) - a query taking at least this long is slow.
- `n_plus_one_threshold` (default 3) - how often a select's shape must run to count as a possible N+1.
- `bindings` (default true) - false leaves the bindings out of the rows.
- `ignore_tables` (default `telescope_*`, `pulse_*`) - queries that only touch these tables are left out.

![A query folded out](../images/15-query-details-light.webp#gh-light-mode-only)
![A query folded out](../images/15-query-details-dark.webp#gh-dark-mode-only)
