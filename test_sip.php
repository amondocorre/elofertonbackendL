<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';

require 'index.php';
$CI =& get_instance();
$CI->load->library('sip_service');
$res = $CI->sip_service->generateQr('test', 10, 'glosa');
print_r($res);
