<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Tienda extends CI_Controller {

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
     * Obtiene el listado de productos públicos
     * GET /tienda/productos?sucursal=1&q=clavos&marca=Truper
     */
    public function productos() {
        $sucursal = $this->input->get('sucursal');
        if (empty($sucursal) || $sucursal === '0') {
            $header_sucursal = $this->input->get_request_header('X-Active-Branch', TRUE);
            if (!empty($header_sucursal)) {
                $sucursal = $header_sucursal;
            }
        }
        $q = $this->input->get('q');
        $marca = $this->input->get('marca');
        $vendedor_id = $this->input->get('vendedor_id');

        // Check if the vendedor is valid
        $is_vendedor = false;
        if (!empty($vendedor_id)) {
            $this->db->where('id', $vendedor_id);
            $vendedor = $this->db->get('vendedores')->row();
            if ($vendedor && strtolower(trim($vendedor->estado)) === 'activo') {
                // Obtener roles múltiples
                $rolesQuery = $this->db->get_where('vendedores_roles', ['vendedor_id' => $vendedor->id])->result();
                $roles_lower = array();
                foreach ($rolesQuery as $rq) {
                    if (!empty($rq->rol)) {
                        $roles_lower[] = trim(strtolower($rq->rol));
                    }
                }
                if (empty($roles_lower) && !empty($vendedor->rol)) {
                    $roles_lower[] = trim(strtolower($vendedor->rol));
                }
                
                if (in_array('vendedores', $roles_lower) || in_array('vendedor', $roles_lower) || in_array('admin', $roles_lower) || in_array('administrador', $roles_lower) || in_array('administradores', $roles_lower) || in_array('enc. tienda y caja', $roles_lower) || in_array('encargado de tienda', $roles_lower) || in_array('editor', $roles_lower)) {
                    $is_vendedor = true;
                    
                    if ((empty($sucursal) || $sucursal === '0') && !empty($vendedor->ciudad)) {
                        $sucursal = $vendedor->ciudad;
                    }
                }
            }
        }

        // Obtener marcas únicas (antes de iniciar la consulta principal)
        $this->db->select('m.nombre as marca, m.logo');
        $this->db->from('productos p');
        $this->db->join('marcas m', 'p.idmarca = m.id', 'inner');
        $this->db->join('inventarios i', 'p.idprod = i.idprod', 'left');
        $this->db->where('p.estado', 'Activo');
        $this->db->distinct();
        $this->db->order_by('m.nombre', 'ASC');
        $marcas_result = $this->db->get()->result_array();
        
        $marcas = [];
        foreach ($marcas_result as $row) {
            if (!empty($row['marca'])) {
                $marcas[] = [
                    'nombre' => $row['marca'],
                    'logo' => $row['logo'] ?? null
                ];
            }
        }

        // Campos base: JOIN con tabla 'inventarios' para obtener stock y precio por sucursal
        $select_fields = '
            COALESCE(MAX(inventarios.id), MAX(p.id)) as id,
            p.idprod,
            MAX(p.descripcion) AS descripcion,
            MAX(c.descripcion) AS categoria,
            MAX(m.nombre) AS marca,
            COALESCE(MAX(inventarios.unidad), MAX(p.subunidad), \'unid\') AS unidad,
            MAX(p.precioventa) AS precioventa,
            MAX(p.nuevoprecio) AS preciomayor,
            COALESCE(MAX(inventarios.deposito), 1) AS sucursal,
            COALESCE(SUM(inventarios.cantidad), 0) AS cantidad,
            NULLIF(MAX(p.imagen), \'\') AS imagen
        ';
        
        if ($is_vendedor) {
            $select_fields .= ', COALESCE(MAX(p.comision), 0) AS comision';
            $select_fields .= ', MAX((SELECT COUNT(*) FROM vendedor_favoritos WHERE id_producto = COALESCE(inventarios.id, p.id) AND id_vendedor = ' . (int)$vendedor_id . ')) as is_favorito';
        }

        $this->db->select($select_fields, FALSE);
        $this->db->from('productos p');
        if (!empty($sucursal) && $sucursal !== '0') {
            $this->db->join('inventarios', 'p.idprod = inventarios.idprod AND inventarios.deposito = ' . (int)$sucursal, 'left', FALSE);
        } else {
            $this->db->join('inventarios', 'p.idprod = inventarios.idprod AND inventarios.deposito = 1', 'left', FALSE);
        }
        $this->db->join('categoria_producto c', 'p.idcategoria = c.idcategoria', 'left', FALSE);
        $this->db->join('marcas m', 'p.idmarca = m.id', 'left', FALSE);
        $this->db->where('p.estado', 'Activo');

        if (!empty($marca) && $marca !== 'Todas') {
            $this->db->where('m.nombre', $marca);
        }

        if (!empty($q)) {
            $search_escaped = $this->db->escape_like_str(trim($q));
            $this->db->group_start();
            $this->db->like('p.descripcion', $search_escaped, 'both', FALSE);
            $this->db->or_like('p.idprod', $search_escaped, 'both', FALSE);
            $this->db->group_end();
        }

        $this->db->group_by('p.idprod');

        // Ocultar productos con stock 0 si el usuario está logueado o si se solicita explícitamente
        $user_id_param = $this->input->get('user_id');
        $user_id_header = $this->input->get_request_header('X-User-Id', TRUE);
        $ocultar_sin_stock = $this->input->get('ocultar_sin_stock');

        if (!empty($vendedor_id) || !empty($user_id_param) || !empty($user_id_header) || $ocultar_sin_stock === '1' || $ocultar_sin_stock === 'true') {
            $this->db->having('COALESCE(SUM(inventarios.cantidad), 0) > 0');
        }

        $this->db->order_by('CASE WHEN COALESCE(SUM(inventarios.cantidad), 0) > 0 THEN 1 ELSE 2 END', 'ASC', FALSE);
        $this->db->order_by('MAX(p.descripcion)', 'ASC', FALSE);

        $this->db->limit(1000); // Límite amplio para ver todos los productos sin crashear
        $productos = $this->db->get()->result();

        // Enriquecer productos con promociones vigentes aplicables (Regla del Mayor Valor)
        $hoy = date('Y-m-d');
        $promociones = $this->db->query("
            SELECT p.*, m.nombre as marca_nombre, c.nombre as categoria_nombre 
            FROM promociones_descuentos p 
            LEFT JOIN marcas m ON p.marca_id = m.id 
            LEFT JOIN categorias c ON p.categoria_id = c.id 
            WHERE p.activo = 1 AND p.fecha_inicio <= '$hoy' AND p.fecha_fin >= '$hoy' 
            ORDER BY p.porcentaje_descuento DESC
        ")->result_array();

        foreach ($productos as &$prod) {
            $max_descuento_porcentaje = 0;
            $nombre_promo = '';
            foreach ($promociones as $promo) {
                $match = false;
                if ($promo['tipo_filtro'] === 'todos') {
                    $match = true;
                } else if ($promo['tipo_filtro'] === 'comision') {
                    if (floatval($prod->comision ?? 0) > 0) {
                        $match = true;
                    }
                } else if ($promo['tipo_filtro'] === 'marca' && !empty($promo['marca_nombre'])) {
                    if (strtolower(trim($prod->marca ?? '')) === strtolower(trim($promo['marca_nombre']))) {
                        $match = true;
                    }
                } else if ($promo['tipo_filtro'] === 'categoria' && !empty($promo['categoria_nombre'])) {
                    if (strtolower(trim($prod->categoria ?? '')) === strtolower(trim($promo['categoria_nombre']))) {
                        $match = true;
                    }
                }

                if ($match) {
                    $pct = intval($promo['porcentaje_descuento']);
                    if ($pct > $max_descuento_porcentaje) {
                        $max_descuento_porcentaje = $pct;
                        $nombre_promo = $promo['nombre'];
                    }
                }
            }

            if ($max_descuento_porcentaje > 0) {
                $pv_orig = floatval($prod->precioventa);
                $monto_desc = $pv_orig * ($max_descuento_porcentaje / 100.0);
                $prod->precio_original = $pv_orig;
                $prod->precioventa = round($pv_orig - $monto_desc, 2);
                $prod->descuento_porcentaje = $max_descuento_porcentaje;
                $prod->descuento_monto = round($monto_desc, 2);
                $prod->tiene_promocion = 1;
                $prod->nombre_promocion = $nombre_promo;
            } else {
                $prod->precio_original = floatval($prod->precioventa);
                $prod->descuento_porcentaje = 0;
                $prod->descuento_monto = 0;
                $prod->tiene_promocion = 0;
                $prod->nombre_promocion = '';
            }
        }

        echo json_encode([
            'status' => 'success',
            'data' => $productos,
            'marcas' => $marcas
        ]);
    }

    /**
     * Obtiene la configuración pública (bancos, qr, etc)
     * GET /tienda/configuracion
     */
    public function configuracion() {
        // Obtener sucursales (depósitos) reales de la base de datos
        $this->db->select('id, nombre');
        $this->db->where('estado', 'activo');
        // Filtrar por tipo de almacén para no enviar los depósitos
        $this->db->where('tipo_almacen', 'Sucursal_Venta');
        $this->db->order_by('id', 'ASC');
        $sucursales = $this->db->get('depositos')->result_array();

        // Configuración de la aplicación
        $config_db = $this->db->get('configapp')->row_array();

        $config = [
            'nrocuenta' => $config_db['nrocuenta'] ?? '1234567890',
            'banco' => $config_db['banco'] ?? 'Banco Mercantil Santa Cruz',
            'titularcuenta' => $config_db['titularcuenta'] ?? 'Ferretería Ofertón SRL',
            'whatsapp' => '+591' . ($config_db['whatsapp'] ?? '77939732'),
            'metodo_transferencia' => $config_db['metodo_transferencia'] ?? 1,
            'metodo_qrbisa' => $config_db['metodo_qrbisa'] ?? 1,
            'metodo_qrmercantil' => $config_db['metodo_qrmercantil'] ?? 1,
            'sucursales' => $sucursales
        ];
        echo json_encode(['status' => 'success', 'data' => $config]);
    }

    /**
     * Buscar clientes para el autocompletado en el checkout web
     */
    public function buscar_cliente() {
        $search = $this->input->get('q');
        if (empty($search) || strlen(trim($search)) < 2) {
            return $this->output->set_content_type('application/json')->set_output(json_encode([]));
        }

        $search_escaped = $this->db->escape_like_str(trim($search));
        $this->db->select('id, nombre, nit, telefono, tipo_cliente, direccion');
        $this->db->group_start();
        $this->db->like('nombre', $search_escaped);
        $this->db->or_like('nit', $search_escaped);
        $this->db->or_like('telefono', $search_escaped);
        $this->db->group_end();
        $this->db->where('estado', 'activo');
        $this->db->limit(5);
        
        $clientes = $this->db->get('clientes')->result_array();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'data' => $clientes]));
    }

    /**
     * Procesa el checkout de la web
     * POST /tienda/checkout
     */
    public function checkout() {
        $input_data = json_decode(file_get_contents('php://input'), true);

        if (!$input_data || empty($input_data['detalle'])) {
            echo json_encode(['status' => 'error', 'error' => 'Datos de orden inválidos']);
            return;
        }

        $this->db->trans_start();

        $token = "WEB-" . strtoupper(substr(uniqid(), -6));
        $total = isset($input_data['total']) ? (float)$input_data['total'] : 0;
        
        $orden_data = [
            'fecha' => date('Y-m-d H:i:s'),
            'cliente' => isset($input_data['cliente']) ? $input_data['cliente'] : 'Cliente Web',
            'nit' => isset($input_data['celular']) ? $input_data['celular'] : '0', // Usamos celular en NIT para referencia o creamos columna nueva
            'total' => $total,
            'formapago' => isset($input_data['metodo_pago']) ? $input_data['metodo_pago'] : 'transferencia',
            'idneg' => isset($input_data['sucursal']) ? $input_data['sucursal'] : 1,
            'idusr' => isset($input_data['vendedor_id']) ? $input_data['vendedor_id'] : 1, 
            'vendedor' => isset($input_data['vendedor_id']) ? $input_data['vendedor_id'] : 1, 
            'comentario' => "Orden Web Token: " . $token
        ];

        // Se inserta en ventas (como una preventa/orden pendiente)
        $this->db->insert('ventas', $orden_data);
        $insert_id = $this->db->insert_id();

        // Insertar detalles
        foreach ($input_data['detalle'] as $item) {
            $det_data = [
                'idventa' => $insert_id,
                'idprod' => $item['idprod'],
                'descripcion' => $item['descripcion'],
                'cantidad' => $item['cantidad'],
                'precio' => $item['precioventa'],
                'subtotal' => (float)$item['cantidad'] * (float)$item['precioventa']
            ];
            $this->db->insert('ventas_detalle', $det_data);
            
            // Reducir stock temporalmente
            $this->db->set('cantidad', 'cantidad - ' . (float)$item['cantidad'], FALSE);
            $this->db->where('id', $item['id']);
            $this->db->update('inventario');
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            echo json_encode(['status' => 'error', 'error' => 'No se pudo generar la orden.']);
        } else {
            echo json_encode([
                'status' => 'success',
                'message' => 'Orden registrada correctamente',
                'token' => $token,
                'idventa' => $insert_id
            ]);
        }
    }

    /**
     * Procesa el checkout desde el modal de Vendedor (guarda como Proforma)
     * POST /tienda/checkout_proforma
     */
    public function checkout_proforma() {
        $input_data = json_decode(file_get_contents('php://input'), true);

        if (!$input_data || empty($input_data['detalle'])) {
            echo json_encode(['status' => 'error', 'error' => 'Datos de proforma inválidos']);
            return;
        }

        $phone = isset($input_data['celular']) ? trim($input_data['celular']) : '';
        // Validar que el celular sea de Bolivia (8 dígitos y comience con 6 o 7)
        if (!preg_match('/^[67]\d{7}$/', $phone)) {
            echo json_encode(['status' => 'error', 'error' => 'El número de celular debe tener 8 dígitos y comenzar con 6 o 7.']);
            return;
        }

        $this->db->trans_start();

        $idproforma = uniqid(); 
        $total = isset($input_data['total']) ? (float)$input_data['total'] : 0;
        
        // Construir el comentario de transporte
        $comentario = "Generado desde WEB Vendedor.\n\n";
        $comentario .= "Observaciones: " . ($input_data['observaciones'] ?? 'Ninguna') . "\n";
        $comentario .= "Estado de Pago: " . ($input_data['estado_pago'] ?? 'Pendiente') . "\n";
        
        if (isset($input_data['con_transporte']) && $input_data['con_transporte']) {
            $comentario .= "\n--- TRANSPORTE ---\n";
            $costo = floatval($input_data['costo_envio'] ?? 0);
            $comentario .= "Costo de Envío: " . ($costo > 0 ? 'Bs. ' . number_format($costo, 2) : 'El cliente paga') . "\n";
            $comentario .= "Dirección: " . ($input_data['direccion_envio'] ?? '') . "\n";
            $comentario .= "Ubicación/Mapa: " . ($input_data['mapa_envio'] ?? '') . "\n";
            $comentario .= "Fecha y hora de entrega: " . ($input_data['fecha_entrega'] ?? '') . "\n";
        }

        // Buscar o registrar al cliente en la tabla `clientes`
        $idcliente = isset($input_data['idcliente']) ? intval($input_data['idcliente']) : null;
        $nit = isset($input_data['nit']) ? trim($input_data['nit']) : '0';
        $cliente_nombre = isset($input_data['cliente']) ? trim($input_data['cliente']) : 'Cliente Web';
        $telefono = isset($input_data['celular']) ? trim($input_data['celular']) : '';
        $complemento = isset($input_data['complemento']) ? trim($input_data['complemento']) : '';

        // Si se recibió ID del cliente de frontend, validar si existe
        if ($idcliente) {
            $this->db->where('id', $idcliente);
            $client_check = $this->db->get('clientes')->row();
            if ($client_check) {
                // Actualizar datos del cliente por si variaron
                $update_data = [];
                if ($client_check->nombre !== $cliente_nombre) {
                    $update_data['nombre'] = $cliente_nombre;
                }
                if ($client_check->nit !== $nit) {
                    $update_data['nit'] = $nit;
                }
                if ($client_check->complemento !== $complemento) {
                    $update_data['complemento'] = $complemento;
                }
                if (!empty($telefono) && $client_check->telefono !== $telefono) {
                    $update_data['telefono'] = $telefono;
                }
                if (!empty($update_data)) {
                    $this->db->where('id', $idcliente);
                    $this->db->update('clientes', $update_data);
                }
            } else {
                $idcliente = null;
            }
        }

        // Si no se tiene o no existe el cliente por ID, buscar por NIT (si no es genérico)
        if (!$idcliente && !empty($nit)) {
            $nit_lower = strtolower($nit);
            $generic_placeholders = ['0', 's/n', 'sn', 'n/a', 'na', 'sin nit', 'sin numero', 'sin número', 'sin nro', 'sin nmero', 'sin documento', 'ninguno'];
            $clean_nit = preg_replace('/[^a-z0-9]/', '', $nit_lower);
            $generic_cleans = ['sn', 'na', 'sinnit', 'sinnumero', 'sinnro', 'sindocumento', 'ninguno'];
            $es_generico = in_array($clean_nit, $generic_cleans) || in_array($nit_lower, $generic_placeholders);

            if (!$es_generico) {
                $this->db->where('nit', $nit);
                $client_by_nit = $this->db->get('clientes')->row();
                if ($client_by_nit) {
                    $idcliente = $client_by_nit->id;
                    // Actualizar datos
                    $update_data = [];
                    if ($client_by_nit->nombre !== $cliente_nombre) {
                        $update_data['nombre'] = $cliente_nombre;
                    }
                    if ($client_by_nit->complemento !== $complemento) {
                        $update_data['complemento'] = $complemento;
                    }
                    if (!empty($telefono) && $client_by_nit->telefono !== $telefono) {
                        $update_data['telefono'] = $telefono;
                    }
                    if (!empty($update_data)) {
                        $this->db->where('id', $idcliente);
                        $this->db->update('clientes', $update_data);
                    }
                }
            }
        }

        // Si no se encontró por NIT, buscar por nombre exacto
        if (!$idcliente && !empty($cliente_nombre)) {
            $this->db->where('nombre', $cliente_nombre);
            $client_by_name = $this->db->get('clientes')->row();
            if ($client_by_name) {
                $idcliente = $client_by_name->id;
                // Actualizar datos
                $update_data = [];
                if ($client_by_name->nit !== $nit) {
                    $update_data['nit'] = $nit;
                }
                if ($client_by_name->complemento !== $complemento) {
                    $update_data['complemento'] = $complemento;
                }
                if (!empty($telefono) && $client_by_name->telefono !== $telefono) {
                    $update_data['telefono'] = $telefono;
                }
                if (!empty($update_data)) {
                    $this->db->where('id', $idcliente);
                    $this->db->update('clientes', $update_data);
                }
            }
        }

        // Si es cliente nuevo y no se encontró coincidencia alguna, se crea
        if (!$idcliente) {
            $new_client_data = [
                'nombre' => $cliente_nombre,
                'nit' => $nit,
                'complemento' => $complemento,
                'telefono' => $telefono
            ];
            $this->db->insert('clientes', $new_client_data);
            $idcliente = $this->db->insert_id();
        }

        $estado_pago = $input_data['estado_pago'] ?? 'Pendiente';

        // Verificar si hay webhook que llego antes y actualizo la transaccion
        if (!empty($input_data['qr_alias'])) {
            $tx = $this->db->where('alias', $input_data['qr_alias'])->get('bisa_qr_transacciones')->row();
            if ($tx && $tx->estado === 'PAGADO') {
                $estado_pago = 'Pagado';
            }
        }

        $isPaid = $estado_pago === 'Pagado';
        $paidAmount = $isPaid ? $total : 0;
        $remainingBalance = $isPaid ? 0 : $total;

        $proforma_data = [
            'idneg' => $input_data['sucursal_entrega'] ?? 1,
            'idproforma' => $idproforma,
            'fecha' => date('Y-m-d H:i:s'),
            'cliente' => $cliente_nombre,
            'nit' => $nit,
            'complemento' => $complemento,
            'telefono' => $telefono,
            'total' => $total,
            'formapago' => $input_data['metodo_pago'] ?? 'efectivo',
            'estado' => $estado_pago, 
            'vendedor' => $input_data['vendedor_id'] ?? 1,
            'idusr' => $input_data['vendedor_id'] ?? 1,
            'idcliente' => $idcliente,
            'pago' => $paidAmount,
            'saldo' => $remainingBalance,
            'comentario' => $comentario
        ];

        $this->db->insert('proformas', $proforma_data);

        // Vincular la transaccion de QR BISA si existe
        if (!empty($input_data['qr_alias'])) {
            $this->db->where('alias', $input_data['qr_alias'])->update('bisa_qr_transacciones', [
                'id_proforma' => $idproforma
            ]);
        }

        foreach ($input_data['detalle'] as $item) {
            $det_data = [
                'idproforma' => $idproforma,
                'idprod' => $item['id'], // El sistema POS requiere el ID numérico autoincremental
                'descripcion' => $item['descripcion'],
                'cuantos' => $item['cantidad'],
                'preciolocal' => $item['precio_base'] ?? $item['precioventa'],
                'precioventa' => $item['precioventa'],
                'preciofinal' => (float)$item['cantidad'] * (float)$item['precioventa'],
                'vendedor' => $input_data['vendedor_id'] ?? 1,
                'comision' => $item['comision'] ?? 0
            ];
            $this->db->insert('detalleproformas', $det_data);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            echo json_encode(['status' => 'error', 'error' => 'No se pudo generar la proforma.']);
        } else {
            echo json_encode([
                'status' => 'success',
                'message' => 'Proforma registrada correctamente',
                'idproforma' => $idproforma
            ]);
        }
    }

    /**
     * Alterna (agrega o quita) un producto de la lista de favoritos de un vendedor.
     * POST /tienda/toggle_favorito
     */
    public function toggle_favorito() {
        $input_data = json_decode(file_get_contents('php://input'), true);

        $vendedor_id = $input_data['vendedor_id'] ?? null;
        $producto_id = $input_data['producto_id'] ?? null;

        if (!$vendedor_id || !$producto_id) {
            echo json_encode(['status' => 'error', 'error' => 'Parámetros insuficientes']);
            return;
        }

        $this->db->where('id_vendedor', $vendedor_id);
        $this->db->where('id_producto', $producto_id);
        $exists = $this->db->get('vendedor_favoritos')->row();

        if ($exists) {
            $this->db->where('id', $exists->id);
            $this->db->delete('vendedor_favoritos');
            echo json_encode(['status' => 'success', 'is_favorito' => false]);
        } else {
            $this->db->insert('vendedor_favoritos', [
                'id_vendedor' => $vendedor_id,
                'id_producto' => $producto_id,
                'fecha_agregado' => date('Y-m-d H:i:s')
            ]);
            echo json_encode(['status' => 'success', 'is_favorito' => true]);
        }
    }

    /**
     * Obtiene información pública básica de un vendedor (nombre y celular)
     * GET /tienda/vendedor_info?id=1
     */
    public function vendedor_info() {
        $id = $this->input->get('id');
        if (empty($id)) {
            echo json_encode(['status' => 'error', 'error' => 'Falta el ID del vendedor']);
            return;
        }

        $this->db->select('id, nombre, telefono');
        $this->db->where('id', $id);
        $this->db->where('estado', 'activo');
        $vendedor = $this->db->get('vendedores')->row();

        if ($vendedor) {
            echo json_encode(['status' => 'success', 'data' => $vendedor]);
        } else {
            echo json_encode(['status' => 'error', 'error' => 'Vendedor no encontrado']);
        }
    }

    /**
     * Obtiene el listado de proformas generadas por un vendedor específico
     * GET /tienda/listar_proformas_vendedor?vendedor_id=X
     */
    public function listar_proformas_vendedor() {
        // Expirar proformas pendientes de días anteriores (damos 2 días de vigencia)
        $this->db->query("UPDATE proformas SET estado = 'Vencido' WHERE estado = 'Pendiente' AND DATE(fecha) < DATE_SUB(CURDATE(), INTERVAL 2 DAY)");

        $sellerId = $this->input->get('vendedor_id');

        if (empty($sellerId)) {
            echo json_encode(['status' => 'error', 'error' => 'Falta el ID del vendedor']);
            return;
        }

        // Validar vendedor activo
        $this->db->where('id', $sellerId);
        $this->db->where('estado', 'activo');
        $seller = $this->db->get('vendedores')->row();
        if (!$seller) {
            echo json_encode(['status' => 'error', 'error' => 'Vendedor no válido o inactivo']);
            return;
        }

        $this->db->select('p.id, p.idproforma, p.fecha, p.fecha_venta, p.cliente, p.nit, p.telefono, p.total, p.estado, p.formapago, MAX(d.nombre) AS sucursal_nombre, MAX(qr.alias) AS qr_alias, MAX(qr.qr_base64) AS qr_base64');
        $this->db->from('proformas p');
        $this->db->join('depositos d', 'p.idneg = d.id', 'left');
        $this->db->join('bisa_qr_transacciones qr', 'p.idproforma = qr.id_proforma', 'left');
        $this->db->where('p.vendedor', $sellerId);
        $this->db->group_by('p.id');
        $this->db->order_by('p.fecha', 'DESC');
        $proformas = $this->db->get()->result();

        echo json_encode([
            'status' => 'success',
            'data' => $proformas
        ]);
    }

    /**
     * Obtiene el detalle de una proforma generada por un vendedor
     * GET /tienda/detalle_proforma?vendedor_id=X&idproforma=Y
     */
    public function detalle_proforma() {
        $sellerId = $this->input->get('vendedor_id');
        $proformaId = $this->input->get('idproforma');

        if (empty($sellerId) || empty($proformaId)) {
            echo json_encode(['status' => 'error', 'error' => 'Parámetros insuficientes']);
            return;
        }

        // Validar proforma y que pertenezca al vendedor
        $this->db->where('idproforma', $proformaId);
        $this->db->where('vendedor', $sellerId);
        $proforma = $this->db->get('proformas')->row();

        if (!$proforma) {
            echo json_encode(['status' => 'error', 'error' => 'Proforma no encontrada o no pertenece al vendedor']);
            return;
        }

        $details = $this->db->where('idproforma', $proformaId)->get('detalleproformas')->result();

        echo json_encode([
            'status' => 'success',
            'proforma' => $proforma,
            'data' => $details
        ]);
    }
}

