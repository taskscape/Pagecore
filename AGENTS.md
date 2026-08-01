# Repository instructions

## Versioning

- Pair every large or feature-level change with a minor version bump: `X.Y.Z` becomes `X.(Y+1).0`.
- Pair every smaller bug fix with a patch version bump: `X.Y.Z` becomes `X.Y.(Z+1)`.
- Keep the version synchronized across `package.json`, the root package entries in `package-lock.json`, `PAGECORE_VERSION` in `cms/engine.php`, and version assertions in tests.
