<?php

// ============================================================
//  Florería Los Lirios — CategoryModel
// ============================================================

class CategoryModel {

    private Database $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // Todas las categorías activas (para la tienda pública)
    public function getAllActive(): array {
        return $this->db->fetchAll(
            'SELECT id, nombre, descripcion, icono
               FROM categories
              WHERE activo = 1
              ORDER BY nombre ASC'
        );
    }

    // Todas las categorías (para el panel admin)
    public function getAll(): array {
        return $this->db->fetchAll(
            'SELECT c.id, c.nombre, c.descripcion, c.icono, c.activo, c.created_at,
                    COUNT(p.id) AS total_productos
               FROM categories c
               LEFT JOIN products p ON p.category_id = c.id
              GROUP BY c.id
              ORDER BY c.nombre ASC'
        );
    }

    // Una categoría por ID
    public function getById(int $id): array|false {
        return $this->db->fetchOne(
            'SELECT * FROM categories WHERE id = ?',
            [$id]
        );
    }

    // Crear categoría
    public function create(array $data): string {
        return $this->db->insert(
            'INSERT INTO categories (nombre, descripcion, icono, activo)
             VALUES (?, ?, ?, ?)',
            [
                sanitize($data['nombre']),
                sanitize($data['descripcion'] ?? ''),
                sanitize($data['icono']       ?? ''),
                isset($data['activo']) ? (int)(bool)$data['activo'] : 1,
            ]
        );
    }

    // Actualizar categoría
    public function update(int $id, array $data): int {
        return $this->db->execute(
            'UPDATE categories
                SET nombre      = ?,
                    descripcion = ?,
                    icono       = ?,
                    activo      = ?
              WHERE id = ?',
            [
                sanitize($data['nombre']),
                sanitize($data['descripcion'] ?? ''),
                sanitize($data['icono']       ?? ''),
                isset($data['activo']) ? (int)(bool)$data['activo'] : 1,
                $id,
            ]
        );
    }

    // Eliminar categoría (solo si no tiene productos asociados)
    public function delete(int $id): array {
        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM products WHERE category_id = ?',
            [$id]
        );

        if ($count > 0) {
            return [
                'deleted' => false,
                'reason'  => "No se puede eliminar: tiene {$count} producto(s) asociado(s).",
            ];
        }

        $rows = $this->db->execute('DELETE FROM categories WHERE id = ?', [$id]);
        return ['deleted' => $rows > 0, 'reason' => ''];
    }

    // Verificar si el nombre ya existe (para evitar duplicados)
    public function nameExists(string $nombre, int $excludeId = 0): bool {
        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM categories WHERE nombre = ? AND id != ?',
            [sanitize($nombre), $excludeId]
        );
        return $count > 0;
    }
}
