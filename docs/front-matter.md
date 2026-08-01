# Post front matter

Post files begin with an exact `---` line, one `key: value` field per line, and a closing exact `---` line. Pagecore normalizes newlines, recognizes `title`, `date`, `category`, `status`, `excerpt`, `image`, and `tags`, and preserves syntactically valid unknown keys for forward compatibility. Duplicate or malformed keys produce diagnostics.

Dates use a real calendar date in `YYYY-MM-DD`, optionally followed by `HH:MM` or `HH:MM:SS`. Status is one of `publish`, `draft`, `private`, `pending`, `future`, or `trash`; only `publish` is anonymous. Category must exist in configuration. Malformed post metadata is excluded from public indexes/direct rendering and diagnosed in the server log.

The builder emits known keys in schema order, then unknown keys alphabetically. Values requiring quoting are escaped with double quotes, making parse/build round trips deterministic.
