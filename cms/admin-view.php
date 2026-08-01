<?php
require_once __DIR__ . '/modules/JsonPolicy.php';

function cms_admin_e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cms_admin_head_assets() {
    return '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
        . '<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&family=Inter:wght@400;500;600;700&family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20,400,1,0&display=swap" rel="stylesheet">' . "\n"
        . '<link rel="stylesheet" href="' . cms_admin_e(cms_asset_url('tokens.css')) . '">' . "\n"
        . '<link rel="stylesheet" href="' . cms_admin_e(cms_asset_url('admin.css')) . '">';
}

function cms_admin_sidebar($active, $picker = false) {
    $content = cms_admin_e(cms_admin_url('content.php'));
    $media = cms_admin_e(cms_admin_url('media.php'));
    $home = cms_admin_e(cms_site_url());
    $contentCurrent = $active === 'content' ? ' aria-current="page"' : '';
    $mediaCurrent = $active === 'media' ? ' aria-current="page"' : '';
    $html = '<aside class="pc-sidebar">'
        . '<a class="pc-brand" href="' . $home . '" aria-label="Pagecore site home">'
        . '<span class="pc-brand-mark"><span class="material-symbols-rounded" aria-hidden="true">check</span></span>'
        . '<span class="pc-brand-copy"><strong>Pagecore</strong><span>Content workspace</span></span></a>'
        . '<div class="pc-nav-group"><p class="pc-nav-label">Workspace</p><nav class="pc-nav" aria-label="CMS navigation">'
        . '<a href="' . $content . '"' . $contentCurrent . '><span class="material-symbols-rounded" aria-hidden="true">dashboard</span>Overview</a>'
        . '<a href="' . $content . '#posts-title"><span class="material-symbols-rounded" aria-hidden="true">edit_note</span>Posts</a>'
        . '<a href="' . $content . '#pages-title"><span class="material-symbols-rounded" aria-hidden="true">article</span>Pages</a>'
        . '<a href="' . $media . '"' . $mediaCurrent . '><span class="material-symbols-rounded" aria-hidden="true">photo_library</span>Media</a>'
        . '</nav></div>';
    if ($active === 'media') {
        $html .= '<div class="pc-nav-group"><p class="pc-nav-label">Library</p><nav class="pc-nav" aria-label="Media shortcuts">'
            . '<a href="' . $media . '"><span class="material-symbols-rounded" aria-hidden="true">grid_view</span>All files</a>'
            . (!$picker ? '<a href="' . $media . '?picker=1"><span class="material-symbols-rounded" aria-hidden="true">add_photo_alternate</span>Picker mode</a>' : '')
            . '</nav></div><div class="pc-sidebar-foot">Images and documents</div>';
    } else {
        $html .= '<div class="pc-nav-group"><p class="pc-nav-label">Structure</p><nav class="pc-nav" aria-label="Structure navigation">'
            . '<a href="#regions-title"><span class="material-symbols-rounded" aria-hidden="true">dashboard_customize</span>Regions</a>'
            . '<a href="#nav-title"><span class="material-symbols-rounded" aria-hidden="true">account_tree</span>Navigation</a>'
            . '</nav></div><div class="pc-sidebar-foot">Version ' . cms_admin_e(cms_version()) . '</div>';
    }
    return $html . '</aside>';
}

function cms_admin_client_assets($configName, $config) {
    try { $json = PagecoreJsonPolicy::encodeStrict($config); }
    catch (Throwable $error) { throw new RuntimeException('Could not encode admin client configuration.', 0, $error); }
    return '<script nonce="' . cms_admin_e(cms_csp_nonce()) . '">window.' . $configName . ' = ' . $json . ';</script>' . "\n"
        . '<script src="' . cms_admin_e(cms_asset_url('dialog.js')) . '"></script>' . "\n"
        . '<script src="' . cms_admin_e(cms_asset_url('admin-client.js')) . '"></script>';
}
