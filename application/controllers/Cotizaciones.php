<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Cotizaciones extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->database();
        $this->load->helper('url');
        
        // CORS Headers
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-User-Id, X-Rol-Id, X-Active-Branch');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit();
        }
    }

    /**
     * Lista las cotizaciones con filtros de fecha, sucursal y texto de búsqueda.
     * GET /cotizaciones/listar?inicio=2026-01-01&fin=2026-12-31&q=Juan
     */
    public function listar() {
        $this->check_permission('Cotizaciones', 'ver');

        $inicio = $this->input->get('inicio');
        $fin = $this->input->get('fin');
        $q = trim($this->input->get('q') ?? '');
        $estado = $this->input->get('estado');
        $sucursal = $this->input->get('sucursal') ?: $this->input->get_request_header('X-Active-Branch', TRUE);

        $this->db->select("c.*, DATE_FORMAT(c.fecha, '%d/%m/%Y %H:%i') as fecha_formateada, DATE_FORMAT(c.fecha, '%Y-%m-%d') as fecha_simple, d.nombre as sucursal_nombre, COALESCE(v.nombre, c.vendedor, 'Usuario') as usuario_nombre, (SELECT COUNT(dc.id) FROM detallecotizaciones dc WHERE dc.idcotizacion = c.idcotizacion) as cantidad_items", FALSE);
        $this->db->from('cotizaciones c');
        $this->db->join('depositos d', 'c.idneg = d.id', 'left');
        $this->db->join('vendedores v', 'c.idusr = v.id', 'left');

        if (!empty($inicio)) {
            // Soporta formatos dd/mm/yyyy o yyyy-mm-dd
            if (strpos($inicio, '/') !== false) {
                $parts = explode('/', $inicio);
                if (count($parts) === 3) {
                    $inicio = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                }
            }
            $this->db->where('DATE(c.fecha) >=', $inicio);
        }

        if (!empty($fin)) {
            if (strpos($fin, '/') !== false) {
                $parts = explode('/', $fin);
                if (count($parts) === 3) {
                    $fin = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                }
            }
            $this->db->where('DATE(c.fecha) <=', $fin);
        }

        if (!empty($sucursal) && $sucursal !== 'todas' && $sucursal !== 'all') {
            $this->db->where('c.idneg', $sucursal);
        }

        if (!empty($estado) && $estado !== 'Todos') {
            $this->db->where('c.estado', $estado);
        }

        if (!empty($q)) {
            $this->db->group_start();
            $this->db->like('c.cliente', $q);
            $this->db->or_like('c.nit', $q);
            $this->db->or_like('c.telefono', $q);
            $this->db->or_like('c.idcotizacion', $q);
            $this->db->or_like('c.id', $q);
            $this->db->group_end();
        }

        $this->db->order_by('c.id', 'DESC');
        $this->db->limit(300);

        $query = $this->db->get();
        $cotizaciones = $query->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($cotizaciones));
    }

    /**
     * Obtiene los datos completos de una cotización y su detalle.
     * GET /cotizaciones/buscar_cotizacion?nro=1
     */
    public function buscar_cotizacion() {
        $this->check_permission('Cotizaciones', 'ver');

        $nro = $this->input->get('nro') ?: $this->input->get('id');
        $idcotizacion = $this->input->get('idcotizacion');

        if (empty($nro) && empty($idcotizacion)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'error', 'error' => 'Parámetro nro o idcotizacion requerido']));
        }

        $this->db->select("c.*, DATE_FORMAT(c.fecha, '%d/%m/%Y %H:%i') as fecha_formateada, DATE_FORMAT(c.fecha, '%Y-%m-%d') as fecha_simple, d.nombre as sucursal_nombre, COALESCE(v.nombre, c.vendedor, 'Asesor Comercial') as asesor_nombre, COALESCE(v.telefono, '') as asesor_telefono", FALSE);
        $this->db->from('cotizaciones c');
        $this->db->join('depositos d', 'c.idneg = d.id', 'left');
        $this->db->join('vendedores v', 'c.idusr = v.id', 'left');

        if (!empty($idcotizacion)) {
            $this->db->where('c.idcotizacion', $idcotizacion);
        } else {
            $this->db->group_start();
            $this->db->where('c.id', $nro);
            $this->db->or_where('c.idcotizacion', (string)$nro);
            $this->db->group_end();
        }

        $cotizacion = $this->db->get()->row();

        if (!$cotizacion) {
            return $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'error', 'error' => 'Cotización no encontrada']));
        }

        // Obtener detalles de la cotización
        $this->db->select('dc.*, dc.cantidad as cuantos, dc.precioventa as precio, dc.idprod as codigo_producto');
        $this->db->from('detallecotizaciones dc');
        $this->db->where('dc.idcotizacion', $cotizacion->idcotizacion);
        $this->db->order_by('dc.id', 'ASC');
        $detalles = $this->db->get()->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'status' => 'success',
                'cotizacion' => $cotizacion,
                'detalles' => $detalles
            ]));
    }

    /**
     * Guarda una cotización nueva o actualiza una existente (NO descuenta inventario).
     * POST /cotizaciones/guardar
     */
    public function guardar() {
        $this->check_permission('Cotizaciones', 'crear');

        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            $data = $this->input->post();
        }

        $cliente = trim($data['cliente'] ?? '');
        $telefono = trim($data['telefono'] ?? '');
        $nit = trim($data['nit'] ?? '');
        $items = $data['items'] ?? [];

        if (empty($cliente) || empty($telefono) || empty($nit)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'error', 'error' => 'Nombre del cliente, celular y CI/NIT son obligatorios.']));
        }

        if (empty($items) || !is_array($items)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'error', 'error' => 'Debe agregar al menos un producto a la cotización.']));
        }

        $idusr = intval($this->input->get_request_header('X-User-Id', TRUE) ?: ($data['idusr'] ?? 1));
        $idneg = $data['idneg'] ?? ($this->input->get_request_header('X-Active-Branch', TRUE) ?: '1');

        // Obtener nombre del vendedor
        $vendedorRow = $this->db->select('nombre')->get_where('vendedores', ['id' => $idusr])->row();
        $vendedorNombre = $vendedorRow ? $vendedorRow->nombre : ($data['vendedor'] ?? 'Vendedor');

        // Determinar ID único de cotización
        $idcotizacion = !empty($data['idcotizacion']) ? $data['idcotizacion'] : 'COT-' . date('ymd') . '-' . substr(uniqid(), -5);

        $validez_dias = isset($data['validez_dias']) ? intval($data['validez_dias']) : 15;
        $forma_pago = trim($data['forma_pago'] ?? 'Efectivo / Transferencia');
        $tiempo_entrega = trim($data['tiempo_entrega'] ?? 'Inmediata');
        $comentario = trim($data['comentario'] ?? '');
        $descuento = floatval($data['descuento'] ?? 0);

        // Calcular totales
        $subtotalCalculado = 0;
        $detalleRows = [];

        foreach ($items as $item) {
            $qty = floatval($item['cantidad'] ?? $item['cuantos'] ?? 1);
            if ($qty <= 0) $qty = 1;

            $precio = floatval($item['precioventa'] ?? $item['precio'] ?? 0);
            $sub = floatval($item['subtotal'] ?? ($qty * $precio));
            $subtotalCalculado += $sub;

            $detalleRows[] = [
                'idcotizacion'     => $idcotizacion,
                'idprod'           => !empty($item['idprod']) ? (string)$item['idprod'] : (!empty($item['codigo']) ? (string)$item['codigo'] : null),
                'es_personalizado' => !empty($item['es_personalizado']) ? 1 : 0,
                'descripcion'      => trim($item['descripcion'] ?? $item['nombre'] ?? 'Producto'),
                'marca'            => trim($item['marca'] ?? ''),
                'unidad'           => trim($item['unidad'] ?? 'unid'),
                'imagen'           => $item['imagen'] ?? $item['imagenes'] ?? null,
                'cantidad'         => $qty,
                'precioventa'      => $precio,
                'subtotal'         => $sub,
                'observaciones'    => trim($item['observaciones'] ?? '')
            ];
        }

        $totalFinal = max(0, $subtotalCalculado - $descuento);

        $this->db->trans_start();

        // Verificar si la cotización ya existe para actualizarla o crearla nueva
        $existente = $this->db->get_where('cotizaciones', ['idcotizacion' => $idcotizacion])->row();

        if ($existente) {
            $this->db->where('idcotizacion', $idcotizacion)->update('cotizaciones', [
                'idneg'          => (string)$idneg,
                'cliente'        => $cliente,
                'telefono'       => $telefono,
                'nit'            => $nit,
                'validez_dias'   => $validez_dias,
                'forma_pago'     => $forma_pago,
                'tiempo_entrega' => $tiempo_entrega,
                'comentario'     => $comentario,
                'subtotal'       => $subtotalCalculado,
                'descuento'      => $descuento,
                'total'          => $totalFinal,
                'idusr'          => $idusr,
                'vendedor'       => $vendedorNombre,
                'estado'         => $data['estado'] ?? $existente->estado ?? 'Pendiente'
            ]);
            $cotizacionId = $existente->id;

            // Eliminar detalles previos para reinsertar actualizados
            $this->db->where('idcotizacion', $idcotizacion)->delete('detallecotizaciones');
        } else {
            $this->db->insert('cotizaciones', [
                'idcotizacion'   => $idcotizacion,
                'idneg'          => (string)$idneg,
                'cliente'        => $cliente,
                'telefono'       => $telefono,
                'nit'            => $nit,
                'fecha'          => date('Y-m-d H:i:s'),
                'validez_dias'   => $validez_dias,
                'forma_pago'     => $forma_pago,
                'tiempo_entrega' => $tiempo_entrega,
                'comentario'     => $comentario,
                'subtotal'       => $subtotalCalculado,
                'descuento'      => $descuento,
                'total'          => $totalFinal,
                'idusr'          => $idusr,
                'vendedor'       => $vendedorNombre,
                'estado'         => 'Pendiente'
            ]);
            $cotizacionId = $this->db->insert_id();
        }

        // Insertar los ítems
        if (!empty($detalleRows)) {
            $this->db->insert_batch('detallecotizaciones', $detalleRows);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'error', 'error' => 'Error al registrar la cotización en base de datos.']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'status'       => 'success',
                'message'      => 'Cotización guardada exitosamente.',
                'id'           => $cotizacionId,
                'idcotizacion' => $idcotizacion,
                'total'        => $totalFinal
            ]));
    }

    /**
     * Sube una imagen para un producto no registrado o libre.
     * POST /cotizaciones/subir_imagen
     */
    public function subir_imagen() {
        $this->check_permission('Cotizaciones', 'crear');

        if (empty($_FILES['imagen']['name'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'error', 'error' => 'No se recibió ningún archivo de imagen.']));
        }

        $uploadDir = FCPATH . 'uploads/cotizaciones/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileExt = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        if (!in_array($fileExt, $allowed)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'error', 'error' => 'Formato de imagen no permitido. Utilice JPG, PNG o WebP.']));
        }

        $fileName = 'cotiz_' . uniqid() . '.' . $fileExt;
        $targetFile = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['imagen']['tmp_name'], $targetFile)) {
            $relativePath = 'uploads/cotizaciones/' . $fileName;
            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status'   => 'success',
                    'filename' => $fileName,
                    'path'     => $relativePath,
                    'url'      => base_url($relativePath)
                ]));
        } else {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'error', 'error' => 'Error al mover el archivo subido al servidor.']));
        }
    }

    /**
     * Busca productos existentes en el catálogo / inventario de la sucursal activa.
     * GET /cotizaciones/buscar_productos_catalogo?q=taladro&sucursal=1
     */
    public function buscar_productos_catalogo() {
        $q = trim($this->input->get('q') ?? '');
        $sucursal = $this->input->get('sucursal') ?: $this->input->get_request_header('X-Active-Branch', TRUE);

        if (empty($sucursal)) {
            $sucursal = 1;
        }

        $this->db->select("p.idprod, MAX(p.descripcion) as descripcion, COALESCE(MAX(m.nombre), MAX(p.marca), 'Sin Marca') as marca, COALESCE(MAX(p.unidad), 'unid') as unidad, MAX(p.precioventa) as precioventa, MAX(p.preciolocal) as preciolocal, COALESCE(NULLIF(MAX(p.imagen), ''), '') as imagen, COALESCE(SUM(i.cantidad), 0) as stock_disponible, MAX(p.id) as id", FALSE);
        $this->db->from('productos p');
        $this->db->join('marcas m', 'p.idmarca = m.id', 'left');
        $this->db->join('inventarios i', 'p.idprod = i.idprod AND i.deposito = ' . $this->db->escape($sucursal), 'left');

        if (!empty($q)) {
            $this->db->group_start();
            $this->db->like('p.descripcion', $q);
            $this->db->or_like('p.idprod', $q);
            $this->db->or_like('p.marca', $q);
            $this->db->or_like('m.nombre', $q);
            $this->db->group_end();
        }

        $this->db->group_by('p.idprod');
        $this->db->order_by('MAX(p.descripcion)', 'ASC');
        $this->db->limit(60);

        $productos = $this->db->get()->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($productos));
    }

    /**
     * Cambia el estado de una cotización (Pendiente, Aprobada, Vencida, Anulada).
     * POST /cotizaciones/cambiar_estado
     */
    public function cambiar_estado() {
        $this->check_permission('Cotizaciones', 'editar');

        $data = json_decode(file_get_contents('php://input'), true) ?: $this->input->post();
        $id = $data['id'] ?? null;
        $idcotizacion = $data['idcotizacion'] ?? null;
        $nuevo_estado = trim($data['estado'] ?? '');

        if ((empty($id) && empty($idcotizacion)) || empty($nuevo_estado)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'error', 'error' => 'ID y nuevo estado son requeridos.']));
        }

        if (!empty($id)) {
            $this->db->where('id', $id);
        } else {
            $this->db->where('idcotizacion', $idcotizacion);
        }

        $this->db->update('cotizaciones', ['estado' => $nuevo_estado]);

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'message' => "Estado actualizado a $nuevo_estado"]));
    }
}
