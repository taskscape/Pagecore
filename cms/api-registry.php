<?php

/** Declarative API contract. Handler callables are attached by api.php. */
function pagecore_api_registry() {
    $read = array('method' => 'GET', 'authorization' => 'session', 'response' => 'json');
    $write = array('method' => 'POST', 'authorization' => 'session+csrf', 'response' => 'json');
    $registry = array();
    foreach (array('get', 'revisions', 'media-list', 'content-inventory', 'version') as $action) { $registry[$action] = $read; }
    $registry['preview-draft'] = array('method' => 'GET', 'authorization' => 'session', 'response' => 'html');
    foreach (array('preview', 'save', 'save-draft', 'publish', 'discard-draft', 'restore', 'save-post-meta', 'create-post', 'delete-post', 'save-nav', 'create-region', 'save-media-meta', 'delete-media', 'upload') as $action) { $registry[$action] = $write; }
    $registry['logout'] = array('method' => 'POST', 'authorization' => 'session+csrf', 'response' => 'redirect');
    return $registry;
}
