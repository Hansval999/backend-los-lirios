<?php

// ============================================================
//  Florería Los Lirios — AuthController
// ============================================================

class AuthController {

    private UserModel $userModel;

    public function __construct() {
        $this->userModel = new UserModel();
    }

    // POST /api/auth/login
    public function login(): never {
        $body = getRequestBody();

        $email    = trim($body['email']    ?? '');
        $password = trim($body['password'] ?? '');

        if (empty($email) || empty($password)) {
            jsonResponse(false, null, 'Email y contraseña son requeridos.', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, null, 'El email no tiene un formato válido.', 422);
        }

        $user = $this->userModel->authenticate($email, $password);

        if (!$user) {
            // Mensaje genérico para no revelar si el email existe
            jsonResponse(false, null, 'Credenciales incorrectas.', 401);
        }

        $token = AuthMiddleware::generateJWT([
            'user_id' => $user['id'],
            'email'   => $user['email'],
            'rol'     => $user['rol'],
        ]);

        jsonResponse(true, [
            'token' => $token,
            'user'  => [
                'id'     => $user['id'],
                'nombre' => $user['nombre'],
                'email'  => $user['email'],
                'rol'    => $user['rol'],
            ],
        ], 'Sesión iniciada correctamente.');
    }

    // GET /api/auth/me  (verificar token activo)
    public function me(): never {
        $payload = AuthMiddleware::verify();

        $user = $this->userModel->getById((int) $payload['user_id']);

        if (!$user) {
            jsonResponse(false, null, 'Usuario no encontrado.', 404);
        }

        jsonResponse(true, ['user' => $user], '');
    }
}
