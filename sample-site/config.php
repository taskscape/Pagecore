<?php
$root = __DIR__;
$content = getenv('PAGECORE_SAMPLE_CONTENT') ?: $root . '/working-content';
$uploads = getenv('PAGECORE_SAMPLE_UPLOADS') ?: $root . '/working-uploads';
$siteUrl = getenv('PAGECORE_SITE_URL') ?: 'http://127.0.0.1:8765';

return array(
    'session_name' => 'PAGECORE_SAMPLE',
    'session_hours' => 8,
    'username' => 'admin',
    'password_hash' => '$2y$12$oWLexpCUtOum0KYLB0Ms/ukXgxPm0XepSJNAY8j.oZ8qldfdxpl9W',
    'content_dir' => $content,
    'backup_dir' => $content . '/.backups',
    'backup_keep' => 20,
    'site_root' => $root,
    'site_url' => $siteUrl,
    'uploads_dir' => $uploads,
    'uploads_url' => '/sample-site/working-uploads',
    'max_upload_mb' => 8,
    'allowed_ext' => array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf'),
    'allow_html' => true,
    'post_url' => '/sample-site/post/{slug}/',
    'categories' => array(
        'news' => array('News', '/sample-site/news/'),
        'events' => array('Events', '/sample-site/news/?category=events'),
        'docs' => array('Docs', '/sample-site/news/?category=docs'),
    ),
    'search_pages' => array(
        '/sample-site/' => array('Home', 'Page', 'home/hero'),
        '/sample-site/news/' => array('News', 'Listing', null),
        '/sample-site/contact.php' => array('Contact', 'Page', 'contact/body'),
    ),
);
