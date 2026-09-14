# Repository instructions

## Versioning

- Bump the version for every change, including maintenance and documentation changes; do not batch unrelated changes under one version.
- Pair every large or feature-level change with a minor version bump: `X.Y.Z` becomes `X.(Y+1).0`.
- Pair every smaller bug fix with a patch version bump: `X.Y.Z` becomes `X.Y.(Z+1)`.
- Keep the version synchronized across `package.json`, the root package entries in `package-lock.json`, `PAGECORE_VERSION` in `cms/engine.php`, and version assertions in tests.

## Automated testing

- After every change, run the complete automated test set: `npm run quality`, `npm run test:php`, `npm run test:e2e`, and `npm run test:migration`.
- Report any test that cannot run or fails, including the precise environmental prerequisite or failure that remains.

## PHP runtime

- On Windows, the PHP engine is typically installed at `C:\php\php.exe` or `C:\Tools\PHP\php.exe`.
