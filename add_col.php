<?php
$mysqli = new mysqli("localhost", "root", "", "importadorav1");
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}
$res = $mysqli->query("ALTER TABLE configapp ADD COLUMN dias_proforma INT DEFAULT 1");
if ($res) {
    echo "Column added successfully";
} else {
    echo "Error adding column: " . $mysqli->error;
}
$mysqli->close();
?>
