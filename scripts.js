function revisarInput(id, input){
	const texto = document.getElementById(input+'_'+id).value;
	const boton = document.getElementById('btn_'+id);
	boton.disabled = texto.trim() === '';
}

function obtenerOverlayCargaNavegacion(){
	let overlay = document.getElementById('carga-navegacion');
	if (overlay) return overlay;

	overlay = document.createElement('div');
	overlay.id = 'carga-navegacion';
	overlay.setAttribute('role', 'status');
	overlay.setAttribute('aria-live', 'polite');
	overlay.setAttribute('aria-hidden', 'true');
	overlay.innerHTML =
		'<div class="carga-navegacion-panel">' +
			'<span class="carga-navegacion-spinner" aria-hidden="true"></span>' +
			'<span class="carga-navegacion-texto">Cargando</span>' +
		'</div>';
	document.body.appendChild(overlay);
	return overlay;
}

function mostrarCargaNavegacion(texto = 'Cargando'){
	if (!document.body) return;
	const overlay = obtenerOverlayCargaNavegacion();
	const etiqueta = overlay.querySelector('.carga-navegacion-texto');
	if (etiqueta) etiqueta.textContent = texto;
	overlay.setAttribute('aria-hidden', 'false');
	document.body.classList.add('navegacion-cargando');
	window.requestAnimationFrame(() => {
		overlay.classList.add('visible');
	});
}

function ocultarCargaNavegacion(){
	const overlay = document.getElementById('carga-navegacion');
	if (!overlay) return;
	overlay.classList.remove('visible');
	overlay.setAttribute('aria-hidden', 'true');
	document.body.classList.remove('navegacion-cargando');
}

const DAM_DUPLICADOS_LOCALES_DESACTUALIZADOS = 'dam.duplicados.localesDesactualizados';
let marcaDuplicadosLocalesDesactualizadosMemoria = '';

function leerMarcaDuplicadosLocalesDesactualizados(){
	try {
		return window.sessionStorage.getItem(DAM_DUPLICADOS_LOCALES_DESACTUALIZADOS) || marcaDuplicadosLocalesDesactualizadosMemoria;
	} catch (error) {
		return marcaDuplicadosLocalesDesactualizadosMemoria;
	}
}

function marcarDuplicadosLocalesDesactualizados(){
	const marca = String(Date.now());
	marcaDuplicadosLocalesDesactualizadosMemoria = marca;
	try {
		window.sessionStorage.setItem(DAM_DUPLICADOS_LOCALES_DESACTUALIZADOS, marca);
	} catch (error) {
		// La señal en memoria mantiene actualizada la vista aunque sessionStorage no esté disponible.
	}
	window.dispatchEvent(new CustomEvent('dam:duplicados-locales-desactualizados', { detail: { marca } }));
}

function respuestaGuardadoCompletado(html){
	const contenedor = document.createElement('div');
	contenedor.innerHTML = html;
	const texto = contenedor.textContent || '';
	if (/Error:/i.test(texto) || /Respuesta vacía/i.test(texto)) {
		return false;
	}
	return /\b(updated|unchanged)\b/i.test(texto);
}

function alternarControlesGuardado(id, guardando){
	const formulario = document.getElementById('formulario_'+id);
	if (!formulario) return;

	formulario.dataset.guardando = guardando ? '1' : '0';
	formulario.setAttribute('aria-busy', guardando ? 'true' : 'false');

	formulario.querySelectorAll('.botones button, .botones a').forEach(control => {
		if (control.tagName === 'BUTTON') {
			if (guardando) {
				control.dataset.deshabilitadoPrevio = control.disabled ? '1' : '0';
				control.disabled = true;
			} else if ('deshabilitadoPrevio' in control.dataset) {
				control.disabled = control.dataset.deshabilitadoPrevio === '1';
				delete control.dataset.deshabilitadoPrevio;
			}
			return;
		}

		if (guardando) {
			control.dataset.tabindexPrevio = control.getAttribute('tabindex') ?? '';
			control.setAttribute('aria-disabled', 'true');
			control.classList.add('deshabilitado');
			control.tabIndex = -1;
		} else {
			control.removeAttribute('aria-disabled');
			control.classList.remove('deshabilitado');
			if ('tabindexPrevio' in control.dataset) {
				const tabindexPrevio = control.dataset.tabindexPrevio;
				if (tabindexPrevio === '') {
					control.removeAttribute('tabindex');
				} else {
					control.setAttribute('tabindex', tabindexPrevio);
				}
				delete control.dataset.tabindexPrevio;
			}
		}
	});
}

function sincronizarEstadoLocalGuardado(id){
	const panel = document.getElementById('pie_'+id);
	const articulo = document.getElementById('art_'+id);
	if (!panel) return;

	panel.querySelectorAll('.sugerido').forEach(elemento => {
		elemento.classList.remove('sugerido');
	});
	panel.querySelectorAll('.requiere-guardar').forEach(elemento => {
		elemento.classList.remove('requiere-guardar');
		elemento.removeAttribute('title');
	});
	panel.dataset.palabrasDuplicadas = '0';

	if (articulo && window.DAM && window.DAM.sincronizarIndicadoresArticulo) {
		window.DAM.sincronizarIndicadoresArticulo(articulo);
	}
}

function aplicarEstadoMetadatosActualizado(id, htmlEstado, respuestaGuardado){
	const plantilla = document.createElement('template');
	plantilla.innerHTML = htmlEstado.trim();

	const panelActual = document.getElementById('pie_'+id);
	const articuloActual = document.getElementById('art_'+id);
	const panelNuevo = plantilla.content.querySelector('#pie_'+id);
	const articuloNuevo = plantilla.content.querySelector('#art_'+id);

	if (!panelActual || !panelNuevo) {
		sincronizarEstadoLocalGuardado(id);
		return;
	}

	const panelVisible = !panelActual.hidden;
	panelActual.replaceWith(panelNuevo);
	panelNuevo.hidden = !panelVisible;

	const respuestaNueva = document.getElementById('respuesta_'+id);
	if (respuestaNueva) {
		respuestaNueva.innerHTML = respuestaGuardado;
	}

	if (window.DAM && window.DAM.prepararFormularioPanel) {
		window.DAM.prepararFormularioPanel(panelNuevo);
	}

	if (articuloActual && articuloNuevo) {
		const articuloActivo = articuloActual.classList.contains('activo');
		const articuloSeleccionado = articuloActual.classList.contains('seleccionado');
		const figureActual = articuloActual.querySelector('figure');
		const figureNuevo = articuloNuevo.querySelector('figure');
		if (figureActual && figureNuevo) {
			figureActual.replaceWith(figureNuevo);
		}
		articuloActual.className = articuloNuevo.className;
		if (articuloActivo) {
			articuloActual.classList.add('activo');
		}
		if (articuloSeleccionado) {
			articuloActual.classList.add('seleccionado');
		}

		const estilo = articuloNuevo.getAttribute('style');
		if (estilo === null) {
			articuloActual.removeAttribute('style');
		} else {
			articuloActual.setAttribute('style', estilo);
		}
	}

	if (articuloActual && window.DAM && window.DAM.sincronizarIndicadoresArticulo) {
		window.DAM.sincronizarIndicadoresArticulo(articuloActual);
	}
}

function actualizarEstadoMetadatosGuardados(id, datos, respuestaGuardado, alFinalizar){
	const xhr = new XMLHttpRequest();
	xhr.open('POST', 'index.php', true);
	xhr.setRequestHeader("Content-Type", "application/json;charset=UTF-8");
	xhr.onreadystatechange = function(){
		if (xhr.readyState === 4){
			try {
				if (xhr.status === 200){
					aplicarEstadoMetadatosActualizado(id, xhr.responseText, respuestaGuardado);
				} else {
					sincronizarEstadoLocalGuardado(id);
				}
			} finally {
				if (typeof alFinalizar === 'function') {
					alFinalizar();
				}
			}
		}
	};
	xhr.send(JSON.stringify({
		estado_metadatos: true,
		id: id,
		ruta: datos.ruta,
		media: datos.media
	}));
}

function obtenerRutaArchivoExtraido(html){
	const contenedor = document.createElement('div');
	contenedor.innerHTML = html;
	const marcador = contenedor.querySelector('[data-archivo-extraido]');
	if (marcador && marcador.dataset.archivoExtraido) {
		return marcador.dataset.archivoExtraido.trim();
	}

	const texto = contenedor.textContent || '';
	const coincidencia = texto.match(/Imagen extraída con éxito:\s*(.+?)(?:\s*2\.\s*Metadatos|$)/s);
	return coincidencia ? coincidencia[1].trim() : '';
}

function rutaYaVisibleEnMiniaturas(ruta){
	return Array.from(document.querySelectorAll('main [data-ruta]'))
		.some(elemento => elemento.dataset.ruta === ruta);
}

function solicitarBloqueMetadatos(ruta, media, despuesDeArticuloId){
	if (!ruta || rutaYaVisibleEnMiniaturas(ruta)) return;

	const xhr = new XMLHttpRequest();
	xhr.open('POST', 'index.php', true);
	xhr.setRequestHeader("Content-Type", "application/json;charset=UTF-8");
	xhr.onreadystatechange = function(){
		if (xhr.readyState === 4 && xhr.status === 200) {
			if (window.DAM && window.DAM.insertarBloqueHtml) {
				window.DAM.insertarBloqueHtml(xhr.responseText, despuesDeArticuloId);
			}
		}
	};
	xhr.send(JSON.stringify({
		estado_metadatos: true,
		id: String(Date.now()),
		ruta: ruta,
		media: media
	}));
}

function obtenerParametrosVistaActual(){
	const params = new URLSearchParams(window.location.search);
	const paginador = document.querySelector('.col-contenido > .paginación.condensado')
		|| document.querySelector('.paginación');
	const controlPagina = paginador?.querySelector('[name="pagina"]');
	const controlPaginaRange = paginador?.querySelector('[data-paginacion-pagina-range]');
	const controlVer = paginador?.querySelector('input[name="ver"]');
	const controlMedia = paginador?.querySelector('[name="media"]');
	const controlRuta = paginador?.querySelector('input[name="ruta"]');
	const pagina = parseInt(params.get('pagina') || controlPagina?.value || '1', 10);
	const ver = parseInt(params.get('ver') || controlVer?.value || '6', 10);
	const totalPaginas = parseInt(controlPaginaRange?.max || '1', 10);
	const filtros = {};
	['geo', 'regiones', 'rotacion', 'palabras', 'sugerencias', 'duplicadas', 'tracking'].forEach(clave => {
		filtros[clave] = params.get(clave) || '';
	});

	return {
		pagina: Number.isFinite(pagina) && pagina > 0 ? pagina : 1,
		ver: Number.isFinite(ver) && ver > 0 ? ver : 6,
		total_paginas: Number.isFinite(totalPaginas) && totalPaginas > 0 ? totalPaginas : 1,
		media: params.get('media') || controlMedia?.value || '',
		ruta: params.get('ruta') || controlRuta?.value || '',
		archivo: params.get('archivo') || '',
		palabra_clave: params.get('palabra_clave') || '',
		...filtros
	};
}

function totalElementosVistaActual(){
	const resumen = document.querySelector('.filtros-metadatos-resumen')?.textContent || '';
	const coincidencia = resumen.match(/^\s*(\d+)\s+de\s+\d+/);
	return coincidencia ? Number(coincidencia[1]) : null;
}

function primerArchivoPaginaActual(){
	return document.querySelector('main article[data-panel-id] [data-ruta]')?.dataset.ruta || '';
}

function vistaLocalActivaRevisable(){
	const params = new URLSearchParams(window.location.search);
	const panel = params.get('panel') || '';
	if (panel === 'duplicados' || panel === 'yandex') return false;
	if (document.hidden) return false;
	if (!document.querySelector('main')) return false;
	if (document.querySelector('[data-guardando="1"], [aria-busy="true"]')) return false;
	if (document.activeElement?.closest?.('input, textarea, select, [contenteditable="true"]')) return false;
	return true;
}

let revisionVistaLocalEnCurso = false;
let ultimaRevisionVistaLocal = 0;

async function revisarCambiosVistaLocal(forzar = false){
	if (!vistaLocalActivaRevisable()) return;
	if (revisionVistaLocalEnCurso) return;

	const ahora = Date.now();
	if (!forzar && ultimaRevisionVistaLocal > 0 && ahora - ultimaRevisionVistaLocal < 15000) return;
	ultimaRevisionVistaLocal = ahora;

	const parametros = obtenerParametrosVistaActual();
	if (parametros.palabra_clave) return;

	revisionVistaLocalEnCurso = true;
	try {
		const respuesta = await fetch('index.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json; charset=UTF-8' },
			body: JSON.stringify({
				estado_vista_local: true,
				...parametros
			})
		});
		const datos = await respuesta.json().catch(() => null);
		if (!respuesta.ok || !datos?.ok) return;

		const totalActual = totalElementosVistaActual();
		const primerActual = primerArchivoPaginaActual();
		const totalNuevo = Number(datos.total_elementos);
		const primerNuevo = datos.primer_archivo_pagina || '';
		const totalCambio = totalActual !== null && Number.isFinite(totalNuevo) && totalActual !== totalNuevo;
		const primerCambio = primerNuevo && primerActual && primerNuevo !== primerActual;
		const paginaVaciaConContenido = !primerActual && primerNuevo;

		if (totalCambio || primerCambio || paginaVaciaConContenido) {
			mostrarCargaNavegacion('Actualizando archivos');
			window.location.reload();
		}
	} finally {
		revisionVistaLocalEnCurso = false;
	}
}

function normalizarRutaVista(ruta){
	return (ruta || '').replace(/\\/g, '/').replace(/^\/+|\/+$/g, '');
}

function urlRaizCarpetas(){
	const url = new URL(window.location.href);
	['archivo', 'ruta', 'pagina', 'palabra_clave'].forEach(parametro => {
		url.searchParams.delete(parametro);
	});
	url.searchParams.set('panel', 'carpetas');
	return url.toString();
}

function urlPanelYandexDisk(ruta){
	const url = new URL(window.location.href);
	url.searchParams.set('panel', 'yandex');
	url.searchParams.set('yandex_path', normalizarRutaVista(ruta) || '/');
	url.searchParams.delete('pagina');
	return url.toString();
}

function redirigirARaizCarpetas(){
	mostrarCargaNavegacion('Volviendo a la raíz');
	window.location.href = urlRaizCarpetas();
}

function redirigirAPaginaVista(pagina, texto = 'Actualizando página'){
	const paginaDestino = Math.max(1, parseInt(pagina || '1', 10) || 1);
	const url = new URL(window.location.href);
	url.searchParams.set('pagina', String(paginaDestino));
	mostrarCargaNavegacion(texto);
	window.location.href = url.toString();
}

function directorioEliminadoAfectaVista(directorio){
	const params = new URLSearchParams(window.location.search);
	const vista = normalizarRutaVista(params.get('ruta') || params.get('archivo') || '');
	const eliminado = normalizarRutaVista(directorio);
	return Boolean(vista && eliminado && (vista === eliminado || vista.startsWith(`${eliminado}/`)));
}

function manejarDirectorioEliminadoDesdeHeader(xhr){
	const header = xhr.getResponseHeader?.('X-DAM-Deleted-Dir') || '';
	if (!header) return false;
	let directorio = header;
	try {
		directorio = decodeURIComponent(header);
	} catch (err) {
		// El encabezado sigue siendo comparable en crudo si no puede decodificarse.
	}
	if (!directorioEliminadoAfectaVista(directorio)) return false;
	redirigirARaizCarpetas();
	return true;
}

function cantidadArticulosVista(){
	return document.querySelectorAll('main article[data-panel-id]').length;
}

function redirigirSiPaginaVacia(){
	if (cantidadArticulosVista() > 0) return;
	const parametros = obtenerParametrosVistaActual();
	if (parametros.total_paginas > 1) {
		const paginaDestino = Math.max(1, parametros.pagina - 1);
		redirigirAPaginaVista(paginaDestino, 'Cargando página anterior');
	}
}

function manejarPaginacionLote(datos, parametros){
	const paginacion = datos?.paginacion;
	if (!paginacion || !paginacion.ok) return false;

	const totalActual = Number(parametros.total_paginas || 1);
	const totalNuevo = Number(paginacion.total_paginas || 1);
	const paginaNueva = Number(paginacion.pagina_actual || parametros.pagina || 1);
	const paginaCorregida = Boolean(paginacion.pagina_corregida);
	if (
		paginaCorregida
		|| (Number.isFinite(totalNuevo) && Number.isFinite(totalActual) && totalNuevo !== totalActual)
	) {
		redirigirAPaginaVista(paginaNueva, paginaCorregida ? 'Cargando página anterior' : 'Actualizando paginación');
		return true;
	}

	return false;
}

function solicitarRellenoPagina(indice = null){
	const main = document.querySelector('main');
	if (!main) return Promise.resolve(null);

	const parametros = obtenerParametrosVistaActual();
	return new Promise(resolve => {
		const xhr = new XMLHttpRequest();
		xhr.open('POST', 'index.php', true);
		xhr.setRequestHeader("Content-Type", "application/json;charset=UTF-8");
		xhr.onreadystatechange = function(){
			if (xhr.readyState !== 4) return;
			if (xhr.status !== 200 || !xhr.responseText.trim()) {
				resolve(null);
				return;
			}

			const plantilla = document.createElement('template');
			plantilla.innerHTML = xhr.responseText.trim();
			const ruta = plantilla.content.querySelector('[data-ruta]')?.dataset.ruta || '';
			if (ruta && rutaYaVisibleEnMiniaturas(ruta)) {
				resolve(null);
				return;
			}

			const articulos = Array.from(document.querySelectorAll('main article[data-panel-id]'));
			const ultimoArticulo = articulos[articulos.length - 1];
			let articuloInsertado = null;
			if (window.DAM && window.DAM.insertarBloqueHtml) {
				articuloInsertado = window.DAM.insertarBloqueHtml(xhr.responseText, ultimoArticulo?.id || '');
			}
			resolve(articuloInsertado);
		};
		const payload = {
			relleno_pagina: true,
			id: String(Date.now()) + '_' + cantidadArticulosVista(),
			pagina: parametros.pagina,
			ver: parametros.ver,
			media: parametros.media,
			ruta: parametros.ruta,
			archivo: parametros.archivo,
			palabra_clave: parametros.palabra_clave,
			geo: parametros.geo,
			regiones: parametros.regiones,
			rotacion: parametros.rotacion,
			palabras: parametros.palabras,
			sugerencias: parametros.sugerencias,
			duplicadas: parametros.duplicadas,
			tracking: parametros.tracking
		};
		if (Number.isInteger(indice) && indice >= 0) {
			payload.indice = indice;
		}
		xhr.send(JSON.stringify(payload));
	});
}

async function rellenarVistaHastaCompleta(){
	const parametros = obtenerParametrosVistaActual();
	const objetivo = parametros.ver;
	let intentos = 0;
	while (cantidadArticulosVista() < objetivo && intentos < objetivo) {
		const indice = ((parametros.pagina - 1) * objetivo) + cantidadArticulosVista();
		const articulo = await solicitarRellenoPagina(indice);
		if (!articulo) break;
		intentos++;
	}
}

function actualizarVistaTrasExtraccion(id, respuestaHtml){
	const rutaExtraida = obtenerRutaArchivoExtraido(respuestaHtml);
	if (!rutaExtraida) return;
	solicitarBloqueMetadatos(rutaExtraida, 'img', 'art_'+id);
}

function actualizarVistaTrasMovimiento(id, mensajeHtml){
	const hacerRelleno = async () => {
		await rellenarVistaHastaCompleta();
		if (cantidadArticulosVista() === 0) {
			const parametros = obtenerParametrosVistaActual();
			if (parametros.total_paginas > 1) {
				const paginaDestino = Math.max(1, parametros.pagina - 1);
				redirigirAPaginaVista(paginaDestino, 'Cargando página anterior');
			}
		}
	};

	if (window.DAM && window.DAM.removerBloqueArticulo) {
		window.DAM.removerBloqueArticulo(id, mensajeHtml);
		hacerRelleno();
		return;
	}

	document.getElementById('art_'+id)?.remove();
	document.getElementById('pie_'+id)?.remove();
	hacerRelleno();
}

function abrirArchivo(id){
	const panel = document.getElementById('pie_'+id);
	const respuesta = document.getElementById('respuesta_'+id);
	const boton = document.getElementById('abrir_archivo_'+id);
	const ruta = panel?.querySelector('.ruta_local')?.value || '';
	if (!ruta || !respuesta) return;

	if (boton) {
		boton.disabled = true;
		boton.setAttribute('aria-busy', 'true');
	}
	respuesta.innerHTML = '<code>Abriendo archivo...</code>';

	const xhr = new XMLHttpRequest();
	xhr.open('POST', 'index.php', true);
	xhr.setRequestHeader("Content-Type", "application/json;charset=UTF-8");
	xhr.onreadystatechange = function(){
		if (xhr.readyState === 4){
			respuesta.innerHTML = xhr.responseText || '<code>Sin respuesta de open.</code>';
			if (boton) {
				boton.disabled = false;
				boton.removeAttribute('aria-busy');
			}
		}
	};
	try {
		xhr.send(JSON.stringify({abrir_archivo: ruta}));
	} catch (error) {
		if (boton) {
			boton.disabled = false;
			boton.removeAttribute('aria-busy');
		}
		throw error;
	}
}

/**
 * Abre la carpeta contenedora del archivo en el Finder.
 * Lee la ruta desde el input .ruta_local del panel y envía
 * la acción 'abrir_carpeta' al servidor.
 */
function abrirCarpeta(id){
	const panel = document.getElementById('pie_'+id);
	const respuesta = document.getElementById('respuesta_'+id);
	const boton = document.getElementById('abrir_carpeta_'+id);
	const ruta = panel?.querySelector('.ruta_local')?.value || '';
	if (!ruta || !respuesta) return;

	if (boton) {
		boton.disabled = true;
		boton.setAttribute('aria-busy', 'true');
	}
	respuesta.innerHTML = '<code>Abriendo carpeta...</code>';

	const xhr = new XMLHttpRequest();
	xhr.open('POST', 'index.php', true);
	xhr.setRequestHeader("Content-Type", "application/json;charset=UTF-8");
	xhr.onreadystatechange = function(){
		if (xhr.readyState === 4){
			respuesta.innerHTML = xhr.responseText || '<code>Sin respuesta de open.</code>';
			if (boton) {
				boton.disabled = false;
				boton.removeAttribute('aria-busy');
			}
		}
	};
	try {
		xhr.send(JSON.stringify({abrir_carpeta: ruta}));
	} catch (error) {
		if (boton) {
			boton.disabled = false;
			boton.removeAttribute('aria-busy');
		}
		throw error;
	}
}

