<?php

// ============================================================
//  Florería Los Lirios — UserModel
// ============================================================

class UserModel {

    private Database $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // Buscar usuario por email (para login)
    public function getByEmail(string $email): array|false {
        return $this->db->fetchOne(
            'SELECT id, nombre, email, password, rol
               FROM users
              WHERE email = ?
              LIMIT 1',
            [sanitize($email)]
        );
    }

    // Buscar usuario por ID
    public function getById(int $id): array|false {
        return $this->db->fetchOne(
            'SELECT id, nombre, email, rol, created_at
               FROM users
              WHERE id = ?',
            [$id]
        );
    }

    // Verificar credenciales y devolver datos del usuario (sin password)
    public function authenticate(string $email, string $password): array|false {
        $user = $this->getByEmail($email);

        if (!$user) {
            return false;
        }

        if (!password_verify($password, $user['password'])) {
            return false;
        }

        // Renovar hash si bcrypt cambió de costo
        if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            $this->db->execute(
                'UPDATE users SET password = ? WHERE id = ?',
                [$newHash, $user['id']]
            );
        }

        // Devolver datos sin el hash
        unset($user['password']);
        return $user;
    }

    // Crear usuario admin
    public function create(string $nombre, string $email, string $password): string {
        return $this->db->insert(
            'INSERT INTO users (nombre, email, password, rol) VALUES (?, ?, ?, ?)',
            [
                sanitize($nombre),
                sanitize($email),
                password_hash($password, PASSWORD_BCRYPT),
                'admin',
            ]
        );
    }

    // Cambiar contraseña
    public function updatePassword(int $id, string $newPassword): int {
        return $this->db->execute(
            'UPDATE users SET password = ? WHERE id = ?',
            [password_hash($newPassword, PASSWORD_BCRYPT), $id]
        );
    }

    // Verificar si un email ya está registrado
    public function emailExists(string $email, int $excludeId = 0): bool {
        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM users WHERE email = ? AND id != ?',
            [sanitize($email), $excludeId]
        );
        return $count > 0;
    }
}
