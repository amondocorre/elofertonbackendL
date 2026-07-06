<?php
define('BASEPATH', __DIR__ . '/system/');
define('APPPATH', __DIR__ . '/application/');
define('ENVIRONMENT', 'development');

// Simulate CI config loading
$active_group = 'default';
$query_builder = TRUE;
require_once APPPATH . 'config/database.php';

$mysqli = new mysqli(
    $db['default']['hostname'],
    $db['default']['username'],
    $db['default']['password'],
    $db['default']['database'],
    $db['default']['port'] ?? 3306
);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}
echo "Connected successfully to DB.\n";

// 1. Crear tabla unidad_medida
$sql = "CREATE TABLE IF NOT EXISTS unidad_medida (
    idunidad INT AUTO_INCREMENT PRIMARY KEY,
    descripcion VARCHAR(50) NOT NULL UNIQUE,
    estado ENUM('Activo', 'Inactivo') DEFAULT 'Activo'
)";
if(!$mysqli->query($sql)) echo "Error creating table: " . $mysqli->error . "\n";
else echo "Table unidad_medida ensured.\n";

// 2. Insertar unidades existentes de la tabla productos
$sql = "INSERT IGNORE INTO unidad_medida (descripcion) SELECT DISTINCT UPPER(TRIM(unidad)) FROM productos WHERE unidad IS NOT NULL AND TRIM(unidad) != ''";
if(!$mysqli->query($sql)) echo "Error inserting units: " . $mysqli->error . "\n";
else echo "Inserted " . $mysqli->affected_rows . " distinct units.\n";

// Ensure basic units exist
$basic = ['UNID', 'PZA', 'CAJA'];
foreach ($basic as $b) {
    $mysqli->query("INSERT IGNORE INTO unidad_medida (descripcion) VALUES ('$b')");
}
echo "Ensured basic units exist.\n";

// 3. Añadir idunidad a productos si no existe
$sql = "SHOW COLUMNS FROM productos LIKE 'idunidad'";
$res = $mysqli->query($sql);
if ($res->num_rows == 0) {
    $sql = "ALTER TABLE productos ADD COLUMN idunidad INT NULL AFTER unidad";
    if(!$mysqli->query($sql)) echo "Error adding column idunidad: " . $mysqli->error . "\n";
    else echo "Added column idunidad to productos.\n";
} else {
    echo "Column idunidad already exists in productos.\n";
}

// 4. Actualizar idunidad en productos
$sql = "UPDATE productos p JOIN unidad_medida u ON UPPER(TRIM(p.unidad)) = u.descripcion SET p.idunidad = u.idunidad WHERE p.idunidad IS NULL";
if(!$mysqli->query($sql)) echo "Error updating idunidad: " . $mysqli->error . "\n";
else echo "Updated " . $mysqli->affected_rows . " products with idunidad.\n";

$mysqli->close();
echo "Migration completed.\n";
?>
