<?php

namespace App\Utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Inertia\Response;
use Illuminate\Contracts\Routing\ResponseFactory;
use App\Http\Services\DatabaseConnectionService;

class Helpers
{
    private static ?DatabaseConnectionService $dbService = null;

    private static function getDbService(): DatabaseConnectionService
    {
        if (self::$dbService === null) {
            self::$dbService = app(DatabaseConnectionService::class);
        }
        return self::$dbService;
    }

    private const DEFAULT_SCHEMA = 'dbo';

    // Data type constants
    private const TYPE_BIT = 'bit';
    private const TYPE_INT = 'int';
    private const TYPE_DECIMAL = 'decimal';
    private const TYPE_NUMERIC = 'numeric';
    private const TYPE_DATETIME = 'datetime';

    // Entity type constants
    private const ENTITY_FUNCTION = 'function';
    private const ENTITY_PROCEDURE = 'procedure';
    private const ENTITY_TABLE = 'table';

    // Special procedure names
    private const SPECIAL_PROCEDURES = [
        'p_traer_valor',
        'p_registrar_programas',
        'p_register_records'
    ];

    private const PARAMETERS_QUERY = "
        SELECT
            ordinal_position AS position,
            data_type AS type,
            REPLACE(parameter_name, '@', '') AS name,
            character_maximum_length AS max_length
        FROM
            information_schema.parameters
        WHERE
            specific_schema = ? AND
            specific_name = ? AND
            ordinal_position > 0
        ORDER BY
            ordinal_position
    ";

    private const COLUMNS_QUERY = "
            SELECT
              ordinal_position AS posicion,
              data_type AS tipo,
              column_name AS nombre,
              column_default AS valor_predeterminado,
              CONVERT(BIT, CASE is_nullable WHEN 'YES' THEN 1 ELSE 0 END) AS valor_nulo,
              character_maximum_length AS longitud_maxima_caracteres
            FROM
              information_schema.columns
            WHERE
              table_schema = ? AND
              table_name = ?
    ";

    private static function getConnection()
    {
        try {
            return self::getDbService()->getConnection();
        } catch (\Exception $e) {
            self::logError($e, ['context' => 'Getting database connection']);
            throw new \RuntimeException('No se pudo obtener la conexión a la base de datos');
        }
    }

    private static function createEntity(array $request): object
    {
        $type = self::determineEntityType($request);

        if (!$type) {
            throw new \InvalidArgumentException('No function, procedure, or table name provided');
        }

        return (object) [
            'schema' => $request['schema'] ?? self::DEFAULT_SCHEMA,
            'name' => $request[$type],
            'type' => $type,
            'query' => in_array($type, ['function', 'procedure'])
                ? self::PARAMETERS_QUERY
                : self::COLUMNS_QUERY
        ];
    }

    private static function determineEntityType(array $request): ?string
    {
        foreach (['function', 'procedure', 'table'] as $type) {
            if (isset($request[$type])) {
                return $type;
            }
        }
        return null;
    }

    public static function getSchema(Request|array|string $data): array
    {
        $request = self::sanitizeRequest($data);
        $entity = self::createEntity($request);

        return self::getConnection()->select('SET NOCOUNT ON;' . $entity->query, [
            $entity->schema,
            $entity->name
        ]);
    }

    private static function createResponse($data, int $status = 200, ?string $message = null): Response|ResponseFactory|\Illuminate\Http\Response
    {
        $response = [
            'data' => $data,
            'status_code' => $status
        ];

        if ($message) {
            $response['message'] = $message;
        }

        return response($response, $status);
    }

