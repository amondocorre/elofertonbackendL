<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Controlador base personalizado para el sistema de control de accesos y permisos.
 * Sigue los estándares PSR-12 y las directrices del proyecto.
 */
class MY_Controller extends CI_Controller {

    public function __construct() {
        parent::__construct();
        
        // Habilitar CORS de manera general
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-User-Id, X-Rol-Id, X-Active-Branch, X-QR-Env, X-QR-ENV');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit();
        }

        $this->load->database();
    }

    /**
     * Obtiene los permisos efectivos combinando la configuración de rol y excepciones de usuario.
     * 
     * Regla: Si hay un registro en permisos_usuarios para el módulo, se aplica (override).
     * Si no existe, se aplican los permisos definidos para el rol en permisos_roles.
     * Si no hay ninguno, el permiso por defecto es 0 (denegado).
     */
    protected function get_effective_permissions($userId, $rolId, $sistemaName = 'SISVEN') {
        // Obtener el ID del sistema
        $sistema = $this->db->get_where('sistemas', ['nombre_sistema' => $sistemaName])->row();
        $sistemaId = $sistema ? $sistema->id : 0;

        // Comprobar si el usuario tiene excepciones registradas en permisos_usuarios
        $hasCustom = $this->db->get_where('permisos_usuarios', ['id_usuario' => $userId])->num_rows() > 0;

        if ($hasCustom) {
            // Si el usuario tiene excepciones personalizadas en permisos_usuarios, prevalecen sobre el rol
            $sql = "SELECT 
                        m.id AS id_modulo,
                        m.nombre_modulo,
                        m.url,
                        m.icono,
                        m.id_padre,
                        m.orden,
                        COALESCE(u_perm.ver, r_perm.ver, 0) AS ver,
                        COALESCE(u_perm.crear, r_perm.crear, 0) AS crear,
                        COALESCE(u_perm.editar, r_perm.editar, 0) AS editar,
                        COALESCE(u_perm.eliminar, r_perm.eliminar, 0) AS eliminar
                    FROM modulos_menu m
                    LEFT JOIN permisos_roles r_perm 
                        ON r_perm.id_modulo = m.id AND r_perm.id_rol = ?
                    LEFT JOIN permisos_usuarios u_perm 
                        ON u_perm.id_modulo = m.id AND u_perm.id_usuario = ?
                    WHERE m.id_sistema = ?
                    ORDER BY m.id_padre ASC, m.orden ASC";

            return $this->db->query($sql, [$rolId, $userId, $sistemaId])->result_array();
        } else {
            // Si no tiene excepciones personalizadas, combina los permisos del rol principal y múltiples roles
            $rolesList = [intval($rolId)];
            $userRoles = $this->db->get_where('vendedores_roles', ['vendedor_id' => $userId])->result_array();
            foreach ($userRoles as $ur) {
                $rName = $ur['rol'];
                $rRow = $this->db->get_where('roles', ['nombre_rol' => $rName])->row();
                if ($rRow && !in_array((int)$rRow->id, $rolesList)) {
                    $rolesList[] = (int)$rRow->id;
                }
            }

            $rolesPlaceholder = implode(',', $rolesList);
            $sql = "SELECT 
                        m.id AS id_modulo,
                        m.nombre_modulo,
                        m.url,
                        m.icono,
                        m.id_padre,
                        m.orden,
                        COALESCE(MAX(r_perm.ver), 0) AS ver,
                        COALESCE(MAX(r_perm.crear), 0) AS crear,
                        COALESCE(MAX(r_perm.editar), 0) AS editar,
                        COALESCE(MAX(r_perm.eliminar), 0) AS eliminar
                    FROM modulos_menu m
                    LEFT JOIN permisos_roles r_perm 
                        ON r_perm.id_modulo = m.id AND r_perm.id_rol IN ($rolesPlaceholder)
                    WHERE m.id_sistema = ?
                    GROUP BY m.id
                    ORDER BY m.id_padre ASC, m.orden ASC";

            return $this->db->query($sql, [$sistemaId])->result_array();
        }
    }

    /**
     * Valida si el usuario actual tiene permisos para ejecutar una acción sobre un módulo específico.
     * Si no tiene permisos, interrumpe el script enviando un estado HTTP 403.
     */
    protected function check_permission($moduloName, $action = 'ver', $sistemaName = 'SISVEN') {
        // Obtener datos del usuario desde los encabezados o token (para API stateless)
        $userId = $this->input->get_request_header('X-User-Id', TRUE);
        $rolId = $this->input->get_request_header('X-Rol-Id', TRUE);

        // Fallback robusto para servidores que no mapean get_request_header correctamente
        if (empty($userId)) {
            $userId = isset($_SERVER['HTTP_X_USER_ID']) ? $_SERVER['HTTP_X_USER_ID'] : (isset($_SERVER['HTTP_X_User_Id']) ? $_SERVER['HTTP_X_User_Id'] : '');
        }
        if (empty($rolId)) {
            $rolId = isset($_SERVER['HTTP_X_ROL_ID']) ? $_SERVER['HTTP_X_ROL_ID'] : (isset($_SERVER['HTTP_X_Rol_Id']) ? $_SERVER['HTTP_X_Rol_Id'] : '');
        }

        // Si no se proveen por encabezados, validar de manera tradicional (o retornar 401/403)
        if (empty($userId) || empty($rolId)) {
            $this->output
                ->set_status_header(401)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'No se encontraron las credenciales de usuario en la petición (Auth Headers vacíos).']))
                ->_display();
            exit();
        }

        $permissions = $this->get_effective_permissions($userId, $rolId, $sistemaName);

        foreach ($permissions as $p) {
            if (strcasecmp($p['nombre_modulo'], $moduloName) === 0) {
                if (isset($p[$action]) && intval($p[$action]) === 1) {
                    return TRUE; // Permiso concedido
                }
            }
        }

        // Permiso denegado
        $this->output
            ->set_status_header(403)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'error' => "Acceso Denegado. No tienes permisos para realizar la acción '$action' en el módulo '$moduloName'."
            ]))
            ->_display();
        exit();
    }

    /**
     * Devuelve la estructura jerárquica de menús listos para pintar en el Sidebar (Frontend).
     */
    protected function build_menu_tree($permissions) {
        $menuTree = [];
        $submenus = [];

        foreach ($permissions as $p) {
            // Solo considerar si tiene permiso de Ver
            if (intval($p['ver']) === 1) {
                $item = [
                    'id' => intval($p['id_modulo']),
                    'nombre' => $p['nombre_modulo'],
                    'url' => $p['url'],
                    'icono' => $p['icono'],
                    'orden' => intval($p['orden']),
                    'permisos' => [
                        'ver' => intval($p['ver']),
                        'crear' => intval($p['crear']),
                        'editar' => intval($p['editar']),
                        'eliminar' => intval($p['eliminar'])
                    ],
                    'submenu' => []
                ];

                if (empty($p['id_padre'])) {
                    $menuTree[$item['id']] = $item;
                } else {
                    $submenus[] = [
                        'parent_id' => intval($p['id_padre']),
                        'data' => $item
                    ];
                }
            }
        }

        // Asociar submenús a sus respectivos padres
        foreach ($submenus as $sub) {
            if (isset($menuTree[$sub['parent_id']])) {
                $menuTree[$sub['parent_id']]['submenu'][] = $sub['data'];
            }
        }

        // Reindexar y ordenar
        usort($menuTree, function($a, $b) {
            return $a['orden'] - $b['orden'];
        });

        foreach ($menuTree as &$item) {
            if (!empty($item['submenu'])) {
                usort($item['submenu'], function($a, $b) {
                    return $a['orden'] - $b['orden'];
                });
            }
        }

        return $menuTree;
    }

    /**
     * Obtiene el listado de sucursales (depósitos) permitidas para un usuario.
     * Evalúa la prioridad: excepciones por usuario primero, luego la configuración por rol,
     * y si no hay configuraciones, la sucursal/ciudad por defecto.
     */
    protected function get_allowed_branches($userId, $rolId, $defaultBranchId = null) {
        // 1. Validar excepciones directas del usuario
        $userBranchQuery = $this->db->select('d.id, d.nombre')
            ->from('permisos_sucursales_usuarios p')
            ->join('depositos d', 'p.id_sucursal = d.id')
            ->where('p.id_usuario', $userId)
            ->where('d.estado', 'activo')
            ->get();

        if ($userBranchQuery->num_rows() > 0) {
            return $userBranchQuery->result_array();
        }

        // 2. Si no hay excepciones, obtener las sucursales vinculadas a su rol
        $roleBranchQuery = $this->db->select('d.id, d.nombre')
            ->from('permisos_sucursales_roles p')
            ->join('depositos d', 'p.id_sucursal = d.id')
            ->where('p.id_rol', $rolId)
            ->where('d.estado', 'activo')
            ->get();

        if ($roleBranchQuery->num_rows() > 0) {
            return $roleBranchQuery->result_array();
        }

        // 3. Fallback a la sucursal por defecto (vendedores.ciudad)
        if (!empty($defaultBranchId)) {
            $defaultQuery = $this->db->get_where('depositos', ['id' => $defaultBranchId, 'estado' => 'activo']);
            if ($defaultQuery->num_rows() > 0) {
                return [$defaultQuery->row_array()];
            }
        }

        // Si no hay nada, retornar vacío
        return [];
    }

    /**
     * Obtiene el ID de la sucursal activa actual enviado desde las cabeceras del frontend (X-Active-Branch).
     * Valida que el usuario realmente posea acceso a esta sucursal antes de proceder.
     */
    protected function get_active_branch_id() {
        $userId = $this->input->get_request_header('X-User-Id', TRUE);
        $rolId = $this->input->get_request_header('X-Rol-Id', TRUE);
        $activeBranchHeader = $this->input->get_request_header('X-Active-Branch', TRUE);

        if (empty($userId) || empty($rolId)) {
            return null;
        }

        // Obtener la sucursal por defecto del usuario
        $user = $this->db->select('ciudad')->get_where('vendedores', ['id' => $userId])->row();
        $defaultBranchId = $user ? $user->ciudad : null;

        $allowedBranches = $this->get_allowed_branches($userId, $rolId, $defaultBranchId);
        $allowedIds = array_column($allowedBranches, 'id');

        if (empty($allowedIds)) {
            return null;
        }

        // Validar si el header recibido está entre las sucursales permitidas
        if (!empty($activeBranchHeader) && in_array(intval($activeBranchHeader), $allowedIds)) {
            return intval($activeBranchHeader);
        }

        // Si el header no se envió o no está autorizado, retornar la sucursal por defecto (la primera permitida)
        return intval($allowedIds[0]);
    }

    /**
     * Aplica automáticamente el filtro de la sucursal activa actual a las consultas de Active Record en CodeIgniter 3.
     * Si el usuario tiene un rol que requiere aislamiento o si ha seleccionado una sucursal específica,
     * inyecta la cláusula WHERE correspondiente en la base de datos.
     */
    protected function apply_branch_filter($column_name = 'deposito') {
        $activeBranchId = $this->get_active_branch_id();
        if ($activeBranchId !== null) {
            $this->db->where($column_name, $activeBranchId);
        }
    }
}
