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
	const formularioPaginacion = document.querySelector('.col-contenido > .paginación.condensado form')
		|| document.querySelector('.paginación form');
	const controlPagina = formularioPaginacion?.querySelector('select[name="pagina"]');
	const controlVer = formularioPaginacion?.querySelector('input[name="ver"]');
	const controlMedia = formularioPaginacion?.querySelector('[name="media"]');
	const controlRuta = formularioPaginacion?.querySelector('input[name="ruta"]');
	const pagina = parseInt(params.get('pagina') || controlPagina?.value || '1', 10);
	const ver = parseInt(params.get('ver') || controlVer?.value || '6', 10);
	const filtros = {};
	['geo', 'regiones', 'rotacion', 'palabras', 'sugerencias', 'duplicadas', 'tracking'].forEach(clave => {
		filtros[clave] = params.get(clave) || '';
	});

	return {
		pagina: Number.isFinite(pagina) && pagina > 0 ? pagina : 1,
		ver: Number.isFinite(ver) && ver > 0 ? ver : 6,
		media: params.get('media') || controlMedia?.value || '',
		ruta: params.get('ruta') || controlRuta?.value || '',
		archivo: params.get('archivo') || '',
		palabra_clave: params.get('palabra_clave') || '',
		...filtros
	};
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

