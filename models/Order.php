<?php

// ============================================================
//  Florería Los Lirios — OrderModel
// ============================================================

class OrderModel {

    private Database $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // ------------------------------------------------------------
    // Crear pedido completo (orden + ítems en una transacción)
    // ------------------------------------------------------------

    public function create(array $orderData, array $items): array {
        $this->db->beginTransaction();

        try {
            $codigo = $this->generateCode();

            $orderId = $this->db->insert(
                'INSERT INTO orders (
                    codigo, cliente_nombre, cliente_apellido, cliente_telefono,
                    cliente_email, direccion, distrito, fecha_entrega, hora_entrega,
                    tipo_entrega, nota_dedicatoria, metodo_pago, estado,
                    subtotal, delivery, total, culqi_token
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $codigo,
                    sanitize($orderData['cliente_nombre']),
                    sanitize($orderData['cliente_apellido']),
                    sanitize($orderData['cliente_telefono']),
                    sanitize($orderData['cliente_email']     ?? ''),
                    sanitize($orderData['direccion']         ?? ''),
                    sanitize($orderData['distrito']          ?? ''),
                    $orderData['fecha_entrega'],
                    $orderData['hora_entrega'],
                    $orderData['tipo_entrega']   ?? 'delivery',
                    sanitize($orderData['nota_dedicatoria']  ?? ''),
                    $orderData['metodo_pago']    ?? 'efectivo',
                    $orderData['estado']         ?? 'pendiente',
                    (float) $orderData['subtotal'],
                    (float) $orderData['delivery'],
                    (float) $orderData['total'],
                    $orderData['culqi_token']    ?? null,
                ]
            );

            // Insertar ítems
            foreach ($items as $item) {
                $this->db->insert(
                    'INSERT INTO order_items
                       (order_id, product_id, nombre_producto, precio_unitario, cantidad, subtotal)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        (int)   $orderId,
                        (int)   $item['product_id'],
                        sanitize($item['nombre']),
                        (float) $item['precio'],
                        (int)   $item['cantidad'],
                        (float) ($item['precio'] * $item['cantidad']),
                    ]
                );
            }

            $this->db->commit();

