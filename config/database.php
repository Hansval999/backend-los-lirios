<?php

// ============================================================
//  Florería Los Lirios — Configuración de base de datos
//  Conexión PDO a MySQL con soporte para variables de entorno
// ============================================================

// ------------------------------------------------------------
// Configuración por entorno
// En producción (InfinityFree) define las variables de entorno
// en un archivo .env o directamente en el panel de hosting.
// En desarrollo local, los valores por defecto son suficientes.
// ------------------------------------------------------------

define('DB_HOST',    getenv('DB_HOST')    ?: 'sql105.infinityfree.com');
define('DB_PORT',    getenv('DB_PORT')    ?: '3306');
define('DB_NAME',    getenv('DB_NAME')    ?: 'if0_42742761_floreria');
define('DB_USER',    getenv('DB_USER')    ?: 'if0_42742761');
define('DB_PASS',    getenv('DB_PASS')    ?: 'homunculito1');
define('DB_CHARSET', 'utf8mb4');

// ------------------------------------------------------------
// JWT Secret — usar variable de entorno en producción
// ------------------------------------------------------------
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'floreria_lirios_jwt_secret_2026_dev');

// ------------------------------------------------------------
// Clase Database — patrón Singleton
// Garantiza una única conexión PDO por ciclo de vida del script
// ------------------------------------------------------------

class Database {

    private static ?Database $instance = null;
    private PDO $connection;

    // Constructor privado: no se puede instanciar directamente
    private function __construct() {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // Lanza PDOException en error
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // Devuelve arrays asociativos
            PDO::ATTR_EMULATE_PREPARES   => false,                    // Prepared statements reales
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // No exponer detalles del error en producción
            $this->handleConnectionError($e);
        }
    }

    // Obtener la instancia única (Singleton)
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Obtener el objeto PDO directamente
    public function getConnection(): PDO {
        return $this->connection;
    }

    // Ejecutar una query con prepared statement y devolver el PDOStatement
    public function query(string $sql, array $params = []): PDOStatement {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $this->handleQueryError($e, $sql);
        }
    }

    // Obtener todos los registros de una query
    public function fetchAll(string $sql, array $params = []): array {
        return $this->query($sql, $params)->fetchAll();
    }

    // Obtener un único registro de una query
    public function fetchOne(string $sql, array $params = []): array|false {
        return $this->query($sql, $params)->fetch();
    }

    // Obtener un valor escalar (primera columna del primer registro)
    public function fetchScalar(string $sql, array $params = []): mixed {
        return $this->query($sql, $params)->fetchColumn();
    }

    // Insertar un registro y devolver el último ID insertado
    public function insert(string $sql, array $params = []): string {
        $this->query($sql, $params);
        return $this->connection->lastInsertId();
    }

    // Ejecutar UPDATE/DELETE y devolver filas afectadas
    public function execute(string $sql, array $params = []): int {
        return $this->query($sql, $params)->rowCount();
    }

    // Iniciar una transacción
    public function beginTransaction(): void {
        $this->connection->beginTransaction();
    }

    // Confirmar una transacción
    public function commit(): void {
        $this->connection->commit();
    }

    // Revertir una transacción
    public function rollback(): void {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }

    // ------------------------------------------------------------
    // Manejo de errores interno
    // ------------------------------------------------------------

    private function handleConnectionError(PDOException $e): never {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'No se pudo conectar a la base de datos. Intente más tarde.',
            'data'    => null,
        ]);

        // Solo logear en desarrollo (no exponer en producción)
        if (getenv('APP_ENV') !== 'production') {
            error_log('[DB Connection Error] ' . $e->getMessage());
        }

        exit;
    }

    private function handleQueryError(PDOException $e, string $sql): never {
        http_response_code(500);
        header('Content-Type: application/json');

        $response = [
            'success' => false,
            'message' => 'Error interno del servidor.',
            'data'    => null,
        ];

        // En desarrollo mostrar más detalle
        if (getenv('APP_ENV') !== 'production') {
            $response['debug'] = [
                'error' => $e->getMessage(),
                'sql'   => $sql,
            ];
            error_log('[DB Query Error] ' . $e->getMessage() . ' | SQL: ' . $sql);
        }

        echo json_encode($response);
        exit;
    }

    // Evitar clonación y deserialización del Singleton
    private function __clone() {}
    public function __wakeup(): never {
        throw new \Exception('No se puede deserializar un Singleton.');
    }
}
