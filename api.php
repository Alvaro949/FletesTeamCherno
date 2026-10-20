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
        'tabla'    => 'clientes',
        'campos'   => ['razon_social', 'rfc', 'email'],
        'singular' => 'cliente',
        'fk'       => 'cliente_id',
    ],
    'choferes' => [
        'tabla'    => 'choferes',
        'campos'   => ['nombre', 'licencia'],
        'singular' => 'chofer',
        'fk'       => 'chofer_id',
    ],
    'unidades' => [
        'tabla'    => 'unidades',
        'campos'   => ['placa', 'marca', 'modelo', 'anio'],
        'singular' => 'unidad',
        'fk'       => 'unidad_id',
    ],
    'fletes' => [
        'tabla'    => 'fletes',
        'campos'   => ['cliente_id', 'chofer_id', 'unidad_id', 'origen', 'destino', 'monto', 'estatus', 'pagado', 'fecha'],
        'singular' => 'flete',
        'fk'       => null,
    ],
];

const ESTATUS_SIGUIENTE = [
    'pendiente'   => 'en_transito',
    'en_transito' => 'entregado',
];

const ESTATUS_ACTIVOS = "('pendiente', 'en_transito')";

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

function valor($db, $sql, $params = []) {
    $fila = uno($db, $sql, $params);
    return $fila === null ? null : array_values($fila)[0];
}

function existe($db, $tabla, $id) {
    return valor($db, "SELECT 1 FROM $tabla WHERE id = :id", [':id' => $id]) !== null;
}

function fleteActivo($db, $columna, $id, $excluirFlete = 0) {
    return valor($db,
        "SELECT id FROM fletes
         WHERE $columna = :id AND estatus IN " . ESTATUS_ACTIVOS . " AND id != :excluir
         LIMIT 1",
        [':id' => $id, ':excluir' => $excluirFlete]
    );
}

function texto($valor) {
    if (is_string($valor) || is_int($valor) || is_float($valor)) {
        return trim((string) $valor);
    }
    return '';
}

function entero($valor) {
    if (is_int($valor)) return $valor;
    if (is_string($valor) && ctype_digit(trim($valor))) return (int) $valor;
    return null;
}

function validar($db, $recurso, array $datos, $actual) {
    $errores = [];
    $creando = $actual === null;
    $enviado = fn($campo) => array_key_exists($campo, $datos);
    $revisar = fn($campo) => $creando || $enviado($campo);

    switch ($recurso) {
        case 'clientes':
        case 'choferes':
        case 'unidades':
            // Catálogos simples: todos los campos son obligatorios.
            foreach (RECURSOS[$recurso]['campos'] as $campo) {
                if (!$revisar($campo)) continue;
                $datos[$campo] = texto($datos[$campo] ?? '');
                if ($datos[$campo] === '') {
                    $errores[$campo] = 'Este campo es obligatorio.';
                }
            }
            if (isset($datos['placa'])) $datos['placa'] = strtoupper($datos['placa']);
            if (isset($datos['anio']) && entero($datos['anio']) === null) {
                $errores['anio'] = 'El año debe ser un número.';
            }
            break;

        case 'fletes':
            $relaciones = ['cliente_id' => 'clientes', 'chofer_id' => 'choferes', 'unidad_id' => 'unidades'];
            foreach ($relaciones as $campo => $tabla) {
                if (!$revisar($campo)) continue;
                $id = entero($datos[$campo] ?? null);
                if ($id === null || !existe($db, $tabla, $id)) {
                    $errores[$campo] = 'Selecciona un ' . RECURSOS[$tabla]['singular'] . ' válido.';
                } else {
                    $datos[$campo] = $id;
                }
            }

            foreach (['origen', 'destino'] as $campo) {
                if (!$revisar($campo)) continue;
                $datos[$campo] = texto($datos[$campo] ?? '');
                if (mb_strlen($datos[$campo]) < 2) {
                    $errores[$campo] = "El $campo es obligatorio.";
                }
            }
            $origen  = $datos['origen']  ?? $actual['origen']  ?? '';
            $destino = $datos['destino'] ?? $actual['destino'] ?? '';
            if (!isset($errores['destino']) && $origen !== '' && mb_strtolower($origen) === mb_strtolower($destino)) {
                $errores['destino'] = 'El destino debe ser distinto al origen.';
            }

            if ($revisar('monto')) {
                $monto = $datos['monto'] ?? null;
                if (!is_numeric($monto) || (float) $monto <= 0) {
                    $errores['monto'] = 'El monto debe ser un número mayor a 0.';
                } else {
                    $datos['monto'] = round((float) $monto, 2);
                }
            }

            if ($enviado('pagado')) {
                $pagado = $datos['pagado'];
                if (!in_array($pagado, [0, 1, '0', '1', true, false], true)) {
                    $errores['pagado'] = 'Pagado debe ser 0 o 1.';
                } else {
                    $datos['pagado'] = (int) $pagado;
                }
            }

            if ($enviado('fecha')) {
                $fecha = DateTime::createFromFormat('Y-m-d', texto($datos['fecha']));
                if (!$fecha || $fecha->format('Y-m-d') !== texto($datos['fecha'])) {
                    $errores['fecha'] = 'La fecha debe tener formato AAAA-MM-DD.';
                }
            }

            if ($enviado('estatus')) {
                $nuevo = $datos['estatus'];
                if ($creando && $nuevo !== 'pendiente') {
                    $errores['estatus'] = 'Un flete nuevo siempre inicia como pendiente.';
                } elseif (!$creando && $nuevo !== $actual['estatus']) {
                    $esperado = ESTATUS_SIGUIENTE[$actual['estatus']] ?? null;
                    if ($nuevo !== $esperado) {
                        $errores['estatus'] = $esperado
                            ? "Un flete {$actual['estatus']} solo puede pasar a $esperado."
                            : 'El flete ya fue entregado; su estatus no puede cambiar.';
                    }
                }
            }

            foreach (['chofer_id', 'unidad_id'] as $campo) {
                if (!$enviado($campo) || isset($errores[$campo])) continue;
                if (!$creando && $datos[$campo] === $actual[$campo]) continue;
                if (!$creando && $actual['estatus'] !== 'pendiente') {
                    $errores[$campo] = 'Solo se puede cambiar ' . ($campo === 'chofer_id' ? 'el chofer' : 'la unidad') . ' de un flete pendiente.';
                    continue;
                }
                $ocupadoEn = fleteActivo($db, $campo, $datos[$campo], $creando ? 0 : $actual['id']);
                if ($ocupadoEn !== null) {
                    $quien = $campo === 'chofer_id' ? 'El chofer ya está asignado' : 'La unidad ya está asignada';
                    $errores[$campo] = "$quien al flete #$ocupadoEn.";
                }
            }
            break;
    }

    if ($errores) {
        error(422, 'Revisa los datos enviados.', $errores);
    }
    return $datos;
}

