<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';

require 'index.php';
$CI =& get_instance();
$CI->db->query("UPDATE bisa_qr_config SET token = NULL, token_expires_at = NULL");
echo "Token cleared\n";
