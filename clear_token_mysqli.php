<?php
$mysqli = new mysqli("localhost", "root", "", "elofertondev");
if ($mysqli->connect_errno) {
    echo "Failed to connect to MySQL: " . $mysqli->connect_error;
} else {
    $mysqli->query("UPDATE bisa_qr_config SET token = NULL, token_expires_at = NULL");
    echo "Token cleared\n";
}