function validarUnicos($db, $recurso, array $datos, $id = 0) {
    if ($recurso === 'unidades' && isset($datos['placa'])) {
        $repetida = valor($db, 'SELECT id FROM unidades WHERE placa = :p AND id != :id',
            [':p' => $datos['placa'], ':id' => $id]);
        if ($repetida !== null) {
            error(409, "Ya existe una unidad con placa {$datos['placa']}.", ['placa' => 'Placa ya registrada.']);
        }
    }
}

function listarConEstado($db, $tabla, $fk, $soloDisponibles) {
    $sql = "SELECT t.*,
                   f.id AS flete_actual,
                   CASE f.estatus
                       WHEN 'en_transito' THEN 'en_ruta'
                       WHEN 'pendiente'   THEN 'asignado'
                       ELSE 'disponible'
                   END AS estado
            FROM $tabla t
            LEFT JOIN fletes f ON f.id = (
                SELECT id FROM fletes
                WHERE $fk = t.id AND estatus IN " . ESTATUS_ACTIVOS . "
                ORDER BY id DESC LIMIT 1
            )";
    if ($soloDisponibles) $sql .= ' WHERE f.id IS NULL';
    $sql .= ' ORDER BY t.id DESC';
    return todos($db, $sql);
}

const SELECT_FLETES = "SELECT f.*,
                              cl.razon_social AS cliente,
                              ch.nombre AS chofer,
                              u.placa   AS placa,
                              u.marca   AS marca,
                              u.modelo  AS modelo
                       FROM fletes f
                       JOIN clientes cl ON cl.id = f.cliente_id
                       JOIN choferes ch ON ch.id = f.chofer_id
                       JOIN unidades u  ON u.id  = f.unidad_id";

