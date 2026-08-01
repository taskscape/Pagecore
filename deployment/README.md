# Production storage layout

Pagecore's production guard requires the configuration, content, backups, and
uploads to be outside the HTTP document root. A typical layout is:

```text
site/
├── public/                 # web server DOCUMENT_ROOT
│   ├── cms/
│   └── index.php
└── pagecore-private/
    ├── config.php          # copied from pagecore-config.php.example
    ├── content/
    ├── backups/
    └── uploads/
```

Set `PAGECORE_CONFIG` to the absolute private `config.php` path and
`PAGECORE_DOCUMENT_ROOT` to the absolute `public/` path in the PHP process
environment. Grant the PHP worker write access only to the three private data
directories and to the generated public `search-index.json` and `sitemap.xml`.

The browser never reads Markdown or upload files directly. `/cms/media-file.php`
validates the requested relative media path and supplies a fixed MIME policy;
PDFs are attachments and raster images may render inline. Do not set
`PAGECORE_DEVELOPMENT=1` in production. That opt-in exists only for the bundled
sample and private local migration fixture, whose routers enforce explicit
HTTP denials.
