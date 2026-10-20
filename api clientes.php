<?php

date_default_timezone_set('America/Mexico_City');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const CAMPOS = ['razon_social', 'rfc', 'email'];

function responder($status, $data) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function error($status, $mensaje, $campos = null) {
    $data = ['error' => $mensaje];
    if ($campos) $data['campos'] = $campos;
    responder($status, $data);
}

function consultar($db, $sql, $params = []) {
    $stmt = $db->prepare($sql);
    foreach ($params as $nombre => $valor) {
        $stmt->bindValue($nombre, $valor);
    }
    return $stmt->execute();
}

function todos($db, $sql, $params = []) {
    $res = consultar($db, $sql, $params);
    $filas = [];
    while ($fila = $res->fetchArray(SQLITE3_ASSOC)) {
        $filas[] = $fila;
    }
    return $filas;
}

function uno($db, $sql, $params = []) {
    $fila = consultar($db, $sql, $params)->fetchArray(SQLITE3_ASSOC);
    return $fila === false ? null : $fila;
}

function texto($valor) {
    if (is_string($valor) || is_int($valor) || is_float($valor)) {
        return trim((string) $valor);
    }
    return '';
}

function leerBody() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if ($raw !== '' && json_last_error() !== JSON_ERROR_NONE) {
        error(400, 'JSON inválido en el cuerpo de la petición: ' . json_last_error_msg());
    }
    if ($data !== null && !is_array($data)) {
        error(400, 'El cuerpo debe ser un objeto JSON.');
    }
    return $data ?? [];
}

function filtrarCampos(array $body) {
    $limpio = [];
    foreach (CAMPOS as $campo) {
        if (array_key_exists($campo, $body)) {
            $limpio[$campo] = $body[$campo];
        }
    }
    return $limpio;
}

function validarCliente(array $datos, $creando) {
    $errores = [];

    foreach (CAMPOS as $campo) {
        if (!$creando && !array_key_exists($campo, $datos)) continue;
        $datos[$campo] = texto($datos[$campo] ?? '');
        if ($datos[$campo] === '') {
            $errores[$campo] = 'Este campo es obligatorio.';
        }
    }

    if (isset($datos['rfc']) && !isset($errores['rfc'])) {
        $datos['rfc'] = mb_strtoupper($datos['rfc']);
        if (!preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', $datos['rfc'])) {
            $errores['rfc'] = 'El RFC debe tener 12 o 13 caracteres con formato válido.';
        }
    }

    if (isset($datos['email']) && !isset($errores['email'])) {
        $datos['email'] = mb_strtolower($datos['email']);
        if (!filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
            $errores['email'] = 'El correo no tiene un formato válido.';
        }
    }

    if ($errores) {
        error(422, 'Revisa los datos enviados.', $errores);
    }
    return $datos;
}

function validarRfcUnico($db, array $datos, $id = 0) {
    if (!isset($datos['rfc'])) return;
    $repetido = uno($db, 'SELECT id FROM clientes WHERE rfc = :r AND id != :id',
        [':r' => $datos['rfc'], ':id' => $id]);
    if ($repetido !== null) {
        error(409, "Ya existe un cliente con RFC {$datos['rfc']}.", ['rfc' => 'RFC ya registrado.']);
    }
}

try {
    $db = new SQLite3(__DIR__ . '/database.sqlite');
    $db->enableExceptions(true);
    $db->exec('PRAGMA foreign_keys = ON;');
} catch (Exception $e) {
    error(500, 'No se pudo conectar a la base de datos: ' . $e->getMessage());
}

$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo) {
        case 'GET':
            if ($id === null) {
                responder(200, todos($db, 'SELECT * FROM clientes ORDER BY id DESC'));
            }

            $cliente = uno($db, 'SELECT * FROM clientes WHERE id = :id', [':id' => $id]);
            if ($cliente === null) {
                error(404, "No existe cliente con id $id");
            }

            $resumen = uno($db,
                'SELECT COUNT(*) AS total_fletes,
                        COALESCE(SUM(monto), 0) AS ingresos_generados,
                        COALESCE(SUM(CASE WHEN pagado = 0 THEN monto END), 0) AS por_cobrar
                 FROM fletes WHERE cliente_id = :id',
                [':id' => $id]);
            $cliente = array_merge($cliente, $resumen);
            $cliente['ultimos_fletes'] = todos($db,
                'SELECT * FROM fletes WHERE cliente_id = :id ORDER BY fecha DESC, id DESC LIMIT 5',
                [':id' => $id]);
            responder(200, $cliente);
            break;

        case 'POST':
            $datos = validarCliente(filtrarCampos(leerBody()), true);
            validarRfcUnico($db, $datos);

            $columnas   = array_keys($datos);
            $marcadores = array_map(fn($c) => ":$c", $columnas);
            consultar($db,
                'INSERT INTO clientes (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', $marcadores) . ')',
                array_combine($marcadores, array_values($datos)));

            responder(201, uno($db, 'SELECT * FROM clientes WHERE id = :id', [':id' => $db->lastInsertRowID()]));
            break;

        case 'PUT':
            if ($id === null) {
                error(400, 'Falta el parámetro "id" para actualizar.');
            }
            if (uno($db, 'SELECT id FROM clientes WHERE id = :id', [':id' => $id]) === null) {
                error(404, "No existe cliente con id $id");
            }

            $datos = filtrarCampos(leerBody());
            if (empty($datos)) {
                error(400, 'No se enviaron campos válidos para actualizar.');
            }
            $datos = validarCliente($datos, false);
            validarRfcUnico($db, $datos, $id);

            $sets   = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($datos)));
            $params = [':id' => $id];
            foreach ($datos as $col => $val) $params[":$col"] = $val;
            consultar($db, "UPDATE clientes SET $sets WHERE id = :id", $params);

            responder(200, uno($db, 'SELECT * FROM clientes WHERE id = :id', [':id' => $id]));
            break;

        case 'DELETE':
            if ($id === null) {
                error(400, 'Falta el parámetro "id" para eliminar.');
            }
            if (uno($db, 'SELECT id FROM clientes WHERE id = :id', [':id' => $id]) === null) {
                error(404, "No existe cliente con id $id");
            }

            $total = uno($db, 'SELECT COUNT(*) AS n FROM fletes WHERE cliente_id = :id', [':id' => $id])['n'];
            if ($total > 0) {
                error(409, "No se puede eliminar: el cliente tiene $total flete(s) registrados.");
            }

            consultar($db, 'DELETE FROM clientes WHERE id = :id', [':id' => $id]);
            responder(200, ['ok' => true, 'mensaje' => "Cliente con id $id eliminado"]);
            break;

        default:
            error(405, "Método $metodo no soportado");
    }
} catch (Exception $e) {
    error(500, 'Error en la base de datos: ' . $e->getMessage());
}
