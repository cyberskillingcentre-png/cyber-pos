<?php

require_once '../master_config.php';

function require_super_admin() {
    if (!isset($_SESSION['super_admin_id'])) {
        header('Location: login.php');
        exit();
    }
}

function get_super_admin_name() {
    return $_SESSION['super_admin_name'] ?? 'Admin';
}
?>