            return [
                'id'     => (int) $orderId,
                'codigo' => $codigo,
            ];

        } catch (\Throwable $e) {
            $this->db->rollback();
            error_log('[OrderModel::create] ' . $e->getMessage());
            throw $e;
        }
    }

    // ------------------------------------------------------------
    // Consultas públicas
    // ------------------------------------------------------------

    // Obtener pedido por código (para la página de confirmación)
    public function getByCode(string $codigo): array|false {
        $order = $this->db->fetchOne(
            'SELECT * FROM orders WHERE codigo = ?',
            [sanitize($codigo)]
        );

        if (!$order) return false;

        $order['items'] = $this->getItems((int) $order['id']);
        return $order;
    }

    // ------------------------------------------------------------
    // Consultas admin
    // ------------------------------------------------------------

    // Lista de pedidos con filtros opcionales
    public function getAll(array $filters = []): array {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['estado'])) {
            $where[]  = 'o.estado = ?';
            $params[] = $filters['estado'];
        }

        if (!empty($filters['fecha'])) {
            $where[]  = 'DATE(o.created_at) = ?';
            $params[] = $filters['fecha'];
        }

        if (!empty($filters['metodo_pago'])) {
            $where[]  = 'o.metodo_pago = ?';
            $params[] = $filters['metodo_pago'];
        }

        if (!empty($filters['search'])) {
            $where[]  = '(o.codigo LIKE ? OR o.cliente_nombre LIKE ? OR o.cliente_apellido LIKE ? OR o.cliente_telefono LIKE ?)';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $page    = max(1, (int)($filters['page'] ?? 1));
        $perPage = (int)($filters['per_page'] ?? 20);
        $offset  = ($page - 1) * $perPage;

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $total = (int) $this->db->fetchScalar(
            "SELECT COUNT(*) FROM orders o {$whereClause}",
            $params
        );

        $params[] = $perPage;
        $params[] = $offset;

        $orders = $this->db->fetchAll(
            "SELECT o.id, o.codigo,
                    o.cliente_nombre, o.cliente_apellido, o.cliente_telefono, o.cliente_email,
                    o.tipo_entrega, o.metodo_pago, o.estado,
                    o.subtotal, o.delivery, o.total,
                    o.fecha_entrega, o.hora_entrega,
                    o.created_at,
                    COUNT(oi.id) AS total_items
               FROM orders o
               LEFT JOIN order_items oi ON oi.order_id = o.id
             {$whereClause}
              GROUP BY o.id
              ORDER BY o.created_at DESC
              LIMIT ? OFFSET ?",
            $params
        );

        return [
            'orders'      => $orders,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    // Detalle completo de un pedido (admin)
    public function getById(int $id): array|false {
        $order = $this->db->fetchOne(
            'SELECT * FROM orders WHERE id = ?',
            [$id]
        );

        if (!$order) return false;

        $order['items'] = $this->getItems($id);
        return $order;
    }

    // Actualizar estado de un pedido
    public function updateStatus(int $id, string $estado): int {
        $validStates = ['pendiente', 'confirmado', 'preparando', 'enviado', 'entregado', 'cancelado'];
        if (!in_array($estado, $validStates, true)) {
            return 0;
        }

        return $this->db->execute(
            'UPDATE orders SET estado = ? WHERE id = ?',
            [$estado, $id]
        );
    }

    // Actualizar campos de un pedido (admin puede editar más cosas)
    public function update(int $id, array $data): int {
        $fields = [];
        $params = [];

        $allowed = ['estado', 'nota_dedicatoria', 'fecha_entrega', 'hora_entrega', 'direccion', 'distrito'];

        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = ?";
                $params[] = in_array($field, ['nota_dedicatoria', 'direccion', 'distrito'])
                    ? sanitize($data[$field])
                    : $data[$field];
            }
        }

        if (empty($fields)) return 0;

        $params[] = $id;

        return $this->db->execute(
            'UPDATE orders SET ' . implode(', ', $fields) . ' WHERE id = ?',
            $params
        );
    }

    // ------------------------------------------------------------
    // Métricas para el Dashboard
    // ------------------------------------------------------------

    public function getDashboardStats(): array {
        $today = date('Y-m-d');
        $month = date('Y-m');

        $pedidosHoy = (int) $this->db->fetchScalar(
            "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = ?",
            [$today]
        );

        $pedidosPendientes = (int) $this->db->fetchScalar(
            "SELECT COUNT(*) FROM orders WHERE estado = 'pendiente'"
        );

        $ingresosMes = (float) $this->db->fetchScalar(
            "SELECT COALESCE(SUM(total), 0)
               FROM orders
              WHERE DATE_FORMAT(created_at, '%Y-%m') = ?
                AND estado NOT IN ('cancelado')",
            [$month]
        );

        return [
            'pedidos_hoy'        => $pedidosHoy,
            'pedidos_pendientes' => $pedidosPendientes,
            'ingresos_mes'       => $ingresosMes,
        ];
    }

    // Últimos N pedidos para la tabla del dashboard
    public function getLatest(int $limit = 10): array {
        return $this->db->fetchAll(
            'SELECT o.id, o.codigo,
                    CONCAT(o.cliente_nombre, " ", o.cliente_apellido) AS cliente,
                    o.total, o.metodo_pago, o.estado, o.created_at
               FROM orders o
              ORDER BY o.created_at DESC
              LIMIT ?',
            [$limit]
        );
    }

    // ------------------------------------------------------------
    // Helpers privados
    // ------------------------------------------------------------

    // Obtener ítems de un pedido
    private function getItems(int $orderId): array {
        return $this->db->fetchAll(
            'SELECT oi.id, oi.product_id, oi.nombre_producto,
                    oi.precio_unitario, oi.cantidad, oi.subtotal,
                    p.imagen_url
               FROM order_items oi
               LEFT JOIN products p ON p.id = oi.product_id
              WHERE oi.order_id = ?
              ORDER BY oi.id ASC',
            [$orderId]
        );
    }

    // Generar código único de pedido: LIR-YYYY-XXXX
    private function generateCode(): string {
        $year = date('Y');

        // Contar pedidos del año actual para el correlativo
        $count = (int) $this->db->fetchScalar(
            "SELECT COUNT(*) FROM orders WHERE YEAR(created_at) = ?",
            [$year]
        );

        $correlativo = str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
        $codigo      = "LIR-{$year}-{$correlativo}";

        // Verificar unicidad (en caso de concurrencia)
        $exists = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM orders WHERE codigo = ?',
            [$codigo]
        );

        if ($exists > 0) {
            // Fallback: usar timestamp
            $codigo = "LIR-{$year}-" . str_pad((string)($count + 2), 4, '0', STR_PAD_LEFT);
        }

        return $codigo;
    }
}
