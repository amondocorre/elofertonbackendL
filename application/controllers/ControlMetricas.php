<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class ControlMetricas extends CI_Controller {

    public function __construct() {
        parent::__construct();
        // CORS Headers
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-User-Id, X-Rol-Id, X-Active-Branch');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit();
        }

        $this->load->database();
        $this->inicializar_tablas();
    }

    /**
     * Inicializa las tablas y columnas necesarias para el módulo de métricas y registro de reclutadores
     */
    private function inicializar_tablas() {
        try {
            // 1. Asegurar columna 'registrado_por' en tabla vendedores
            if ($this->db->table_exists('vendedores')) {
                $fields = $this->db->list_fields('vendedores');
                if (!in_array('registrado_por', $fields)) {
                    $this->db->query("ALTER TABLE `vendedores` ADD COLUMN `registrado_por` INT(11) NULL DEFAULT NULL AFTER `ciudad`");
                }
            }

            // 2. Crear tabla para almacenar metas y ajustes mensuales de métricas
            $this->db->query("CREATE TABLE IF NOT EXISTS `metricas_gerencia_mensual` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `anio` INT(4) NOT NULL,
                `mes` INT(2) NOT NULL,
                `vendedor_id` INT(11) NOT NULL,
                `tipo_usuario` ENUM('encargado', 'vendedor') DEFAULT 'encargado',
                `ventas_dinero_meta` DECIMAL(12,2) DEFAULT 0.00,
                `ventas_transacciones_meta` INT(11) DEFAULT 0,
                `vend_obj_meta` INT(11) DEFAULT 0,
                `vendedores_nuevos_meta` INT(11) DEFAULT 0,
                `ventas_mes_meta` INT(11) DEFAULT 0,
                `estado` ENUM('activo', 'excluido') DEFAULT 'activo',
                `creado_por` INT(11) DEFAULT NULL,
                `fecha_registro` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `fecha_actualizacion` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_periodo_vendedor` (`anio`, `mes`, `vendedor_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            // Asegurar columna estado si la tabla ya existía
            if ($this->db->table_exists('metricas_gerencia_mensual')) {
                $mFields = $this->db->list_fields('metricas_gerencia_mensual');
                if (!in_array('estado', $mFields)) {
                    $this->db->query("ALTER TABLE `metricas_gerencia_mensual` ADD COLUMN `estado` ENUM('activo', 'excluido') DEFAULT 'activo' AFTER `ventas_mes_meta`");
                }
            }

            // 3. Crear tabla para configuración global del mes (días hábiles y trabajados editables)
            $this->db->query("CREATE TABLE IF NOT EXISTS `metricas_config_mes` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `anio` INT(4) NOT NULL,
                `mes` INT(2) NOT NULL,
                `dias_habiles` INT(3) NOT NULL,
                `dias_trabajados` INT(3) NOT NULL,
                `actualizado_por` INT(11) DEFAULT NULL,
                `fecha_actualizacion` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_periodo_mes` (`anio`, `mes`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            // 4. Registrar en la tabla modulos_menu para que aparezca en el panel de Roles y Permisos de SISVEN
            if ($this->db->table_exists('modulos_menu')) {
                $modExiste = $this->db->group_start()
                    ->where('nombre_modulo', 'Control Métricas')
                    ->or_where('url', 'Control Métricas')
                    ->group_end()
                    ->get('modulos_menu')
                    ->row();

                $sistemaId = 1;
                if ($this->db->table_exists('sistemas')) {
                    $sisRow = $this->db->get_where('sistemas', ['nombre_sistema' => 'SISVEN'])->row();
                    if ($sisRow) $sistemaId = $sisRow->id;
                }

                if (!$modExiste) {
                    $this->db->insert('modulos_menu', [
                        'nombre_modulo' => 'Control Métricas',
                        'url' => 'Control Métricas',
                        'icono' => '🎯',
                        'id_padre' => null,
                        'orden' => 15,
                        'id_sistema' => $sistemaId
                    ]);
                    $moduloId = $this->db->insert_id();

                    // Asignar permisos automáticos al rol Administrador (id_rol = 1)
                    if ($this->db->table_exists('permisos_roles') && $moduloId) {
                        $this->db->insert('permisos_roles', [
                            'id_rol' => 1,
                            'id_modulo' => $moduloId,
                            'ver' => 1,
                            'crear' => 1,
                            'editar' => 1,
                            'eliminar' => 1
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Error en inicializar_tablas ControlMetricas: ' . $e->getMessage());
        }
    }

    /**
     * Calcula días hábiles (Lunes a Sábado) en un mes y días transcurridos
     */
    private function calcular_dias_mes($anio, $mes) {
        $totalDias = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
        $diasHabiles = 0;
        $diasTrabajados = 0;
        
        $hoy = date('Y-m-d');
        $anioHoy = intval(date('Y'));
        $mesHoy = intval(date('m'));
        $diaHoy = intval(date('d'));

        for ($d = 1; $d <= $totalDias; $d++) {
            $fechaDia = sprintf('%04d-%02d-%02d', $anio, $mes, $d);
            $diaSemana = date('N', strtotime($fechaDia)); // 1 (Lun) a 7 (Dom)
            
            // Lunes (1) a Sábado (6)
            if ($diaSemana <= 6) {
                $diasHabiles++;
                
                if ($anio < $anioHoy || ($anio == $anioHoy && $mes < $mesHoy)) {
                    // Mes pasado completado
                    $diasTrabajados++;
                } elseif ($anio == $anioHoy && $mes == $mesHoy) {
                    // Mes actual: contar hasta hoy
                    if ($d <= $diaHoy) {
                        $diasTrabajados++;
                    }
                }
            }
        }

        // Si es un mes futuro
        if ($anio > $anioHoy || ($anio == $anioHoy && $mes > $mesHoy)) {
            $diasTrabajados = 0;
        }

        return [
            'dias_habiles' => $diasHabiles,
            'dias_trabajados' => max(1, $diasTrabajados) // Evitar división por cero
        ];
    }

    /**
     * Obtiene el listado completo de métricas por mes y año
     */
    public function obtener_metricas() {
        $mes = intval($this->input->get('mes') ?: date('m'));
        $anio = intval($this->input->get('anio') ?: date('Y'));

        // Obtener configuración de días
        $calcDias = $this->calcular_dias_mes($anio, $mes);
        $confRow = $this->db->get_where('metricas_config_mes', ['anio' => $anio, 'mes' => $mes])->row();
        
        $diasHabiles = $confRow ? intval($confRow->dias_habiles) : $calcDias['dias_habiles'];
        $diasTrabajados = $confRow ? intval($confRow->dias_trabajados) : $calcDias['dias_trabajados'];
        $diasRestantes = max(0, $diasHabiles - $diasTrabajados);

        // 1. Obtener encargados de tienda activos
        // Un encargado tiene rol 'encargado de tienda', 'enc. tienda y caja' o similar
        $encargadosQuery = $this->db->query("
            SELECT DISTINCT 
                v.id, 
                v.nombre, 
                v.ciudad as sucursal_id, 
                COALESCE(d.nombre, 'Sin Sucursal') as sucursal_nombre,
                'encargado' as tipo_usuario
            FROM vendedores v
            LEFT JOIN depositos d ON v.ciudad = d.id
            LEFT JOIN vendedores_roles vr ON v.id = vr.vendedor_id
            WHERE v.estado = 'activo'
              AND (
                  TRIM(LOWER(v.rol)) LIKE '%encargad%' 
                  OR TRIM(LOWER(v.rol)) LIKE '%caja%'
                  OR TRIM(LOWER(vr.rol)) LIKE '%encargad%'
                  OR TRIM(LOWER(vr.rol)) LIKE '%caja%'
              )
            ORDER BY sucursal_nombre ASC, v.nombre ASC
        ")->result_array();

        // 2. Obtener vendedores adicionales seleccionados guardados en la tabla de métricas para este mes
        $vendedoresGuardadosQuery = $this->db->query("
            SELECT DISTINCT 
                v.id, 
                v.nombre, 
                v.ciudad as sucursal_id, 
                COALESCE(d.nombre, 'Sin Sucursal') as sucursal_nombre,
                m.tipo_usuario,
                m.estado as estado_metrica
            FROM metricas_gerencia_mensual m
            JOIN vendedores v ON m.vendedor_id = v.id
            LEFT JOIN depositos d ON v.ciudad = d.id
            WHERE m.anio = ? AND m.mes = ?
        ", [$anio, $mes])->result_array();

        // 3. Cargar metas y estados almacenados previamente para el mes
        $metasExistentes = $this->db->get_where('metricas_gerencia_mensual', [
            'anio' => $anio,
            'mes' => $mes
        ])->result_array();

        $metasMap = [];
        $excluidosIds = [];
        foreach ($metasExistentes as $m) {
            $metasMap[$m['vendedor_id']] = $m;
            if (isset($m['estado']) && $m['estado'] === 'excluido') {
                $excluidosIds[] = intval($m['vendedor_id']);
            }
        }

        // Unificar lista evitando duplicados y omitiendo excluidos
        $usuariosMap = [];
        foreach ($encargadosQuery as $enc) {
            if (!in_array(intval($enc['id']), $excluidosIds)) {
                $usuariosMap[$enc['id']] = $enc;
            }
        }
        foreach ($vendedoresGuardadosQuery as $vend) {
            if (!in_array(intval($vend['id']), $excluidosIds)) {
                if (!isset($usuariosMap[$vend['id']])) {
                    $usuariosMap[$vend['id']] = $vend;
                } else {
                    if (isset($vend['tipo_usuario'])) {
                        $usuariosMap[$vend['id']]['tipo_usuario'] = $vend['tipo_usuario'];
                    }
                }
            }
        }

        $vendedoresList = array_values($usuariosMap);

        // Rango de fechas para el mes analizado
        $fechaInicio = sprintf('%04d-%02d-01 00:00:00', $anio, $mes);
        $totalDiasMes = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
        $fechaFin = sprintf('%04d-%02d-%02d 23:59:59', $anio, $mes, $totalDiasMes);

        // 4. Calcular métricas dinámicas para cada usuario/sucursal
        $filas = [];
        $totalGlobal = [
            'ventas_dinero' => 0,
            'va' => 0,
            'proyeccion' => 0,
            'necesidad_diaria' => 0,
            'ventas_transacciones' => 0,
            'ta' => 0,
            'vend_obj' => 0,
            'ves_act_mes' => 0,
            'vendedores_nuevos_meta' => 0,
            'vendedores_nuevos' => 0,
            'ventas_encargadas' => 0,
            'ventas_mes' => 0,
            'bono_dinero' => 0,
            'bono_ta' => 0,
            'bono_ves_act' => 0,
            'bono_nuevos' => 0,
            'total_bonos' => 0
        ];

        foreach ($vendedoresList as $item) {
            $vendedorId = intval($item['id']);
            $sucursalId = intval($item['sucursal_id']);
            $meta = $metasMap[$vendedorId] ?? [];

            // A. Metas ingresadas por gerencia (o 0 por defecto)
            $ventasDineroMeta = floatval($meta['ventas_dinero_meta'] ?? 0);
            $ventasTxMeta = intval($meta['ventas_transacciones_meta'] ?? 0);
            $vendObjMeta = intval($meta['vend_obj_meta'] ?? 0);
            $vendNuevosMeta = intval($meta['vendedores_nuevos_meta'] ?? 0);
            $ventasMesMeta = intval($meta['ventas_mes_meta'] ?? 0);

            // B. VA: Suma de ventas en dinero de la sucursal en el mes (no anuladas)
            $vaQuery = $this->db->query("
                SELECT COALESCE(SUM(total), 0) as total_ventas, COUNT(idventa) as total_tx
                FROM ventas
                WHERE idneg = ?
                  AND fecha >= ? AND fecha <= ?
                  AND (estado IS NULL OR UPPER(TRIM(estado)) != 'ANULADO')
            ", [$sucursalId, $fechaInicio, $fechaFin])->row();

            $va = floatval($vaQuery->total_ventas ?? 0);
            $ta = intval($vaQuery->total_tx ?? 0);

            // Si es un vendedor individual (no encargado de sucursal completa), se puede medir sobre sus ventas
            if (($item['tipo_usuario'] ?? '') === 'vendedor') {
                $vaVendQuery = $this->db->query("
                    SELECT COALESCE(SUM(total), 0) as total_ventas, COUNT(idventa) as total_tx
                    FROM ventas
                    WHERE vendedor = ?
                      AND fecha >= ? AND fecha <= ?
                      AND (estado IS NULL OR UPPER(TRIM(estado)) != 'ANULADO')
                ", [$vendedorId, $fechaInicio, $fechaFin])->row();
                $va = floatval($vaVendQuery->total_ventas ?? 0);
                $ta = intval($vaVendQuery->total_tx ?? 0);
            }

            // C. Proyección = (VA / dias_trabajados) * dias_habiles
            $proyeccion = $diasTrabajados > 0 ? round(($va / $diasTrabajados) * $diasHabiles, 2) : 0;

            // D. Tendencia % = (Proyección / Ventas Dinero) * 100 (Redondeado a entero)
            $tendenciaDinero = $ventasDineroMeta > 0 ? round(($proyeccion / $ventasDineroMeta) * 100) : 0;

            // E. Necesidad Diaria = (Ventas Dinero - VA) / (dias_habiles - dias_trabajados)
            $diasFaltantes = max(1, $diasHabiles - $diasTrabajados);
            $necesidadDiaria = max(0, round(($ventasDineroMeta - $va) / $diasFaltantes, 2));

            // F. Tendencia TA % = (TA / Ventas Transacciones) * 100 (Redondeado a entero)
            $tendenciaTA = $ventasTxMeta > 0 ? round(($ta / $ventasTxMeta) * 100) : 0;

            // G. Ves Act Mes: Conteo de vendedores distintos que hicieron >= 1 venta en la sucursal en el mes
            $vesActQuery = $this->db->query("
                SELECT COUNT(DISTINCT vendedor) as total_vendedores
                FROM ventas
                WHERE idneg = ?
                  AND fecha >= ? AND fecha <= ?
                  AND (estado IS NULL OR UPPER(TRIM(estado)) != 'ANULADO')
                  AND vendedor IS NOT NULL AND vendedor > 0
            ", [$sucursalId, $fechaInicio, $fechaFin])->row();
            $vesActMes = intval($vesActQuery->total_vendedores ?? 0);

            // Tendencia Ves Act % = (Ves Act Mes / Vend Obj) * 100 (Redondeado a entero)
            $tendenciaVes = $vendObjMeta > 0 ? round(($vesActMes / $vendObjMeta) * 100) : 0;

            // H. Vendedores Nuevos registrados en el mes por este encargado/vendedor
            $nuevosQuery = $this->db->query("
                SELECT COUNT(id) as total_nuevos
                FROM vendedores
                WHERE registrado_por = ?
                  AND fechareg >= ? AND fechareg <= ?
            ", [$vendedorId, $fechaInicio, $fechaFin])->row();
            $vendedoresNuevos = intval($nuevosQuery->total_nuevos ?? 0);

            // I. Ventas Encargadas: Ventas realizadas directamente por el usuario (vendedor = $vendedorId)
            $ventasEncQuery = $this->db->query("
                SELECT COUNT(idventa) as total_ventas_encargado
                FROM ventas
                WHERE (vendedor = ? OR idusr = ?)
                  AND fecha >= ? AND fecha <= ?
                  AND (estado IS NULL OR UPPER(TRIM(estado)) != 'ANULADO')
            ", [$vendedorId, $vendedorId, $fechaInicio, $fechaFin])->row();
            $ventasEncargadas = intval($ventasEncQuery->total_ventas_encargado ?? 0);

            // Tendencia Ventas Encargada % = (Ventas Encargadas / Ventas Mes) * 100 (Redondeado a entero)
            $tendenciaVentasEnc = $ventasMesMeta > 0 ? round(($ventasEncargadas / $ventasMesMeta) * 100) : 0;

            // J. Bonos
            $bonoDinero = ($ventasDineroMeta > 0 && $va >= $ventasDineroMeta) ? 1100 : 0;
            $bonoTA = ($ventasTxMeta > 0 && $ta >= $ventasTxMeta) ? 300 : 0;
            $bonoVesAct = ($vendObjMeta > 0 && $vesActMes >= $vendObjMeta) ? 300 : 0;
            $bonoNuevos = ($vendNuevosMeta > 0 && $vendedoresNuevos >= $vendNuevosMeta) ? 300 : 0;
            $totalBonos = $bonoDinero + $bonoTA + $bonoVesAct + $bonoNuevos;

            $fila = [
                'vendedor_id' => $vendedorId,
                'nombre' => $item['nombre'],
                'sucursal_id' => $sucursalId,
                'sucursal_nombre' => $item['sucursal_nombre'],
                'tipo_usuario' => $item['tipo_usuario'] ?? 'encargado',
                'ventas_dinero' => $ventasDineroMeta,
                'va' => $va,
                'proyeccion' => $proyeccion,
                'tendencia_dinero' => $tendenciaDinero,
                'necesidad_diaria' => $necesidadDiaria,
                'ventas_transacciones' => $ventasTxMeta,
                'ta' => $ta,
                'tendencia_ta' => $tendenciaTA,
                'vend_obj' => $vendObjMeta,
                'ves_act_mes' => $vesActMes,
                'tendencia_ves' => $tendenciaVes,
                'vendedores_nuevos_meta' => $vendNuevosMeta,
                'vendedores_nuevos' => $vendedoresNuevos,
                'ventas_encargadas' => $ventasEncargadas,
                'ventas_mes' => $ventasMesMeta,
                'tendencia_ventas_enc' => $tendenciaVentasEnc,
                'bono_dinero' => $bonoDinero,
                'bono_ta' => $bonoTA,
                'bono_ves_act' => $bonoVesAct,
                'bono_nuevos' => $bonoNuevos,
                'total_bonos' => $totalBonos
            ];

            $filas[] = $fila;

            // Acumular totales globales
            $totalGlobal['ventas_dinero'] += $ventasDineroMeta;
            $totalGlobal['va'] += $va;
            $totalGlobal['proyeccion'] += $proyeccion;
            $totalGlobal['necesidad_diaria'] += $necesidadDiaria;
            $totalGlobal['ventas_transacciones'] += $ventasTxMeta;
            $totalGlobal['ta'] += $ta;
            $totalGlobal['vend_obj'] += $vendObjMeta;
            $totalGlobal['ves_act_mes'] += $vesActMes;
            $totalGlobal['vendedores_nuevos_meta'] += $vendNuevosMeta;
            $totalGlobal['vendedores_nuevos'] += $vendedoresNuevos;
            $totalGlobal['ventas_encargadas'] += $ventasEncargadas;
            $totalGlobal['ventas_mes'] += $ventasMesMeta;
            $totalGlobal['bono_dinero'] += $bonoDinero;
            $totalGlobal['bono_ta'] += $bonoTA;
            $totalGlobal['bono_ves_act'] += $bonoVesAct;
            $totalGlobal['bono_nuevos'] += $bonoNuevos;
            $totalGlobal['total_bonos'] += $totalBonos;
        }

        // Tendencias globales redondeadas a entero
        $totalGlobal['tendencia_dinero'] = $totalGlobal['ventas_dinero'] > 0 ? round(($totalGlobal['proyeccion'] / $totalGlobal['ventas_dinero']) * 100) : 0;
        $totalGlobal['tendencia_ta'] = $totalGlobal['ventas_transacciones'] > 0 ? round(($totalGlobal['ta'] / $totalGlobal['ventas_transacciones']) * 100) : 0;
        $totalGlobal['tendencia_ves'] = $totalGlobal['vend_obj'] > 0 ? round(($totalGlobal['ves_act_mes'] / $totalGlobal['vend_obj']) * 100) : 0;
        $totalGlobal['tendencia_ventas_enc'] = $totalGlobal['ventas_mes'] > 0 ? round(($totalGlobal['ventas_encargadas'] / $totalGlobal['ventas_mes']) * 100) : 0;

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'status' => 'success',
                'mes' => $mes,
                'anio' => $anio,
                'dias_habiles' => $diasHabiles,
                'dias_trabajados' => $diasTrabajados,
                'dias_restantes' => $diasRestantes,
                'filas' => $filas,
                'total_global' => $totalGlobal
            ]));
    }

    /**
     * Guarda las metas ingresadas por gerencia para un mes específico
     */
    public function guardar_metas() {
        $data = json_decode(file_get_contents('php://input'), true);

        $mes = intval($data['mes'] ?? date('m'));
        $anio = intval($data['anio'] ?? date('Y'));
        $filas = $data['filas'] ?? [];
        $diasHabiles = isset($data['dias_habiles']) ? intval($data['dias_habiles']) : null;
        $diasTrabajados = isset($data['dias_trabajados']) ? intval($data['dias_trabajados']) : null;

        if (empty($filas)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'No se enviaron datos para guardar']));
        }

        $this->db->trans_start();

        // 1. Guardar o actualizar configuración de días del mes
        if ($diasHabiles !== null && $diasTrabajados !== null) {
            $confExist = $this->db->get_where('metricas_config_mes', ['anio' => $anio, 'mes' => $mes])->row();
            if ($confExist) {
                $this->db->where('id', $confExist->id)->update('metricas_config_mes', [
                    'dias_habiles' => $diasHabiles,
                    'dias_trabajados' => $diasTrabajados
                ]);
            } else {
                $this->db->insert('metricas_config_mes', [
                    'anio' => $anio,
                    'mes' => $mes,
                    'dias_habiles' => $diasHabiles,
                    'dias_trabajados' => $diasTrabajados
                ]);
            }
        }

        // 2. Guardar o actualizar metas por cada vendedor
        foreach ($filas as $f) {
            $vendedorId = intval($f['vendedor_id']);
            if ($vendedorId <= 0) continue;

            $metaData = [
                'anio' => $anio,
                'mes' => $mes,
                'vendedor_id' => $vendedorId,
                'tipo_usuario' => $f['tipo_usuario'] ?? 'encargado',
                'ventas_dinero_meta' => floatval($f['ventas_dinero'] ?? 0),
                'ventas_transacciones_meta' => intval($f['ventas_transacciones'] ?? 0),
                'vend_obj_meta' => intval($f['vend_obj'] ?? 0),
                'vendedores_nuevos_meta' => intval($f['vendedores_nuevos_meta'] ?? 0),
                'ventas_mes_meta' => intval($f['ventas_mes'] ?? 0)
            ];

            $existente = $this->db->get_where('metricas_gerencia_mensual', [
                'anio' => $anio,
                'mes' => $mes,
                'vendedor_id' => $vendedorId
            ])->row();

            if ($existente) {
                $this->db->where('id', $existente->id)->update('metricas_gerencia_mensual', $metaData);
            } else {
                $this->db->insert('metricas_gerencia_mensual', $metaData);
            }
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al guardar las metas en la base de datos']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'status' => 'success',
                'message' => 'Metas del mes guardadas exitosamente'
            ]));
    }

    /**
     * Busca vendedores activos para añadir manualmente a la matriz de métricas
     */
    public function buscar_vendedores() {
        $q = trim($this->input->get('q') ?? '');

        $this->db->select('v.id, v.nombre, v.ciudad as sucursal_id, COALESCE(d.nombre, "Sin Sucursal") as sucursal_nombre, v.rol');
        $this->db->from('vendedores v');
        $this->db->join('depositos d', 'v.ciudad = d.id', 'left');
        $this->db->where('v.estado', 'activo');

        if (!empty($q)) {
            $this->db->group_start();
            $this->db->like('v.nombre', $q);
            $this->db->or_like('v.email', $q);
            $this->db->group_end();
        }

        $this->db->order_by('v.nombre', 'ASC');
        $this->db->limit(30);
        $vendedores = $this->db->get()->result_array();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode($vendedores));
    }

    /**
     * Retira/excluye a un vendedor o encargado de la matriz de métricas del mes
     */
    public function retirar_vendedor() {
        $data = json_decode(file_get_contents('php://input'), true);

        $mes = intval($data['mes'] ?? date('m'));
        $anio = intval($data['anio'] ?? date('Y'));
        $vendedorId = intval($data['vendedor_id'] ?? 0);

        if ($vendedorId <= 0) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'ID de vendedor inválido']));
        }

        $existente = $this->db->get_where('metricas_gerencia_mensual', [
            'anio' => $anio,
            'mes' => $mes,
            'vendedor_id' => $vendedorId
        ])->row();

        if ($existente) {
            // Si ya existe registro de metas, marcarlo como excluido
            $this->db->where('id', $existente->id)->update('metricas_gerencia_mensual', [
                'estado' => 'excluido'
            ]);
        } else {
            // Si es un encargado cargado dinámicamente, insertar registro como excluido
            $this->db->insert('metricas_gerencia_mensual', [
                'anio' => $anio,
                'mes' => $mes,
                'vendedor_id' => $vendedorId,
                'tipo_usuario' => 'encargado',
                'estado' => 'excluido'
            ]);
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'status' => 'success',
                'message' => 'Vendedor retirado de la medición mensual correctamente'
            ]));
    }
}
