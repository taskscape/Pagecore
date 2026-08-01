<?php
require_once __DIR__ . '/JsonPolicy.php';

final class PagecoreOperationResult {
    public $ok;
    public $value;
    public $error;
    public function __construct($ok, $value = null, $error = null) { $this->ok = (bool) $ok; $this->value = $value; $this->error = $error; }
}

final class PagecoreOperationalBoundary {
    private static function diagnostic($operation, $path, $message) {
        $record = array(
            'component' => 'pagecore.filesystem',
            'operation' => preg_replace('/[^a-z0-9._-]/i', '_', (string) $operation),
            'target' => basename((string) $path),
            'target_hash' => hash('sha256', (string) $path),
            'error' => substr((string) $message, 0, 240),
        );
        try { error_log(PagecoreJsonPolicy::encodeSubstituting($record)); }
        catch (Throwable $error) { error_log('{"component":"pagecore.filesystem","error":"diagnostic encoding failed"}'); }
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
            return PagecoreJsonPolicy::encodeStrict($data);
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            return '{"ok":false,"error":"Response encoding failed."}';
        }
    }

    public static function logThrowable($action, Throwable $error) {
        $record = array(
            'component' => 'pagecore.api',
            'action' => preg_replace('/[^a-z0-9-]/i', '_', (string) $action),
            'exception' => get_class($error),
            'message' => substr($error->getMessage(), 0, 240),
            'correlation_id' => function_exists('cms_correlation_id') ? cms_correlation_id() : '',
        );
        try { error_log(PagecoreJsonPolicy::encodeSubstituting($record)); }
        catch (Throwable $encodingError) { error_log('{"component":"pagecore.api","error":"diagnostic encoding failed"}'); }
    }
}
