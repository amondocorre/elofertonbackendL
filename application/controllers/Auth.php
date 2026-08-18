<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Auth extends MY_Controller {

    public function __construct() {
        parent::__construct();
    }

    public function login() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $email = $data['email'] ?? '';
            $password = $data['password'] ?? '';

            if (empty($email) || empty($password)) {
                return $this->output
                    ->set_status_header(400)
                    ->set_content_type('application/json')
                    ->set_output(json_encode(['error' => 'Email y contraseña son requeridos']));
            }

            $login_input = trim($email);
            $this->db->group_start();
            $this->db->where('email', $login_input);
            if ($this->db->field_exists('telefono', 'vendedores')) {
                $this->db->or_where('telefono', $login_input);
            }
            if ($this->db->field_exists('ci', 'vendedores')) {
                $this->db->or_where('ci', $login_input);
            }
            if ($this->db->field_exists('usuario', 'vendedores')) {
                $this->db->or_where('usuario', $login_input);
            }
            $this->db->group_end();

            $this->db->where('password', $password);
            $this->db->where('estado', 'activo');
            $user = $this->db->get('vendedores')->row();

            // Validar contraseña en texto plano (como en el sistema antiguo)
            if ($user) {
                // Registrar fecha y hora de último ingreso en fechault
                $fecha_actual = date('Y-m-d H:i:s');
                if ($this->db->field_exists('fechault', 'vendedores')) {
                    $this->db->where('id', $user->id)->update('vendedores', ['fechault' => $fecha_actual]);
                }
                $user->fechault = $fecha_actual;

                // Generar un token simple
                $token = bin2hex(random_bytes(32));
                
                // Renombrar 'nombre' a 'name' para compatibilidad en el frontend
                $user->name = $user->nombre;
                unset($user->password); // No devolver la contraseña

                // Obtener el ID del rol estructurado o asociar por defecto
                $rolId = $user->id_rol ?? null;
                if (empty($rolId)) {
                    $roleName = $user->rol ?? '';
                    if (stripos($roleName, 'admin') !== false) {
                        $rolId = 1;
                    } else if (stripos($roleName, 'vend') !== false) {
                        $rolId = 2;
                    } else if (stripos($roleName, 'enc') !== false || stripos($roleName, 'caja') !== false) {
                        $rolId = 3;
                    } else {
                        $rolRow = $this->db->table_exists('roles') ? $this->db->get_where('roles', ['nombre_rol' => $roleName])->row() : null;
                        $rolId = $rolRow ? $rolRow->id : 2; // Por defecto rol de Vendedor
                    }
                    
                    // Actualizar localmente para el response si existe la columna id_rol
                    $user->id_rol = $rolId;
                    if ($this->db->field_exists('id_rol', 'vendedores')) {
                        $this->db->where('id', $user->id)->update('vendedores', ['id_rol' => $rolId]);
                    }
                }

                // Obtener los múltiples roles
                $user->roles = [];
                if ($this->db->table_exists('vendedores_roles')) {
                    $rolesQuery = $this->db->get_where('vendedores_roles', ['vendedor_id' => $user->id])->result();
                    $user->roles = array_column($rolesQuery, 'rol');
                }
                if (empty($user->roles) && !empty($user->rol)) {
                    $user->roles = [$user->rol];
                }

                // Cargar los permisos efectivos del usuario
                $permissions = $this->get_effective_permissions($user->id, $user->id_rol, 'SISVEN');
                $menuTree = $this->build_menu_tree($permissions);

                // Cargar las sucursales permitidas
                $branches = $this->get_allowed_branches($user->id, $user->id_rol, $user->ciudad);

                return $this->output
                    ->set_content_type('application/json')
                    ->set_output(json_encode([
                        'message' => 'Login exitoso',
                        'token' => $token,
                        'user' => $user,
                        'permissions' => $permissions,
                        'menu' => $menuTree,
                        'branches' => $branches
                    ]));
            }

            return $this->output
                ->set_status_header(401)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Credenciales inválidas']));
        } catch (\Throwable $e) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]));
        }
    }

    /**
     * Endpoint para consultar permisos y estructura de menús en caliente
     */
    public function get_permissions() {
        $userId = $this->input->get_post('userId');
        $rolId = $this->input->get_post('rolId');
        $sistema = $this->input->get_post('sistema') ?? 'SISVEN';

        if (empty($userId) || empty($rolId)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'userId y rolId son requeridos']));
        }

        $permissions = $this->get_effective_permissions($userId, $rolId, $sistema);
        $menuTree = $this->build_menu_tree($permissions);

        // Obtener la sucursal por defecto del usuario
        $user = $this->db->select('ciudad')->get_where('vendedores', ['id' => $userId])->row();
        $defaultBranchId = $user ? $user->ciudad : null;
        $branches = $this->get_allowed_branches($userId, $rolId, $defaultBranchId);

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'permissions' => $permissions,
                'menu' => $menuTree,
                'branches' => $branches
            ]));
    }

    /**
     * Permite a un vendedor/usuario cambiar su contraseña de acceso.
     */
    public function change_password() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $userId = $data['user_id'] ?? '';
        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        if (empty($userId) || empty($currentPassword) || empty($newPassword)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Todos los campos son requeridos']));
        }

        // Obtener usuario actual
        $user = $this->db->get_where('vendedores', ['id' => intval($userId)])->row();

        if (!$user) {
            return $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Usuario no encontrado']));
        }

        // Validar contraseña actual en texto plano
        if ($user->password !== $currentPassword) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'La contraseña actual es incorrecta']));
        }

        // Actualizar contraseña en la BD
        $updated = $this->db->where('id', $user->id)->update('vendedores', ['password' => $newPassword]);

        if (!$updated) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'No se pudo actualizar la contraseña. Intente nuevamente.']));
        }

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode(['message' => 'Contraseña actualizada con éxito.']));
    }
}
