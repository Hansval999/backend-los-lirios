<?php

// ============================================================
//  Florería Los Lirios — ProductModel
// ============================================================

class ProductModel {

    private Database $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // ------------------------------------------------------------
    // Endpoints públicos (tienda)
    // ------------------------------------------------------------

    // Productos activos con filtros opcionales
    public function getActive(array $filters = []): array {
        $where  = ['p.activo = 1'];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where[]  = 'p.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }

        if (!empty($filters['search'])) {
            $where[]  = '(p.nombre LIKE ? OR p.descripcion LIKE ?)';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
        }

        if (!empty($filters['precio_min'])) {
            $where[]  = 'p.precio >= ?';
            $params[] = (float) $filters['precio_min'];
        }

        if (!empty($filters['precio_max'])) {
            $where[]  = 'p.precio <= ?';
            $params[] = (float) $filters['precio_max'];
        }

        $orderBy = match($filters['sort'] ?? '') {
            'precio_asc'  => 'p.precio ASC',
            'precio_desc' => 'p.precio DESC',
            'nombre_az'   => 'p.nombre ASC',
            default       => 'p.featured DESC, p.created_at DESC',
        };

        // Paginación
        $page    = max(1, (int)($filters['page'] ?? 1));
        $perPage = (int)($filters['per_page'] ?? 12);
        $offset  = ($page - 1) * $perPage;

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        // Total de registros para la paginación
        $total = (int) $this->db->fetchScalar(
            "SELECT COUNT(*) FROM products p {$whereClause}",
            $params
        );

        $params[] = $perPage;
        $params[] = $offset;

        $products = $this->db->fetchAll(
            "SELECT p.id, p.nombre, p.descripcion, p.precio, p.stock,
                    p.imagen_url, p.featured,
                    c.id AS category_id, c.nombre AS category_nombre, c.icono AS category_icono
               FROM products p
               INNER JOIN categories c ON c.id = p.category_id
             {$whereClause}
              ORDER BY {$orderBy}
              LIMIT ? OFFSET ?",
            $params
        );

        return [
            'products'    => $products,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    // Productos destacados (featured) para el home
    public function getFeatured(int $limit = 8): array {
        return $this->db->fetchAll(
            'SELECT p.id, p.nombre, p.descripcion, p.precio, p.stock,
                    p.imagen_url, p.featured,
                    c.id AS category_id, c.nombre AS category_nombre, c.icono AS category_icono
               FROM products p
               INNER JOIN categories c ON c.id = p.category_id
              WHERE p.activo = 1 AND p.featured = 1
              ORDER BY p.created_at DESC
              LIMIT ?',
            [$limit]
        );
    }

    // Detalle de un producto activo por ID
    public function getActiveById(int $id): array|false {
        return $this->db->fetchOne(
            'SELECT p.id, p.nombre, p.descripcion, p.precio, p.stock,
                    p.imagen_url, p.activo, p.featured, p.created_at,
                    c.id AS category_id, c.nombre AS category_nombre, c.icono AS category_icono
               FROM products p
               INNER JOIN categories c ON c.id = p.category_id
              WHERE p.id = ? AND p.activo = 1',
            [$id]
        );
    }

    // ------------------------------------------------------------
    // Endpoints admin
    // ------------------------------------------------------------

    // Todos los productos con paginación y búsqueda (admin)
    public function getAllAdmin(array $filters = []): array {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = '(p.nombre LIKE ? OR p.descripcion LIKE ?)';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
        }

        if (isset($filters['category_id']) && $filters['category_id'] !== '') {
            $where[]  = 'p.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }

        if (isset($filters['activo']) && $filters['activo'] !== '') {
            $where[]  = 'p.activo = ?';
            $params[] = (int) $filters['activo'];
        }

        $page    = max(1, (int)($filters['page'] ?? 1));
        $perPage = (int)($filters['per_page'] ?? 20);
        $offset  = ($page - 1) * $perPage;

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $total = (int) $this->db->fetchScalar(
            "SELECT COUNT(*) FROM products p {$whereClause}",
            $params
        );

        $params[] = $perPage;
        $params[] = $offset;

        $products = $this->db->fetchAll(
            "SELECT p.id, p.nombre, p.descripcion, p.precio, p.stock,
                    p.imagen_url, p.activo, p.featured, p.created_at,
                    c.id AS category_id, c.nombre AS category_nombre
               FROM products p
               INNER JOIN categories c ON c.id = p.category_id
             {$whereClause}
              ORDER BY p.created_at DESC
              LIMIT ? OFFSET ?",
            $params
        );

        return [
            'products'    => $products,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    // Obtener cualquier producto por ID (admin, incluso inactivos)
    public function getById(int $id): array|false {
        return $this->db->fetchOne(
            'SELECT p.*, c.nombre AS category_nombre
               FROM products p
               INNER JOIN categories c ON c.id = p.category_id
              WHERE p.id = ?',
            [$id]
        );
    }

    // Crear producto
    public function create(array $data): string {
        return $this->db->insert(
            'INSERT INTO products
               (category_id, nombre, descripcion, precio, stock, imagen_url, activo, featured)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int)   $data['category_id'],
                sanitize($data['nombre']),
                sanitize($data['descripcion'] ?? ''),
                (float) $data['precio'],
                (int)   ($data['stock'] ?? 0),
                sanitize($data['imagen_url']  ?? ''),
                isset($data['activo'])   ? (int)(bool)$data['activo']   : 1,
                isset($data['featured']) ? (int)(bool)$data['featured'] : 0,
            ]
        );
    }

    // Actualizar producto
    public function update(int $id, array $data): int {
        return $this->db->execute(
            'UPDATE products
                SET category_id = ?,
                    nombre      = ?,
                    descripcion = ?,
                    precio      = ?,
                    stock       = ?,
                    imagen_url  = ?,
                    activo      = ?,
                    featured    = ?
              WHERE id = ?',
            [
                (int)   $data['category_id'],
                sanitize($data['nombre']),
                sanitize($data['descripcion'] ?? ''),
                (float) $data['precio'],
                (int)   ($data['stock'] ?? 0),
                sanitize($data['imagen_url']  ?? ''),
                isset($data['activo'])   ? (int)(bool)$data['activo']   : 1,
                isset($data['featured']) ? (int)(bool)$data['featured'] : 0,
                $id,
            ]
        );
    }

    // Eliminar producto
    public function delete(int $id): int {
        return $this->db->execute(
            'DELETE FROM products WHERE id = ?',
            [$id]
        );
    }

    // Decrementar stock al confirmar un pedido
    public function decrementStock(int $id, int $qty): void {
        $this->db->execute(
            'UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?',
            [$qty, $id]
        );
    }

    // Cantidad de productos activos (para el dashboard)
    public function countActive(): int {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM products WHERE activo = 1'
        );
    }
}
