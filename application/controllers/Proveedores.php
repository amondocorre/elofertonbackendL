<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Proveedores extends MY_Controller {

    public function __construct() {
        parent::__construct();
        // Configuración de cabeceras CORS
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, X-User-Id, X-Rol-Id, X-Active-Branch, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        header('Access-Control-Max-Age: 86400');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit(0);
        }
        
        $this->load->database();
    }

    /**
     * Obtiene el listado de proveedores paginado y filtrado.
     */
    public function index() {
        $this->check_permission('Proveedores', 'ver');
        $search = $this->input->get('q');
        $page = $this->input->get('page') ? intval($this->input->get('page')) : null;
        $limit = $this->input->get('limit') ? intval($this->input->get('limit')) : 50;
        
        // 1. Obtener el total de registros para paginación
        $count_sql = "SELECT COUNT(*) as total FROM proveedores";
        if (!empty($search)) {
            $search_escaped = $this->db->escape_like_str(trim($search));
            $count_sql .= " WHERE nombre LIKE '%$search_escaped%' 
                             OR contacto LIKE '%$search_escaped%' 
                             OR telefono LIKE '%$search_escaped%' 
                             OR telefono_fijo LIKE '%$search_escaped%'
                             OR nit LIKE '%$search_escaped%'
                             OR ciudad LIKE '%$search_escaped%' ";
        }
        $total_query = $this->db->query($count_sql);
        $total_records = intval($total_query->row()->total);

        // 2. Obtener los registros paginados con el nombre del usuario de baja
        $sql = "SELECT p.*, u.nombre as usuario_baja_nombre 
                FROM proveedores p 
                LEFT JOIN vendedores u ON p.id_usuario_baja = u.id";
        if (!empty($search)) {
            $search_escaped = $this->db->escape_like_str(trim($search));
            $sql .= " WHERE p.nombre LIKE '%$search_escaped%' 
                       OR p.contacto LIKE '%$search_escaped%' 
                       OR p.telefono LIKE '%$search_escaped%' 
                       OR p.telefono_fijo LIKE '%$search_escaped%'
                       OR p.nit LIKE '%$search_escaped%'
                       OR p.ciudad LIKE '%$search_escaped%' ";
        }
        
        $sql .= " ORDER BY p.nombre ASC";

        if ($page !== null) {
            $offset = ($page - 1) * $limit;
            $sql .= " LIMIT $offset, $limit";
        } else {
            $sql .= " LIMIT $limit";
        }
        
        $query = $this->db->query($sql);
        $proveedores = $query->result();

        // Devolver respuesta estructurada si se solicita paginado
        if ($page !== null) {
            $response = [
                'data'  => $proveedores,
                'total' => $total_records,
                'page'  => $page,
                'pages' => ceil($total_records / $limit)
            ];
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(200)
                ->set_output(json_encode($response));
        }

        // De lo contrario, formato plano
        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode($proveedores));
    }

    /**
     * Guarda o edita un proveedor con control de duplicidad por nombre.
     */
    public function guardar() {
        $raw_input = file_get_contents('php://input');
        $data = json_decode($raw_input, true);

        if (!$data || empty($data['nombre'])) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El nombre del proveedor es obligatorio']));
        }



        $id = isset($data['id']) ? intval($data['id']) : null;
        if ($id) {
            $this->check_permission('Proveedores', 'editar');
        } else {
            $this->check_permission('Proveedores', 'crear');
        }
        $name = mb_strtoupper(trim($data['nombre']), 'UTF-8');
        $contact = isset($data['contacto']) ? mb_strtoupper(trim($data['contacto']), 'UTF-8') : '';
        $nit = isset($data['nit']) ? trim($data['nit']) : '';

        // Validación de Proveedor Duplicado por nombre
        $this->db->where('nombre', $name);
        if ($id) {
            $this->db->where('id !=', $id);
        }
        $query = $this->db->get('proveedores');
        
        if ($query->num_rows() > 0) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => "Ya existe un proveedor registrado con el nombre '$name'."
                ]));
        }

        // Validación de Proveedor Duplicado por NIT solo si se proporciona un NIT
        if (!empty($nit)) {
            $this->db->where('nit', $nit);
            if ($id) {
                $this->db->where('id !=', $id);
            }
            $query_nit = $this->db->get('proveedores');
            
            if ($query_nit->num_rows() > 0) {
                return $this->output
                    ->set_content_type('application/json')
                    ->set_status_header(400)
                    ->set_output(json_encode([
                        'error' => "Ya existe un proveedor registrado con el NIT '$nit'."
                    ]));
            }
        }

        $supplier_data = [
            'nombre'        => $name,
            'nit'           => isset($data['nit']) ? trim($data['nit']) : '',
            'contacto'      => $contact,
            'telefono'      => isset($data['telefono']) ? trim($data['telefono']) : '',
            'telefono_fijo' => isset($data['telefono_fijo']) ? trim($data['telefono_fijo']) : '',
            'ciudad'        => isset($data['ciudad']) ? trim($data['ciudad']) : '',
            'direccion'     => isset($data['direccion']) ? trim($data['direccion']) : ''
        ];

        if ($id) {
            // Edición
            $this->db->where('id', $id);
            $this->db->update('proveedores', $supplier_data);
            $message = 'Proveedor actualizado con éxito';
        } else {
            // Registro nuevo
            $supplier_data['estado'] = 'Activo';
            $this->db->insert('proveedores', $supplier_data);
            $id = $this->db->insert_id();
            $message = 'Proveedor registrado con éxito';
        }

        // Obtener el proveedor guardado
        $this->db->where('id', $id);
        $saved_supplier = $this->db->get('proveedores')->row();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'message'   => $message,
                'proveedor' => $saved_supplier
            ]));
    }

    /**
     * Inactiva un proveedor (baja lógica).
     */
    public function inactivar($id = null) {
        $this->check_permission('Proveedores', 'eliminar');
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de proveedor no proporcionado']));
        }

        $raw_input = file_get_contents('php://input');
        $data = json_decode($raw_input, true);
        $id_usuario_baja = isset($data['id_usuario_baja']) ? intval($data['id_usuario_baja']) : null;

        $this->db->where('id', $id);
        $this->db->update('proveedores', [
            'estado'          => 'Inactivo',
            'id_usuario_baja' => $id_usuario_baja
        ]);

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Proveedor inactivado con éxito']));
    }

    /**
     * Reactiva un proveedor.
     */
    public function reactivar($id = null) {
        $this->check_permission('Proveedores', 'eliminar');
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de proveedor no proporcionado']));
        }

        $this->db->where('id', $id);
        $this->db->update('proveedores', [
            'estado'          => 'Activo',
            'id_usuario_baja' => null
        ]);

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Proveedor reactivado con éxito']));
    }

    /**
     * Elimina un proveedor por su ID.
     */
    public function eliminar($id = null) {
        $this->check_permission('Proveedores', 'eliminar');
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de proveedor no proporcionado']));
        }

        // Obtener datos del proveedor para verificar si tiene compras asociadas por nombre
        $this->db->where('id', $id);
        $supplier = $this->db->get('proveedores')->row();

        if (!$supplier) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(404)
                ->set_output(json_encode(['error' => 'Proveedor no encontrado']));
        }

        // Validar si tiene compras registradas
        $this->db->where('proveedor', $supplier->nombre);
        $query = $this->db->get('compras');
        if ($query->num_rows() > 0) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'No se puede eliminar el proveedor porque tiene compras registradas en el sistema']));
        }

        // Eliminar proveedor
        $this->db->where('id', $id);
        $this->db->delete('proveedores');

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Proveedor eliminado con éxito']));
    }

    /**
     * Verifica si un NIT de proveedor ya existe.
     */
    public function verificar_nit() {
        $nit = $this->input->get('nit');
        $id = $this->input->get('id') ? intval($this->input->get('id')) : null;

        if (empty($nit)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(200)
                ->set_output(json_encode(['exists' => false]));
        }

        $this->db->where('nit', trim($nit));
        if ($id) {
            $this->db->where('id !=', $id);
        }
        $query = $this->db->get('proveedores');

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['exists' => $query->num_rows() > 0]));
    }
}
