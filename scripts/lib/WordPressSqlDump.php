<?php

final class PagecoreWordPressSqlDump {
    public static function rows($sql, $table) {
        $rows = array();
        $needle = "INSERT INTO `$table` VALUES ";
        $len = strlen($sql);
        $from = 0;
        while (($pos = strpos($sql, $needle, $from)) !== false) {
            $i = $pos + strlen($needle);
            $depth = 0; $cur = ''; $inq = false; $esc = false;
            for (; $i < $len; $i++) {
                $ch = $sql[$i];
                if ($esc) { $cur .= $ch; $esc = false; continue; }
                if ($ch === '\\') { $cur .= $ch; $esc = true; continue; }
                if ($ch === "'") { $inq = !$inq; $cur .= $ch; continue; }
                if (!$inq) {
                    if ($ch === '(') { if ($depth === 0) { $cur = ''; } else { $cur .= $ch; } $depth++; continue; }
                    if ($ch === ')') { $depth--; if ($depth < 0) { throw new RuntimeException('malformed SQL tuple'); } if ($depth === 0) { $rows[] = $cur; $cur = ''; } else { $cur .= $ch; } continue; }
                    if ($ch === ';' && $depth === 0) { break; }
                }
                $cur .= $ch;
            }
            if ($inq || $depth !== 0) { throw new RuntimeException('unterminated SQL tuple'); }
            $from = $i;
        }
        return $rows;
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

    public static function rowsFromFile($path, array $tables, $maxStatementBytes) {
        $result = array_fill_keys($tables, array());
        $handle = fopen($path, 'rb');
        if ($handle === false) { throw new RuntimeException('could not open SQL dump'); }
        $buffer = '';
        $table = null;
        try {
            while (($line = fgets($handle)) !== false) {
                $candidate = ltrim($line, "\xEF\xBB\xBF \t");
                if ($buffer === '' && preg_match('~^INSERT INTO `([^`]+)` VALUES ~', $candidate, $match)) {
                    $table = in_array($match[1], $tables, true) ? $match[1] : null;
                    if ($table !== null) { $line = $candidate; }
                }
                if ($table !== null) {
                    $buffer .= $line;
                    if (strlen($buffer) > $maxStatementBytes) { throw new RuntimeException('SQL INSERT exceeds configured statement bound for ' . $table); }
                }
                if (substr(rtrim($line), -1) === ';') {
                    if ($table !== null) { $result[$table] = array_merge($result[$table], self::rows($buffer, $table)); }
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
