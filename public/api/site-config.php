<?php

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

$_admin_root = dirname(__DIR__, 2) . '/admin';
require_once $_admin_root . '/config/config.php';
require_once INC_PATH . '/db.php';
require_once INC_PATH . '/helpers.php';

echo json_encode([
    'name'      => get_setting('company_name',    'Trash Panda Roll-Offs'),
    'phone'     => get_setting('company_phone',   ''),
    'email'     => get_setting('company_email',   ''),
    'address'   => get_setting('company_address', ''),
    'tagline'   => get_setting('company_tagline', ''),
    'website'   => get_setting('company_website', ''),
    'facebook'  => get_setting('company_facebook',  ''),
    'instagram' => get_setting('company_instagram', ''),
]);
