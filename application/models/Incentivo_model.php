<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Incentivo_model extends CI_Model {

    public function __construct() {
        parent::__construct();
        $this->load->database();
    }

    /**
     * Obtiene la lista de escalas de incentivos ordenadas de mayor a menor.
     */
    public function obtener_escalas() {
        return $this->db->order_by('min_ventas', 'DESC')->get('escalas_incentivos')->result();
    }

    /**
     * Calcula la escala alcanzada y el bono correspondiente según las unidades vendidas.
     */
    public function evaluar_escala($unidades, $escalas = null) {
        if ($escalas === null) {
            $escalas = $this->obtener_escalas();
        }

        $escalaAlcanzada = null;
        $montoBono = 0.00;
        $siguienteEscala = null;
        $unidadesFaltantes = 0;

        // Las escalas vienen ordenadas descendentemente: 140, 80, 60, 50, 25, 15, 10, 5
        $escalasAsc = array_reverse($escalas);
        // Buscar siguiente meta
        foreach ($escalasAsc as $esc) {
            if ($unidades < intval($esc->min_ventas)) {
                $siguienteEscala = $esc;
                $unidadesFaltantes = intval($esc->min_ventas) - $unidades;
                break;
            }
        }

        // Buscar escala actual alcanzada
        foreach ($escalas as $esc) {
            if ($unidades >= intval($esc->min_ventas)) {
                $escalaAlcanzada = $esc;
                $montoBono = floatval($esc->monto_bono);
                break;
            }
        }

        return [
            'escala_id'          => $escalaAlcanzada ? intval($escalaAlcanzada->id) : null,
            'min_ventas'         => $escalaAlcanzada ? intval($escalaAlcanzada->min_ventas) : 0,
            'monto_bono'         => $montoBono,
            'siguiente_escala'   => $siguienteEscala ? [
                'min_ventas' => intval($siguienteEscala->min_ventas),
                'monto_bono' => floatval($siguienteEscala->monto_bono)
            ] : null,
            'unidades_faltantes' => $unidadesFaltantes
        ];
    }

    /**
     * Calcula y sincroniza el progreso mensual de un vendedor en un período (YYYY-MM).
     */
    public function calcular_progreso_vendedor($vendedor_id, $periodo = null) {
        if (!$periodo) {
            $periodo = date('Y-m');
        }

        $fecha_inicio = "{$periodo}-01 00:00:00";
        $ultimo_dia = date('t', strtotime($fecha_inicio));
        $fecha_fin = "{$periodo}-{$ultimo_dia} 23:59:59";

        $sql = "
            SELECT 
                v.idneg AS sucursal_id,
                COALESCE(d.nombre, CONCAT('Sucursal ', v.idneg)) AS sucursal_nombre,
                SUM(dv.cuantos) AS unidades_sucursal
            FROM ventas v
            JOIN detalleventas dv ON v.idventa = dv.idventa
            LEFT JOIN depositos d ON v.idneg = d.id
            WHERE (COALESCE(NULLIF(CAST(dv.vendedor AS SIGNED), 0), NULLIF(CAST(v.vendedor AS SIGNED), 0), CAST(v.idusr AS SIGNED)) = ?)
              AND v.fecha >= ? AND v.fecha <= ?
              AND (v.estado IS NULL OR UPPER(TRIM(v.estado)) != 'ANULADO')
              AND dv.cuantos > 0
              AND (dv.comision / dv.cuantos) >= 40.00
            GROUP BY v.idneg
            ORDER BY unidades_sucursal DESC
        ";

        $sucursalesCubiertas = $this->db->query($sql, [(string)$vendedor_id, $fecha_inicio, $fecha_fin])->result();
        $totalUnidades = 0;
        foreach ($sucursalesCubiertas as $sc) {
            $totalUnidades += intval($sc->unidades_sucursal);
        }

        $totalSucursales = count($sucursalesCubiertas);
        $cumpleSucursales = ($totalSucursales >= 3);

        // 3. Evaluar escala y bono
        $escalas = $this->obtener_escalas();
        $evaluacion = $this->evaluar_escala($totalUnidades, $escalas);

        // 4. Determinar estado de pago
        // Estados: 'PENDIENTE', 'PAGADO', 'BLOQUEADO', 'NO_APLICA'
        $nuevoEstado = 'NO_APLICA';
        if ($totalUnidades >= 5) {
            if ($cumpleSucursales) {
                $nuevoEstado = 'PENDIENTE';
            } else {
                $nuevoEstado = 'BLOQUEADO';
            }
        }

        // 5. Sincronizar con tabla logros_mensuales_vendedores
        $registroExistente = $this->db->get_where('logros_mensuales_vendedores', [
            'vendedor_id' => $vendedor_id,
            'periodo'     => $periodo
        ])->row();

        if ($registroExistente) {
            $updateData = [
                'total_unidades_calificadas' => $totalUnidades,
                'total_sucursales_cubiertas' => $totalSucursales,
                'escala_id'                  => $evaluacion['escala_id'],
                'monto_bono'                 => $evaluacion['monto_bono'],
                'cumple_sucursales'          => $cumpleSucursales ? 1 : 0
            ];

            // Si ya está pagado, no modificar el estado ni los datos de pago
            if ($registroExistente->estado_pago !== 'PAGADO') {
                $updateData['estado_pago'] = $nuevoEstado;
            }

            $this->db->where('id', $registroExistente->id);
            $this->db->update('logros_mensuales_vendedores', $updateData);
            $logroId = $registroExistente->id;
            $estadoFinal = $registroExistente->estado_pago === 'PAGADO' ? 'PAGADO' : $nuevoEstado;
            $datosPago = [
                'fecha_pago'      => $registroExistente->fecha_pago,
                'metodo_pago'     => $registroExistente->metodo_pago,
                'nro_comprobante' => $registroExistente->nro_comprobante,
                'observaciones'   => $registroExistente->observaciones
            ];
        } else {
            $insertData = [
                'vendedor_id'                => $vendedor_id,
                'periodo'                    => $periodo,
                'total_unidades_calificadas' => $totalUnidades,
                'total_sucursales_cubiertas' => $totalSucursales,
                'escala_id'                  => $evaluacion['escala_id'],
                'monto_bono'                 => $evaluacion['monto_bono'],
                'cumple_sucursales'          => $cumpleSucursales ? 1 : 0,
                'estado_pago'                => $nuevoEstado
            ];
            $this->db->insert('logros_mensuales_vendedores', $insertData);
            $logroId = $this->db->insert_id();
            $estadoFinal = $nuevoEstado;
            $datosPago = null;
        }

        return [
            'id'                         => $logroId,
            'vendedor_id'                => $vendedor_id,
            'periodo'                    => $periodo,
            'total_unidades_calificadas' => $totalUnidades,
            'total_sucursales_cubiertas' => $totalSucursales,
            'cumple_sucursales'          => $cumpleSucursales,
            'sucursales_detalle'         => $sucursalesCubiertas,
            'escala_id'                  => $evaluacion['escala_id'],
            'min_ventas_alcanzado'       => $evaluacion['min_ventas'],
            'monto_bono'                 => $evaluacion['monto_bono'],
            'siguiente_escala'           => $evaluacion['siguiente_escala'],
            'unidades_faltantes'         => $evaluacion['unidades_faltantes'],
            'estado_pago'                => $estadoFinal,
            'datos_pago'                 => $datosPago,
            'escalas_disponibles'        => $escalas
        ];
    }

    /**
     * Obtiene el listado de productos individuales vendidos por el vendedor en el mes que califican para el bono.
     */
    public function obtener_detalle_ventas_calificadas($vendedor_id, $periodo = null) {
        if (!$periodo) {
            $periodo = date('Y-m');
        }

        $fecha_inicio = "{$periodo}-01 00:00:00";
        $ultimo_dia = date('t', strtotime($fecha_inicio));
        $fecha_fin = "{$periodo}-{$ultimo_dia} 23:59:59";

        $sql = "
            SELECT 
                dv.id as id_detalle,
                dv.idprod,
                COALESCE(dv.descripcion, i.descripcion, 'Producto sin descripción') as descripcion,
                dv.cuantos as cantidad,
                dv.precioventa,
                (CASE WHEN dv.cuantos > 0 THEN (dv.comision / dv.cuantos) ELSE dv.comision END) as comision_unitaria,
                dv.comision as comision_total,
                DATE_FORMAT(v.fecha, '%d/%m/%Y %H:%i') as fecha_venta,
                v.idventa as nro_documento,
                v.id as id_venta,
                v.cliente,
                d.nombre as sucursal_nombre
            FROM ventas v
            JOIN detalleventas dv ON v.idventa = dv.idventa
            LEFT JOIN depositos d ON v.idneg = d.id
            LEFT JOIN inventarios i ON dv.idprod = i.id
            WHERE (COALESCE(NULLIF(CAST(dv.vendedor AS SIGNED), 0), NULLIF(CAST(v.vendedor AS SIGNED), 0), CAST(v.idusr AS SIGNED)) = ?)
              AND v.fecha >= ? AND v.fecha <= ?
              AND (v.estado IS NULL OR UPPER(TRIM(v.estado)) != 'ANULADO')
              AND dv.cuantos > 0
              AND (dv.comision / dv.cuantos) >= 40.00
            ORDER BY v.fecha DESC
        ";

        return $this->db->query($sql, [(int)$vendedor_id, $fecha_inicio, $fecha_fin])->result();
    }

    /**
     * Obtiene el consolidado mensual de todos los vendedores para el panel de administración optimizado.
     */
    public function obtener_consolidado_admin($periodo = null, $estado_filtro = null, $sucursal_filtro = null) {
        if (!$periodo) {
            $periodo = date('Y-m');
        }

        $fecha_inicio = "{$periodo}-01 00:00:00";
        $ultimo_dia = date('t', strtotime($fecha_inicio));
        $fecha_fin = "{$periodo}-{$ultimo_dia} 23:59:59";

        // 1. Obtener lista de escalas
        $escalas = $this->obtener_escalas();

        // 2. Consulta agregada grupal de ventas calificadas para el período
        $sqlVentas = "
            SELECT 
                COALESCE(NULLIF(CAST(dv.vendedor AS SIGNED), 0), NULLIF(CAST(v.vendedor AS SIGNED), 0), CAST(v.idusr AS SIGNED)) AS vendedor_id,
                v.idneg AS sucursal_id,
                COALESCE(d.nombre, CONCAT('Sucursal ', v.idneg)) AS sucursal_nombre,
                SUM(dv.cuantos) AS unidades_sucursal
            FROM ventas v
            JOIN detalleventas dv ON v.idventa = dv.idventa
            LEFT JOIN depositos d ON v.idneg = d.id
            WHERE v.fecha >= ? AND v.fecha <= ?
              AND (v.estado IS NULL OR UPPER(TRIM(v.estado)) != 'ANULADO')
              AND dv.cuantos > 0
              AND (dv.comision / dv.cuantos) >= 40.00
            GROUP BY vendedor_id, v.idneg
        ";
        $ventasAgrupadas = $this->db->query($sqlVentas, [$fecha_inicio, $fecha_fin])->result();

        // Estructurar sucursales y unidades por vendedor
        $vendedoresData = [];
        foreach ($ventasAgrupadas as $va) {
            $vid = (int)$va->vendedor_id;
            if (!isset($vendedoresData[$vid])) {
                $vendedoresData[$vid] = [
                    'total_unidades' => 0,
                    'sucursales'     => []
                ];
            }
            $vendedoresData[$vid]['total_unidades'] += intval($va->unidades_sucursal);
            $vendedoresData[$vid]['sucursales'][] = [
                'sucursal_id'       => $va->sucursal_id,
                'sucursal_nombre'   => $va->sucursal_nombre,
                'unidades_sucursal' => intval($va->unidades_sucursal)
            ];
        }

        // 3. Obtener registros existentes en logros_mensuales_vendedores para el periodo
        $logrosExistentes = $this->db->get_where('logros_mensuales_vendedores', ['periodo' => $periodo])->result();
        $logrosMap = [];
        foreach ($logrosExistentes as $le) {
            $logrosMap[$le->vendedor_id] = $le;
        }

        // 4. Obtener todos los vendedores válidos del sistema
        $this->db->select("v.id as vendedor_id, v.nombre as vendedor_nombre, v.carnet, v.telefono, v.nro_cuenta, v.banco, d.nombre as sucursal_base_nombre");
        $this->db->from('vendedores v');
        $this->db->join('depositos d', 'v.ciudad = d.id', 'left');
        $this->db->where('(v.recibe_comision IS NULL OR v.recibe_comision = 1)');
        $this->db->where("(TRIM(LOWER(v.rol)) IN ('vendedores', 'vendedor') OR v.id IN (SELECT vendedor_id FROM vendedores_roles WHERE TRIM(LOWER(rol)) IN ('vendedores', 'vendedor')))", NULL, FALSE);
        $this->db->order_by('v.nombre', 'ASC');

        $vendedores = $this->db->get()->result();
        $consolidado = [];

        foreach ($vendedores as $vend) {
            $vid = (int)$vend->vendedor_id;
            $dataVendedor = $vendedoresData[$vid] ?? ['total_unidades' => 0, 'sucursales' => []];
            $totalUnidades = $dataVendedor['total_unidades'];
            $sucursalesDetalle = $dataVendedor['sucursales'];
            $totalSucursales = count($sucursalesDetalle);
            $cumpleSucursales = ($totalSucursales >= 3);

            $evaluacion = $this->evaluar_escala($totalUnidades, $escalas);

            // Determinar estado de pago
            $nuevoEstado = 'NO_APLICA';
            if ($totalUnidades >= 5) {
                if ($cumpleSucursales) {
                    $nuevoEstado = 'PENDIENTE';
                } else {
                    $nuevoEstado = 'BLOQUEADO';
                }
            }

            // Sincronizar logro
            $logro = $logrosMap[$vid] ?? null;
            $datosPago = null;

            if ($logro) {
                $logroId = $logro->id;
                $estadoFinal = ($logro->estado_pago === 'PAGADO') ? 'PAGADO' : $nuevoEstado;

                // Actualizar si hubo cambios
                if ($logro->total_unidades_calificadas != $totalUnidades || $logro->total_sucursales_cubiertas != $totalSucursales || ($logro->estado_pago !== 'PAGADO' && $logro->estado_pago !== $nuevoEstado)) {
                    $updateFields = [
                        'total_unidades_calificadas' => $totalUnidades,
                        'total_sucursales_cubiertas' => $totalSucursales,
                        'escala_id'                  => $evaluacion['escala_id'],
                        'monto_bono'                 => $evaluacion['monto_bono'],
                        'cumple_sucursales'          => $cumpleSucursales ? 1 : 0
                    ];
                    if ($logro->estado_pago !== 'PAGADO') {
                        $updateFields['estado_pago'] = $nuevoEstado;
                    }
                    $this->db->where('id', $logroId)->update('logros_mensuales_vendedores', $updateFields);
                }

                if ($logro->estado_pago === 'PAGADO') {
                    $datosPago = [
                        'fecha_pago'      => $logro->fecha_pago,
                        'metodo_pago'     => $logro->metodo_pago,
                        'nro_comprobante' => $logro->nro_comprobante,
                        'observaciones'   => $logro->observaciones
                    ];
                }
            } else {
                $insertFields = [
                    'vendedor_id'                => $vid,
                    'periodo'                    => $periodo,
                    'total_unidades_calificadas' => $totalUnidades,
                    'total_sucursales_cubiertas' => $totalSucursales,
                    'escala_id'                  => $evaluacion['escala_id'],
                    'monto_bono'                 => $evaluacion['monto_bono'],
                    'cumple_sucursales'          => $cumpleSucursales ? 1 : 0,
                    'estado_pago'                => $nuevoEstado
                ];
                $this->db->insert('logros_mensuales_vendedores', $insertFields);
                $logroId = $this->db->insert_id();
                $estadoFinal = $nuevoEstado;
            }

            // Filtro de estado
            if ($estado_filtro && $estado_filtro !== 'TODOS') {
                if ($estadoFinal !== $estado_filtro) {
                    continue;
                }
            }

            // Filtro de sucursales
            if ($sucursal_filtro && $sucursal_filtro !== 'TODOS') {
                if ($sucursal_filtro === 'CUMPLE' && !$cumpleSucursales) {
                    continue;
                }
                if ($sucursal_filtro === 'NO_CUMPLE' && $cumpleSucursales) {
                    continue;
                }
            }

            $consolidado[] = [
                'id'                         => $logroId,
                'vendedor_id'                => $vid,
                'vendedor_nombre'            => $vend->vendedor_nombre,
                'carnet'                     => $vend->carnet,
                'telefono'                   => $vend->telefono,
                'nro_cuenta'                 => $vend->nro_cuenta,
                'banco'                      => $vend->banco,
                'sucursal_base'              => $vend->sucursal_base_nombre,
                'periodo'                    => $periodo,
                'total_unidades_calificadas' => $totalUnidades,
                'total_sucursales_cubiertas' => $totalSucursales,
                'cumple_sucursales'          => $cumpleSucursales,
                'sucursales_detalle'         => $sucursalesDetalle,
                'min_ventas_alcanzado'       => $evaluacion['min_ventas'],
                'monto_bono'                 => $evaluacion['monto_bono'],
                'estado_pago'                => $estadoFinal,
                'datos_pago'                 => $datosPago
            ];
        }

        return $consolidado;
    }

    /**
     * Registra el pago individual de un bono comprobando el requisito de sucursales.
     */
    public function registrar_pago_individual($id_logro, $datos_pago, $usuario_id) {
        $logro = $this->db->get_where('logros_mensuales_vendedores', ['id' => $id_logro])->row();
        if (!$logro) {
            return ['status' => false, 'error' => 'Registro de logro no encontrado.'];
        }

        if (!$logro->cumple_sucursales || intval($logro->total_sucursales_cubiertas) < 3) {
            return ['status' => false, 'error' => 'El bono está bloqueado: el vendedor no cumple con ventas en al menos 3 sucursales.'];
        }

        if (floatval($logro->monto_bono) <= 0) {
            return ['status' => false, 'error' => 'El vendedor no cuenta con un monto de bono asignado para este período.'];
        }

        $updateData = [
            'estado_pago'     => 'PAGADO',
            'fecha_pago'      => date('Y-m-d H:i:s'),
            'metodo_pago'     => $datos_pago['metodo_pago'] ?? 'TRANSFERENCIA',
            'nro_comprobante' => $datos_pago['nro_comprobante'] ?? '',
            'observaciones'   => $datos_pago['observaciones'] ?? '',
            'usuario_pago_id' => $usuario_id
        ];

        $this->db->where('id', $id_logro);
        $this->db->update('logros_mensuales_vendedores', $updateData);

        return ['status' => true, 'message' => 'Bono registrado como pagado exitosamente.'];
    }

    /**
     * Registra pagos en lote para múltiples vendedores calificados.
     */
    public function registrar_pago_masivo($logros_ids, $datos_pago, $usuario_id) {
        if (empty($logros_ids) || !is_array($logros_ids)) {
            return ['status' => false, 'error' => 'No se especificaron bonos para pagar.'];
        }

        $this->db->trans_start();
        $pagados = 0;
        $errores = [];

        foreach ($logros_ids as $id) {
            $logro = $this->db->get_where('logros_mensuales_vendedores', ['id' => $id])->row();
            if ($logro && $logro->cumple_sucursales && floatval($logro->monto_bono) > 0 && $logro->estado_pago !== 'PAGADO') {
                $this->db->where('id', $id);
                $this->db->update('logros_mensuales_vendedores', [
                    'estado_pago'     => 'PAGADO',
                    'fecha_pago'      => date('Y-m-d H:i:s'),
                    'metodo_pago'     => $datos_pago['metodo_pago'] ?? 'PAGO MASIVO BANCARIO',
                    'nro_comprobante' => $datos_pago['nro_comprobante'] ?? 'LOTE-' . date('YmdHis'),
                    'observaciones'   => $datos_pago['observaciones'] ?? 'Liquidación masiva mensual de bonos',
                    'usuario_pago_id' => $usuario_id
                ]);
                $pagados++;
            }
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return ['status' => false, 'error' => 'Error durante la transacción de pago masivo.'];
        }

        return [
            'status'  => true,
            'message' => "Se procesó el pago de {$pagados} bono(s) exitosamente.",
            'pagados' => $pagados
        ];
    }
}
