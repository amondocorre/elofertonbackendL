<?php
require 'index.php';
$CI =& get_instance();
$CI->load->database();
$query = $CI->db->query("SELECT * FROM bisa_qr_transacciones ORDER BY id DESC LIMIT 1");
$row = $query->row_array();
$res = "Alias: " . $row['alias'] . "\nQR_Length: " . strlen($row['qr_base64']) . "\nQR_Prefix: " . substr($row['qr_base64'], 0, 50) . "\nEstado: " . $row['estado'] . "\n";
file_put_contents('qr_debug.txt', $res);
echo "Done";
