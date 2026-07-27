<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Instalar extends CI_Controller {
    public function index() {
        $this->load->database();
        $res = $this->db->where('nombre', 'Ventas por Mayor')->get('modulos');
        if ($res->num_rows() == 0) {
            $this->db->insert('modulos', [
                'nombre' => 'Ventas por Mayor',
                'descripcion' => 'Módulo de ventas al por mayor',
                'icono' => '🏭',
                'orden' => 3,
                'ruta' => '/ventas-mayorista',
                'estado' => 'Activo'
            ]);
            $modId = $this->db->insert_id();
            echo "Módulo creado. ID: $modId\n";
        } else {
            $modId = $res->row()->id;
            echo "Módulo ya existía. ID: $modId\n";
        }
        
        $resPerm = $this->db->where('rol_id', 1)->where('modulo_id', $modId)->get('permisos_rol');
        if ($resPerm->num_rows() == 0) {
            $this->db->insert('permisos_rol', [
                'rol_id' => 1,
                'modulo_id' => $modId,
                'ver' => 1,
                'crear' => 1,
                'editar' => 1,
                'eliminar' => 1,
                'exportar' => 1
            ]);
            echo "Permisos asignados.\n";
        } else {
            echo "Permisos ya existían.\n";
        }
    }
}