function guardar(id){
	const xhr = new XMLHttpRequest();
	const respuesta = document.getElementById('respuesta_'+id);
	const formulario = document.getElementById('formulario_'+id);
	const datosFormulario = new FormData(formulario);
	let datos = {};
	datosFormulario.forEach((valor, clave) => {
		datos[clave] = valor;
	});

	console.log('datos.createdate', datos.createdate);
	// Corrige la pérdida de segundos cuando son 00
	if(typeof datos.createdate === 'string' && datos.createdate.length === 16) {
		datos.createdate += ':00';
		console.log('datos.createdate (fix)', datos.createdate);
	}
	datos['ruta'] = document.getElementById('img_'+id).getAttribute('data-ruta');
	datos['media'] = document.getElementById('img_'+id).getAttribute('data-tipo');

/*
	const imagen = document.getElementById('img_'+id).getAttribute('data-ruta');
	const tipo = document.getElementById('img_'+id).getAttribute('data-tipo');
	const etiquetas = document.getElementById('txt_'+id).value;
	const fechayhora = document.getElementById('createdate_'+id).value;
	const offtime = document.getElementById('offsettime_'+id).value;
	const derechos = document.getElementById('copyright_'+id).value;
	const rotar = document.getElementById('orientacion_'+id).value;
	const ubicación = document.getElementById('location_'+id).value;
*/
	console.log('imagen', datos['ruta']);
	console.log('datos', datos);
	//return;
	alternarControlesGuardado(id, true);
	xhr.open('POST', 'index.php', true)
	xhr.setRequestHeader("Content-Type", "application/json;charset=UTF-8");
	xhr.onreadystatechange = function(){
		if (xhr.readyState === 4){
			if (xhr.status === 200){
				respuesta.innerHTML = xhr.responseText;
				if (respuestaGuardadoCompletado(xhr.responseText)) {
					actualizarEstadoMetadatosGuardados(id, datos, xhr.responseText, () => {
						alternarControlesGuardado(id, false);
					});
				} else {
					alternarControlesGuardado(id, false);
				}
			} else {
				const errorMensaje = document.createElement('div');
				errorMensaje.className = 'respuesta_error';
				errorMensaje.textContent = 'Error al enviar la información ' + xhr.statusText;
				respuesta.innerHTML = '';
				respuesta.appendChild(errorMensaje);
				alternarControlesGuardado(id, false);
			}
		}
	};
	try {
		xhr.send(JSON.stringify(datos));
	} catch (error) {
		alternarControlesGuardado(id, false);
		throw error;
	}
}
function mover(id, accion){
	console.log('Acción:', accion);
	const xhr = new XMLHttpRequest();
	const respuesta = document.getElementById('respuesta_'+id);
	const ruta = document.getElementById('img_'+id).getAttribute('data-ruta');
	console.log('Archivo:', ruta);
	const imagen = document.getElementById('img_'+id);
	const art = document.getElementById('art_'+id);
	const etiquetas = document.getElementById('txt_'+id);
	const boton = document.getElementById('btn_'+id);
	const bmover = document.getElementById('bcara_'+id);
	const barchivar = document.getElementById('blisto_'+id);

	xhr.open('POST', 'index.php', true)
	xhr.setRequestHeader("Content-Type", "application/json;charset=UTF-8");
	xhr.onreadystatechange = function(){
		if (xhr.readyState === 4){
			if (xhr.status === 200){
				let textoRespuesta = xhr.responseText.split('\n');
				if(textoRespuesta[0] == 1){
					if (['archivar', 'borrar', 'caras'].includes(accion)) {
						marcarDuplicadosLocalesDesactualizados();
					}

					const mensajeExito =
					'<span style="color:green">' +
					'Archivo movido con éxito' +
					textoRespuesta[1] +
					'</span>';
					respuesta.innerHTML = mensajeExito;
					if (accion === 'archivar' || accion === 'borrar') {
						if (manejarDirectorioEliminadoDesdeHeader(xhr)) return;
						actualizarVistaTrasMovimiento(id, mensajeExito);
					} else {
						imagen.style.opacity = .2;
						art.style.pointerEvents = 'none';
						imagen.style.filter = 'grayscale(0.4) blur(5px)';
						etiquetas.disabled = true;
						if(bmover) {
							bmover.disabled = true;
						}
						boton.disabled = true;
						barchivar.disabled = true;
					}
				} else {
					respuesta.innerHTML = '<span style="color:red">No se pudo mover el archivo</span><br>' + xhr.responseText;
				}
			} else {
				const errorMensaje = document.createElement('div');
				errorMensaje.className = 'respuesta_error';
				errorMensaje.textContent = 'Error al enviar la información ' + xhr.statusText;
				respuesta.appendChild(errorMensaje);
			}
		}
	};
	if(accion == 'caras'){
		xhr.send(JSON.stringify({caras: ruta}));
	} else if (accion == 'archivar') {
		xhr.send(JSON.stringify({listo: ruta}));
	} else if (accion == 'borrar') {
		xhr.send(JSON.stringify({borrar: ruta}));
	}
}
function extraer(id){
	const video = document.getElementById('img_'+id)?.getAttribute('data-ruta') || '';
	if (!video) return;
	const params = new URLSearchParams();
	params.set('video', video);
	params.set('volver', window.location.href);
	window.location.href = `extraer.php?${params.toString()}`;
}
function convertir(id){
	console.log('Convertir imagen a WebP:', id);
	const xhr = new XMLHttpRequest();
	const respuesta = document.getElementById('respuesta_'+id);
	const video = document.getElementById('img_'+id).getAttribute('data-ruta');
	console.log('imagen', video);
	xhr.open('POST', 'index.php', true)
	xhr.setRequestHeader("Content-Type", "application/json;charset=UTF-8");
	xhr.onreadystatechange = function(){
		if (xhr.readyState === 4){
			if (xhr.status === 200){
				respuesta.innerHTML = xhr.responseText;
			} else {
				const errorMensaje = document.createElement('div');
				errorMensaje.className = 'respuesta_error';
				errorMensaje.textContent = 'Error al enviar la información ' + xhr.statusText;
				respuesta.appendChild(errorMensaje);
			}
		}
	};
	xhr.send(JSON.stringify({convertir: video}));
}
function corregirRegiones(id, eje){
	if (!['horizontal', 'vertical'].includes(eje)) return;
	console.log('Corregir regiones:', id, eje);
	const xhr = new XMLHttpRequest();
	const respuesta = document.getElementById('respuesta_'+id);
	const media = document.getElementById('img_'+id);
	const ruta = media?.getAttribute('data-ruta') || '';
	const tipo = media?.getAttribute('data-tipo') || 'img';
	if (!ruta) return;

	alternarControlesGuardado(id, true);
	xhr.open('POST', 'index.php', true);
	xhr.setRequestHeader("Content-Type", "application/json;charset=UTF-8");
	xhr.onreadystatechange = function(){
		if (xhr.readyState === 4){
			if (xhr.status === 200){
				respuesta.innerHTML = xhr.responseText;
				if (respuestaGuardadoCompletado(xhr.responseText)) {
					actualizarEstadoMetadatosGuardados(id, { ruta: ruta, media: tipo }, xhr.responseText, () => {
						alternarControlesGuardado(id, false);
					});
				} else {
					alternarControlesGuardado(id, false);
				}
			} else {
				const errorMensaje = document.createElement('div');
				errorMensaje.className = 'respuesta_error';
				errorMensaje.textContent = 'Error al enviar la información ' + xhr.statusText;
				respuesta.innerHTML = '';
				respuesta.appendChild(errorMensaje);
				alternarControlesGuardado(id, false);
			}
		}
	};
	try {
		xhr.send(JSON.stringify({corregir_region: eje, ruta: ruta}));
	} catch (error) {
		alternarControlesGuardado(id, false);
		throw error;
	}
}

