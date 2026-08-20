<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ventas extends CI_Controller {

    public function __construct() {
        parent::__construct();
        // Habilitar CORS
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-Active-Branch, X-User-Id, X-Rol-Id, X-QR-Env, X-QR-ENV');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit();
        }
        
        $this->load->database();

        // Crear la tabla de transacciones de QR BISA si no existe
        $this->db->query("CREATE TABLE IF NOT EXISTS `bisa_qr_transacciones` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `alias` VARCHAR(100) NOT NULL UNIQUE,
            `monto` DECIMAL(10,2) NOT NULL,
            `id_venta` VARCHAR(50) DEFAULT NULL,
            `id_proforma` VARCHAR(100) DEFAULT NULL,
            `id_qr` VARCHAR(100) DEFAULT NULL,
            `qr_base64` LONGTEXT DEFAULT NULL,
            `estado` VARCHAR(50) DEFAULT 'PENDIENTE',
            `fecha_registro` DATETIME NOT NULL,
            `fecha_pago` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;");
    }

    public function search_products() {
        $query = $this->input->get('q');
        $dep = $this->input->get('dep');
        
        if (empty($dep)) {
            $dep = '1'; // Por defecto depósito 1 (Tienda Importación)
        }
               // Determinar si es un Depósito Central o Sucursal de Venta para aplicar la tarifa de precios adecuada
        $depositoObj = $this->db->select('tipo_almacen')->where('id', intval($dep))->get('depositos')->row();
        $esMayorista = ($depositoObj && $depositoObj->tipo_almacen === 'Deposito_Central');
        
        // Siempre utilizar precioventa de la tabla productos
        $precioSelect = 'MAX(p.precioventa)';
  
        // Seleccionar datos de productos con su stock consolidado para el depósito actual (vía LEFT JOIN)
        $this->db->select('
            MAX(p.id) AS id,
            p.idprod,
            MAX(p.descripcion) AS descripcion,
            MAX(p.marca) AS marca,
            MAX(p.categoria) AS categoria,
            MAX(p.idmarca) AS idmarca,
            MAX(p.idcategoria) AS idcategoria,
            MAX(p.unidad) AS unidad,
            ' . $precioSelect . ' AS precioventa,
            COALESCE(NULLIF(MAX(i.preciolocal), 0), MAX(p.preciolocal), 0) AS preciolocal,
            COALESCE(SUM(i.cantidad), 0) AS cantidad,
            COALESCE(MAX(p.comision), 0) AS comision,
            \'' . intval($dep) . '\' AS deposito,
            MAX(NULLIF(p.imagen, \'\')) AS imagen
        ', FALSE);
        $this->db->from('productos p');
        $this->db->join('inventarios i', 'p.idprod = i.idprod AND i.deposito = ' . intval($dep), 'left', FALSE);
        $this->db->where('p.estado', 'Activo');
        
        if (!empty($query)) {
            $this->db->group_start();
            $this->db->like('p.descripcion', $query, 'both', FALSE);
            $this->db->or_like('p.idprod', $query, 'both', FALSE);
            $this->db->group_end();
        }
        $this->db->group_by('p.idprod');
        $this->db->order_by('COALESCE(SUM(i.cantidad), 0) DESC, MAX(p.descripcion) ASC'); // Primero con stock consolidado, luego alfabéticamente
        $this->db->limit(200); // Limite a 200 resultados para visualizar mas productos
        $productos = $this->db->get()->result();

        // Enriquecer productos con promociones vigentes (Regla del Mayor Valor y Protección Financiera)
        $promociones = $this->db->query("
            SELECT p.*, m.nombre as marca_nombre, c.descripcion as categoria_nombre 
            FROM promociones_descuentos p 
            LEFT JOIN marcas m ON p.marca_id = m.id 
            LEFT JOIN categoria_producto c ON p.categoria_id = c.idcategoria 
            WHERE p.activo = 1 AND DATE(p.fecha_inicio) <= CURDATE() AND DATE(p.fecha_fin) >= CURDATE() 
            ORDER BY p.porcentaje_descuento DESC
        ")->result_array();

        // Mapeos auxiliares de marcas y categorías por ID
        $marcas_by_id = [];
        $cats_by_id = [];
        foreach ($promociones as $pr) {
            if (!empty($pr['marca_id']) && empty($marcas_by_id[$pr['marca_id']])) {
                $mrow = $this->db->get_where('marcas', ['id' => $pr['marca_id']])->row();
                if ($mrow) $marcas_by_id[$pr['marca_id']] = strtolower(trim($mrow->nombre));
            }
            if (!empty($pr['categoria_id']) && empty($cats_by_id[$pr['categoria_id']])) {
                $crow = $this->db->get_where('categoria_producto', ['idcategoria' => $pr['categoria_id']])->row();
                if ($crow) $cats_by_id[$pr['categoria_id']] = strtolower(trim($crow->descripcion));
            }
        }

        foreach ($productos as &$prod) {
            $max_pct = 0;
            $nombre_promo = '';
            $comision_val = floatval($prod->comision ?? 0);
            $costo_compra = floatval($prod->preciolocal ?? 0);
            $pv_orig_base = floatval($prod->precioventa ?? 0);

            foreach ($promociones as $promo) {
                $match = false;
                if ($promo['tipo_filtro'] === 'todos') {
                    $match = true;
                } else if ($promo['tipo_filtro'] === 'comision') {
                    $min_com = floatval($promo['comision_minima'] ?? 0);
                    if ($min_com > 0) {
                        if ($comision_val >= $min_com) {
                            $match = true;
                        }
                    } else if ($comision_val > 0) {
                        $match = true;
                    }
                } else if ($promo['tipo_filtro'] === 'productos' && !empty($promo['productos_ids'])) {
                    $prod_list = json_decode($promo['productos_ids'], true);
                    if (!is_array($prod_list)) {
                        $prod_list = array_map('trim', explode(',', $promo['productos_ids']));
                    }
                    $prod_list_str = array_map('strval', $prod_list);
                    if (in_array((string)($prod->idprod ?? ''), $prod_list_str) || in_array((string)($prod->id ?? ''), $prod_list_str)) {
                        $match = true;
                    }
                } else if ($promo['tipo_filtro'] === 'marca') {
                    $prod_marca_str = strtolower(trim($prod->marca ?? ''));
                    if (!empty($promo['marca_id']) && !empty($prod->idmarca) && (int)$promo['marca_id'] === (int)$prod->idmarca) {
                        $match = true;
                    } else if (!empty($promo['marca_nombre']) && $prod_marca_str === strtolower(trim($promo['marca_nombre']))) {
                        $match = true;
                    } else if (!empty($promo['marca_id']) && isset($marcas_by_id[$promo['marca_id']]) && $prod_marca_str === $marcas_by_id[$promo['marca_id']]) {
                        $match = true;
                    }
                } else if ($promo['tipo_filtro'] === 'categoria') {
                    $prod_cat_str = strtolower(trim($prod->categoria ?? ''));
                    if (!empty($promo['categoria_id']) && !empty($prod->idcategoria) && (int)$promo['categoria_id'] === (int)$prod->idcategoria) {
                        $match = true;
                    } else if (!empty($promo['categoria_nombre']) && $prod_cat_str === strtolower(trim($promo['categoria_nombre']))) {
                        $match = true;
                    } else if (!empty($promo['categoria_id']) && isset($cats_by_id[$promo['categoria_id']]) && $prod_cat_str === $cats_by_id[$promo['categoria_id']]) {
                        $match = true;
                    }
                }

                if ($match) {
                    $pct_eval = intval($promo['porcentaje_descuento']);
                    if ($costo_compra > 0 && $pv_orig_base > 0) {
                        $monto_desc_eval = $pv_orig_base * ($pct_eval / 100.0);
                        $pv_desc_eval = $pv_orig_base - $monto_desc_eval;
                        $neto_eval = $pv_desc_eval - $comision_val;

                        // Excluir de la promoción si genera pérdida financiera
                        if ($neto_eval <= $costo_compra) {
                            $match = false;
                        }
                    }
                }

                if ($match) {
                    $pct = intval($promo['porcentaje_descuento']);
                    if ($pct > $max_pct) {
                        $max_pct = $pct;
                        $nombre_promo = $promo['nombre'];
                    }
                }
            }

            if ($max_pct > 0) {
                $pv_orig = floatval($prod->precioventa);
                // Descuento redondeado a ENTERO estricto para evitar decimales
                $monto_descuento = round($pv_orig * ($max_pct / 100.0));
                $prod->precio_original = $pv_orig;
                $prod->precioventa = round($pv_orig - $monto_descuento, 2);
                $prod->descuento_porcentaje = $max_pct;
                $prod->descuento_monto = intval($monto_descuento);
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

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($productos));
    }

    /**
     * Busca vendedores activos con rol Vendedores (para asignar ventas en POS).
     */
    public function buscar_vendedores()
    {
        $q = trim($this->input->get('q') ?? '');

        $this->db->select('id, nombre, email, rol');
        $this->db->from('vendedores');
        $this->db->where('estado', 'activo');

        if ($q !== '') {
            $this->db->group_start();
            $this->db->like('nombre', $q);
            $this->db->or_like('email', $q);
            $this->db->group_end();
        }

        $this->db->order_by('nombre', 'ASC');
        $this->db->limit(20);
        $vendedores = $this->db->get()->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($vendedores));
    }

    // Endpoint para procesar una venta
    public function procesar($data = null) {
        if ($data === null || !is_array($data)) {
            $data = json_decode(file_get_contents('php://input'), true);
        }
        
        if (empty($data['cart']) || empty($data['total'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Datos de venta incompletos']));
        }

        // Validar vendedor asignado (rol Vendedores)
        $idVendedor = intval($data['vendedor'] ?? 0);
        if ($idVendedor <= 0) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Debe seleccionar un vendedor para la venta.']));
        }
        $vendedorRow = $this->db->where('id', $idVendedor)->where('estado', 'activo')->get('vendedores')->row();
        if (!$vendedorRow) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El vendedor seleccionado no existe o está inactivo.']));
        }
        // La validación de rol fue removida para permitir que cualquier usuario activo pueda registrar la venta.

        $conFactura = isset($data['con_factura']) ? (bool)$data['con_factura'] : false;
        $porcentajeImpuesto = isset($data['porcentaje_impuesto']) ? floatval($data['porcentaje_impuesto']) : 0;
        $impuestoFactor = $conFactura ? (1 + ($porcentajeImpuesto / 100)) : 1;

        $depositoId = intval($data['idneg'] ?? 1);

        // Validar precios unitarios según comisión de cada producto
        foreach ($data['cart'] as &$item) {
            $inv = null;
            // Buscar lote en el depósito actual por IDPROD (código SKU)
            if (!empty($item['idprod'])) {
                $inv = $this->db->where('idprod', $item['idprod'])
                                ->where('deposito', $depositoId)
                                ->order_by('precioventa', 'DESC')
                                ->get('inventarios')
                                ->row();
                if ($inv) {
                    $item['id'] = $inv->id; // Actualizar el ID del item para el resto del procesamiento con el del lote encontrado
                }
            }

            // 3. Buscar siempre en el catálogo maestro para los precios base
            $prodMaster = null;
            $idprodSearch = $item['idprod'] ?? ($inv ? $inv->idprod : null);
            if ($idprodSearch) {
                $prodMaster = $this->db->where('idprod', $idprodSearch)->get('productos')->row();
            }

            // 4. Si aún no existe el lote en este depósito, creamos un lote virtual con cantidad 0
            if (!$inv && $prodMaster) {
                    $nuevoLote = [
                        'idprod' => $prodMaster->idprod,
                        'descripcion' => $prodMaster->descripcion,
                        'marca' => $prodMaster->marca,
                        'idmarca' => $prodMaster->idmarca,
                        'categoria' => $prodMaster->categoria,
                        'idcategoria' => $prodMaster->idcategoria,
                        'unidad' => $prodMaster->unidad,
                        'cantidad_inicial' => 0,
                        'cantidad' => 0,
                        'preciolocal' => $prodMaster->preciolocal,
                        'precioventa' => $prodMaster->precioventa,
                        'preciomayor' => $prodMaster->nuevoprecio ?? $prodMaster->precioventa,
                        'comision' => $prodMaster->comision ?? 0,
                        'deposito' => $depositoId,
                        'imagenes' => $prodMaster->imagen
                    ];
                    $this->db->insert('inventarios', $nuevoLote);
                    $nuevoLoteId = $this->db->insert_id();
                    
                    $inv = $this->db->where('id', $nuevoLoteId)->get('inventarios')->row();
                    if ($inv) {
                        $item['id'] = $inv->id; // Actualizar el ID del item
                    }
                }

            if (!$inv) {
                return $this->output
                    ->set_status_header(400)
                    ->set_content_type('application/json')
                    ->set_output(json_encode(['error' => 'Producto no encontrado en inventario ni en el catálogo maestro.']));
            }

            // Determinar si es Depósito Central para aplicar validación sobre el precio mayorista (nuevoprecio)
            $depositoObj = $this->db->select('tipo_almacen')->where('id', intval($inv->deposito))->get('depositos')->row();
            $esMayorista = ($depositoObj && $depositoObj->tipo_almacen === 'Deposito_Central');
            
            $precioListaBase = floatval($prodMaster->precioventa);
            $comisionBase = $prodMaster->comision;
            $comision = floatval($comisionBase) * $impuestoFactor;
            $precioVenta = floatval($item['precioventa']);

            // Obtener monto de descuento promocional si aplica
            $descuentoMontoItem = $this->obtener_descuento_promocional_item($prodMaster, $item);
            $tienePromocionItem = !empty($item['tiene_promocion']) || !empty($item['nombre_promocion']) || $descuentoMontoItem > 0;
            
            if ($descuentoMontoItem > 0) {
                $precioListaBase = max(0, $precioListaBase - $descuentoMontoItem);
            }
            $precioLista = ceil($precioListaBase * $impuestoFactor);

            if ($comision > 0) {
                $precioMin = max(0, $precioLista - $comision);
                if (fmod($precioVenta, 1) != 0) {
                    return $this->output
                        ->set_status_header(400)
                        ->set_content_type('application/json')
                        ->set_output(json_encode([
                            'error' => 'El precio de venta para "' . ($item['descripcion'] ?? $inv->descripcion)
                                . '" debe ser un número entero sin decimales.',
                        ]));
                }
                if ($precioVenta < $precioMin - 0.05 || $precioVenta > (floatval($prodMaster->precioventa) * $impuestoFactor) + 0.05) {
                    return $this->output
                        ->set_status_header(400)
                        ->set_content_type('application/json')
                        ->set_output(json_encode([
                            'error' => 'Precio inválido para "' . ($item['descripcion'] ?? $inv->descripcion)
                                . '". Debe estar entre Bs ' . number_format($precioMin, 0, '.', '')
                                . ' y Bs ' . number_format($precioLista, 0, '.', '') . '.',
                        ]));
                }
            } else {
                // Si tiene promoción o el vendedor le aplicó un descuento válido dentro del precio con promoción
                $precioEsperado = $precioLista;
                if ($precioVenta < $precioEsperado - 0.05 && !$tienePromocionItem) {
                    return $this->output
                        ->set_status_header(400)
                        ->set_content_type('application/json')
                        ->set_output(json_encode([
                            'error' => 'El producto "' . ($item['descripcion'] ?? $inv->descripcion)
                                . '" no permite modificar el precio de venta.',
                        ]));
                }
            }
        }
        unset($item);

        // Iniciar transacción
        $this->db->trans_start();

        // Verificar si la venta proviene de una proforma ya pagada en la web
        $isWebPaid = false;
        if (!empty($data['origen_proforma_id'])) {
            $proforma = $this->db->where('idproforma', $data['origen_proforma_id'])->get('proformas')->row();
            if ($proforma && (strtolower($proforma->estado) === 'pagado' || strtolower($proforma->estado) === 'pagado 100%')) {
                $isWebPaid = true;
            }
        }

        $comment = $data['comentario'] ?? '';
        if ($isWebPaid) {
            $comment = trim($comment . ' [WEB_PAGADO]');
        }

        // 1. Guardar la Venta principal
        $idventa = uniqid(); // Usando uniqid() como en el sistema antiguo
        $ventaData = [
            'idneg' => $data['idneg'] ?? '1', // Depósito del usuario
            'idventa' => $idventa,
            'total' => $data['total'],
            'cliente' => $data['cliente'] ?? 'Cliente General',
            'telefono' => $data['telefono'] ?? '',
            'nit' => $data['nit'] ?? '',
            'formapago' => $data['formapago'] ?? 'Efectivo',
            'fecha' => date('Y-m-d H:i:s'),
            'vendedor' => intval($data['vendedor'] ?? 1), // ID del vendedor asignado a la venta
            'idusr' => intval($data['idusr'] ?? 1),         // ID del cajero que registra la venta
            'con_factura' => $conFactura ? 1 : 0,
            'porcentaje_aplicado' => $conFactura ? $porcentajeImpuesto : 0,
            'idcliente' => $data['idcliente'] ?? 0,
            'pago' => $data['pago'] ?? $data['total'],
            'saldo' => abs(($data['pago'] ?? $data['total']) - $data['total']),
            'pagomixto' => $data['pagomixto'] ?? null,
            'comentario' => $comment
        ];
        
        $this->db->insert('ventas', $ventaData);
        $nro_venta = $this->db->insert_id();

        // Vincular transaccion de QR BISA si existe
        if (!empty($data['qr_alias'])) {
            $this->db->where('alias', $data['qr_alias'])->update('bisa_qr_transacciones', [
                'id_venta' => $idventa
            ]);
        }

        // 2. Guardar Detalles de la Venta e Impactar Inventario (FIFO por lotes)
        foreach ($data['cart'] as $item) {
            $inv = $this->db->where('id', intval($item['id']))->get('inventarios')->row();
            if (!$inv) {
                continue;
            }

            $idprod = $inv->idprod;
            $depositoId = intval($inv->deposito);
            $cantA_Vender = floatval($item['cantidad']);

            // Obtener producto master para el ID de kardex e inventario_stock
            $prodMaster = $this->db->where('idprod', $idprod)->get('productos')->row();
            $prodIdMaster = $prodMaster ? $prodMaster->id : 0;

            // Determinar la comisión real unitaria e información del descuento promocional si aplica
            $promoInfoItem = $this->obtener_descuento_promocional_info($prodMaster, $item);
            $descuentoPromo = $promoInfoItem['monto'];
            $obsPromoItem = '';
            if ($promoInfoItem['porcentaje'] > 0 || $descuentoPromo > 0) {
                $pctTxt = intval($promoInfoItem['porcentaje']);
                if ($pctTxt <= 0 && $prodMaster && floatval($prodMaster->precioventa) > 0) {
                    $pctTxt = round(($descuentoPromo / floatval($prodMaster->precioventa)) * 100);
                }
                $obsPromoItem = "Descuento Promocional: " . $pctTxt . "% OFF";
                if (!empty($promoInfoItem['nombre'])) {
                    $obsPromoItem .= " (" . $promoInfoItem['nombre'] . ")";
                }
            }

            $precioEsperadoBase = $prodMaster ? max(0, floatval($prodMaster->precioventa) - $descuentoPromo) : 0;
            $precioEsperado = $conFactura ? ceil($precioEsperadoBase * $impuestoFactor) : $precioEsperadoBase;
            $precioVentaReal = floatval($item['precioventa']);
            $comisionBase = $prodMaster ? floatval($prodMaster->comision) : 0;
            $comisionBaseFinal = $conFactura ? ($comisionBase * $impuestoFactor) : $comisionBase;
            
            $rebaja = max(0, $precioEsperado - $precioVentaReal);
            $comisionUnitariaReal = max(0, $comisionBaseFinal - $rebaja);


            // Restar stock de la tabla de conciliación consolidada inventario_stock
            if ($prodIdMaster > 0) {
                $this->db->where('producto_id', $prodIdMaster);
                $this->db->where('almacen_id', $depositoId);
                $this->db->set('stock', 'stock - ' . $cantA_Vender, false);
                $this->db->update('inventario_stock');
            }

            // Buscar lotes activos en el depósito ordenados por fecha_ingreso (FIFO)
            $this->db->from('inventarios');
            $this->db->where('idprod', $idprod);
            $this->db->where('deposito', $depositoId);
            $this->db->where('cantidad >', 0);
            $this->db->order_by('fecha_ingreso', 'ASC');
            $lotes = $this->db->get()->result();

            $cantPendiente = $cantA_Vender;

            foreach ($lotes as $lote) {
                if ($cantPendiente <= 0) {
                    break;
                }

                $descuento = min($lote->cantidad, $cantPendiente);

                // A. Restar de la cantidad de este lote
                $this->db->where('id', $lote->id);
                $this->db->set('cantidad', 'cantidad - ' . $descuento, false);
                $this->db->update('inventarios');

                // B. Insertar detalle de la venta para este lote
                $detalleData = [
                    'idventa' => $idventa,
                    'idprod' => $lote->idprod, // Código del producto, NO id del lote
                    'inventario_id' => $lote->id, // ID específico del lote descontado
                    'preciolocal' => $lote->preciolocal, // Costo de compra del lote
                    'precioventa' => $item['precioventa'],
                    'preciofinal' => $item['precioventa'],
                    'cuantos' => $descuento,
                    'comision' => $comisionUnitariaReal * $descuento,
                    'descripcion' => $item['descripcion'] ?? $lote->descripcion,
                    'vendedor' => $data['vendedor'] ?? 1,
                    'pagocomision' => null,
                    'observaciones' => $obsPromoItem,
                    'cierre' => null
                ];
                $this->db->insert('detalleventas', $detalleData);

                // C. Registrar en Kardex salida de este lote
                if ($prodIdMaster > 0) {
                    $this->db->insert('kardex', [
                        'producto_id' => $prodIdMaster,
                        'almacen_id' => $depositoId,
                        'lote_id' => $lote->id,
                        'cantidad' => $descuento,
                        'concepto' => 'VENTA',
                        'tipo_movimiento' => 'EGRESO',
                        'referencia_id' => $nro_venta
                    ]);
                }

                $cantPendiente -= $descuento;
            }

            // Si queda saldo pendiente (stock negativo)
            if ($cantPendiente > 0) {
                // Restar el remanente del lote enviado por el cliente originalmente
                $this->db->where('id', $inv->id);
                $this->db->set('cantidad', 'cantidad - ' . $cantPendiente, false);
                $this->db->update('inventarios');

                $detalleData = [
                    'idventa' => $idventa,
                    'idprod' => $inv->idprod,
                    'inventario_id' => $inv->id, // ID del lote
                    'preciolocal' => $inv->preciolocal,
                    'precioventa' => $item['precioventa'],
                    'preciofinal' => $item['precioventa'],
                    'cuantos' => $cantPendiente,
                    'comision' => $comisionUnitariaReal * $cantPendiente,
                    'descripcion' => $item['descripcion'] ?? $inv->descripcion,
                    'vendedor' => $data['vendedor'] ?? 1,
                    'pagocomision' => null,
                    'observaciones' => !empty($obsPromoItem) ? "Venta sobregirada | " . $obsPromoItem : 'Venta sobregirada (stock negativo)',
                    'cierre' => null
                ];
                $this->db->insert('detalleventas', $detalleData);

                if ($prodIdMaster > 0) {
                    $this->db->insert('kardex', [
                        'producto_id' => $prodIdMaster,
                        'almacen_id' => $depositoId,
                        'lote_id' => $inv->id,
                        'cantidad' => $cantPendiente,
                        'concepto' => 'VENTA',
                        'tipo_movimiento' => 'EGRESO',
                        'referencia_id' => $nro_venta
                    ]);
                }
            }
        }

        // 3. Actualizar estado de proforma si la venta viene de una
        if (!empty($data['origen_proforma_id'])) {
            $this->db->where('idproforma', $data['origen_proforma_id']);
            $this->db->update('proformas', [
                'estado' => 'Vendido',
                'formapago' => $data['formapago'] ?? 'Efectivo',
                'fecha_venta' => date('Y-m-d H:i:s')
            ]);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al procesar la venta']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'message' => 'Venta procesada exitosamente', 
                'idventa' => $idventa,
                'nro_venta' => $nro_venta
            ]));
    }

    /**
     * Guarda una cotización/proforma sin descontar inventario.
     */
    public function guardar_proforma() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || !isset($data['cart']) || empty($data['cart'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Datos de proforma inválidos o carrito vacío']));
        }

        $conFactura = isset($data['con_factura']) ? (bool)$data['con_factura'] : false;
        $porcentajeImpuesto = isset($data['porcentaje_impuesto']) ? floatval($data['porcentaje_impuesto']) : 0;
        $impuestoFactor = $conFactura ? (1 + ($porcentajeImpuesto / 100)) : 1;

        $this->db->trans_start();

        $idproforma = uniqid(); 
        $proformaData = [
            'idneg' => $data['idneg'] ?? '1',
            'idproforma' => $idproforma,
            'total' => $data['total'],
            'cliente' => $data['cliente'] ?? 'Cliente General',
            'telefono' => $data['telefono'] ?? '',
            'nit' => $data['nit'] ?? '',
            'complemento' => $data['complemento'] ?? '',
            'formapago' => $data['formapago'] ?? 'Efectivo',
            'fecha' => date('Y-m-d H:i:s'),
            'vendedor' => intval($data['vendedor'] ?? 1),
            'idusr' => intval($data['idusr'] ?? 1),
            'idcliente' => $data['idcliente'] ?? 0,
            'pago' => $data['pago'] ?? $data['total'],
            'saldo' => abs(($data['pago'] ?? $data['total']) - $data['total']),
            'pagomixto' => $data['pagomixto'] ?? null,
            'comentario' => $data['comentario'] ?? '',
            'tipo_proforma' => $data['tipo_proforma'] ?? 'normal',
            'con_factura' => $conFactura ? 1 : 0,
            'porcentaje_aplicado' => $conFactura ? $porcentajeImpuesto : 0
        ];
        
        $this->db->insert('proformas', $proformaData);
        $nro_proforma = $this->db->insert_id();

        foreach ($data['cart'] as $item) {
            $detalleData = [
                'idproforma' => $idproforma,
                'idprod' => !empty($item['idprod']) ? $item['idprod'] : $item['id'],
                'preciolocal' => $item['preciolocal'] ?? 0,
                'precioventa' => $item['precioventa'],
                'preciofinal' => $item['precioventa'],
                'cuantos' => $item['cantidad'],
                'descripcion' => $item['descripcion'],
                'vendedor' => $data['vendedor'] ?? 1,
                'comision' => $item['comision'] ?? 0,
                'pagocomision' => null,
                'observaciones' => '',
                'cierre' => null
            ];
            $this->db->insert('detalleproformas', $detalleData);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al guardar la proforma']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'message' => 'Proforma guardada exitosamente', 
                'idproforma' => $idproforma,
                'nro_proforma' => $nro_proforma
            ]));
    }

    private function expirar_proformas_vencidas() {
        $config_app = $this->db->get('configapp')->row();
        $dias = (isset($config_app->dias_proforma) && intval($config_app->dias_proforma) > 0) ? intval($config_app->dias_proforma) : 1;
        
        // Expirar proformas que sobrepasen el plazo de días configurado
        $this->db->query("UPDATE proformas SET estado = 'Vencido' WHERE estado = 'Pendiente' AND DATE(fecha) < DATE_SUB(CURDATE(), INTERVAL $dias DAY)");

        // Restaurar proformas dentro del plazo configurado
        $this->db->query("UPDATE proformas SET estado = 'Pendiente' WHERE estado = 'Vencido' AND DATE(fecha) >= DATE_SUB(CURDATE(), INTERVAL $dias DAY)");
    }

    /**
     * Lista todas las proformas generadas en el sistema.
     */
    public function listar_proformas() {
        // Expirar proformas respetando dias_proforma de la configuración
        $this->expirar_proformas_vencidas();

        $fechaInicio = $this->input->get('inicio');
        $fechaFin = $this->input->get('fin');
        $sucursal_activa = $this->input->get_request_header('X-Active-Branch', TRUE);
        $tipoProforma = $this->input->get('tipo') ?? 'normal';
        
        $this->db->select('p.id, p.idproforma, p.fecha, p.cliente, p.total, p.estado, p.comentario, p.tipo_proforma, u.nombre AS usuario, d.nombre AS sucursal');
        $this->db->from('proformas p');
        $this->db->join('vendedores u', 'p.idusr = u.id', 'left');
        $this->db->join('depositos d', 'p.idneg = d.id', 'left');
        
        $this->db->where('p.tipo_proforma', $tipoProforma);
        
        if (!empty($sucursal_activa)) {
            $this->db->where('p.idneg', $sucursal_activa);
        }

        if (!empty($fechaInicio)) {
            $this->db->where('DATE(p.fecha) >=', $fechaInicio);
        }
        if (!empty($fechaFin)) {
            $this->db->where('DATE(p.fecha) <=', $fechaFin);
        }

        $this->db->order_by('p.fecha', 'DESC');
        
        $proformas = $this->db->get()->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($proformas));
    }

    /**
     * Reporte detallado de proformas con múltiples filtros.
     */
    public function reporte_proformas() {
        // Expirar proformas respetando dias_proforma de la configuración
        $this->expirar_proformas_vencidas();

        $fechaInicio = $this->input->get('inicio');
        $fechaFin = $this->input->get('fin');
        $sucursal = $this->input->get('sucursal');
        $estado = $this->input->get('estado');

        $this->db->select('p.id, p.idproforma, p.fecha, p.cliente, p.nit, p.complemento, p.telefono, p.total, p.estado, u.nombre AS vendedor_nombre, usr.name AS cajero_nombre, d.nombre AS sucursal_nombre');
        $this->db->from('proformas p');
        $this->db->join('vendedores u', 'p.vendedor = u.id', 'left');
        $this->db->join('users usr', 'p.idusr = usr.id', 'left');
        $this->db->join('depositos d', 'p.idneg = d.id', 'left');

        if (!empty($fechaInicio)) {
            $this->db->where('DATE(p.fecha) >=', $fechaInicio);
        }
        if (!empty($fechaFin)) {
            $this->db->where('DATE(p.fecha) <=', $fechaFin);
        }
        if (!empty($sucursal)) {
            $this->db->where('p.idneg', $sucursal);
        }
        if (!empty($estado)) {
            $this->db->where('p.estado', $estado);
        }

        $this->db->order_by('p.fecha', 'DESC');
        $this->db->limit(3000);
        
        $reporte = $this->db->get()->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($reporte));
    }

    /**
     * Reporte detallado de ventas con múltiples filtros
     */
    public function reporte_ventas() {
        $fechaInicio = $this->input->get('inicio');
        $fechaFin = $this->input->get('fin');
        $sucursal = $this->input->get('sucursal');
        $vendedor = $this->input->get('vendedor');
        $cliente = $this->input->get('cliente');
        $producto = $this->input->get('producto');

        $this->db->select('v.id AS nro_venta, v.idventa, v.fecha, v.cliente, v.nit, v.comentario, v.formapago, v.pagomixto, d.nombre AS sucursal, u.nombre AS vendedor_nombre, COALESCE(i.idprod, p_old.idprod, dv.idprod) AS codigoprod, dv.idprod AS codigo, dv.descripcion AS producto, COALESCE(m_inv.nombre, m_p.nombre, m_p_old.nombre, NULLIF(i.marca, ""), NULLIF(p.marca, ""), NULLIF(p_old.marca, ""), "Sin Marca") AS marca, COALESCE(i.idmarca, p.idmarca, p_old.idmarca, 0) AS idmarca, COALESCE(pr_inv.nombre, pr_p.nombre, pr_p_old.nombre, NULLIF(i.proveedor, ""), NULLIF(p.proveedor, ""), NULLIF(p_old.proveedor, ""), "Sin Proveedor") AS proveedor, COALESCE(i.proveedor, p.proveedor, p_old.proveedor, 0) AS idproveedor, dv.cuantos AS cantidad, dv.preciolocal AS precio_compra, dv.precioventa AS precio_unitario, (dv.cuantos * dv.precioventa) AS subtotal, v.estado, v.motivo_anulacion, v.usuario_anulacion, dv.comision AS comision_pagada, COALESCE(p.comision, p_old.comision) AS comision_producto');
        $this->db->from('ventas v');
        $this->db->join('detalleventas dv', 'v.idventa = dv.idventa', 'inner');
        // Unir con inventarios (lotes) para obtener el SKU real en los nuevos datos
        $this->db->join('inventarios i', 'dv.idprod = i.id', 'left');
        // Unir con productos a través del lote
        $this->db->join('productos p', 'i.idprod = p.idprod', 'left');
        // Unir con productos directamente por si es un registro antiguo (donde dv.idprod ya era el SKU)
        $this->db->join('productos p_old', 'dv.idprod = p_old.idprod', 'left');
        // Unir con marcas
        $this->db->join('marcas m_inv', 'i.idmarca = m_inv.id', 'left');
        $this->db->join('marcas m_p', 'p.idmarca = m_p.id', 'left');
        $this->db->join('marcas m_p_old', 'p_old.idmarca = m_p_old.id', 'left');
        // Unir con proveedores
        $this->db->join('proveedores pr_inv', 'i.proveedor = pr_inv.id', 'left');
        $this->db->join('proveedores pr_p', 'p.proveedor = pr_p.id', 'left');
        $this->db->join('proveedores pr_p_old', 'p_old.proveedor = pr_p_old.id', 'left');
        $this->db->join('depositos d', 'v.idneg = d.id', 'left');
        $this->db->join('vendedores u', 'v.vendedor = u.id', 'left');

        if (!empty($fechaInicio)) {
            $this->db->where('DATE(v.fecha) >=', $fechaInicio);
        }
        if (!empty($fechaFin)) {
            $this->db->where('DATE(v.fecha) <=', $fechaFin);
        }
        if (!empty($sucursal)) {
            $this->db->where('v.idneg', $sucursal);
        }
        if (!empty($vendedor)) {
            if (is_numeric($vendedor)) {
                $this->db->where('v.vendedor', $vendedor);
            } else {
                $this->db->like('u.nombre', $vendedor);
            }
        }
        if (!empty($cliente)) {
            $this->db->group_start();
            $this->db->like('v.cliente', $cliente);
            $this->db->or_like('v.nit', $cliente);
            $this->db->group_end();
        }
        if (!empty($producto)) {
            $this->db->group_start();
            $this->db->like('dv.descripcion', $producto);
            $this->db->or_like('dv.idprod', $producto);
            $this->db->group_end();
        }

        $this->db->order_by('v.fecha', 'DESC');
        $this->db->limit(2000);
        
        $reporte = $this->db->get()->result();

        // Mapas de fallback para IDs numéricos de marcas y proveedores
        $marcasMap = [];
        $marcasQuery = $this->db->select('id, nombre')->get('marcas')->result();
        foreach ($marcasQuery as $m) {
            $marcasMap[$m->id] = $m->nombre;
        }

        $proveedoresMap = [];
        $provQuery = $this->db->select('id, nombre')->get('proveedores')->result();
        foreach ($provQuery as $pr) {
            $proveedoresMap[$pr->id] = $pr->nombre;
        }

        foreach ($reporte as &$row) {
            // Resolver nombre de Marca si es ID numérico o irreconocible
            if (empty($row->marca) || $row->marca === 'Sin Marca' || is_numeric($row->marca)) {
                $mId = is_numeric($row->marca) ? intval($row->marca) : intval($row->idmarca ?? 0);
                if ($mId > 0 && isset($marcasMap[$mId])) {
                    $row->marca = $marcasMap[$mId];
                }
            }

            // Resolver nombre de Proveedor si es ID numérico o irreconocible
            if (empty($row->proveedor) || $row->proveedor === 'Sin Proveedor' || is_numeric($row->proveedor)) {
                $pId = is_numeric($row->proveedor) ? intval($row->proveedor) : intval($row->idproveedor ?? 0);
                if ($pId > 0 && isset($proveedoresMap[$pId])) {
                    $row->proveedor = $proveedoresMap[$pId];
                } else if (is_numeric($row->proveedor)) {
                    $row->proveedor = 'Sin Proveedor';
                }
            }
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($reporte));
    }

    /**
     * Reporte general de ventas (1 fila por venta, sin detalles)
     */
    public function reporte_general() {
        $fechaInicio = $this->input->get('inicio');
        $fechaFin = $this->input->get('fin');
        $sucursal = $this->input->get('sucursal');
        $vendedor = $this->input->get('vendedor');
        $cliente = $this->input->get('cliente');
        $cajero = $this->input->get('cajero');

        $this->db->select('v.id AS nro_venta, v.idventa, v.fecha, v.cliente, v.nit, d.nombre AS sucursal, u.nombre AS vendedor_nombre, usr.nombre AS cajero_nombre, v.total, v.pago, v.saldo, v.formapago, v.pagomixto, v.estado, v.motivo_anulacion, v.usuario_anulacion');
        $this->db->from('ventas v');
        $this->db->join('depositos d', 'v.idneg = d.id', 'left');
        $this->db->join('vendedores u', 'v.vendedor = u.id', 'left');
        $this->db->join('vendedores usr', 'v.idusr = usr.id', 'left');

        if (!empty($fechaInicio)) {
            $this->db->where('DATE(v.fecha) >=', $fechaInicio);
        }
        if (!empty($fechaFin)) {
            $this->db->where('DATE(v.fecha) <=', $fechaFin);
        }
        if (!empty($sucursal)) {
            $this->db->where('v.idneg', $sucursal);
        }
        if (!empty($vendedor)) {
            if (is_numeric($vendedor)) {
                $this->db->where('v.vendedor', $vendedor);
            } else {
                $this->db->like('u.nombre', $vendedor);
            }
        }
        if (!empty($cajero)) {
            if (is_numeric($cajero)) {
                $this->db->where('v.idusr', $cajero);
            } else {
                $this->db->like('usr.name', $cajero);
            }
        }
        if (!empty($cliente)) {
            $this->db->group_start();
            $this->db->like('v.cliente', $cliente);
            $this->db->or_like('v.nit', $cliente);
            $this->db->group_end();
        }

        $this->db->order_by('v.fecha', 'DESC');
        $this->db->limit(3000);
        
        $reporte = $this->db->get()->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($reporte));
    }

    /**
     * Actualiza el vendedor de una venta y sus detalles.
     */
    public function actualizar_vendedor() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $idventa = $data['idventa'] ?? null;
        $vendedor_id = $data['vendedor_id'] ?? null;

        if (empty($idventa) || empty($vendedor_id)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Faltan datos requeridos.']));
        }

        $this->db->trans_start();

        // Actualizar en ventas
        $this->db->where('idventa', $idventa);
        $this->db->update('ventas', ['vendedor' => $vendedor_id]);

        // Actualizar en detalleventas
        $this->db->where('idventa', $idventa);
        $this->db->update('detalleventas', ['vendedor' => $vendedor_id]);

        // Actualizar en proformas si corresponde
        $venta = $this->db->where('idventa', $idventa)->get('ventas')->row();
        if ($venta && !empty($venta->idproforma)) {
            $this->db->where('idproforma', $venta->idproforma);
            $this->db->update('proformas', ['vendedor' => $vendedor_id]);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al actualizar el vendedor.']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success']));
    }

    /**
     * Anula una venta, revierte el stock del inventario y registra la operacion en Kardex.
     */
    public function anular_venta() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $idventa = $data['idventa'] ?? null;
        $motivo = $data['motivo'] ?? '';
        $usuario = $data['usuario'] ?? '';

        if (empty($idventa)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'ID de venta requerido']));
        }

        if (empty($motivo)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El motivo de la anulación es requerido']));
        }

        if (empty($usuario)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El usuario que anula es requerido']));
        }

        // Obtener la venta
        $venta = $this->db->where('idventa', $idventa)->get('ventas')->row();
        if (!$venta) {
            return $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Venta no encontrada']));
        }

        if ($venta->estado === 'ANULADO') {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'La venta ya se encuentra anulada']));
        }

        $this->db->trans_start();

        // 1. Obtener los detalles de la venta
        $detalles = $this->db->where('idventa', $idventa)->get('detalleventas')->result();
        $depositoId = intval($venta->idneg);
        $nro_venta = $venta->id;

        foreach ($detalles as $det) {
            $idprod = $det->idprod;
            $cantA_Revertir = floatval($det->cuantos);
            $loteId = intval($det->inventario_id);

            // Obtener producto master para inventario_stock
            $prodMaster = $this->db->where('idprod', $idprod)->get('productos')->row();
            $prodIdMaster = $prodMaster ? $prodMaster->id : 0;

            // Restablecer stock consolidado en inventario_stock
            if ($prodIdMaster > 0) {
                $this->db->where('producto_id', $prodIdMaster);
                $this->db->where('almacen_id', $depositoId);
                $this->db->set('stock', 'stock + ' . $cantA_Revertir, false);
                $this->db->update('inventario_stock');
            }

            // Aumentar la cantidad en el lote original de inventarios
            if ($loteId > 0) {
                $this->db->where('id', $loteId);
                $this->db->set('cantidad', 'cantidad + ' . $cantA_Revertir, false);
                $this->db->update('inventarios');
            }

            // Registrar en Kardex la anulación como un INGRESO
            if ($prodIdMaster > 0) {
                $this->db->insert('kardex', [
                    'producto_id' => $prodIdMaster,
                    'almacen_id' => $depositoId,
                    'lote_id' => $loteId > 0 ? $loteId : null,
                    'cantidad' => $cantA_Revertir,
                    'concepto' => 'ANULACION_VENTA',
                    'tipo_movimiento' => 'INGRESO',
                    'referencia_id' => $nro_venta
                ]);
            }
        }

        // 2. Marcar la venta como anulada
        $this->db->where('idventa', $idventa)->update('ventas', [
            'estado' => 'ANULADO',
            'motivo_anulacion' => $motivo,
            'usuario_anulacion' => $usuario
        ]);

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al anular la venta en base de datos']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['message' => 'Venta anulada exitosamente y stock retornado']));
    }


    /**
     * Busca una proforma por su ID.
     */
    public function buscar_proforma() {
        $nro = $this->input->get('nro');
        $tipoProforma = $this->input->get('tipo') ?? 'normal';
        if (!$nro) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'Número de proforma requerido']));
        }

        $proforma = $this->db->group_start()
            ->where('id', $nro)
            ->or_where('idproforma', $nro)
            ->group_end()
            ->where('tipo_proforma', $tipoProforma)
            ->get('proformas')->row();

        if (!$proforma) {
            $proforma = $this->db->group_start()
                ->where('id', $nro)
                ->or_where('idproforma', $nro)
                ->group_end()
                ->get('proformas')->row();
        }

        if (!$proforma) {
            return $this->output->set_status_header(404)->set_output(json_encode(['error' => 'Proforma no encontrada']));
        }

        // Expirar o restaurar estado según el tiempo de validez configurado (dias_proforma)
        $config_app = $this->db->get('configapp')->row();
        $dias_proforma = (isset($config_app->dias_proforma) && intval($config_app->dias_proforma) > 0) ? intval($config_app->dias_proforma) : 1;
        $fecha_proforma = date('Y-m-d', strtotime($proforma->fecha));
        $fecha_limite = date('Y-m-d', strtotime($fecha_proforma . " + $dias_proforma days"));

        if ($proforma->estado === 'Pendiente' && date('Y-m-d') > $fecha_limite) {
            $this->db->where('id', $proforma->id)->update('proformas', ['estado' => 'Vencido']);
            $proforma->estado = 'Vencido';
        } else if ($proforma->estado === 'Vencido' && date('Y-m-d') <= $fecha_limite) {
            $this->db->where('id', $proforma->id)->update('proformas', ['estado' => 'Pendiente']);
            $proforma->estado = 'Pendiente';
        }

        $mode = $this->input->get('mode');
        if ($proforma->estado === 'Vencido' && $mode !== 'view') {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'La proforma está vencida y no puede ser recuperada']));
        }
        
        // Solo bloquear si ya fue Vendido. Permitir recuperar proformas 'Pagado' (por QR web) para convertirlas a venta y descontar stock.
        if ($proforma->estado === 'Vendido' && $mode !== 'view') {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'La proforma ya ha sido procesada y convertida a venta.']));
        }

        $vendedorRow = $this->db->select('nombre')->where('id', $proforma->vendedor)->get('vendedores')->row();
        if (!$vendedorRow && !empty($proforma->idusr)) {
            $vendedorRow = $this->db->select('nombre')->where('id', $proforma->idusr)->get('vendedores')->row();
            if ($vendedorRow) {
                $proforma->vendedor = $proforma->idusr;
            }
        }
        $proforma->vendedor_nombre = $vendedorRow ? $vendedorRow->nombre : '';

        // Extraer link de google maps y costo del comentario
        $proforma->transporte_lat = null;
        $proforma->transporte_lng = null;
        $proforma->costo_transporte = 0;

        $comentario = $proforma->comentario ?? '';

        // Extraer costo de transporte
        if (preg_match('/Costo de Env[ií]o:\s*Bs\.\s*([\d.]+)/i', $comentario, $costMatch)) {
            $proforma->costo_transporte = floatval($costMatch[1]);
        }

        // Buscar coordenadas directas en el comentario
        $decodedComentario = urldecode($comentario);
        if (preg_match('/q=([-\d.]+)\s*,\s*([-\d.]+)/i', $decodedComentario, $coordMatch) || 
            preg_match('/Ubicaci[oó]n\/Mapa:\s*([-\d.]+)\s*,\s*([-\d.]+)/i', $decodedComentario, $coordMatch) ||
            preg_match('/@([-\d.]+)\s*,\s*([-\d.]+)/i', $decodedComentario, $coordMatch) ||
            preg_match('/\/place\/([-\d.]+)\s*,\s*([-\d.]+)/i', $decodedComentario, $coordMatch) ||
            preg_match('/\/search\/([-\d.]+)\s*,\s*([-\d.]+)/i', $decodedComentario, $coordMatch)) {
            
            $proforma->transporte_lat = floatval($coordMatch[1]);
            $proforma->transporte_lng = floatval($coordMatch[2]);
        } else {
            // Si es un link corto de maps.app.goo.gl, resolverlo
            if (preg_match('/(https?:\/\/maps\.app\.goo\.gl\/[a-zA-Z0-9_-]+)/i', $comentario, $linkMatch)) {
                $shortUrl = $linkMatch[1];
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $shortUrl);
                curl_setopt($ch, CURLOPT_HEADER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                $response = curl_exec($ch);
                $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                curl_close($ch);

                if ($finalUrl) {
                    $finalUrlDecoded = urldecode($finalUrl);
                    if (preg_match('/q=([-\d.]+)\s*,\s*([-\d.]+)/i', $finalUrlDecoded, $coordMatch) ||
                        preg_match('/@([-\d.]+)\s*,\s*([-\d.]+)/i', $finalUrlDecoded, $coordMatch) ||
                        preg_match('/\/place\/([-\d.]+)\s*,\s*([-\d.]+)/i', $finalUrlDecoded, $coordMatch) ||
                        preg_match('/\/search\/([-\d.]+)\s*,\s*([-\d.]+)/i', $finalUrlDecoded, $coordMatch)) {
                        
                        $proforma->transporte_lat = floatval($coordMatch[1]);
                        $proforma->transporte_lng = floatval($coordMatch[2]);
                    }
                }
            }
        }

        $detalles = $this->db->where('idproforma', $proforma->idproforma)->get('detalleproformas')->result();

        // Enriquecer detalles con información del inventario (código, precio original, comisión del maestro y stock disponible)
        foreach ($detalles as &$det) {
            $prod = $this->db->select('idprod, cantidad')->where('id', $det->idprod)->get('inventarios')->row();
            
            $prodCode = null;
            if ($prod) {
                $prodCode = $prod->idprod;
            } else {
                $prod2 = $this->db->select('idprod')->where('id', $det->idprod)->get('productos')->row();
                if ($prod2) {
                    $prodCode = $prod2->idprod;
                } else {
                    $prodCode = $det->idprod;
                }
            }

            $det->codigo = $prodCode;

            // Consultar producto en la tabla de productos para obtener datos maestros (precioventa, comision)
            $prodData = $this->db->select('precioventa, comision')->where('idprod', $prodCode)->get('productos')->row();

            $det->precioventaOriginal = $prodData ? floatval($prodData->precioventa) : floatval($det->precioventa);
            
            // Si la comisión en la proforma es 0 o vacía, recuperar la comisión configurada en el producto maestro
            if (empty($det->comision) || floatval($det->comision) <= 0) {
                $det->comision = $prodData ? floatval($prodData->comision ?? 0) : 0;
            } else {
                $det->comision = floatval($det->comision);
            }

            $det->stockMaximo = $prod ? floatval($prod->cantidad) : floatval($det->cuantos);
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'proforma' => $proforma,
                'detalles' => $detalles
            ]));
    }

    /**
     * Guarda la apertura de caja en la base de datos.
     */
    public function guardar_apertura() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || !isset($data['apertura'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Monto de apertura no especificado']));
        }

        $deposito = $data['deposito'] ?? 'General';
        $aperturaMonto = floatval($data['apertura']);

        $aperturaData = [
            'deposito' => $deposito,
            'fecha'    => date('Y-m-d'),
            'apertura' => $aperturaMonto,
            'gastado'  => 0,
            'saldo'    => $aperturaMonto
        ];

        $this->db->insert('aperturacajachicas', $aperturaData);
        $insert_id = $this->db->insert_id();

        // Registrar estado activo en archivo JSON del servidor
        $filePath = APPPATH . 'cache/caja_status_' . $deposito . '.json';
        $nowStr = date('d/m/Y, h:i:s a');
        $jsonStatus = [
            'cajaAbierta' => true,
            'id_apertura' => $insert_id,
            'montoApertura' => $aperturaMonto,
            'fechaApertura' => $nowStr,
            'fecha_apertura_server' => date('Y-m-d H:i:s')
        ];
        @file_put_contents($filePath, json_encode($jsonStatus));

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Apertura registrada en DB', 'id' => $insert_id]));
    }

    /**
     * Guarda el cierre de caja en la base de datos.
     */
    public function guardar_cierre() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || !isset($data['declarado'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Monto declarado no especificado']));
        }

        $deposito = $data['deposito'] ?? 'General';

        $cierreData = [
            'total'       => floatval($data['declarado']),
            'observacion' => $data['observacion'] ?? 'Cierre de caja',
            'fecha'       => date('Y-m-d H:i:s'),
            'deposito'    => $deposito,
            'idusr'       => $data['idusr'] ?? 1,
            'respaldos'   => isset($data['respaldos']) ? json_encode($data['respaldos']) : ''
        ];

        $this->db->insert('cierres', $cierreData);
        $insert_id = $this->db->insert_id();

        // Marcar caja como cerrada en archivo JSON del servidor
        $filePath = APPPATH . 'cache/caja_status_' . $deposito . '.json';
        $jsonStatus = [
            'cajaAbierta' => false
        ];
        @file_put_contents($filePath, json_encode($jsonStatus));

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Cierre de caja guardado en DB', 'id' => $insert_id]));
    }

    /**
     * Verifica si existe una apertura de caja activa para el depósito y recupera su estado.
     */
    public function obtener_estado_caja() {
        $deposito = $this->input->get('deposito') ?? '1';
        $usuario_id = $this->input->get('usuario_id');

        $filePath = APPPATH . 'cache/caja_status_' . $deposito . '.json';

        $this->db->where('deposito', $deposito);
        $this->db->order_by('id', 'DESC');
        $last_apertura = $this->db->get('aperturacajachicas', 1)->row();

        $this->db->where('deposito', $deposito);
        $this->db->order_by('id', 'DESC');
        $last_cierre = $this->db->get('cierres', 1)->row();

        $cajaAbierta = false;
        $montoApertura = 0.0;
        $fechaApertura = '';
        $shiftSales = [];
        $fecha_ap_server = null;

        // 1. Buscar en la nueva tabla sesiones_caja si hay un turno abierto para el usuario
        $caja = null;
        if ($usuario_id) {
            $caja = $this->db->where('usuario_id', $usuario_id)
                             ->where('estado', 'Abierta')
                             ->get('sesiones_caja')
                             ->row();
        } else {
            // Fallback: buscar la última caja abierta activa de cualquier usuario en el sistema
            $caja = $this->db->where('estado', 'Abierta')
                             ->order_by('id', 'DESC')
                             ->get('sesiones_caja')
                             ->row();
        }

        if ($caja) {
            $cajaAbierta = true;
            $montoApertura = floatval($caja->monto_apertura);
            
            // Formatear la fecha de apertura para compatibilidad con el frontend antiguo (DD/MM/YYYY, HH:MM:SS)
            $fechaApertura = date('d/m/Y, h:i:s a', strtotime($caja->fecha_apertura));
            $fecha_ap_server = $caja->fecha_apertura;
        } else {
            // Si no hay sesiones activas en la nueva tabla, usar el archivo de estado compartido y fallback tradicional
            
            // 1. Intentar leer desde el archivo de estado compartido
            if (file_exists($filePath)) {
                $jsonStatus = json_decode(file_get_contents($filePath), true);
                if ($jsonStatus && isset($jsonStatus['cajaAbierta']) && $jsonStatus['cajaAbierta']) {
                    $cajaAbierta = true;
                    $montoApertura = floatval($jsonStatus['montoApertura']);
                    $fechaApertura = $jsonStatus['fechaApertura'];
                    $fecha_ap_server = $jsonStatus['fecha_apertura_server'] ?? null;
                }
            }

            // 2. Fallback Inteligente (si el archivo no existe o dice que está cerrado pero en BD hay un turno abierto)
            if (!$cajaAbierta && $last_apertura) {
                $isFallbackOpen = false;
                if (!$last_cierre) {
                    $isFallbackOpen = true;
                } else {
                    $fecha_ap = $last_apertura->fecha;
                    $fecha_ci = substr($last_cierre->fecha, 0, 10);
                    
                    if ($fecha_ap > $fecha_ci) {
                        $isFallbackOpen = true;
                    } else if ($fecha_ap === $fecha_ci) {
                        // Si son del mismo día, comparamos la apertura para ver si el último cierre pertenece a otra apertura previa
                        $obs = $last_cierre->observacion ?? '';
                        $apertura_str = 'Apertura inicial: Bs ' . number_format($last_apertura->apertura, 2, '.', '');
                        if (strpos($obs, $apertura_str) === false) {
                            $isFallbackOpen = true;
                        }
                    }
                }

                if ($isFallbackOpen) {
                    $cajaAbierta = true;
                    $montoApertura = floatval($last_apertura->apertura);
                    
                    $dateParts = explode('-', $last_apertura->fecha);
                    if (count($dateParts) === 3) {
                        $fechaApertura = $dateParts[2] . '/' . intval($dateParts[1]) . '/' . $dateParts[0] . ', 00:00:00';
                    } else {
                        $fechaApertura = $last_apertura->fecha;
                    }

                    // Inicio del turno: si hubo cierre hoy, ventas solo después de ese cierre
                    if ($last_cierre && substr($last_cierre->fecha, 0, 10) === $last_apertura->fecha) {
                        $fecha_ap_server = $last_cierre->fecha;
                    } else {
                        $fecha_ap_server = date('Y-m-d H:i:s');
                    }

                    $jsonStatus = [
                        'cajaAbierta' => true,
                        'id_apertura' => $last_apertura->id,
                        'montoApertura' => $montoApertura,
                        'fechaApertura' => $fechaApertura,
                        'fecha_apertura_server' => $fecha_ap_server
                    ];
                    @file_put_contents($filePath, json_encode($jsonStatus));
                }
            }

            // Si la caja está abierta pero el monto es 0, recuperar desde la última apertura en BD
            if ($cajaAbierta && $montoApertura <= 0 && $last_apertura) {
                $montoApertura = floatval($last_apertura->apertura);
            }
        }

        $transporteCobrado = 0.0;
        $transportePagadoTransportista = 0.0;
        $enviosDespachados = 0;
        $enviosTurno = [];

        // 3. Recuperar ventas asociadas a la sesión activa (solo del turno actual)
        if ($cajaAbierta) {
            $this->db->select('v.idventa, v.total, v.formapago, v.pago, v.pagomixto, v.fecha, COALESCE(vt.precio_transporte, 0) AS precio_transporte');
            $this->db->from('ventas v');
            $this->db->join('ventatransporte vt', 'v.idventa = vt.idventa', 'left');
            $this->db->where('v.idneg', $deposito);
            $this->db->where('(v.estado IS NULL OR v.estado != "ANULADO")');
            if ($caja) {
                $this->db->where('v.idusr', $caja->usuario_id);
            } else if ($usuario_id) {
                $this->db->where('v.idusr', $usuario_id);
            }

            $fechaInicioTurno = $fecha_ap_server;
            $usarMayorQue = false;

            // Re-apertura el mismo día: excluir ventas de turnos anteriores ya cerrados
            if ($last_cierre && $last_apertura && substr($last_cierre->fecha, 0, 10) === $last_apertura->fecha) {
                if (!$fechaInicioTurno || strtotime($last_cierre->fecha) >= strtotime($fechaInicioTurno)) {
                    $fechaInicioTurno = $last_cierre->fecha;
                    $usarMayorQue = true;
                }
            }

            if ($fechaInicioTurno) {
                if ($usarMayorQue) {
                    $this->db->where('v.fecha >', $fechaInicioTurno);
                } else {
                    $this->db->where('v.fecha >=', $fechaInicioTurno);
                }
            } elseif ($last_cierre) {
                $this->db->where('v.fecha >', $last_cierre->fecha);
            }

            $sales = $this->db->get()->result();

            foreach ($sales as $s) {
                $pagoEfectivo = 0;
                $pagoOtro = 0;
                $precioTransporte = floatval($s->precio_transporte ?? 0);
                $totalProductos = floatval($s->total);
                
                // Verificar si la forma de pago contiene 'MIXTO' y el desglose de pago mixto no esta vacio
                if ((stripos($s->formapago, 'MIXTO') !== false || $s->formapago === 'mixto') && !empty($s->pagomixto)) {
                    $mix = json_decode($s->pagomixto, true);
                    if (is_array($mix)) {
                        // Desglose en formato JSON estructurado
                        $pagoEfectivo = floatval($mix['efectivo'] ?? 0);
                        $pagoOtro = floatval($mix['tarjeta'] ?? 0) + floatval($mix['transferencia'] ?? 0) + floatval($mix['deposito'] ?? 0);
                    } else {
                        // Desglose en formato cadena de texto (ej: "EFECTIVO: 700.00 | TRANSFERENCIA: 499.00")
                        if (preg_match('/EFECTIVO:\s*([0-9.]+)/i', $s->pagomixto, $matches)) {
                            $pagoEfectivo = floatval($matches[1]);
                        }
                        
                        $otherPaymentSum = 0;
                        if (preg_match('/TARJETA:\s*([0-9.]+)/i', $s->pagomixto, $matches)) {
                            $otherPaymentSum += floatval($matches[1]);
                        }
                        if (preg_match('/TRANSFERENCIA:\s*([0-9.]+)/i', $s->pagomixto, $matches)) {
                            $otherPaymentSum += floatval($matches[1]);
                        }
                        if (preg_match('/QR-MERCANTIL:\s*([0-9.]+)/i', $s->pagomixto, $matches)) {
                            $otherPaymentSum += floatval($matches[1]);
                        }
                        if (preg_match('/QR-BCP:\s*([0-9.]+)/i', $s->pagomixto, $matches)) {
                            $otherPaymentSum += floatval($matches[1]);
                        }
                        if (preg_match('/QR-BISA:\s*([0-9.]+)/i', $s->pagomixto, $matches)) {
                            $otherPaymentSum += floatval($matches[1]);
                        }
                        if (preg_match('/DEPOSITO:\s*([0-9.]+)/i', $s->pagomixto, $matches)) {
                            $otherPaymentSum += floatval($matches[1]);
                        }
                        $pagoOtro = $otherPaymentSum;
                    }
                } else if (strtolower($s->formapago) === 'efectivo') {
                    $pagoEfectivo = $totalProductos;
                } else {
                    $pagoOtro = $totalProductos;
                }

                // Sumar cobro de transporte al mismo desglose de pago de la venta
                if ($precioTransporte > 0 && $totalProductos > 0) {
                    $ratioEfectivo = $pagoEfectivo / $totalProductos;
                    $ratioOtro = $pagoOtro / $totalProductos;
                    $pagoEfectivo += $precioTransporte * $ratioEfectivo;
                    $pagoOtro += $precioTransporte * $ratioOtro;
                } elseif ($precioTransporte > 0) {
                    if (strtolower($s->formapago) === 'efectivo') {
                        $pagoEfectivo += $precioTransporte;
                    } else {
                        $pagoOtro += $precioTransporte;
                    }
                }

                $transporteCobrado += $precioTransporte;

                $shiftSales[] = [
                    'idventa' => $s->idventa,
                    'total' => $totalProductos + $precioTransporte,
                    'totalProductos' => $totalProductos,
                    'costoTransporte' => $precioTransporte,
                    'formapago' => $s->formapago,
                    'pagoEfectivo' => $pagoEfectivo,
                    'pagoOtro' => $pagoOtro,
                    'fecha' => date('H:i:s', strtotime($s->fecha))
                ];
            }

            // 4. Pagos a transportistas por envíos registrados en este turno (salida de efectivo)
            if ($fechaInicioTurno) {
                $this->db->select('vt.id, vt.idventa, vt.pago_transporte, vt.precio_transporte, vt.fecha_pago, v.cliente');
                $this->db->from('ventatransporte vt');
                $this->db->join('ventas v', 'v.idventa = vt.idventa', 'inner');
                $this->db->where('v.idneg', $deposito);
                $this->db->where('vt.pago_transporte >', 0);
                $this->db->where('vt.fecha_pago >', '2000-01-01 00:00:00');
                if ($caja) {
                    $this->db->where('v.idusr', $caja->usuario_id);
                } else if ($usuario_id) {
                    $this->db->where('v.idusr', $usuario_id);
                }

                if ($usarMayorQue) {
                    $this->db->where('vt.fecha_pago >', $fechaInicioTurno);
                } else {
                    $this->db->where('vt.fecha_pago >=', $fechaInicioTurno);
                }

                $envios = $this->db->get()->result();

                foreach ($envios as $envio) {
                    $montoPago = floatval($envio->pago_transporte);
                    $transportePagadoTransportista += $montoPago;
                    $enviosDespachados++;
                    $enviosTurno[] = [
                        'id' => intval($envio->id),
                        'idventa' => $envio->idventa,
                        'cliente' => $envio->cliente,
                        'pago_transporte' => $montoPago,
                        'precio_transporte' => floatval($envio->precio_transporte),
                        'fecha_pago' => $envio->fecha_pago,
                    ];
                }
            }
        }

        // Mantener el archivo de estado actualizado con el monto correcto
        if ($cajaAbierta && file_exists($filePath)) {
            $existing = json_decode(file_get_contents($filePath), true);
            if (is_array($existing)) {
                $existing['cajaAbierta'] = true;
                $existing['montoApertura'] = $montoApertura;
                if (!empty($fechaApertura)) {
                    $existing['fechaApertura'] = $fechaApertura;
                }
                if (!empty($fecha_ap_server)) {
                    $existing['fecha_apertura_server'] = $fecha_ap_server;
                }
                @file_put_contents($filePath, json_encode($existing));
            }
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'cajaAbierta' => $cajaAbierta,
                'montoApertura' => $montoApertura,
                'fechaApertura' => $fechaApertura,
                'shiftSales' => $shiftSales,
                'transporteTurno' => [
                    'cobrado' => $transporteCobrado,
                    'pagadoTransportista' => $transportePagadoTransportista,
                    'neto' => $transporteCobrado - $transportePagadoTransportista,
                    'enviosDespachados' => $enviosDespachados,
                    'envios' => $enviosTurno,
                ],
            ]));
    }

    /**
     * Guarda los datos de transporte asociados a una venta en la tabla ventatransporte.
     * Simplificado: solo requiere idventa y precio_transporte.
     * Los datos de ubicación y referencia se completan después al registrar el envío.
     */
    public function guardar_transporte()
    {
        $data = json_decode(file_get_contents('php://input'), true);

        // Validar campos requeridos (simplificado)
        if (empty($data['idventa']) || !isset($data['precio_transporte'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Se requiere idventa y precio_transporte.']));
        }

        // Insertar registro inicial de transporte (incluye ubicación y descripción si se proporcionan)
        $transporteData = [
            'idventa'           => $this->security->xss_clean($data['idventa']),
            'precio_transporte' => floatval($data['precio_transporte']),
            'pago_transporte'   => 0,
            'descripcion'       => $this->security->xss_clean($data['descripcion'] ?? ''),
            'latitud'           => isset($data['latitud']) ? floatval($data['latitud']) : null,
            'longitud'          => isset($data['longitud']) ? floatval($data['longitud']) : null,
            'usuario_cobro'     => intval($data['usuario_cobro'] ?? 0),
            'fecha_registro'    => date('Y-m-d H:i:s'),
        ];

        $this->db->insert('ventatransporte', $transporteData);
        $insertId = $this->db->insert_id();

        if (!$insertId) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'No se pudo guardar el transporte en la base de datos.']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'message' => 'Transporte registrado exitosamente',
                'id'      => $insertId,
            ]));
    }

    /**
     * Lista todas las ventas que tienen transporte asociado.
     * Hace JOIN entre ventas y ventatransporte.
     * Filtro opcional por depósito (idneg).
     */
    public function listar_ventas_transporte()
    {
        $deposito = $this->input->get('deposito');

        $this->db->select('
            v.id AS nro_venta,
            v.idventa,
            v.cliente,
            v.telefono,
            v.pago,
            v.saldo,
            v.total,
            v.fecha,
            v.fecha AS fecha_venta,
            v.formapago,
            d.nombre AS sucursal,
            vt.id AS transporte_id,
            vt.precio_transporte,
            vt.pago_transporte,
            vt.descripcion,
            vt.nombre_referencia,
            vt.telefono_referencia,
            vt.latitud,
            vt.longitud,
            vt.usuario_cobro,
            vt.usuario_pago,
            vt.fecha_registro,
            vt.fecha_pago
        ');
        $this->db->from('ventas v');
        $this->db->join('ventatransporte vt', 'v.idventa = vt.idventa', 'inner');
        $this->db->join('depositos d', 'v.idneg = d.id', 'left');

        if (!empty($deposito)) {
            $this->db->where('v.idneg', $deposito);
        }

        $this->db->order_by('vt.fecha_registro', 'DESC');
        $result = $this->db->get()->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode($result));
    }

    /**
     * Actualiza los datos de envío/pago al transportista en ventatransporte.
     * Valida que el pago no exceda el precio cobrado al cliente.
     * Actualiza: pago_transporte, usuario_pago, fecha_pago, ubicación GPS y referencias.
     */
    public function actualizar_pago_transporte()
    {
        $data = json_decode(file_get_contents('php://input'), true);

        // Validar campo obligatorio: id del registro de transporte
        if (empty($data['id'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Se requiere el ID del registro de transporte.']));
        }

        // Obtener el registro actual para validar el monto
        $registro = $this->db->where('id', intval($data['id']))->get('ventatransporte')->row();
        if (!$registro) {
            return $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Registro de transporte no encontrado.']));
        }

        // Validar que el pago al transportista sea mayor o igual a 0
        $pagoTransporte = floatval($data['pago_transporte'] ?? 0);
        if ($pagoTransporte < 0) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'El pago al transportista debe ser mayor o igual a 0.'
                ]));
        }

        // Validar que el pago al transportista no exceda lo cobrado al cliente
        if ($pagoTransporte > floatval($registro->precio_transporte)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'El pago al transportista (Bs ' . number_format($pagoTransporte, 2) . ') no puede exceder el cobro al cliente (Bs ' . number_format($registro->precio_transporte, 2) . ').'
                ]));
        }

        // Preparar datos para actualización
        $updateData = [
            'pago_transporte'     => $pagoTransporte,
            'usuario_pago'        => intval($data['usuario_pago'] ?? 0),
            'fecha_pago'          => date('Y-m-d H:i:s'),
            'latitud'             => isset($data['latitud']) ? floatval($data['latitud']) : null,
            'longitud'            => isset($data['longitud']) ? floatval($data['longitud']) : null,
            'descripcion'         => $this->security->xss_clean($data['descripcion'] ?? ''),
            'nombre_referencia'   => $this->security->xss_clean($data['nombre_referencia'] ?? ''),
            'telefono_referencia' => $this->security->xss_clean($data['telefono_referencia'] ?? ''),
        ];

        $this->db->where('id', intval($data['id']));
        $success = $this->db->update('ventatransporte', $updateData);

        if (!$success) {
            $dbError = $this->db->error();
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'No se pudo actualizar el registro de transporte: ' . ($dbError['message'] ?? 'Error de base de datos')]));
        }

        // Obtener el ID del cajero (vendedor) que registró la venta original
        $venta = $this->db->select('idusr')->where('idventa', $registro->idventa)->get('ventas')->row();
        $cajeroId = $venta ? intval($venta->idusr) : 0;

        // Registrar movimiento de egreso en la caja abierta del cajero si existe
        $caja = null;
        if ($cajeroId > 0) {
            $caja = $this->db->where('usuario_id', $cajeroId)
                             ->where('estado', 'Abierta')
                             ->get('sesiones_caja')
                             ->row();
        }

        if ($caja && $pagoTransporte > 0 && floatval($registro->pago_transporte) <= 0) {
            $movData = [
                'caja_id' => $caja->id,
                'usuario_id' => $cajeroId,
                'tipo' => 'Egreso',
                'detalle' => 'Pago a Transportista',
                'monto' => $pagoTransporte,
                'informacion_adicional' => 'Pago a transportista por envío de venta ' . $registro->idventa,
                'fecha_registro' => date('Y-m-d H:i:s')
            ];
            $this->db->insert('movimientos_caja', $movData);
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'message' => 'Pago al transportista registrado exitosamente',
            ]));
    }

    /**
     * Genera un código QR de Banco BISA (SIP MC4) para cobro
     */
    public function generar_qr_bisa() {
        $amount = floatval($this->input->post('monto'));
        $proformaId = $this->input->post('id_proforma');
        $ventaId = $this->input->post('id_venta');

        if ($amount <= 0) {
            return $this->output
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El monto debe ser mayor a 0']));
        }

        // Cargar biblioteca SIP BISA
        $this->load->library('sip_service');

        $sucursalId = $this->input->post('sucursal_id');
        $sucursalAlias = 'SUC';

        if ($sucursalId) {
            $this->db->group_start();
            $this->db->where('id', $sucursalId);
            if (!is_numeric($sucursalId)) {
                $this->db->or_like('nombre', $sucursalId);
            }
            $this->db->group_end();
            $deposito = $this->db->get('depositos')->row();

            if ($deposito && !empty($deposito->nombre)) {
                $sucursalAlias = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($deposito->nombre, 0, 8)));
            }
        }

        $usuarioId = $this->input->post('usuario_id');
        error_log("QR GENERATION - POST DATA: " . json_encode($_POST));
        $usuarioSuffix = $usuarioId ? '_USU_' . $usuarioId : '';

        $alias = 'BISA_' . $sucursalAlias . $usuarioSuffix . '_' . time() . '_' . rand(10, 99);
        $detail = 'Pago Ferreteria Oferton';

        $res = $this->sip_service->generateQr($alias, $amount, $detail);

        if ($res['status'] === 'success') {
            // Guardar transacción en la base de datos
            $txData = [
                'alias' => $alias,
                'monto' => $amount,
                'id_venta' => $ventaId ? $ventaId : null,
                'id_proforma' => $proformaId ? $proformaId : null,
                'id_qr' => $res['idQr'],
                'qr_base64' => $res['qr_base64'],
                'estado' => 'PENDIENTE',
                'fecha_registro' => date('Y-m-d H:i:s')
            ];
            $this->db->insert('bisa_qr_transacciones', $txData);

            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(200)
                ->set_output(json_encode([
                    'status' => 'success',
                    'alias' => $alias,
                    'qr_base64' => $res['qr_base64'],
                    'simulated' => $res['simulated'] ?? false
                ]));
        } else {
            return $this->output
                ->set_status_header(500)
                ->set_output(json_encode(['error' => $res['error']]));
        }
    }

    /**
     * Consulta el estado de pago del QR BISA
     */
    public function consultar_pago_bisa() {
        $alias = $this->input->get('alias');
        if (!$alias) {
            $alias = $this->input->post('alias');
        }
        if (!$alias) {
            $rawInput = $this->input->raw_input_stream;
            $data = json_decode($rawInput, true);
            $alias = $data['alias'] ?? null;
        }

        if (!$alias) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Alias requerido']));
        }

        // Buscar en la base de datos local
        $tx = $this->db->where('alias', $alias)->get('bisa_qr_transacciones')->row();
        if (!$tx) {
            return $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Transaccion no encontrada']));
        }

        $estadoActual = strtoupper($tx->estado ?? '');
        $estaPagado = ($estadoActual === 'PAGADO');

        if (!$estaPagado && !empty($tx->id_proforma)) {
            $profCheck = $this->db->get_where('proformas', ['idproforma' => $tx->id_proforma])->row();
            if ($profCheck && strtoupper($profCheck->estado ?? '') === 'PAGADO') {
                $estaPagado = true;
            }
        }

        if ($estaPagado) {
            $this->db->where('alias', $alias)->update('bisa_qr_transacciones', ['estado' => 'PAGADO', 'fecha_pago' => date('Y-m-d H:i:s')]);
            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'PAGADO']));
        }

        // Si es una transacción simulada, no consultar a la API real
        $isSimulated = (strpos($tx->id_qr, 'SIM_QR_') !== false);
        if ($isSimulated) {
            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => $tx->estado]));
        }

        // Consultar pasarela real
        $this->load->library('sip_service');
        $status = $this->sip_service->checkStatus($alias);

        if ($status === 'PAGADO') {
            $this->db->trans_start();
            
            // Actualizar base de datos local
            $this->db->where('alias', $alias)->update('bisa_qr_transacciones', [
                'estado' => 'PAGADO',
                'fecha_pago' => date('Y-m-d H:i:s')
            ]);
            
            // Si está vinculado a una proforma, marcarla como pagada
            if ($tx->id_proforma) {
                $this->db->where('idproforma', $tx->id_proforma)->update('proformas', [
                    'estado' => 'Pagado',
                    'formapago' => 'qr_bisa',
                    'pago' => $tx->monto,
                    'saldo' => 0
                ]);
            }

            // Si está vinculado a una venta directa (POS), marcarla como pagada
            if ($tx->id_venta) {
                $this->db->where('idventa', $tx->id_venta)->update('ventas', [
                    'pago' => $tx->monto,
                    'saldo' => 0
                ]);
            }
            
            $this->db->trans_complete();
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => $status]));
    }

    /**
     * Webhook/Callback para confirmaciones de pago desde el Banco BISA / SIP
     */
    public function confirma_pago_sip() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $alias = $input['alias'] ?? null;
        $monto = floatval($input['monto'] ?? 0);
        $idQr = $input['idQr'] ?? null;

        if (!$alias) {
            return $this->output
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'Alias no proporcionado']));
        }

        $tx = $this->db->where('alias', $alias)->get('bisa_qr_transacciones')->row();
        if (!$tx) {
            return $this->output
                ->set_status_header(404)
                ->set_output(json_encode(['error' => 'Transaccion no encontrada']));
        }

        if ($tx->estado === 'PAGADO') {
            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'success', 'message' => 'Ya estaba pagado']));
        }

        if (abs(floatval($tx->monto) - $monto) > 0.1) {
            return $this->output
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'Monto discrepante']));
        }

        $this->db->trans_start();

        $this->db->where('alias', $alias)->update('bisa_qr_transacciones', [
            'estado' => 'PAGADO',
            'fecha_pago' => date('Y-m-d H:i:s'),
            'id_qr' => $idQr ? $idQr : $tx->id_qr
        ]);

        if ($tx->id_proforma) {
            $this->db->where('idproforma', $tx->id_proforma)->update('proformas', [
                'estado' => 'Pagado',
                'formapago' => 'qr_bisa',
                'pago' => $tx->monto,
                'saldo' => 0
            ]);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_status_header(500)
                ->set_output(json_encode(['error' => 'Error al procesar base de datos']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['status' => 'success', 'message' => 'Pago confirmado']));
    }

    /**
     * Endpoint para simulación manual de pago (Solo para pruebas locales)
     */
    public function simular_pago_bisa() {
        $alias = $this->input->get('alias');
        if (!$alias) {
            return $this->output
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'Alias requerido']));
        }

        $tx = $this->db->where('alias', $alias)->get('bisa_qr_transacciones')->row();
        if (!$tx) {
            return $this->output
                ->set_status_header(404)
                ->set_output(json_encode(['error' => 'Transaccion no encontrada']));
        }

        $this->db->trans_start();

        $this->db->where('alias', $alias)->update('bisa_qr_transacciones', [
            'estado' => 'PAGADO',
            'fecha_pago' => date('Y-m-d H:i:s')
        ]);

        if ($tx->id_proforma) {
            $this->db->where('idproforma', $tx->id_proforma)->update('proformas', [
                'estado' => 'Pagado',
                'formapago' => 'qr_bisa',
                'pago' => $tx->monto,
                'saldo' => 0
            ]);
        }

        if ($tx->id_venta) {
            $this->db->where('idventa', $tx->id_venta)->update('ventas', [
                'pago' => $tx->monto,
                'saldo' => 0
            ]);
        }

        $this->db->trans_complete();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'message' => 'Pago simulado con exito']));
    }

    /**
     * Devuelve las estadisticas para el dashboard (KPIs y ventas por sucursal).
     */
    public function dashboard_stats() {
        $sucursal_activa = $this->input->get_request_header('X-Active-Branch', TRUE);
        if (empty($sucursal_activa)) {
            $sucursal_activa = $this->input->get('active_branch') ?? $this->input->get('sucursal');
        }
        if (empty($sucursal_activa) && isset($_SERVER['HTTP_X_ACTIVE_BRANCH'])) {
            $sucursal_activa = $_SERVER['HTTP_X_ACTIVE_BRANCH'];
        }
        if ($sucursal_activa === 'null' || $sucursal_activa === 'undefined' || $sucursal_activa === 'all' || empty($sucursal_activa)) {
            $sucursal_activa = null;
        }
        
        $today = date('Y-m-d');
        $this->db->select_sum('total')->where('DATE(fecha)', $today)->where('estado !=', 'anulado');
        if (!empty($sucursal_activa)) {
            $this->db->where('idneg', $sucursal_activa);
        }
        $ventas_hoy = $this->db->get('ventas')->row()->total ?? 0;

        $total_clientes = $this->db->count_all('clientes');

        $this->db->group_start()
                 ->where('vt.fecha_pago IS NULL')
                 ->or_where('vt.fecha_pago <', '2000-01-01 00:00:00')
                 ->group_end();
        $this->db->from('ventatransporte vt');
        if (!empty($sucursal_activa)) {
            $this->db->join('ventas v', 'vt.idventa = v.idventa', 'inner');
            $this->db->where('v.idneg', $sucursal_activa);
        }
        $envios_pendientes = $this->db->count_all_results();

        $month = date('m');
        $year = date('Y');
        $this->db->select_sum('total')->where('MONTH(fecha)', $month)->where('YEAR(fecha)', $year)->where('estado !=', 'anulado');
        if (!empty($sucursal_activa)) {
            $this->db->where('idneg', $sucursal_activa);
        }
        $ingresos_mes = $this->db->get('ventas')->row()->total ?? 0;

        $periodo = $this->input->get('periodo') ?? '15dias';
        $sucursal_filter_where = "";
        if (!empty($sucursal_activa)) {
            $sucursal_filter_where = " AND idneg = " . intval($sucursal_activa);
        }

        $historial_ventas = [];
        if ($periodo === 'dia') {
            $ventas_db = $this->db->query("
                SELECT DATE_FORMAT(fecha, '%H:00') as etiqueta, 
                       COALESCE(SUM(total), 0) as total,
                       COALESCE(SUM(CASE WHEN comentario LIKE '%Orden Web Token:%' OR comentario LIKE '%[WEB_PAGADO]%' THEN total ELSE 0 END), 0) as total_web
                FROM ventas 
                WHERE DATE(fecha) = '$today' $sucursal_filter_where
                GROUP BY DATE_FORMAT(fecha, '%H:00')
            ")->result_array();
            $mapped_total = array_column($ventas_db, 'total', 'etiqueta');
            $mapped_web = array_column($ventas_db, 'total_web', 'etiqueta');
            
            $current_hour = (int)date('H');
            for ($i = 8; $i <= max(20, $current_hour); $i++) {
                $label = str_pad($i, 2, '0', STR_PAD_LEFT) . ':00';
                $historial_ventas[] = [
                    'etiqueta' => $label,
                    'total' => isset($mapped_total[$label]) ? floatval($mapped_total[$label]) : 0,
                    'total_web' => isset($mapped_web[$label]) ? floatval($mapped_web[$label]) : 0
                ];
            }
        } else {
            if ($periodo === 'semana') {
                $dias = 7;
            } elseif ($periodo === 'mes') {
                $dias = 30;
            } else {
                $dias = 15; // 15 días por defecto
            }

            $ventas_db = $this->db->query("
                SELECT DATE_FORMAT(fecha, '%Y-%m-%d') as etiqueta, 
                       COALESCE(SUM(total), 0) as total,
                       COALESCE(SUM(CASE WHEN comentario LIKE '%Orden Web Token:%' OR comentario LIKE '%[WEB_PAGADO]%' THEN total ELSE 0 END), 0) as total_web
                FROM ventas 
                WHERE fecha >= DATE_SUB('$today', INTERVAL " . ($dias - 1) . " DAY) $sucursal_filter_where
                GROUP BY DATE_FORMAT(fecha, '%Y-%m-%d')
            ")->result_array();
            $mapped_total = array_column($ventas_db, 'total', 'etiqueta');
            $mapped_web = array_column($ventas_db, 'total_web', 'etiqueta');

            for ($i = $dias - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $historial_ventas[] = [
                    'etiqueta' => date('d/m', strtotime($date)),
                    'total' => isset($mapped_total[$date]) ? floatval($mapped_total[$date]) : 0,
                    'total_web' => isset($mapped_web[$date]) ? floatval($mapped_web[$date]) : 0
                ];
            }
        }

        // Métodos de pago en los últimos 6 meses
        $this->db->select("DATE_FORMAT(fecha, '%Y-%m') AS mes, formapago, total, pagomixto")
                 ->from('ventas')
                 ->where('fecha >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)');
        if (!empty($sucursal_activa)) {
            $this->db->where('idneg', $sucursal_activa);
        }
        $sales_pagos = $this->db->get()->result();

        $pagos_mes = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = date('Y-m', strtotime("-$i months"));
            $pagos_mes[$m] = [
                'mes' => $m,
                'efectivo' => 0.0,
                'tarjeta' => 0.0,
                'qr' => 0.0,
                'otros' => 0.0
            ];
        }

        foreach ($sales_pagos as $s) {
            $mes = $s->mes;
            if (!isset($pagos_mes[$mes])) {
                continue;
            }
            $total = floatval($s->total);
            $fp = strtolower($s->formapago);
            
            if ($fp === 'mixto' && !empty($s->pagomixto)) {
                $mix = json_decode($s->pagomixto, true);
                if (is_array($mix)) {
                    foreach ($mix as $key => $val) {
                        $key = strtolower($key);
                        $val = floatval($val);
                        if ($key === 'efectivo') {
                            $pagos_mes[$mes]['efectivo'] += $val;
                        } elseif ($key === 'tarjeta') {
                            $pagos_mes[$mes]['tarjeta'] += $val;
                        } elseif ($key === 'qr' || stripos($key, 'qr') !== false || stripos($key, 'bisa') !== false || stripos($key, 'mercantil') !== false || stripos($key, 'bcp') !== false) {
                            $pagos_mes[$mes]['qr'] += $val;
                        } else {
                            $pagos_mes[$mes]['otros'] += $val;
                        }
                    }
                } else {
                    $pagos_mes[$mes]['otros'] += $total;
                }
            } elseif ($fp === 'efectivo') {
                $pagos_mes[$mes]['efectivo'] += $total;
            } elseif ($fp === 'tarjeta') {
                $pagos_mes[$mes]['tarjeta'] += $total;
            } elseif ($fp === 'qr' || stripos($fp, 'qr') !== false || stripos($fp, 'bisa') !== false || stripos($fp, 'mercantil') !== false || stripos($fp, 'bcp') !== false) {
                $pagos_mes[$mes]['qr'] += $total;
            } else {
                $pagos_mes[$mes]['otros'] += $total;
            }
        }

        $pagos_metodos = array_values($pagos_mes);

        $sucursal_filter = "";
        if (!empty($sucursal_activa)) {
            $sucursal_filter = " AND v.idneg = " . intval($sucursal_activa);
        }

        $period_filter = "";
        if ($periodo === 'dia') {
            $period_filter = "AND DATE(v.fecha) = CURDATE()";
        } elseif ($periodo === 'semana') {
            $period_filter = "AND v.fecha >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
        } elseif ($periodo === 'mes') {
            $period_filter = "AND v.fecha >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)";
        } else {
            $period_filter = "AND v.fecha >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)";
        }

        // Top 5 de productos más vendidos
        $top_productos = $this->db->query("
            SELECT dv.descripcion, COALESCE(SUM(dv.cuantos), 0) AS cantidad_vendida
            FROM detalleventas dv
            JOIN ventas v ON dv.idventa = v.idventa
            WHERE 1=1 $period_filter $sucursal_filter
            GROUP BY dv.idprod, dv.descripcion
            ORDER BY cantidad_vendida DESC
            LIMIT 5
        ")->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'status' => 'success',
                'data' => [
                    'ventas_hoy' => floatval($ventas_hoy),
                    'total_clientes' => intval($total_clientes),
                    'envios_pendientes' => intval($envios_pendientes),
                    'ingresos_mes' => floatval($ingresos_mes),
                    'historial_ventas' => $historial_ventas,
                    'pagos_metodos' => $pagos_metodos,
                    'top_productos' => $top_productos
                ]
            ]));
    }

    /**
     * Obtiene la información detallada del descuento promocional (monto, porcentaje y nombre de la promoción).
     */
    private function obtener_descuento_promocional_info($prodMaster, $item = []) {
        $monto = 0;
        $porcentaje = 0;
        $nombre = '';

        if (isset($item['descuento_monto']) && floatval($item['descuento_monto']) > 0) {
            $monto = floatval($item['descuento_monto']);
        }
        if (isset($item['descuento_porcentaje']) && floatval($item['descuento_porcentaje']) > 0) {
            $porcentaje = floatval($item['descuento_porcentaje']);
        }
        if (!empty($item['nombre_promocion'])) {
            $nombre = trim($item['nombre_promocion']);
        }

        // Si ya se tiene el porcentaje o monto asignado al item, usarlo directamente sin sobrescribir con otras promociones
        if ($monto > 0 || $porcentaje > 0) {
            if ($porcentaje <= 0 && $monto > 0 && $prodMaster && floatval($prodMaster->precioventa) > 0) {
                $porcentaje = round(($monto / floatval($prodMaster->precioventa)) * 100);
            }
            if ($monto <= 0 && $porcentaje > 0 && $prodMaster && floatval($prodMaster->precioventa) > 0) {
                $monto = round(floatval($prodMaster->precioventa) * ($porcentaje / 100.0));
            }
            return [
                'monto' => $monto,
                'porcentaje' => $porcentaje,
                'nombre' => $nombre
            ];
        }

        if ($prodMaster && ($monto <= 0 || empty($nombre))) {
            $hoy = date('Y-m-d');
            $promociones = $this->db->query("
                SELECT p.*, m.nombre as marca_nombre, c.descripcion as categoria_nombre 
                FROM promociones_descuentos p 
                LEFT JOIN marcas m ON p.marca_id = m.id 
                LEFT JOIN categoria_producto c ON p.categoria_id = c.idcategoria 
                WHERE p.activo = 1 AND DATE(p.fecha_inicio) <= '$hoy' AND DATE(p.fecha_fin) >= '$hoy' 
                ORDER BY p.porcentaje_descuento DESC
            ")->result_array();

            if (!empty($promociones)) {
                $comision_val = floatval($prodMaster->comision ?? 0);
                $costo_compra = floatval($prodMaster->preciolocal ?? 0);
                $pv_orig_base = floatval($prodMaster->precioventa ?? 0);

                $prod_marca_str = '';
                if (!empty($prodMaster->idmarca)) {
                    $mrow = $this->db->get_where('marcas', ['id' => $prodMaster->idmarca])->row();
                    if ($mrow) $prod_marca_str = strtolower(trim($mrow->nombre));
                }
                $prod_cat_str = '';
                if (!empty($prodMaster->idcategoria)) {
                    $crow = $this->db->get_where('categoria_producto', ['idcategoria' => $prodMaster->idcategoria])->row();
                    if ($crow) $prod_cat_str = strtolower(trim($crow->descripcion));
                }

                foreach ($promociones as $promo) {
                    $match = false;
                    $tipo = $promo['tipo_filtro'] ?? 'todos';
                    if ($tipo === 'todos') {
                        $match = true;
                    } else if ($tipo === 'comision') {
                        $min_com = floatval($promo['comision_minima'] ?? 0);
                        if ($min_com > 0) {
                            if ($comision_val >= $min_com) {
                                $match = true;
                            }
                        } else if ($comision_val > 0) {
                            $match = true;
                        }
                    } else if ($tipo === 'productos' && !empty($promo['productos_ids'])) {
                        $prod_list = json_decode($promo['productos_ids'], true);
                        if (!is_array($prod_list)) {
                            $prod_list = array_map('trim', explode(',', $promo['productos_ids']));
                        }
                        $prod_list_str = array_map('strval', $prod_list);
                        if (in_array((string)($prodMaster->idprod ?? ''), $prod_list_str) || in_array((string)($prodMaster->id ?? ''), $prod_list_str)) {
                            $match = true;
                        }
                    } else if ($tipo === 'marca') {
                        if (!empty($promo['marca_id']) && !empty($prodMaster->idmarca) && (int)$promo['marca_id'] === (int)$prodMaster->idmarca) {
                            $match = true;
                        } else if (!empty($promo['marca_nombre']) && $prod_marca_str === strtolower(trim($promo['marca_nombre']))) {
                            $match = true;
                        }
                    } else if ($tipo === 'categoria') {
                        if (!empty($promo['categoria_id']) && !empty($prodMaster->idcategoria) && (int)$promo['categoria_id'] === (int)$prodMaster->idcategoria) {
                            $match = true;
                        } else if (!empty($promo['categoria_nombre']) && $prod_cat_str === strtolower(trim($promo['categoria_nombre']))) {
                            $match = true;
                        }
                    }

                    if ($match) {
                        $pct_eval = intval($promo['porcentaje_descuento']);
                        if ($costo_compra > 0 && $pv_orig_base > 0) {
                            $monto_desc_eval = $pv_orig_base * ($pct_eval / 100.0);
                            $pv_desc_eval = $pv_orig_base - $monto_desc_eval;
                            $neto_eval = $pv_desc_eval - $comision_val;

                            if ($neto_eval <= $costo_compra) {
                                $match = false;
                            }
                        }
                    }

                    if ($match) {
                        $pctPromo = floatval($promo['porcentaje_descuento'] ?? 0);
                        if ($pctPromo > 0) {
                            if ($porcentaje <= 0) $porcentaje = $pctPromo;
                            if ($monto <= 0) $monto = round($pv_orig_base * ($pctPromo / 100.0));
                            if (empty($nombre)) $nombre = trim($promo['nombre_promocion'] ?? $promo['nombre'] ?? $promo['titulo'] ?? '');
                            break;
                        }
                    }
                }
            }
        }

        if ($porcentaje <= 0 && $monto > 0 && $prodMaster && floatval($prodMaster->precioventa) > 0) {
            $porcentaje = round(($monto / floatval($prodMaster->precioventa)) * 100);
        }

        return [
            'monto' => $monto,
            'porcentaje' => $porcentaje,
            'nombre' => $nombre
        ];
    }

    /**
     * Calcula el monto de descuento promocional por unidad para un producto según promociones activas o datos del item.
     */
    private function obtener_descuento_promocional_item($prodMaster, $item = []) {
        $info = $this->obtener_descuento_promocional_info($prodMaster, $item);
        return $info['monto'];
    }

    /**
     * Guarda o actualiza una Venta Abierta con pago QR pendiente
     */
    public function guardar_venta_abierta()
    {
        $alias = $this->input->post('alias');
        $monto = floatval($this->input->post('monto'));
        $qr_base64 = $this->input->post('qr_base64');
        $id_qr = $this->input->post('id_qr');
        $datos_venta = $this->input->post('datos_venta');

        if (empty($alias) || $monto <= 0) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Alias o monto inválido']));
        }

        $tx = $this->db->get_where('bisa_qr_transacciones', ['alias' => $alias])->row();
        if ($tx) {
            $this->db->where('alias', $alias)->update('bisa_qr_transacciones', [
                'monto' => $monto,
                'id_qr' => $id_qr ? $id_qr : $tx->id_qr,
                'qr_base64' => $qr_base64 ? $qr_base64 : $tx->qr_base64,
                'datos_venta' => $datos_venta ? $datos_venta : $tx->datos_venta,
                'estado' => ($tx->estado === 'PAGADO') ? 'PAGADO' : 'ABIERTA',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            $this->db->insert('bisa_qr_transacciones', [
                'alias' => $alias,
                'monto' => $monto,
                'id_qr' => $id_qr,
                'qr_base64' => $qr_base64,
                'datos_venta' => $datos_venta,
                'estado' => 'ABIERTA',
                'fecha_registro' => date('Y-m-d H:i:s')
            ]);
        }

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'status' => 'success',
                'message' => 'Venta guardada como Venta Abierta correctamente'
            ]));
    }

    /**
     * Lista las ventas abiertas pendientes por cobro QR
     */
    public function listar_ventas_abiertas()
    {
        $this->db->from('bisa_qr_transacciones');
        $this->db->where_in('estado', ['ABIERTA', 'PENDIENTE', 'PAGADO']);
        $this->db->order_by('id', 'DESC');
        $this->db->limit(100);
        $ventas = $this->db->get()->result_array();

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode($ventas));
    }

    /**
     * Verifica con la pasarela SIP si el pago de una Venta Abierta fue realizado.
     * Si está pagado, finaliza la venta en la BD (descuento de inventarios, kardex, registro de venta) y retorna los datos para el recibo.
     */
    public function verificar_pago_venta_abierta()
    {
        $alias = $this->input->post('alias');
        if (empty($alias)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Alias no proporcionado']));
        }

        $tx = $this->db->get_where('bisa_qr_transacciones', ['alias' => $alias])->row();
        if (!$tx) {
            return $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Transacción no encontrada']));
        }

        $estadoActual = strtoupper($tx->estado ?? '');
        $estaPagado = ($estadoActual === 'PAGADO');

        if (!$estaPagado && !empty($tx->id_proforma)) {
            $profCheck = $this->db->get_where('proformas', ['idproforma' => $tx->id_proforma])->row();
            if ($profCheck && strtoupper($profCheck->estado ?? '') === 'PAGADO') {
                $estaPagado = true;
                $this->db->where('alias', $alias)->update('bisa_qr_transacciones', ['estado' => 'PAGADO', 'fecha_pago' => date('Y-m-d H:i:s')]);
            }
        }

        if (!$estaPagado) {
            $this->load->library('sip_service');
            $statusSip = $this->sip_service->checkStatus($alias);
            if ($statusSip === 'PAGADO') {
                $estaPagado = true;
                $this->db->where('alias', $alias)->update('bisa_qr_transacciones', [
                    'estado' => 'PAGADO',
                    'fecha_pago' => date('Y-m-d H:i:s')
                ]);
            }
        }

        if (!$estaPagado) {
            return $this->output
                ->set_status_header(200)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status' => 'success',
                    'paid' => false,
                    'message' => 'El pago en Banco BISA continúa PENDIENTE'
                ]));
        }

        // Si ya fue procesada anteriormente, retornar info exitosa
        if ($estadoActual === 'PROCESADO' && !empty($tx->id_venta)) {
            return $this->output
                ->set_status_header(200)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status' => 'success',
                    'paid' => true,
                    'already_processed' => true,
                    'id_venta' => $tx->id_venta,
                    'alias' => $alias
                ]));
        }

        // Procesar la venta en la base de datos
        $datosVentaRaw = $tx->datos_venta;
        $datos = json_decode($datosVentaRaw, true);

        if (empty($datos) || empty($datos['cart'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Los datos de la venta están incompletos']));
        }

        // Construir el array estructurado para la venta
        $salePayload = [
            'cart' => $datos['cart'],
            'total' => floatval($tx->monto),
            'cliente' => $datos['cliente_id'] ?? null,
            'cliente_nombre' => $datos['cliente_nombre'] ?? 'CLIENTE QR BISA',
            'cliente_nit' => $datos['cliente_nit'] ?? '0',
            'cliente_codigo' => $datos['cliente_codigo'] ?? '',
            'cliente_complemento' => $datos['cliente_complemento'] ?? '',
            'vendedor' => intval($datos['vendedor_id'] ?? 1),
            'formapago' => 'qr_bisa',
            'comentario' => $datos['comentario'] ?? 'Venta Abierta confirmada vía QR BISA',
            'idneg' => intval($datos['deposito_id'] ?? 1),
            'sucursal' => intval($datos['sucursal_id'] ?? 1),
            'tipo_venta' => $datos['tipo_venta'] ?? 'contado',
            'monto_pago' => floatval($tx->monto)
        ];

        // Ejecutar procesar internamente pasando $salePayload
        $this->procesar($salePayload);
        $resultJson = $this->output->get_output();

        $resData = json_decode($resultJson, true);
        if ($resData && (!empty($resData['idventa']) || !empty($resData['nro_venta']) || (isset($resData['status']) && $resData['status'] === 'success'))) {
            $idVenta = $resData['idventa'] ?? $resData['sale_id'] ?? $resData['nro_venta'] ?? null;
            $nroVenta = $resData['nro_venta'] ?? $idVenta;

            $this->db->where('alias', $alias)->update('bisa_qr_transacciones', [
                'estado' => 'PROCESADO',
                'id_venta' => $idVenta,
                'fecha_pago' => date('Y-m-d H:i:s')
            ]);

            return $this->output
                ->set_status_header(200)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status' => 'success',
                    'paid' => true,
                    'sale_id' => $idVenta,
                    'nro_venta' => $nroVenta,
                    'cart' => $datos['cart'],
                    'total' => $tx->monto,
                    'client_name' => $datos['cliente_nombre'] ?? '',
                    'client_nit' => $datos['cliente_nit'] ?? '',
                    'client_code' => $datos['cliente_codigo'] ?? '',
                    'client_complemento' => $datos['cliente_complemento'] ?? '',
                    'payment_type' => $datos['tipo_venta'] ?? 'contado',
                    'comments' => $datos['comentario'] ?? ''
                ]));
        } else {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'No se pudo procesar la venta en la base de datos', 'detail' => $resData]));
        }
    }

    /**
     * Cancela una Venta Abierta pendiente por QR
     */
    public function cancelar_venta_abierta()
    {
        $alias = $this->input->post('alias');
        if (empty($alias)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Alias no proporcionado']));
        }

        $this->db->where('alias', $alias)->update('bisa_qr_transacciones', [
            'estado' => 'CANCELADO',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'status' => 'success',
                'message' => 'Venta Abierta cancelada correctamente'
            ]));
    }
}