    private static function processInput(array|string $data): array
    {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Invalid JSON format: ' . json_last_error_msg());
            }
            $request = $decoded;
        } else {
            $request = $data;
        }

        if (isset($request['data'])) {
            $request = $request['data'];
        }

        if (!is_array($request)) {
            throw new \InvalidArgumentException('Input must be an array or valid JSON string');
        }

        return $request;
    }

    private static function normalizeRequest(array $request): array
    {
        $normalizedRequest = [];
        foreach ($request as $key => $value) {
            $key = strtolower($key);

            switch ($key) {
                case 'procedimiento':
                    $normalizedRequest['procedure'] = $value;
                    break;
                case 'esquema':
                    $normalizedRequest['schema'] = $value;
                    break;
                default:
                    $normalizedRequest[$key] = $value;
            }
        }
        return $normalizedRequest;
    }

    private static function createQueryObject(): object
    {
        return (object)[
            'parameters' => [],
            'bindings' => [],
            'query' => null,
            'data' => null
        ];
    }

    private static function processParameter(object $parameter, array &$request, string $entityName): void
    {
        if (!is_object($parameter)) {
            return;
        }

        $parameter->position = intval($parameter->position ?? 0);

        if (isset($parameter->max_length)) {
            $parameter->max_length = intval($parameter->max_length);
            $parameter->max_length === -1 && $parameter->max_length = null;
        }

        self::handleUserIdParameter($parameter, $request, $entityName);
        self::convertParameterType($parameter, $request, $entityName);
        self::validateParameterLength($parameter, $request);
    }

    private static function convertParameterType(object $parameter, array &$request, string $entityName): void
    {
        $type = $parameter->type ?? '';
        $name = $parameter->name ?? '';
        $value = $request[$name] ?? null;

        $request[$name] = match($type) {
            self::TYPE_BIT => boolval($value ?? false),
            self::TYPE_INT => intval($value ?? 0),
            self::TYPE_DECIMAL, self::TYPE_NUMERIC => floatval(str_replace(',', '', $value ?? '0')),
            self::TYPE_DATETIME => date('Y-m-d H:i:s', strtotime($value ?? 'now')),
            default => self::handleDefaultParameterType($parameter, $request, $entityName)
        };
    }

    private static function handleDefaultParameterType(object $parameter, array &$request, string $entityName): string
    {
        $name = $parameter->name ?? '';
        $value = $request[$name] ?? '';

        // Handle special cases
        if (in_array($entityName, self::SPECIAL_PROCEDURES)) {
            return $value;
        }

        // Check for special parameter names
        if (
            strpos($name, 'json') === false &&
            strpos($name, 'campo') === false &&
            strpos($name, 'sql') === false
        ) {
            $value = strtoupper($value);
        }

        // Handle date-related parameters
        if (
            strpos($name, 'desde') === 0 ||
            strpos($name, 'hasta') === 0 ||
            strpos($name, 'fecha') === 0
        ) {
            $value = str_replace('-', '', $value);
        }

        return $value;
    }

    private static function handleUserIdParameter(object $parameter, array &$request, string $entityName): void
    {
        if (
            $parameter->position === 1 &&
            strpos($parameter->name ?? '', 'id_usuario') === 0 &&
            !isset($request[$parameter->name]) &&
            !in_array($entityName, self::SPECIAL_PROCEDURES)
        ) {
            $request[$parameter->name] = session('usuario')->id_usuario ?? 1;
        }
    }

    private static function validateParameterLength(object $parameter, array $request): void
    {
        if (
            isset($request[$parameter->name]) &&
            isset($parameter->max_length) &&
            strlen($request[$parameter->name]) > $parameter->max_length
        ) {
            throw new \InvalidArgumentException(
                strtoupper("Field [{$parameter->name}] cannot exceed [{$parameter->max_length}] characters!")
            );
        }
    }

    private static function buildQuery(object $query, array $request, object $entity): void
    {
        $query->query = $request['procedure']
            ? "SET NOCOUNT ON; EXEC {$entity->schema}.{$entity->name}"
            : "SET NOCOUNT ON; SELECT {$entity->schema}.{$entity->name}";

        info('Executing query:', [
            'query' => $query->query,
            'parameters' => $query->parameters,
            'bindings' => $query->bindings
        ]);
    }

    private static function executeQuery(object $query, array $request): void
    {
        if ($request['returns'] ?? true) {
            $query->data = $request['procedure']
                ? self::getConnection()->select(
                    $query->query . ' ' . implode(', ', $query->bindings),
                    $query->parameters
                )
                : self::getConnection()->select(
                    $query->query . ' (' . implode(', ', $query->bindings) . ')',
                    $query->parameters
                );
        } else {
            $query->data = $request['procedure']
                ? self::getConnection()->statement(
                    $query->query . ' ' . implode(', ', $query->bindings),
                    $query->parameters
                )
                : self::getConnection()->statement(
                    $query->query . '(' . implode(', ', $query->bindings) . ')',
                    $query->parameters
                );
        }
    }

    private static function handleQueryResult(object $query): Response|ResponseFactory|\Illuminate\Http\Response
    {
        if (empty($query->data)) {
            return response([], 200);
        }

        return response($query->data, $query->data[0]?->codigo_estado ?? 200);
    }

    private static function logError(\Exception $e, array $context = []): void
    {
        $context = array_merge([
            'trace' => $e->getTraceAsString(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ], $context);

        info($e->getMessage(), $context);
    }

    public static function executeProcedure(array|string $data, bool $isApi = false): Response|ResponseFactory|\Illuminate\Http\Response
    {
        try {
            $request = self::processInput($data);
            $request = self::normalizeRequest($request);

            $entity = (object)[
                'schema' => $request['schema'] ?? self::DEFAULT_SCHEMA,
                'name' => $request['procedure'] ?? $request['function'] ?? null
            ];

            if (!$entity->name) {
                throw new \InvalidArgumentException('No procedure or function name provided');
            }

            $entity->parameters = self::getSchema($request);
            $query = self::createQueryObject();

            if (empty($entity->parameters) && $entity->name != 'p_register_records') {
                self::logError(new \RuntimeException("Entity has no parameters"), [
                    'entity_name' => $entity->name
                ]);
                return self::createResponse(null, 422, "Entity has no parameters");
            }

            foreach ($entity->parameters as $parameter) {
                self::processParameter($parameter, $request, $entity->name);
                $query->parameters[] = $request[$parameter->name] ?? '';
                $query->bindings[] = '?';
            }

            self::buildQuery($query, $request, $entity);
            self::executeQuery($query, $request);
            return self::handleQueryResult($query);

        } catch (\Exception $e) {
            self::logError($e, [
                'data' => $data,
                'is_api' => $isApi
            ]);

            return response([
                'message' => 'Error executing procedure: ' . $e->getMessage(),
                'status_code' => 500
            ], 500);
        }
    }

    /**
     * Sanitizes and converts a Request object or array into a clean array
     *
     * @param Request|array|string $data
     * @return array
     */
    public static function sanitizeRequest(Request|array|string $data): array
    {
        $request = match(true) {
            $data instanceof Request => $data->all(),
            is_string($data) => json_decode($data, true),
            default => $data
        };

        $request = array_filter($request, function($value) {
            return $value !== null && $value !== '';
        });

        $request = array_combine(
            array_map(function($key) {
                $key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
                return match($key) {
                    'procedimiento' => 'procedure',
                    'esquema' => 'schema',
                    default => $key
                };
            }, array_keys($request)),
            array_values($request)
        );

        $request = array_map(function($value) {
            if (is_string($value)) {
                $value = strtoupper($value);
                if ($value === 'true') return true;
                if ($value === 'false') return false;
            }
            return $value;
        }, $request);

        return $request;
    }
}



