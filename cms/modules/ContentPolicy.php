<?php

final class PagecoreContentPolicy {
    public static function isPublicStatus($status) {
        return strtolower(trim((string) $status)) === 'publish';
    }

    public static function page(array $items, $page, $perPage) {
        $total = count($items);
        $perPage = max(1, (int) $perPage);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, (int) $page));
        return array(
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages,
            'has_prev' => $page > 1, 'has_next' => $page < $pages,
        );
    }
}
