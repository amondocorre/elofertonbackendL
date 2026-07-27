<?php
$m = new mysqli('dokploy.mamier.cloud', 'root', 'Adminoferton123', 'jampasnando_ferreteria', 3307);
$m->query("UPDATE modulos_menu SET id_sistema = 2 WHERE id = 24");
echo "Updated module 24 to id_sistema=2\n";
