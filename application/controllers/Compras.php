<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Compras extends MY_Controller {

    public function __construct() {
        parent::__construct();
        // Habilitar CORS
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-User-Id, X-Rol-Id, X-Active-Branch');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit();
        }
        
        $this->load->database();
    }

    /**
     * Obtiene el listado de todas las compras con paginación, filtros y búsqueda.
     */
    public function index() {
        $this->check_permission('Compras', 'ver');
        $search = $this->input->get('q');
        $proveedor = $this->input->get('proveedor');
        $page = $this->input->get('page') ? intval($this->input->get('page')) : 1;
        $limit = $this->input->get('limit') ? intval($this->input->get('limit')) : 25;
        $fecha_inicio = $this->input->get('fecha_inicio');
        $fecha_fin = $this->input->get('fecha_fin');
        $formapago = $this->input->get('formapago');

        $this->db->select('compras.*, vendedores.email as comprador_email');
        $this->db->from('compras');
        $this->db->join('vendedores', 'compras.idusr = vendedores.id', 'left');

        if (!empty($search)) {
            $search_escaped = $this->db->escape_like_str(trim($search));
            $this->db->group_start();
            $this->db->like('compras.proveedor', $search_escaped);
            $this->db->or_like('compras.idcompra', $search_escaped);
            $this->db->or_like('compras.factura', $search_escaped);
            $this->db->group_end();
        }

        if (!empty($proveedor)) {
            $this->db->where('compras.proveedor', $proveedor);
        }

        if (!empty($fecha_inicio)) {
            $this->db->where('DATE(compras.fecha) >=', $fecha_inicio);
        }

        if (!empty($fecha_fin)) {
            $this->db->where('DATE(compras.fecha) <=', $fecha_fin);
        }

        if (!empty($formapago)) {
            $this->db->where('compras.formapago', $formapago);
        }

        // Clonar la consulta para contar total
        $count_db = clone $this->db;
        $total_records = $count_db->count_all_results();

        $this->db->order_by('compras.fecha', 'DESC');
        $offset = ($page - 1) * $limit;
        $this->db->limit($limit, $offset);
        $compras = $this->db->get()->result();

        $response = [
            'data' => $compras,
            'total' => $total_records,
            'page' => $page,
            'pages' => ceil($total_records / $limit),
            'limit' => $limit
        ];

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode($response));
    }

    /**
     * Obtiene los detalles de una compra específica.
     */
    public function detalle($idcompra = null) {
        if (!$idcompra) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de compra no proporcionado.']));
        }

        $compra = $this->db->where('idcompra', $idcompra)->get('compras')->row();
        if (!$compra) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(404)
                ->set_output(json_encode(['error' => 'Compra no encontrada.']));
        }

        $detalle = $this->db->where('idcompra', $idcompra)->get('detallecompras')->result();
        $pagos = $this->db->where('idcompra', $idcompra)->get('pagos')->result();

        $response = [
            'compra' => $compra,
            'detalle' => $detalle,
            'pagos' => $pagos
        ];

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode($response));
    }

    /**
     * Registra una nueva compra en la base de datos, incrementando stock y precios de inventario.
     */
    public function guardar() {
        $this->check_permission('Compras', 'crear');
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'Datos de compra vacíos.']));
        }

        $idusr = isset($data['idusr']) ? intval($data['idusr']) : 1;
        $proveedor = isset($data['proveedor']) ? trim($data['proveedor']) : 'General';
        $nit = isset($data['nit']) ? trim($data['nit']) : '';
        $formapago = isset($data['formapago']) ? trim($data['formapago']) : 'efectivo';
        $factura = isset($data['factura']) ? trim($data['factura']) : '';
        $comentario = isset($data['comentario']) ? trim($data['comentario']) : '';
        $total = isset($data['total']) ? floatval($data['total']) : 0.0;
        $deposito_destino = isset($data['deposito']) ? intval($data['deposito']) : 1;
        $detalle = isset($data['detalle']) ? $data['detalle'] : [];

        // Datos del pago a crédito (opcional)
        $montoInicial = isset($data['monto_inicial']) ? floatval($data['monto_inicial']) : 0.0;

        if (empty($detalle)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El detalle de la compra no puede estar vacío.']));
        }

        $idcompra = uniqid('cmp_');
        $hoy = date('Y-m-d H:i:s');

        $this->db->trans_start();

        // 1. Insertar en tabla compras
        $compraData = [
            'idneg' => 1,
            'idcompra' => $idcompra,
            'total' => $total,
            'proveedor' => $proveedor,
            'nit' => $nit,
            'formapago' => $formapago,
            'fecha' => $hoy,
            'comentario' => $comentario,
            'comprador' => $idusr,
            'idusr' => $idusr,
            'factura' => $factura
        ];
        $this->db->insert('compras', $compraData);

        // 2. Procesar detalle de la compra (Control de inventario por lotes)
        foreach ($detalle as $item) {
            $preciolocal = floatval($item['preciolocal']); // Costo de compra
            $precioventa = floatval($item['precioventa']); // Nuevo precio de venta
            $comision = floatval($item['comision']); // Nueva comisión
            $cuantos = floatval($item['cantidad']); // Cantidad comprada

            // Buscar prototipo en inventarios para copiar características descriptivas
            $this->db->where('idprod', $item['idprod']);
            $proto = $this->db->get('inventarios')->row();

            // Buscar si en la tabla productos existe para actualizar el costo y precio maestro
            $prodMaster = $this->db->where('idprod', $item['idprod'])->get('productos')->row();
            $prodIdMaster = $prodMaster ? $prodMaster->id : 0;
            
            $precioMayorista = $precioventa; // Por defecto
            if ($prodMaster) {
                // Actualizar precios maestros en la tabla productos
                $this->db->where('id', $prodMaster->id);
                $this->db->update('productos', [
                    'preciolocal' => $preciolocal,
                    'precioventa' => $precioventa
                ]);
                $precioMayorista = $prodMaster->nuevoprecio ?? $precioventa;
            }

            // Insertar siempre un nuevo registro (lote) en inventarios
            $newInvData = [
                'idprod' => $item['idprod'],
                'descripcion' => $proto ? $proto->descripcion : (isset($item['descripcion']) ? trim($item['descripcion']) : ''),
                'marca' => $proto ? $proto->marca : (isset($item['marca']) ? trim($item['marca']) : ''),
                'idmarca' => $proto ? $proto->idmarca : ($prodMaster ? $prodMaster->idmarca : 0),
                'categoria' => $proto ? $proto->categoria : (isset($item['categoria']) ? trim($item['categoria']) : ''),
                'idcategoria' => $proto ? $proto->idcategoria : ($prodMaster ? $prodMaster->idcategoria : 0),
                'unidad' => $proto ? $proto->unidad : (isset($item['unidad']) ? trim($item['unidad']) : 'unid'),
                'cantidad' => $cuantos,
                'cantidad_inicial' => $cuantos,
                'preciolocal' => $preciolocal,
                'precioventa' => $precioventa,
                'preciomayor' => $precioMayorista,
                'comision' => $comision,
                'deposito' => $deposito_destino,
                'proveedor' => $proveedor,
                'imagenes' => $proto ? $proto->imagenes : '',
                'fecha_ingreso' => $hoy
            ];
            $this->db->insert('inventarios', $newInvData);
            $loteId = $this->db->insert_id();

            // Sincronizar el precio de venta y comisión en todos los lotes del mismo idprod para mantener catálogo
            $this->db->where('idprod', $item['idprod']);
            $this->db->update('inventarios', [
                'precioventa' => $precioventa,
                'comision' => $comision
            ]);

            // Insertar en detallecompras
            $detalleData = [
                'idcompra' => $idcompra,
                'idprod' => $loteId, // Referencia al lote creado en inventarios
                'descripcion' => $newInvData['descripcion'],
                'preciolocal' => $preciolocal,
                'cuantos' => $cuantos,
                'registrado' => $hoy
            ];
            $this->db->insert('detallecompras', $detalleData);

            // Registrar movimiento en Kardex
            if ($prodIdMaster > 0) {
                $this->db->insert('kardex', [
                    'producto_id' => $prodIdMaster,
                    'almacen_id' => $deposito_destino,
                    'lote_id' => $loteId,
                    'cantidad' => $cuantos,
                    'concepto' => 'INGRESO_POR_COMPRA_DIRECTA',
                    'tipo_movimiento' => 'INGRESO',
                    'referencia_id' => null
                ]);
            }
        }

        // 3. Si es a crédito, registrar el pago inicial y la deuda
        if ($formapago === 'credito') {
            $deuda = $total - $montoInicial;
            $pagoData = [
                'idcompra' => $idcompra,
                'monto' => $montoInicial,
                'tipopago' => 'efectivo',
                'fecha' => $hoy,
                'observacion' => 'Pago inicial al registrar compra a crédito'
            ];
            $this->db->insert('pagos', $pagoData);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(500)
                ->set_output(json_encode(['error' => 'Ocurrió un error al guardar la compra en la base de datos.']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'message' => 'Compra registrada con éxito y existencias actualizadas.',
                'idcompra' => $idcompra
            ]));
    }

    /**
     * Elimina una compra y descuenta/resta el stock de inventario.
     */
    public function eliminar($idcompra = null) {
        $this->check_permission('Compras', 'eliminar');
        if (!$idcompra) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de compra no proporcionado.']));
        }

        $this->db->trans_start();

        // 1. Obtener los detalles de la compra para revertir existencias
        $detalle = $this->db->where('idcompra', $idcompra)->get('detallecompras')->result();

        foreach ($detalle as $item) {
            // Descontar del inventario
            $this->db->set('cantidad', 'cantidad - ' . $item->cuantos, FALSE);
            $this->db->where('id', $item->idprod);
            $this->db->update('inventarios');
        }

        // 2. Eliminar detalles, pagos y compra
        $this->db->where('idcompra', $idcompra)->delete('detallecompras');
        $this->db->where('idcompra', $idcompra)->delete('pagos');
        $this->db->where('idcompra', $idcompra)->delete('compras');

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(500)
                ->set_output(json_encode(['error' => 'Error al revertir y eliminar la compra.']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Compra anulada y stock revertido con éxito.']));
    }
}
