<?php

// ============================================================
//  Florería Los Lirios — Middleware de Autenticación JWT
//  Verifica el token JWT en el header Authorization: Bearer {token}
//  Implementación manual sin dependencias externas
// ============================================================

class AuthMiddleware {

    // ------------------------------------------------------------
    // Verificar el JWT del request actual.
    // Si es válido, devuelve el payload decodificado.
    // Si no, responde 401 y termina el script.
    // ------------------------------------------------------------

    public static function verify(): array {
        $token = self::extractToken();

        if ($token === null) {
            jsonResponse(false, null, 'Token de autenticación no proporcionado.', 401);
        }

        $payload = self::decodeJWT($token);

        if ($payload === null) {
            jsonResponse(false, null, 'Token inválido o malformado.', 401);
        }

        if (self::isExpired($payload)) {
            jsonResponse(false, null, 'La sesión ha expirado. Por favor inicia sesión nuevamente.', 401);
        }

        return $payload;
    }

    // ------------------------------------------------------------
    // Generar un JWT firmado con HMAC-SHA256
    // ------------------------------------------------------------

    public static function generateJWT(array $payload): string {
        $header = self::base64UrlEncode(json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ]));

        // Agregar tiempos estándar al payload
        $payload['iat'] = time();                    // Issued At
        $payload['exp'] = time() + (8 * 60 * 60);   // Expira en 8 horas

        $encodedPayload = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));

        $signature = self::sign($header . '.' . $encodedPayload);

        return $header . '.' . $encodedPayload . '.' . $signature;
    }

    // ------------------------------------------------------------
    // Métodos privados de soporte
    // ------------------------------------------------------------

    /**
     * Extrae el token del header Authorization: Bearer {token}
     * También acepta el token como query param ?token= (fallback)
     */
    private static function extractToken(): ?string {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (!empty($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
            return trim(substr($authHeader, 7));
        }

        // Algunos servidores Apache no pasan Authorization al PHP
        // Workaround via getallheaders()
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            if (!empty($auth) && str_starts_with($auth, 'Bearer ')) {
                return trim(substr($auth, 7));
            }
        }

        // Fallback: query string (no recomendado en producción)
        if (!empty($_GET['token'])) {
            return sanitize($_GET['token']);
        }

        return null;
    }

    /**
     * Decodifica y valida la firma de un JWT.
     * Devuelve el payload como array o null si algo falla.
     */
    private static function decodeJWT(string $token): ?array {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // Verificar la firma
        $expectedSignature = self::sign($headerB64 . '.' . $payloadB64);

        // Comparación segura contra timing attacks
        if (!hash_equals($expectedSignature, $signatureB64)) {
            return null;
        }

        // Decodificar el payload
        $payloadJson = self::base64UrlDecode($payloadB64);
        if ($payloadJson === false) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        return $payload;
    }

    /**
     * Verifica si el token ha expirado.
     */
    private static function isExpired(array $payload): bool {
        if (!isset($payload['exp'])) {
            return true; // Sin expiración → consideramos inválido
        }
        return time() > (int) $payload['exp'];
    }

    /**
     * Firma un string con HMAC-SHA256 y el JWT_SECRET.
     */
    private static function sign(string $data): string {
        return self::base64UrlEncode(
            hash_hmac('sha256', $data, JWT_SECRET, true)
        );
    }

    /**
     * Codifica en Base64 URL-safe (sin =, + → -, / → _)
     */
    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decodifica Base64 URL-safe.
     */
    private static function base64UrlDecode(string $data): string|false {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
