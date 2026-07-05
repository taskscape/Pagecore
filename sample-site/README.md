# Pagecore sample site

This folder is a working PHP site that demonstrates the CMS engine without
copying the reusable `cms/` directory.

Run it from the repository root:

```powershell
npm install
npm run sample:start
```

Then open `http://127.0.0.1:8765/sample-site/`.

CMS login:

- Username: `admin`
- Password: `pagecore-demo`

The sample uses `sample-site/config.php` via the `PAGECORE_CONFIG` environment
variable. Mutable runtime files are copied from `fixtures/` into ignored
folders:

- `working-content/`
- `working-uploads/`
- `search-index.json`
- `sitemap.xml`

Reset them with:

```powershell
npm run sample:reset
```

Run the browser suite with:

```powershell
npm run test:e2e
```
