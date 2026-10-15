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

const RECURSOS = [
    'clientes' => [
        'tabla'   => 'clientes',
        'campos'  => ['nombre'],
    ],
    'choferes' => [
        'tabla'   => 'choferes',
        'campos'  => ['nombre'],
    ],
    'unidades' => [
        'tabla'   => 'unidades',
        'campos'  => ['placas', 'modelo'],
    ],
    'fletes' => [
        'tabla'   => 'fletes',
        'campos'  => ['cliente_id', 'chofer_id', 'unidad_id', 'origen', 'destino', 'monto', 'estatus', 'pagado', 'fecha'],
    ],
];

function responder($status, $data) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function error($status, $mensaje) {
    responder($status, ['error' => $mensaje]);
}

try {
    $db = new SQLite3(__DIR__ . '/database.sqlite');
    $db->enableExceptions(true);
    $db->exec('PRAGMA foreign_keys = ON;');
} catch (Exception $e) {
    error(500, 'No se pudo conectar a la base de datos: ' . $e->getMessage());
}

$recurso = $_GET['recurso'] ?? null;

if ($recurso === null) {
    error(400, 'Falta el parámetro "recurso". Disponibles: ' . implode(', ', array_keys(RECURSOS)));
}

if (!array_key_exists($recurso, RECURSOS)) {
    error(404, "Recurso '$recurso' no existe. Disponibles: " . implode(', ', array_keys(RECURSOS)));
}

$config = RECURSOS[$recurso];
$tabla  = $config['tabla'];
$campos = $config['campos'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;
$metodo = $_SERVER['REQUEST_METHOD'];

function leerBody() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if ($raw !== '' && json_last_error() !== JSON_ERROR_NONE) {
        error(400, 'JSON inválido en el cuerpo de la petición: ' . json_last_error_msg());
    }
    return $data ?? [];
}

function filtrarCampos(array $body, array $campos) {
    $limpio = [];
    foreach ($campos as $campo) {
        if (array_key_exists($campo, $body)) {
            $limpio[$campo] = $body[$campo];
        }
    }
    return $limpio;
}

switch ($metodo) {

    case 'GET':
        if ($id !== null) {
            $stmt = $db->prepare("SELECT * FROM $tabla WHERE id = :id");
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $res = $stmt->execute();
            $fila = $res->fetchArray(SQLITE3_ASSOC);

            if ($fila === false) {
                error(404, "No existe $recurso con id $id");
            }
            responder(200, $fila);
        } else {
            $res = $db->query("SELECT * FROM $tabla ORDER BY id DESC");
            $filas = [];
            while ($fila = $res->fetchArray(SQLITE3_ASSOC)) {
                $filas[] = $fila;
            }
            responder(200, $filas);
        }
        break;

    case 'POST':
        $body = leerBody();
        $datos = filtrarCampos($body, $campos);

        if (empty($datos)) {
            error(400, 'No se enviaron campos válidos para crear el registro.');
        }

        $columnas = array_keys($datos);
        $marcadores = array_map(fn($c) => ":$c", $columnas);

        $sql = "INSERT INTO $tabla (" . implode(', ', $columnas) . ") VALUES (" . implode(', ', $marcadores) . ")";
        $stmt = $db->prepare($sql);
        foreach ($datos as $col => $val) {
            $stmt->bindValue(":$col", $val);
        }

        try {
            $stmt->execute();
        } catch (Exception $e) {
            error(400, 'No se pudo crear el registro: ' . $e->getMessage());
        }

        $nuevoId = $db->lastInsertRowID();
        $res = $db->query("SELECT * FROM $tabla WHERE id = $nuevoId");
        responder(201, $res->fetchArray(SQLITE3_ASSOC));
        break;

    case 'PUT':
        if ($id === null) {
            error(400, 'Falta el parámetro "id" para actualizar.');
        }

        $body = leerBody();
        $datos = filtrarCampos($body, $campos);

        if (empty($datos)) {
            error(400, 'No se enviaron campos válidos para actualizar.');
        }

        $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($datos)));
        $sql = "UPDATE $tabla SET $sets WHERE id = :id";
        $stmt = $db->prepare($sql);
        foreach ($datos as $col => $val) {
            $stmt->bindValue(":$col", $val);
        }
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);

        try {
            $stmt->execute();
        } catch (Exception $e) {
            error(400, 'No se pudo actualizar el registro: ' . $e->getMessage());
        }

        if ($db->changes() === 0) {
            error(404, "No existe $recurso con id $id");
        }

        $res = $db->query("SELECT * FROM $tabla WHERE id = $id");
        responder(200, $res->fetchArray(SQLITE3_ASSOC));
        break;

    case 'DELETE':
        if ($id === null) {
            error(400, 'Falta el parámetro "id" para eliminar.');
        }

        $stmt = $db->prepare("DELETE FROM $tabla WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);

        try {
            $stmt->execute();
        } catch (Exception $e) {
            error(409, 'No se pudo eliminar (probablemente tiene registros relacionados): ' . $e->getMessage());
        }

        if ($db->changes() === 0) {
            error(404, "No existe $recurso con id $id");
        }

        responder(200, ['ok' => true, 'mensaje' => "$recurso con id $id eliminado"]);
        break;

    default:
        error(405, "Método $metodo no soportado");
}
