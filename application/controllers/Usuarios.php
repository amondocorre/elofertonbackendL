<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Usuarios extends CI_Controller {

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

    // Obtener la lista de usuarios (vendedores)
    public function index() {
        $this->db->select("vendedores.*, depositos.nombre as deposito_nombre, GROUP_CONCAT(vendedores_roles.rol SEPARATOR ',') as roles_string");
        $this->db->from('vendedores');
        // Join para obtener el nombre del depósito/sucursal
        $this->db->join('depositos', 'vendedores.ciudad = depositos.id', 'left');
        // Join para obtener los roles múltiples
        $this->db->join('vendedores_roles', 'vendedores.id = vendedores_roles.vendedor_id', 'left');
        $this->db->group_by('vendedores.id');
        $this->db->order_by('vendedores.nombre', 'ASC');
        $usuarios = $this->db->get()->result();

        // Parsear roles_string a arreglo
        foreach ($usuarios as $u) {
            $u->roles = $u->roles_string ? explode(',', $u->roles_string) : ($u->rol ? [$u->rol] : []);
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($usuarios));
    }

    // Obtener la lista de depósitos disponibles
    public function depositos() {
        $depositos = $this->db->get('depositos')->result();
        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($depositos));
    }

    // Obtener la lista de bancos disponibles desde la tabla bancos
    public function bancos() {
        $this->load->model('Banco_model');
        $bancos = $this->Banco_model->get_bancos();
        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($bancos));
    }

    // Guardar (Crear o Actualizar) usuario
    public function guardar() {
        $data = json_decode(file_get_contents('php://input'), true);

        $id = $data['id'] ?? null;
        $nombre = $data['nombre'] ?? '';
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $roles = $data['roles'] ?? []; // Arreglo de roles
        $rol = $data['rol'] ?? 'Vendedores'; // Rol principal por compatibilidad
        
        // Si mandan roles vacíos pero mandan rol, usamos ese rol.
        if (empty($roles) && !empty($rol)) {
            $roles = [$rol];
        } else if (!empty($roles)) {
            $rol = $roles[0]; // Actualizar rol principal al primer rol del array
        }

        $ciudad = $data['ciudad'] ?? '1'; // depósito/ciudad
        $telefono = $data['telefono'] ?? '';
        $direccion = $data['direccion'] ?? '';
        $carnet = $data['carnet'] ?? '';
        $estado = $data['estado'] ?? 'activo';
        $cargo = $data['cargo'] ?? null;

        // Validaciones en servidor
        if (empty($nombre) || empty($email)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Nombre y Email/Usuario son obligatorios']));
        }

        // Si es nuevo usuario, la contraseña es requerida
        if (!$id && empty($password)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'La contraseña es obligatoria para nuevos usuarios']));
        }

        // Validar email único (excepto para sí mismo al editar)
        $this->db->where('email', $email);
        if ($id) {
            $this->db->where('id !=', $id);
        }
        $existing = $this->db->get('vendedores')->row();
        if ($existing) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El Email/Usuario ya está registrado']));
        }

        // Obtener id_rol correspondiente al rol principal
        $rolMap = [
            'Administradores' => 'Administrador',
            'Vendedores' => 'Vendedor',
            'Enc. Tienda y caja' => 'Encargado de tienda',
            'editor' => 'Editor'
        ];
        $mappedRol = $rolMap[$rol] ?? $rol;
        
        $rolRow = $this->db->get_where('roles', ['nombre_rol' => $mappedRol])->row();
        $id_rol = $rolRow ? $rolRow->id : 2; // Vendedor por defecto

        $saveData = [
            'nombre' => $nombre,
            'email' => $email,
            'rol' => $rol,
            'id_rol' => $id_rol,
            'ciudad' => $ciudad,
            'telefono' => $telefono,
            'direccion' => $direccion,
            'carnet' => $carnet,
            'estado' => $estado,
            'cargo' => $cargo,
            'recibe_comision' => isset($data['recibe_comision']) ? intval($data['recibe_comision']) : 1,
            'nro_cuenta' => $data['nro_cuenta'] ?? null,
            'banco' => $data['banco'] ?? null
        ];

        // Solo actualizar contraseña si se ha proporcionado una nueva
        if (!empty($password)) {
            $saveData['password'] = $password; // Almacenado en texto plano según el sistema antiguo
        }

        if ($id) {
            // Actualizar
            $this->db->where('id', $id);
            $this->db->update('vendedores', $saveData);
            $vendedor_id = $id;
            $message = 'Usuario actualizado exitosamente';
        } else {
            // Crear
            $saveData['fechareg'] = date('Y-m-d H:i:s');
            $this->db->insert('vendedores', $saveData);
            $vendedor_id = $this->db->insert_id();
            $message = 'Usuario creado exitosamente';
        }

        // Guardar roles múltiples en la tabla vendedores_roles
        if ($vendedor_id) {
            $this->db->where('vendedor_id', $vendedor_id)->delete('vendedores_roles');
            if (!empty($roles)) {
                $batch_roles = [];
                foreach ($roles as $r) {
                    $batch_roles[] = [
                        'vendedor_id' => $vendedor_id,
                        'rol' => $r
                    ];
                }
                $this->db->insert_batch('vendedores_roles', $batch_roles);
            }

            // Guardar sucursales permitidas (excepciones) si se envían
            if (isset($data['branches_allowed'])) {
                $branchesAllowed = $data['branches_allowed'];
                $this->db->where('id_usuario', $vendedor_id)->delete('permisos_sucursales_usuarios');
                if (!empty($branchesAllowed)) {
                    $batch_branches = [];
                    foreach ($branchesAllowed as $bId) {
                        $batch_branches[] = [
                            'id_usuario' => $vendedor_id,
                            'id_sucursal' => intval($bId)
                        ];
                    }
                    $this->db->insert_batch('permisos_sucursales_usuarios', $batch_branches);
                }
            }

            // Guardar excepciones de permisos de módulos si se envían
            if (isset($data['module_exceptions'])) {
                $moduleExceptions = $data['module_exceptions'];
                $this->db->where('id_usuario', $vendedor_id)->delete('permisos_usuarios');
                if (!empty($moduleExceptions)) {
                    $batch_perms = [];
                    foreach ($moduleExceptions as $modId => $actions) {
                        $batch_perms[] = [
                            'id_usuario' => $vendedor_id,
                            'id_modulo' => intval($modId),
                            'ver' => intval($actions['ver'] ?? 0),
                            'crear' => intval($actions['crear'] ?? 0),
                            'editar' => intval($actions['editar'] ?? 0),
                            'eliminar' => intval($actions['eliminar'] ?? 0),
                        ];
                    }
                    if (!empty($batch_perms)) {
                        $this->db->insert_batch('permisos_usuarios', $batch_perms);
                    }
                }
            }

            // Guardar almacenes autorizados (Conciliaciones) si se envían
            if (isset($data['warehouses_allowed'])) {
                $warehousesAllowed = $data['warehouses_allowed'];
                $this->db->where('usuario_id', $vendedor_id)->delete('usuarios_almacenes');
                if (!empty($warehousesAllowed)) {
                    $batch_warehouses = [];
                    foreach ($warehousesAllowed as $wId) {
                        $batch_warehouses[] = [
                            'usuario_id' => $vendedor_id,
                            'almacen_id' => intval($wId)
                        ];
                    }
                    $this->db->insert_batch('usuarios_almacenes', $batch_warehouses);
                }
            }
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['message' => $message]));
    }

    // Cambiar estado de usuario (Activar / Dar de baja)
    public function cambiar_estado() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $id = $data['id'] ?? null;
        $estado = $data['estado'] ?? '';

        if (!$id || empty($estado)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'ID y Estado son obligatorios']));
        }

        $this->db->where('id', $id);
        $this->db->update('vendedores', ['estado' => $estado]);

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['message' => 'Estado del usuario actualizado exitosamente']));
    }

    /**
     * Retorna las excepciones de permisos y las sucursales permitidas del usuario.
     */
    public function detalles_accesos() {
        $userId = $this->input->get('id');
        if ($userId === null || $userId === '') {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'ID de usuario requerido']));
        }

        // Obtener sucursales permitidas
        $branches = $this->db->select('id_sucursal')->get_where('permisos_sucursales_usuarios', ['id_usuario' => $userId])->result_array();
        $branchIds = array_map('intval', array_column($branches, 'id_sucursal'));

        // Obtener excepciones de permisos de módulos
        $perms = $this->db->get_where('permisos_usuarios', ['id_usuario' => $userId])->result_array();
        
        // Mapear excepciones en un formato indexado por id_modulo
        $exceptionsIndexed = [];
        foreach ($perms as $p) {
            $exceptionsIndexed[$p['id_modulo']] = [
                'ver' => intval($p['ver']),
                'crear' => intval($p['crear']),
                'editar' => intval($p['editar']),
                'eliminar' => intval($p['eliminar'])
            ];
        }

        // Obtener listado de módulos completo
        $modules = $this->db->order_by('id_padre ASC, orden ASC')->get('modulos_menu')->result_array();

        // Obtener almacenes autorizados (Conciliación)
        $warehouses = $this->db->select('almacen_id')->get_where('usuarios_almacenes', ['usuario_id' => $userId])->result_array();
        $warehouseIds = array_map('intval', array_column($warehouses, 'almacen_id'));

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'branches' => $branchIds,
                'exceptions' => $exceptionsIndexed,
                'modules' => $modules,
                'warehouses' => $warehouseIds
            ]));
    }
}
