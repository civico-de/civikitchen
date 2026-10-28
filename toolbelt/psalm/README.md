# psalm taint setup

Psalm used ONLY as a taint engine (`cktaint`), never as a second phpstan:
does request input reach a query, shell command, file path or redirect
unescaped? Its config ships in `/opt/civikitchen/toolbelt/psalm` as
`psalm-taint.xml.dist` and the CiviCRM source/sink/escape stubs.

psalm itself is pinned in the repository root's `composer.json`;
`docs/building.md` explains why the lock resolves against PHP 8.1.31. Verify a
taint finding still reports on the lowest image PHP after a bump.
