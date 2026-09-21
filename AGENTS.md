# Repository instructions

## Versioning

- Bump the version for every change, including maintenance and documentation changes; do not batch unrelated changes under one version.
- Pair every large or feature-level change with a minor version bump: `X.Y.Z` becomes `X.(Y+1).0`.
- Pair every smaller bug fix with a patch version bump: `X.Y.Z` becomes `X.Y.(Z+1)`.
- Keep the version synchronized in the same change. Update all of these:

  - `package.json` — the root `version` field
  - `package-lock.json` — the two root package entries only (`version` at the top of the file and `packages[""].version`). Do not change dependency versions.
  - `cms/engine.php` — `PAGECORE_VERSION`
  - `tests/sample-site.spec.js` — every assertion that embeds the current version (toolbar text, editor asset `?v=` query, version API, content page, and update page)

- Confirm the stamps match with `npm run quality:version` (`scripts/Test-VersionSync.ps1`).
- Do not rewrite `release/latest.json` during an ordinary bump; the release pipeline writes that file when a build is published. Leave historical version strings in update-test fixtures and example docs unchanged.

## Automated testing

- After every change, run the complete automated test set: `npm run quality`, `npm run test:php`, `npm run test:e2e`, and `npm run test:migration`.
- Report any test that cannot run or fails, including the precise environmental prerequisite or failure that remains.

## PHP runtime

- On Windows, the PHP engine is typically installed at `C:\php\php.exe` or `C:\Tools\PHP\php.exe`.