function obtener($db, $recurso, $id) {
    if ($recurso === 'fletes') {
        return uno($db, SELECT_FLETES . ' WHERE f.id = :id', [':id' => $id]);
    }
    $tabla = RECURSOS[$recurso]['tabla'];
    return uno($db, "SELECT * FROM $tabla WHERE id = :id", [':id' => $id]);
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

$config   = RECURSOS[$recurso];
$tabla    = $config['tabla'];
$campos   = $config['campos'];
$singular = $config['singular'];
$fk       = $config['fk'];
$id       = isset($_GET['id']) ? (int) $_GET['id'] : null;
$metodo   = $_SERVER['REQUEST_METHOD'];

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

function filtrarCampos(array $body, array $campos) {
    $limpio = [];
    foreach ($campos as $campo) {
        if (array_key_exists($campo, $body)) {
            $limpio[$campo] = $body[$campo];
        }
    }
    return $limpio;
}

try {
    switch ($metodo) {
        case 'GET':
            if ($id !== null) {
                $fila = obtener($db, $recurso, $id);
                if ($fila === null) {
                    error(404, "No existe $singular con id $id");
                }

                if ($fk !== null) {
                    $resumen = uno($db,
                        "SELECT COUNT(*) AS total_fletes,
                                COALESCE(SUM(monto), 0) AS ingresos_generados,
                                COALESCE(SUM(CASE WHEN pagado = 0 THEN monto END), 0) AS por_cobrar
                         FROM fletes WHERE $fk = :id",
                        [':id' => $id]);
                    $fila = array_merge($fila, $resumen);
                    $fila['ultimos_fletes'] = todos($db,
                        SELECT_FLETES . " WHERE f.$fk = :id ORDER BY f.fecha DESC, f.id DESC LIMIT 5",
                        [':id' => $id]);
                }
                responder(200, $fila);
            }

            if ($recurso === 'choferes' || $recurso === 'unidades') {
                responder(200, listarConEstado($db, $tabla, $fk, !empty($_GET['disponibles'])));
            }

            if ($recurso === 'fletes') {
                $where = [];
                $params = [];
                if (isset($_GET['estatus']) && $_GET['estatus'] !== '') {
                    $where[] = 'f.estatus = :estatus';
                    $params[':estatus'] = $_GET['estatus'];
                }
                if (isset($_GET['pagado']) && $_GET['pagado'] !== '') {
                    $where[] = 'f.pagado = :pagado';
                    $params[':pagado'] = (int) $_GET['pagado'];
                }
                $sql = SELECT_FLETES
                     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                     . ' ORDER BY f.fecha DESC, f.id DESC';
                responder(200, todos($db, $sql, $params));
            }

            responder(200, todos($db, "SELECT * FROM $tabla ORDER BY id DESC"));
            break;

        case 'POST':
            $datos = filtrarCampos(leerBody(), $campos);
            $datos = validar($db, $recurso, $datos, null);
            validarUnicos($db, $recurso, $datos);

            $columnas   = array_keys($datos);
            $marcadores = array_map(fn($c) => ":$c", $columnas);
            $sql = "INSERT INTO $tabla (" . implode(', ', $columnas) . ") VALUES (" . implode(', ', $marcadores) . ")";
            consultar($db, $sql, array_combine($marcadores, array_values($datos)));

            responder(201, obtener($db, $recurso, $db->lastInsertRowID()));
            break;

        case 'PUT':
            if ($id === null) {
                error(400, 'Falta el parámetro "id" para actualizar.');
            }

            $actual = uno($db, "SELECT * FROM $tabla WHERE id = :id", [':id' => $id]);
            if ($actual === null) {
                error(404, "No existe $singular con id $id");
            }

            $datos = filtrarCampos(leerBody(), $campos);
            if (empty($datos)) {
                error(400, 'No se enviaron campos válidos para actualizar.');
            }
            $datos = validar($db, $recurso, $datos, $actual);
            validarUnicos($db, $recurso, $datos, $id);

            $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($datos)));
            $params = [':id' => $id];
            foreach ($datos as $col => $val) $params[":$col"] = $val;
            consultar($db, "UPDATE $tabla SET $sets WHERE id = :id", $params);

            responder(200, obtener($db, $recurso, $id));
            break;

        case 'DELETE':
            if ($id === null) {
                error(400, 'Falta el parámetro "id" para eliminar.');
            }

            $actual = uno($db, "SELECT * FROM $tabla WHERE id = :id", [':id' => $id]);
            if ($actual === null) {
                error(404, "No existe $singular con id $id");
            }

            if ($fk !== null) {
                $total = valor($db, "SELECT COUNT(*) FROM fletes WHERE $fk = :id", [':id' => $id]);
                if ($total > 0) {
                    $articulo = $recurso === 'unidades' ? 'la' : 'el';
                    error(409, "No se puede eliminar: $articulo $singular tiene $total flete(s) registrados.");
                }
            } elseif ($actual['estatus'] !== 'pendiente') {
                error(409, 'Solo se pueden eliminar fletes pendientes.');
            }

            consultar($db, "DELETE FROM $tabla WHERE id = :id", [':id' => $id]);
            responder(200, ['ok' => true, 'mensaje' => ucfirst($singular) . " con id $id eliminado"]);
            break;

        default:
            error(405, "Método $metodo no soportado");
    }
} catch (Exception $e) {
    error(500, 'Error en la base de datos: ' . $e->getMessage());
}
