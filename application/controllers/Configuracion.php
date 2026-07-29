<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Configuracion extends CI_Controller {

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

    public function migrate_proforma() {
        $this->db->query("ALTER TABLE configapp ADD COLUMN dias_proforma INT DEFAULT 1");
        echo "Migration complete";
    }

    // GET /configuracion/get_config
    public function get_config() {
        if (!$this->db->field_exists('metodo_qrbcp', 'configapp')) {
            $this->db->query("ALTER TABLE configapp ADD COLUMN metodo_qrbcp INT DEFAULT 1");
        }
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
            'metodo_qrbcp' => isset($input_data['metodo_qrbcp']) ? (int)$input_data['metodo_qrbcp'] : 1,
        ];

        // POS payment data
        $pos_fields = ['pos_metodo_efectivo', 'pos_metodo_tarjeta', 'pos_metodo_transferencia', 'pos_metodo_qrbisa', 'pos_metodo_mixto'];
        foreach ($pos_fields as $field) {
            if (isset($input_data[$field])) {
                $data[$field] = (int)$input_data[$field];
            }
        }
        
        if (isset($input_data['dias_proforma'])) {
            $data['dias_proforma'] = (int)$input_data['dias_proforma'];
        }

        // Importadora data
        if (isset($input_data['razon_social'])) $data['razon_social'] = $input_data['razon_social'];
        if (isset($input_data['nit'])) $data['nit'] = $input_data['nit'];
        if (isset($input_data['correo'])) $data['correo'] = $input_data['correo'];
        if (isset($input_data['telefono'])) $data['telefono'] = $input_data['telefono'];
        if (isset($input_data['direccion'])) $data['direccion'] = $input_data['direccion'];

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
