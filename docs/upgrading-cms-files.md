# Upgrading a site's starter files

A Vela site is the [`velabuild/cms`](https://github.com/VelaBuild/cms) starter — a stock
Laravel skeleton plus a handful of CMS files — with the CMS itself in
`vendor/velabuild/core`. `composer update velabuild/core` updates the CMS. It cannot update
the starter files, because those are the site's own code: its owner (or its AI) may have
changed any of them.

So core manages them: it ships the pristine starter files of each release and works out,
per file, whether the site can take the new version safely.

## What's managed

`skeleton/manifest.json` lists every managed file; `skeleton/files/` holds this release's
copy of each. Today: `public/index.php`, `public/.htaccess`, `public/install.php`, `artisan`,
`bootstrap/app.php`, `app/**` (kernels, providers, middleware, `User`), `config/*.php`,
`routes/*.php`, `resources/views/errors/*`, `welcome.blade.php`, the default seeder and
factory, `phpunit.xml`, `.env.example`, `.gitignore`, `.gitattributes`, `.editorconfig`.

Not managed (the site's or Composer's): `composer.json` / `composer.lock`, `README.md`,
`public/robots.txt`, `public/favicon.ico`, `public/images/*`, `public/flags/*`, themes,
`storage/`, `tests/`.

Manifest entry fields:

| Field | Meaning |
|---|---|
| `critical` | The site won't boot if this file is wrong (`public/index.php`, `bootstrap/app.php`, kernels, `artisan`) |
| `strategy` | `merge` (default) or `replace` (no three-way merge offered: `artisan`, `install.php`) |
| `known` | sha256 of the pristine file in earlier releases — lets a site with no baseline yet update safely |
| `source` | file name under `skeleton/files/` when it differs (`.gitignore` is stored as `gitignore.dist` so it doesn't act on core's own repo) |

`removed` lists files earlier starters shipped that are no longer needed, with the hashes of
their pristine copies.

## How a file is classified

The site keeps what core last gave it in `storage/app/vela-skeleton/`: `state.json`
(path → sha256) and `base/<path>` (a copy, for merging).

| Status | Meaning | Auto (`--auto`) | Offered |
|---|---|---|---|
| `current` | Site has this release's version | records the baseline | — |
| `update` | Site never changed it, release has a new one | **yes** | overwrite, keep, skip |
| `add` | New in this release | **yes** | add, skip |
| `review` | Changed by the site *and* by the release | no | overwrite, merge (if a base copy exists), keep, skip |
| `customised` | Changed by the site only | no | overwrite (reset to Vela's) |
| `deleted` | Site removed a file it was given | no | add (restore) |
| `remove` | Release dropped it, site never changed it | no | delete, keep, skip |
| `removed-customised` | Release dropped it, site changed it | no | delete, keep, skip |
| `kept` | Site chose to keep a dropped file | no | delete |

A site installed before baselines existed has no `state.json`. Then a file matching a
`known` hash counts as pristine (`update`), a file whose manifest entry has no `known`
hashes (unchanged since baselines began) and differs counts as `customised`, and anything
else is `review`. The first `--auto` run records baselines for every current file.

**Keep mine** moves the baseline to this release's version without touching the file, so it
reads as `customised` until the next release changes it again. **Merge** is a three-way merge
(`git merge-file`) of the site's file, the base copy and the new version; with conflicts it
writes nothing unless you explicitly ask for conflict markers.

## Using it

```bash
php artisan vela:upgrade-files              # report (default; --dry-run is the same)
php artisan vela:upgrade-files --auto       # apply update + add, record baselines
php artisan vela:upgrade-files -i           # walk the rest: diff / overwrite / merge / keep / skip
php artisan vela:upgrade-files --json       # for scripts (with --auto: what was done)
php artisan vela:upgrade-files --path=config/ --path=routes/web.php
php artisan vela:upgrade-files --auto --strict   # exit 2 if a critical file still needs review
```

In the admin: **Settings → CMS files** (`/admin/cms-files`) — the same list, a diff per
file, per-file buttons, and "Apply N safe updates". It requires the `config_edit` permission
**and** the Admin role. Every admin page shows a notice when files are pending (cached five
minutes), and `vela:update` prints the count.

## Safety

- Only paths from the manifest can be acted on; a request names a file, it can't choose one.
  Manifest paths are validated (relative, no `..`, no `.` segments).
- Writes are refused through a symlink or outside `base_path()`.
- Every overwrite, merge or delete backs the old file up to
  `storage/app/vela-skeleton/backups/<timestamp>/<path>`.
- Every action is logged (Laravel log channel, and `storage/app/vela-skeleton/actions.log`
  with who did it).

## For core maintainers

- Change a starter file in `skeleton/files/`, then add the **previous** file's sha256 to
  that entry's `known` list in `skeleton/manifest.json`. Without it, sites with no baseline
  will see the file as `review` instead of `update`.
- A file the starter no longer needs goes under `removed` with its last pristine hash.
- `tests/Feature/CmsFileUpgraderTest.php` checks that the manifest and `skeleton/files/` list
  the same files, and that a fresh copy of the skeleton reports entirely `current`.
- Keep `velabuild/cms` identical to `skeleton/files/` (the CMS repo is what `composer
  create-project` installs).
