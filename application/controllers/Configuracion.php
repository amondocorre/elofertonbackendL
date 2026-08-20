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

    private function check_and_migrate_columns() {
        if (!$this->db->field_exists('metodo_qrbcp', 'configapp')) {
            $this->db->query("ALTER TABLE configapp ADD COLUMN metodo_qrbcp INT DEFAULT 1");
        }
        if (!$this->db->field_exists('pos_metodo_qrbcp', 'configapp')) {
            $this->db->query("ALTER TABLE configapp ADD COLUMN pos_metodo_qrbcp INT DEFAULT 1");
        }
        if (!$this->db->field_exists('pos_metodo_qrmercantil', 'configapp')) {
            $this->db->query("ALTER TABLE configapp ADD COLUMN pos_metodo_qrmercantil INT DEFAULT 1");
        }
        if (!$this->db->field_exists('pos_metodo_cheque', 'configapp')) {
            $this->db->query("ALTER TABLE configapp ADD COLUMN pos_metodo_cheque INT DEFAULT 1");
        }
        if (!$this->db->field_exists('pos_metodo_deposito', 'configapp')) {
            $this->db->query("ALTER TABLE configapp ADD COLUMN pos_metodo_deposito INT DEFAULT 1");
        }
        if (!$this->db->field_exists('dias_proforma', 'configapp')) {
            $this->db->query("ALTER TABLE configapp ADD COLUMN dias_proforma INT DEFAULT 1");
        }
        if (!$this->db->table_exists('metodos_pago_custom')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `metodos_pago_custom` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `nombre` VARCHAR(100) NOT NULL,
                `descripcion` VARCHAR(255) DEFAULT NULL,
                `tipo` ENUM('banco','pasarela','qr','efectivo','otro') NOT NULL DEFAULT 'banco',
                `pos_metodo` INT(11) DEFAULT 1,
                `web_metodo` INT(11) DEFAULT 0,
                `activo` INT(11) DEFAULT 1,
                `configuracion_json` TEXT DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;");
        }
    }

    public function migrate_proforma() {
        $this->check_and_migrate_columns();
        echo "Migration complete";
    }

    // GET /configuracion/get_config
    public function get_config() {
        $this->check_and_migrate_columns();
        $config_db = $this->db->get('configapp')->row_array();
        echo json_encode(['status' => 'success', 'data' => $config_db]);
    }

    // POST /configuracion/save_config
    public function save_config() {
        $this->check_and_migrate_columns();
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
        $pos_fields = ['pos_metodo_efectivo', 'pos_metodo_tarjeta', 'pos_metodo_transferencia', 'pos_metodo_qrbisa', 'pos_metodo_qrbcp', 'pos_metodo_qrmercantil', 'pos_metodo_cheque', 'pos_metodo_deposito', 'pos_metodo_mixto'];
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

    // GET /configuracion/get_metodos_custom
    public function get_metodos_custom() {
        $this->check_and_migrate_columns();
        $methods = $this->db->order_by('id', 'ASC')->get('metodos_pago_custom')->result_array();
        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'data' => $methods]));
    }

    // POST /configuracion/save_metodo_custom
    public function save_metodo_custom() {
        $this->check_and_migrate_columns();
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['nombre'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'error', 'message' => 'El nombre del método de pago es requerido.']));
        }

        $data = [
            'nombre'        => trim($input['nombre']),
            'descripcion'   => trim($input['descripcion'] ?? ''),
            'icono'         => trim($input['icono'] ?? '📲'),
            'activo'        => isset($input['activo']) ? intval($input['activo']) : 1,
            'permite_mixto' => isset($input['permite_mixto']) ? intval($input['permite_mixto']) : 1,
        ];

        if (!empty($input['id'])) {
            $this->db->where('id', intval($input['id']));
            $this->db->update('metodos_pago_custom', $data);
            $id = intval($input['id']);
        } else {
            $this->db->insert('metodos_pago_custom', $data);
            $id = $this->db->insert_id();
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'message' => 'Método de pago personalizado guardado.', 'id' => $id]));
    }

    // POST /configuracion/toggle_metodo_custom
    public function toggle_metodo_custom() {
        $this->check_and_migrate_columns();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            return $this->output->set_status_header(400)->set_output(json_encode(['status' => 'error', 'message' => 'ID inválido.']));
        }
        $method = $this->db->get_where('metodos_pago_custom', ['id' => $id])->row_array();
        if (!$method) {
            return $this->output->set_status_header(404)->set_output(json_encode(['status' => 'error', 'message' => 'Método de pago no encontrado.']));
        }
        $nuevo_estado = $method['activo'] == 1 ? 0 : 1;
        $this->db->where('id', $id)->update('metodos_pago_custom', ['activo' => $nuevo_estado]);

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'activo' => $nuevo_estado]));
    }

    // POST /configuracion/delete_metodo_custom
    public function delete_metodo_custom() {
        $this->check_and_migrate_columns();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            return $this->output->set_status_header(400)->set_output(json_encode(['status' => 'error', 'message' => 'ID inválido.']));
        }
        $this->db->where('id', $id)->delete('metodos_pago_custom');

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'message' => 'Método de pago eliminado.']));
    }
}
