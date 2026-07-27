<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Unidades extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->database();
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, X-User-Id, X-Rol-Id, X-Active-Branch, Authorization');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            die();
        }
    }

    // GET /unidades?q=...&limit=15&page=1
    public function index() {
        $search = $this->input->get('q');
        $limit = $this->input->get('limit') ? intval($this->input->get('limit')) : 15;
        $page = $this->input->get('page') ? intval($this->input->get('page')) : 1;
        
        $this->db->start_cache();
        if (!empty($search)) {
            $this->db->group_start();
            $this->db->like('descripcion', $search);
            $this->db->group_end();
        }
        $this->db->stop_cache();
        
        $total = $this->db->count_all_results('unidad_medida');
        
        $this->db->order_by('descripcion', 'ASC');
        $this->db->limit($limit, ($page - 1) * $limit);
        $unidades = $this->db->get('unidad_medida')->result_array();
        
        $this->db->flush_cache();
        
        echo json_encode([
            'status' => 'success',
            'data' => $unidades,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ]);
    }

    // POST /unidades/guardar
    public function guardar() {
        $input_data = json_decode(file_get_contents('php://input'), true);
        if (!$input_data) {
            echo json_encode(['status' => 'error', 'message' => 'No data provided']);
            return;
        }

        $idunidad = isset($input_data['idunidad']) ? intval($input_data['idunidad']) : null;
        $descripcion = trim($input_data['descripcion'] ?? '');

        if (empty($descripcion)) {
            echo json_encode(['status' => 'error', 'message' => 'La descripción es obligatoria.']);
            return;
        }

        // Check duplicates
        $this->db->where('descripcion', $descripcion);
        if ($idunidad) {
            $this->db->where('idunidad !=', $idunidad);
        }
        if ($this->db->get('unidad_medida')->num_rows() > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Ya existe una unidad de medida con esa descripción.']);
            return;
        }

        $data = [
            'descripcion' => $descripcion,
            'estado' => isset($input_data['estado']) ? $input_data['estado'] : 'Activo'
        ];

        if ($idunidad) {
            $this->db->where('idunidad', $idunidad);
            $this->db->update('unidad_medida', $data);
            echo json_encode(['status' => 'success', 'message' => 'Unidad actualizada correctamente', 'idunidad' => $idunidad]);
        } else {
            $this->db->insert('unidad_medida', $data);
            $new_id = $this->db->insert_id();
            echo json_encode(['status' => 'success', 'message' => 'Unidad creada correctamente', 'idunidad' => $new_id]);
        }
    }

    // GET /unidades/inactivar/:id
    public function inactivar($id) {
        $this->db->where('idunidad', $id);
        $this->db->update('unidad_medida', ['estado' => 'Inactivo']);
        echo json_encode(['status' => 'success', 'message' => 'Unidad inactivada']);
    }

    // GET /unidades/reactivar/:id
    public function reactivar($id) {
        $this->db->where('idunidad', $id);
        $this->db->update('unidad_medida', ['estado' => 'Activo']);
        echo json_encode(['status' => 'success', 'message' => 'Unidad reactivada']);
    }

    // DELETE /unidades/eliminar/:id
    public function eliminar($id) {
        $this->db->where('idunidad', $id);
        $this->db->delete('unidad_medida');
        echo json_encode(['status' => 'success', 'message' => 'Unidad eliminada']);
    }
}
