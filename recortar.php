<?php
ini_set("pcre.jit", "0");
set_time_limit(300);

require_once "src/funciones.php";

function urlVolverRecorteValida(string $url): bool
{
	$url = trim($url);
	if ($url === ''):
		return false;
	endif;

	$partes = parse_url($url);
	if ($partes === false):
		return false;
	endif;

	$hostActual = $_SERVER['HTTP_HOST'] ?? '';
	if (isset($partes['host']) && $partes['host'] !== $hostActual):
		return false;
	endif;
	if (isset($partes['scheme']) && !in_array($partes['scheme'], ['http', 'https'], true)):
		return false;
	endif;

	$ruta = (string) ($partes['path'] ?? '');
	return strtolower(basename($ruta)) !== 'recortar.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'):
	$json = json_decode(file_get_contents('php://input'), true);
	if (!is_array($json)):
		http_response_code(400);
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode(['ok' => false, 'mensaje' => 'Solicitud inválida.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	endif;

	$ruta = resolverRutaProyecto($json['archivo'] ?? null, 'file', false);
	if ($ruta === null):
		http_response_code(400);
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode(['ok' => false, 'mensaje' => 'Archivo inválido.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	endif;

	$resultado = recortarImagenConRegiones($ruta, $json);
	if (!$resultado['ok']):
		http_response_code(400);
	endif;
	$resultado['archivo'] = rutaRelativaDesdeProyecto($ruta);
	$resultado['version'] = firmaCacheArchivo($ruta);
	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
endif;

$configuracion = cargarConfiguracion();
$archivo = $_GET['foto'] ?? $_GET['archivo'] ?? '';
$ruta = resolverRutaProyecto($archivo, 'file', false);
$archivoRelativo = $ruta ? rutaRelativaDesdeProyecto($ruta) : '';
$srcImagen = $archivoRelativo;
$dimensiones = [0, 0];
$firmaImagen = '';
$regionesTotal = 0;
$mensaje = '';

if ($ruta):
	$extension = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
	if (in_array($extension, ['heic', 'heif', 'avif', 'tif', 'tiff'], true)):
		$srcTemporal = generarJPGtemporal($archivoRelativo);
		if ($srcTemporal !== ''):
			$srcImagen = $srcTemporal;
		endif;
	endif;
	$dimensiones = getimagesize($ruta) ?: [0, 0];
	$firmaImagen = firmaCacheArchivo($ruta, $dimensiones);
	$metaRegiones = obtenerMetadatos($ruta, [
		'Orientation',
		'RegionAppliedToDimensionsW',
		'RegionAppliedToDimensionsH',
		'RegionAreaX',
		'RegionAreaY',
		'RegionAreaW',
		'RegionAreaH',
		'RegionAreaUnit',
		'RegionName',
		'RegionType'
	])['resultado'] ?? [];
	$regionesTotal = is_array($metaRegiones) ? count(obtenerRegionesVisualesDesdeMetadatos($ruta, $metaRegiones)) : 0;
	if (isset($_GET['recortado'])):
		$mensaje = 'Imagen recortada.';
	endif;
endif;

$volver = 'index.php';
if (isset($_GET['volver']) && urlVolverRecorteValida((string) $_GET['volver'])):
	$volver = (string) $_GET['volver'];
elseif (!empty($_SERVER['HTTP_REFERER'])):
	$referer = (string) $_SERVER['HTTP_REFERER'];
	if (urlVolverRecorteValida($referer)):
		$volver = $referer;
	endif;
endif;

?><!DOCTYPE html>
<html lang="es"<?php echo atributoTemaConfiguracion($configuracion); ?>>

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Recortar imagen</title>
	<link href="estilos.css?v=<?php echo filemtime('estilos.css'); ?>" rel="stylesheet">
</head>

<body class="editor-recorte">
	<header class="recorte-barra">
		<a href="<?php echo escaparHtml($volver); ?>" class="volver-link" title="Volver">←</a>
		<form method="get" class="recorte-buscador">
			<input type="hidden" name="volver" value="<?php echo escaparHtml($volver); ?>">
			<input type="text" name="foto" value="<?php echo escaparHtml($archivoRelativo ?: (string) $archivo); ?>" class="ruta-foto" autocomplete="off">
			<button type="submit">Ver</button>
		</form>
		<div id="estadoRecorteGlobal" class="recorte-estado-global"><?php echo escaparHtml($mensaje); ?></div>
	</header>

	<?php if (!$ruta): ?>
		<main class="recorte-vacio">
			<form method="get" class="recorte-selector">
				<label for="foto-recorte">Imagen</label>
				<input type="hidden" name="volver" value="<?php echo escaparHtml($volver); ?>">
				<input id="foto-recorte" type="text" name="foto" value="<?php echo escaparHtml((string) $archivo); ?>" autocomplete="off">
				<button type="submit">Abrir</button>
			</form>
		</main>
	<?php else: ?>
		<main class="recorte-main">
			<section class="recorte-area" aria-label="Editor de recorte">
				<div class="recorte-stage-wrap">
					<div id="recorteStage" class="recorte-stage">
							<img
								id="imagenRecorte"
								src="<?php echo escaparHtml(agregarCacheBuster($srcImagen, $firmaImagen)); ?>"
								alt=""
							draggable="false"
							data-archivo="<?php echo escaparHtml($archivoRelativo); ?>"
						>
						<div id="capaRecorte" class="recorte-layer">
							<div id="rectRecorte" class="recorte-box" hidden>
								<span id="tamanoRecorte" class="recorte-size"></span>
								<span class="recorte-handle recorte-handle-nw" data-handle="nw"></span>
								<span class="recorte-handle recorte-handle-n" data-handle="n"></span>
								<span class="recorte-handle recorte-handle-ne" data-handle="ne"></span>
								<span class="recorte-handle recorte-handle-e" data-handle="e"></span>
								<span class="recorte-handle recorte-handle-se" data-handle="se"></span>
								<span class="recorte-handle recorte-handle-s" data-handle="s"></span>
								<span class="recorte-handle recorte-handle-sw" data-handle="sw"></span>
								<span class="recorte-handle recorte-handle-w" data-handle="w"></span>
							</div>
						</div>
					</div>
				</div>
			</section>

			<aside class="recorte-panel">
				<h1>Recorte</h1>
				<dl class="recorte-datos">
					<div>
						<dt>Archivo</dt>
						<dd><?php echo escaparHtml(basename($archivoRelativo)); ?></dd>
					</div>
					<div>
						<dt>Original</dt>
						<dd><?php echo (int) ($dimensiones[0] ?? 0); ?>×<?php echo (int) ($dimensiones[1] ?? 0); ?> px</dd>
					</div>
					<div>
						<dt>Regiones</dt>
						<dd><?php echo (int) $regionesTotal; ?></dd>
					</div>
					<div>
						<dt>Selección</dt>
						<dd id="detalleSeleccion">-</dd>
					</div>
				</dl>
				<div id="accionesRecorte" class="recorte-acciones" hidden>
					<button type="button" id="botonRecortar">Recortar</button>
					<button type="button" id="botonCancelar">Cancelar</button>
				</div>
				<output id="estadoRecorte" class="recorte-estado"></output>
			</aside>
		</main>

		<script>
			(() => {
				const archivo = <?php echo json_encode($archivoRelativo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
				const volver = <?php echo json_encode($volver, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
				const imagen = document.getElementById('imagenRecorte');
				const capa = document.getElementById('capaRecorte');
				const rectElemento = document.getElementById('rectRecorte');
				const etiquetaTamano = document.getElementById('tamanoRecorte');
				const acciones = document.getElementById('accionesRecorte');
				const botonRecortar = document.getElementById('botonRecortar');
				const botonCancelar = document.getElementById('botonCancelar');
				const detalleSeleccion = document.getElementById('detalleSeleccion');
				const estado = document.getElementById('estadoRecorte');
				const estadoGlobal = document.getElementById('estadoRecorteGlobal');
				const minimo = 6;
				let caja = null;
				let cajaNatural = null;
				let modo = '';
				let handle = '';
				let puntoInicial = null;
				let cajaInicial = null;
				let pointerActivo = null;

				function clamp(valor, min, max) {
					return Math.max(min, Math.min(max, valor));
				}

				function rectCapa() {
					return capa.getBoundingClientRect();
				}

				function puntoEvento(evento) {
					const rect = rectCapa();
					return {
						x: clamp(evento.clientX - rect.left, 0, rect.width),
						y: clamp(evento.clientY - rect.top, 0, rect.height)
					};
				}

				function cajaDesdePuntos(a, b) {
					return {
						x: Math.min(a.x, b.x),
						y: Math.min(a.y, b.y),
						ancho: Math.abs(b.x - a.x),
						alto: Math.abs(b.y - a.y)
					};
				}

				function escalaNatural() {
					const rect = rectCapa();
					return {
						x: imagen.naturalWidth / rect.width,
						y: imagen.naturalHeight / rect.height
					};
				}

				function aNatural(box) {
					const escala = escalaNatural();
					const x = Math.round(box.x * escala.x);
					const y = Math.round(box.y * escala.y);
					const ancho = Math.round(box.ancho * escala.x);
					const alto = Math.round(box.alto * escala.y);
					return {
						x: clamp(x, 0, imagen.naturalWidth - 1),
						y: clamp(y, 0, imagen.naturalHeight - 1),
						ancho: clamp(ancho, 1, imagen.naturalWidth - x),
						alto: clamp(alto, 1, imagen.naturalHeight - y)
					};
				}

				function desdeNatural(box) {
					const rect = rectCapa();
					return {
						x: box.x * rect.width / imagen.naturalWidth,
						y: box.y * rect.height / imagen.naturalHeight,
						ancho: box.ancho * rect.width / imagen.naturalWidth,
						alto: box.alto * rect.height / imagen.naturalHeight
					};
				}

				function actualizarCaja() {
					if (!caja || caja.ancho < 1 || caja.alto < 1) {
						rectElemento.hidden = true;
						acciones.hidden = true;
						detalleSeleccion.textContent = '-';
						cajaNatural = null;
						return;
					}

					cajaNatural = aNatural(caja);
					rectElemento.hidden = false;
					rectElemento.style.transform = `translate(${caja.x}px, ${caja.y}px)`;
					rectElemento.style.width = `${caja.ancho}px`;
					rectElemento.style.height = `${caja.alto}px`;
					const texto = `${cajaNatural.ancho}×${cajaNatural.alto} px`;
					etiquetaTamano.textContent = texto;
					detalleSeleccion.textContent = texto;
					acciones.hidden = false;
				}

				function cancelarSeleccion() {
					caja = null;
					cajaNatural = null;
					modo = '';
					handle = '';
					actualizarCaja();
				}

				function redimensionarCaja(punto) {
					const rect = rectCapa();
					let izquierda = cajaInicial.x;
					let arriba = cajaInicial.y;
					let derecha = cajaInicial.x + cajaInicial.ancho;
					let abajo = cajaInicial.y + cajaInicial.alto;

					if (handle.includes('w')) izquierda = punto.x;
					if (handle.includes('e')) derecha = punto.x;
					if (handle.includes('n')) arriba = punto.y;
					if (handle.includes('s')) abajo = punto.y;

					if (Math.abs(derecha - izquierda) < minimo) {
						if (handle.includes('w')) izquierda = derecha - minimo;
						else derecha = izquierda + minimo;
					}
					if (Math.abs(abajo - arriba) < minimo) {
						if (handle.includes('n')) arriba = abajo - minimo;
						else abajo = arriba + minimo;
					}

					izquierda = clamp(izquierda, 0, rect.width);
					derecha = clamp(derecha, 0, rect.width);
					arriba = clamp(arriba, 0, rect.height);
					abajo = clamp(abajo, 0, rect.height);
					caja = cajaDesdePuntos({ x: izquierda, y: arriba }, { x: derecha, y: abajo });
				}

				function moverCaja(punto) {
					const rect = rectCapa();
					const dx = punto.x - puntoInicial.x;
					const dy = punto.y - puntoInicial.y;
					caja = {
						x: clamp(cajaInicial.x + dx, 0, rect.width - cajaInicial.ancho),
						y: clamp(cajaInicial.y + dy, 0, rect.height - cajaInicial.alto),
						ancho: cajaInicial.ancho,
						alto: cajaInicial.alto
					};
				}

				function distanciaRgb(data, indice, r, g, b) {
					return Math.abs(data[indice] - r) + Math.abs(data[indice + 1] - g) + Math.abs(data[indice + 2] - b);
				}

				function colorPromedio(data, ancho, alto, x1, y1, x2, y2) {
					const minX = clamp(Math.floor(x1), 0, ancho - 1);
					const maxX = clamp(Math.ceil(x2), 0, ancho - 1);
					const minY = clamp(Math.floor(y1), 0, alto - 1);
					const maxY = clamp(Math.ceil(y2), 0, alto - 1);
					let r = 0, g = 0, b = 0, total = 0;
					for (let y = minY; y <= maxY; y++) {
						for (let x = minX; x <= maxX; x++) {
							const indice = (y * ancho + x) * 4;
							r += data[indice];
							g += data[indice + 1];
							b += data[indice + 2];
							total++;
						}
					}
					return total > 0 ? [r / total, g / total, b / total] : [0, 0, 0];
				}

				function mediana(valores) {
					if (!valores.length) return 0;
					const ordenados = [...valores].sort((a, b) => a - b);
					return ordenados[Math.floor(ordenados.length / 2)];
				}

				function suavizar(valores, radio) {
					return valores.map((_, indice) => {
						const inicio = Math.max(0, indice - radio);
						const fin = Math.min(valores.length - 1, indice + radio);
						let suma = 0;
						for (let i = inicio; i <= fin; i++) suma += valores[i];
						return suma / (fin - inicio + 1);
					});
				}

				function puntajesFilas(data, ancho, alto) {
					const margen = Math.max(2, Math.round(ancho * 0.012));
					const paso = Math.max(1, Math.floor(ancho / 180));
					const puntajes = [];
					for (let y = 0; y < alto; y++) {
						const izquierda = colorPromedio(data, ancho, alto, 0, y, margen - 1, y);
						const derecha = colorPromedio(data, ancho, alto, ancho - margen, y, ancho - 1, y);
						let residuo = 0, textura = 0, total = 0, previo = null;
						for (let x = 0; x < ancho; x += paso) {
							const indice = (y * ancho + x) * 4;
							const t = ancho > 1 ? x / (ancho - 1) : 0;
							const r = izquierda[0] + (derecha[0] - izquierda[0]) * t;
							const g = izquierda[1] + (derecha[1] - izquierda[1]) * t;
							const b = izquierda[2] + (derecha[2] - izquierda[2]) * t;
							residuo += distanciaRgb(data, indice, r, g, b);
							if (previo) textura += distanciaRgb(data, indice, previo[0], previo[1], previo[2]);
							previo = [data[indice], data[indice + 1], data[indice + 2]];
							total++;
						}
						puntajes.push((residuo + textura * 0.8) / Math.max(1, total));
					}
					return puntajes;
				}

				function puntajesColumnas(data, ancho, alto) {
					const margen = Math.max(2, Math.round(alto * 0.012));
					const paso = Math.max(1, Math.floor(alto / 180));
					const puntajes = [];
					for (let x = 0; x < ancho; x++) {
						const arriba = colorPromedio(data, ancho, alto, x, 0, x, margen - 1);
						const abajo = colorPromedio(data, ancho, alto, x, alto - margen, x, alto - 1);
						let residuo = 0, textura = 0, total = 0, previo = null;
						for (let y = 0; y < alto; y += paso) {
							const indice = (y * ancho + x) * 4;
							const t = alto > 1 ? y / (alto - 1) : 0;
							const r = arriba[0] + (abajo[0] - arriba[0]) * t;
							const g = arriba[1] + (abajo[1] - arriba[1]) * t;
							const b = arriba[2] + (abajo[2] - arriba[2]) * t;
							residuo += distanciaRgb(data, indice, r, g, b);
							if (previo) textura += distanciaRgb(data, indice, previo[0], previo[1], previo[2]);
							previo = [data[indice], data[indice + 1], data[indice + 2]];
							total++;
						}
						puntajes.push((residuo + textura * 0.8) / Math.max(1, total));
					}
					return puntajes;
				}

				function detectarTramoCentral(puntajes) {
					const total = puntajes.length;
					if (total < 24) return null;
					const borde = Math.max(4, Math.floor(total * 0.08));
					const base = mediana([...puntajes.slice(0, borde), ...puntajes.slice(total - borde)]);
					const maximo = Math.max(...puntajes);
					const umbral = Math.max(base + 12, base * 2.35, maximo * 0.28);
					const activo = puntajes.map(valor => valor > umbral);
					const brechaMax = Math.max(2, Math.floor(total * 0.018));
					let inicioBrecha = -1;
					for (let i = 0; i < activo.length; i++) {
						if (activo[i]) {
							if (inicioBrecha >= 0 && i - inicioBrecha <= brechaMax) {
								for (let j = inicioBrecha; j < i; j++) activo[j] = true;
							}
							inicioBrecha = -1;
						} else if (inicioBrecha < 0) {
							inicioBrecha = i;
						}
					}

					const tramos = [];
					for (let i = 0; i < total; i++) {
						if (!activo[i]) continue;
						let fin = i;
						while (fin + 1 < total && activo[fin + 1]) fin++;
						const largo = fin - i + 1;
						if (largo >= total * 0.12) {
							const centro = (i + fin) / 2;
							const distanciaCentro = Math.abs(centro - total / 2) / total;
							tramos.push({ inicio: i, fin, valor: largo - distanciaCentro * total * 0.9 });
						}
						i = fin;
					}
					if (!tramos.length) return null;

					tramos.sort((a, b) => b.valor - a.valor);
					let { inicio, fin } = tramos[0];
					const umbralExpansion = Math.max(base + 6, base * 1.45);
					while (inicio > 0 && puntajes[inicio - 1] > umbralExpansion) inicio--;
					while (fin + 1 < total && puntajes[fin + 1] > umbralExpansion) fin++;

					const margenTotal = inicio + (total - 1 - fin);
					const margenMinimo = Math.max(8, total * 0.06);
					if (margenTotal < margenMinimo) return null;
					if (inicio < total * 0.015 && fin > total * 0.985) return null;

					return { inicio, fin };
				}

				function detectarRecorteFondo() {
					if (!imagen.naturalWidth || !imagen.naturalHeight) return null;
					const maximo = 420;
					const escala = Math.min(1, maximo / Math.max(imagen.naturalWidth, imagen.naturalHeight));
					const ancho = Math.max(24, Math.round(imagen.naturalWidth * escala));
					const alto = Math.max(24, Math.round(imagen.naturalHeight * escala));
					const canvas = document.createElement('canvas');
					canvas.width = ancho;
					canvas.height = alto;
					const ctx = canvas.getContext('2d', { willReadFrequently: true });
					if (!ctx) return null;
					ctx.drawImage(imagen, 0, 0, ancho, alto);
					let datos;
					try {
						datos = ctx.getImageData(0, 0, ancho, alto).data;
					} catch (error) {
						return null;
					}

					const aspecto = imagen.naturalWidth / imagen.naturalHeight;
					let box = { x: 0, y: 0, ancho: imagen.naturalWidth, alto: imagen.naturalHeight };
					let confianza = 0;

					if (aspecto < 0.82) {
						const tramo = detectarTramoCentral(suavizar(puntajesFilas(datos, ancho, alto), Math.max(2, Math.round(alto * 0.006))));
						if (tramo) {
							box.y = Math.max(0, Math.round(tramo.inicio / escala));
							const fin = Math.min(imagen.naturalHeight, Math.round((tramo.fin + 1) / escala));
							box.alto = fin - box.y;
							confianza++;
						}
					} else if (aspecto > 1.22) {
						const tramo = detectarTramoCentral(suavizar(puntajesColumnas(datos, ancho, alto), Math.max(2, Math.round(ancho * 0.006))));
						if (tramo) {
							box.x = Math.max(0, Math.round(tramo.inicio / escala));
							const fin = Math.min(imagen.naturalWidth, Math.round((tramo.fin + 1) / escala));
							box.ancho = fin - box.x;
							confianza++;
						}
					}

					if (!confianza || box.ancho < imagen.naturalWidth * 0.2 || box.alto < imagen.naturalHeight * 0.2) return null;
					const recorte = 1 - (box.ancho * box.alto) / (imagen.naturalWidth * imagen.naturalHeight);
					if (recorte < 0.06) return null;
					return box;
				}

				function aplicarSugerenciaAutomatica() {
					if (caja) return;
					const sugerencia = detectarRecorteFondo();
					if (!sugerencia) return;
					cajaNatural = sugerencia;
					caja = desdeNatural(cajaNatural);
					actualizarCaja();
					estado.textContent = 'Sugerencia automática.';
				}

				capa.addEventListener('pointerdown', (evento) => {
					if (evento.button !== 0) return;
					const punto = puntoEvento(evento);
					const objetivoHandle = evento.target.closest('[data-handle]');
					puntoInicial = punto;
					estado.textContent = '';

					if (objetivoHandle && caja) {
						modo = 'resize';
						handle = objetivoHandle.dataset.handle;
						cajaInicial = { ...caja };
					} else if (evento.target.closest('#rectRecorte') && caja) {
						modo = 'move';
						cajaInicial = { ...caja };
					} else {
						modo = 'draw';
						handle = '';
						cajaInicial = null;
						caja = { x: punto.x, y: punto.y, ancho: 0, alto: 0 };
					}

					pointerActivo = evento.pointerId;
					capa.setPointerCapture(pointerActivo);
					evento.preventDefault();
				});

				capa.addEventListener('pointermove', (evento) => {
					if (!modo) return;
					const punto = puntoEvento(evento);
					if (modo === 'draw') {
						caja = cajaDesdePuntos(puntoInicial, punto);
					} else if (modo === 'resize') {
						redimensionarCaja(punto);
					} else if (modo === 'move') {
						moverCaja(punto);
					}
					actualizarCaja();
				});

				function finalizarPointer(evento) {
					if (!modo) return;
					if (pointerActivo !== null && capa.hasPointerCapture(pointerActivo)) {
						capa.releasePointerCapture(pointerActivo);
					}
					if (!caja || caja.ancho < minimo || caja.alto < minimo) {
						cancelarSeleccion();
					} else {
						actualizarCaja();
					}
					pointerActivo = null;
					modo = '';
					handle = '';
					if (evento) evento.preventDefault();
				}

				capa.addEventListener('pointerup', finalizarPointer);
				capa.addEventListener('pointercancel', finalizarPointer);
				window.addEventListener('pointerup', finalizarPointer);

				botonCancelar.addEventListener('click', cancelarSeleccion);

				botonRecortar.addEventListener('click', async () => {
					if (!cajaNatural) return;
					botonRecortar.disabled = true;
					botonCancelar.disabled = true;
					estado.textContent = 'Recortando...';
					try {
						const respuesta = await fetch('recortar.php', {
							method: 'POST',
							headers: { 'Content-Type': 'application/json' },
							body: JSON.stringify({
								accion: 'recortar',
								archivo,
								x: cajaNatural.x,
								y: cajaNatural.y,
								ancho: cajaNatural.ancho,
								alto: cajaNatural.alto,
								origen_ancho: imagen.naturalWidth,
								origen_alto: imagen.naturalHeight
							})
						});
						const datos = await respuesta.json();
						if (!respuesta.ok || !datos.ok) {
							throw new Error(datos.mensaje || 'No se pudo recortar.');
						}
						estado.textContent = `${datos.mensaje} ${datos.ancho}×${datos.alto} px.`;
						estadoGlobal.textContent = estado.textContent;
						const url = new URL(window.location.href);
						url.searchParams.set('foto', archivo);
						url.searchParams.set('volver', volver);
						url.searchParams.set('recortado', '1');
						url.searchParams.set('v', String(datos.version || Date.now()));
						window.location.href = url.toString();
					} catch (error) {
						estado.textContent = error.message;
						botonRecortar.disabled = false;
						botonCancelar.disabled = false;
					}
				});

				window.addEventListener('resize', () => {
					if (!cajaNatural) return;
					caja = desdeNatural(cajaNatural);
					actualizarCaja();
				});

				imagen.addEventListener('load', () => {
					if (cajaNatural) {
						caja = desdeNatural(cajaNatural);
					} else {
						aplicarSugerenciaAutomatica();
					}
					actualizarCaja();
				});
				if (imagen.complete && imagen.naturalWidth > 0) {
					aplicarSugerenciaAutomatica();
				}
			})();
		</script>
	<?php endif; ?>
</body>

</html>
