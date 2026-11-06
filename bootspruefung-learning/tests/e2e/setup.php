<?php

declare(strict_types=1);

// Bereitet eine Test-WordPress-Installation für die E2E-Tests vor:
//   WP_DIR=… BP_URL=http://127.0.0.1:8099 php tests/e2e/setup.php
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require rtrim((string) getenv('WP_DIR'), '/').'/wp-load.php';

$url = (string) (getenv('BP_URL') ?: 'http://127.0.0.1:8099');
update_option('siteurl', $url);
update_option('home', $url);
update_option('permalink_structure', '/%postname%/');
delete_option('bp_learning_rewrite_version');

$users = [
    ['lerner1', 'Test-Passwort-123', 'subscriber', 'Lea Lernt'],
    ['admin', 'Admin-Passwort-123', 'administrator', 'Admin'],
];
foreach ($users as [$login, $pass, $role, $name]) {
    $user = get_user_by('login', $login);
    $id = $user ? $user->ID : wp_insert_user(['user_login' => $login, 'user_email' => $login.'@example.invalid', 'user_pass' => $pass, 'display_name' => $name, 'role' => $role]);
    wp_set_password($pass, $id);
}
echo "E2E-Setup fertig.\n";
