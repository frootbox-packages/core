# frootbox/core

The core of [Frootbox](https://github.com/Frootbox/frootbox), a content
management system in PHP: pages from blocks, layouts and themes, field
types, drafts and versions, languages, navigations, media, users, roles
and rights, actions and queries with undo, the REST API, the page cache,
scheduling and the queue, settings, backups, consent, search and help.

Everything else, including the backend, comes as extensions that use the
same extension points as anyone's.

## Installation

Start a new website from the skeleton, which brings the core, the
backend, the base theme and the official modules:

```bash
composer create-project frootbox/skeleton my-website
cd my-website
php bin/frootbox install
```

See [Installation](https://github.com/Frootbox/frootbox/blob/main/documentation/getting-started/installation.md)
and the rest of the [documentation](https://github.com/Frootbox/frootbox/tree/main/documentation).

## Requirements

PHP 8.5 with `intl`, `pdo_sqlite` or `pdo_mysql`, and `gd` or `imagick`.
SQLite, MySQL 8 or MariaDB 10.11 or newer. It runs on ordinary web space
without Composer, build tools or processes of its own on the server.

## Source and issues

This package is developed in the [Frootbox monorepo](https://github.com/Frootbox/frootbox)
and mirrored here read-only. Questions, bugs and ideas go there, see the
[documentation](https://github.com/Frootbox/frootbox/tree/main/documentation).

## License

MIT, see [`LICENSE`](LICENSE).
