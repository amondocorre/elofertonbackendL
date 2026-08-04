<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Controlador para la Conciliación de Compras e Inventarios en 3 Pasos (3-Way Matching).
 * Cumple con los estándares PSR-12 y las directrices globales del proyecto.
 */
class Conciliacion extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        
        // Habilitar CORS
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization, X-User-Id, X-Rol-Id, X-Active-Branch');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        header('Access-Control-Max-Age: 86400');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit(0);
        }
        
        $this->load->model('Conciliacion_model');
    }

    /**
     * Paso A: Registra un nuevo pedido en estado 'Pendiente' (Supervisor).
     */
    public function guardar_pedido()
    {
        $this->check_permission('Conciliación', 'crear');
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['proveedor_id']) || empty($data['supervisor_id']) || empty($data['almacen_id']) || empty($data['fecha_pedido']) || empty($data['detalles'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Todos los campos del pedido (proveedor, supervisor, almacén, fecha y detalles de productos) son requeridos.']));
        }

        $pedidoId = $this->Conciliacion_model->guardar_pedido($data, $data['detalles']);

        if (!$pedidoId) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error interno al registrar el pedido de compra.']));
        }

        return $this->output
            ->set_status_header(201)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'message' => 'Pedido registrado con éxito en estado Pendiente.',
                'pedido_id' => $pedidoId
            ]));
    }

    /**
     * Paso A (Edición): Edita un pedido en estado 'Pendiente'.
     */
    public function editar_pedido()
    {
        $this->check_permission('Conciliación', 'editar');
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['pedido_id']) || empty($data['proveedor_id']) || empty($data['almacen_id']) || empty($data['detalles'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Datos insuficientes para editar el pedido.']));
        }

        $pedidoId = intval($data['pedido_id']);

        // Verificar el estado del pedido actual
        $pedidoObj = $this->Conciliacion_model->get_pedido_by_id($pedidoId);
        if (!$pedidoObj) {
            return $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El pedido especificado no existe.']));
        }

        if ($pedidoObj['pedido']['estado'] !== 'Pendiente') {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Sólo se pueden editar pedidos que estén en estado Pendiente.']));
        }

        $success = $this->Conciliacion_model->editar_pedido($pedidoId, $data, $data['detalles']);

        if (!$success) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al guardar los cambios del pedido.']));
        }

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'message' => 'Pedido actualizado con éxito.'
            ]));
    }

    /**
     * Paso B: Recepción física de mercancía (Almacenero).
     * Aplica la regla crítica de validación de permisos de almacén (Error 403 si falla).
     */
    public function recibir_pedido()
    {
        $this->check_permission('Conciliación', 'editar');
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['pedido_id']) || empty($data['almacenero_id']) || empty($data['items'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'ID de pedido, almacenero y lista de ítems recibidos son obligatorios.']));
        }

        $pedidoId = intval($data['pedido_id']);
        $almaceneroId = intval($data['almacenero_id']);

        // Obtener datos del pedido para extraer el almacen_id
        $pedidoObj = $this->Conciliacion_model->get_pedido_by_id($pedidoId);
        if (!$pedidoObj) {
            return $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El pedido especificado no existe.']));
        }

        $almacenId = intval($pedidoObj['pedido']['almacen_id']);

        // REGLA CRÍTICA: Validar si el almacenero tiene acceso al almacén
        if (!$this->Conciliacion_model->has_warehouse_permission($almaceneroId, $almacenId)) {
            return $this->output
                ->set_status_header(403)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'Acceso denegado (403). No tienes permisos asignados para gestionar operaciones en este almacén.'
                ]));
        }

        // Ejecutar recepción física y actualización de stock con observaciones
        $observacionPedido = isset($data['observacion_pedido']) ? $data['observacion_pedido'] : null;
        $success = $this->Conciliacion_model->recibir_pedido($pedidoId, $data['items'], $almacenId, $observacionPedido);

        if (!$success) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al registrar la recepción y actualizar stock.']));
        }

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'message' => 'Recepción física registrada y stock actualizado con éxito en el almacén.'
            ]));
    }

    /**
     * Sube un archivo de factura (PDF o imagen) y retorna su nombre en el servidor.
     */
    public function subir_factura()
    {
        $this->check_permission('Conciliación', 'crear');

        if (isset($_FILES['factura_file']) && !empty($_FILES['factura_file']['name'])) {
            $upload_path = FCPATH . 'uploads/facturas/';
            if (!is_dir($upload_path)) {
                mkdir($upload_path, 0777, true);
            }
            
            $config['upload_path'] = $upload_path;
            $config['allowed_types'] = 'gif|jpg|jpeg|png|webp|pdf|doc|docx|xls|xlsx|txt|csv';
            $config['max_size'] = 10240; // 10MB
            $config['file_name'] = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['factura_file']['name']);

            $this->load->library('upload', $config);

            if ($this->upload->do_upload('factura_file')) {
                $uploadData = $this->upload->data();
                return $this->output
                    ->set_content_type('application/json')
                    ->set_status_header(200)
                    ->set_output(json_encode([
                        'message' => 'Archivo subido con éxito.',
                        'file_name' => $uploadData['file_name']
                    ]));
            } else {
                return $this->output
                    ->set_content_type('application/json')
                    ->set_status_header(400)
                    ->set_output(json_encode(['error' => strip_tags($this->upload->display_errors())]));
            }
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(400)
            ->set_output(json_encode(['error' => 'No se ha detectado ningún archivo para subir.']));
    }

    /**
     * Paso C: Validación de Factura (Conciliación / 3-Way Matching).
     */
    public function facturar_pedido()
    {
        $this->check_permission('Conciliación', 'crear');
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['pedido_id']) || empty($data['nro_comprobante']) || empty($data['tipo_pago']) || !isset($data['monto_total']) || empty($data['fecha_factura']) || empty($data['items'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'ID de pedido, nro comprobante, tipo pago, monto total, fecha e ítems facturados son obligatorios.']));
        }

        $pedidoId = intval($data['pedido_id']);
        $pedidoObj = $this->Conciliacion_model->get_pedido_by_id($pedidoId);
        if (!$pedidoObj) {
            return $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El pedido especificado no existe.']));
        }

        $warehouseId = intval($pedidoObj['pedido']['almacen_id'] ?? 0);
        $invoiceDate = $data['fecha_factura'];

        // Se deshabilita la validación contra el último cierre de caja/inventario para permitir registrar compras en cualquier fecha.
        /*
        // Validar que la fecha de la factura no sea anterior o igual a la fecha de bloqueo (último cierre)
        if ($warehouseId > 0) {
            $this->db->where('deposito', $warehouseId);
            $this->db->order_by('fecha', 'DESC');
            $lastClosure = $this->db->get('cierres', 1)->row();
            if ($lastClosure) {
                $lockDate = substr($lastClosure->fecha, 0, 10);
                if ($invoiceDate <= $lockDate) {
                    return $this->output
                        ->set_status_header(400)
                        ->set_content_type('application/json')
                        ->set_output(json_encode([
                            'error' => 'La fecha de la factura (' . $invoiceDate . ') no puede ser anterior o igual al último cierre de caja/inventario (' . $lockDate . ') de este almacén.'
                        ]));
                }
            }
        }
        */

        // Validar que la fecha de vencimiento sea válida si es a crédito
        if ($data['tipo_pago'] === 'A Crédito') {
            $dueDate = $data['fecha_vencimiento'] ?? null;
            if (empty($dueDate)) {
                return $this->output
                    ->set_status_header(400)
                    ->set_content_type('application/json')
                    ->set_output(json_encode(['error' => 'La fecha de vencimiento es requerida para compras a crédito.']));
            }
            if ($dueDate < $invoiceDate) {
                return $this->output
                    ->set_status_header(400)
                    ->set_content_type('application/json')
                    ->set_output(json_encode(['error' => 'La fecha de vencimiento del crédito (' . $dueDate . ') no puede ser anterior a la fecha de la factura (' . $invoiceDate . ').']));
            }
        }

        $fechaVencimiento = $data['fecha_vencimiento'] ?? null;
        $facturaId = $this->Conciliacion_model->facturar_pedido($data, $data['items'], $fechaVencimiento);

        if (!$facturaId) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al registrar la factura comercial.']));
        }

        // Obtener el pedido actualizado para retornar discrepancias finales
        $pedidoActualizado = $this->Conciliacion_model->get_pedido_by_id($pedidoId);

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'message' => 'Factura registrada con éxito. Pedido liquidado.',
                'factura_id' => $facturaId,
                'resultado_conciliacion' => $pedidoActualizado['detalles']
            ]));
    }

    /**
     * Obtiene el listado de pedidos de conciliación.
     */
    public function pedidos()
    {
        $this->check_permission('Conciliación', 'ver');
        $filters = [
            'status' => $this->input->get('status'),
            'proveedor_id' => $this->input->get('proveedor_id'),
            'almacen_id' => $this->input->get('almacen_id')
        ];

        $pedidos = $this->Conciliacion_model->get_pedidos($filters);

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode($pedidos));
    }

    /**
     * Obtiene el detalle de un pedido incluyendo diferencias de recepción y facturación.
     */
    public function pedido($id = null)
    {
        $this->check_permission('Conciliación', 'ver');
        if (empty($id)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'ID de pedido no proporcionado.']));
        }

        $pedido = $this->Conciliacion_model->get_pedido_by_id($id);

        if (!$pedido) {
            return $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Pedido no encontrado.']));
        }

        // Obtener la fecha del último cierre para el almacén (depósito) del pedido
        $warehouseId = intval($pedido['pedido']['almacen_id'] ?? 0);
        $lockDate = null;
        if ($warehouseId > 0) {
            $this->db->where('deposito', $warehouseId);
            $this->db->order_by('fecha', 'DESC');
            $lastClosure = $this->db->get('cierres', 1)->row();
            if ($lastClosure) {
                // Solo nos interesa la fecha YYYY-MM-DD
                $lockDate = substr($lastClosure->fecha, 0, 10);
            }
        }
        $pedido['fecha_bloqueo'] = $lockDate;

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode($pedido));
    }

    /**
     * Gestión de Almacenes (Listado y creación).
     */
    public function almacenes()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->check_permission('Conciliación', 'ver');
            $this->db->where('estado', 'activo');
            $this->db->order_by('nombre', 'ASC');
            $query = $this->db->get('depositos');
            
            $results = [];
            foreach ($query->result_array() as $row) {
                $results[] = [
                    'id' => $row['id'],
                    'nombre_almacen' => $row['nombre'],
                    'ubicacion' => $row['direccion'],
                    'estado' => $row['estado']
                ];
            }
            
            return $this->output
                ->set_status_header(200)
                ->set_content_type('application/json')
                ->set_output(json_encode($results));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->check_permission('Conciliación', 'eliminar');
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['nombre_almacen'])) {
                return $this->output
                    ->set_status_header(400)
                    ->set_content_type('application/json')
                    ->set_output(json_encode(['error' => 'El nombre del almacén es obligatorio.']));
            }

            $almacenData = [
                'nombre' => trim($data['nombre_almacen']),
                'direccion' => $data['ubicacion'] ?? '',
                'estado' => 'activo',
                'lat' => '0',
                'lng' => '0',
                'zoom' => 0
            ];

            if ($this->db->insert('depositos', $almacenData)) {
                return $this->output
                    ->set_status_header(201)
                    ->set_content_type('application/json')
                    ->set_output(json_encode([
                        'message' => 'Almacén registrado con éxito en depositos.',
                        'almacen_id' => $this->db->insert_id()
                    ]));
            }
        }
    }

    /**
     * Asigna un almacén a un usuario (almacenero).
     */
    public function asignar_almacen()
    {
        $this->check_permission('Conciliación', 'eliminar');
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['usuario_id']) || empty($data['almacen_id'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'usuario_id y almacen_id son requeridos.']));
        }

        $pivotData = [
            'usuario_id' => intval($data['usuario_id']),
            'almacen_id' => intval($data['almacen_id'])
        ];

        // Insertar ignorando duplicados
        $this->db->db_debug = false; // Desactivar errores fatales de BD por llaves duplicadas
        $inserted = $this->db->insert('usuarios_almacenes', $pivotData);
        $this->db->db_debug = true;

        if ($inserted) {
            return $this->output
                ->set_status_header(201)
                ->set_content_type('application/json')
                ->set_output(json_encode(['message' => 'Permiso de almacén asignado correctamente.']));
        } else {
            return $this->output
                ->set_status_header(409)
                ->set_content_type('application/json')
                ->set_output(json_encode(['message' => 'La asignación ya existe o el almacén/usuario es inválido.']));
        }
    }

    /**
     * Cuentas por Cobrar/Pagar (Listado de créditos pendientes).
     */
    public function cuentas_por_pagar()
    {
        $this->check_permission('Conciliación', 'ver');
        $this->db->select('cpp.*, cf.nro_comprobante, cf.monto_total, cf.fecha_factura, p.id as pedido_id, pr.nombre as proveedor_nombre');
        $this->db->from('cuentas_por_pagar cpp');
        $this->db->join('compras_facturas cf', 'cpp.factura_id = cf.id', 'left');
        $this->db->join('pedidos p', 'cf.pedido_id = p.id', 'left');
        $this->db->join('proveedores pr', 'p.proveedor_id = pr.id', 'left');
        
        $query = $this->db->get();
        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode($query->result_array()));
    }

    /**
     * Registra un pago o abono parcial a una cuenta por pagar.
     */
    public function registrar_pago()
    {
        $this->check_permission('Conciliación', 'eliminar');
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['cuenta_id']) || empty($data['monto_pagado'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'ID de cuenta y monto del pago son requeridos.']));
        }

        $cuentaId = intval($data['cuenta_id']);
        $montoPagado = floatval($data['monto_pagado']);

        if ($montoPagado <= 0) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El monto del pago debe ser un número positivo mayor a cero.']));
        }

        // Consultar la cuenta por pagar existente
        $cuenta = $this->db->get_where('cuentas_por_pagar', ['id' => $cuentaId])->row_array();
        if (!$cuenta) {
            return $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'La cuenta por pagar especificada no existe.']));
        }

        if (floatval($cuenta['saldo_pendiente']) <= 0) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Esta cuenta por pagar ya ha sido cancelada en su totalidad.']));
        }

        // Verificar que el pago no sea mayor que el saldo pendiente
        if ($montoPagado > floatval($cuenta['saldo_pendiente'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'El monto ingresado (' . $montoPagado . ') excede el saldo pendiente actual (' . $cuenta['saldo_pendiente'] . ').'
                ]));
        }

        // Ejecutar inserción y actualización
        $pagoId = $this->Conciliacion_model->registrar_pago_cuota($data);

        if (!$pagoId) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error interno al registrar el abono del pago.']));
        }

        // Obtener datos actualizados de la cuenta por pagar
        $cuentaActualizada = $this->db->get_where('cuentas_por_pagar', ['id' => $cuentaId])->row_array();

        return $this->output
            ->set_status_header(201)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'message' => 'Abono registrado con éxito.',
                'pago_id' => $pagoId,
                'saldo_pendiente' => floatval($cuentaActualizada['saldo_pendiente']),
                'estado' => $cuentaActualizada['estado']
            ]));
    }

    /**
     * Obtiene el historial de abonos de una cuenta por pagar específica.
     */
    public function historial_pagos()
    {
        $this->check_permission('Conciliación', 'ver');
        $cuentaId = $this->input->get('cuenta_id');
        if (empty($cuentaId)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El parámetro cuenta_id es obligatorio.']));
        }

        $pagos = $this->Conciliacion_model->get_pagos_by_cuenta($cuentaId);

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode($pagos));
    }

    /**
     * Obtiene el stock actual de los productos en un almacén específico.
     */
    public function stock_almacen()
    {
        $this->check_permission('Conciliación', 'ver');
        $almacenId = $this->input->get('almacen_id');
        if (empty($almacenId)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El almacen_id es requerido.']));
        }

        $this->db->select('p.id as producto_id, SUM(i.cantidad) as stock');
        $this->db->from('inventarios i');
        $this->db->join('productos p', 'p.idprod = i.idprod', 'left');
        $this->db->where('i.deposito', intval($almacenId));
        $this->db->where('i.cantidad >', 0);
        $this->db->group_by('p.id');
        $query = $this->db->get();

        $stockMap = [];
        foreach ($query->result_array() as $row) {
            if (!empty($row['producto_id'])) {
                $stockMap[$row['producto_id']] = floatval($row['stock']);
            }
        }

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode($stockMap));
    }

    /**
     * Registra un traspaso interno de stock entre almacenes.
     */
    public function transferir_mercaderia()
    {
        $this->check_permission('Conciliación', 'editar');
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['almacen_origen_id']) || empty($data['almacen_destino_id']) || empty($data['usuario_id']) || empty($data['items'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Almacén origen, destino, usuario e ítems a transferir son obligatorios.']));
        }

        $origenId = intval($data['almacen_origen_id']);
        $destinoId = intval($data['almacen_destino_id']);
        $usuarioId = intval($data['usuario_id']);

        if ($origenId === $destinoId) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El almacén de origen y destino no pueden ser iguales.']));
        }

        // VALIDAR PERMISO: El usuario en sesión debe tener acceso al almacén origen
        if (!$this->Conciliacion_model->has_warehouse_permission($usuarioId, $origenId)) {
            return $this->output
                ->set_status_header(403)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Acceso denegado (403). No tienes permisos sobre el almacén de origen.']));
        }

        $success = $this->Conciliacion_model->registrar_transferencia($origenId, $destinoId, $usuarioId, $data['items']);

        if (!$success) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error interno al registrar el traspaso de mercancía.']));
        }

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode(['message' => 'Traspaso de mercancía completado con éxito.']));
    }

    /**
     * Busca productos por una lista de códigos (idprod)
     * POST /conciliacion/buscar_por_codigos
     */
    public function buscar_por_codigos()
    {
        $this->check_permission('Conciliación', 'crear');
        $data = json_decode(file_get_contents('php://input'), true);
        $codigos = isset($data['codigos']) ? $data['codigos'] : [];

        if (empty($codigos)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'No se proporcionaron códigos.']));
        }

        // Sanitizar y limpiar códigos
        $codigos_clean = array_map(function($c) {
            return trim($c);
        }, $codigos);
        $codigos_clean = array_filter($codigos_clean);

        if (empty($codigos_clean)) {
            return $this->output
                ->set_status_header(200)
                ->set_content_type('application/json')
                ->set_output(json_encode([]));
        }

        $this->db->select('id, idprod, descripcion, preciolocal');
        $this->db->from('productos');
        $this->db->where_in('idprod', $codigos_clean);
        $productos = $this->db->get()->result_array();

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode($productos));
    }
}
