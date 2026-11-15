# Contributing

Thank you for helping. This is a small plugin with a narrow job: suggest
alt text, never write it without a person, and always be able to undo.
Changes that keep that promise are welcome.

## Setup

PHP 8.1 or later, Composer 2, and the `gd` extension for the image tests.

```sh
git clone https://github.com/IndianOpinion/alt-text-fill.git
cd alt-text-fill
composer install
```

No WordPress install is needed: the tests run against a stub layer in
`tests/stubs/`.

## Tests

```sh
composer test        # unit suite, plus integration when a database is set
composer stan        # PHPStan level 6
composer cs          # PHPCS (PSR-12); composer cbf fixes what it can
```

The integration suite (`tests/Integration/`) runs the wpdb storage against
a real MySQL or MariaDB server. Point it at a throwaway database:

```sh
ALT_TEXT_FILL_TEST_DB_HOST=127.0.0.1 ALT_TEXT_FILL_TEST_DB_USER=root \
ALT_TEXT_FILL_TEST_DB_PASSWORD=secret ALT_TEXT_FILL_TEST_DB_NAME=atf_test \
composer test
```

It drops and creates its own tables with the `wptest_` prefix. Never point
it at a database you care about.

Each acceptance test in `docs/PRD.md` section 12 has a test method named
after it (`testT42...`). A change in behaviour needs a test that fails
before it and passes after.

## Style

- PSR-12, `declare(strict_types=1);` in every PHP file.
- PHP 8.1 syntax only: no readonly classes, no DNF types, no `true` type,
  no constants in traits.
- Escape every output on admin screens; check a capability and a nonce on
  every admin action.
- No `eval`, no shell, no `@` error suppression. Typed exceptions, never a
  silent failure.
- Prose in docs and messages: short sentences, plain words, British
  spelling. No em dashes.
- No vendor plugin names anywhere, and no code or text taken from any
  third-party plugin.

## Pull requests

- One change per pull request, with a clear title and a description of
  what changes for a site owner.
- Update `CHANGELOG.md` under "Unreleased".
- Update `docs/ARCHITECTURE.md` when a decision changes.
- CI must be green: lint, PHPCS, PHPStan and PHPUnit on PHP 8.1, 8.2 and 8.3.
- A DCO-style sign-off is not required.
