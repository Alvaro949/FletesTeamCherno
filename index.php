<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Fletes</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/water.css@2/out/water.css">
	<link rel="stylesheet" href="estilos.css">
	
	<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
	<script src="datos.js"></script>
	<script src="app.js"></script>
</head>
<body>
	<div id="app" x-data="contexto" x-init="datos_iniciales">
		<div class="barra">
			<h1>Fletes Xpress</h1>
			<div class="navegacion">
				<button @click="vista='inicio'">Inicio</button>
				<button @click="vista='unidades'">Unidades</button>
				<button @click="vista='choferes'">Choferes</button>
				<button @click="vista='nuevo_flete'">Nuevo flete</button>
			</div>
		</div>

		<div id="principal">
				<?php include("./componentes/inicioView.html"); ?>
				<?php include("./componentes/unidadesView.html"); ?>
				<?php include("./componentes/choferesView.html"); ?>
				<?php include("./componentes/nuevoFleteView.html"); ?>
				<?php include("./componentes/nuevaunidadView.html"); ?>
				<?php include("./componentes/nuevochoferView.html"); ?>
		</div>
		<footer>
			Derechos reservados por AM 
		</footer>
	</div>
</body>
</html>