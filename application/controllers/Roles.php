<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Roles extends MY_Controller {

    public function __construct() {
        parent::__construct();
        // Habilitar CORS
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-User-Id, X-Rol-Id, X-Active-Branch');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit();
        }
        
        $this->load->database();
    }

    public function index() {
        $this->check_permission('Usuarios', 'ver');
        $this->db->order_by('nombre_rol', 'ASC');
        $roles = $this->db->get('roles')->result();
        
        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($roles));
    }

    public function guardar() {
        $this->check_permission('Usuarios', 'editar');
        $data = json_decode(file_get_contents('php://input'), true);

        $id = isset($data['id']) ? intval($data['id']) : null;
        $nombre_rol = $data['nombre_rol'] ?? '';
        $descripcion = $data['descripcion'] ?? '';
        $module_permissions = $data['module_permissions'] ?? [];
        $branches_allowed = $data['branches_allowed'] ?? [];

        if (empty($nombre_rol)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El nombre del rol es obligatorio']));
        }

        $this->db->trans_start();

        if ($id) {
            $this->db->where('id', $id);
            $this->db->update('roles', [
                'nombre_rol' => $nombre_rol,
                'descripcion' => $descripcion
            ]);
            $rol_id = $id;
            $message = 'Rol actualizado exitosamente';
        } else {
            $this->db->insert('roles', [
                'nombre_rol' => $nombre_rol,
                'descripcion' => $descripcion,
                'fecha_registro' => date('Y-m-d H:i:s')
            ]);
            $rol_id = $this->db->insert_id();
            $message = 'Rol creado exitosamente';
        }

        // Permisos de modulos
        $this->db->where('id_rol', $rol_id)->delete('permisos_roles');
        if (!empty($module_permissions)) {
            $batch_perms = [];
            foreach ($module_permissions as $modId => $actions) {
                $batch_perms[] = [
                    'id_rol' => $rol_id,
                    'id_modulo' => intval($modId),
                    'ver' => intval($actions['ver'] ?? 0),
                    'crear' => intval($actions['crear'] ?? 0),
                    'editar' => intval($actions['editar'] ?? 0),
                    'eliminar' => intval($actions['eliminar'] ?? 0)
                ];
            }
            if (!empty($batch_perms)) {
                $this->db->insert_batch('permisos_roles', $batch_perms);
            }
        }

        // Permisos sucursales
        $this->db->where('id_rol', $rol_id)->delete('permisos_sucursales_roles');
        if (!empty($branches_allowed)) {
            $batch_branches = [];
            foreach ($branches_allowed as $bId) {
                $batch_branches[] = [
                    'id_rol' => $rol_id,
                    'id_sucursal' => intval($bId)
                ];
            }
            if (!empty($batch_branches)) {
                $this->db->insert_batch('permisos_sucursales_roles', $batch_branches);
            }
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al guardar el rol']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['message' => $message, 'id' => $rol_id]));
    }

    public function eliminar($id = null) {
        $this->check_permission('Usuarios', 'eliminar');
        if (!$id) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'ID de rol no proporcionado']));
        }

        // Check if users use this role
        $users = $this->db->get_where('vendedores_roles', ['rol' => (string)$id])->num_rows(); 
        // Note: vendedores_roles.rol might contain the string name or id, let's check id_rol in vendedores
        $users_count = $this->db->get_where('vendedores', ['id_rol' => $id])->num_rows();

        if ($users_count > 0) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'No se puede eliminar el rol porque tiene usuarios asignados.']));
        }

        $this->db->trans_start();
        $this->db->where('id', $id)->delete('roles');
        $this->db->where('id_rol', $id)->delete('permisos_roles');
        $this->db->where('id_rol', $id)->delete('permisos_sucursales_roles');
        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al eliminar el rol']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['message' => 'Rol eliminado con éxito']));
    }

    public function get_permisos_y_sucursales($id_rol) {
        $this->check_permission('Usuarios', 'ver');
        
        $permisos = $this->db->get_where('permisos_roles', ['id_rol' => $id_rol])->result_array();
        $sucursales = $this->db->get_where('permisos_sucursales_roles', ['id_rol' => $id_rol])->result_array();
        
        $module_permissions = [];
        foreach ($permisos as $p) {
            $module_permissions[$p['id_modulo']] = [
                'ver' => (bool)$p['ver'],
                'crear' => (bool)$p['crear'],
                'editar' => (bool)$p['editar'],
                'eliminar' => (bool)$p['eliminar']
            ];
        }

        $branches_allowed = array_column($sucursales, 'id_sucursal');

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'module_permissions' => $module_permissions,
                'branches_allowed' => $branches_allowed
            ]));
    }
    public function permissions_by_user() {
        $user_id = $this->input->get('user_id');
        if (!$user_id) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'user_id es requerido']));
        }

        $user = $this->db->get_where('vendedores', ['id' => $user_id])->row();
        if (!$user) {
            return $this->output->set_status_header(404)->set_output(json_encode(['error' => 'Usuario no encontrado']));
        }

        $rolId = $user->id_rol;
        if (empty($rolId)) {
            $roleName = $user->rol;
            $rolRow = $this->db->get_where('roles', ['nombre_rol' => $roleName])->row();
            $rolId = $rolRow ? $rolRow->id : 2;
        }

        $permissions = $this->get_effective_permissions($user_id, $rolId, 'SISVEN');
        $menuTree = $this->build_menu_tree($permissions);

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'permissions' => $permissions,
                'menu' => $menuTree
            ]));
    }
}
