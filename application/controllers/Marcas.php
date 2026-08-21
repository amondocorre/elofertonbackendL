<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Marcas extends MY_Controller {

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
     * Obtiene el listado de marcas paginado y filtrado.
     */
    public function index() {
        $this->check_permission('Marcas', 'ver');
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
     * Muestra las marcas con la cantidad de productos en stock en todas las sucursales.
     * GET /marcas/con_stock
     */
    public function con_stock() {
        $sql = "SELECT COALESCE(NULLIF(TRIM(m.nombre), ''), '-SIN MARCA') as marca_nombre,
                       COUNT(DISTINCT p.idprod) as cantidad_productos,
                       SUM(inv.cantidad) as total_stock
                FROM productos p
                JOIN (
                    SELECT idprod, SUM(cantidad) as cantidad 
                    FROM inventarios 
                    GROUP BY idprod 
                    HAVING SUM(cantidad) > 0
                ) inv ON p.idprod = inv.idprod
                LEFT JOIN marcas m ON p.idmarca = m.id
                WHERE p.estado = 'Activo'
                GROUP BY marca_nombre
                ORDER BY marca_nombre ASC";

        $query = $this->db->query($sql);
        $resultados = $query->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode($resultados));
    }

    /**
     * Guarda o edita una marca con control de duplicidad por nombre.
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
                ->set_output(json_encode(['error' => 'El nombre de la marca es obligatorio']));
        }

        $id = isset($data['id']) && $data['id'] !== '' && $data['id'] !== 'null' ? intval($data['id']) : null;
        if ($id) {
            $this->check_permission('Marcas', 'editar');
        } else {
            $this->check_permission('Marcas', 'crear');
        }
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

        $logo = isset($data['logo']) ? trim($data['logo']) : '';

        // Subir imagen si existe
        if (isset($_FILES['logo_file']) && !empty($_FILES['logo_file']['name'])) {
            $upload_path = FCPATH . 'uploads/marcas/';
            if (!is_dir($upload_path)) {
                mkdir($upload_path, 0777, true);
            }
            
            $ext = pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION);
            $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '', str_replace(' ', '_', $name));
            $new_filename = strtolower($safe_name) . '_' . time() . '.' . $ext;

            $config['upload_path'] = $upload_path;
            $config['allowed_types'] = '*'; // Allow all types to bypass strict MIME checks from CI3
            $config['max_size'] = 2000; // 2 MB
            $config['file_name'] = $new_filename;
            $config['overwrite'] = TRUE;

            $this->load->library('upload', $config);
            $this->upload->initialize($config);

            if ($this->upload->do_upload('logo_file')) {
                $uploadData = $this->upload->data();
                $logo = $uploadData['file_name'];
            } else {
                return $this->output
                    ->set_content_type('application/json')
                    ->set_status_header(400)
                    ->set_output(json_encode(['error' => strip_tags($this->upload->display_errors())]));
            }
        }

        $brand_data = [
            'nombre' => $name,
            'pais'   => isset($data['pais']) ? trim($data['pais']) : '',
            'direccion_garantia' => isset($data['direccion_garantia']) ? trim($data['direccion_garantia']) : '',
            'logo' => $logo
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
        $this->check_permission('Marcas', 'eliminar');
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
        $this->check_permission('Marcas', 'eliminar');
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
        $this->check_permission('Marcas', 'eliminar');
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
