<?php

final class PagecoreSessionContext {
    public static function start(array $config, array $transport) {
        if (session_status() !== PHP_SESSION_NONE || PHP_SAPI === 'cli') { return; }
        session_name($config['session_name']);
        session_set_cookie_params(array(
            'lifetime' => 0, 'path' => '/', 'secure' => $transport['cookie_secure'],
            'httponly' => true, 'samesite' => 'Lax',
        ));
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_start();
        if (!empty($_SESSION['cms_auth'])) {
            $maxAge = $config['session_hours'] * 3600;
            if (empty($_SESSION['cms_auth_at']) || time() - $_SESSION['cms_auth_at'] > $maxAge) {
                unset($_SESSION['cms_auth'], $_SESSION['cms_auth_at'], $_SESSION['cms_csrf']);
            }
        }
    }
}
