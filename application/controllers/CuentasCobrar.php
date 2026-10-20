<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Controlador de Cuentas por Cobrar.
 * Sigue los estándares PSR-12 y control de permisos mediante MY_Controller.
 */
class CuentasCobrar extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->check_table();
    }

    /**
     * Asegura la existencia de la tabla de pagos de ventas.
     */
    private function check_table()
    {
        $sql = "CREATE TABLE IF NOT EXISTS pagos_ventas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            idventa VARCHAR(50) NOT NULL,
            monto DECIMAL(10,2) NOT NULL,
            fecha_pago DATETIME DEFAULT CURRENT_TIMESTAMP,
            metodo_pago VARCHAR(50),
            usuario_id INT,
            comentario TEXT
        )";
        $this->db->query($sql);
    }

    /**
     * Lista las ventas a crédito según el estado solicitado.
     * Parámetro GET "estado": pendiente (por defecto) | pagado | todos.
     * Las cuentas "pagadas" son ventas con saldo 0 que registran abonos en pagos_ventas.
     */
    public function lista()
    {
        $this->check_permission('Cuentas por Cobrar', 'ver');

        // Validar el estado recibido contra una lista blanca
        $estado = strtolower(trim((string) $this->input->get('estado', true)));
        if (!in_array($estado, ['pendiente', 'pagado', 'todos'], true)) {
            $estado = 'pendiente';
        }

        $this->db->select('v.idventa, v.id AS nro_venta, v.fecha, v.cliente, v.nit, v.telefono, v.vendedor, v.total, v.pago, v.saldo, v.comentario, d.nombre AS sucursal, d.direccion AS sucursal_direccion, d.telefonos AS sucursal_telefonos, d.tipo_reporte AS sucursal_tipo_reporte');
        $this->db->from('ventas v');
        $this->db->join('depositos d', 'v.idneg = d.id', 'left');

        if ($estado === 'pendiente') {
            $this->db->where('v.saldo >', 0);
        } elseif ($estado === 'pagado') {
            $this->db->where('v.saldo <=', 0);
            $this->db->where('v.idventa IN (SELECT DISTINCT pv.idventa FROM pagos_ventas pv)', null, false);
        } else {
            // Todos: pendientes + pagadas que tuvieron abonos a crédito
            $this->db->group_start();
            $this->db->where('v.saldo >', 0);
            $this->db->or_where('v.idventa IN (SELECT DISTINCT pv.idventa FROM pagos_ventas pv)', null, false);
            $this->db->group_end();
        }

        $this->db->order_by('v.fecha', 'DESC');
        $query = $this->db->get();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode($query->result()));
    }

    /**
     * Registra un nuevo abono/pago sobre una venta a crédito.
     */
    public function registrar_pago()
    {
        $this->check_permission('Cuentas por Cobrar', 'crear');

        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['idventa']) || empty($data['monto'])) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'Datos incompletos']));
        }

        $monto = floatval($data['monto']);
        if ($monto <= 0) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El monto debe ser mayor a cero']));
        }

        $venta = $this->db->where('idventa', $data['idventa'])->get('ventas')->row();
        if (!$venta) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(404)
                ->set_output(json_encode(['error' => 'Venta no encontrada']));
        }

        if ($monto > floatval($venta->saldo)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El abono no puede superar el saldo actual']));
        }

        $headerUserId = $this->input->get_request_header('X-User-Id', true);
        $userId = !empty($headerUserId) ? intval($headerUserId) : (!empty($data['usuario_id']) ? intval($data['usuario_id']) : 1);

        $this->db->trans_start();
        $this->db->insert('pagos_ventas', [
            'idventa' => $data['idventa'],
            'monto' => $monto,
            'metodo_pago' => $data['metodo_pago'] ?? 'Efectivo',
            'usuario_id' => $userId,
            'comentario' => $data['comentario'] ?? ''
        ]);
        $pago_id = $this->db->insert_id();

        $nuevo_pago = floatval($venta->pago) + $monto;
        $nuevo_saldo = floatval($venta->saldo) - $monto;
        $this->db->where('idventa', $data['idventa'])->update('ventas', [
            'pago' => $nuevo_pago,
            'saldo' => $nuevo_saldo
        ]);
        $this->db->trans_complete();

        if ($this->db->trans_status() === false) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(500)
                ->set_output(json_encode(['error' => 'Error al procesar pago']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'message' => 'Pago registrado exitosamente',
                'pago_id' => $pago_id
            ]));
    }

    /**
     * Consulta el historial de pagos asociados a una venta.
     */
    public function historial($idventa)
    {
        $this->check_permission('Cuentas por Cobrar', 'ver');

        if (empty($idventa)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de venta requerido']));
        }

        $this->db->select("pv.*, COALESCE(NULLIF(TRIM(u.nombre), ''), CONCAT('Usuario #', pv.usuario_id), 'No registrado') AS usuario_nombre", false);
        $this->db->from('pagos_ventas pv');
        $this->db->join('vendedores u', 'pv.usuario_id = u.id', 'left');
        $this->db->where('pv.idventa', $idventa);
        $this->db->order_by('pv.fecha_pago', 'DESC');
        $query = $this->db->get();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode($query->result()));
    }
}
