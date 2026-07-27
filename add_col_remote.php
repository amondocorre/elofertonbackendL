<?php
$mysqli = new mysqli('dokploy.mamier.cloud', 'root', 'Adminoferton123', 'jampasnando_ferreteria', 3307);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}
$res = $mysqli->query("ALTER TABLE configapp ADD COLUMN dias_proforma INT DEFAULT 1");
if ($res) {
    echo "Column added successfully\n";
} else {
    echo "Error adding column: " . $mysqli->error . "\n";
}
$mysqli->close();
?>
