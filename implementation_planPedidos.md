# Plan de Implementación: Conciliación de Compras e Inventarios en 3 Pasos (3-Way Matching)

Este plan describe la arquitectura de base de datos, la lógica del backend y las reglas de negocio para implementar un flujo completo de conciliación de compras (3-Way Matching) con soporte multi-almacén y control de permisos.

## User Review Required

> [!IMPORTANT]
> - Se crearán 7 nuevas tablas en la base de datos (`almacenes`, `usuarios_almacenes`, `pedidos`, `pedido_detalles`, `inventario_stock`, `compras_facturas`, `cuentas_por_pagar`).
> - Se utilizará la tabla de usuarios existente (`vendedores`) para asociar supervisores y almaceneros.
> - Se creará un nuevo controlador `Conciliacion.php` con endpoints para gestionar cada uno de los 3 pasos (Creación de Pedido, Recepción Física y Registro de Factura).
> - Se validará en el paso de recepción que el almacenero tenga asignado el almacén correspondiente. En caso contrario, se retornará un código HTTP `403 (Forbidden)`.

## Proposed Changes

---

### Base de Datos (MySQL)

#### [NEW] [migrate_three_way_matching.php](file:///c:/xampp/htdocs/elofertondev/backend/migrate_three_way_matching.php)
- Un script standalone para crear la estructura de tablas necesaria:
  - **`almacenes`**: ID, Nombre del Almacén, Ubicación, Estado.
  - **`usuarios_almacenes`**: Relación de permisos usuario-almacén (almaceneros).
  - **`pedidos`**: Cabecera (Proveedor, Supervisor, Almacén, Estado ['Pendiente', 'Recepcionado', 'Facturado/Liquidado'], Fecha).
  - **`pedido_detalles`**: Relación con cantidades pedida, recibida y facturada, más precio unitario.
  - **`inventario_stock`**: Control de stock por almacén (Llave primaria compuesta `(producto_id, almacen_id)`).
  - **`compras_facturas`**: Registro de facturas asociadas a pedidos (Número de comprobante, tipo de pago, monto total, estado de pago).
  - **`cuentas_por_pagar`**: Cuentas de crédito pendientes de pago.

---

### Backend (PHP - CodeIgniter)

#### [NEW] [Conciliacion_model.php](file:///c:/xampp/htdocs/elofertondev/backend/application/models/Conciliacion_model.php)
- Implementará todas las consultas a la base de datos para garantizar la integridad transaccional:
  - Métodos para crear pedidos y sus detalles.
  - Verificación de permisos de usuario sobre almacenes (`usuarios_almacenes`).
  - Lógica para actualizar las cantidades recibidas e incrementar las existencias en `inventario_stock`.
  - Lógica para registrar facturas, verificar las diferencias en la conciliación y generar cuentas por pagar si el tipo de pago es 'Crédito'.

#### [NEW] [Conciliacion.php](file:///c:/xampp/htdocs/elofertondev/backend/application/controllers/Conciliacion.php)
- Controlador REST que expondrá los siguientes endpoints:
  - **`POST /conciliacion/guardar_pedido`**: Paso A. Registra un nuevo pedido.
  - **`POST /conciliacion/recibir_pedido`**: Paso B. Registra la recepción física, validando permisos del almacenero y actualizando stock.
  - **`POST /conciliacion/facturar_pedido`**: Paso C. Registra la factura, valida coincidencia (3-Way Matching), y crea cuentas por pagar de ser necesario.
  - **`GET /conciliacion/pedidos`**: Listado de pedidos filtrados por estado, proveedor o almacén.
  - **`GET /conciliacion/pedido/{id}`**: Detalles completos de un pedido incluyendo discrepancias.
  - **`POST /conciliacion/asignar_almacen`**: Asigna permisos de almacén a un usuario.

## Verification Plan

### Automated Tests
- Ejecutaremos el script de migración para validar que no haya errores de sintaxis DDL.
- Validaremos la consistencia sintáctica de PHP compilando los nuevos archivos.

### Manual Verification
- Probar el flujo de 3 pasos haciendo peticiones HTTP simuladas:
  1. Crear un pedido para un almacén específico.
  2. Intentar recibir el pedido con un usuario que no tiene permisos en ese almacén (debe retornar 403).
  3. Asignar el permiso de almacén al usuario, y volver a intentar la recepción física (debe registrar cantidad recibida e incrementar el stock en `inventario_stock`).
  4. Registrar la factura a crédito para completar la conciliación y verificar que se genere la cuenta por pagar.
