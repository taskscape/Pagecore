# Internal modules

`engine.php` remains Pagecore's compatibility facade: templates and integrations continue to call the documented `cms_*` functions. Cohesive, dependency-light internals live here and never load the web bootstrap themselves.

- `PathPolicy.php` owns platform normalization and containment.
- `SessionContext.php` owns secure session startup and expiry and receives validated configuration and transport explicitly.
- `ContentPolicy.php` owns pure visibility and collection rules.

Modules may depend on PHP and explicit arguments only. The facade may depend on modules; modules must not require `engine.php` or one another, preventing circular bootstrap loading.
