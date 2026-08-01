<?php

final class PagecoreOperationResult {
    public $ok;
    public $value;
    public $error;
    public function __construct($ok, $value = null, $error = null) { $this->ok = (bool) $ok; $this->value = $value; $this->error = $error; }
}

final class PagecoreOperationalBoundary {
    private static function diagnostic($operation, $path, $message) {
        error_log(json_encode(array(
            'component' => 'pagecore.filesystem',
            'operation' => preg_replace('/[^a-z0-9._-]/i', '_', (string) $operation),
            'target' => basename((string) $path),
            'target_hash' => hash('sha256', (string) $path),
            'error' => substr((string) $message, 0, 240),
        ), JSON_UNESCAPED_SLASHES));
    }

    private static function capture(callable $callback) {
        $warning = null;
        set_error_handler(function ($severity, $message) use (&$warning) { $warning = $message; return true; });
        try { $value = $callback(); }
        catch (Throwable $error) { return new PagecoreOperationResult(false, null, $error->getMessage()); }
        finally { restore_error_handler(); }
        return $value === false ? new PagecoreOperationResult(false, null, $warning ?: 'operation returned false') : new PagecoreOperationResult(true, $value);
    }

    public static function delete($path, $operation, $missingOkay = true) {
        if (!file_exists($path) && !is_link($path)) { return new PagecoreOperationResult((bool) $missingOkay, null, $missingOkay ? null : 'target missing'); }
        $result = self::capture(function () use ($path) { return unlink($path); });
        if (!$result->ok) { self::diagnostic($operation, $path, $result->error); }
        return $result;
    }

    public static function reserve($path, $operation) {
        $result = self::capture(function () use ($path) { return fopen($path, 'x+b'); });
        if (!$result->ok) { self::diagnostic($operation, $path, $result->error); }
        return $result;
    }

    public static function directoryItems($path, $operation) {
        $result = self::capture(function () use ($path) { return scandir($path); });
        if (!$result->ok) { self::diagnostic($operation, $path, $result->error); }
        return $result;
    }

    public static function removeDirectory($path, $operation) {
        $result = self::capture(function () use ($path) { return rmdir($path); });
        if (!$result->ok) { self::diagnostic($operation, $path, $result->error); }
        return $result;
    }

    public static function modified($path, $operation) {
        $result = self::capture(function () use ($path) { return filemtime($path); });
        if (!$result->ok) { self::diagnostic($operation, $path, $result->error); }
        return $result;
    }
}

final class PagecoreApiBoundary {
    public static function encode($data, &$error = null) {
        try {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if (defined('JSON_THROW_ON_ERROR')) { $flags |= JSON_THROW_ON_ERROR; }
            $json = json_encode($data, $flags, 32);
            if ($json === false) { throw new RuntimeException(json_last_error_msg()); }
            return $json;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            return '{"ok":false,"error":"Response encoding failed."}';
        }
    }

    public static function logThrowable($action, Throwable $error) {
        error_log(json_encode(array(
            'component' => 'pagecore.api',
            'action' => preg_replace('/[^a-z0-9-]/i', '_', (string) $action),
            'exception' => get_class($error),
            'message' => substr($error->getMessage(), 0, 240),
            'correlation_id' => function_exists('cms_correlation_id') ? cms_correlation_id() : '',
        ), JSON_UNESCAPED_SLASHES));
    }
}
