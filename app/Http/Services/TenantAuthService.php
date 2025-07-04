<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class TenantAuthService
{
    private DatabaseConnectionService $dbService;

    /**
     * The name of the dynamic connection
     *
     * @var string
     */
    protected $connectionName = 'tenant';

    public function __construct(DatabaseConnectionService $dbService)
    {
        $this->dbService = $dbService;
    }

    /**
     * Authenticate a user with the given credentials
     *
     * @param string $username
     * @param string $password
     * @param string|null $device
     * @param string $origin
     * @return array
     */
    public function authenticateUser($username, $password, $device = null, $origin = 'WEB')
    {
        try {
            // First get credentials using default connection
            $credentials = DB::connection('sqlsrv')->select('EXEC [dbo].[p_traer_conexion_usuario_autenticar] ?, ?, ?, ?', [
                $username,
                $password,
                $device ?? request()->header('User-Agent'),
                $origin
            ]);

            $credentials = $credentials[0] ?? null;

            if (!$credentials || property_exists($credentials, 'error')) {
                Log::error('Error getting credentials', [
                    'credentials' => $credentials,
                    'username' => $username
                ]);
                return [
                    'error' => true,
                    'data' => $credentials
                ];
            }

            // Configure new connection
            try {
                $this->dbService->setConnection($credentials);
            } catch (\Exception $e) {
                Log::error('Error configuring connection: ' . $e->getMessage(), [
                    'credentials' => $credentials
                ]);
                return [
                    'error' => true,
                    'data' => [
                        'mensaje' => 'Error al configurar la conexión a la base de datos',
                        'codigo_estado' => 500
                    ]
                ];
            }

            // Use new connection to find user
            try {
                $usuarioModel = (new User)->setConnection('tenant')
                    ->where('usuario', $username)
                    ->where('contrasena', $password)
                    ->first();

                if (!$usuarioModel) {
                    Log::warning('User not found', [
                        'username' => $username
                    ]);
                    return [
                        'error' => true,
                        'data' => [
                            'mensaje' => '¡El Usuario no existe!',
                            'campo' => 'usuario',
                            'codigo_estado' => 400
                        ]
                    ];
                }

                // Save information in session before login
                Session::put('conexion', $credentials);
                Session::put('usuario', $usuarioModel);

                // Perform login
                Auth::login($usuarioModel);

                Log::info('User authenticated successfully', [
                    'username' => $username,
                    'id_usuario' => $usuarioModel->id_usuario
                ]);

                return [
                    'error' => false,
                    'data' => $usuarioModel
                ];
            } catch (\Exception $e) {
                Log::error('Error finding user: ' . $e->getMessage(), [
                    'username' => $username,
                    'trace' => $e->getTraceAsString()
                ]);
                return [
                    'error' => true,
                    'data' => [
                        'mensaje' => 'Error al buscar usuario en la base de datos',
                        'codigo_estado' => 500
                    ]
                ];
            }

        } catch (\Exception $e) {
            Log::error('Authentication error: ' . $e->getMessage(), [
                'username' => $username,
                'origin' => $origin,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'error' => true,
                'data' => [
                    'mensaje' => 'Error al autenticar usuario: ' . $e->getMessage(),
                    'codigo_estado' => 500
                ]
            ];
        }
    }

    /**
     * Set the connection name to use
     *
     * @param string $name
     * @return $this
     */
    public function setConnectionName($name)
    {
        $this->connectionName = $name;
        return $this;
    }
}
