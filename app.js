
const API_URL = 'api.php';

const ETIQUETAS = {
	pendiente: 'Pendiente',
	en_transito: 'En tránsito',
	entregado: 'Entregado',
	disponible: 'Disponible',
	asignado: 'Asignado',
	en_ruta: 'En ruta',
};

const SIGUIENTE_ESTATUS = {
	pendiente: 'en_transito',
	en_transito: 'entregado',
};

async function api(recurso, { id = null, metodo = 'GET', datos = null, params = {} } = {}) {
	const query = new URLSearchParams({ recurso, ...params });
	if (id !== null) query.set('id', id);

	const opciones = { method: metodo };
	if (datos !== null) {
		opciones.headers = { 'Content-Type': 'application/json' };
		opciones.body = JSON.stringify(datos);
	}

	const respuesta = await fetch(`${API_URL}?${query}`, opciones);
	const json = await respuesta.json().catch(() => ({ error: 'El servidor respondió algo que no es JSON.' }));

	if (!respuesta.ok) {
		const error = new Error(json.error || `Error ${respuesta.status}`);
		error.campos = json.campos || {};
		throw error;
	}
	return json;
}

function dinero(cantidad) {
	return Number(cantidad).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });
}

function etiqueta(clave) {
	return ETIQUETAS[clave] ?? clave;
}

function avisar(texto, tipo = 'ok') {
	Alpine.store('aviso').mostrar(texto, tipo);
}

document.addEventListener('alpine:init', () => {
	Alpine.store('aviso', {
		texto: '',
		tipo: 'ok',
		temporizador: null,
		mostrar(texto, tipo) {
			this.texto = texto;
			this.tipo = tipo;
			clearTimeout(this.temporizador);
			this.temporizador = setTimeout(() => (this.texto = ''), 4000);
		},
	});

	Alpine.data('catalogo', (recurso, campos) => ({
		filas: [],
		form: {},
		editando: null,
		errores: {},
		cargando: true,
		guardando: false,

		init() {
			this.limpiar();
			this.cargar();
		},

		limpiar() {
			this.form = Object.fromEntries(campos.map((c) => [c, '']));
			this.editando = null;
			this.errores = {};
		},

		async cargar() {
			this.cargando = true;
			try {
				this.filas = await api(recurso);
			} catch (e) {
				avisar(e.message, 'error');
			} finally {
				this.cargando = false;
			}
		},

		editar(fila) {
			this.form = Object.fromEntries(campos.map((c) => [c, fila[c]]));
			this.editando = fila.id;
			this.errores = {};
		},

		async guardar() {
			this.guardando = true;
			this.errores = {};
			try {
				if (this.editando) {
					await api(recurso, { id: this.editando, metodo: 'PUT', datos: this.form });
					avisar('Cambios guardados');
				} else {
					await api(recurso, { metodo: 'POST', datos: this.form });
					avisar('Registro agregado');
				}
				this.limpiar();
				await this.cargar();
			} catch (e) {
				this.errores = e.campos;
				avisar(e.message, 'error');
			} finally {
				this.guardando = false;
			}
		},

		async eliminar(fila, descripcion) {
			if (!confirm(`¿Eliminar ${descripcion}?`)) return;
			try {
				await api(recurso, { id: fila.id, metodo: 'DELETE' });
				avisar('Registro eliminado');
				if (this.editando === fila.id) this.limpiar();
				await this.cargar();
			} catch (e) {
				avisar(e.message, 'error');
			}
		},
	}));

	Alpine.data('inicio', () => ({
		fletes: [],
		cargando: true,

		init() {
			this.cargar();
		},

		async cargar() {
			try {
				const todos = await api('fletes');
				this.fletes = todos.filter((f) => f.estatus !== 'entregado');
			} catch (e) {
				avisar(e.message, 'error');
			} finally {
				this.cargando = false;
			}
		},

		get grupos() {
			return [
				{ titulo: 'En tránsito', vacio: 'No hay fletes en tránsito.', lista: this.fletes.filter((f) => f.estatus === 'en_transito') },
				{ titulo: 'Por salir', vacio: 'No hay fletes pendientes de salir.', lista: this.fletes.filter((f) => f.estatus === 'pendiente') },
			];
		},

		async avanzar(flete) {
			const siguiente = SIGUIENTE_ESTATUS[flete.estatus];
			try {
				await api('fletes', { id: flete.id, metodo: 'PUT', datos: { estatus: siguiente } });
				avisar(`Flete #${flete.id}: ${etiqueta(siguiente)}`);
				await this.cargar();
			} catch (e) {
				avisar(e.message, 'error');
			}
		},
	}));

	Alpine.data('nuevoFlete', () => ({
		clientes: [],
		choferes: [],
		unidades: [],
		errores: {},
		guardando: false,
		form: { cliente_id: '', chofer_id: '', unidad_id: '', origen: '', destino: '', monto: '' },

		async init() {
			try {
				[this.clientes, this.choferes, this.unidades] = await Promise.all([
					api('clientes'),
					api('choferes', { params: { disponibles: 1 } }),
					api('unidades', { params: { disponibles: 1 } }),
				]);
			} catch (e) {
				avisar(e.message, 'error');
			}
		},

		async guardar() {
			this.guardando = true;
			this.errores = {};
			try {
				const flete = await api('fletes', { metodo: 'POST', datos: this.form });
				avisar(`Flete #${flete.id} registrado`);
				this.$router.push('/');
			} catch (e) {
				this.errores = e.campos;
				avisar(e.message, 'error');
			} finally {
				this.guardando = false;
			}
		},
	}));
});
