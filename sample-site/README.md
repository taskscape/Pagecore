# Pagecore sample site

This folder is a working PHP site that demonstrates the CMS engine without
copying the reusable `cms/` directory.

Run it from the repository root:

```powershell
npm install
npm run sample:start
```

The launcher uses `C:\Tools\PHP\php.exe` by default. Set `PAGECORE_PHP_EXE`
when a different PHP executable is required.

Then open `http://127.0.0.1:8765/sample-site/`.

The bundled pages include `/sample-site/showcase/`, which demonstrates the
file-based content model and featured images stored as post front matter.

CMS login:

- Username: `admin`
- Password: `pagecore-demo`

These are public demo credentials, not a deployment default. The sample
configuration is `development_only` and cannot start in production mode or
serve non-loopback clients. Use the private production template under
`deployment/` with a newly generated password hash for a real site.

The sample uses `sample-site/config.php` via the `PAGECORE_CONFIG` environment
variable. `_bootstrap.php` honours that variable (and `$_SERVER`) before
falling back to the sibling `config.php`, so a `SetEnv` override cannot
disagree with a pinned constant. Mutable runtime files are copied from
`fixtures/` into ignored
folders:

- `working-content/`
- `working-uploads/`
- `search-index.json`
- `sitemap.xml`

`fixtures/test-site.json` is the committed browser-regression contract. It
lists the routes, content fragments, post visibility states, navigation, and
uploads the browser tests must preserve. Playwright copies the fixture into a
worker-specific temporary directory before every test, removes mutations after
every test, and deletes the worker directory at completion.

Reset them with:

```powershell
npm run sample:reset
```

`npm run test:site:reset` is an equivalent, explicitly test-oriented reset
command. Both commands verify that the resulting runtime files are exact copies
of the committed fixture. Validate the fixture itself with:

```powershell
npm run test:sample-test-site
```

Run the browser suite with:

```powershell
npm run test:e2e
```

The independent migration-output contract suite uses the same reproducible
fixtures but has its own Playwright configuration and command:

```powershell
npm run test:migration
```
