<?php
date_default_timezone_set('America/Mexico_City');
if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');

try {
	$db = new SQLite3(__DIR__ . '/database.sqlite');
	$db->enableExceptions(true);
	$db->exec('PRAGMA foreign_keys = ON;');
	$db->exec('BEGIN');

	$db->exec('DROP TABLE IF EXISTS fletes');
	$db->exec('DROP TABLE IF EXISTS clientes');
	$db->exec('DROP TABLE IF EXISTS choferes');
	$db->exec('DROP TABLE IF EXISTS unidades');

	$db->exec('CREATE TABLE clientes (
		id           INTEGER PRIMARY KEY AUTOINCREMENT,
		razon_social TEXT NOT NULL,
		rfc          TEXT NOT NULL,
		email        TEXT NOT NULL
	)');

	$db->exec('CREATE TABLE choferes (
		id       INTEGER PRIMARY KEY AUTOINCREMENT,
		nombre   TEXT NOT NULL,
		licencia TEXT NOT NULL
	)');

	$db->exec('CREATE TABLE unidades (
		id     INTEGER PRIMARY KEY AUTOINCREMENT,
		placa  TEXT NOT NULL UNIQUE,
		marca  TEXT NOT NULL,
		modelo TEXT NOT NULL,
		anio   INTEGER NOT NULL
	)');

	$db->exec("CREATE TABLE fletes (
		id         INTEGER PRIMARY KEY AUTOINCREMENT,
		cliente_id INTEGER NOT NULL REFERENCES clientes(id),
		chofer_id  INTEGER NOT NULL REFERENCES choferes(id),
		unidad_id  INTEGER NOT NULL REFERENCES unidades(id),
		origen     TEXT NOT NULL,
		destino    TEXT NOT NULL,
		monto      REAL NOT NULL,
		estatus    TEXT NOT NULL DEFAULT 'pendiente'
		           CHECK (estatus IN ('pendiente', 'en_transito', 'entregado')),
		pagado     INTEGER NOT NULL DEFAULT 0 CHECK (pagado IN (0, 1)),
		fecha      TEXT NOT NULL DEFAULT (date('now', 'localtime'))
	)");

	function insertar($db, $sql, $filas) {
		$st = $db->prepare($sql);
		foreach ($filas as $fila) {
			foreach (array_values($fila) as $i => $valor) $st->bindValue($i + 1, $valor);
			$st->execute();
			$st->reset();
		}
	}

	function hace($dias) {
		return date('Y-m-d', strtotime("-$dias days"));
	}

	insertar($db, 'INSERT INTO clientes (razon_social, rfc, email) VALUES (?, ?, ?)', [
		['Agroexportadora del Bajío SA de CV',    'ABA150310KJ2', 'contacto@agrobajio.mx'],
		['Distribuidora Celaya SA de CV',         'DCE980722MN4', 'compras@discelaya.mx'],
		['Calzado Fino de León SA de CV',         'CFL050115PQ7', 'logistica@calzadofino.mx'],
		['Granos y Semillas del Centro SC',       'GSC120901RT1', 'ventas@granoscentro.mx'],
		['Autopartes Puerto Interior SA de CV',   'API170404XY9', 'embarques@autopartespi.mx'],
	]);

	insertar($db, 'INSERT INTO choferes (nombre, licencia) VALUES (?, ?)', [
		['Juan Carlos Ramírez',    'GTO-E-104521'],
		['Miguel Ángel Torres',    'GTO-E-208734'],
		['Roberto Hernández',      'GTO-E-311290'],
		['José Luis Martínez',     'QRO-E-405567'],
		['Francisco Javier López', 'GTO-E-503345'],
		['Pedro Sánchez Ruiz',     'GTO-E-608812'],
	]);

	insertar($db, 'INSERT INTO unidades (placa, marca, modelo, anio) VALUES (?, ?, ?, ?)', [
		['GT-4521-A', 'Kenworth',      'T680',     2021],
		['GT-7834-B', 'Freightliner',  'Cascadia', 2020],
		['GT-1290-C', 'International', 'LT625',    2019],
		['QR-5567-D', 'Volvo',         'VNL 760',  2022],
		['GT-3345-E', 'Kenworth',      'T880',     2018],
		['GT-8812-F', 'Freightliner',  'M2 106',   2023],
	]);

	insertar($db, 'INSERT INTO fletes (cliente_id, chofer_id, unidad_id, origen, destino, monto, estatus, pagado, fecha)
	               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
		[1, 1, 1, 'Irapuato', 'Monterrey',      38500, 'en_transito', 0, hace(1)],
		[3, 2, 2, 'León',     'Guadalajara',    22000, 'en_transito', 0, hace(2)],
		[5, 3, 3, 'Silao',    'CDMX',           31000, 'en_transito', 1, hace(1)],
		[2, 4, 4, 'Celaya',   'Querétaro',       9500, 'pendiente',   0, hace(0)],
		[1, 1, 1, 'Irapuato', 'CDMX',           29000, 'entregado',   1, hace(20)],
		[1, 2, 2, 'Irapuato', 'Nuevo Laredo',   52000, 'entregado',   0, hace(15)],
		[3, 3, 3, 'León',     'Monterrey',      41000, 'entregado',   1, hace(14)],
		[4, 4, 4, 'Celaya',   'Aguascalientes', 18500, 'entregado',   0, hace(10)],
		[5, 1, 2, 'Silao',    'Guadalajara',    24000, 'entregado',   1, hace(9)],
		[2, 2, 1, 'Irapuato', 'León',            7800, 'entregado',   0, hace(7)],
	]);

	$db->exec('COMMIT');
	$db->close();
	echo "Base de datos creada\n";

} catch (Exception $e) {
	if (isset($db)) @$db->exec('ROLLBACK');
	http_response_code(500);
	echo "Error: " . $e->getMessage() . "\n";
	exit(1);
}
