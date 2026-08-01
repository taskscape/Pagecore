# PHP unit and security suite

Run `npm run test:php` from the repository root. The suite starts no web server and executes every contract in a separate PHP process, so globals and temporary fixtures cannot leak between tests.

The gate covers configuration and proxy/session policy; redirect, request-method, request-size, and path validation; traversal and private-storage boundaries; Parsedown safe mode; malformed front matter and scalar inputs; login throttling; atomic mutation rollback; route generation; admin escaping; content/template caches; media reference parsing; and WordPress import parsing/conversion policy.

When adding a pure PHP boundary, add one focused `tests/*.php` contract and list it in `scripts/Test-PhpSuite.ps1`. The contract must create data only under a uniquely named system-temporary directory, remove that exact directory in `finally`, write a concise failure to STDERR, and return a non-zero status.
