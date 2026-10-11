# Contributing

Thanks for wanting to help with Monocle. This page explains how to propose a change and what a pull request needs before it can be merged.

## Start with an issue

Every pull request needs an issue first, also small ones. Open an issue describing the bug or the change you have in mind and wait for a maintainer to agree on the approach. Pull requests without an agreed issue are closed.

Security issues are the exception: never open a public issue for them. See [SECURITY.md](SECURITY.md).

## Lenses for other packages

A lens for a widely used package can go into Monocle itself, as `src/Lenses/Packages/{Author}/{Package}Lens.php`, where `{Author}` is the root namespace of the package it records (`laravel/fortify` becomes `Laravel\FortifyLens`, `spatie/laravel-medialibrary` becomes `Spatie\MediaLibraryLens`). Every lens and response capture there implements `RequiresPackages`, naming the package it records first. A lens that adds to an existing section, rather than having its own, implements `ExtendsLens`. Say which package and what the lens records in the issue, and we decide together whether it belongs in core.

For other packages, publish the lens as your own package and register it with `Monocle::register()`. To have it listed in the index of third-party lenses in the docs, open an issue with the **Add a third-party lens** template. A lens is listed when it meets the requirements in the template, and removed only when it is reported as broken with the **Report a broken third-party lens** template. Listing is not an endorsement: the maintainers do not review or take responsibility for third-party lenses.

## Setting up

Everything runs in Docker, so you only need Docker and Node (see `.nvmrc`).

```bash
docker compose run --rm composer install
npm install
```

| Task | Command |
| --- | --- |
| Run the tests | `docker compose run --rm composer test` |
| Fix code style (PHP and Blade) | `docker compose run --rm composer lint` |
| Check code style without changing files | `docker compose run --rm composer lint:test` |
| Build the dashboard assets | `npm run build` |
| Run the demo app on `localhost:8000` | `docker compose up -d serve` |
| Preview the docs | `npm run docs:dev` |

## What a pull request needs

- **Tests** for all new behaviour, written with Pest. A test file mirrors the class it tests (`src/Lenses/CacheLens.php` → `tests/Feature/Lenses/CacheLensTest.php`), uses a matching namespace and declares what it covers with `covers()`. Coverage must stay at 90% or above. Lenses for other packages are tested against the real package, running its normal flow.
- **Docs** updated when behaviour or options change: the page in `docs/` and, for config options, the comment block in `config/monocle.php`.
- **Green lint.** Run `composer lint` before pushing; CI runs `composer lint:test` and the tests only start when it passes.
- **Dashboard changes** come with rebuilt assets: run `npm run build` and commit `resources/dist` in the same pull request. New UI also needs a page in the workbench demo app that shows it, so it can be tried with `docker compose up -d serve`.
- **No breaking changes** unless agreed in the issue. This covers config keys, the `Lens` contract and its optional interfaces, MCP tool arguments and the stored capture format. Additions are fine.

## Code style

Pint settles formatting. Beyond that, follow what the surrounding code does. The architecture tests in `tests/Arch` check the conventions that can be checked automatically, such as strict types, final classes and that every lens implements `Lens` and `Advises`.

## Commit messages

Commits follow [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`, with an optional scope such as `fix(mask):`. Describe what changes for the user in the subject, and why in the body when it is not obvious.

## AI-assisted contributions

You may use AI tools. You are responsible for every line you submit: you must understand it, have run it, and be able to explain it in review. Do not add AI attribution (co-author lines or similar) to commits or pull requests.

## Code of Conduct

Everyone taking part is expected to follow the [Code of Conduct](CODE_OF_CONDUCT.md).
