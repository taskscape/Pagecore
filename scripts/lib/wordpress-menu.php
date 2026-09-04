<?php
namespace PagecoreWordPressMenu;

/** Replace same-site WordPress absolute links with root-relative Pagecore URLs. */
function menu_internal_url($url, array $siteUrls) {
    return \PagecoreWordPressImportPolicy::internalUrl($url, $siteUrls);
}

/** Published page/post records used to resolve WordPress menu object links. */
function menu_content_objects(array $postRows, $postUrl) {
    $objects = array();
    foreach ($postRows as $r) {
        $f = sql_fields($r);
        if (count($f) < 21) { continue; }
        $type = sql_val($f[20]);
        if ($type !== 'post' && $type !== 'page') { continue; }
        if (sql_val($f[7]) !== 'publish') { continue; }
        $id = (string) sql_val($f[0]);
        $slug = trim((string) sql_val($f[11]));
        if ($slug === '') { continue; }
        $objects[$id] = array(
            'title' => (string) sql_val($f[5]),
            'url' => $type === 'post' ? str_replace('{slug}', $slug, $postUrl) : '/' . $slug . '/',
        );
    }
    return $objects;
}

/** Find the nav-menu term assigned to the active theme's primary location. */
function primary_menu_term_id(array $options) {
    $stylesheet = isset($options['stylesheet']) ? trim((string) $options['stylesheet']) : '';
    $keys = array();
    if ($stylesheet !== '') { $keys[] = 'theme_mods_' . $stylesheet; }
    foreach ($options as $key => $ignore) {
        if (strpos($key, 'theme_mods_') === 0 && !in_array($key, $keys, true)) { $keys[] = $key; }
    }
    foreach ($keys as $key) {
        if (!isset($options[$key])) { continue; }
        $mods = wp_unserialize_option($options[$key]);
        if (!is_array($mods) || empty($mods['nav_menu_locations']) || !is_array($mods['nav_menu_locations'])) { continue; }
        $locations = $mods['nav_menu_locations'];
        if (!empty($locations['primary'])) { return (string) $locations['primary']; }
        foreach ($locations as $termId) { if ((int) $termId > 0) { return (string) $termId; } }
    }
    return '';
}

/** Convert the selected WordPress nav_menu into Pagecore's nested nav.json shape. */
function imported_nav_items(array $postRows, array $meta, array $rel, array $tt, array $terms,
                            array $options, $postUrl, &$menuLabel = '') {
    $menuPosts = array();
    foreach ($postRows as $r) {
        $f = sql_fields($r);
        if (count($f) < 21 || sql_val($f[20]) !== 'nav_menu_item' || sql_val($f[7]) !== 'publish') { continue; }
        $id = (string) sql_val($f[0]);
        $menuTermIds = array();
        foreach (isset($rel[$id]) ? $rel[$id] : array() as $ttid) {
            if (isset($tt[$ttid]) && $tt[$ttid][1] === 'nav_menu') { $menuTermIds[] = (string) $tt[$ttid][0]; }
        }
        if (!$menuTermIds) { continue; }
        $menuPosts[$id] = array(
            'id' => $id,
            'title' => (string) sql_val($f[5]),
            'order' => (int) sql_val($f[19]),
            'menu_terms' => $menuTermIds,
        );
    }
    if (!$menuPosts) { return array(); }

    $selected = primary_menu_term_id($options);
    if ($selected === '') {
        $counts = array();
        foreach ($menuPosts as $item) {
            foreach ($item['menu_terms'] as $termId) { $counts[$termId] = isset($counts[$termId]) ? $counts[$termId] + 1 : 1; }
        }
        arsort($counts);
        $selected = (string) key($counts);
    }
    $menuLabel = isset($terms[$selected]) ? $terms[$selected][0] : $selected;

    $objects = menu_content_objects($postRows, $postUrl);
    $siteUrls = array();
    foreach (array('home', 'siteurl') as $key) { if (!empty($options[$key])) { $siteUrls[] = $options[$key]; } }
    $items = array();
    foreach ($menuPosts as $id => $row) {
        if (!in_array($selected, $row['menu_terms'], true)) { continue; }
        $m = isset($meta[$id]) ? $meta[$id] : array();
        $parent = isset($m['_menu_item_menu_item_parent']) ? (string) $m['_menu_item_menu_item_parent'] : '0';
        $objectId = isset($m['_menu_item_object_id']) ? (string) $m['_menu_item_object_id'] : '';
        $object = isset($m['_menu_item_object']) ? (string) $m['_menu_item_object'] : '';
        $type = isset($m['_menu_item_type']) ? (string) $m['_menu_item_type'] : '';
        $label = trim($row['title']);
        $url = '';

        if ($type === 'custom') {
            $url = isset($m['_menu_item_url']) ? menu_internal_url($m['_menu_item_url'], $siteUrls) : '#';
        } elseif ($type === 'post_type') {
            // Never retain menu entries for draft/private content.
            if (!isset($objects[$objectId])) { continue; }
            $url = $objects[$objectId]['url'];
            if ($label === '') { $label = $objects[$objectId]['title']; }
        } elseif ($type === 'taxonomy' && isset($terms[$objectId])) {
            $term = $terms[$objectId];
            $url = $object === 'post_tag' ? '/tag/' . $term[1] . '/' : '/category/' . $term[1] . '/';
            if ($label === '') { $label = $term[0]; }
        }
        if ($url === '' && isset($m['_menu_item_url'])) { $url = menu_internal_url($m['_menu_item_url'], $siteUrls); }
        if ($label === '') { $label = $object !== '' ? $object : 'Menu'; }
        if ($url === '') { $url = '#'; }
        $items[$id] = array('id' => $id, 'parent' => $parent, 'order' => $row['order'],
            'label' => $label, 'url' => $url, 'children' => array());
    }
    if (!$items) { return array(); }

    uasort($items, function ($a, $b) {
        if ($a['order'] !== $b['order']) { return $a['order'] - $b['order']; }
        return (int) $a['id'] - (int) $b['id'];
    });
    $childrenByParent = array();
    foreach ($items as $id => $item) {
        $parent = $item['parent'];
        if ($parent === $id || !isset($items[$parent])) { $parent = '0'; }
        $childrenByParent[$parent][] = $id;
    }
    $build = function ($parent, array $trail = array()) use (&$build, &$items, &$childrenByParent) {
        $out = array();
        foreach (isset($childrenByParent[$parent]) ? $childrenByParent[$parent] : array() as $id) {
            if (isset($trail[$id])) { continue; }
            $nextTrail = $trail; $nextTrail[$id] = true;
            $item = $items[$id];
            $item['children'] = $build($id, $nextTrail);
            unset($item['id'], $item['parent'], $item['order']);
            $out[] = $item;
        }
        return $out;
    };
    return $build('0');
}