function redirigirARaizCarpetas(){
	mostrarCargaNavegacion('Volviendo a la raíz');
	window.location.href = urlRaizCarpetas();
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
	if (window.DAM && window.DAM.removerBloqueArticulo) {
		window.DAM.removerBloqueArticulo(id, mensajeHtml);
		rellenarVistaHastaCompleta();
		return;
	}

	document.getElementById('art_'+id)?.remove();
	document.getElementById('pie_'+id)?.remove();
	rellenarVistaHastaCompleta();
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

	document.addEventListener('click', function (ev) {
		if (ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
		const enlace = ev.target.closest?.('.paginación a, .palabra-clave-link');
		if (!enlace || !enlace.href) return;
		if (enlace.classList.contains('palabra-clave-link')) {
			escribirStorageSesion(
				claveFocoPalabraClave,
				enlace.dataset.palabraBusqueda || enlace.dataset.palabra || ''
			);
		}
		mostrarCargaNavegacion('Cargando página');
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
		let modalMoverLote = null;
		let modalCopiarYandex = null;
		let carpetasLocalesProyecto = null;
		let operacionLoteEnCurso = false;

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
				'<button type="button" data-accion-lote="mover">Mover</button>' +
				'<button type="button" data-accion-lote="archivar">Archivar</button>' +
				'<button type="button" data-accion-lote="borrar">Descartar</button>' +
				'<button type="button" data-accion-lote="limpiar">Limpiar</button>' +
			'</div>';

		barraSeleccion.addEventListener('click', function (ev) {
			const boton = ev.target.closest?.('[data-accion-lote]');
			if (!boton || operacionLoteEnCurso) return;
			const accion = boton.dataset.accionLote;
			if (accion === 'mover') {
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
					vista_ruta: parametros.ruta,
					vista_archivo: parametros.archivo
				})
			});
			const datos = await respuesta.json().catch(() => null);
			if (!respuesta.ok || !datos) {
				throw new Error(datos?.mensaje || `HTTP ${respuesta.status}`);
			}
			if (datos.redirect_raiz) {
				redirigiendo = true;
				redirigirARaizCarpetas();
				return true;
			}

			const rutasProcesadas = new Set(
				(datos.resultados || [])
					.filter(resultado => resultado.ok)
					.map(resultado => normalizarRutaVista(resultado.origen))
			);
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
		} catch (err) {
			mostrarMensajeDetalle(`<p class="respuesta_error">${escaparHtmlCliente(err.message || 'No se pudo enviar a la papelera de Yandex Disk.')}</p>`);
			boton.disabled = false;
			boton.removeAttribute('aria-busy');
		} finally {
			ocultarCargaNavegacion();
		}
	}

	function mostrarPanelArticulo(articulo) {
		if (!articulo) return;
		const panelId = articulo.dataset.panelId;
		const panel = document.getElementById(panelId);
		if (!panel) return;

		document.querySelectorAll('main article[data-panel-id]').forEach(item => item.classList.toggle('activo', item === articulo));
		document.querySelectorAll('.panel-articulo[id^="pie_"]').forEach(item => {
			item.hidden = item !== panel;
		});

		if (placeholderPanel) {
			placeholderPanel.hidden = true;
		}
	}

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

	function agregarIndicadorArticulo(contenedor, clase, icono, etiqueta) {
		const indicador = document.createElement('span');
		indicador.className = `indicador-articulo ${clase}`;
		indicador.setAttribute('role', 'img');
		indicador.setAttribute('aria-label', etiqueta);
		indicador.title = etiqueta;
		indicador.textContent = icono;
		contenedor.appendChild(indicador);
	}

	function sincronizarIndicadoresArticulo(articulo) {
		const panel = document.getElementById(articulo.dataset.panelId);
		const figure = articulo.querySelector('figure');
		if (!panel || !figure) return;

		insertarControlSeleccion(articulo);
		let contenedor = figure.querySelector('.indicadores-articulo');
		if (!contenedor) {
			contenedor = document.createElement('div');
			contenedor.className = 'indicadores-articulo';
			figure.appendChild(contenedor);
		}
		contenedor.innerHTML = '';

		const sugeridos = panel.querySelectorAll('.sugerido').length;
		const advertencias = panel.querySelectorAll('.advertencia-metadatos').length;
		const metadatosIA = panel.dataset.metadatosIa === '1' || panel.querySelector('.metadata-ia');
		const geolocalizacion = panel.dataset.geolocalizacion === '1' || panel.querySelector('.metadata-geo');
		const origenSistema = panel.querySelector('.metadata-xattr');
		const palabrasDuplicadas = panel.dataset.palabrasDuplicadas === '1' || panel.querySelector('.requiere-guardar');

		if (metadatosIA) {
			agregarIndicadorArticulo(
				contenedor,
				'indicador-ia',
				'🤖',
				'Metadatos de IA detectados'
			);
		}
		if (geolocalizacion) {
			agregarIndicadorArticulo(
				contenedor,
				'indicador-geo',
				'📍',
				'Datos de geolocalización detectados'
			);
		}
		if (origenSistema) {
			agregarIndicadorArticulo(
				contenedor,
				'indicador-xattr',
				'ℹ️',
				'Origen del archivo registrado por el sistema'
			);
		}
		if (palabrasDuplicadas) {
			agregarIndicadorArticulo(
				contenedor,
				'indicador-duplicado',
				'🔁',
				'Palabras clave duplicadas; guardar para corregir'
			);
		}
		if (sugeridos > 0) {
			agregarIndicadorArticulo(
				contenedor,
				'indicador-sugerido',
				'💡',
				`${sugeridos} campo${sugeridos === 1 ? '' : 's'} sugerido${sugeridos === 1 ? '' : 's'}`
			);
		}
		if (advertencias > 0) {
			agregarIndicadorArticulo(
				contenedor,
				'indicador-advertencia',
				'⚠️',
				`${advertencias} advertencia${advertencias === 1 ? '' : 's'} de metadatos`
			);
		}
	}

	function prepararFormularioPanel(formulario) {
		if (!formulario || formulario.dataset.formularioPreparado === '1') return;
		formulario.dataset.formularioPreparado = '1';

		const formId = formulario.id.split('_')[1]; // Extrae el número, ej. '2'
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

	function mostrarMensajeDetalle(html) {
		document.querySelectorAll('.panel-articulo[id^="pie_"]').forEach(panel => {
			panel.hidden = true;
		});
		document.querySelectorAll('main article[data-panel-id]').forEach(articulo => {
			articulo.classList.remove('activo');
		});
		if (placeholderPanel) {
			placeholderPanel.hidden = false;
			placeholderPanel.innerHTML = html || 'Selecciona un archivo';
		}
	}

	function insertarBloqueHtml(html, despuesDeArticuloId = '') {
		const plantilla = document.createElement('template');
		plantilla.innerHTML = html.trim();
		const articulo = plantilla.content.querySelector('article[data-panel-id]');
		const panel = plantilla.content.querySelector('.panel-articulo[id^="pie_"]');
		const main = document.querySelector('main');
		if (!articulo || !panel || !main) return null;

		const ruta = articulo.querySelector('[data-ruta]')?.dataset.ruta || '';
		if (ruta && rutaYaVisibleEnMiniaturas(ruta)) return null;

		main.querySelector('.panel-detalle-placeholder')?.remove();
		const referencia = despuesDeArticuloId ? document.getElementById(despuesDeArticuloId) : null;
		if (referencia && referencia.parentElement === main) {
			referencia.after(articulo);
		} else {
			main.prepend(articulo);
		}
		registrarPanelArticulo(panel);
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

	// Selecciona todos los contenedores tipo 'form_N'
	const formularios = document.querySelectorAll('[id^="pie_"]');

	formularios.forEach(formulario => {
		prepararFormularioPanel(formulario);
	});
});

// Simple lightbox for dedicated media buttons.
document.addEventListener('DOMContentLoaded', function () {
	let lightboxIndex = -1;

	function obtenerDisparadoresLightbox() {
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
			video.controls = true;
			video.loop = true;
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
		const href = disparador.dataset.lightboxHref || disparador.href;
		if (!href) return;

		const disparadores = obtenerDisparadoresLightbox();
		const index = disparadores.indexOf(disparador);
		const tipoDeclarado = disparador.dataset.lightboxTipo || disparador.dataset.tipo;
		const tipo = resolverTipoLightbox(href, tipoDeclarado);
		openLightbox(href, tipo, index, disparador);
	}

	function actualizarControlesLightbox() {
		const overlay = document.getElementById('lightbox-overlay');
		if (!overlay) return;

		const disparadores = obtenerDisparadoresLightbox();
		const total = disparadores.length;
		const prevBtn = overlay.querySelector('#lightbox-prev');
		const nextBtn = overlay.querySelector('#lightbox-next');
		const estado = overlay.querySelector('#lightbox-status');
		const hayAnteriorPagina = Boolean(document.querySelector('a[title="Anterior"]'));
		const haySiguientePagina = Boolean(document.querySelector('a[title="Siguiente"]'));

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

});


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
