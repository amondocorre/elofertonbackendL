<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Configuracion extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->database();
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            die();
        }
    }

    // GET /configuracion/get_config
    public function get_config() {
        $config_db = $this->db->get('configapp')->row_array();
        echo json_encode(['status' => 'success', 'data' => $config_db]);
    }

    // POST /configuracion/save_config
    public function save_config() {
        $input_data = json_decode(file_get_contents('php://input'), true);
        if (!$input_data) {
            echo json_encode(['status' => 'error', 'message' => 'No data provided']);
            return;
        }

        $data = [
            'metodo_transferencia' => isset($input_data['metodo_transferencia']) ? (int)$input_data['metodo_transferencia'] : 1,
            'metodo_qrbisa' => isset($input_data['metodo_qrbisa']) ? (int)$input_data['metodo_qrbisa'] : 1,
            'metodo_qrmercantil' => isset($input_data['metodo_qrmercantil']) ? (int)$input_data['metodo_qrmercantil'] : 1,
        ];

        // Ensure configapp has id 1
        $exists = $this->db->where('id', 1)->get('configapp')->num_rows();
        if ($exists > 0) {
            $this->db->where('id', 1);
            $this->db->update('configapp', $data);
        } else {
            $data['id'] = 1;
            $this->db->insert('configapp', $data);
        }

        echo json_encode(['status' => 'success', 'message' => 'Configuración actualizada exitosamente']);
    }
}
