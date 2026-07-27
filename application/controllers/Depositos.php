<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Depositos extends CI_Controller {

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
     * Obtiene el listado de depósitos paginado y filtrado.
     */
    public function index() {
        $search = $this->input->get('q');
        $status = $this->input->get('status'); // 'activo' o 'inactivo'
        $page = $this->input->get('page') ? intval($this->input->get('page')) : null;
        $limit = $this->input->get('limit') ? intval($this->input->get('limit')) : 50;
        
        // 1. Construir cláusula WHERE
        $where_clauses = [];
        if (!empty($search)) {
            $search_escaped = $this->db->escape_like_str(trim($search));
            $where_clauses[] = "(d.nombre LIKE '%$search_escaped%' 
                                 OR d.direccion LIKE '%$search_escaped%' 
                                 OR d.contacto LIKE '%$search_escaped%' 
                                 OR d.telefonos LIKE '%$search_escaped%')";
        }
        if (!empty($status)) {
            $status_escaped = $this->db->escape($status);
            $where_clauses[] = "d.estado = $status_escaped";
        }

        $where_sql = "";
        if (count($where_clauses) > 0) {
            $where_sql = " WHERE " . implode(" AND ", $where_clauses);
        }

        // 2. Obtener el total de registros para paginación
        $count_sql = "SELECT COUNT(*) as total FROM depositos d" . $where_sql;
        $total_query = $this->db->query($count_sql);
        $total_records = intval($total_query->row()->total);

        // 3. Obtener los registros paginados con join del vendedor de baja
        $sql = "SELECT d.*, v.nombre as usuario_baja_nombre 
                FROM depositos d 
                LEFT JOIN vendedores v ON d.id_usuario_baja = v.id" . $where_sql . " ORDER BY d.nombre ASC";

        if ($page !== null) {
            $offset = ($page - 1) * $limit;
            $sql .= " LIMIT $offset, $limit";
        } else {
            $sql .= " LIMIT $limit";
        }
        
        $query = $this->db->query($sql);
        $depositos = $query->result();

        // Devolver respuesta estructurada si se solicita paginado
        if ($page !== null) {
            $response = [
                'data'  => $depositos,
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
            ->set_output(json_encode($depositos));
    }

    /**
     * Guarda o edita un depósito con control de duplicidad por nombre.
     */
    public function guardar() {
        $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
        if (strpos($contentType, 'application/json') !== false) {
            $data = json_decode(file_get_contents('php://input'), true);
        } else {
            $data = $this->input->post();
        }

        if (!$data || empty($data['nombre'])) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El nombre del depósito es obligatorio']));
        }

        $id = isset($data['id']) && $data['id'] !== '' && $data['id'] !== 'null' ? intval($data['id']) : null;
        $name = mb_strtoupper(trim($data['nombre']));
        $contacto = isset($data['contacto']) ? mb_strtoupper(trim($data['contacto'])) : '';

        // Validación de Depósito Duplicado por nombre
        $this->db->where('nombre', $name);
        if ($id) {
            $this->db->where('id !=', $id);
        }
        $query = $this->db->get('depositos');
        
        if ($query->num_rows() > 0) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => "Ya existe un depósito registrado con el nombre '$name'."
                ]));
        }

        $foto = isset($data['foto']) ? trim($data['foto']) : null;

        // Subir foto si existe
        if (isset($_FILES['foto_file']) && !empty($_FILES['foto_file']['name'])) {
            $upload_path = FCPATH . 'uploads/sucursales/';
            if (!is_dir($upload_path)) {
                mkdir($upload_path, 0777, true);
            }
            
            $config['upload_path'] = $upload_path;
            $config['allowed_types'] = 'gif|jpg|jpeg|png|webp';
            $config['max_size'] = 5120; // 5MB
            $config['file_name'] = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['foto_file']['name']);

            $this->load->library('upload', $config);

            if ($this->upload->do_upload('foto_file')) {
                $uploadData = $this->upload->data();
                $foto = $uploadData['file_name'];
            } else {
                return $this->output
                    ->set_content_type('application/json')
                    ->set_status_header(400)
                    ->set_output(json_encode(['error' => strip_tags($this->upload->display_errors())]));
            }
        }

        $warehouse_data = [
            'nombre'       => $name,
            'direccion'    => isset($data['direccion']) ? trim($data['direccion']) : '',
            'contacto'     => $contacto,
            'telefonos'    => isset($data['telefonos']) ? trim($data['telefonos']) : '',
            'lat'          => isset($data['lat']) ? trim($data['lat']) : '0',
            'lng'          => isset($data['lng']) ? trim($data['lng']) : '0',
            'zoom'         => isset($data['zoom']) ? intval($data['zoom']) : 0,
            'ubicaciongps' => isset($data['ubicaciongps']) ? trim($data['ubicaciongps']) : '',
            'foto'         => $foto,
            'estado'       => (isset($data['estado']) && $data['estado'] === 'inactivo') ? 'inactivo' : 'activo',
            'tipo_reporte' => isset($data['tipo_reporte']) ? trim($data['tipo_reporte']) : 'carta',
            'tipo_almacen' => isset($data['tipo_almacen']) ? trim($data['tipo_almacen']) : 'Deposito_Central'
        ];

        if ($id) {
            // Edición
            $this->db->where('id', $id);
            $this->db->update('depositos', $warehouse_data);
            $message = 'Depósito actualizado con éxito';
        } else {
            // Registro nuevo
            $this->db->insert('depositos', $warehouse_data);
            $id = $this->db->insert_id();
            $message = 'Depósito registrado con éxito';
        }

        // Obtener el depósito guardado
        $this->db->where('id', $id);
        $saved_warehouse = $this->db->get('depositos')->row();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'message'  => $message,
                'deposito' => $saved_warehouse
            ]));
    }

    /**
     * Inactiva un depósito (baja lógica).
     */
    public function inactivar($id = null) {
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de depósito no proporcionado']));
        }

        $raw_input = file_get_contents('php://input');
        $data = json_decode($raw_input, true);
        $id_usuario_baja = isset($data['id_usuario_baja']) ? intval($data['id_usuario_baja']) : null;

        $this->db->where('id', $id);
        $this->db->update('depositos', [
            'estado'          => 'inactivo',
            'id_usuario_baja' => $id_usuario_baja
        ]);

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Depósito inactivado con éxito']));
    }

    /**
     * Reactiva un depósito.
     */
    public function reactivar($id = null) {
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de depósito no proporcionado']));
        }

        $this->db->where('id', $id);
        $this->db->update('depositos', [
            'estado'          => 'activo',
            'id_usuario_baja' => null
        ]);

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Depósito reactivado con éxito']));
    }

    /**
     * Elimina un depósito por su ID.
     */
    public function eliminar($id = null) {
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de depósito no proporcionado']));
        }

        // Obtener datos del depósito para verificar si tiene productos o inventario asociado
        $this->db->where('id', $id);
        $warehouse = $this->db->get('depositos')->row();

        if (!$warehouse) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(404)
                ->set_output(json_encode(['error' => 'Depósito no encontrado']));
        }

        // 1. Validar si tiene productos vinculados por ID
        $this->db->where('deposito', $id);
        $query_prod = $this->db->get('productos');
        if ($query_prod->num_rows() > 0) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'No se puede eliminar el depósito porque existen productos vinculados a él en el sistema']));
        }

        // 2. Validar si tiene inventarios registrados con su nombre
        $this->db->where('deposito', $warehouse->nombre);
        $query_inv = $this->db->get('inventarios');
        if ($query_inv->num_rows() > 0) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'No se puede eliminar el depósito porque posee registros de inventario vinculados en el sistema']));
        }

        // Eliminar depósito
        $this->db->where('id', $id);
        $this->db->delete('depositos');

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Depósito eliminado con éxito']));
    }
}
