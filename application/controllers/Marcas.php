<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Marcas extends CI_Controller {

    public function __construct() {
        parent::__construct();
        // Configuración de cabeceras CORS
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        header('Access-Control-Max-Age: 86400');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit(0);
        }
        
        $this->load->database();
    }

    /**
     * Obtiene el listado de marcas paginado y filtrado.
     */
    public function index() {
        $search = $this->input->get('q');
        $page = $this->input->get('page') ? intval($this->input->get('page')) : null;
        $limit = $this->input->get('limit') ? intval($this->input->get('limit')) : 50;
        
        // 1. Obtener el total de registros para paginación
        $count_sql = "SELECT COUNT(*) as total FROM marcas";
        if (!empty($search)) {
            $search_escaped = $this->db->escape_like_str(trim($search));
            $count_sql .= " WHERE nombre LIKE '%$search_escaped%' 
                             OR pais LIKE '%$search_escaped%' ";
        }
        $total_query = $this->db->query($count_sql);
        $total_records = intval($total_query->row()->total);

        // 2. Obtener los registros paginados con join del vendedor de baja
        $sql = "SELECT m.*, v.nombre as usuario_baja_nombre 
                FROM marcas m 
                LEFT JOIN vendedores v ON m.id_usuario_baja = v.id";
        if (!empty($search)) {
            $search_escaped = $this->db->escape_like_str(trim($search));
            $sql .= " WHERE m.nombre LIKE '%$search_escaped%' 
                       OR m.pais LIKE '%$search_escaped%' ";
        }
        
        $sql .= " ORDER BY m.nombre ASC";

        if ($page !== null) {
            $offset = ($page - 1) * $limit;
            $sql .= " LIMIT $offset, $limit";
        } else {
            $sql .= " LIMIT $limit";
        }
        
        $query = $this->db->query($sql);
        $marcas = $query->result();

        // Devolver respuesta estructurada si se solicita paginado
        if ($page !== null) {
            $response = [
                'data'  => $marcas,
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
            ->set_output(json_encode($marcas));
    }

    /**
     * Guarda o edita una marca con control de duplicidad por nombre.
     */
    public function guardar() {
        $raw_input = file_get_contents('php://input');
        $data = json_decode($raw_input, true);

        if (!$data || empty($data['nombre'])) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El nombre de la marca es obligatorio']));
        }

        $id = isset($data['id']) ? intval($data['id']) : null;
        $name = trim($data['nombre']);

        // Validación de Marca Duplicada por nombre
        $this->db->where('nombre', $name);
        if ($id) {
            $this->db->where('id !=', $id);
        }
        $query = $this->db->get('marcas');
        
        if ($query->num_rows() > 0) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => "Ya existe una marca registrada con el nombre '$name'."
                ]));
        }

        $brand_data = [
            'nombre' => $name,
            'pais'   => isset($data['pais']) ? trim($data['pais']) : ''
        ];

        if ($id) {
            // Edición
            $this->db->where('id', $id);
            $this->db->update('marcas', $brand_data);
            $message = 'Marca actualizada con éxito';
        } else {
            // Registro nuevo
            $brand_data['estado'] = 'Activo';
            $this->db->insert('marcas', $brand_data);
            $id = $this->db->insert_id();
            $message = 'Marca registrada con éxito';
        }

        // Obtener la marca guardada
        $this->db->where('id', $id);
        $saved_brand = $this->db->get('marcas')->row();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'message' => $message,
                'marca'   => $saved_brand
            ]));
    }

    /**
     * Inactiva una marca (baja lógica).
     */
    public function inactivar($id = null) {
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de marca no proporcionado']));
        }

        $raw_input = file_get_contents('php://input');
        $data = json_decode($raw_input, true);
        $id_usuario_baja = isset($data['id_usuario_baja']) ? intval($data['id_usuario_baja']) : null;

        $this->db->where('id', $id);
        $this->db->update('marcas', [
            'estado'          => 'Inactivo',
            'id_usuario_baja' => $id_usuario_baja
        ]);

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Marca inactivada con éxito']));
    }

    /**
     * Reactiva una marca.
     */
    public function reactivar($id = null) {
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de marca no proporcionado']));
        }

        $this->db->where('id', $id);
        $this->db->update('marcas', [
            'estado'          => 'Activo',
            'id_usuario_baja' => null
        ]);

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Marca reactivada con éxito']));
    }

    /**
     * Elimina una marca por su ID.
     */
    public function eliminar($id = null) {
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de marca no proporcionado']));
        }

        // Obtener datos de la marca para verificar si tiene productos asociados por nombre
        $this->db->where('id', $id);
        $brand = $this->db->get('marcas')->row();

        if (!$brand) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(404)
                ->set_output(json_encode(['error' => 'Marca no encontrada']));
        }

        // Validar si tiene productos vinculados
        $this->db->where('marca', $brand->nombre);
        $query = $this->db->get('productos');
        if ($query->num_rows() > 0) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'No se puede eliminar la marca porque existen productos registrados en el sistema vinculados a ella']));
        }

        // Eliminar marca
        $this->db->where('id', $id);
        $this->db->delete('marcas');

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Marca eliminada con éxito']));
    }
}
