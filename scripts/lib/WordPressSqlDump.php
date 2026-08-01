<?php

final class PagecoreWordPressSqlDump {
    /**
     * Canonical WordPress column order for every table the importer reads.
     *
     * mysqldump emits bare `INSERT INTO `t` VALUES (...)` tuples in schema
     * order, so the importer addresses fields positionally. phpMyAdmin — the
     * exporter most shared hosts offer — emits an explicit column list instead,
     * and may append site-specific columns (for example `blog_id` on Yoast's
     * primary-term table). Mapping the declared list onto this canonical order
     * keeps those positional readers correct for either exporter.
     */
    private static $schemas = array(
        'posts' => array('ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title',
            'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name',
            'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent',
            'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count'),
        'postmeta' => array('meta_id', 'post_id', 'meta_key', 'meta_value'),
        'terms' => array('term_id', 'name', 'slug', 'term_group'),
        'term_taxonomy' => array('term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent', 'count'),
        'term_relationships' => array('object_id', 'term_taxonomy_id', 'term_order'),
        'options' => array('option_id', 'option_name', 'option_value', 'autoload'),
        'yoast_primary_term' => array('id', 'post_id', 'term_id', 'taxonomy', 'created_at', 'updated_at'),
    );

    /** Map prefixed table names to their canonical column order. */
    public static function wordPressSchemas($prefix) {
        $map = array();
        foreach (self::$schemas as $name => $columns) { $map[$prefix . $name] = $columns; }
        return $map;
    }

    public static function rows($sql, $table, array $canonicalColumns = null) {
        $rows = array();
        $len = strlen($sql);
        $pattern = '~INSERT INTO\s+`' . preg_quote($table, '~') . '`\s*(?:\(([^()]*)\))?\s*VALUES\s*~';
        $from = 0;
        while ($from < $len && preg_match($pattern, $sql, $match, PREG_OFFSET_CAPTURE, $from)) {
            $declared = (isset($match[1]) && $match[1][1] !== -1) ? self::columnNames($match[1][0]) : null;
            $i = $match[0][1] + strlen($match[0][0]);
            $depth = 0; $cur = ''; $inq = false; $esc = false; $tuples = array();
            for (; $i < $len; $i++) {
                $ch = $sql[$i];
                if ($esc) { $cur .= $ch; $esc = false; continue; }
                if ($ch === '\\') { $cur .= $ch; $esc = true; continue; }
                if ($ch === "'") { $inq = !$inq; $cur .= $ch; continue; }
                if (!$inq) {
                    if ($ch === '(') { if ($depth === 0) { $cur = ''; } else { $cur .= $ch; } $depth++; continue; }
                    if ($ch === ')') { $depth--; if ($depth < 0) { throw new RuntimeException('malformed SQL tuple'); } if ($depth === 0) { $tuples[] = $cur; $cur = ''; } else { $cur .= $ch; } continue; }
                    if ($ch === ';' && $depth === 0) { break; }
                }
                $cur .= $ch;
            }
            if ($inq || $depth !== 0) { throw new RuntimeException('unterminated SQL tuple'); }
            foreach ($tuples as $tuple) {
                $rows[] = ($declared !== null && $canonicalColumns !== null)
                    ? self::remapTuple($tuple, $declared, $canonicalColumns, $table)
                    : $tuple;
            }
            $from = $i;
        }
        return $rows;
    }

    /** Split a declared `(`a`, `b`)` list into lower-cased column names. */
    private static function columnNames($raw) {
        $names = array();
        foreach (explode(',', $raw) as $name) { $names[] = strtolower(trim(trim($name), '`" ')); }
        return $names;
    }

    /**
     * Reorder one tuple's raw fields from the statement's declared columns into
     * canonical schema order. Columns the dump omits become empty strings so
     * positional readers keep receiving a full, well-typed tuple.
     */
    private static function remapTuple($tuple, array $declared, array $canonicalColumns, $table) {
        $fields = self::fields($tuple);
        if (count($fields) !== count($declared)) {
            throw new RuntimeException('column count does not match the declared list for ' . $table);
        }
        $byName = array();
        foreach ($declared as $index => $name) { $byName[$name] = $fields[$index]; }
        $ordered = array();
        foreach ($canonicalColumns as $name) {
            $name = strtolower($name);
            $ordered[] = array_key_exists($name, $byName) ? $byName[$name] : "''";
        }
        return implode(',', $ordered);
    }

    public static function fields($row) {
        $fields = array(); $cur = ''; $inq = false; $esc = false;
        $len = strlen($row);
        for ($i = 0; $i < $len; $i++) {
            $ch = $row[$i];
            if ($esc) { $cur .= $ch; $esc = false; continue; }
            if ($ch === '\\') { $cur .= $ch; $esc = true; continue; }
            if ($ch === "'") { $inq = !$inq; $cur .= $ch; continue; }
            if ($ch === ',' && !$inq) { $fields[] = $cur; $cur = ''; continue; }
            $cur .= $ch;
        }
        if ($inq || $esc) { throw new RuntimeException('unterminated SQL field'); }
        $fields[] = $cur;
        return $fields;
    }

    public static function value($raw) {
        $raw = trim($raw);
        if ($raw === 'NULL') { return null; }
        if (strlen($raw) >= 2 && $raw[0] === "'" && substr($raw, -1) === "'") { $raw = substr($raw, 1, -1); }
        return strtr($raw, array("\\'" => "'", '\\"' => '"', '\\n' => "\n", '\\r' => "\r",
            '\\t' => "\t", '\\0' => "\0", '\\Z' => "\x1a", '\\\\' => '\\'));
    }

    public static function rowsFromFile($path, array $tables, $maxStatementBytes, array $schemas = array()) {
        $result = array_fill_keys($tables, array());
        $handle = fopen($path, 'rb');
        if ($handle === false) { throw new RuntimeException('could not open SQL dump'); }
        $buffer = '';
        $table = null;
        try {
            while (($line = fgets($handle)) !== false) {
                $candidate = ltrim($line, "\xEF\xBB\xBF \t");
                if ($buffer === '' && preg_match('~^INSERT INTO\s+`([^`]+)`\s*(?:\([^()]*\))?\s*VALUES~', $candidate, $match)) {
                    $table = in_array($match[1], $tables, true) ? $match[1] : null;
                    if ($table !== null) { $line = $candidate; }
                }
                if ($table !== null) {
                    $buffer .= $line;
                    if (strlen($buffer) > $maxStatementBytes) { throw new RuntimeException('SQL INSERT exceeds configured statement bound for ' . $table); }
                }
                if (substr(rtrim($line), -1) === ';') {
                    if ($table !== null) {
                        $canonical = isset($schemas[$table]) ? $schemas[$table] : null;
                        $result[$table] = array_merge($result[$table], self::rows($buffer, $table, $canonical));
                    }
                    $buffer = '';
                    $table = null;
                }
            }
            if ($buffer !== '') { throw new RuntimeException('unterminated SQL INSERT statement'); }
            if (!feof($handle)) { throw new RuntimeException('failed while reading SQL dump'); }
        } finally { fclose($handle); }
        return $result;
    }
}