document.addEventListener('DOMContentLoaded', function () {
	obtenerOverlayCargaNavegacion();
	window.addEventListener('pageshow', ocultarCargaNavegacion);
	let temporizadorRevisionVistaLocal = null;
	const programarRevisionVistaLocal = (forzar = false, demora = 600) => {
		window.clearTimeout(temporizadorRevisionVistaLocal);
		temporizadorRevisionVistaLocal = window.setTimeout(() => {
			revisarCambiosVistaLocal(forzar);
		}, demora);
	};
	window.addEventListener('pageshow', () => programarRevisionVistaLocal(true, 1200));
	window.addEventListener('focus', () => programarRevisionVistaLocal(true, 500));
	document.addEventListener('visibilitychange', () => {
		if (!document.hidden) {
			programarRevisionVistaLocal(true, 500);
		}
	});
	window.setInterval(() => revisarCambiosVistaLocal(false), 45000);
	const claveFocoPalabraClave = 'dam.palabrasClave.foco';

	function leerStorageSesion(clave) {
		try {
			return window.sessionStorage.getItem(clave);
		} catch (err) {
			return null;
		}
	}

	function escribirStorageSesion(clave, valor) {
		try {
			window.sessionStorage.setItem(clave, valor);
		} catch (err) {
			// La navegación no depende de sessionStorage.
		}
	}

	function borrarStorageSesion(clave) {
		try {
			window.sessionStorage.removeItem(clave);
		} catch (err) {
			// Sin impacto funcional si el navegador bloquea sessionStorage.
		}
	}

	document.querySelectorAll('.paginación form, .explorador-carpetas, .filtros-metadatos').forEach(formulario => {
		formulario.addEventListener('submit', function () {
			mostrarCargaNavegacion('Cargando página');
		});
	});

	document.querySelectorAll('[data-paginacion-pagina-range]').forEach(control => {
		const formulario = control.form;
		const salida = formulario?.querySelector('[data-paginacion-pagina-valor]');
		const actualizarSalida = () => {
			if (salida) salida.textContent = control.value;
		};
		control.addEventListener('input', actualizarSalida);
		control.addEventListener('change', function () {
			actualizarSalida();
			if (!formulario) return;
			if (typeof formulario.requestSubmit === 'function') {
				formulario.requestSubmit();
			} else {
				formulario.submit();
			}
		});
		actualizarSalida();
	});

	document.addEventListener('click', function (ev) {
		if (ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
		const enlace = ev.target.closest?.('.paginación a, .palabra-clave-link, .yandex-disk-panel a');
		if (!enlace || !enlace.href) return;
		if (enlace.target === '_blank') return;
		if (enlace.classList.contains('palabra-clave-link')) {
			escribirStorageSesion(
				claveFocoPalabraClave,
				enlace.dataset.palabraBusqueda || enlace.dataset.palabra || ''
			);
		}
		mostrarCargaNavegacion('Cargando');
	});

	/* ─────────── Resolver dirección desde GEO ─────────── */
	document.addEventListener('click', function (ev) {
		const btn = ev.target.closest?.('.geo-resolver');
		if (!btn) return;
		const id = btn.dataset.id;
		const panel = document.getElementById('pie_' + id);
		const respuesta = document.getElementById('respuesta_' + id);
		if (!panel || !respuesta) return;
		btn.disabled = true;
		btn.textContent = '⏳';
		const xhr = new XMLHttpRequest();
		xhr.open('POST', 'index.php', true);
		xhr.setRequestHeader('Content-Type', 'application/json;charset=UTF-8');
		xhr.onreadystatechange = function () {
			if (xhr.readyState !== 4) return;
			if (xhr.status === 200) {
				try {
					const datos = JSON.parse(xhr.responseText);
					if (datos.ok) {
						// Poblar campos ocultos para que "Guardar datos" los persista
						const geoCountry = document.getElementById('geo_country_' + id);
						const geoCountryCode = document.getElementById('geo_country_code_' + id);
						const geoState = document.getElementById('geo_state_' + id);
						const geoCity = document.getElementById('geo_city_' + id);
						const locInput = document.getElementById('location_' + id);
						if (geoCountry) geoCountry.value = datos.country || '';
						if (geoCountryCode) geoCountryCode.value = datos.country_code || '';
						if (geoState) geoState.value = datos.state || '';
						if (geoCity) geoCity.value = datos.city || '';
						// También poblar el campo de ubicación si está vacío
						if (locInput && !locInput.value.trim()) {
							locInput.value = [datos.city, datos.state].filter(Boolean).join(', ');
						}
						const geoDiv = btn.closest('.metadata-geo');
						if (geoDiv) {
							// Remover el botón y mostrar los datos obtenidos
							btn.remove();
							const partes = [];
							if (datos.city) partes.push('🗺️ ' + datos.city);
							if (datos.state) partes.push(datos.state);
							if (datos.country) partes.push(datos.country);
							const info = document.createElement('div');
							info.textContent = partes.join(', ');
							geoDiv.appendChild(info);
						}
						respuesta.innerHTML = '<code>Dirección resuelta: ' +
							[datos.city, datos.state, datos.country].filter(Boolean).join(', ') + '</code>';
						return; // btn ya fue eliminado
					} else {
						respuesta.innerHTML = '<code>No se encontró dirección para estas coordenadas.</code>';
					}
				} catch (e) {
					respuesta.innerHTML = '<code>Error al procesar la respuesta.</code>';
				}
			} else {
				respuesta.innerHTML = '<code>Error del servidor.</code>';
			}
			btn.disabled = false;
			btn.textContent = '🌐';
		};
		xhr.send(JSON.stringify({
			resolver_geo: true,
			lat: btn.dataset.lat,
			lon: btn.dataset.lon
		}));
	});

	const claveColumnaCarpetas = 'dam.columnaCarpetas.colapsada';
	const claveArbolDirectorios = 'dam.arbolDirectorios.ramasAbiertas';

	function leerStorage(clave) {
		try {
			return window.localStorage.getItem(clave);
		} catch (err) {
			return null;
		}
	}

	function escribirStorage(clave, valor) {
		try {
			window.localStorage.setItem(clave, valor);
		} catch (err) {
			// La navegación sigue funcionando aunque el navegador bloquee localStorage.
		}
	}

	const normalizarBusquedaLateral = (texto) => (texto || '')
		.toLowerCase()
		.normalize('NFD')
		.replace(/[\u0300-\u036f]/g, '');
	const clavePestanaLateral = 'dam.columnaCarpetas.pestana';
	const tabsLaterales = Array.from(document.querySelectorAll('[data-sidebar-tab]'));
	const panelesLaterales = Array.from(document.querySelectorAll('.panel-lateral[role="tabpanel"]'));
	if (tabsLaterales.length && panelesLaterales.length) {
		const nombresPestanasLaterales = tabsLaterales
			.map(tab => tab.dataset.sidebarTab)
			.filter(Boolean);
		const pestanaLateralDefecto = nombresPestanasLaterales.includes('carpetas')
			? 'carpetas'
			: (nombresPestanasLaterales[0] || '');

		function activarPestanaLateral(nombre, guardar = true) {
			tabsLaterales.forEach(tab => {
				const activa = tab.dataset.sidebarTab === nombre;
				tab.classList.toggle('activo', activa);
				tab.setAttribute('aria-selected', activa ? 'true' : 'false');
			});
			panelesLaterales.forEach(panel => {
				const activa = panel.id === `panel-${nombre}`;
				panel.hidden = !activa;
			});
			if (guardar) {
				escribirStorage(clavePestanaLateral, nombre);
			}
			document.dispatchEvent(new CustomEvent('dam:sidebar-tab-activated', { detail: { nombre } }));
		}

		const pestañaInicial = tabsLaterales.find(tab => tab.classList.contains('activo'))?.dataset.sidebarTab || pestanaLateralDefecto;
		activarPestanaLateral(nombresPestanasLaterales.includes(pestañaInicial) ? pestañaInicial : pestanaLateralDefecto, false);

		function urlParaPestanaLateral(nombre) {
			const url = new URL(window.location.href);
			url.searchParams.delete('pagina');
			if (nombre === 'yandex') {
				url.searchParams.set('panel', 'yandex');
				url.searchParams.delete('palabra_clave');
				if (!url.searchParams.get('yandex_path')) {
					url.searchParams.set('yandex_path', '/');
				}
			} else if (nombre === 'duplicados') {
				url.searchParams.set('panel', 'duplicados');
				url.searchParams.delete('pagina');
				url.searchParams.delete('palabra_clave');
				url.searchParams.delete('yandex_path');
				url.searchParams.delete('yandex_sort');
			} else if (nombre === 'palabras') {
				url.searchParams.set('panel', 'palabras');
				url.searchParams.delete('yandex_path');
				url.searchParams.delete('yandex_sort');
			} else {
				url.searchParams.delete('panel');
				url.searchParams.delete('yandex_path');
				url.searchParams.delete('yandex_sort');
				url.searchParams.delete('palabra_clave');
			}
			return url;
		}

		tabsLaterales.forEach(tab => {
			tab.addEventListener('click', function () {
				const nombre = tab.dataset.sidebarTab;
				if (!nombre) return;
				const panel = document.getElementById(`panel-${nombre}`);
				const cargado = panel?.dataset.panelLoaded === '1';
				const activa = tab.classList.contains('activo');
				if (activa && cargado) {
					activarPestanaLateral(nombre);
					return;
				}
				escribirStorage(clavePestanaLateral, nombre);
				window.location.href = urlParaPestanaLateral(nombre).toString();
			});
		});
	}

		const vistaDuplicados = document.getElementById('duplicados-vista');
		if (vistaDuplicados) {
			const botonIniciarDuplicados = document.getElementById('duplicados-iniciar');
			const botonRecalcularDuplicados = document.getElementById('duplicados-recalcular');
			const botonCancelarDuplicados = document.getElementById('duplicados-cancelar');
			const botonCatalogarYandexDuplicados = document.getElementById('duplicados-yandex-catalogar');
			const botonCancelarCatalogoYandexDuplicados = document.getElementById('duplicados-yandex-catalogar-cancelar');
			const botonIniciarYandexDuplicados = document.getElementById('duplicados-yandex-iniciar');
			const botonCancelarYandexDuplicados = document.getElementById('duplicados-yandex-cancelar');
			const progresoDuplicados = document.getElementById('duplicados-progreso');
			const progresoCatalogoYandexDuplicados = document.getElementById('duplicados-yandex-catalogo-progreso');
			const progresoYandexDuplicados = document.getElementById('duplicados-yandex-progreso');
			const mensajeDuplicados = document.getElementById('duplicados-mensaje');
			const mensajeCatalogoYandexDuplicados = document.getElementById('duplicados-yandex-catalogo-mensaje');
			const mensajeYandexDuplicados = document.getElementById('duplicados-yandex-mensaje');
			const resultadosDuplicados = document.getElementById('duplicados-resultados');
			const buscadorDuplicados = document.getElementById('duplicados-buscador');
			const selectorOrdenDuplicados = document.getElementById('duplicados-orden');
			const botonesFiltroOrigenDuplicados = Array.from(document.querySelectorAll('[data-duplicados-filtro-origen]'));
			const cargaMasDuplicados = document.getElementById('duplicados-carga-mas');
			const botonCargarMasDuplicados = cargaMasDuplicados?.querySelector('[data-duplicados-cargar-mas]');
			const estadoCargaMasDuplicados = cargaMasDuplicados?.querySelector('[data-duplicados-carga-estado]');
			const limitePaginaDuplicados = Math.max(1, Number(resultadosDuplicados?.dataset.duplicadosPageSize || vistaDuplicados.dataset.duplicadosPageSize || 24));
			let temporizadorDuplicados = null;
			let itemDuplicadoActivo = null;
			let offsetDuplicados = 0;
			let hayMasDuplicados = true;
			let cargandoGruposDuplicados = false;
			let trabajoDuplicadosActivoAnterior = false;
			let filtroOrigenDuplicados = 'todos';
			let ordenDuplicados = 'relevancia';
			let modalMetadatosDuplicados = null;
			let cargaModalMetadatosDuplicados = 0;
			let marcaDuplicadosLocalesVista = leerMarcaDuplicadosLocalesDesactualizados();
			let recargaDuplicadosLocalesPendiente = false;
			let conteosOrigenDuplicadosSolicitados = false;
			let conteosOrigenDuplicadosEnCurso = false;
			let conteosOrigenDuplicadosActuales = null;
			let versionConteosOrigenDuplicados = 0;
			let conteosOrigenDuplicadosReintentoPendiente = false;
			let conteosOrigenDuplicadosUltimaSolicitud = 0;

			function trabajoDuplicadosActivo(job) {
				return job && ['queued', 'scanning', 'hashing', 'cancelando'].includes(String(job.estado || ''));
			}

			function trabajoYandexDuplicadosActivo(job) {
				return job && ['queued', 'preparando', 'consultando', 'cancelando'].includes(String(job.estado || ''));
			}

			function trabajoCatalogoYandexDuplicadosActivo(job) {
				return job && ['queued', 'catalogando', 'cancelando'].includes(String(job.estado || ''));
			}

			function normalizarFiltroOrigenDuplicados(valor) {
				return ['local', 'remoto', 'mixto'].includes(String(valor || '')) ? String(valor) : 'todos';
			}

			function normalizarOrdenDuplicados(valor) {
				return String(valor || '') === 'tamano' ? 'tamano' : 'relevancia';
			}

			function etiquetaFiltroOrigenDuplicados() {
				return {
					local: 'locales',
					remoto: 'remotos',
					mixto: 'mixtos'
				}[filtroOrigenDuplicados] || '';
			}

			function mensajeVacioDuplicados() {
				const etiqueta = etiquetaFiltroOrigenDuplicados();
				return etiqueta
					? `No hay duplicados ${etiqueta} exactos ni probables con las firmas disponibles.`
					: 'No hay duplicados exactos ni probables con las firmas disponibles.';
			}

			function formatearNumeroDuplicados(valor) {
				const numero = Math.max(0, Number(valor) || 0);
				try {
					return new Intl.NumberFormat('es-MX').format(numero);
				} catch (error) {
					return String(numero);
				}
			}

			function textoConteoOrigenDuplicados(conteos, origen) {
				const dato = conteos && typeof conteos === 'object' ? conteos[origen] : null;
				if (!dato) return '...';
				const tieneConteoCache = Number.isFinite(Number(dato.grupos)) && Number(conteos.actualizado || 0) > 0;
				if (dato.pendiente && !tieneConteoCache) return '...';
				return `${formatearNumeroDuplicados(dato.grupos)}${dato.mas ? '+' : ''}`;
			}

			function actualizarConteosOrigenDuplicados(conteos) {
				if (!conteos || typeof conteos !== 'object') return true;
				conteosOrigenDuplicadosActuales = {
					local: { ...(conteos.local || {}) },
					remoto: { ...(conteos.remoto || {}) },
					mixto: { ...(conteos.mixto || {}) },
					pendiente: Boolean(conteos.pendiente),
					actualizado: Number(conteos.actualizado || 0)
				};
				let pendiente = Boolean(conteos.pendiente);
				botonesFiltroOrigenDuplicados.forEach(boton => {
					const origen = normalizarFiltroOrigenDuplicados(boton.dataset.duplicadosFiltroOrigen);
					if (origen === 'todos') return;
					const indicador = boton.querySelector(`[data-duplicados-conteo-origen="${origen}"]`);
					if (!indicador) return;
					const dato = conteos[origen] || null;
					const texto = textoConteoOrigenDuplicados(conteos, origen);
					indicador.textContent = texto;
					if (dato?.pendiente) pendiente = true;
					const titulo = texto === '...'
						? 'Conteo de grupos pendiente'
						: `${texto} grupos de duplicados${dato?.pendiente ? ' · actualizando' : ''}`;
					indicador.title = titulo;
					indicador.setAttribute('aria-label', titulo);
				});
				return pendiente;
			}

			function conteosOrigenDuplicadosConocidos() {
				return conteosOrigenDuplicadosActuales
					&& !conteosOrigenDuplicadosActuales.pendiente
					&& ['local', 'remoto', 'mixto'].some(origen => Number.isFinite(Number(conteosOrigenDuplicadosActuales[origen]?.grupos)));
			}

			function procesarConteosOrigenDesdeEstado(conteos) {
				if (!conteos || typeof conteos !== 'object') {
					solicitarConteosOrigenDuplicados();
					return;
				}
				if (conteos.pendiente && conteosOrigenDuplicadosConocidos()) {
					actualizarConteosOrigenDuplicados(conteos);
					if (!conteosOrigenDuplicadosReintentoPendiente) {
						conteosOrigenDuplicadosReintentoPendiente = true;
						solicitarConteosOrigenDuplicados(true);
					}
					return;
				}
				if (!conteos.pendiente) {
					conteosOrigenDuplicadosReintentoPendiente = false;
				}
				if (actualizarConteosOrigenDuplicados(conteos)) {
					solicitarConteosOrigenDuplicados();
				}
			}

			function normalizarAjustesConteosOrigenDuplicados(ajustes) {
				const normalizados = { local: 0, remoto: 0, mixto: 0 };
				if (!ajustes || typeof ajustes !== 'object') return normalizados;
				Object.keys(normalizados).forEach(origen => {
					normalizados[origen] = Math.trunc(Number(ajustes[origen] || 0));
				});
				return normalizados;
			}

			function hayAjustesConteosOrigenDuplicados(ajustes) {
				return Object.values(normalizarAjustesConteosOrigenDuplicados(ajustes)).some(valor => valor !== 0);
			}

			function combinarAjustesConteosOrigenDuplicados(base, extra) {
				const combinado = normalizarAjustesConteosOrigenDuplicados(base);
				const adicionales = normalizarAjustesConteosOrigenDuplicados(extra);
				Object.keys(combinado).forEach(origen => {
					combinado[origen] += adicionales[origen];
				});
				return combinado;
			}

			function aplicarAjustesConteosOrigenDuplicados(ajustes) {
				const normalizados = normalizarAjustesConteosOrigenDuplicados(ajustes);
				if (!hayAjustesConteosOrigenDuplicados(normalizados) || !conteosOrigenDuplicadosActuales) return;
				versionConteosOrigenDuplicados++;
				const siguientes = {
					local: { ...(conteosOrigenDuplicadosActuales.local || {}) },
					remoto: { ...(conteosOrigenDuplicadosActuales.remoto || {}) },
					mixto: { ...(conteosOrigenDuplicadosActuales.mixto || {}) },
					pendiente: false,
					actualizado: Date.now() / 1000
				};
				Object.keys(normalizados).forEach(origen => {
					const actual = Number(siguientes[origen]?.grupos || 0);
					siguientes[origen] = {
						...(siguientes[origen] || {}),
						grupos: Math.max(0, actual + normalizados[origen]),
						pendiente: false
					};
				});
				actualizarConteosOrigenDuplicados(siguientes);
			}

			async function persistirAjustesConteosOrigenDuplicados(ajustes) {
				const normalizados = normalizarAjustesConteosOrigenDuplicados(ajustes);
				if (!hayAjustesConteosOrigenDuplicados(normalizados)) return;
				try {
					const datos = await solicitarDuplicados('ajustar_conteos', { ajustes: normalizados });
					actualizarConteosOrigenDuplicados(datos?.conteos_origen || null);
				} catch (error) {
					if (mensajeDuplicados) {
						mensajeDuplicados.textContent = `No se pudieron guardar los conteos: ${error.message}`;
					}
				}
			}

			function solicitarConteosOrigenDuplicados(forzar = false) {
				const ahora = Date.now();
				const intervaloMinimo = forzar ? 15000 : 30000;
				if (
					conteosOrigenDuplicadosEnCurso ||
					(!forzar && conteosOrigenDuplicadosSolicitados) ||
					(conteosOrigenDuplicadosUltimaSolicitud > 0 && ahora - conteosOrigenDuplicadosUltimaSolicitud < intervaloMinimo)
				) return;
				conteosOrigenDuplicadosSolicitados = true;
				conteosOrigenDuplicadosUltimaSolicitud = ahora;
				const versionSolicitud = versionConteosOrigenDuplicados;
				window.setTimeout(async () => {
					conteosOrigenDuplicadosEnCurso = true;
					try {
						const datos = await solicitarDuplicados('conteos');
						const conteos = datos?.conteos_origen || null;
						if (versionSolicitud === versionConteosOrigenDuplicados) {
							actualizarConteosOrigenDuplicados(conteos);
						}
						if (conteos && !conteos.pendiente) {
							conteosOrigenDuplicadosReintentoPendiente = false;
							conteosOrigenDuplicadosSolicitados = false;
						}
					} catch (error) {
						conteosOrigenDuplicadosSolicitados = false;
						if (mensajeDuplicados) {
							mensajeDuplicados.textContent = `No se pudieron calcular los conteos: ${error.message}`;
						}
					} finally {
						conteosOrigenDuplicadosEnCurso = false;
					}
				}, 0);
			}

			function formatearCuentaRegresivaDuplicados(segundos) {
				segundos = Math.max(0, Math.ceil(Number(segundos) || 0));
				const minutos = Math.floor(segundos / 60);
				const resto = segundos % 60;
				if (minutos <= 0) return `${resto}s`;
				return `${minutos} min ${resto.toString().padStart(2, '0')}s`;
			}

			function mensajeCatalogoYandexConReanudacion(jobCatalogoYandex, fallback) {
				let mensaje = jobCatalogoYandex?.mensaje || fallback;
				const reanudarEn = Number(jobCatalogoYandex?.reanudar_en || 0);
				const restantes = reanudarEn > 0 ? reanudarEn - (Date.now() / 1000) : 0;
				if (restantes > 0) {
					mensaje = mensaje.replace(/\s*Se reanuda automáticamente en \d+ segundos\./, '');
					mensaje = `${mensaje} Reanuda en ${formatearCuentaRegresivaDuplicados(restantes)}.`;
				}
				return mensaje;
			}

			function actualizarBotonesFiltroOrigenDuplicados() {
				botonesFiltroOrigenDuplicados.forEach(boton => {
					const activo = normalizarFiltroOrigenDuplicados(boton.dataset.duplicadosFiltroOrigen) === filtroOrigenDuplicados;
					boton.classList.toggle('activo', activo);
					boton.setAttribute('aria-pressed', activo ? 'true' : 'false');
				});
			}

			function asegurarListaDuplicados() {
				if (!resultadosDuplicados) return null;
				let lista = resultadosDuplicados.querySelector('[data-duplicados-lista]');
				if (!lista) {
					lista = document.createElement('div');
					lista.className = 'duplicados-lista';
					lista.dataset.duplicadosLista = '1';
					resultadosDuplicados.prepend(lista);
				}
				return lista;
			}

			function mostrarMensajeListaDuplicados(mensaje) {
				if (!resultadosDuplicados) return;
				resultadosDuplicados.querySelectorAll('.duplicados-vacio').forEach(nodo => nodo.remove());
				const vacio = document.createElement('div');
				vacio.className = 'duplicados-vacio';
				vacio.setAttribute('role', 'status');
				vacio.textContent = mensaje;
				resultadosDuplicados.appendChild(vacio);
			}

			function quitarMensajeListaDuplicados() {
				resultadosDuplicados?.querySelectorAll('.duplicados-vacio').forEach(nodo => nodo.remove());
			}

			function totalGruposDuplicadosCargados() {
				return resultadosDuplicados?.querySelectorAll('[data-duplicado-grupo]').length || 0;
			}

			function sincronizarOffsetDuplicadosConDom() {
				offsetDuplicados = totalGruposDuplicadosCargados();
			}

			function actualizarCargaMasDuplicados(mensaje = '') {
				if (!cargaMasDuplicados) return;
				const hayGrupos = totalGruposDuplicadosCargados() > 0;
				cargaMasDuplicados.hidden = !cargandoGruposDuplicados && !hayMasDuplicados && !hayGrupos;
				if (botonCargarMasDuplicados) {
					botonCargarMasDuplicados.hidden = !hayMasDuplicados || cargandoGruposDuplicados;
					botonCargarMasDuplicados.disabled = cargandoGruposDuplicados || !hayMasDuplicados;
				}
				if (estadoCargaMasDuplicados) {
					if (mensaje) {
						estadoCargaMasDuplicados.textContent = mensaje;
					} else if (cargandoGruposDuplicados) {
						estadoCargaMasDuplicados.textContent = 'Cargando grupos...';
					} else if (hayMasDuplicados) {
						estadoCargaMasDuplicados.textContent = 'Desplázate para cargar más grupos.';
					} else if (hayGrupos) {
						estadoCargaMasDuplicados.textContent = 'Todos los grupos cargados.';
					} else {
						estadoCargaMasDuplicados.textContent = '';
					}
				}
			}

			function reiniciarListaDuplicados(mensaje = 'Cargando grupos de duplicados...') {
				if (!resultadosDuplicados) return;
				offsetDuplicados = 0;
				hayMasDuplicados = true;
				resultadosDuplicados.innerHTML = '<div class="duplicados-lista" data-duplicados-lista></div>';
				mostrarMensajeListaDuplicados(mensaje);
				actualizarCargaMasDuplicados('Cargando grupos...');
			}

			function datosDuplicadoDesdeElemento(elemento) {
				const dataset = elemento?.dataset || {};
				return {
					origen: dataset.duplicadoOrigen || '',
					origenEtiqueta: dataset.duplicadoOrigenEtiqueta || dataset.duplicadoOrigen || '',
					ruta: dataset.duplicadoRuta || '',
					nombre: dataset.duplicadoNombre || dataset.duplicadoRuta || '',
					kind: dataset.duplicadoKind || '',
					preview: dataset.duplicadoPreview || '',
					media: dataset.duplicadoMedia || dataset.duplicadoPreview || '',
					open: dataset.duplicadoOpen || '',
					actionLabel: dataset.duplicadoActionLabel || 'Descartar',
					md5: dataset.duplicadoMd5 || '',
					sha256: dataset.duplicadoSha256 || '',
					contenidoHash: dataset.duplicadoContenidoHash || '',
					perceptualHash: dataset.duplicadoPerceptualHash || '',
					tamano: dataset.duplicadoTamano || '',
					modificado: dataset.duplicadoModificado || '',
					dimensiones: dataset.duplicadoDimensiones || '',
					duracion: dataset.duplicadoDuracion || '',
					mime: dataset.duplicadoMime || ''
				};
			}

			function ocultarPanelesArticulosParaDuplicados() {
				document.querySelectorAll('main article[data-panel-id]').forEach(item => item.classList.remove('activo'));
				document.querySelectorAll('.panel-articulo[id^="pie_"]').forEach(panel => {
					panel.hidden = true;
				});
				const placeholder = document.querySelector('.panel-detalle-placeholder');
				if (placeholder) {
					placeholder.hidden = true;
				}
			}

			function htmlMetaDuplicado(etiqueta, valor) {
				if (!valor) return '';
				return `<dt>${escaparHtmlCliente(etiqueta)}</dt><dd>${escaparHtmlCliente(valor)}</dd>`;
			}

			function grupoDuplicadoTienePixelesSinMetadatos(item) {
				const razones = item?.closest?.('[data-duplicado-grupo]')?.dataset?.duplicadoRazones || '';
				return normalizarBusquedaLateral(razones).includes('pixeles identicos sin metadatos');
			}

			function htmlAvisoMetadatosDuplicado(item) {
				if (!grupoDuplicadoTienePixelesSinMetadatos(item)) return '';
				return '<p class="duplicados-metadatos-aviso">Los píxeles coinciden, pero los metadatos pueden indicar cuál archivo conserva la versión más completa o correcta.</p>';
			}

			function itemsGrupoDuplicado(itemActivo) {
				const grupo = itemActivo?.closest?.('[data-duplicado-grupo]');
				return Array.from(grupo?.querySelectorAll('[data-duplicado-item]') || []);
			}

			function anchoColumnaMetadatosDuplicados() {
				const panel = document.getElementById('panelDetalleContenido');
				const ancho = Math.round(panel?.getBoundingClientRect?.().width || 0);
				return Math.max(280, ancho || 360);
			}

			function cerrarModalMetadatosDuplicados() {
				const modal = modalMetadatosDuplicados;
				if (!modal) return;
				modal.hidden = true;
				if (Array.isArray(modal._abortControllers)) {
					modal._abortControllers.forEach(controlador => controlador.abort());
				}
				modal._abortControllers = [];
				if (typeof modal._prevBodyOverflow !== 'undefined') {
					document.body.style.overflow = modal._prevBodyOverflow;
					delete modal._prevBodyOverflow;
				}
			}

			function crearModalMetadatosDuplicados() {
				if (modalMetadatosDuplicados) return modalMetadatosDuplicados;

				modalMetadatosDuplicados = document.createElement('div');
				modalMetadatosDuplicados.id = 'modal-duplicados-metadatos';
				modalMetadatosDuplicados.className = 'modal-lote duplicados-metadatos-modal';
				modalMetadatosDuplicados.hidden = true;
				modalMetadatosDuplicados.setAttribute('role', 'dialog');
				modalMetadatosDuplicados.setAttribute('aria-modal', 'true');
				modalMetadatosDuplicados.setAttribute('aria-labelledby', 'modal-duplicados-metadatos-titulo');
				modalMetadatosDuplicados.innerHTML =
					'<div class="modal-lote-panel duplicados-metadatos-modal-panel">' +
						'<header class="duplicados-metadatos-modal-cabecera">' +
							'<div>' +
								'<h2 id="modal-duplicados-metadatos-titulo">Metadatos del grupo</h2>' +
								'<p data-duplicados-metadatos-contexto></p>' +
								'<p class="duplicados-metadatos-modal-diferencias" data-duplicados-metadatos-diferencias></p>' +
							'</div>' +
							'<button type="button" data-duplicados-metadatos-cerrar aria-label="Cerrar">Cerrar</button>' +
						'</header>' +
						'<div class="duplicados-metadatos-modal-columnas" data-duplicados-metadatos-columnas></div>' +
					'</div>';

				modalMetadatosDuplicados.addEventListener('click', async ev => {
					if (ev.target === modalMetadatosDuplicados || ev.target.closest?.('[data-duplicados-metadatos-cerrar]')) {
						cerrarModalMetadatosDuplicados();
						return;
					}

					const botonSeleccion = ev.target.closest?.('[data-duplicado-modal-seleccionar]');
					if (botonSeleccion && modalMetadatosDuplicados.contains(botonSeleccion)) {
						ev.preventDefault();
						const columna = botonSeleccion.closest('[data-duplicado-modal-columna]');
						const item = columna?._duplicadoItem;
						if (item?.isConnected) {
							alternarSeleccionDuplicado(item);
							actualizarColumnasMetadatosDuplicados();
						}
						return;
					}

					const botonDescartar = ev.target.closest?.('[data-duplicado-modal-descartar]');
					if (botonDescartar && modalMetadatosDuplicados.contains(botonDescartar)) {
						ev.preventDefault();
						await descartarDesdeModalMetadatosDuplicados(botonDescartar.closest('[data-duplicado-modal-columna]'), botonDescartar);
					}
				});

				document.addEventListener('keydown', ev => {
					if (ev.key === 'Escape' && modalMetadatosDuplicados && !modalMetadatosDuplicados.hidden) {
						cerrarModalMetadatosDuplicados();
					}
				});

				document.body.appendChild(modalMetadatosDuplicados);
				return modalMetadatosDuplicados;
			}

			function actualizarColumnasMetadatosDuplicados() {
				if (!modalMetadatosDuplicados || modalMetadatosDuplicados.hidden) return;
				modalMetadatosDuplicados.querySelectorAll('[data-duplicado-modal-columna]').forEach(columna => {
					const item = columna._duplicadoItem;
					const botonSeleccion = columna.querySelector('[data-duplicado-modal-seleccionar]');
					const botonDescartar = columna.querySelector('[data-duplicado-modal-descartar]');
					const procesado = columna.classList.contains('procesado');
					const conectado = Boolean(item?.isConnected);
					const seleccionado = conectado && item.classList.contains('seleccionado');
					if (botonSeleccion) {
						botonSeleccion.disabled = procesado || !conectado;
						botonSeleccion.setAttribute('aria-pressed', seleccionado ? 'true' : 'false');
						botonSeleccion.textContent = seleccionado ? 'Quitar selección' : 'Seleccionar para borrar';
					}
					if (botonDescartar) {
						botonDescartar.disabled = procesado || !conectado;
					}
				});
			}

			function limpiarDiferenciasMetadatosDuplicados(modal) {
				modal?.querySelectorAll('[data-duplicado-metadato-fila]').forEach(fila => {
					fila.classList.remove('duplicados-metadatos-diferente', 'duplicados-metadatos-diferente-volatil');
					fila.removeAttribute('data-duplicado-metadato-diferente');
					fila.removeAttribute('title');
				});
			}

			function compararMetadatosModalDuplicados() {
				const modal = modalMetadatosDuplicados;
				if (!modal || modal.hidden) return;
				limpiarDiferenciasMetadatosDuplicados(modal);

				const resumen = modal.querySelector('[data-duplicados-metadatos-diferencias]');
				const columnas = Array.from(modal.querySelectorAll('[data-duplicado-modal-columna]'))
					.filter(columna => columna.dataset.metadatosEstado === 'ok');
				if (columnas.length < 2) {
					if (resumen) resumen.textContent = 'Cargando metadatos para comparar...';
					return;
				}

				const porClave = new Map();
				columnas.forEach((columna, indiceColumna) => {
					columna.querySelectorAll('[data-duplicado-metadato-fila]').forEach(fila => {
						const tipo = fila.dataset.duplicadoMetadatoTipo || 'normal';
						const clave = fila.dataset.duplicadoMetadatoClave || '';
						if (!clave || tipo === 'ignorar') return;
						if (!porClave.has(clave)) {
							porClave.set(clave, {
								tipos: new Set(),
								valores: new Map(),
								filas: []
							});
						}
						const entrada = porClave.get(clave);
						entrada.tipos.add(tipo);
						entrada.valores.set(indiceColumna, fila.dataset.duplicadoMetadatoHash || '');
						entrada.filas.push(fila);
					});
				});

				let diferencias = 0;
				let diferenciasVolatiles = 0;
				porClave.forEach(entrada => {
					const valores = new Set(entrada.valores.values());
					const faltanColumnas = entrada.valores.size < columnas.length;
					if (valores.size <= 1 && !faltanColumnas) return;

					const volatil = !entrada.tipos.has('normal');
					if (volatil) {
						diferenciasVolatiles++;
					} else {
						diferencias++;
					}
					entrada.filas.forEach(fila => {
						fila.classList.add('duplicados-metadatos-diferente');
						fila.dataset.duplicadoMetadatoDiferente = volatil ? 'volatil' : 'normal';
						fila.title = volatil
							? 'Diferencia en fecha o campo volátil'
							: 'Diferencia de metadatos';
						if (volatil) {
							fila.classList.add('duplicados-metadatos-diferente-volatil');
						}
					});
				});

				if (resumen) {
					const partes = [];
					if (diferencias > 0) {
						partes.push(`${diferencias} diferencia${diferencias === 1 ? '' : 's'} importante${diferencias === 1 ? '' : 's'}`);
					}
					if (diferenciasVolatiles > 0) {
						partes.push(`${diferenciasVolatiles} fecha${diferenciasVolatiles === 1 ? '' : 's'} o campo${diferenciasVolatiles === 1 ? '' : 's'} volátil${diferenciasVolatiles === 1 ? '' : 'es'}`);
					}
					resumen.textContent = partes.length
						? `Diferencias detectadas: ${partes.join(' · ')}.`
						: 'Sin diferencias relevantes entre los metadatos cargados.';
				}
			}

			function htmlResumenColumnaMetadatos(datos) {
				const resumen = [datos.tamano, datos.dimensiones, datos.duracion, datos.modificado]
					.filter(Boolean)
					.join(' · ');
				return resumen ? `<small>${escaparHtmlCliente(resumen)}</small>` : '';
			}

			function htmlColumnaMetadatosDuplicado(item, activo) {
				const datos = datosDuplicadoDesdeElemento(item);
				const seleccionado = item.classList.contains('seleccionado');
				return (
					`<article class="duplicados-metadatos-columna${activo ? ' activa' : ''}" data-duplicado-modal-columna>` +
						'<div class="duplicados-metadatos-columna-acciones">' +
							`<button type="button" data-duplicado-modal-descartar>${escaparHtmlCliente(datos.actionLabel || 'Descartar')}</button>` +
							`<button type="button" data-duplicado-modal-seleccionar aria-pressed="${seleccionado ? 'true' : 'false'}">${seleccionado ? 'Quitar selección' : 'Seleccionar para borrar'}</button>` +
						'</div>' +
						'<div class="duplicados-metadatos-columna-identidad">' +
							`<span class="duplicados-origen duplicados-origen-${escaparHtmlCliente(datos.origen)}">${escaparHtmlCliente(datos.origenEtiqueta)}</span>` +
							`<strong title="${escaparHtmlCliente(datos.nombre)}">${escaparHtmlCliente(datos.nombre)}</strong>` +
							`<code title="${escaparHtmlCliente(datos.ruta)}">${escaparHtmlCliente(datos.ruta)}</code>` +
							htmlResumenColumnaMetadatos(datos) +
							'<output data-duplicado-modal-estado aria-live="polite"></output>' +
						'</div>' +
						'<div class="duplicados-metadatos-columna-cuerpo" data-duplicado-modal-cuerpo>' +
							'<p class="panel-cargando">Cargando metadatos...</p>' +
						'</div>' +
					'</article>'
				);
			}

			async function solicitarMetadatosDuplicado(datos, signal) {
				const respuesta = await fetch('index.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json; charset=UTF-8' },
					signal,
					body: JSON.stringify({
						duplicados_accion: 'metadatos',
						origen: datos.origen,
						archivo: datos.ruta
					})
				});
				const payload = await respuesta.json().catch(() => null);
				if (!respuesta.ok || !payload?.ok) {
					throw new Error(payload?.error || 'No se pudieron cargar los metadatos.');
				}
				return payload;
			}

			async function cargarMetadatosColumnaDuplicado(columna, cargaId, signal) {
				const cuerpo = columna?.querySelector('[data-duplicado-modal-cuerpo]');
				const datos = columna?._duplicadoDatos;
				if (!cuerpo || !datos) return;
				try {
					const payload = await solicitarMetadatosDuplicado(datos, signal);
					if (signal?.aborted || String(modalMetadatosDuplicados?.dataset.cargaId || '') !== String(cargaId)) return;
					cuerpo.innerHTML = payload.html || '<p class="duplicados-metadatos-vacio">No hay metadatos disponibles.</p>';
					columna.dataset.metadatosEstado = 'ok';
				} catch (err) {
					if (err?.name === 'AbortError') return;
					if (signal?.aborted || String(modalMetadatosDuplicados?.dataset.cargaId || '') !== String(cargaId)) return;
					cuerpo.innerHTML = '<p class="respuesta_error">' + escaparHtmlCliente(err.message || 'No se pudieron cargar los metadatos.') + '</p>';
					columna.dataset.metadatosEstado = 'error';
				} finally {
					if (signal?.aborted || String(modalMetadatosDuplicados?.dataset.cargaId || '') !== String(cargaId)) return;
					compararMetadatosModalDuplicados();
				}
			}

			function cargarColumnasMetadatosDuplicados(columnas, cargaId) {
				const modal = modalMetadatosDuplicados;
				if (!modal) return;
				modal._abortControllers = [];
				let indice = 0;
				const concurrencia = Math.min(3, columnas.length);
				const trabajador = async () => {
					while (indice < columnas.length && !modal.hidden) {
						const columna = columnas[indice++];
						const controlador = new AbortController();
						modal._abortControllers.push(controlador);
						await cargarMetadatosColumnaDuplicado(columna, cargaId, controlador.signal);
					}
				};
				for (let i = 0; i < concurrencia; i++) {
					trabajador();
				}
			}

			function abrirModalMetadatosDuplicados(itemActivo) {
				if (!itemActivo) return;
				const grupo = itemActivo.closest?.('[data-duplicado-grupo]');
				const items = itemsGrupoDuplicado(itemActivo);
				if (!grupo || !items.length) return;

				const modal = crearModalMetadatosDuplicados();
				const cargaId = String(++cargaModalMetadatosDuplicados);
				modal.dataset.cargaId = cargaId;
				cerrarModalMetadatosDuplicados();
				modal.hidden = false;
				modal._prevBodyOverflow = document.body.style.overflow || '';
				document.body.style.overflow = 'hidden';
				modal.style.setProperty('--duplicados-metadatos-columna', `${anchoColumnaMetadatosDuplicados()}px`);

				const contexto = modal.querySelector('[data-duplicados-metadatos-contexto]');
				const columnas = modal.querySelector('[data-duplicados-metadatos-columnas]');
				const razones = grupo.dataset.duplicadoRazones || '';
				if (contexto) {
					contexto.textContent = `${items.length} archivos${razones ? ' · ' + razones : ''}`;
				}
				const resumenDiferencias = modal.querySelector('[data-duplicados-metadatos-diferencias]');
				if (resumenDiferencias) {
					resumenDiferencias.textContent = 'Cargando metadatos para comparar...';
				}
				if (!columnas) return;
				columnas.innerHTML = items.map(item => htmlColumnaMetadatosDuplicado(item, item === itemActivo)).join('');

				const columnasDom = Array.from(columnas.querySelectorAll('[data-duplicado-modal-columna]'));
				columnasDom.forEach((columna, indiceColumna) => {
					const item = items[indiceColumna];
					columna._duplicadoItem = item;
					columna._duplicadoDatos = datosDuplicadoDesdeElemento(item);
				});
				actualizarColumnasMetadatosDuplicados();
				cargarColumnasMetadatosDuplicados(columnasDom, cargaId);

				requestAnimationFrame(() => {
					const activa = columnas.querySelector('.duplicados-metadatos-columna.activa');
					activa?.scrollIntoView({ block: 'nearest', inline: 'center' });
					modal.querySelector('[data-duplicados-metadatos-cerrar]')?.focus();
				});
			}

			async function descartarDesdeModalMetadatosDuplicados(columna, boton) {
				const item = columna?._duplicadoItem;
				const datos = columna?._duplicadoDatos || (item ? datosDuplicadoDesdeElemento(item) : null);
				const estado = columna?.querySelector('[data-duplicado-modal-estado]');
				const grupo = item?.closest?.('[data-duplicado-grupo]') || null;
				if (!columna || !item?.isConnected || !datos) {
					if (estado) estado.textContent = 'Este archivo ya no está en el grupo.';
					actualizarColumnasMetadatosDuplicados();
					return;
				}
				if (!confirmarAccionDuplicado(datos)) return;

				columna.classList.add('procesando');
				columna.querySelectorAll('button').forEach(control => {
					control.disabled = true;
				});
				if (boton) boton.setAttribute('aria-busy', 'true');
				if (estado) {
					estado.className = '';
					estado.textContent = 'Procesando...';
				}
				mostrarCargaNavegacion(datos.actionLabel || 'Procesando');

				try {
					await ejecutarAccionDuplicado(datos);
					const ajustesConteos = eliminarItemDuplicadoDeVista(item);
					const restantesGrupo = grupo?.isConnected
						? grupo.querySelectorAll('[data-duplicado-item]').length
						: 0;
					const grupoResuelto = !grupo?.isConnected || restantesGrupo <= 1;
					aplicarAjustesConteosOrigenDuplicados(ajustesConteos);
					columna.classList.remove('procesando');
					columna.classList.add('procesado');
					if (estado) {
						estado.className = 'ok';
						estado.textContent = `${datos.actionLabel}: listo.`;
					}
					if (grupoResuelto) {
						cerrarModalMetadatosDuplicados();
						limpiarDetalleDuplicado();
					} else if (itemDuplicadoActivo === item) {
						mostrarResultadoAccionDuplicado(`${datos.actionLabel}: ${datos.ruta}`);
					}
					await persistirAjustesConteosOrigenDuplicados(ajustesConteos);
					try {
						const estadoDuplicados = await solicitarDuplicados('estado');
						pintarEstadoDuplicados(estadoDuplicados);
					} catch (err) {
						if (mensajeDuplicados) mensajeDuplicados.textContent = `No se pudo actualizar el estado: ${err.message}`;
					}
				} catch (err) {
					columna.classList.remove('procesando');
					if (estado) {
						estado.className = 'error';
						estado.textContent = err.message || 'No se pudo procesar el archivo.';
					}
					columna.querySelectorAll('button').forEach(control => {
						control.disabled = false;
					});
				} finally {
					if (boton) boton.removeAttribute('aria-busy');
					ocultarCargaNavegacion();
					actualizarColumnasMetadatosDuplicados();
				}
			}

			function dimensionesDuplicadoDesdeTexto(valor) {
				const match = String(valor || '').match(/(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)/i);
				if (!match) return null;
				const ancho = Number(match[1].replace(',', '.'));
				const alto = Number(match[2].replace(',', '.'));
				if (!Number.isFinite(ancho) || !Number.isFinite(alto) || ancho <= 0 || alto <= 0) return null;
				return {
					ancho: Math.round(ancho),
					alto: Math.round(alto)
				};
			}

			function estiloAspectoDuplicado(datos) {
				const dimensiones = dimensionesDuplicadoDesdeTexto(datos.dimensiones);
				if (!dimensiones) return '';
				return ` style="--duplicado-aspecto:${dimensiones.ancho} / ${dimensiones.alto}"`;
			}

			function aplicarAspectoPreviewDuplicado(panel) {
				const contenedor = panel?.querySelector('.duplicados-preview-media');
				const media = contenedor?.querySelector('img, video');
				if (!contenedor || !media) return;

				function aplicar(ancho, alto) {
					if (!Number.isFinite(ancho) || !Number.isFinite(alto) || ancho <= 0 || alto <= 0) return;
					contenedor.style.setProperty('--duplicado-aspecto', `${Math.round(ancho)} / ${Math.round(alto)}`);
				}

				if (media.tagName === 'IMG') {
					if (media.naturalWidth && media.naturalHeight) {
						aplicar(media.naturalWidth, media.naturalHeight);
					} else {
						media.addEventListener('load', () => aplicar(media.naturalWidth, media.naturalHeight), { once: true });
					}
					return;
				}

				if (media.tagName === 'VIDEO') {
					if (media.videoWidth && media.videoHeight) {
						aplicar(media.videoWidth, media.videoHeight);
					} else {
						media.addEventListener('loadedmetadata', () => aplicar(media.videoWidth, media.videoHeight), { once: true });
					}
				}
			}

			function elementosLightboxGrupoDuplicado(itemActivo) {
				const grupo = itemActivo?.closest?.('[data-duplicado-grupo]');
				const items = Array.from(grupo?.querySelectorAll('[data-duplicado-item]') || []);
				return items
					.map((item, index) => {
						const datos = datosDuplicadoDesdeElemento(item);
						const href = datos.media || datos.preview || '';
						if (!href || !['image', 'video'].includes(datos.kind)) return null;
						return {
							href,
							type: datos.kind,
							title: datos.nombre,
							source: item,
							index
						};
					})
					.filter(Boolean);
			}

			function indiceLightboxGrupoDuplicado(lista, itemActivo) {
				const indice = lista.findIndex(entrada => entrada.source === itemActivo);
				return indice >= 0 ? indice : 0;
			}

			function abrirLightboxDuplicado(itemActivo) {
				if (!itemActivo || typeof window.abrirLightboxPersonalizado !== 'function') return;
				const lista = elementosLightboxGrupoDuplicado(itemActivo);
				if (!lista.length) return;
				window.abrirLightboxPersonalizado(lista, indiceLightboxGrupoDuplicado(lista, itemActivo));
			}

			function htmlVistaPreviaDuplicado(datos) {
				const src = datos.preview || datos.media || '';
				const media = datos.media || src;
				const estiloAspecto = estiloAspectoDuplicado(datos);
				if (datos.kind === 'image' && src) {
					return `<figure class="duplicados-preview-media" data-duplicado-preview-lightbox tabindex="0" role="button" aria-label="Abrir ${escaparHtmlCliente(datos.nombre)} en lightbox"${estiloAspecto}>` +
						`<img src="${escaparHtmlCliente(src)}" alt="${escaparHtmlCliente(datos.nombre)}" loading="lazy">` +
						'</figure>';
				}
				if (datos.kind === 'video' && src) {
					return `<figure class="duplicados-preview-media" data-duplicado-preview-lightbox tabindex="0" role="button" aria-label="Abrir ${escaparHtmlCliente(datos.nombre)} en lightbox"${estiloAspecto}>` +
						`<video src="${escaparHtmlCliente(src)}" muted playsinline preload="metadata"></video>` +
						'</figure>';
				}

				return '<div class="duplicados-preview-sin-media">Vista previa no disponible.</div>';
			}

			function mostrarResultadoAccionDuplicado(mensaje, error = false) {
				const panel = document.getElementById('panelDetalleContenido');
				if (!panel) return;
				ocultarPanelesArticulosParaDuplicados();
				document.querySelectorAll('.duplicados-item.activo').forEach(item => item.classList.remove('activo'));
				itemDuplicadoActivo = null;
				panel.innerHTML =
					'<section class="duplicados-preview-panel duplicados-preview-resultado">' +
					`<p class="${error ? 'respuesta_error' : 'duplicados-accion-ok'}">${escaparHtmlCliente(mensaje)}</p>` +
					'</section>';
			}

			function limpiarDetalleDuplicado() {
				const panel = document.getElementById('panelDetalleContenido');
				ocultarPanelesArticulosParaDuplicados();
				document.querySelectorAll('.duplicados-item.activo').forEach(item => item.classList.remove('activo'));
				itemDuplicadoActivo = null;
				if (panel) {
					panel.replaceChildren();
				}
				const placeholder = document.querySelector('.panel-detalle-placeholder');
				if (placeholder) {
					placeholder.hidden = false;
					placeholder.innerHTML =
						'<div class="panel-detalle-mensaje panel-detalle-mensaje-info" role="status" aria-live="polite">' +
							'<span class="panel-detalle-mensaje-icono" aria-hidden="true">i</span>' +
							'<div class="panel-detalle-mensaje-cuerpo">Selecciona un archivo para ver su información</div>' +
						'</div>';
				}
			}

			function mostrarEstadoDetalleDuplicado(mensaje, error = false) {
				const estado = document.querySelector('[data-duplicado-panel-estado]');
				if (!estado) return;
				estado.classList.toggle('error', error);
				estado.textContent = mensaje;
			}

			function tipoOrigenGrupoDuplicados(grupo) {
				const origenes = new Set(
					Array.from(grupo?.querySelectorAll('[data-duplicado-item]') || [])
						.map(item => item.dataset.duplicadoOrigen || '')
						.filter(Boolean)
				);
				const tieneLocal = origenes.has('local');
				const tieneYandex = origenes.has('yandex');
				if (tieneLocal && tieneYandex) return 'mixto';
				if (tieneYandex) return 'remoto';
				return 'local';
			}

			function actualizarTipoGrupoDuplicados(grupo, tipo) {
				if (!grupo || !tipo) return;
				['local', 'remoto', 'mixto'].forEach(origen => {
					grupo.classList.toggle(`duplicados-grupo-${origen}`, origen === tipo);
				});
				grupo.classList.toggle('duplicados-grupo-cruzado', tipo === 'mixto');
				grupo.dataset.duplicadoGrupoTipo = tipo;
			}

			function ajustesConteoPorCambioGrupoDuplicados(tipoAntes, tipoDespues) {
				const ajustes = { local: 0, remoto: 0, mixto: 0 };
				tipoAntes = normalizarFiltroOrigenDuplicados(tipoAntes);
				tipoDespues = normalizarFiltroOrigenDuplicados(tipoDespues);
				if (tipoAntes !== 'todos' && tipoAntes !== tipoDespues) ajustes[tipoAntes] -= 1;
				if (tipoDespues !== 'todos' && tipoAntes !== tipoDespues) ajustes[tipoDespues] += 1;
				return ajustes;
			}

			function actualizarTituloGrupoDuplicados(grupo) {
				if (!grupo) return;
				const titulo = grupo.querySelector('.duplicados-grupo-titulo');
				const items = grupo.querySelectorAll('[data-duplicado-item]');
				if (!titulo) return;
				const tipo = grupo.dataset.duplicadoGrupoTipo || tipoOrigenGrupoDuplicados(grupo);
				const cruzado = tipo === 'mixto';
				titulo.textContent = `${items.length} archivos${cruzado ? ' · Local/Yandex' : ''}`;
			}

			function eliminarItemDuplicadoDeVista(item) {
				if (!item) return { local: 0, remoto: 0, mixto: 0 };
				const grupo = item.closest('[data-duplicado-grupo]');
				if (grupo && !grupo.isConnected) {
					item.remove();
					return { local: 0, remoto: 0, mixto: 0 };
				}
				const tipoAntes = grupo
					? normalizarFiltroOrigenDuplicados(grupo.dataset.duplicadoGrupoTipo || tipoOrigenGrupoDuplicados(grupo))
					: 'todos';
				item.remove();
				let tipoDespues = tipoAntes;
				if (grupo) {
					const restantes = grupo.querySelectorAll('[data-duplicado-item]');
					if (restantes.length < 2) {
						grupo.remove();
						tipoDespues = 'todos';
					} else {
						tipoDespues = tipoOrigenGrupoDuplicados(grupo);
						actualizarTipoGrupoDuplicados(grupo, tipoDespues);
						actualizarTituloGrupoDuplicados(grupo);
						actualizarSeleccionGrupoDuplicados(grupo);
					}
				}
				sincronizarOffsetDuplicadosConDom();
				if (!totalGruposDuplicadosCargados() && !hayMasDuplicados) {
					mostrarMensajeListaDuplicados(mensajeVacioDuplicados());
				}
				actualizarCargaMasDuplicados();
				return ajustesConteoPorCambioGrupoDuplicados(tipoAntes, tipoDespues);
			}

			function mostrarDetalleDuplicado(item) {
				if (!item) return;
				const panel = document.getElementById('panelDetalleContenido');
				if (!panel) return;

				const datos = datosDuplicadoDesdeElemento(item);
				ocultarPanelesArticulosParaDuplicados();
				document.querySelectorAll('.duplicados-item.activo').forEach(actual => actual.classList.toggle('activo', actual === item));
				item.classList.add('activo');
				itemDuplicadoActivo = item;

				const abrir = datos.open
					? `<a href="${escaparHtmlCliente(datos.open)}" target="_blank" rel="noopener noreferrer">Abrir</a>`
					: '';
				panel.innerHTML =
					'<section class="duplicados-preview-panel">' +
						'<div class="duplicados-preview-encabezado">' +
							`<span class="duplicados-origen duplicados-origen-${escaparHtmlCliente(datos.origen)}">${escaparHtmlCliente(datos.origenEtiqueta)}</span>` +
						'</div>' +
						htmlVistaPreviaDuplicado(datos) +
						'<div class="duplicados-preview-identidad">' +
							`<h2>${escaparHtmlCliente(datos.nombre)}</h2>` +
							`<code>${escaparHtmlCliente(datos.ruta)}</code>` +
						'</div>' +
						'<div class="duplicados-detalle-acciones">' +
							`<button type="button" data-duplicado-panel-accion>${escaparHtmlCliente(datos.actionLabel)}</button>` +
							'<button type="button" data-duplicado-metadatos>Ver metadatos</button>' +
							abrir +
						'</div>' +
						htmlAvisoMetadatosDuplicado(item) +
						'<dl class="duplicados-preview-metadatos">' +
							htmlMetaDuplicado('Tamaño', datos.tamano) +
							htmlMetaDuplicado('Dimensiones', datos.dimensiones) +
							htmlMetaDuplicado('Duración', datos.duracion) +
							htmlMetaDuplicado('Modificado', datos.modificado) +
							htmlMetaDuplicado('MIME', datos.mime) +
							htmlMetaDuplicado('MD5', datos.md5) +
							htmlMetaDuplicado('SHA-256', datos.sha256) +
							htmlMetaDuplicado('Píxeles', datos.contenidoHash) +
							htmlMetaDuplicado('dHash', datos.perceptualHash) +
						'</dl>' +
						'<p class="duplicados-accion-estado" data-duplicado-panel-estado aria-live="polite"></p>' +
					'</section>';

				const botonAccion = panel.querySelector('[data-duplicado-panel-accion]');
				if (botonAccion) {
					botonAccion._duplicadoDatos = datos;
				}
				const botonMetadatos = panel.querySelector('[data-duplicado-metadatos]');
				if (botonMetadatos) {
					botonMetadatos._duplicadoDatos = datos;
					botonMetadatos._duplicadoItem = item;
				}
				aplicarAspectoPreviewDuplicado(panel);
				const previewLightbox = panel.querySelector('[data-duplicado-preview-lightbox]');
				if (previewLightbox) {
					previewLightbox.addEventListener('click', () => abrirLightboxDuplicado(item));
					previewLightbox.addEventListener('keydown', (ev) => {
						if (ev.key !== 'Enter' && ev.key !== ' ') return;
						ev.preventDefault();
						abrirLightboxDuplicado(item);
					});
				}
			}

			function actualizarSeleccionGrupoDuplicados(grupo) {
				if (!grupo) return;
				const seleccionados = grupo.querySelectorAll('[data-duplicado-item].seleccionado').length;
				const botonGrupo = grupo.querySelector('[data-duplicado-descartar-grupo]');
				const resumen = grupo.querySelector('[data-duplicado-seleccion-resumen]');
				actualizarAccionesOrigenGrupoDuplicados(grupo);
				if (botonGrupo) {
					botonGrupo.disabled = seleccionados === 0;
				}
				if (resumen) {
					resumen.textContent = seleccionados === 1 ? '1 seleccionado' : `${seleccionados} seleccionados`;
				}
			}

			function itemsGrupoPorOrigenDuplicados(grupo, origen) {
				const objetivo = origen === 'remoto' ? 'yandex' : origen;
				return Array.from(grupo?.querySelectorAll('[data-duplicado-item]') || [])
					.filter(item => (item.dataset.duplicadoOrigen || '') === objetivo);
			}

			function actualizarAccionesOrigenGrupoDuplicados(grupo) {
				if (!grupo) return;
				const tipo = grupo.dataset.duplicadoGrupoTipo || tipoOrigenGrupoDuplicados(grupo);
				grupo.querySelectorAll('[data-duplicado-descartar-origen]').forEach(boton => {
					const origen = boton.dataset.duplicadoDescartarOrigen || '';
					const aplicable = tipo === 'mixto' && itemsGrupoPorOrigenDuplicados(grupo, origen).length > 0;
					boton.hidden = !aplicable;
					if (!aplicable) {
						boton.disabled = true;
					} else if (!boton.hasAttribute('aria-busy')) {
						boton.disabled = false;
					}
				});
			}

			function alternarSeleccionDuplicado(item) {
				if (!item) return;
				const seleccionado = !item.classList.contains('seleccionado');
				item.classList.toggle('seleccionado', seleccionado);
				const boton = item.querySelector('[data-duplicado-seleccionar]');
				if (boton) {
					boton.setAttribute('aria-pressed', seleccionado ? 'true' : 'false');
					boton.textContent = seleccionado ? 'Quitar selección' : 'Seleccionar para borrar';
				}
				actualizarSeleccionGrupoDuplicados(item.closest('[data-duplicado-grupo]'));
			}

			async function ejecutarAccionDuplicado(datos) {
				if (!datos.ruta) {
					throw new Error('No hay ruta para procesar.');
				}
				if (datos.origen === 'local') {
					const respuesta = await fetch('index.php', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json; charset=UTF-8' },
						body: JSON.stringify({ borrar: datos.ruta })
					});
					const texto = await respuesta.text();
					const primeraLinea = (texto.split(/\r?\n/)[0] || '').trim();
					if (!respuesta.ok || primeraLinea !== '1') {
						throw new Error('No se pudo descartar el archivo local.');
					}
					return;
				}
				if (datos.origen === 'yandex') {
					const respuesta = await fetch('yandex_trash.php', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json; charset=UTF-8' },
						body: JSON.stringify({ path: datos.ruta })
					});
					const payload = await respuesta.json().catch(() => null);
					if (!respuesta.ok || !payload?.ok) {
						throw new Error(payload?.error || 'No se pudo enviar a la papelera de Yandex Disk.');
					}
					return;
				}

				throw new Error('Origen de archivo no soportado.');
			}

			function confirmarAccionDuplicado(datos) {
				const accion = datos.origen === 'yandex' ? 'Enviar a la papelera de Yandex Disk' : 'Descartar el archivo local';
				return confirm(`${accion}?\n${datos.ruta}`);
			}

			async function refrescarDuplicadosTrasAccion(mensaje, item = itemDuplicadoActivo) {
				const ajustesConteos = eliminarItemDuplicadoDeVista(item);
				aplicarAjustesConteosOrigenDuplicados(ajustesConteos);
				await persistirAjustesConteosOrigenDuplicados(ajustesConteos);
				try {
					const datos = await solicitarDuplicados('estado');
					pintarEstadoDuplicados(datos);
				} catch (err) {
					if (mensajeDuplicados) mensajeDuplicados.textContent = `No se pudo actualizar el estado: ${err.message}`;
				}
				mostrarResultadoAccionDuplicado(mensaje);
			}

			async function procesarDuplicadoInteractivo(datos, boton, item = itemDuplicadoActivo) {
				if (!confirmarAccionDuplicado(datos)) return;

				if (boton) {
					boton.disabled = true;
					boton.setAttribute('aria-busy', 'true');
				}
				mostrarCargaNavegacion(datos.actionLabel || 'Procesando');

				try {
					await ejecutarAccionDuplicado(datos);
					await refrescarDuplicadosTrasAccion(`${datos.actionLabel}: ${datos.ruta}`, item);
				} catch (err) {
					mostrarEstadoDetalleDuplicado(err.message || 'No se pudo procesar el archivo.', true);
					if (boton) {
						boton.disabled = false;
						boton.removeAttribute('aria-busy');
					}
				} finally {
					ocultarCargaNavegacion();
				}
			}

			function itemsLocalesMediaGrupoDuplicados(grupo) {
				return Array.from(grupo?.querySelectorAll('[data-duplicado-item]') || [])
					.filter(item => {
						const datos = datosDuplicadoDesdeElemento(item);
						return datos.origen === 'local' && (datos.kind === 'image' || datos.kind === 'video');
					});
			}

			function normalizarRutaDuplicadoCliente(ruta) {
				return String(ruta || '').replace(/\\/g, '/').replace(/\/+$/g, '');
			}

			function rutaDuplicadoEstaEnListas(datos) {
				const base = normalizarRutaDuplicadoCliente(vistaDuplicados?.dataset?.duplicadosBase || '/Users/destrella/Sites/dam');
				const raizListas = `${base}/listas`;
				const ruta = normalizarRutaDuplicadoCliente(datos.ruta);
				return ruta === raizListas || ruta.startsWith(`${raizListas}/`);
			}

			function nombreDuplicadoSinBeforeHighres(nombre) {
				return String(nombre || '').split('-before-highres-fix').join('');
			}

			function itemsReglaMantenerListas(grupo) {
				const items = itemsLocalesMediaGrupoDuplicados(grupo);
				const conDatos = items.map(item => ({ item, datos: datosDuplicadoDesdeElemento(item) }));
				if (!conDatos.some(entrada => rutaDuplicadoEstaEnListas(entrada.datos))) return [];
				return conDatos
					.filter(entrada => !rutaDuplicadoEstaEnListas(entrada.datos))
					.map(entrada => entrada.item);
			}

			function itemsReglaBeforeHighres(grupo) {
				const conDatos = itemsLocalesMediaGrupoDuplicados(grupo)
					.map(item => ({ item, datos: datosDuplicadoDesdeElemento(item) }));
				const nombres = new Set(conDatos.map(entrada => entrada.datos.nombre).filter(Boolean));
				return conDatos
					.filter(entrada => (
						entrada.datos.nombre.includes('-before-highres-fix') &&
						nombres.has(nombreDuplicadoSinBeforeHighres(entrada.datos.nombre))
					))
					.map(entrada => entrada.item);
			}

			function itemsGrupoDesdeRutasDuplicados(grupo, rutas) {
				const rutasNormalizadas = new Set((rutas || []).map(normalizarRutaDuplicadoCliente));
				return itemsLocalesMediaGrupoDuplicados(grupo)
					.filter(item => rutasNormalizadas.has(normalizarRutaDuplicadoCliente(datosDuplicadoDesdeElemento(item).ruta)));
			}

			async function solicitarReglaFechaDuplicados(grupo, modo) {
				const rutas = itemsLocalesMediaGrupoDuplicados(grupo)
					.map(item => datosDuplicadoDesdeElemento(item).ruta)
					.filter(Boolean);
				const respuesta = await fetch('index.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json; charset=UTF-8' },
					body: JSON.stringify({
						duplicados_accion: 'regla_fecha',
						modo,
						rutas
					})
				});
				const payload = await respuesta.json().catch(() => null);
				if (!respuesta.ok || !payload?.ok) {
					const error = new Error(payload?.error || 'No se pudo validar la regla por fecha.');
					error.payload = payload;
					throw error;
				}
				return payload;
			}

			async function limpiarRutasAusentesGrupoDuplicados(grupo, rutas) {
				const items = itemsGrupoDesdeRutasDuplicados(grupo, rutas || []);
				if (!items.length) return 0;

				let ajustesConteos = { local: 0, remoto: 0, mixto: 0 };
				items.forEach(item => {
					ajustesConteos = combinarAjustesConteosOrigenDuplicados(
						ajustesConteos,
						eliminarItemDuplicadoDeVista(item)
					);
				});
				aplicarAjustesConteosOrigenDuplicados(ajustesConteos);
				await persistirAjustesConteosOrigenDuplicados(ajustesConteos);
				try {
					const datos = await solicitarDuplicados('estado');
					pintarEstadoDuplicados(datos);
				} catch (err) {
					if (mensajeDuplicados) mensajeDuplicados.textContent = `No se pudo actualizar el estado: ${err.message}`;
				}

				return items.length;
			}

			function mensajeConfirmacionItemsDuplicados(items, encabezado) {
				const vista = items
					.slice(0, 6)
					.map(item => `- ${datosDuplicadoDesdeElemento(item).ruta}`)
					.join('\n');
				const faltantes = Math.max(0, items.length - 6);
				return `${encabezado}\n\n${vista}${faltantes ? `\n... y ${faltantes} más` : ''}`;
			}

			async function procesarItemsDuplicados(grupo, items, boton, encabezadoConfirmacion, mensajeCarga = 'Procesando duplicados') {
				items = Array.from(new Set(items || [])).filter(item => item?.isConnected);
				if (!items.length) {
					mostrarResultadoAccionDuplicado('No hay archivos aplicables para esta regla.', true);
					return;
				}
				if (!confirm(mensajeConfirmacionItemsDuplicados(items, encabezadoConfirmacion))) return;

				if (boton) {
					boton.disabled = true;
					boton.setAttribute('aria-busy', 'true');
				}
				mostrarCargaNavegacion(mensajeCarga);

				const errores = [];
				let procesados = 0;
				let huboCambios = false;
				let ajustesConteos = { local: 0, remoto: 0, mixto: 0 };
				for (const item of items) {
					const datos = datosDuplicadoDesdeElemento(item);
					item.classList.add('procesando');
					try {
						await ejecutarAccionDuplicado(datos);
						procesados++;
						huboCambios = true;
						item.classList.add('procesado');
					} catch (err) {
						errores.push(`${datos.nombre || datos.ruta}: ${err.message || 'No se pudo procesar.'}`);
						item.classList.add('error');
					} finally {
						item.classList.remove('procesando');
					}
				}

				items
					.filter(item => item.classList.contains('procesado'))
					.forEach(item => {
						ajustesConteos = combinarAjustesConteosOrigenDuplicados(
							ajustesConteos,
							eliminarItemDuplicadoDeVista(item)
						);
					});
				if (huboCambios) {
					aplicarAjustesConteosOrigenDuplicados(ajustesConteos);
					await persistirAjustesConteosOrigenDuplicados(ajustesConteos);
				}
				try {
					const datos = await solicitarDuplicados('estado');
					pintarEstadoDuplicados(datos);
				} catch (err) {
					errores.push(`Actualización de la lista: ${err.message || 'falló'}`);
				}

				if (errores.length) {
					mostrarResultadoAccionDuplicado(`${procesados} procesados. Errores: ${errores.slice(0, 3).join(' | ')}`, true);
				} else {
					mostrarResultadoAccionDuplicado(`${procesados} archivos procesados.`);
				}
				ocultarCargaNavegacion();
				if (boton) {
					boton.removeAttribute('aria-busy');
					if (grupo?.isConnected) {
						boton.disabled = false;
						actualizarSeleccionGrupoDuplicados(grupo);
					}
				}
			}

			async function procesarSeleccionGrupoDuplicados(grupo, boton) {
				const items = Array.from(grupo?.querySelectorAll('[data-duplicado-item].seleccionado') || []);
				await procesarItemsDuplicados(
					grupo,
					items,
					boton,
					`¿Procesar ${items.length} archivo${items.length === 1 ? '' : 's'} seleccionado${items.length === 1 ? '' : 's'}?`
				);
			}

			async function procesarOrigenGrupoDuplicados(grupo, boton) {
				const origen = boton?.dataset?.duplicadoDescartarOrigen || '';
				const items = itemsGrupoPorOrigenDuplicados(grupo, origen);
				const esRemoto = origen === 'yandex' || origen === 'remoto';
				await procesarItemsDuplicados(
					grupo,
					items,
					boton,
					esRemoto
						? 'Se enviarán a la papelera de Yandex.Disk los archivos remotos de este grupo exacto:'
						: 'Se descartarán los archivos locales de este grupo exacto:',
					esRemoto ? 'Enviando remotos a papelera' : 'Descartando locales'
				);
			}

				async function procesarReglaGrupoDuplicados(grupo, boton) {
					const regla = boton?.dataset?.duplicadoRegla || '';
					if (!grupo || !regla) return;

				try {
					if (regla === 'mantener-listas') {
						await procesarItemsDuplicados(
							grupo,
							itemsReglaMantenerListas(grupo),
							boton,
							'Se mantendrán los archivos dentro de listas y se descartarán estos archivos fuera de listas:',
							'Aplicando regla de listas'
						);
						return;
					}
					if (regla === 'descartar-before-highres') {
						await procesarItemsDuplicados(
							grupo,
							itemsReglaBeforeHighres(grupo),
							boton,
							'Se descartarán las versiones before-highres-fix que ya tienen una versión equivalente:',
							'Aplicando regla before-highres'
						);
						return;
					}
					if (regla === 'mantener-mas-antiguo' || regla === 'mantener-mas-nuevo') {
						if (boton) {
							boton.disabled = true;
							boton.setAttribute('aria-busy', 'true');
						}
						mostrarCargaNavegacion('Validando metadatos');
						const modo = regla === 'mantener-mas-nuevo' ? 'nuevo' : 'antiguo';
						const payload = await solicitarReglaFechaDuplicados(grupo, modo);
						const ausentesLimpiados = await limpiarRutasAusentesGrupoDuplicados(grupo, payload.rutas_ausentes || []);
						if (!grupo?.isConnected) {
							mostrarResultadoAccionDuplicado(
								ausentesLimpiados > 0
									? 'Se limpiaron rutas ausentes y el grupo ya no tiene duplicados suficientes.'
									: 'El grupo ya no está disponible.',
								ausentesLimpiados === 0
							);
							return;
						}
						const items = itemsGrupoDesdeRutasDuplicados(grupo, payload.rutas_descartar || []);
						ocultarCargaNavegacion();
						if (boton) {
							boton.disabled = false;
							boton.removeAttribute('aria-busy');
						}
						let encabezado = payload.mensaje || `Se mantendrá el archivo más ${modo === 'nuevo' ? 'nuevo' : 'antiguo'} y se descartará el resto:`;
						if (payload.advertencia) {
							encabezado += `\n\n${payload.advertencia}`;
						}
						if (ausentesLimpiados > 0) {
							encabezado += `\n\nSe limpiaron ${ausentesLimpiados} ruta${ausentesLimpiados === 1 ? '' : 's'} que ya no existían en disco.`;
						}
						await procesarItemsDuplicados(
							grupo,
							items,
							boton,
							encabezado,
							`Manteniendo el archivo más ${modo === 'nuevo' ? 'nuevo' : 'antiguo'}`
						);
						return;
					}
				} catch (err) {
					ocultarCargaNavegacion();
					const ausentes = err?.payload?.rutas_ausentes || [];
					if (ausentes.length) {
						const limpiados = await limpiarRutasAusentesGrupoDuplicados(grupo, ausentes);
						if (limpiados > 0) {
							mostrarResultadoAccionDuplicado(
								`Se limpiaron ${limpiados} ruta${limpiados === 1 ? '' : 's'} ausente${limpiados === 1 ? '' : 's'} del grupo. Vuelve a intentar si el grupo todavía tiene duplicados.`,
								false
							);
						} else {
							mostrarResultadoAccionDuplicado(err.message || 'No se pudo aplicar la regla.', true);
						}
					} else {
						mostrarResultadoAccionDuplicado(err.message || 'No se pudo aplicar la regla.', true);
					}
					if (boton) {
						boton.disabled = false;
						boton.removeAttribute('aria-busy');
					}
				}
			}

			function actualizarFiltroDuplicados() {
				if (!buscadorDuplicados) return;
				const consulta = normalizarBusquedaLateral(buscadorDuplicados.value.trim());
				document.querySelectorAll('.duplicados-grupo').forEach(grupo => {
					const texto = normalizarBusquedaLateral(grupo.dataset.duplicadosBusqueda || grupo.textContent || '');
					grupo.hidden = consulta !== '' && !texto.includes(consulta);
				});
			}

			async function cargarGruposDuplicados(opciones = {}) {
				if (!resultadosDuplicados || cargandoGruposDuplicados) return;
				const reiniciar = Boolean(opciones.reiniciar);
				if (reiniciar) {
					reiniciarListaDuplicados();
				}
				if (!hayMasDuplicados) {
					actualizarCargaMasDuplicados();
					return;
				}

				cargandoGruposDuplicados = true;
				actualizarCargaMasDuplicados('Cargando grupos...');
				try {
					const datos = await solicitarDuplicados('grupos', {
						offset: offsetDuplicados,
						limit: limitePaginaDuplicados,
						filtro_origen: filtroOrigenDuplicados,
						orden: ordenDuplicados
					});
					const lista = asegurarListaDuplicados();
					const html = typeof datos?.html_grupos === 'string' ? datos.html_grupos : '';
					if (lista && html.trim() !== '') {
						lista.insertAdjacentHTML('beforeend', html);
						quitarMensajeListaDuplicados();
					}

					const paginacion = datos?.paginacion || {};
					const conteo = Number(paginacion.conteo || 0);
					offsetDuplicados = Number(paginacion.next_offset ?? (offsetDuplicados + conteo));
					hayMasDuplicados = Boolean(paginacion.hay_mas);
					if (datos?.estado) {
						pintarEstadoDuplicados(datos);
					}
					if (!totalGruposDuplicadosCargados() && !hayMasDuplicados) {
						mostrarMensajeListaDuplicados(mensajeVacioDuplicados());
					}
					actualizarFiltroDuplicados();
				} catch (err) {
					mostrarMensajeListaDuplicados(`No se pudieron cargar los grupos: ${err.message}`);
				} finally {
					cargandoGruposDuplicados = false;
					actualizarCargaMasDuplicados();
					if (mensajeDuplicados && !trabajoDuplicadosActivoAnterior) {
						const cargados = totalGruposDuplicadosCargados();
						const etiqueta = etiquetaFiltroOrigenDuplicados();
						const prefijo = etiqueta ? `${etiqueta}: ` : '';
						mensajeDuplicados.textContent = hayMasDuplicados
							? `${prefijo}${cargados} grupos cargados.`
							: `${prefijo}${cargados} grupos cargados. Lista completa.`;
					}
					if (recargaDuplicadosLocalesPendiente) {
						window.setTimeout(recargarDuplicadosLocalesSiDesactualizados, 0);
					}
				}
			}

			async function recargarDuplicadosLocalesSiDesactualizados() {
				const marca = leerMarcaDuplicadosLocalesDesactualizados();
				if (!marca || marca === marcaDuplicadosLocalesVista) return;
				if (cargandoGruposDuplicados) {
					recargaDuplicadosLocalesPendiente = true;
					return;
				}
				recargaDuplicadosLocalesPendiente = false;
				marcaDuplicadosLocalesVista = marca;
				conteosOrigenDuplicadosSolicitados = false;
				conteosOrigenDuplicadosReintentoPendiente = false;
				conteosOrigenDuplicadosUltimaSolicitud = 0;
				await cargarGruposDuplicados({ reiniciar: true });
			}

			function pintarEstadoDuplicados(datos) {
				const estado = datos?.estado || {};
				const job = estado.job || null;
				const activo = trabajoDuplicadosActivo(job);
				const yandex = estado.yandex || {};
				const jobYandex = yandex.job || null;
				const jobCatalogoYandex = yandex.catalog_job || null;
				const activoYandex = trabajoYandexDuplicadosActivo(jobYandex);
				const activoCatalogoYandex = trabajoCatalogoYandexDuplicadosActivo(jobCatalogoYandex);
				const total = Number(job?.total || 0);
				const procesados = Number(job?.procesados || 0);
				if (progresoDuplicados) {
					if (total > 0) {
						progresoDuplicados.max = total;
						progresoDuplicados.value = Math.max(0, Math.min(procesados, total));
					} else if (activo) {
						progresoDuplicados.removeAttribute('value');
					} else {
						progresoDuplicados.max = 1;
						progresoDuplicados.value = 1;
					}
				}
				if (mensajeDuplicados) {
					const resumen = estado.resumen || {};
					const mensaje = job?.mensaje || (resumen.pendiente ? 'Local: grupos listos para cargar por tandas.' : `Local: ${Number(resumen.grupos || 0)} grupos encontrados.`);
					mensajeDuplicados.textContent = datos?.error || mensaje;
				}
				procesarConteosOrigenDesdeEstado(estado.conteos_origen || null);
				const totalYandex = Number(jobYandex?.total || 0);
				const procesadosYandex = Number(jobYandex?.procesados || 0);
				if (progresoYandexDuplicados) {
					if (totalYandex > 0) {
						progresoYandexDuplicados.max = totalYandex;
						progresoYandexDuplicados.value = Math.max(0, Math.min(procesadosYandex, totalYandex));
					} else if (activoYandex) {
						progresoYandexDuplicados.removeAttribute('value');
					} else {
						progresoYandexDuplicados.max = 1;
						progresoYandexDuplicados.value = 1;
					}
				}
				if (mensajeYandexDuplicados) {
					const pendientes = Number(yandex.pendientes_hash || 0);
					const conHash = Number(yandex.total || yandex.con_hash || 0);
					const mensaje = jobYandex?.mensaje || `Yandex: ${conHash} con hash · ${pendientes} pendientes.`;
					mensajeYandexDuplicados.textContent = datos?.error || mensaje;
				}
				const directoriosCatalogados = Number(jobCatalogoYandex?.directorios || 0);
				const directoriosPendientes = Number(jobCatalogoYandex?.directorios_pendientes || 0);
				const totalCatalogo = Math.max(1, Number(jobCatalogoYandex?.total || 0), directoriosCatalogados + directoriosPendientes);
				if (progresoCatalogoYandexDuplicados) {
					if (activoCatalogoYandex || jobCatalogoYandex) {
						progresoCatalogoYandexDuplicados.max = totalCatalogo;
						progresoCatalogoYandexDuplicados.value = Math.max(0, Math.min(directoriosCatalogados, totalCatalogo));
					} else {
						progresoCatalogoYandexDuplicados.max = 1;
						progresoCatalogoYandexDuplicados.value = 1;
					}
				}
				if (mensajeCatalogoYandexDuplicados) {
					const totalCatalogado = Number(yandex.catalogo_total || 0);
					const mensaje = mensajeCatalogoYandexConReanudacion(
						jobCatalogoYandex,
						`Catálogo Yandex: ${totalCatalogado} multimedia registrados.`
					);
					mensajeCatalogoYandexDuplicados.textContent = datos?.error || mensaje;
				}
				if (resultadosDuplicados && typeof datos?.html_resultados === 'string') {
					resultadosDuplicados.innerHTML = datos.html_resultados;
					actualizarFiltroDuplicados();
				}
				if (botonIniciarDuplicados) botonIniciarDuplicados.disabled = activo;
				if (botonRecalcularDuplicados) botonRecalcularDuplicados.disabled = activo;
				if (botonCancelarDuplicados) botonCancelarDuplicados.disabled = !activo;
				if (botonCatalogarYandexDuplicados) botonCatalogarYandexDuplicados.disabled = activoCatalogoYandex;
				if (botonCancelarCatalogoYandexDuplicados) botonCancelarCatalogoYandexDuplicados.disabled = !activoCatalogoYandex;
				if (botonIniciarYandexDuplicados) botonIniciarYandexDuplicados.disabled = activoYandex;
				if (botonCancelarYandexDuplicados) botonCancelarYandexDuplicados.disabled = !activoYandex;
				return activo || activoYandex || activoCatalogoYandex;
			}

			async function solicitarDuplicados(accion, extra = {}) {
				const respuesta = await fetch('index.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json; charset=UTF-8' },
					body: JSON.stringify({
						duplicados_accion: accion,
						ruta: vistaDuplicados.dataset.duplicadosRuta || '',
						...extra
					})
				});
				if (!respuesta.ok) {
					throw new Error(`HTTP ${respuesta.status}`);
				}
				return respuesta.json();
			}

			function programarMonitoreoDuplicados() {
				window.clearTimeout(temporizadorDuplicados);
				temporizadorDuplicados = window.setTimeout(async () => {
					try {
						const datos = await solicitarDuplicados('estado');
						const estabaActivo = trabajoDuplicadosActivoAnterior;
						const activo = pintarEstadoDuplicados(datos);
						if (activo) {
							trabajoDuplicadosActivoAnterior = true;
							programarMonitoreoDuplicados();
						} else {
							trabajoDuplicadosActivoAnterior = false;
							if (estabaActivo) {
								cargarGruposDuplicados({ reiniciar: true });
							}
						}
					} catch (err) {
						if (mensajeDuplicados) mensajeDuplicados.textContent = `No se pudo leer el progreso: ${err.message}`;
					}
				}, 1200);
			}

			function inicializarCargaPorScrollDuplicados() {
				if (!cargaMasDuplicados) return;
				let contenedorScroll = cargaMasDuplicados.parentElement;
				while (contenedorScroll && contenedorScroll !== document.body) {
					const estilos = window.getComputedStyle(contenedorScroll);
					if (/(auto|scroll)/.test(estilos.overflowY) && contenedorScroll.scrollHeight > contenedorScroll.clientHeight) {
						break;
					}
					contenedorScroll = contenedorScroll.parentElement;
				}
				if (contenedorScroll === document.body) {
					contenedorScroll = null;
				}
				if ('IntersectionObserver' in window) {
					const observador = new IntersectionObserver(entradas => {
						if (entradas.some(entrada => entrada.isIntersecting)) {
							cargarGruposDuplicados();
						}
					}, { root: contenedorScroll || null, rootMargin: '360px 0px' });
					observador.observe(cargaMasDuplicados);
				}
				if (contenedorScroll) {
					contenedorScroll.addEventListener('scroll', () => {
						const distanciaFondo = contenedorScroll.scrollHeight - contenedorScroll.scrollTop - contenedorScroll.clientHeight;
						if (distanciaFondo < 700) {
							cargarGruposDuplicados();
						}
					}, { passive: true });
				}
			}

			async function iniciarDuplicados(forzar = false) {
				if (mensajeDuplicados) mensajeDuplicados.textContent = forzar ? 'Preparando recálculo...' : 'Preparando índice local...';
				try {
					const datos = await solicitarDuplicados('iniciar', { forzar });
					const activo = pintarEstadoDuplicados(datos);
					trabajoDuplicadosActivoAnterior = activo;
					if (activo) {
						programarMonitoreoDuplicados();
					}
				} catch (err) {
					if (mensajeDuplicados) mensajeDuplicados.textContent = `No se pudo iniciar: ${err.message}`;
				}
			}

			async function iniciarHashesYandexDuplicados() {
				if (mensajeYandexDuplicados) mensajeYandexDuplicados.textContent = 'Preparando consulta de Yandex...';
				try {
					const datos = await solicitarDuplicados('yandex_hashes');
					const activo = pintarEstadoDuplicados(datos);
					trabajoDuplicadosActivoAnterior = activo;
					if (activo) {
						programarMonitoreoDuplicados();
					}
				} catch (err) {
					if (mensajeYandexDuplicados) mensajeYandexDuplicados.textContent = `No se pudo iniciar Yandex: ${err.message}`;
				}
			}

			async function iniciarCatalogoYandexDuplicados(forzar = false) {
				if (mensajeCatalogoYandexDuplicados) mensajeCatalogoYandexDuplicados.textContent = forzar ? 'Reiniciando catálogo remoto de Yandex...' : 'Preparando catálogo remoto de Yandex...';
				try {
					const datos = await solicitarDuplicados('yandex_catalogo', { forzar_catalogo: forzar });
					const activo = pintarEstadoDuplicados(datos);
					trabajoDuplicadosActivoAnterior = activo;
					if (activo) {
						programarMonitoreoDuplicados();
					}
				} catch (err) {
					if (mensajeCatalogoYandexDuplicados) mensajeCatalogoYandexDuplicados.textContent = `No se pudo iniciar el catálogo Yandex: ${err.message}`;
				}
			}

			function cambiarFiltroOrigenDuplicados(filtro) {
				const normalizado = normalizarFiltroOrigenDuplicados(filtro);
				filtroOrigenDuplicados = filtroOrigenDuplicados === normalizado ? 'todos' : normalizado;
				actualizarBotonesFiltroOrigenDuplicados();
				cargarGruposDuplicados({ reiniciar: true });
			}

			function cambiarOrdenDuplicados(orden) {
				ordenDuplicados = normalizarOrdenDuplicados(orden);
				if (selectorOrdenDuplicados && selectorOrdenDuplicados.value !== ordenDuplicados) {
					selectorOrdenDuplicados.value = ordenDuplicados;
				}
				cargarGruposDuplicados({ reiniciar: true });
			}

			botonIniciarDuplicados?.addEventListener('click', () => iniciarDuplicados(false));
			botonRecalcularDuplicados?.addEventListener('click', () => iniciarDuplicados(true));
			botonCatalogarYandexDuplicados?.addEventListener('click', () => iniciarCatalogoYandexDuplicados(false));
			botonIniciarYandexDuplicados?.addEventListener('click', () => iniciarHashesYandexDuplicados());
			botonesFiltroOrigenDuplicados.forEach(boton => {
				boton.setAttribute('aria-pressed', 'false');
				boton.addEventListener('click', () => cambiarFiltroOrigenDuplicados(boton.dataset.duplicadosFiltroOrigen));
			});
			botonCancelarDuplicados?.addEventListener('click', async () => {
				try {
					const datos = await solicitarDuplicados('cancelar');
					trabajoDuplicadosActivoAnterior = pintarEstadoDuplicados(datos);
					programarMonitoreoDuplicados();
				} catch (err) {
					if (mensajeDuplicados) mensajeDuplicados.textContent = `No se pudo cancelar: ${err.message}`;
				}
			});
			botonCancelarCatalogoYandexDuplicados?.addEventListener('click', async () => {
				try {
					const datos = await solicitarDuplicados('yandex_catalogo_cancelar');
					trabajoDuplicadosActivoAnterior = pintarEstadoDuplicados(datos);
					programarMonitoreoDuplicados();
				} catch (err) {
					if (mensajeCatalogoYandexDuplicados) mensajeCatalogoYandexDuplicados.textContent = `No se pudo cancelar el catálogo Yandex: ${err.message}`;
				}
			});
			botonCancelarYandexDuplicados?.addEventListener('click', async () => {
				try {
					const datos = await solicitarDuplicados('yandex_cancelar');
					trabajoDuplicadosActivoAnterior = pintarEstadoDuplicados(datos);
					programarMonitoreoDuplicados();
				} catch (err) {
					if (mensajeYandexDuplicados) mensajeYandexDuplicados.textContent = `No se pudo cancelar Yandex: ${err.message}`;
				}
			});
			botonCargarMasDuplicados?.addEventListener('click', () => cargarGruposDuplicados());
			selectorOrdenDuplicados?.addEventListener('change', () => cambiarOrdenDuplicados(selectorOrdenDuplicados.value));
			window.addEventListener('dam:duplicados-locales-desactualizados', () => {
				recargarDuplicadosLocalesSiDesactualizados();
			});
			window.addEventListener('pageshow', () => {
				recargarDuplicadosLocalesSiDesactualizados();
			});
				document.addEventListener('dam:sidebar-tab-activated', ev => {
					if (ev.detail?.nombre === 'duplicados') {
						recargarDuplicadosLocalesSiDesactualizados();
					}
				});
				vistaDuplicados.addEventListener('click', ev => {
					const botonOrigen = ev.target.closest?.('[data-duplicado-descartar-origen]');
					if (botonOrigen && vistaDuplicados.contains(botonOrigen)) {
						ev.preventDefault();
						procesarOrigenGrupoDuplicados(botonOrigen.closest('[data-duplicado-grupo]'), botonOrigen);
						return;
					}

					const botonRegla = ev.target.closest?.('[data-duplicado-regla]');
					if (botonRegla && vistaDuplicados.contains(botonRegla)) {
						ev.preventDefault();
						procesarReglaGrupoDuplicados(botonRegla.closest('[data-duplicado-grupo]'), botonRegla);
						return;
					}

					const botonDescartarItem = ev.target.closest?.('[data-duplicado-descartar-item]');
					if (botonDescartarItem && vistaDuplicados.contains(botonDescartarItem)) {
						ev.preventDefault();
						const item = botonDescartarItem.closest('[data-duplicado-item]');
						const datos = datosDuplicadoDesdeElemento(item);
						procesarDuplicadoInteractivo(datos, botonDescartarItem, item);
						return;
					}

					const botonSeleccion = ev.target.closest?.('[data-duplicado-seleccionar]');
					if (botonSeleccion && vistaDuplicados.contains(botonSeleccion)) {
						ev.preventDefault();
						alternarSeleccionDuplicado(botonSeleccion.closest('[data-duplicado-item]'));
						return;
					}

					const botonGrupo = ev.target.closest?.('[data-duplicado-descartar-grupo]');
					if (botonGrupo && vistaDuplicados.contains(botonGrupo)) {
						ev.preventDefault();
						procesarSeleccionGrupoDuplicados(botonGrupo.closest('[data-duplicado-grupo]'), botonGrupo);
						return;
					}

					const item = ev.target.closest?.('[data-duplicado-item]');
					if (item && vistaDuplicados.contains(item) && !ev.target.closest?.('button, a, input, select, textarea, label')) {
						ev.preventDefault();
						mostrarDetalleDuplicado(item);
					}
				});
			vistaDuplicados.addEventListener('keydown', ev => {
				if (ev.key !== 'Enter' && ev.key !== ' ') return;
				const item = ev.target.closest?.('[data-duplicado-item]');
				if (!item || ev.target !== item || !vistaDuplicados.contains(item)) return;
				ev.preventDefault();
				mostrarDetalleDuplicado(item);
			});
			document.addEventListener('click', ev => {
				const botonMetadatos = ev.target.closest?.('[data-duplicado-metadatos]');
				if (botonMetadatos) {
					ev.preventDefault();
					const item = botonMetadatos._duplicadoItem || itemDuplicadoActivo;
					if (item) {
						abrirModalMetadatosDuplicados(item);
					}
					return;
				}

				const boton = ev.target.closest?.('[data-duplicado-panel-accion]');
				if (!boton) return;
				ev.preventDefault();
				const datos = boton._duplicadoDatos || (itemDuplicadoActivo ? datosDuplicadoDesdeElemento(itemDuplicadoActivo) : null);
				if (datos) {
					procesarDuplicadoInteractivo(datos, boton);
				}
			});
			buscadorDuplicados?.addEventListener('input', actualizarFiltroDuplicados);
			actualizarBotonesFiltroOrigenDuplicados();
			actualizarFiltroDuplicados();
			actualizarCargaMasDuplicados('Cargando grupos...');
			solicitarDuplicados('estado')
				.then(datos => {
					const activo = pintarEstadoDuplicados(datos);
					trabajoDuplicadosActivoAnterior = activo;
					if (activo) {
						programarMonitoreoDuplicados();
					}
				})
				.catch(() => {})
				.finally(async () => {
					await cargarGruposDuplicados({ reiniciar: true });
					inicializarCargaPorScrollDuplicados();
				});
		}

		const buscadorPalabrasClave = document.getElementById('buscador-palabras-clave');
	const contenedorPalabrasClave = document.getElementById('palabras-clave-contenedor');
	const resumenPalabrasClave = document.getElementById('palabras-clave-resumen');
	let enlacesPalabrasClave = Array.from(document.querySelectorAll('.palabra-clave-link'));

	function actualizarEnlacesPalabrasClave() {
		enlacesPalabrasClave = Array.from(document.querySelectorAll('.palabra-clave-link'));
	}

	function restaurarFocoPalabraClave() {
		if (!enlacesPalabrasClave.length) return;

		const params = new URLSearchParams(window.location.search);
		const palabraActual = params.get('palabra_clave') || '';
		if (!palabraActual) {
			borrarStorageSesion(claveFocoPalabraClave);
			return;
		}

		const claveActual = normalizarBusquedaLateral(palabraActual);
		const claveGuardada = normalizarBusquedaLateral(leerStorageSesion(claveFocoPalabraClave) || '');
		if (claveGuardada && claveGuardada !== claveActual) {
			borrarStorageSesion(claveFocoPalabraClave);
			return;
		}

		const enlaceActivo = enlacesPalabrasClave.find(enlace => {
			const clave = normalizarBusquedaLateral(enlace.dataset.palabraBusqueda || enlace.dataset.palabra || '');
			return clave === claveActual;
		}) || enlacesPalabrasClave.find(enlace => enlace.classList.contains('activo'));

		if (!enlaceActivo) return;

		window.requestAnimationFrame(() => {
			try {
				enlaceActivo.focus({ preventScroll: true });
			} catch (err) {
				enlaceActivo.focus();
			}
			enlaceActivo.scrollIntoView({ block: 'center', inline: 'nearest' });
			borrarStorageSesion(claveFocoPalabraClave);
		});
	}

	function aplicarFiltroPalabrasClave() {
		if (!buscadorPalabrasClave) return;
		const consulta = normalizarBusquedaLateral(buscadorPalabrasClave.value.trim());
		enlacesPalabrasClave.forEach(enlace => {
			const texto = normalizarBusquedaLateral(`${enlace.dataset.palabra || ''} ${enlace.dataset.palabraBusqueda || ''}`);
			enlace.hidden = consulta !== '' && !texto.includes(consulta);
		});
	}

	function urlPalabraClave(palabra) {
		const params = new URLSearchParams(window.location.search);
		params.delete('pagina');
		params.delete('archivo');
		params.delete('ruta');
		params.set('palabra_clave', palabra);
		return `?${params.toString()}`;
	}

	function renderizarListaPalabrasClave(palabras) {
		if (!contenedorPalabrasClave || !Array.isArray(palabras)) return;
		contenedorPalabrasClave.textContent = '';
		if (resumenPalabrasClave) {
			resumenPalabrasClave.textContent = `${palabras.length} palabras`;
		}
		if (!palabras.length) {
			const vacio = document.createElement('p');
			vacio.className = 'palabras-clave-vacio';
			vacio.textContent = 'Sin palabras clave indexadas.';
			contenedorPalabrasClave.appendChild(vacio);
			actualizarEnlacesPalabrasClave();
			return;
		}

		const lista = document.createElement('div');
		lista.className = 'palabras-clave-lista';
		lista.setAttribute('role', 'list');
		const palabraActiva = normalizarBusquedaLateral(new URLSearchParams(window.location.search).get('palabra_clave') || '');
		palabras.forEach(fila => {
			const palabra = String(fila.palabra || '');
			const clave = String(fila.clave || palabra);
			if (!palabra || !clave) return;
			const enlace = document.createElement('a');
			enlace.className = 'palabra-clave-link';
			if (palabraActiva && normalizarBusquedaLateral(clave) === palabraActiva) {
				enlace.classList.add('activo');
			}
			enlace.href = urlPalabraClave(palabra);
			enlace.dataset.palabra = palabra;
			enlace.dataset.palabraBusqueda = clave;
			enlace.title = palabra;
			enlace.setAttribute('role', 'listitem');

			const texto = document.createElement('span');
			texto.className = 'palabra-clave-texto';
			texto.textContent = palabra;
			const total = document.createElement('span');
			total.className = 'palabra-clave-total';
			total.textContent = String(Number(fila.total || 0));
			enlace.append(texto, total);
			lista.appendChild(enlace);
		});
		contenedorPalabrasClave.appendChild(lista);
		actualizarEnlacesPalabrasClave();
		aplicarFiltroPalabrasClave();
		restaurarFocoPalabraClave();
	}

	if (buscadorPalabrasClave) {
		buscadorPalabrasClave.addEventListener('input', aplicarFiltroPalabrasClave);
	}
	restaurarFocoPalabraClave();

	const botonSincronizarPalabras = document.getElementById('sincronizar-palabras-clave');
	const panelSincronizacionPalabras = document.getElementById('sincronizacion-palabras');
	const progresoSincronizacionPalabras = document.getElementById('sincronizacion-palabras-progreso');
	const mensajeSincronizacionPalabras = document.getElementById('sincronizacion-palabras-mensaje');
	if (botonSincronizarPalabras) {
		function actualizarProgresoPalabras(evento) {
			if (panelSincronizacionPalabras) panelSincronizacionPalabras.hidden = false;
			if (mensajeSincronizacionPalabras && evento.mensaje) {
				mensajeSincronizacionPalabras.textContent = evento.mensaje;
			}
			if (progresoSincronizacionPalabras) {
				const total = Number(evento.total || 0);
				const actual = Number(evento.actual || 0);
				if (total > 0) {
					progresoSincronizacionPalabras.max = total;
					progresoSincronizacionPalabras.value = Math.max(0, Math.min(actual, total));
				} else if (evento.tipo === 'fin') {
					progresoSincronizacionPalabras.max = 1;
					progresoSincronizacionPalabras.value = 1;
				} else {
					progresoSincronizacionPalabras.max = 1;
					progresoSincronizacionPalabras.value = 0;
				}
			}
			if (evento.tipo === 'fin' && Array.isArray(evento.palabras)) {
				renderizarListaPalabrasClave(evento.palabras);
			}
		}

		async function sincronizarPalabrasClave() {
			botonSincronizarPalabras.disabled = true;
			if (panelSincronizacionPalabras) panelSincronizacionPalabras.hidden = false;
			if (mensajeSincronizacionPalabras) mensajeSincronizacionPalabras.textContent = 'Buscando archivos...';
			if (progresoSincronizacionPalabras) {
				progresoSincronizacionPalabras.max = 1;
				progresoSincronizacionPalabras.value = 0;
			}

			try {
				const respuesta = await fetch('index.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json; charset=UTF-8' },
					body: JSON.stringify({ sincronizar_palabras_clave: true })
				});
				if (!respuesta.ok) {
					throw new Error(`HTTP ${respuesta.status}`);
				}

				const procesarLinea = (linea) => {
					const texto = linea.trim();
					if (!texto) return;
					try {
						actualizarProgresoPalabras(JSON.parse(texto));
					} catch (err) {
						if (mensajeSincronizacionPalabras) mensajeSincronizacionPalabras.textContent = texto;
					}
				};

				if (!respuesta.body || !respuesta.body.getReader) {
					(await respuesta.text()).split('\n').forEach(procesarLinea);
					return;
				}

				const reader = respuesta.body.getReader();
				const decoder = new TextDecoder();
				let buffer = '';
				while (true) {
					const { value, done } = await reader.read();
					if (done) break;
					buffer += decoder.decode(value, { stream: true });
					const lineas = buffer.split('\n');
					buffer = lineas.pop() || '';
					lineas.forEach(procesarLinea);
				}
				buffer += decoder.decode();
				procesarLinea(buffer);
			} catch (err) {
				if (mensajeSincronizacionPalabras) {
					mensajeSincronizacionPalabras.textContent = `Error al sincronizar: ${err.message}`;
				}
			} finally {
				botonSincronizarPalabras.disabled = false;
			}
		}

		botonSincronizarPalabras.addEventListener('click', sincronizarPalabrasClave);
	}

	const layout = document.getElementById('layoutPrincipal');
	const alternarCarpetas = document.getElementById('alternar-carpetas');
	if (layout && alternarCarpetas) {
		function aplicarEstadoColumnaCarpetas(colapsada) {
			layout.classList.toggle('carpetas-colapsadas', colapsada);
			alternarCarpetas.setAttribute('aria-expanded', String(!colapsada));
			alternarCarpetas.title = colapsada ? 'Mostrar carpetas' : 'Colapsar carpetas';
		}

		const columnaGuardada = leerStorage(claveColumnaCarpetas);
		if (columnaGuardada !== null) {
			aplicarEstadoColumnaCarpetas(columnaGuardada === '1');
		} else {
			aplicarEstadoColumnaCarpetas(layout.dataset.columnaCarpetasDefault === 'colapsado');
		}

		alternarCarpetas.addEventListener('click', function () {
			const colapsada = !layout.classList.contains('carpetas-colapsadas');
			aplicarEstadoColumnaCarpetas(colapsada);
			escribirStorage(claveColumnaCarpetas, colapsada ? '1' : '0');
		});
	}

	const buscadorCarpetas = document.getElementById('buscador-carpetas');
	const arbolCarpetas = document.querySelector('.arbol-directorios');
	if (buscadorCarpetas && arbolCarpetas) {
		const nodos = Array.from(arbolCarpetas.querySelectorAll('[data-path]'));
		const detalles = Array.from(arbolCarpetas.querySelectorAll('details.directorio-nodo'));
		let filtrandoCarpetas = false;
		let restaurandoArbol = false;

		function leerRamasAbiertas() {
			const valor = leerStorage(claveArbolDirectorios);
			if (!valor) return null;

			try {
				const rutas = JSON.parse(valor);
				if (!Array.isArray(rutas)) return null;
				return new Set(rutas.filter(ruta => typeof ruta === 'string'));
			} catch (err) {
				return null;
			}
		}

		function guardarRamasAbiertas() {
			const rutas = detalles
				.filter(detalle => detalle.open && detalle.dataset.path)
				.map(detalle => detalle.dataset.path)
				.sort();
			escribirStorage(claveArbolDirectorios, JSON.stringify(rutas));
		}

		function cambiarAperturaArbol(callback) {
			restaurandoArbol = true;
			detalles.forEach(callback);
			window.setTimeout(() => {
				restaurandoArbol = false;
			}, 0);
		}

		function restaurarAperturaArbol() {
			const ramasAbiertas = leerRamasAbiertas();
			cambiarAperturaArbol(detalle => {
				const ruta = detalle.dataset.path || '';
				detalle.open = ramasAbiertas
					? ramasAbiertas.has(ruta)
					: detalle.dataset.openDefault === '1';
			});
			if (!ramasAbiertas) {
				guardarRamasAbiertas();
			}
		}

		restaurarAperturaArbol();

		detalles.forEach(detalle => {
			detalle.addEventListener('toggle', function () {
				if (restaurandoArbol || filtrandoCarpetas) return;
				guardarRamasAbiertas();
			});
		});

		function filtrarCarpetas() {
			const consulta = normalizarBusquedaLateral(buscadorCarpetas.value.trim());
			const filtrando = consulta.length > 0;
			filtrandoCarpetas = filtrando;

			nodos.forEach(nodo => {
				const texto = normalizarBusquedaLateral(`${nodo.dataset.path || ''} ${nodo.dataset.name || ''}`);
				nodo.dataset.coincide = texto.includes(consulta) ? '1' : '0';
			});

			nodos.forEach(nodo => {
				const coincide = nodo.dataset.coincide === '1';
				const descendienteCoincide = Array.from(nodo.querySelectorAll('[data-path]'))
					.some(descendiente => descendiente.dataset.coincide === '1');
				nodo.hidden = filtrando && !coincide && !descendienteCoincide;
			});

			cambiarAperturaArbol(detalle => {
				if (filtrando) {
					detalle.open = !detalle.hidden;
				} else {
					const ramasAbiertas = leerRamasAbiertas();
					const ruta = detalle.dataset.path || '';
					detalle.open = ramasAbiertas
						? ramasAbiertas.has(ruta)
						: detalle.dataset.openDefault === '1';
				}
			});
		}

		buscadorCarpetas.addEventListener('input', filtrarCarpetas);
	}

	const buscadorYandexDisk = document.getElementById('buscador-yandex-disk');
	const itemsYandexDisk = Array.from(document.querySelectorAll('.yandex-media-item'));
	if (buscadorYandexDisk && itemsYandexDisk.length) {
		function filtrarYandexDisk() {
			const consulta = normalizarBusquedaLateral(buscadorYandexDisk.value.trim());
			itemsYandexDisk.forEach(item => {
				const texto = normalizarBusquedaLateral(item.dataset.yandexBusqueda || item.textContent || '');
				item.hidden = consulta !== '' && !texto.includes(consulta);
			});
		}

		buscadorYandexDisk.addEventListener('input', filtrarYandexDisk);
	}

	const panelDestino = document.getElementById('panelDetalleContenido');
	const placeholderPanel = document.querySelector('.panel-detalle-placeholder');
	const paneles = Array.from(document.querySelectorAll('.panel-articulo[id^="pie_"]'));
	const articulos = Array.from(document.querySelectorAll('main article[data-panel-id]'));
	let barraSeleccion = null;
	let barraSeleccionYandex = null;
	let modalMoverLote = null;
	let modalMoverYandex = null;
	let modalCopiarYandex = null;
	let carpetasLocalesProyecto = null;
	let operacionLoteEnCurso = false;
	let operacionYandexEnCurso = false;

	function escaparHtmlCliente(valor) {
		const nodo = document.createElement('span');
		nodo.textContent = String(valor ?? '');
		return nodo.innerHTML;
	}

	function idArticuloDesdeArticulo(articulo) {
		return (articulo?.id || '').replace(/^art_/, '');
	}

	function obtenerSeleccionArchivos() {
		return Array.from(document.querySelectorAll('main .archivo-seleccion-checkbox:checked'))
			.map(checkbox => {
				const articulo = checkbox.closest('article[data-panel-id]');
				const ruta = checkbox.dataset.ruta || articulo?.querySelector('[data-ruta]')?.dataset.ruta || '';
				const id = checkbox.dataset.articuloId || idArticuloDesdeArticulo(articulo);
				return { checkbox, articulo, ruta, id };
			})
			.filter(item => item.articulo && item.ruta && item.id);
	}

	function alternarAccionesLoteOcupadas(ocupadas) {
		const barra = crearBarraSeleccion();
		barra.querySelectorAll('button').forEach(boton => {
			boton.disabled = ocupadas;
		});
		if (modalMoverLote) {
			modalMoverLote.querySelectorAll('button, select, input').forEach(control => {
				control.disabled = ocupadas;
			});
		}
	}

	function actualizarBarraSeleccion() {
		const barra = crearBarraSeleccion();
		const seleccion = obtenerSeleccionArchivos();
		document.querySelectorAll('main article[data-panel-id]').forEach(articulo => {
			const checkbox = articulo.querySelector('.archivo-seleccion-checkbox');
			articulo.classList.toggle('seleccionado', Boolean(checkbox?.checked));
		});

		barra.hidden = seleccion.length === 0;
		barra.querySelector('[data-seleccion-total]').textContent =
			seleccion.length === 1 ? '1 archivo seleccionado' : `${seleccion.length} archivos seleccionados`;
		if (!operacionLoteEnCurso) {
			barra.querySelectorAll('button').forEach(boton => {
				boton.disabled = false;
			});
		}
	}

	function limpiarSeleccionArchivos() {
		document.querySelectorAll('main .archivo-seleccion-checkbox:checked').forEach(checkbox => {
			checkbox.checked = false;
		});
		actualizarBarraSeleccion();
	}

	function seleccionarTodosArchivosMostrados() {
		document.querySelectorAll('main article[data-panel-id]').forEach(articulo => {
			if (articulo.hidden || articulo.getClientRects().length === 0) return;
			const checkbox = articulo.querySelector('.archivo-seleccion-checkbox');
			if (checkbox && !checkbox.disabled) {
				checkbox.checked = true;
			}
		});
		actualizarBarraSeleccion();
	}

	function normalizarRutaYandexCliente(ruta) {
		const partes = String(ruta || '')
			.replace(/\\/g, '/')
			.replace(/^disk:/, '')
			.split('/')
			.map(parte => parte.trim())
			.filter(parte => parte && parte !== '.');
		const normalizadas = [];
		partes.forEach(parte => {
			if (parte === '..') {
				normalizadas.pop();
			} else {
				normalizadas.push(parte);
			}
		});
		return normalizadas.length ? '/' + normalizadas.join('/') : '/';
	}

	function articuloYandexMovible(articulo) {
		return Boolean(
			articulo
			&& articulo.matches?.('article.yandex-remoto-articulo[data-yandex-movable="1"][data-yandex-path]')
			&& !articulo.classList.contains('yandex-remoto-unlimited')
		);
	}

	function obtenerArticulosYandexMovibles() {
		return Array.from(document.querySelectorAll('main article.yandex-remoto-articulo[data-yandex-movable="1"][data-yandex-path]'))
			.filter(articulo => articuloYandexMovible(articulo));
	}

	function obtenerSeleccionYandex() {
		return Array.from(document.querySelectorAll('main .yandex-seleccion-checkbox:checked'))
			.map(checkbox => {
				const articulo = checkbox.closest('article.yandex-remoto-articulo[data-panel-id]');
				const ruta = normalizarRutaYandexCliente(checkbox.dataset.yandexPath || articulo?.dataset.yandexPath || '');
				const id = checkbox.dataset.articuloId || idArticuloDesdeArticulo(articulo);
				const nombre = checkbox.dataset.yandexName || articulo?.dataset.yandexName || ruta.split('/').pop() || ruta;
				return { checkbox, articulo, ruta, id, nombre };
			})
			.filter(item => item.articulo && item.ruta !== '/' && item.id);
	}

	function crearBarraSeleccionYandex() {
		if (barraSeleccionYandex) return barraSeleccionYandex;

		barraSeleccionYandex = document.createElement('div');
		barraSeleccionYandex.className = 'acciones-lote acciones-lote-yandex';
		barraSeleccionYandex.hidden = true;
		barraSeleccionYandex.setAttribute('role', 'region');
		barraSeleccionYandex.setAttribute('aria-live', 'polite');
		barraSeleccionYandex.innerHTML =
			'<strong data-yandex-seleccion-total>0 archivos seleccionados</strong>' +
			'<div class="acciones-lote-botones">' +
				'<button type="button" data-yandex-lote="seleccionar-todos">Seleccionar todos</button>' +
				'<button type="button" data-yandex-lote="mover">Mover</button>' +
				'<button type="button" data-yandex-lote="limpiar">Limpiar</button>' +
			'</div>';

		barraSeleccionYandex.addEventListener('click', function (ev) {
			const boton = ev.target.closest?.('[data-yandex-lote]');
			if (!boton || operacionYandexEnCurso) return;
			const accion = boton.dataset.yandexLote;
			if (accion === 'seleccionar-todos') {
				seleccionarTodosYandexMostrados();
			} else if (accion === 'mover') {
				abrirModalMoverYandex();
			} else if (accion === 'limpiar') {
				limpiarSeleccionYandex();
			}
		});

		const columnaContenido = document.querySelector('.col-contenido');
		const main = document.querySelector('main');
		if (columnaContenido && main) {
			columnaContenido.insertBefore(barraSeleccionYandex, main);
		} else {
			document.body.appendChild(barraSeleccionYandex);
		}
		return barraSeleccionYandex;
	}

	function actualizarBarraSeleccionYandex() {
		const articulosMovibles = obtenerArticulosYandexMovibles();
		if (!articulosMovibles.length) {
			if (barraSeleccionYandex) barraSeleccionYandex.hidden = true;
			return;
		}

		const barra = crearBarraSeleccionYandex();
		const seleccion = obtenerSeleccionYandex();
		articulosMovibles.forEach(articulo => {
			const checkbox = articulo.querySelector('.yandex-seleccion-checkbox');
			articulo.classList.toggle('seleccionado', Boolean(checkbox?.checked));
		});

		barra.hidden = false;
		barra.querySelector('[data-yandex-seleccion-total]').textContent =
			seleccion.length === 1 ? '1 archivo seleccionado' : `${seleccion.length} archivos seleccionados`;
		if (!operacionYandexEnCurso) {
			barra.querySelectorAll('button').forEach(boton => {
				const accion = boton.dataset.yandexLote;
				boton.disabled = accion === 'mover' || accion === 'limpiar' ? seleccion.length === 0 : false;
			});
		}
	}

	function alternarAccionesYandexOcupadas(ocupadas) {
		const barra = crearBarraSeleccionYandex();
		barra.querySelectorAll('button').forEach(boton => {
			boton.disabled = ocupadas;
		});
		if (modalMoverYandex) {
			modalMoverYandex.querySelectorAll('button, select, input').forEach(control => {
				control.disabled = ocupadas;
			});
		}
	}

	function limpiarSeleccionYandex() {
		document.querySelectorAll('main .yandex-seleccion-checkbox:checked').forEach(checkbox => {
			checkbox.checked = false;
		});
		actualizarBarraSeleccionYandex();
	}

	function seleccionarTodosYandexMostrados() {
		obtenerArticulosYandexMovibles().forEach(articulo => {
			if (articulo.hidden || articulo.getClientRects().length === 0) return;
			const checkbox = articulo.querySelector('.yandex-seleccion-checkbox');
			if (checkbox && !checkbox.disabled) {
				checkbox.checked = true;
			}
		});
		actualizarBarraSeleccionYandex();
	}

	function insertarControlSeleccionYandex(articulo) {
		if (!articuloYandexMovible(articulo)) return;
		const figure = articulo.querySelector('figure');
		if (!figure || figure.querySelector('.yandex-seleccion-control')) return;

		const ruta = normalizarRutaYandexCliente(articulo.dataset.yandexPath || '');
		if (ruta === '/') return;

		const label = document.createElement('label');
		label.className = 'seleccion-archivo-control yandex-seleccion-control';
		label.title = 'Seleccionar archivo de Yandex';
		label.setAttribute('aria-label', 'Seleccionar archivo de Yandex');

		const checkbox = document.createElement('input');
		checkbox.type = 'checkbox';
		checkbox.className = 'yandex-seleccion-checkbox';
		checkbox.dataset.articuloId = idArticuloDesdeArticulo(articulo);
		checkbox.dataset.yandexPath = ruta;
		checkbox.dataset.yandexName = articulo.dataset.yandexName || ruta.split('/').pop() || ruta;
		checkbox.checked = articulo.classList.contains('seleccionado');

		const texto = document.createElement('span');
		texto.className = 'seleccion-archivo-texto';
		texto.textContent = 'Seleccionar archivo de Yandex';

		label.append(checkbox, texto);
		label.addEventListener('click', ev => ev.stopPropagation());
		checkbox.addEventListener('click', ev => ev.stopPropagation());
		checkbox.addEventListener('change', actualizarBarraSeleccionYandex);
		figure.prepend(label);
	}

	function crearBarraSeleccion() {
		if (barraSeleccion) return barraSeleccion;

		barraSeleccion = document.createElement('div');
		barraSeleccion.className = 'acciones-lote';
		barraSeleccion.hidden = true;
		barraSeleccion.setAttribute('role', 'region');
		barraSeleccion.setAttribute('aria-live', 'polite');
		barraSeleccion.innerHTML =
			'<strong data-seleccion-total>0 archivos seleccionados</strong>' +
			'<div class="acciones-lote-botones">' +
				'<button type="button" data-accion-lote="seleccionar-todos">Seleccionar todos</button>' +
				'<button type="button" data-accion-lote="mover">Mover</button>' +
				'<button type="button" data-accion-lote="archivar">Archivar</button>' +
				'<button type="button" data-accion-lote="borrar">Descartar</button>' +
				'<button type="button" data-accion-lote="limpiar">Limpiar</button>' +
			'</div>';

		barraSeleccion.addEventListener('click', function (ev) {
			const boton = ev.target.closest?.('[data-accion-lote]');
			if (!boton || operacionLoteEnCurso) return;
			const accion = boton.dataset.accionLote;
			if (accion === 'seleccionar-todos') {
				seleccionarTodosArchivosMostrados();
			} else if (accion === 'mover') {
				abrirModalMoverLote();
			} else if (accion === 'archivar' || accion === 'borrar') {
				operarLoteSeleccionado(accion);
			} else if (accion === 'limpiar') {
				limpiarSeleccionArchivos();
			}
		});

		const columnaContenido = document.querySelector('.col-contenido');
		const main = document.querySelector('main');
		if (columnaContenido && main) {
			columnaContenido.insertBefore(barraSeleccion, main);
		} else {
			document.body.appendChild(barraSeleccion);
		}
		return barraSeleccion;
	}

	function insertarControlSeleccion(articulo) {
		const figure = articulo?.querySelector('figure');
		const media = figure?.querySelector('[data-ruta]');
		if (!figure || !media || figure.querySelector('.seleccion-archivo-control')) return;

		const label = document.createElement('label');
		label.className = 'seleccion-archivo-control';
		label.title = 'Seleccionar archivo';
		label.setAttribute('aria-label', 'Seleccionar archivo');

		const checkbox = document.createElement('input');
		checkbox.type = 'checkbox';
		checkbox.className = 'archivo-seleccion-checkbox';
		checkbox.dataset.articuloId = idArticuloDesdeArticulo(articulo);
		checkbox.dataset.ruta = media.dataset.ruta || '';
		checkbox.checked = articulo.classList.contains('seleccionado');

		const texto = document.createElement('span');
		texto.className = 'seleccion-archivo-texto';
		texto.textContent = 'Seleccionar archivo';

		label.append(checkbox, texto);
		label.addEventListener('click', ev => ev.stopPropagation());
		checkbox.addEventListener('click', ev => ev.stopPropagation());
		checkbox.addEventListener('change', actualizarBarraSeleccion);
		figure.prepend(label);
	}

	function poblarSelectorDestinos(select) {
		const opciones = new Map();
		opciones.set('', 'Raíz del proyecto');
		document.querySelectorAll('.directorio-boton[name="archivo"]').forEach(boton => {
			const valor = boton.value || '';
			if (!valor || opciones.has(valor)) return;
			opciones.set(valor, boton.title || valor);
		});

		select.textContent = '';
		opciones.forEach((etiqueta, valor) => {
			const option = document.createElement('option');
			option.value = valor;
			option.textContent = etiqueta;
			select.appendChild(option);
		});
	}

	function cerrarModalMoverLote() {
		const modal = crearModalMoverLote();
		modal.hidden = true;
		modal.querySelector('form')?.reset();
	}

	function abrirModalMoverLote() {
		if (!obtenerSeleccionArchivos().length) return;
		const modal = crearModalMoverLote();
		const select = modal.querySelector('[name="destino"]');
		poblarSelectorDestinos(select);
		modal.hidden = false;
		requestAnimationFrame(() => select.focus());
	}

	function crearModalMoverLote() {
		if (modalMoverLote) return modalMoverLote;

		modalMoverLote = document.createElement('div');
		modalMoverLote.id = 'modal-mover-lote';
		modalMoverLote.className = 'modal-lote';
		modalMoverLote.hidden = true;
		modalMoverLote.setAttribute('role', 'dialog');
		modalMoverLote.setAttribute('aria-modal', 'true');
		modalMoverLote.setAttribute('aria-labelledby', 'modal-mover-lote-titulo');
		modalMoverLote.innerHTML =
			'<div class="modal-lote-panel">' +
				'<form>' +
					'<h2 id="modal-mover-lote-titulo">Mover archivos</h2>' +
					'<label>Carpeta destino' +
						'<select name="destino"></select>' +
					'</label>' +
					'<label>Nueva subcarpeta' +
						'<input type="text" name="subcarpeta" autocomplete="off">' +
					'</label>' +
					'<div class="modal-lote-acciones">' +
						'<button type="button" data-modal-cancelar>Cancelar</button>' +
						'<button type="submit">Mover</button>' +
					'</div>' +
				'</form>' +
			'</div>';

		modalMoverLote.addEventListener('click', function (ev) {
			if (ev.target === modalMoverLote || ev.target.closest?.('[data-modal-cancelar]')) {
				cerrarModalMoverLote();
			}
		});
		modalMoverLote.querySelector('form').addEventListener('submit', async function (ev) {
			ev.preventDefault();
			const form = ev.currentTarget;
			const correcto = await operarLoteSeleccionado('mover', {
				destino: form.elements.destino.value,
				subcarpeta: form.elements.subcarpeta.value.trim()
			});
			if (correcto) {
				cerrarModalMoverLote();
			}
		});
		document.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape' && !modalMoverLote.hidden) {
				cerrarModalMoverLote();
			}
		});
		document.body.appendChild(modalMoverLote);
		return modalMoverLote;
	}

	function mensajeResultadoLote(operacion, datos) {
		const etiquetas = {
			mover: 'Mover',
			archivar: 'Archivar',
			borrar: 'Descartar'
		};
		const titulo = etiquetas[operacion] || 'Procesar';
		const procesados = Number(datos?.procesados || 0);
		const errores = Array.isArray(datos?.errores) ? datos.errores : [];
		let html = `<p><b>${titulo}:</b> ${procesados} archivo${procesados === 1 ? '' : 's'} procesado${procesados === 1 ? '' : 's'}.</p>`;
		if (errores.length) {
			html += '<ul class="errores-lote">';
			errores.slice(0, 6).forEach(error => {
				const ruta = error.origen || error.destino || 'Archivo';
				html += `<li>${escaparHtmlCliente(ruta)}: ${escaparHtmlCliente(error.error || 'No se pudo procesar.')}</li>`;
			});
			if (errores.length > 6) {
				html += `<li>${errores.length - 6} errores más.</li>`;
			}
			html += '</ul>';
		}
		return html;
	}

		async function operarLoteSeleccionado(operacion, extra = {}) {
			const seleccion = obtenerSeleccionArchivos();
			if (!seleccion.length || operacionLoteEnCurso) return false;

		const textosCarga = {
			mover: 'Moviendo archivos',
			archivar: 'Archivando archivos',
			borrar: 'Descartando archivos'
		};
		const parametros = obtenerParametrosVistaActual();
		let redirigiendo = false;
		operacionLoteEnCurso = true;
		alternarAccionesLoteOcupadas(true);
		mostrarCargaNavegacion(textosCarga[operacion] || 'Procesando archivos');

		try {
			const respuesta = await fetch('index.php', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json; charset=UTF-8' },
				body: JSON.stringify({
					operacion_lote: operacion,
					rutas: seleccion.map(item => item.ruta),
					destino: extra.destino || '',
					subcarpeta: extra.subcarpeta || '',
					pagina: parametros.pagina,
					ver: parametros.ver,
					media: parametros.media,
					ruta: parametros.ruta,
					archivo: parametros.archivo,
					palabra_clave: parametros.palabra_clave,
					geo: parametros.geo,
					regiones: parametros.regiones,
					rotacion: parametros.rotacion,
					palabras: parametros.palabras,
					sugerencias: parametros.sugerencias,
					duplicadas: parametros.duplicadas,
					tracking: parametros.tracking,
					vista_ruta: parametros.ruta,
					vista_archivo: parametros.archivo
				})
			});
			const datos = await respuesta.json().catch(() => null);
			if (!respuesta.ok || !datos) {
				throw new Error(datos?.mensaje || `HTTP ${respuesta.status}`);
			}
			const rutasProcesadas = new Set(
				(datos.resultados || [])
					.filter(resultado => resultado.ok)
					.map(resultado => normalizarRutaVista(resultado.origen))
			);
			if (rutasProcesadas.size > 0) {
				marcarDuplicadosLocalesDesactualizados();
			}
			if (datos.redirect_raiz) {
				redirigiendo = true;
				redirigirARaizCarpetas();
				return true;
			}
			if (manejarPaginacionLote(datos, parametros)) {
				redirigiendo = true;
				return true;
			}

			seleccion.forEach(item => {
				if (rutasProcesadas.has(normalizarRutaVista(item.ruta))) {
					window.DAM.removerBloqueArticulo(item.id, '');
				}
			});

			mostrarMensajeDetalle(mensajeResultadoLote(operacion, datos));
			actualizarBarraSeleccion();
			if (rutasProcesadas.size > 0) {
				await rellenarVistaHastaCompleta();
			}
			return rutasProcesadas.size > 0;
		} catch (err) {
			mostrarMensajeDetalle(`<p class="respuesta_error">${escaparHtmlCliente(err.message || 'No se pudo procesar la selección.')}</p>`);
			return false;
		} finally {
			if (!redirigiendo) {
				ocultarCargaNavegacion();
				operacionLoteEnCurso = false;
				actualizarBarraSeleccion();
				alternarAccionesLoteOcupadas(false);
			}
		}
		}

		function rutaActualYandexCliente() {
			const params = new URLSearchParams(window.location.search);
			const controlRuta = document.querySelector('.yandex-orden-form input[name="yandex_path"]');
			return normalizarRutaYandexCliente(controlRuta?.value || params.get('yandex_path') || '/');
		}

		function rutaPadreYandexCliente(ruta) {
			ruta = normalizarRutaYandexCliente(ruta);
			if (ruta === '/') return '/';
			const partes = ruta.replace(/^\/+|\/+$/g, '').split('/').filter(Boolean);
			partes.pop();
			return partes.length ? '/' + partes.join('/') : '/';
		}

		function etiquetaDestinoYandex(ruta) {
			return ruta === '/' ? 'Raíz de Yandex.Disk' : ruta;
		}

		function opcionesDestinoYandex() {
			const opciones = new Map();
			opciones.set('/', 'Raíz de Yandex.Disk');

			const actual = rutaActualYandexCliente();
			if (actual !== '/') {
				opciones.set(actual, `Ruta actual: ${actual}`);
				let padre = rutaPadreYandexCliente(actual);
				while (padre !== '/') {
					if (!opciones.has(padre)) {
						opciones.set(padre, padre);
					}
					padre = rutaPadreYandexCliente(padre);
				}
			}

			obtenerSeleccionYandex().forEach(item => {
				const padre = rutaPadreYandexCliente(item.ruta);
				if (!opciones.has(padre)) {
					opciones.set(padre, padre === '/' ? 'Raíz de Yandex.Disk' : `Origen: ${padre}`);
				}
			});

			document.querySelectorAll('#panel-yandex .yandex-media-item-dir').forEach(enlace => {
				const ruta = normalizarRutaYandexCliente(
					enlace.getAttribute('title')
					|| enlace.querySelector('.yandex-media-ruta')?.textContent
					|| ''
				);
				if (ruta !== '/' && !opciones.has(ruta)) {
					opciones.set(ruta, ruta);
				}
			});

			return Array.from(opciones.entries()).sort((a, b) => {
				if (a[0] === '/') return -1;
				if (b[0] === '/') return 1;
				return a[0].localeCompare(b[0], 'es', { numeric: true, sensitivity: 'base' });
			});
		}

		function poblarSelectorDestinosYandexRemotos(select, input) {
			if (!select) return;
			const valorPrevio = normalizarRutaYandexCliente(input?.value || select.value || rutaActualYandexCliente());
			select.textContent = '';
			opcionesDestinoYandex().forEach(([ruta, etiqueta]) => {
				const option = document.createElement('option');
				option.value = ruta;
				option.textContent = etiqueta;
				select.appendChild(option);
			});
			select.value = Array.from(select.options).some(option => option.value === valorPrevio)
				? valorPrevio
				: '/';
			if (input) {
				input.value = select.value || '/';
			}
		}

		function cerrarModalMoverYandex() {
			const modal = crearModalMoverYandex();
			modal.hidden = true;
			modal.querySelector('form')?.reset();
			const estado = modal.querySelector('[data-yandex-move-status]');
			if (estado) {
				estado.textContent = '';
				estado.className = 'modal-lote-estado';
			}
		}

		function abrirModalMoverYandex() {
			const seleccion = obtenerSeleccionYandex();
			if (!seleccion.length) return;
			const modal = crearModalMoverYandex();
			const contexto = modal.querySelector('[data-yandex-move-contexto]');
			const select = modal.querySelector('[name="destino"]');
			const input = modal.querySelector('[name="destino_manual"]');
			const estado = modal.querySelector('[data-yandex-move-status]');
			const datalist = modal.querySelector('#yandex-destinos-datalist');
			if (contexto) {
				contexto.textContent = seleccion.length === 1
					? seleccion[0].ruta
					: `${seleccion.length} archivos seleccionados`;
			}
			if (estado) {
				estado.textContent = '';
				estado.className = 'modal-lote-estado';
			}
			poblarSelectorDestinosYandexRemotos(select, input);
			if (datalist) {
				datalist.textContent = '';
				fetch('carpetas_locales.php?yandex=1')
					.then(respuesta => respuesta.json().catch(() => null))
					.then(datos => {
						if (!datos?.ok || !Array.isArray(datos.carpetas)) return;
						datalist.textContent = '';
						datos.carpetas.forEach(({ valor }) => {
							const op = document.createElement('option');
							op.value = valor;
							datalist.appendChild(op);
						});
					})
					.catch(() => {});
			}
			modal.hidden = false;
			requestAnimationFrame(() => input?.focus());
		}

		function crearModalMoverYandex() {
			if (modalMoverYandex) return modalMoverYandex;

			modalMoverYandex = document.createElement('div');
			modalMoverYandex.id = 'modal-yandex-mover';
			modalMoverYandex.className = 'modal-lote';
			modalMoverYandex.hidden = true;
			modalMoverYandex.setAttribute('role', 'dialog');
			modalMoverYandex.setAttribute('aria-modal', 'true');
			modalMoverYandex.setAttribute('aria-labelledby', 'modal-yandex-mover-titulo');
			modalMoverYandex.innerHTML =
				'<div class="modal-lote-panel">' +
					'<form>' +
						'<h2 id="modal-yandex-mover-titulo">Mover en Yandex.Disk</h2>' +
						'<p class="modal-lote-contexto" data-yandex-move-contexto></p>' +
						'<label>Carpeta destino' +
							'<select name="destino"></select>' +
						'</label>' +
						'<label>Ruta destino' +
							'<input type="text" name="destino_manual" list="yandex-destinos-datalist" autocomplete="off" placeholder="/media">' +
						'</label>' +
						'<datalist id="yandex-destinos-datalist"></datalist>' +
						'<output class="modal-lote-estado" data-yandex-move-status aria-live="polite"></output>' +
						'<div class="modal-lote-acciones">' +
							'<button type="button" data-modal-cancelar>Cancelar</button>' +
							'<button type="submit">Mover</button>' +
						'</div>' +
					'</form>' +
				'</div>';

			const select = modalMoverYandex.querySelector('[name="destino"]');
			const input = modalMoverYandex.querySelector('[name="destino_manual"]');
			select?.addEventListener('change', function () {
				if (input) input.value = select.value || '/';
			});
			modalMoverYandex.addEventListener('click', function (ev) {
				if (ev.target === modalMoverYandex || ev.target.closest?.('[data-modal-cancelar]')) {
					cerrarModalMoverYandex();
				}
			});
			modalMoverYandex.querySelector('form').addEventListener('submit', async function (ev) {
				ev.preventDefault();
				await moverSeleccionYandex(ev.currentTarget);
			});
			document.addEventListener('keydown', function (ev) {
				if (ev.key === 'Escape' && !modalMoverYandex.hidden) {
					cerrarModalMoverYandex();
				}
			});
			document.body.appendChild(modalMoverYandex);
			return modalMoverYandex;
		}

		function mensajeResultadoMoverYandex(datos, destino) {
			const procesados = Number(datos?.procesados || 0);
			const errores = Array.isArray(datos?.errores) ? datos.errores : [];
			let html = `<p><b>Yandex Disk:</b> ${procesados} archivo${procesados === 1 ? '' : 's'} movido${procesados === 1 ? '' : 's'} a ${escaparHtmlCliente(destino)}.</p>`;
			if (errores.length) {
				html += '<ul class="errores-lote">';
				errores.slice(0, 6).forEach(error => {
					html += `<li>${escaparHtmlCliente(error.origen || 'Archivo')}: ${escaparHtmlCliente(error.error || 'No se pudo mover.')}</li>`;
				});
				if (errores.length > 6) {
					html += `<li>${errores.length - 6} errores más.</li>`;
				}
				html += '</ul>';
			}
			return html;
		}

		async function moverSeleccionYandex(form) {
			const seleccion = obtenerSeleccionYandex();
			if (!seleccion.length || operacionYandexEnCurso) return false;

			const estado = form.querySelector('[data-yandex-move-status]');
			const destino = normalizarRutaYandexCliente(form.elements.destino_manual?.value || form.elements.destino?.value || '/');
			operacionYandexEnCurso = true;
			alternarAccionesYandexOcupadas(true);
			if (estado) {
				estado.className = 'modal-lote-estado';
				estado.textContent = 'Moviendo archivos...';
			}
			mostrarCargaNavegacion('Moviendo en Yandex');

			try {
				const respuesta = await fetch('yandex_move.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json; charset=UTF-8' },
					body: JSON.stringify({
						paths: seleccion.map(item => item.ruta),
						destino
					})
				});
				const datos = await respuesta.json().catch(() => null);
				if (!respuesta.ok || !datos?.ok) {
					throw new Error(datos?.error || `HTTP ${respuesta.status}`);
				}

				const rutasProcesadas = new Set(
					(datos.resultados || [])
						.filter(resultado => resultado.ok)
						.map(resultado => normalizarRutaYandexCliente(resultado.origen))
				);
				seleccion.forEach(item => {
					if (rutasProcesadas.has(normalizarRutaYandexCliente(item.ruta))) {
						window.DAM?.removerBloqueArticulo?.(item.id, '');
					}
				});
				redirigirSiPaginaVacia();

				const mensaje = mensajeResultadoMoverYandex(datos, destino);
				if (estado) {
					estado.classList.add(datos.errores?.length ? 'error' : 'ok');
					estado.textContent = `${Number(datos.procesados || 0)} archivo${Number(datos.procesados || 0) === 1 ? '' : 's'} movido${Number(datos.procesados || 0) === 1 ? '' : 's'}.`;
				}
				mostrarMensajeDetalle(mensaje);
				cerrarModalMoverYandex();
				actualizarBarraSeleccionYandex();
				return rutasProcesadas.size > 0;
			} catch (err) {
				const mensaje = err.message || 'No se pudo mover la selección en Yandex Disk.';
				if (estado) {
					estado.classList.add('error');
					estado.textContent = mensaje;
				}
				mostrarMensajeDetalle(`<p class="respuesta_error">${escaparHtmlCliente(mensaje)}</p>`);
				return false;
			} finally {
				operacionYandexEnCurso = false;
				alternarAccionesYandexOcupadas(false);
				actualizarBarraSeleccionYandex();
				ocultarCargaNavegacion();
			}
		}

		async function cargarCarpetasLocalesProyecto() {
			if (Array.isArray(carpetasLocalesProyecto)) {
				return carpetasLocalesProyecto;
			}

			const respuesta = await fetch('carpetas_locales.php', {
				headers: { 'Accept': 'application/json' }
			});
			const datos = await respuesta.json().catch(() => null);
			if (!respuesta.ok || !datos?.ok || !Array.isArray(datos.carpetas)) {
				throw new Error(datos?.error || `HTTP ${respuesta.status}`);
			}
			carpetasLocalesProyecto = datos.carpetas;
			return carpetasLocalesProyecto;
		}

		async function poblarSelectorDestinoYandex(select) {
			if (!select) return;
			select.disabled = true;
			select.textContent = '';
			const cargando = document.createElement('option');
			cargando.value = '';
			cargando.textContent = 'Cargando carpetas...';
			select.appendChild(cargando);

			const carpetas = await cargarCarpetasLocalesProyecto();
			select.textContent = '';
			carpetas.forEach(carpeta => {
				const option = document.createElement('option');
				option.value = carpeta.valor || '';
				option.textContent = carpeta.etiqueta || carpeta.valor || 'Raíz del proyecto';
				select.appendChild(option);
			});
			select.disabled = false;
		}

		function crearModalCopiarYandex() {
			if (modalCopiarYandex) return modalCopiarYandex;

			modalCopiarYandex = document.createElement('div');
			modalCopiarYandex.id = 'modal-yandex-copiar';
			modalCopiarYandex.className = 'modal-lote';
			modalCopiarYandex.hidden = true;
			modalCopiarYandex.setAttribute('role', 'dialog');
			modalCopiarYandex.setAttribute('aria-modal', 'true');
			modalCopiarYandex.setAttribute('aria-labelledby', 'modal-yandex-copiar-titulo');
			modalCopiarYandex.innerHTML =
				'<div class="modal-lote-panel">' +
					'<form>' +
						'<h2 id="modal-yandex-copiar-titulo">Copiar desde Yandex</h2>' +
						'<p class="modal-lote-contexto" data-yandex-copy-nombre></p>' +
						'<label>Carpeta destino' +
							'<select name="destino"></select>' +
						'</label>' +
						'<label>Nueva subcarpeta' +
							'<input type="text" name="subcarpeta" autocomplete="off">' +
						'</label>' +
						'<output class="modal-lote-estado" data-yandex-copy-status aria-live="polite"></output>' +
						'<div class="modal-lote-acciones">' +
							'<button type="button" data-modal-cancelar>Cancelar</button>' +
							'<button type="submit">Copiar</button>' +
						'</div>' +
					'</form>' +
				'</div>';

			modalCopiarYandex.addEventListener('click', function (ev) {
				if (ev.target === modalCopiarYandex || ev.target.closest?.('[data-modal-cancelar]')) {
					cerrarModalCopiarYandex();
				}
			});
			modalCopiarYandex.querySelector('form').addEventListener('submit', async function (ev) {
				ev.preventDefault();
				await copiarYandexALocal(ev.currentTarget);
			});
			document.addEventListener('keydown', function (ev) {
				if (ev.key === 'Escape' && !modalCopiarYandex.hidden) {
					cerrarModalCopiarYandex();
				}
			});
			document.body.appendChild(modalCopiarYandex);
			return modalCopiarYandex;
		}

		function cerrarModalCopiarYandex() {
			const modal = crearModalCopiarYandex();
			modal.hidden = true;
			modal._botonYandex = null;
			modal.querySelector('form')?.reset();
			const estado = modal.querySelector('[data-yandex-copy-status]');
			if (estado) {
				estado.textContent = '';
				estado.className = 'modal-lote-estado';
			}
		}

		async function abrirModalCopiarYandex(boton) {
			const modal = crearModalCopiarYandex();
			modal._botonYandex = boton;
			const nombre = boton?.dataset.yandexCopyName || 'Archivo de Yandex Disk';
			const contexto = modal.querySelector('[data-yandex-copy-nombre]');
			const estado = modal.querySelector('[data-yandex-copy-status]');
			const select = modal.querySelector('[name="destino"]');
			if (contexto) contexto.textContent = nombre;
			if (estado) {
				estado.textContent = '';
				estado.className = 'modal-lote-estado';
			}
			modal.hidden = false;

			try {
				await poblarSelectorDestinoYandex(select);
				requestAnimationFrame(() => select?.focus());
			} catch (err) {
				if (estado) {
					estado.classList.add('error');
					estado.textContent = err.message || 'No se pudieron cargar las carpetas locales.';
				}
			}
		}

		function mostrarEstadoCopiaYandex(boton, mensaje, error = false) {
			const panel = boton?.closest?.('.panel-yandex-remoto');
			if (!panel) return;
			let estado = panel.querySelector('.yandex-copia-estado');
			if (!estado) {
				estado = document.createElement('p');
				estado.className = 'yandex-copia-estado';
				estado.setAttribute('aria-live', 'polite');
				const acciones = panel.querySelector('.yandex-detalle-acciones');
				acciones?.insertAdjacentElement('afterend', estado);
			}
			estado.classList.toggle('error', error);
			estado.textContent = mensaje;
		}

		function alternarFormularioCopiarYandex(form, ocupado) {
			form.querySelectorAll('button, select, input').forEach(control => {
				control.disabled = ocupado;
			});
		}

		async function copiarYandexALocal(form) {
			const modal = crearModalCopiarYandex();
			const boton = modal._botonYandex;
			if (!boton) return;

				const estado = modal.querySelector('[data-yandex-copy-status]');
				const payload = {
					path: boton.dataset.yandexCopyPath || '',
					photo_id: boton.dataset.yandexCopyPhotoId || '',
					name: boton.dataset.yandexCopyName || '',
					tipo: boton.dataset.yandexCopyType || '',
					preview: boton.dataset.yandexCopyPreview || '',
					destino: form.elements.destino?.value || '',
					subcarpeta: form.elements.subcarpeta?.value.trim() || ''
				};

			alternarFormularioCopiarYandex(form, true);
			boton.disabled = true;
			boton.setAttribute('aria-busy', 'true');
			if (estado) {
				estado.className = 'modal-lote-estado';
				estado.textContent = 'Copiando archivo...';
			}
			mostrarCargaNavegacion('Copiando desde Yandex');

			try {
				const respuesta = await fetch('yandex_copy.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json; charset=UTF-8' },
					body: JSON.stringify(payload)
				});
				const datos = await respuesta.json().catch(() => null);
				if (!respuesta.ok || !datos?.ok) {
					throw new Error(datos?.error || `HTTP ${respuesta.status}`);
				}

				const mensaje = datos.mensaje || `Copiado en ${datos.destino || 'la carpeta seleccionada'}`;
				if (estado) {
					estado.classList.add('ok');
					estado.textContent = mensaje;
				}
				mostrarEstadoCopiaYandex(boton, mensaje, false);
				cerrarModalCopiarYandex();
			} catch (err) {
				const mensaje = err.message || 'No se pudo copiar el archivo desde Yandex Disk.';
				if (estado) {
					estado.classList.add('error');
					estado.textContent = mensaje;
				}
				mostrarEstadoCopiaYandex(boton, mensaje, true);
			} finally {
				alternarFormularioCopiarYandex(form, false);
				boton.disabled = false;
				boton.removeAttribute('aria-busy');
				ocultarCargaNavegacion();
			}
		}

		async function enviarYandexPapelera(boton) {
			const ruta = boton?.dataset.yandexTrashPath || '';
			const id = boton?.dataset.yandexTrashId || '';
		if (!ruta || !id) return;
		if (!confirm(`¿Enviar a la papelera de Yandex Disk?\n${ruta}`)) return;

		boton.disabled = true;
		boton.setAttribute('aria-busy', 'true');
		mostrarCargaNavegacion('Enviando a papelera');

		try {
			const respuesta = await fetch('yandex_trash.php', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json; charset=UTF-8' },
				body: JSON.stringify({ path: ruta })
			});
			const datos = await respuesta.json().catch(() => null);
			if (!respuesta.ok || !datos?.ok) {
				throw new Error(datos?.error || `HTTP ${respuesta.status}`);
			}

			if (window.DAM && window.DAM.removerBloqueArticulo) {
				window.DAM.removerBloqueArticulo(id, `<p><b>Yandex Disk:</b> ${escaparHtmlCliente(ruta)} enviado a la papelera.</p>`);
			} else {
				document.getElementById(`art_${id}`)?.remove();
				document.getElementById(`pie_${id}`)?.remove();
			}
			redirigirSiPaginaVacia();
		} catch (err) {
			mostrarMensajeDetalle(`<p class="respuesta_error">${escaparHtmlCliente(err.message || 'No se pudo enviar a la papelera de Yandex Disk.')}</p>`);
			boton.disabled = false;
			boton.removeAttribute('aria-busy');
		} finally {
			ocultarCargaNavegacion();
		}
	}

	function actualizarArticuloDesdeDetalle(articuloActual, articuloNuevo) {
		if (!articuloActual || !articuloNuevo) return;

		const articuloActivo = articuloActual.classList.contains('activo');
		const articuloSeleccionado = articuloActual.classList.contains('seleccionado');
		const articuloPreparado = articuloActual.dataset.articuloPreparado;
		const figureActual = articuloActual.querySelector('figure');
		const figureNuevo = articuloNuevo.querySelector('figure');
		if (figureActual && figureNuevo) {
			figureActual.replaceWith(figureNuevo);
		}

		articuloActual.className = articuloNuevo.className;
		if (articuloActivo) {
			articuloActual.classList.add('activo');
		}
		if (articuloSeleccionado) {
			articuloActual.classList.add('seleccionado');
		}
		if (articuloPreparado) {
			articuloActual.dataset.articuloPreparado = articuloPreparado;
		}
		articuloActual.dataset.detalleDiferido = '0';

		const estilo = articuloNuevo.getAttribute('style');
		if (estilo === null) {
			articuloActual.removeAttribute('style');
		} else {
			articuloActual.setAttribute('style', estilo);
		}
	}

	function crearPanelArticuloPendiente(articulo) {
		if (!articulo || !panelDestino) return null;

		const panelId = articulo.dataset.panelId;
		const ruta = articulo.dataset.detalleRuta || articulo.querySelector('[data-ruta]')?.dataset.ruta || '';
		const media = articulo.dataset.detalleMedia || articulo.querySelector('[data-tipo]')?.dataset.tipo || 'img';
		if (!panelId || !ruta) return null;

		panelDestino.replaceChildren();
		const panel = document.createElement('section');
		panel.id = panelId;
		panel.className = 'panel-articulo panel-articulo-pendiente';
		panel.dataset.panelPendiente = '1';
		panel.dataset.ruta = ruta;
		panel.dataset.media = media;
		panel.innerHTML = '<p class="panel-cargando">Cargando metadatos...</p>';
		panelDestino.appendChild(panel);
		return panel;
	}

	async function cargarPanelArticuloDiferido(articulo, panel) {
		if (!articulo || !panel || panel.dataset.panelPendiente !== '1' || panel.dataset.cargando === '1') return;

		const id = idArticuloDesdeArticulo(articulo);
		const ruta = panel.dataset.ruta || articulo.querySelector('[data-ruta]')?.dataset.ruta || '';
		const media = panel.dataset.media || articulo.querySelector('[data-tipo]')?.dataset.tipo || 'img';
		if (!id || !ruta) {
			panel.innerHTML = '<p class="respuesta_error">No se pudo resolver la ruta del archivo.</p>';
			return;
		}

		panel.dataset.cargando = '1';
		panel.setAttribute('aria-busy', 'true');
		panel.innerHTML = '<p class="panel-cargando">Cargando metadatos...</p>';

		try {
			const esYandex = articulo.classList.contains('yandex-remoto-articulo');
			const payload = {
				estado_metadatos: true,
				id,
				ruta,
				media
			};
			if (esYandex) {
				payload.yandex = true;
			}
			const respuesta = await fetch('index.php', {
				method: 'POST',
				headers: {'Content-Type': 'application/json;charset=UTF-8'},
				body: JSON.stringify(payload)
			});
			if (!respuesta.ok) {
				throw new Error('No se pudo cargar el detalle.');
			}
			const html = (await respuesta.text()).trim();
			if (!html) {
				throw new Error('El servidor no devolvió metadatos.');
			}

			const plantilla = document.createElement('template');
			plantilla.innerHTML = html;
			const panelNuevo = plantilla.content.querySelector(`#pie_${CSS.escape(id)}`);
			const articuloNuevo = plantilla.content.querySelector(`#art_${CSS.escape(id)}`);
			if (!panelNuevo) {
				throw new Error('La respuesta no contiene el panel de metadatos.');
			}
			if (document.getElementById(panel.id) !== panel || !articulo.classList.contains('activo')) {
				return;
			}

			const visible = !panel.hidden;
			panel.replaceWith(panelNuevo);
			if (panelDestino && panelNuevo.parentElement !== panelDestino) {
				panelDestino.appendChild(panelNuevo);
			}
			prepararFormularioPanel(panelNuevo);
			panelNuevo.hidden = !visible;
			panelNuevo.removeAttribute('aria-busy');

			if (!esYandex) {
				actualizarArticuloDesdeDetalle(articulo, articuloNuevo);
				sincronizarIndicadoresArticulo(articulo);
			}
		} catch (err) {
			panel.innerHTML =
				'<p class="respuesta_error">' + escaparHtmlCliente(err.message || 'No se pudieron cargar los metadatos.') + '</p>' +
				'<button type="button" class="panel-reintentar-detalle">Reintentar</button>';
			delete panel.dataset.cargando;
			panel.removeAttribute('aria-busy');
		}
	}

	function mostrarPanelArticulo(articulo) {
		if (!articulo) return;
		const panelId = articulo.dataset.panelId;
		let panel = document.getElementById(panelId);
		if (!panel) {
			panel = crearPanelArticuloPendiente(articulo);
		}
		if (!panel) return;

		document.querySelectorAll('main article[data-panel-id]').forEach(item => item.classList.toggle('activo', item === articulo));
		document.querySelectorAll('.panel-articulo[id^="pie_"]').forEach(item => {
			item.hidden = item !== panel;
		});

		if (placeholderPanel) {
			placeholderPanel.hidden = true;
		}
		cargarPanelArticuloDiferido(articulo, panel);
	}

	document.addEventListener('click', function (ev) {
		const boton = ev.target.closest?.('.panel-reintentar-detalle');
		if (!boton) return;
		const panel = boton.closest('.panel-articulo[id^="pie_"]');
		const id = (panel?.id || '').replace(/^pie_/, '');
		const articulo = id ? document.getElementById(`art_${id}`) : null;
		if (!panel || !articulo) return;
		ev.preventDefault();
		cargarPanelArticuloDiferido(articulo, panel);
	});

		document.addEventListener('click', function (ev) {
			const boton = ev.target.closest?.('.yandex-descarga-boton[data-yandex-copy-name]');
			if (!boton) return;
			ev.preventDefault();
			ev.stopPropagation();
			abrirModalCopiarYandex(boton);
		});

		document.addEventListener('click', function (ev) {
			const boton = ev.target.closest?.('.yandex-papelera-boton');
			if (!boton) return;
			ev.preventDefault();
		ev.stopPropagation();
		enviarYandexPapelera(boton);
	});

		document.addEventListener('click', function (ev) {
			const boton = ev.target.closest?.('.yandex-borrar-carpeta-boton');
			if (!boton) return;
			ev.preventDefault();
			ev.stopPropagation();
			borrarCarpetaYandex(boton);
		});

		async function borrarCarpetaYandex(boton) {
			const ruta = boton?.dataset.yandexTrashPath || '';
			const padre = boton?.dataset.yandexParentPath || '/';
			if (!ruta) return;
			if (!confirm(`¿Enviar a la papelera de Yandex Disk la carpeta?\n${ruta}`)) return;

			boton.disabled = true;
			boton.setAttribute('aria-busy', 'true');
			mostrarCargaNavegacion('Enviando a papelera');

			try {
				const respuesta = await fetch('yandex_trash.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json; charset=UTF-8' },
					body: JSON.stringify({ path: ruta })
				});
				const datos = await respuesta.json().catch(() => null);
				if (!respuesta.ok || !datos?.ok) {
					throw new Error(datos?.error || `HTTP ${respuesta.status}`);
				}
				window.location.href = urlPanelYandexDisk(padre);
			} catch (err) {
				alert(err.message || 'No se pudo enviar la carpeta a la papelera.');
				boton.disabled = false;
				boton.removeAttribute('aria-busy');
				ocultarCargaNavegacion();
			}
		}

	function agregarIndicadorArticulo(contenedor, clase, icono, etiqueta) {
		const indicador = document.createElement('span');
		indicador.className = `indicador-articulo ${clase}`;
		indicador.setAttribute('role', 'img');
		indicador.setAttribute('aria-label', etiqueta);
		indicador.title = etiqueta;
		indicador.textContent = icono;
		contenedor.appendChild(indicador);
	}

	function indicadorDatasetKey(clave) {
		return 'indicador' + clave.charAt(0).toUpperCase() + clave.slice(1);
	}

	function actualizarDatasetIndicador(articulo, clave, activo) {
		if (!articulo) return;
		const datasetKey = indicadorDatasetKey(clave);
		if (activo) {
			articulo.dataset[datasetKey] = '1';
		} else {
			delete articulo.dataset[datasetKey];
		}
	}

	function indicadorActivoDesdeArticulo(articulo, clave) {
		return articulo?.dataset?.[indicadorDatasetKey(clave)] === '1';
	}

	function obtenerEstadoIndicadoresArticulo(articulo, panel) {
		if (panel) {
			const sugeridos = panel.querySelectorAll('.sugerido').length;
			const advertencias = panel.querySelectorAll('.advertencia-metadatos').length;
			const estado = {
				ia: panel.dataset.metadatosIa === '1' || Boolean(panel.querySelector('.metadata-ia')),
				geo: panel.dataset.geolocalizacion === '1' || Boolean(panel.querySelector('.metadata-geo')),
				xattr: Boolean(panel.querySelector('.metadata-xattr')),
				duplicado: panel.dataset.palabrasDuplicadas === '1' || Boolean(panel.querySelector('.requiere-guardar')),
				sugeridos,
				advertencias
			};
			actualizarDatasetIndicador(articulo, 'ia', estado.ia);
			actualizarDatasetIndicador(articulo, 'geo', estado.geo);
			actualizarDatasetIndicador(articulo, 'xattr', estado.xattr);
			actualizarDatasetIndicador(articulo, 'duplicado', estado.duplicado);
			actualizarDatasetIndicador(articulo, 'sugerido', sugeridos > 0);
			actualizarDatasetIndicador(articulo, 'advertencia', advertencias > 0);
			return estado;
		}

		return {
			ia: indicadorActivoDesdeArticulo(articulo, 'ia'),
			geo: indicadorActivoDesdeArticulo(articulo, 'geo'),
			xattr: indicadorActivoDesdeArticulo(articulo, 'xattr'),
			duplicado: indicadorActivoDesdeArticulo(articulo, 'duplicado'),
			sugeridos: indicadorActivoDesdeArticulo(articulo, 'sugerido') ? 1 : 0,
			advertencias: indicadorActivoDesdeArticulo(articulo, 'advertencia') ? 1 : 0
		};
	}

	function sincronizarIndicadoresArticulo(articulo) {
		const panel = document.getElementById(articulo.dataset.panelId);
		const figure = articulo.querySelector('figure');
		if (!figure) return;

		insertarControlSeleccionYandex(articulo);
		insertarControlSeleccion(articulo);

		let contenedor = figure.querySelector('.indicadores-articulo');
		if (!contenedor) {
			contenedor = document.createElement('div');
			contenedor.className = 'indicadores-articulo';
			figure.appendChild(contenedor);
		}
		contenedor.innerHTML = '';

		const estado = obtenerEstadoIndicadoresArticulo(articulo, panel);

		if (estado.ia) {
			agregarIndicadorArticulo(
				contenedor,
				'indicador-ia',
				'🤖',
				'Metadatos de IA detectados'
			);
		}
		if (estado.geo) {
			agregarIndicadorArticulo(
				contenedor,
				'indicador-geo',
				'📍',
				'Datos de geolocalización detectados'
			);
		}
		if (estado.xattr) {
			agregarIndicadorArticulo(
				contenedor,
				'indicador-xattr',
				'ℹ️',
				'Origen del archivo registrado por el sistema'
			);
		}
		if (estado.duplicado) {
			agregarIndicadorArticulo(
				contenedor,
				'indicador-duplicado',
				'🔁',
				'Palabras clave duplicadas; guardar para corregir'
			);
		}
		if (estado.sugeridos > 0) {
			agregarIndicadorArticulo(
				contenedor,
				'indicador-sugerido',
				'💡',
				panel
					? `${estado.sugeridos} campo${estado.sugeridos === 1 ? '' : 's'} sugerido${estado.sugeridos === 1 ? '' : 's'}`
					: 'Campos sugeridos detectados'
			);
		}
		if (estado.advertencias > 0) {
			agregarIndicadorArticulo(
				contenedor,
				'indicador-advertencia',
				'⚠️',
				panel
					? `${estado.advertencias} advertencia${estado.advertencias === 1 ? '' : 's'} de metadatos`
					: 'Advertencia de metadatos detectada'
			);
		}
		if (!contenedor.children.length) {
			contenedor.remove();
		}
	}

	function prepararFormularioPanel(formulario) {
		if (!formulario || formulario.dataset.formularioPreparado === '1') return;
		formulario.dataset.formularioPreparado = '1';

		const formId = formulario.id.replace(/^[^_]+_/, '');
		const botonGuardar = formulario.querySelector(`#btn_${formId}`);
		const inputs = formulario.querySelectorAll('input, textarea');
		const select = formulario.querySelector(`#orientacion_${formId}`);
		const make = formulario.querySelector(`#make_${formId}`);
		const model = formulario.querySelector(`#model_${formId}`);
		if (!botonGuardar) return;

		function valorExisteEnDatalist(input) {
			const listaId = input?.getAttribute('list');
			const valor = input?.value.trim();
			if (!listaId || !valor) return false;
			const lista = document.getElementById(listaId);
			if (!lista) return false;
			return Array.from(lista.options).some(opcion => opcion.value === valor);
		}

		function autocompletarMakeDesdeModel() {
			if (!make || !model) return;
			const makeVacio = make.value.trim() === '';
			const modelPartioVacio = model.dataset.autocompletarMakePermitido === '1';
			if (makeVacio && modelPartioVacio && valorExisteEnDatalist(model)) {
				make.value = 'Red social';
				model.dataset.autocompletarMakePermitido = '0';
			}
			if (model.value.trim() === '') {
				model.dataset.autocompletarMakePermitido = '1';
			}
		}

		if (model) {
			model.dataset.autocompletarMakePermitido = model.value.trim() === '' ? '1' : '0';
			model.addEventListener('focus', () => {
				model.dataset.autocompletarMakePermitido = model.value.trim() === '' ? '1' : '0';
			});
			model.addEventListener('input', autocompletarMakeDesdeModel);
			model.addEventListener('change', autocompletarMakeDesdeModel);
		}

		inputs.forEach(input => {
			input.addEventListener('input', () => {
				botonGuardar.disabled = false;
			});
		});
		if (select) {
			select.addEventListener('change', () => {
				botonGuardar.disabled = false;
			});
		}
	}

	function registrarPanelArticulo(panel) {
		if (!panel) return;
		panel.hidden = true;
		if (panelDestino && panel.parentElement !== panelDestino) {
			panelDestino.appendChild(panel);
		}
		prepararFormularioPanel(panel);
	}

	function registrarArticulo(articulo) {
		if (!articulo || articulo.dataset.articuloPreparado === '1') return;
		articulo.dataset.articuloPreparado = '1';
		sincronizarIndicadoresArticulo(articulo);
		articulo.addEventListener('click', function () {
			mostrarPanelArticulo(articulo);
		});
		articulo.addEventListener('keydown', function (ev) {
			if (ev.target !== articulo) return;
			if (ev.key === 'Enter' || ev.key === ' ') {
				ev.preventDefault();
				mostrarPanelArticulo(articulo);
			}
		});
	}

	function tipoMensajeDetalle(html) {
		if (!html) return 'info';
		const plantilla = document.createElement('template');
		plantilla.innerHTML = String(html);
		if (plantilla.content.querySelector('.panel-detalle-mensaje')) return '';
		if (plantilla.content.querySelector('.respuesta_error')) return 'error';
		if (plantilla.content.querySelector('.errores-lote')) return 'advertencia';
		const texto = (plantilla.content.textContent || '').toLowerCase();
		if (texto.includes('no se pudo') || texto.includes('error del servidor')) return 'error';
		if (texto.includes('errores') || texto.includes('advertencia')) return 'advertencia';
		if (texto.includes('error')) return 'error';
		return 'exito';
	}

	function formatearMensajeDetalle(html) {
		const esPlaceholder = !html;
		const contenido = html || 'Selecciona un archivo para ver su información';
		const tipo = esPlaceholder ? 'info' : tipoMensajeDetalle(contenido);
		if (tipo === '') return contenido;
		const iconos = {
			exito: '✓',
			error: '!',
			advertencia: '!',
			info: 'i'
		};
		return (
			`<div class="panel-detalle-mensaje panel-detalle-mensaje-${tipo}" role="status" aria-live="polite">` +
				`<span class="panel-detalle-mensaje-icono" aria-hidden="true">${iconos[tipo] || 'i'}</span>` +
				`<div class="panel-detalle-mensaje-cuerpo">${contenido}</div>` +
			'</div>'
		);
	}

	function mostrarMensajeDetalle(html) {
		document.querySelectorAll('.panel-articulo[id^="pie_"]').forEach(panel => {
			panel.hidden = true;
		});
		document.querySelectorAll('main article[data-panel-id]').forEach(articulo => {
			articulo.classList.remove('activo');
		});
		if (placeholderPanel) {
			placeholderPanel.hidden = false;
			placeholderPanel.innerHTML = formatearMensajeDetalle(html);
		}
	}

	function insertarBloqueHtml(html, despuesDeArticuloId = '') {
		const plantilla = document.createElement('template');
		plantilla.innerHTML = html.trim();
		const articulo = plantilla.content.querySelector('article[data-panel-id]');
		const panel = plantilla.content.querySelector('.panel-articulo[id^="pie_"]');
		const main = document.querySelector('main');
		if (!articulo || !main) return null;

		const ruta = articulo.querySelector('[data-ruta]')?.dataset.ruta || '';
		if (ruta && rutaYaVisibleEnMiniaturas(ruta)) return null;

		main.querySelector('.panel-detalle-placeholder')?.remove();
		const referencia = despuesDeArticuloId ? document.getElementById(despuesDeArticuloId) : null;
		if (referencia && referencia.parentElement === main) {
			referencia.after(articulo);
		} else {
			main.prepend(articulo);
		}
		if (panel) {
			registrarPanelArticulo(panel);
		}
		registrarArticulo(articulo);
		return articulo;
	}

	function removerBloqueArticulo(id, mensajeHtml = '') {
		const articulo = document.getElementById('art_'+id);
		const panel = document.getElementById('pie_'+id);
		const estabaActivo = Boolean(articulo?.classList.contains('activo') || (panel && !panel.hidden));

		articulo?.remove();
		panel?.remove();
		actualizarBarraSeleccion();
		actualizarBarraSeleccionYandex();

		if (estabaActivo) {
			mostrarMensajeDetalle(mensajeHtml);
		}

		if (!document.querySelector('main article[data-panel-id]')) {
			const main = document.querySelector('main');
			if (main) {
				main.innerHTML = '<p class="panel-detalle-placeholder">No hay archivos en esta vista.</p>';
			}
		}
	}

	window.DAM = {
		...(window.DAM || {}),
		mostrarPanelArticulo,
		sincronizarIndicadoresArticulo,
		prepararFormularioPanel,
		insertarBloqueHtml,
		removerBloqueArticulo,
		mostrarMensajeDetalle
	};

	paneles.forEach(panel => {
		registrarPanelArticulo(panel);
	});

	articulos.forEach(articulo => {
		registrarArticulo(articulo);
	});
	actualizarBarraSeleccionYandex();

	// Selecciona todos los contenedores tipo 'form_N'
	const formularios = document.querySelectorAll('[id^="pie_"]');

	formularios.forEach(formulario => {
		prepararFormularioPanel(formulario);
	});
});

