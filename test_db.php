<?php
$mysqli = new mysqli("dokploy.mamier.cloud", "root", "Adminoferton123", "jampasnando_ferreteria", 3307);
if ($mysqli->connect_errno) {
    echo "Failed to connect: " . $mysqli->connect_error;
    exit();
}

$res = $mysqli->query("SELECT * FROM productos WHERE idprod='vc1600' OR descripcion LIKE '%vc1600%'");
while($row = $res->fetch_assoc()) {
    print_r($row);
    $res2 = $mysqli->query("SELECT * FROM inventarios WHERE idprod='" . $row['idprod'] . "'");
    echo "INVENTARIOS:\n";
    while($row2 = $res2->fetch_assoc()) {
        print_r($row2);
    }
}
$mysqli->close();
?>
