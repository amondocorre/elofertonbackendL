<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Modelo para gestionar el flujo de Conciliación de Compras en 3 Pasos (3-Way Matching).
 * Sigue el estándar PSR-12 y las directrices globales del proyecto.
 */
class Conciliacion_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    /**
     * Verifica si un almacenero (usuario) tiene acceso a un almacén específico.
     * 
     * @param int $userId ID del usuario/vendedor en sesión
     * @param int $almacenId ID del almacén
     * @return bool TRUE si tiene permiso, FALSE en caso contrario
     */
    public function has_warehouse_permission($userId, $almacenId)
    {
        // Si el usuario no tiene ID, denegar por defecto
        if (empty($userId)) {
            return false;
        }

        // Si es administrador (rol 1), tiene acceso global a todos los almacenes
        $user = $this->db->select('id_rol')->where('id', intval($userId))->get('vendedores')->row();
        if ($user && $user->id_rol == 1) {
            return true;
        }

        // Consultar la tabla pivote usuarios_almacenes
        $query = $this->db->get_where('usuarios_almacenes', [
            'usuario_id' => intval($userId),
            'almacen_id' => intval($almacenId)
        ]);

        return $query->num_rows() > 0;
    }

    /**
     * Obtiene el listado de pedidos con filtros aplicados.
     * 
     * @param array $filters Filtros de búsqueda (status, proveedor_id, almacen_id)
     * @return array Listado de pedidos
     */
    public function get_pedidos($filters = [])
    {
        $this->db->select('p.*, pr.nombre as proveedor_nombre, v.nombre as supervisor_nombre, a.nombre as nombre_almacen, cf.tipo_pago as factura_tipo_pago, cf.nro_comprobante as factura_nro_comprobante');
        $this->db->from('pedidos p');
        $this->db->join('proveedores pr', 'p.proveedor_id = pr.id', 'left');
        $this->db->join('vendedores v', 'p.supervisor_id = v.id', 'left');
        $this->db->join('depositos a', 'p.almacen_id = a.id', 'left');
        $this->db->join('compras_facturas cf', 'p.id = cf.pedido_id', 'left');

        if (!empty($filters['status'])) {
            $this->db->where('p.estado', $filters['status']);
        }
        if (!empty($filters['proveedor_id'])) {
            $this->db->where('p.proveedor_id', intval($filters['proveedor_id']));
        }
        if (!empty($filters['almacen_id'])) {
            $this->db->where('p.almacen_id', intval($filters['almacen_id']));
        }

        $this->db->order_by('p.creado_at', 'DESC');
        return $this->db->get()->result_array();
    }

    /**
     * Obtiene los detalles completos de un pedido específico.
     * 
     * @param int $pedidoId ID del pedido
     * @return array|null Cabecera y detalles del pedido
     */
    public function get_pedido_by_id($pedidoId)
    {
        // 1. Obtener la cabecera con datos de factura si existiese
        $this->db->select('p.*, pr.nombre as proveedor_nombre, v.nombre as supervisor_nombre, a.nombre as nombre_almacen, cf.nro_comprobante, cf.tipo_pago, cf.metodo_pago, cf.adjunto, cf.fecha_factura, cpp.fecha_vencimiento');
        $this->db->from('pedidos p');
        $this->db->join('proveedores pr', 'p.proveedor_id = pr.id', 'left');
        $this->db->join('vendedores v', 'p.supervisor_id = v.id', 'left');
        $this->db->join('depositos a', 'p.almacen_id = a.id', 'left');
        $this->db->join('compras_facturas cf', 'p.id = cf.pedido_id', 'left');
        $this->db->join('cuentas_por_pagar cpp', 'cf.id = cpp.factura_id', 'left');
        $this->db->where('p.id', intval($pedidoId));
        $pedido = $this->db->get()->row_array();

        if (!$pedido) {
            return null;
        }

        // 2. Obtener el detalle de productos con precios maestros incluidos
        $this->db->select('pd.*, prod.descripcion, prod.idprod as codigo_producto, prod.preciolocal, prod.precioventa, prod.nuevoprecio, prod.comision');
        $this->db->from('pedido_detalles pd');
        $this->db->join('productos prod', 'pd.producto_id = prod.id', 'left');
        $this->db->where('pd.pedido_id', intval($pedidoId));
        $detalles = $this->db->get()->result_array();

        // Calcular discrepancias o diferencias en tiempo real
        foreach ($detalles as &$item) {
            $item['diferencia_recepcion'] = intval($item['cantidad_recibida']) - intval($item['cantidad_pedida']);
            $item['diferencia_facturacion'] = intval($item['cantidad_facturada']) - intval($item['cantidad_recibida']);
        }

        return [
            'pedido' => $pedido,
            'detalles' => $detalles
        ];
    }

    /**
     * Paso A: Guarda un pedido de compra en estado 'Pendiente' sin alterar stock.
     * 
     * @param array $pedidoData Datos de la cabecera del pedido
     * @param array $detallesData Array con los detalles de productos
     * @return int|bool ID del pedido guardado o FALSE en caso de error
     */
    public function guardar_pedido($pedidoData, $detallesData)
    {
        $this->db->trans_start();

        // Insertar cabecera del pedido
        $this->db->insert('pedidos', [
            'proveedor_id'  => intval($pedidoData['proveedor_id']),
            'supervisor_id' => intval($pedidoData['supervisor_id']),
            'almacen_id'    => intval($pedidoData['almacen_id']),
            'estado'        => 'Pendiente',
            'fecha_pedido'  => $pedidoData['fecha_pedido']
        ]);
        $pedidoId = $this->db->insert_id();

        // Insertar detalles del pedido
        foreach ($detallesData as $detalle) {
            $this->db->insert('pedido_detalles', [
                'pedido_id'          => $pedidoId,
                'producto_id'        => intval($detalle['producto_id']),
                'cantidad_pedida'    => intval($detalle['cantidad_pedida']),
                'cantidad_recibida'  => 0,
                'cantidad_facturada' => 0,
                'precio_unitario'    => floatval($detalle['precio_unitario'])
            ]);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === false) {
            return false;
        }

        return $pedidoId;
    }

    /**
     * Paso A (Edición): Edita un pedido existente en estado 'Pendiente'.
     * 
     * @param int $pedidoId ID del pedido a editar
     * @param array $pedidoData Datos de la cabecera del pedido
     * @param array $detallesData Array con los nuevos detalles de productos
     * @return bool TRUE si se guardó con éxito, FALSE en caso contrario
     */
    public function editar_pedido($pedidoId, $pedidoData, $detallesData)
    {
        $this->db->trans_start();

        // 1. Actualizar la cabecera del pedido
        $this->db->where('id', intval($pedidoId));
        $this->db->update('pedidos', [
            'proveedor_id'  => intval($pedidoData['proveedor_id']),
            'almacen_id'    => intval($pedidoData['almacen_id']),
        ]);

        // 2. Eliminar detalles anteriores del pedido
        $this->db->where('pedido_id', intval($pedidoId));
        $this->db->delete('pedido_detalles');

        // 3. Insertar los nuevos detalles
        foreach ($detallesData as $detalle) {
            $this->db->insert('pedido_detalles', [
                'pedido_id'          => intval($pedidoId),
                'producto_id'        => intval($detalle['producto_id']),
                'cantidad_pedida'    => intval($detalle['cantidad_pedida']),
                'cantidad_recibida'  => 0,
                'cantidad_facturada' => 0,
                'precio_unitario'    => floatval($detalle['precio_unitario'])
            ]);
        }

        $this->db->trans_complete();

        return $this->db->trans_status() !== false;
    }

    public function recibir_pedido($pedidoId, $recepcionItems, $almacenId, $observacionPedido = null)
    {
        $this->db->trans_start();

        // 1. Procesar cada ítem de recepción física (Solo auditoría, no afecta inventario físico)
        foreach ($recepcionItems as $item) {
            $prodId = intval($item['producto_id']);
            $cantRecibida = intval($item['cantidad_recibida']);
            $obsItem = isset($item['observacion_recepcion']) ? trim($item['observacion_recepcion']) : null;

            // Actualizar la cantidad recibida y observación en el detalle del pedido
            $this->db->where('pedido_id', intval($pedidoId));
            $this->db->where('producto_id', $prodId);
            $this->db->update('pedido_detalles', [
                'cantidad_recibida' => $cantRecibida,
                'observacion_recepcion' => $obsItem
            ]);
        }

        // 2. Actualizar el estado del pedido a 'Recepcionado' y guardar la observación del pedido
        $this->db->where('id', intval($pedidoId));
        $this->db->update('pedidos', [
            'estado' => 'Recepcionado',
            'observacion_recepcion' => !empty($observacionPedido) ? trim($observacionPedido) : null
        ]);

        $this->db->trans_complete();
        return $this->db->trans_status();
    }

    /**
     * Paso C: Registra la factura comercial, ejecuta conciliación de cantidades y genera Cuentas por Pagar.
     * 
     * @param array $facturaData Cabecera de la factura (pedido_id, nro_comprobante, tipo_pago, monto_total, fecha_factura)
     * @param array $facturaItems Detalle facturado de cada producto (producto_id, cantidad_facturada)
     * @param string $fechaVencimiento Fecha de vencimiento si el pago es a Crédito
     * @return int|bool ID de la factura registrada o FALSE en caso de error
     */
    public function facturar_pedido($facturaData, $facturaItems, $fechaVencimiento = null)
    {
        $this->db->trans_start();

        $pedidoId = intval($facturaData['pedido_id']);
        $tipoPago = $facturaData['tipo_pago']; // 'Al Contado' o 'A Crédito'
        $montoTotal = floatval($facturaData['monto_total']);
        $subtotal = floatval($facturaData['subtotal'] ?? $montoTotal);
        $descuentoGlobal = floatval($facturaData['descuento_global'] ?? 0.00);
        $metodoPago = isset($facturaData['metodo_pago']) ? $facturaData['metodo_pago'] : null;

        // 1. Actualizar la cabecera del pedido con los totales y descuentos comerciales
        $this->db->where('id', $pedidoId);
        $this->db->update('pedidos', [
            'estado' => 'Facturado/Liquidado',
            'descuento_global' => $descuentoGlobal,
            'subtotal' => $subtotal,
            'total_neto' => $montoTotal
        ]);

        // Obtener el almacen_id de destino y el supervisor del pedido para inyección de stock y egresos de caja
        $pedidoObj = $this->db->select('almacen_id, supervisor_id')->where('id', $pedidoId)->get('pedidos')->row();
        $almacenId = $pedidoObj ? intval($pedidoObj->almacen_id) : 0;
        $supervisorId = $pedidoObj ? intval($pedidoObj->supervisor_id) : 0;

        // 2. Actualizar las cantidades facturadas, nuevos precios y descuentos por ítem
        foreach ($facturaItems as $item) {
            $prodId = intval($item['producto_id']);
            $cantFacturada = intval($item['cantidad_facturada']);
            $precioCompraFacturado = floatval($item['precio_compra_facturado']);
            $precioMayoristaNuevo = floatval($item['precio_mayorista_nuevo']);
            $precioPublicoNuevo = floatval($item['precio_publico_nuevo']);
            $descuentoItem = floatval($item['descuento_item'] ?? 0.00);
            $precioTotalItem = floatval($item['precio_total_item'] ?? ($cantFacturada * $precioCompraFacturado - $descuentoItem));

            // Actualizar detalle de conciliación
            $this->db->where('pedido_id', $pedidoId);
            $this->db->where('producto_id', $prodId);
            $this->db->update('pedido_detalles', [
                'cantidad_facturada' => $cantFacturada,
                'precio_compra_facturado' => $precioCompraFacturado,
                'precio_mayorista_nuevo' => $precioMayoristaNuevo,
                'precio_publico_nuevo' => $precioPublicoNuevo,
                'descuento_item' => $descuentoItem,
                'precio_total_item' => $precioTotalItem
            ]);

            // Regla de Precios (Mitigación Inflacionaria):
            $this->db->where('id', $prodId);
            $this->db->update('productos', [
                'preciolocal' => $precioCompraFacturado,
                'nuevoprecio' => $precioMayoristaNuevo,
                'precioventa' => $precioPublicoNuevo
            ]);

            // Regla de Inventario (Ingreso Oficial de Stock al Depósito Asignado):
            if ($cantFacturada > 0 && $almacenId > 0) {
                // A. Sumar stock en tabla conciliadora inventario_stock
                $this->db->where('producto_id', $prodId);
                $this->db->where('almacen_id', $almacenId);
                $stockExistente = $this->db->get('inventario_stock')->row();

                if ($stockExistente) {
                    $this->db->where('producto_id', $prodId);
                    $this->db->where('almacen_id', $almacenId);
                    $this->db->set('stock', 'stock + ' . $cantFacturada, false);
                    $this->db->update('inventario_stock');
                } else {
                    $this->db->insert('inventario_stock', [
                        'producto_id' => $prodId,
                        'almacen_id' => $almacenId,
                        'stock' => $cantFacturada
                    ]);
                }

                // B. Sincronizar stock con inventarios (Crear lote en tabla de ventas)
                $prodObj = $this->db->where('id', $prodId)->get('productos')->row();
                if ($prodObj) {
                    $this->db->insert('inventarios', [
                        'idprod' => $prodObj->idprod,
                        'descripcion' => $prodObj->descripcion,
                        'marca' => $prodObj->marca,
                        'idmarca' => $prodObj->idmarca,
                        'categoria' => $prodObj->categoria,
                        'idcategoria' => $prodObj->idcategoria,
                        'unidad' => $prodObj->unidad,
                        'preciolocal' => $precioCompraFacturado,
                        'precioventa' => $precioPublicoNuevo,
                        'preciomayor' => $precioMayoristaNuevo,
                        'cantidad' => $cantFacturada,
                        'cantidad_inicial' => $cantFacturada,
                        'comision' => $prodObj->comision ?? 0,
                        'deposito' => $almacenId,
                        'proveedor' => $prodObj->proveedor,
                        'imagenes' => $prodObj->imagen ?? null,
                        'fecha_ingreso' => date('Y-m-d H:i:s')
                    ]);
                    $loteId = $this->db->insert_id();

                    // C. Registrar ingreso oficial en Kardex vinculando el lote creado
                    $this->db->insert('kardex', [
                        'producto_id' => $prodId,
                        'almacen_id' => $almacenId,
                        'lote_id' => $loteId,
                        'cantidad' => $cantFacturada,
                        'concepto' => 'INGRESO_POR_COMPRA_FACTURA',
                        'tipo_movimiento' => 'INGRESO',
                        'referencia_id' => $pedidoId
                    ]);
                }
            }
        }

        // 3. Crear cabecera de compras_facturas
        $estadoPago = ($tipoPago === 'Al Contado' ? 'Pagado' : 'Pendiente');
        $this->db->insert('compras_facturas', [
            'pedido_id'       => $pedidoId,
            'nro_comprobante' => $facturaData['nro_comprobante'],
            'tipo_pago'       => $tipoPago,
            'metodo_pago'     => $metodoPago,
            'adjunto'         => isset($facturaData['adjunto']) ? $facturaData['adjunto'] : null,
            'monto_total'     => $montoTotal,
            'estado_pago'     => $estadoPago,
            'fecha_factura'   => $facturaData['fecha_factura']
        ]);
        $facturaId = $this->db->insert_id();

        // 4. Si el tipo de pago es A Crédito, generar la cuenta por pagar
        if ($tipoPago === 'A Crédito') {
            $vencimiento = !empty($fechaVencimiento) ? $fechaVencimiento : date('Y-m-d', strtotime('+30 days'));
            $this->db->insert('cuentas_por_pagar', [
                'factura_id'        => $facturaId,
                'saldo_pendiente'   => $montoTotal,
                'fecha_vencimiento' => $vencimiento,
                'estado'            => 'Activo'
            ]);
        }

        // 5. Egreso de Caja automático para pagos al contado en efectivo
        if ($tipoPago === 'Al Contado' && $metodoPago === 'Efectivo') {
            $caja = null;
            if ($supervisorId > 0) {
                $caja = $this->db->where('usuario_id', $supervisorId)
                                 ->where('estado', 'Abierta')
                                 ->get('sesiones_caja')
                                 ->row();
            }
            if ($caja) {
                $this->db->insert('movimientos_caja', [
                    'caja_id' => $caja->id,
                    'usuario_id' => $supervisorId,
                    'tipo' => 'Egreso',
                    'detalle' => 'Pago Factura Compra',
                    'monto' => $montoTotal,
                    'informacion_adicional' => 'Egreso automático por pago de Factura/Recibo Nro ' . $facturaData['nro_comprobante'] . ' al contado',
                    'fecha_registro' => date('Y-m-d H:i:s')
                ]);
            }
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === false) {
            return false;
        }

        return $facturaId;
    }

    /**
     * Registra un pago/abono parcial a una cuenta por pagar.
     * Actualiza el saldo pendiente y cancela la cuenta/factura si el saldo llega a 0.
     * 
     * @param array $pagoData Datos del pago (cuenta_id, monto_pagado, fecha_pago, metodo_pago, nota)
     * @return int|bool ID del pago insertado o FALSE en caso de error
     */
    public function registrar_pago_cuota($pagoData)
    {
        $this->db->trans_start();

        $cuentaId = intval($pagoData['cuenta_id']);
        $montoPagado = floatval($pagoData['monto_pagado']);

        // 1. Registrar el abono en cuentas_por_pagar_pagos
        $this->db->insert('cuentas_por_pagar_pagos', [
            'cuenta_id'    => $cuentaId,
            'usuario_id'   => isset($pagoData['usuario_id']) ? intval($pagoData['usuario_id']) : null,
            'monto_pagado' => $montoPagado,
            'fecha_pago'   => $pagoData['fecha_pago'] ?? date('Y-m-d'),
            'metodo_pago'  => $pagoData['metodo_pago'] ?? 'Efectivo',
            'nota'         => $pagoData['nota'] ?? null
        ]);
        $pagoId = $this->db->insert_id();

        // 2. Restar del saldo pendiente de la cuenta por pagar
        $this->db->where('id', $cuentaId);
        $this->db->set('saldo_pendiente', 'saldo_pendiente - ' . $montoPagado, false);
        $this->db->update('cuentas_por_pagar');

        // 3. Consultar el nuevo saldo pendiente
        $this->db->where('id', $cuentaId);
        $cuenta = $this->db->get('cuentas_por_pagar')->row_array();

        if ($cuenta && floatval($cuenta['saldo_pendiente']) <= 0.00) {
            // Actualizar estado de la cuenta por pagar a 'Cancelado' y forzar saldo a 0
            $this->db->where('id', $cuentaId);
            $this->db->update('cuentas_por_pagar', [
                'saldo_pendiente' => 0.00,
                'estado'          => 'Cancelado'
            ]);

            // Actualizar estado de la factura asociada en compras_facturas a 'Pagado'
            $this->db->where('id', intval($cuenta['factura_id']));
            $this->db->update('compras_facturas', [
                'estado_pago' => 'Pagado'
            ]);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === false) {
            return false;
        }

        return $pagoId;
    }

    /**
     * Obtiene la lista de abonos realizados a una cuenta por pagar específica.
     * 
     * @param int $cuentaId ID de la cuenta por pagar
     * @return array Lista de pagos
     */
    public function get_pagos_by_cuenta($cuentaId)
    {
        $this->db->select('cppp.*, v.nombre as usuario_nombre');
        $this->db->from('cuentas_por_pagar_pagos cppp');
        $this->db->join('vendedores v', 'cppp.usuario_id = v.id', 'left');
        $this->db->where('cppp.cuenta_id', intval($cuentaId));
        $this->db->order_by('cppp.creado_at', 'DESC');
        return $this->db->get()->result_array();
    }

    /**
     * Registra un traspaso interno de mercancía entre almacenes y actualiza stocks.
     */
    public function registrar_transferencia($origenAlmacenId, $destinoAlmacenId, $usuarioId, $items)
    {
        $this->db->trans_start();

        // 1. Registrar cabecera del traspaso
        $this->db->insert('transferencias', [
            'almacen_origen_id' => intval($origenAlmacenId),
            'almacen_destino_id' => intval($destinoAlmacenId),
            'usuario_id'         => intval($usuarioId),
            'estado'             => 'Completado',
            'fecha'              => date('Y-m-d H:i:s')
        ]);
        $transferenciaId = $this->db->insert_id();

        // 2. Procesar ítems
        foreach ($items as $item) {
            $prodId = intval($item['producto_id']);
            $cantidad = intval($item['cantidad']);

            if ($cantidad <= 0) {
                continue;
            }

            // Registrar detalle
            $this->db->insert('transferencias_detalles', [
                'transferencia_id' => $transferenciaId,
                'producto_id'      => $prodId,
                'cantidad'         => $cantidad
            ]);

            // --- DECREMENTAR EN ORIGEN ---
            // A. Decrementar en inventario_stock
            $this->db->where('producto_id', $prodId);
            $this->db->where('almacen_id', intval($origenAlmacenId));
            $this->db->set('stock', 'stock - ' . $cantidad, false);
            $this->db->update('inventario_stock');

            // B. Decrementar en inventarios (POS) aplicando FIFO por lotes y transferir al destino
            $prodObj = $this->db->where('id', $prodId)->get('productos')->row();
            if ($prodObj) {
                $cantPendiente = $cantidad;
                
                // Buscar lotes de origen ordenados por fecha_ingreso (FIFO)
                $this->db->from('inventarios');
                $this->db->where('idprod', $prodObj->idprod);
                $this->db->where('deposito', intval($origenAlmacenId));
                $this->db->where('cantidad >', 0);
                $this->db->order_by('fecha_ingreso', 'ASC');
                $lotesOrigen = $this->db->get()->result();

                foreach ($lotesOrigen as $loteO) {
                    if ($cantPendiente <= 0) {
                        break;
                    }

                    $descuento = min($loteO->cantidad, $cantPendiente);

                    // A. Decrementar en el lote origen
                    $this->db->where('id', $loteO->id);
                    $this->db->set('cantidad', 'cantidad - ' . $descuento, false);
                    $this->db->update('inventarios');

                    // B. Crear lote espejo en el almacén destino con la misma información financiera
                    $this->db->insert('inventarios', [
                        'idprod' => $loteO->idprod,
                        'descripcion' => $loteO->descripcion,
                        'marca' => $loteO->marca,
                        'idmarca' => $loteO->idmarca,
                        'categoria' => $loteO->categoria,
                        'idcategoria' => $loteO->idcategoria,
                        'unidad' => $loteO->unidad,
                        'preciolocal' => $loteO->preciolocal,
                        'precioventa' => $loteO->precioventa,
                        'preciomayor' => $loteO->preciomayor,
                        'cantidad' => $descuento,
                        'cantidad_inicial' => $descuento,
                        'comision' => $loteO->comision,
                        'deposito' => intval($destinoAlmacenId),
                        'proveedor' => $loteO->proveedor,
                        'imagenes' => $loteO->imagenes,
                        'fecha_ingreso' => date('Y-m-d H:i:s')
                    ]);
                    $loteDestinoId = $this->db->insert_id();

                    // C. Registrar Kardex salida en origen
                    $this->db->insert('kardex', [
                        'producto_id' => $prodId,
                        'almacen_id' => intval($origenAlmacenId),
                        'lote_id' => $loteO->id,
                        'cantidad' => $descuento,
                        'concepto' => 'TRASPASO_SALIDA',
                        'tipo_movimiento' => 'EGRESO',
                        'referencia_id' => $transferenciaId
                    ]);

                    // D. Registrar Kardex entrada en destino
                    $this->db->insert('kardex', [
                        'producto_id' => $prodId,
                        'almacen_id' => intval($destinoAlmacenId),
                        'lote_id' => $loteDestinoId,
                        'cantidad' => $descuento,
                        'concepto' => 'TRASPASO_ENTRADA',
                        'tipo_movimiento' => 'INGRESO',
                        'referencia_id' => $transferenciaId
                    ]);

                    $cantPendiente -= $descuento;
                }

                // Si por alguna razón queda saldo pendiente (por ejemplo, stock heredado sin lote o negativo en origen)
                if ($cantPendiente > 0) {
                    $this->db->insert('inventarios', [
                        'idprod' => $prodObj->idprod,
                        'descripcion' => $prodObj->descripcion,
                        'marca' => $prodObj->marca,
                        'idmarca' => $prodObj->idmarca,
                        'categoria' => $prodObj->categoria,
                        'idcategoria' => $prodObj->idcategoria,
                        'unidad' => $prodObj->unidad,
                        'preciolocal' => $prodObj->preciolocal,
                        'precioventa' => $prodObj->precioventa,
                        'preciomayor' => $prodObj->nuevoprecio,
                        'cantidad' => $cantPendiente,
                        'cantidad_inicial' => $cantPendiente,
                        'comision' => $prodObj->comision ?? 0,
                        'deposito' => intval($destinoAlmacenId),
                        'proveedor' => $prodObj->proveedor,
                        'imagenes' => $prodObj->imagen ?? null,
                        'fecha_ingreso' => date('Y-m-d H:i:s')
                    ]);
                    $loteDestinoId = $this->db->insert_id();

                    $this->db->insert('kardex', [
                        'producto_id' => $prodId,
                        'almacen_id' => intval($origenAlmacenId),
                        'lote_id' => null,
                        'cantidad' => $cantPendiente,
                        'concepto' => 'TRASPASO_SALIDA',
                        'tipo_movimiento' => 'EGRESO',
                        'referencia_id' => $transferenciaId
                    ]);

                    $this->db->insert('kardex', [
                        'producto_id' => $prodId,
                        'almacen_id' => intval($destinoAlmacenId),
                        'lote_id' => $loteDestinoId,
                        'cantidad' => $cantPendiente,
                        'concepto' => 'TRASPASO_ENTRADA',
                        'tipo_movimiento' => 'INGRESO',
                        'referencia_id' => $transferenciaId
                    ]);
                }
            }
        }

        $this->db->trans_complete();
        return $this->db->trans_status();
    }
}