// Simple lightbox for dedicated media buttons.
function inicializarLightboxGlobal() {
	if (window.__damLightboxInicializado) return;
	window.__damLightboxInicializado = true;
	let lightboxIndex = -1;
	let lightboxListaPersonalizada = null;

	function obtenerDisparadoresLightbox() {
		if (Array.isArray(lightboxListaPersonalizada)) {
			return lightboxListaPersonalizada;
		}
		return Array.from(document.querySelectorAll('main .abrir-lightbox'));
	}

	function createOverlay() {
		const overlay = document.createElement('div');
		overlay.id = 'lightbox-overlay';
		overlay.innerHTML = '<button id="lightbox-close" aria-label="Cerrar">✕</button><button type="button" id="lightbox-prev" class="lightbox-nav lightbox-prev" aria-label="Anterior">‹</button><button type="button" id="lightbox-next" class="lightbox-nav lightbox-next" aria-label="Siguiente">›</button><div id="lightbox-status" aria-live="polite"></div><div id="lightbox-content" tabindex="-1"></div>';
		document.body.appendChild(overlay);

		// Close when clicking on overlay background (not on content or close button)
		overlay.addEventListener('click', function (ev) {
			// Close if click target is the overlay itself OR if it's the close button
			if (ev.target === overlay || ev.target.id === 'lightbox-close') {
				closeLightbox();
			}
		});

		// Prevent clicks on content from closing (stop propagation)
		const content = overlay.querySelector('#lightbox-content');
		content.addEventListener('click', function (ev) {
			closeLightbox();
			ev.stopPropagation();
		});

		// Prevent clicks on close button from propagating to overlay
		const closeBtn = overlay.querySelector('#lightbox-close');
		closeBtn.addEventListener('click', function (ev) {
			closeLightbox();
			ev.stopPropagation();
		});

		const prevBtn = overlay.querySelector('#lightbox-prev');
		prevBtn.addEventListener('click', function (ev) {
			ev.preventDefault();
			ev.stopPropagation();
			navegarLightbox(-1);
		});

		const nextBtn = overlay.querySelector('#lightbox-next');
		nextBtn.addEventListener('click', function (ev) {
			ev.preventDefault();
			ev.stopPropagation();
			navegarLightbox(1);
		});

		return overlay;
	}

	function clonarSvgRegiones(disparador) {
		const svg = disparador?.closest('article')?.querySelector('.svg_regiones');
		if (!svg) return null;

		const clon = svg.cloneNode(true);
		clon.classList.add('lightbox-regiones');
		clon.querySelectorAll('[id]').forEach(elemento => elemento.removeAttribute('id'));
		return clon;
	}

	function clonarIndicadoresLightbox(disparador) {
		const indicadores = disparador?.closest('article')?.querySelectorAll('.indicador-articulo');
		if (!indicadores || !indicadores.length) return null;

		const contenedor = document.createElement('div');
		contenedor.className = 'lightbox-indicadores';
		indicadores.forEach(indicador => {
			const clon = indicador.cloneNode(true);
			clon.classList.add('lightbox-indicador');
			contenedor.appendChild(clon);
		});
		return contenedor;
	}

	function agregarCapasLightbox(frame, disparador) {
		const regiones = clonarSvgRegiones(disparador);
		const indicadores = clonarIndicadoresLightbox(disparador);
		if (regiones) frame.appendChild(regiones);
		if (indicadores) frame.appendChild(indicadores);
	}

	function openLightbox(href, type, index, disparador = null) {
		let overlay = document.getElementById('lightbox-overlay');
		if (!overlay) overlay = createOverlay();
		const overlayAbierto = overlay.style.display && overlay.style.display !== 'none';
		const content = document.getElementById('lightbox-content');
		content.innerHTML = '';
		content.classList.remove('is-full');
		content.focus();
		lightboxIndex = Number.isInteger(index) ? index : lightboxIndex;
		const frame = document.createElement('div');
		frame.className = 'lightbox-media-frame';
		frame.addEventListener('click', function (ev) {
			ev.stopPropagation();
		});

		if (type === 'video') {
			const video = document.createElement('video');
			video.autoplay = true;
			video.controls = true;
			video.loop = true;
			video.muted = true;
			video.playsInline = true;
			video.src = href;
			video.className = 'lightbox-media';
			frame.appendChild(video);
			agregarCapasLightbox(frame, disparador);
			content.appendChild(frame);
			video.play().catch(()=>{});
			video.addEventListener('click', function (ev) {
				ev.stopPropagation();
			});
			} else {
				const img = document.createElement('img');
				img.className = 'lightbox-media contained';
				img.alt = '';
				const spinner = document.createElement('div');
				spinner.className = 'lightbox-cargando';
				spinner.setAttribute('role', 'status');
				spinner.setAttribute('aria-label', 'Cargando imagen');
				spinner.innerHTML = '<span></span>';
				frame.classList.add('is-loading');
				// Load full-resolution image then decide toggle availability
				const preload = new Image();
				preload.onload = function () {
					img.src = preload.src;
					img.dataset.nw = preload.naturalWidth;
					img.dataset.nh = preload.naturalHeight;
					frame.classList.remove('is-loading');
					spinner.remove();
				};
				preload.onerror = function () {
					frame.classList.remove('is-loading');
					spinner.classList.add('lightbox-cargando-error');
					spinner.removeAttribute('aria-label');
					spinner.textContent = 'No se pudo cargar la imagen';
				};
				preload.src = href;
				frame.appendChild(spinner);
				frame.appendChild(img);
				agregarCapasLightbox(frame, disparador);
				content.appendChild(frame);

			// Prevent clicks on image from closing the lightbox
			img.addEventListener('click', function (ev) {
				ev.stopPropagation();
			});

			// Drag / pan support variables
			let isDragging = false;
			let dragMoved = false;
			let startX = 0, startY = 0, startScrollLeft = 0, startScrollTop = 0;

			img.addEventListener('pointerdown', function (e) {
				if (!img.classList.contains('full')) return;
				img.setPointerCapture(e.pointerId);
				isDragging = true;
				dragMoved = false;
				startX = e.clientX;
				startY = e.clientY;
				startScrollLeft = content.scrollLeft;
				startScrollTop = content.scrollTop;
				img.classList.add('grabbing');
			});

			img.addEventListener('pointermove', function (e) {
				if (!isDragging) return;
				const dx = e.clientX - startX;
				const dy = e.clientY - startY;
				if (Math.abs(dx) > 3 || Math.abs(dy) > 3) dragMoved = true;
				// invert movement so dragging image moves in the direction of pointer
				content.scrollLeft = startScrollLeft - dx;
				content.scrollTop = startScrollTop - dy;
			});

			function endDrag(e) {
				if (!isDragging) return;
				try { img.releasePointerCapture && img.releasePointerCapture(e.pointerId); } catch (err) {}
				isDragging = false;
				setTimeout(() => { dragMoved = false; }, 50);
				img.classList.remove('grabbing');
			}

			img.addEventListener('pointerup', endDrag);
			img.addEventListener('pointercancel', endDrag);

			img.addEventListener('click', function (ev) {
				// if user was panning, ignore this click
				if (dragMoved) { ev.preventDefault(); return; }
				const nw = parseInt(img.dataset.nw || '0', 10);
				const nh = parseInt(img.dataset.nh || '0', 10);
				const vpw = content.clientWidth || window.innerWidth;
				const vph = content.clientHeight || window.innerHeight;
				//if ((nw > vpw || nh > vph)) {
					const nowFull = !img.classList.contains('full');
					img.classList.toggle('full');
					img.classList.toggle('contained');
					// mark content so CSS can align items to start when image is full
					content.classList.toggle('is-full', nowFull);
					// when entering full, center the image so user can pan in any direction
					if (nowFull) {
						// wait a couple frames so image layout and scroll sizes stabilize
						requestAnimationFrame(() => {
							requestAnimationFrame(() => {
								const scrollLeft = Math.max(0, Math.floor((content.scrollWidth - content.clientWidth) / 2));
								const scrollTop = Math.max(0, Math.floor((content.scrollHeight - content.clientHeight) / 2));
								content.scrollLeft = scrollLeft;
								content.scrollTop = scrollTop;
							});
						});
					}
				//}
			});
		}

		overlay.style.display = 'flex';
		actualizarControlesLightbox();

		if (!overlayAbierto) {
			// prevent background scroll and events
			overlay._prevBodyOverflow = document.body.style.overflow || '';
			document.body.style.overflow = 'hidden';

			// block outside events (capture phase). Allow events that originate inside overlay.
			function blockOutside(e) {
				try {
					if (!overlay) return;
					// for keydown allow Escape and allow if focus is inside overlay
					if (e.type === 'keydown') {
						if (e.key === 'Escape') return;
						if (document.activeElement && overlay.contains(document.activeElement)) return;
					}
					if (e.target && overlay.contains(e.target)) return;
					e.stopImmediatePropagation();
					e.preventDefault();
				} catch (err) {
					// ignore
				}
			}
			const eventsToBlock = ['click','pointerdown','mousedown','touchstart','wheel','keydown'];
			overlay._blocked = [];
			eventsToBlock.forEach(evName => {
				document.addEventListener(evName, blockOutside, true);
				overlay._blocked.push({evName, handler: blockOutside});
			});

			function lightboxKeyHandler(e) {
				if (e.key === 'Escape') {
					closeLightbox();
				} else if (e.key === 'ArrowLeft') {
					e.preventDefault();
					navegarLightbox(-1);
				} else if (e.key === 'ArrowRight') {
					e.preventDefault();
					navegarLightbox(1);
				}
			}
			document.addEventListener('keydown', lightboxKeyHandler);
			overlay._lightboxKeyHandler = lightboxKeyHandler;
		}
	}

	function closeLightbox() {
		const overlay = document.getElementById('lightbox-overlay');
		if (!overlay) return;
		if (overlay._lightboxKeyHandler) document.removeEventListener('keydown', overlay._lightboxKeyHandler);
		// remove capture blockers
		if (overlay._blocked && Array.isArray(overlay._blocked)) {
			overlay._blocked.forEach(item => {
				document.removeEventListener(item.evName, item.handler, true);
			});
		}
		// restore body scroll
		if (typeof overlay._prevBodyOverflow !== 'undefined') document.body.style.overflow = overlay._prevBodyOverflow;
		overlay.style.display = 'none';
		const content = document.getElementById('lightbox-content');
		if (content) content.innerHTML = '';
		lightboxIndex = -1;
		lightboxListaPersonalizada = null;
	}

	function resolverTipoLightbox(href, tipoDeclarado) {
		if (tipoDeclarado === 'video' || tipoDeclarado === 'image') {
			return tipoDeclarado;
		}

		const extension = href.split('.').pop().toLowerCase().split(/[#?]/)[0];
		const videoExts = ['mp4','webm','ogg','mov'];
		return videoExts.includes(extension) ? 'video' : 'image';
	}

	function abrirDesdeDisparador(disparador) {
		const dataset = disparador?.dataset || {};
		const href = dataset.lightboxHref || disparador?.href || disparador?.href || '';
		if (!href) return;

		const disparadores = obtenerDisparadoresLightbox();
		const index = disparadores.indexOf(disparador);
		const tipoDeclarado = dataset.lightboxTipo || dataset.tipo || disparador?.type;
		const tipo = resolverTipoLightbox(href, tipoDeclarado);
		const disparadorCapas = disparador instanceof Element
			? disparador
			: (disparador?.source instanceof Element ? disparador.source : null);
		openLightbox(href, tipo, index, disparadorCapas);
	}

	function actualizarControlesLightbox() {
		const overlay = document.getElementById('lightbox-overlay');
		if (!overlay) return;

		const disparadores = obtenerDisparadoresLightbox();
		const total = disparadores.length;
		const prevBtn = overlay.querySelector('#lightbox-prev');
		const nextBtn = overlay.querySelector('#lightbox-next');
		const estado = overlay.querySelector('#lightbox-status');
		const usaListaPersonalizada = Array.isArray(lightboxListaPersonalizada);
		const hayAnteriorPagina = !usaListaPersonalizada && Boolean(document.querySelector('a[title="Anterior"]'));
		const haySiguientePagina = !usaListaPersonalizada && Boolean(document.querySelector('a[title="Siguiente"]'));

		if (prevBtn) {
			prevBtn.disabled = !(lightboxIndex > 0 || hayAnteriorPagina);
		}
		if (nextBtn) {
			nextBtn.disabled = !(lightboxIndex >= 0 && lightboxIndex < total - 1 || haySiguientePagina);
		}
		if (estado) {
			estado.textContent = total && lightboxIndex >= 0 ? `${lightboxIndex + 1} / ${total}` : '';
		}
	}

	function navegarLightbox(direccion) {
		const disparadores = obtenerDisparadoresLightbox();
		if (!disparadores.length) return;

		if (lightboxIndex < 0) {
			lightboxIndex = 0;
		}

		const nuevoIndex = lightboxIndex + direccion;
		if (nuevoIndex >= 0 && nuevoIndex < disparadores.length) {
			abrirDesdeDisparador(disparadores[nuevoIndex]);
			return;
		}
		if (Array.isArray(lightboxListaPersonalizada)) {
			return;
		}

		const enlacePagina = direccion > 0
			? document.querySelector('a[title="Siguiente"]')
			: document.querySelector('a[title="Anterior"]');

		if (enlacePagina) {
			try {
				sessionStorage.setItem('damLightboxAutoOpen', direccion > 0 ? 'first' : 'last');
			} catch (err) {
				// Navega aunque no se pueda conservar la intención de reabrir el lightbox.
			}
			mostrarCargaNavegacion('Cargando página');
			window.location.href = enlacePagina.href;
		}
	}

	document.addEventListener('click', function (ev) {
		const disparador = ev.target.closest?.('.abrir-lightbox');
		if (!disparador || !document.querySelector('main')?.contains(disparador)) return;
			ev.preventDefault();
			ev.stopPropagation();
			abrirDesdeDisparador(disparador);
	}, true);

	window.abrirLightboxPersonalizado = function (elementos, indiceInicial = 0) {
		if (!Array.isArray(elementos) || !elementos.length) return;
		lightboxListaPersonalizada = elementos
			.map(elemento => ({
				href: elemento.href || '',
				type: elemento.type || elemento.tipo || '',
				source: elemento.source || null,
				title: elemento.title || ''
			}))
			.filter(elemento => elemento.href !== '');
		if (!lightboxListaPersonalizada.length) return;
		const indice = Math.max(0, Math.min(Number(indiceInicial) || 0, lightboxListaPersonalizada.length - 1));
		abrirDesdeDisparador(lightboxListaPersonalizada[indice]);
	};

	try {
		const disparadores = obtenerDisparadoresLightbox();
		const autoOpen = sessionStorage.getItem('damLightboxAutoOpen');
		if (autoOpen === 'first' || autoOpen === 'last') {
			sessionStorage.removeItem('damLightboxAutoOpen');
			const disparador = autoOpen === 'last' ? disparadores[disparadores.length - 1] : disparadores[0];
			if (disparador) {
				requestAnimationFrame(() => abrirDesdeDisparador(disparador));
			}
		}
	} catch (err) {
		// Sin sessionStorage, la paginación sigue funcionando sin reapertura automática.
	}

}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', inicializarLightboxGlobal);
} else {
	inicializarLightboxGlobal();
}


// Navegación por teclado
document.addEventListener('keydown', function(e) {
	// ignore global shortcuts while lightbox is open
	const overlayCheck = document.getElementById('lightbox-overlay');
	if (overlayCheck && overlayCheck.style.display && overlayCheck.style.display !== 'none') return;

	const tag = document.activeElement.tagName.toLowerCase();
	if (tag === 'input' || tag === 'textarea' || document.activeElement.isContentEditable) {
		return;
	}

	const inicio    = document.querySelector('a[title="Inicio"]');
	const anterior  = document.querySelector('a[title="Anterior"]');
	const siguiente = document.querySelector('a[title="Siguiente"]');
	const ultima    = document.querySelector('a[title="Última página"]');

	if (e.key === 'ArrowLeft') {
		if (e.shiftKey && inicio) {
			mostrarCargaNavegacion('Cargando página');
			window.location.href = inicio.href;
		} else if (anterior) {
			mostrarCargaNavegacion('Cargando página');
			window.location.href = anterior.href;
		}
	}

	if (e.key === 'ArrowRight') {
		if (e.shiftKey && ultima) {
			mostrarCargaNavegacion('Cargando página');
			window.location.href = ultima.href;
		} else if (siguiente) {
			mostrarCargaNavegacion('Cargando página');
			window.location.href = siguiente.href;
		}
	}
});
