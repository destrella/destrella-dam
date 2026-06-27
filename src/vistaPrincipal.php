<?php

/**
 * Render helpers de la vista principal.
 *
 * Mantener estos helpers fuera de index.php permite revisar la composición de
 * HTML sin mezclarla con la lectura de parámetros, filtros y paginación.
 */

const ARBOL_DIRECTORIOS_MAX_NODOS = 1800;
const ARBOL_DIRECTORIOS_PROFUNDIDAD_BASE = 2;

function ordenarArbolDirectorios(array &$nodos): void
{
	uksort($nodos, 'strnatcasecmp');
	foreach ($nodos as &$nodo):
		ordenarArbolDirectorios($nodo['hijos']);
	endforeach;
	unset($nodo);
}

function rutaArbolEsRamaActiva(string $ruta, string $rutaActiva): bool
{
	return $rutaActiva !== '' && (
		$ruta === $rutaActiva
		|| str_starts_with($rutaActiva . '/', $ruta . '/')
	);
}

function renderizarAvisoArbolReducido(): string
{
	return '<li class="directorio-item directorio-mas"><span>Entra a esta carpeta para ver más subcarpetas.</span></li>';
}

function renderizarArbolDirectoriosLimitado(array $nodos, string $rutaActiva, int $profundidad, int &$renderizados, bool &$reducido): string
{
	$html = '';
	foreach ($nodos as $nombre => $nodo):
		if ($renderizados >= ARBOL_DIRECTORIOS_MAX_NODOS):
			$reducido = true;
			break;
		endif;
		$renderizados++;
		$ruta = $nodo['ruta'];
		$hijos = $nodo['hijos'];
		$activo = ($rutaActiva !== '' && $ruta === $rutaActiva);
		$abierto = ($rutaActiva !== '' && str_starts_with($rutaActiva . '/', $ruta . '/'));
		$enRamaActiva = rutaArbolEsRamaActiva($ruta, $rutaActiva);
		$mostrarHijos = !empty($hijos) && (
			$profundidad < ARBOL_DIRECTORIOS_PROFUNDIDAD_BASE
			|| $enRamaActiva
		);
		$hijosHtml = $mostrarHijos
			? renderizarArbolDirectoriosLimitado($hijos, $rutaActiva, $profundidad + 1, $renderizados, $reducido)
			: '';
		$hijosOcultos = !empty($hijos) && !$mostrarHijos;
		if ($hijosOcultos):
			$reducido = true;
		endif;
		$nombreAttr = escaparHtml($nombre);
		$rutaAttr = escaparHtml($ruta);
		$claseBoton = 'directorio-boton' . ($activo ? ' activo' : '');
		$boton =
			'<button type="submit" name="archivo" value="' . $rutaAttr . '" class="' . $claseBoton . '" title="' . $rutaAttr . '">' .
			'<span class="directorio-nombre">' . $nombreAttr . '</span>' .
			'<span class="directorio-ruta">' . $rutaAttr . '</span>' .
			'</button>';

		if (!empty($hijos)):
			$html .=
				'<li class="directorio-item">' .
				'<details class="directorio-nodo" data-path="' . $rutaAttr . '" data-name="' . $nombreAttr . '" data-open-default="' . ($abierto ? '1' : '0') . '"' . ($abierto ? ' open' : '') . '>' .
				'<summary>' . $boton . '</summary>' .
				'<ul>' . $hijosHtml . ($hijosOcultos ? renderizarAvisoArbolReducido() : '') . '</ul>' .
				'</details>' .
				'</li>';
		else:
			$html .=
				'<li class="directorio-item directorio-hoja" data-path="' . $rutaAttr . '" data-name="' . $nombreAttr . '">' .
				$boton .
				'</li>';
		endif;
	endforeach;

	return $html;
}

function renderizarArbolDirectorios(array $nodos, string $rutaActiva): string
{
	$renderizados = 0;
	$reducido = false;
	return renderizarArbolDirectoriosLimitado($nodos, $rutaActiva, 0, $renderizados, $reducido);
}

function construirArbolDirectorios(array $rutas, string $rutaActiva): string
{
	$arbol = [];
	foreach ($rutas as $ruta):
		$ruta = trim(str_replace('\\', '/', $ruta), '/');
		if ($ruta === ''):
			continue;
		endif;
		$partes = array_values(array_filter(explode('/', $ruta), fn($parte) => $parte !== ''));
		$cursor = &$arbol;
		$acumulada = [];
		foreach ($partes as $parte):
			$acumulada[] = $parte;
			if (!isset($cursor[$parte])):
				$cursor[$parte] = [
					'ruta' => implode('/', $acumulada),
					'hijos' => []
				];
			endif;
			$cursor = &$cursor[$parte]['hijos'];
		endforeach;
		unset($cursor);
	endforeach;

	ordenarArbolDirectorios($arbol);
	if (empty($arbol)):
		return '<p class="arbol-vacio">No hay carpetas.</p>';
	endif;

	$renderizados = 0;
	$reducido = false;
	$html = renderizarArbolDirectoriosLimitado($arbol, $rutaActiva, 0, $renderizados, $reducido);
	$aviso = $reducido
		? '<p class="arbol-aviso">Árbol reducido para mantener la navegación ligera.</p>'
		: '';

	return $aviso . '<ul class="arbol-directorios">' . $html . '</ul>';
}

function urlPalabraClave(string $palabra, int $ver, string $media, array $filtros): string
{
	$params = [
		'palabra_clave' => $palabra,
		'ver' => $ver,
	];
	if ($media !== ''):
		$params['media'] = $media;
	endif;
	foreach ($filtros as $clave => $valor):
		if ($valor !== ''):
			$params[$clave] = $valor;
		endif;
	endforeach;

	return '?' . http_build_query($params);
}

function renderizarListaPalabrasClave(array $palabras, string $palabraActiva, int $ver, string $media, array $filtros): string
{
	if (empty($palabras)):
		return '<p class="palabras-clave-vacio">Sin palabras clave indexadas.</p>';
	endif;

	$claveActiva = normalizarClavePalabraClave($palabraActiva);
	$html = '<div class="palabras-clave-lista" role="list">';
	foreach ($palabras as $fila):
		$palabra = (string) ($fila['palabra'] ?? '');
		$clave = (string) ($fila['clave'] ?? normalizarClavePalabraClave($palabra));
		if ($palabra === '' || $clave === ''):
			continue;
		endif;
		$total = (int) ($fila['total'] ?? 0);
		$activa = $claveActiva !== '' && $clave === $claveActiva;
		$html .=
			'<a role="listitem" class="palabra-clave-link' . ($activa ? ' activo' : '') . '"' .
			' href="' . escaparHtml(urlPalabraClave($palabra, $ver, $media, $filtros)) . '"' .
			' data-palabra="' . escaparHtml($palabra) . '"' .
			' data-palabra-busqueda="' . escaparHtml($clave) . '"' .
			' title="' . escaparHtml($palabra) . '">' .
			'<span class="palabra-clave-texto">' . escaparHtml($palabra) . '</span>' .
			'<span class="palabra-clave-total">' . $total . '</span>' .
			'</a>';
	endforeach;
	$html .= '</div>';

	return $html;
}

function formatearFechaYandexDisk(string $fecha): string
{
	if ($fecha === ''):
		return '';
	endif;

	try {
		return (new DateTimeImmutable($fecha))->format('Y-m-d H:i');
	} catch (Throwable $e) {
		return $fecha;
	}
}

function formatearJsonCortoYandexDisk(mixed $valor): string
{
	if (!is_array($valor) || empty($valor)):
		return '';
	endif;

	$filtrado = array_filter($valor, fn($item) => $item !== null && $item !== '' && $item !== []);
	if (empty($filtrado)):
		return '';
	endif;

	$json = json_encode($filtrado, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	return is_string($json) ? $json : '';
}

function renderizarUsoEspacioYandexDisk(mixed $espacio): string
{
	if (!is_array($espacio) || !($espacio['ok'] ?? false)):
		return '';
	endif;

	$total = (int) ($espacio['total'] ?? 0);
	$usado = (int) ($espacio['usado'] ?? 0);
	if ($total <= 0):
		return '';
	endif;

	$porcentaje = min(100, max(0, (float) ($espacio['porcentaje'] ?? (($usado / $total) * 100))));
	$porcentajeTexto = number_format($porcentaje, 1);
	$usadoLegible = (string) ($espacio['usado_legible'] ?? yandexDiskFormatoTamano($usado));
	$totalLegible = (string) ($espacio['total_legible'] ?? yandexDiskFormatoTamano($total));
	$etiqueta = $usadoLegible . ' de ' . $totalLegible . ' usados';

	return
		'<div class="yandex-uso-espacio" aria-label="Uso de almacenamiento Yandex Disk">' .
		'<div class="yandex-uso-espacio-barra" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . escaparHtml((string) round($porcentaje, 1)) . '" aria-label="' . escaparHtml($etiqueta) . '">' .
		'<span style="--uso-yandex:' . escaparHtml((string) $porcentaje) . '%"></span>' .
		'</div>' .
		'<small>' . escaparHtml($etiqueta . ' · ' . $porcentajeTexto . '%') . '</small>' .
		'</div>';
}

function renderizarPanelYandexDisk(array $estado, string $vistaActiva = 'disk', string $ordenActual = 'name'): string
{
	if (!($estado['configurada'] ?? false)):
		return '';
	endif;

	$vistaActiva = $vistaActiva === 'photos' ? 'photos' : 'disk';
	$ordenActual = normalizarOrdenYandexDisk($ordenActual);
	$ordenNavegacion = ordenYandexDiskEsUltimosSubidos($ordenActual) ? 'name' : $ordenActual;
	$directorios = is_array($estado['directorios'] ?? null) ? $estado['directorios'] : (is_array($estado['items'] ?? null) ? $estado['items'] : []);
	$rutaActual = normalizarRutaYandexDisk($estado['ruta'] ?? '/');
	$totalElementos = (int) ($estado['total'] ?? 0);
	$totalConsultado = (int) ($estado['total_consultado'] ?? count($directorios));
	$totalDirectorios = (int) ($estado['total_directorios'] ?? count($directorios));
	$padre = rutaPadreYandexDisk($rutaActual);
	$html =
		'<div class="yandex-disk-panel">' .
		'<div class="yandex-disk-resumen">' .
		'<strong>Yandex.Disk</strong>' .
		renderizarUsoEspacioYandexDisk($estado['espacio'] ?? null) .
		'<span>' . $totalElementos . ' elementos en ' . escaparHtml($rutaActual) . '</span>' .
		'</div>';

	if (!($estado['ok'] ?? false)):
		$error = (string) ($estado['error'] ?? 'No se pudo leer Yandex Disk.');
		$html .= '<p class="yandex-disk-estado yandex-disk-error">' . escaparHtml($error) . '</p></div>';
		return $html;
	endif;

	$html .=
		'<nav class="yandex-disk-rutas" aria-label="Rutas de Yandex Disk">' .
		'<a href="' . escaparHtml(urlPanelYandexDisk('/', $ordenNavegacion)) . '">Raíz</a>' .
		($padre !== null ? '<a href="' . escaparHtml(urlPanelYandexDisk($padre, $ordenNavegacion)) . '">Subir</a>' : '') .
		'<a href="' . escaparHtml(yandexDiskUrlCliente($rutaActual)) . '" target="_blank" rel="noopener noreferrer">Abrir en Yandex</a>' .
		'</nav>';

	if (($estado['truncado'] ?? false)):
		$html .= '<p class="yandex-disk-estado">' . $totalDirectorios . ' carpetas detectadas en los primeros ' . $totalConsultado . ' elementos.</p>';
	endif;

	if (empty($directorios)):
		$html .= '<p class="yandex-disk-estado">Sin carpetas en esta ruta.</p></div>';
		return $html;
	endif;

	$html .=
		'<label class="buscador-carpetas-label" for="buscador-yandex-disk">Filtrar carpetas</label>' .
		'<input type="search" id="buscador-yandex-disk" class="buscador-yandex-disk" placeholder="Nombre o ruta" autocomplete="off">' .
		'<div class="yandex-carpetas-lista" role="list">';

	foreach ($directorios as $item):
		if (!is_array($item)):
			continue;
		endif;
		$nombre = (string) ($item['nombre'] ?? '');
		$ruta = (string) ($item['ruta'] ?? '');
		$fecha = formatearFechaYandexDisk((string) ($item['modificado'] ?? ''));
		$meta = implode(' · ', array_filter(['Carpeta', $fecha], fn($valor) => $valor !== ''));
		$busqueda = trim($nombre . ' ' . $ruta . ' Carpeta');
		$url = urlPanelYandexDisk($ruta, $ordenNavegacion);

		$html .=
			'<a class="yandex-media-item yandex-media-item-dir" role="listitem" href="' . escaparHtml($url) . '" data-yandex-busqueda="' . escaparHtml($busqueda) . '" title="' . escaparHtml($ruta) . '">' .
			'<span class="yandex-media-preview"><span class="yandex-media-placeholder">Dir</span></span>' .
			'<span class="yandex-media-info">' .
			'<span class="yandex-media-nombre">' . escaparHtml($nombre) . '</span>' .
			'<span class="yandex-media-ruta">' . escaparHtml($ruta) . '</span>' .
			($meta !== '' ? '<span class="yandex-media-meta">' . escaparHtml($meta) . '</span>' : '') .
			'</span>' .
			'</a>';
	endforeach;

	$html .= '</div></div>';
	return $html;
}

function etiquetaOrdenYandexDisk(string $orden): string
{
	return match (normalizarOrdenYandexDisk($orden)) {
		'last-uploaded' => 'Últimos subidos',
		'-name' => 'Nombre Z-A',
		'created' => 'Creado ascendente',
		'-created' => 'Creado descendente',
		'size' => 'Tamaño ascendente',
		'-size' => 'Tamaño descendente',
		'modified' => 'Modificado ascendente',
		'-modified' => 'Modificado descendente',
		default => 'Nombre A-Z',
	};
}

function renderizarSelectorOrdenYandexDisk(string $ordenActual): string
{
	$ordenActual = normalizarOrdenYandexDisk($ordenActual);
	$opciones = [
		'last-uploaded' => 'Últimos subidos',
		'name' => 'Nombre A-Z',
		'-name' => 'Nombre Z-A',
		'created' => 'Creado ascendente',
		'-created' => 'Creado descendente',
		'size' => 'Tamaño ascendente',
		'-size' => 'Tamaño descendente',
		'modified' => 'Modificado ascendente',
		'-modified' => 'Modificado descendente',
	];

	$html = '';
	foreach ($opciones as $valor => $etiqueta):
		$html .= '<option value="' . escaparHtml($valor) . '"' . ($valor === $ordenActual ? ' selected' : '') . '>' . escaparHtml($etiqueta) . '</option>';
	endforeach;

	return $html;
}

function renderizarControlesYandexDisk(int $total, string $sufijoTotal, string $ruta, int $cantidadPagina, string $rutaYandex, int $ver, string $ordenActual): string
{
	$ordenActual = normalizarOrdenYandexDisk($ordenActual);
	$actualizarUltimos = '';
	if (ordenYandexDiskEsUltimosSubidos($ordenActual)):
		$actualizarUrl = urlPanelYandexDisk($rutaYandex, $ordenActual) . '&ver=' . max(3, min(21, $ver)) . '&yandex_refresh=1';
		$actualizarUltimos = '<a class="yandex-actualizar-ultimos" href="' . escaparHtml($actualizarUrl) . '">Actualizar últimos</a>';
	endif;
	return
		'<div class="filtros-metadatos yandex-remoto-resumen">' .
		'<span class="filtros-metadatos-resumen">' . $total . escaparHtml($sufijoTotal) . ' multimedia remota en ' . escaparHtml($ruta) . ' · ' . $cantidadPagina . ' en esta página</span>' .
		'<form method="get" class="yandex-orden-form">' .
		'<input type="hidden" name="panel" value="yandex">' .
		'<input type="hidden" name="yandex_path" value="' . escaparHtml(normalizarRutaYandexDisk($rutaYandex)) . '">' .
		'<input type="hidden" name="ver" value="' . max(3, min(21, $ver)) . '">' .
		'<label for="yandex-sort">Ordenar por<select id="yandex-sort" name="yandex_sort">' . renderizarSelectorOrdenYandexDisk($ordenActual) . '</select></label>' .
		'<button type="submit">Ordenar</button>' .
		'</form>' .
		'<span class="yandex-orden-actual">Orden actual: ' . escaparHtml(etiquetaOrdenYandexDisk($ordenActual)) . '</span>' .
		$actualizarUltimos .
		'</div>';
}

function renderizarMultimediaYandexDisk(array $items, int $indiceInicial = 1, string $mensajeVacio = 'No hay multimedia remota en esta carpeta.'): string
{
	if (empty($items)):
		return '<div class="yandex-remoto-vacio" role="status">' . escaparHtml($mensajeVacio) . '</div>';
	endif;

	$html = '';
	$indice = $indiceInicial;
	foreach ($items as $item):
		if (!is_array($item)):
			continue;
		endif;
		$nombre = (string) ($item['nombre'] ?? '');
		$ruta = (string) ($item['ruta'] ?? '');
		$namespace = (string) ($item['namespace'] ?? 'disk');
		$esPhotos = $namespace === 'photos';
		$photoId = (string) ($item['photo_id'] ?? $item['id'] ?? '');
		$rutaVisible = $esPhotos ? (string) ($item['ruta_visible'] ?? 'From unlimited storage') : $ruta;
		$tipo = (string) ($item['tipo'] ?? 'image');
		$tipoTexto = $tipo === 'video' ? 'Video' : 'Imagen';
		$tamano = (string) ($item['tamano_legible'] ?? '');
		$fecha = formatearFechaYandexDisk((string) ($item['modificado'] ?? ''));
		$desdeUnlimited = !empty($item['desde_unlimited']);
		$origen = $desdeUnlimited ? (string) ($item['origen'] ?? 'From unlimited storage') : '';
		$meta = implode(' · ', array_filter([$tipoTexto, $tamano, $fecha, $origen], fn($valor) => $valor !== ''));
		$preview = (string) ($item['preview'] ?? '');
		$id = 'yandex_' . $indice;
		$panelId = 'pie_' . $id;
		$descarga = $esPhotos ? yandexDiskPhotoDownloadUrl($photoId) : yandexDiskDownloadUrl($ruta);
		$previewLightbox = (string) ($item['preview_lightbox'] ?? '');
		if ($tipo === 'video'):
			$lightboxHref = $esPhotos ? yandexDiskPhotoMediaProxyUrl($photoId, $tipo, $nombre) : yandexDiskMediaProxyUrl($ruta);
		elseif ($esPhotos || $desdeUnlimited):
			$previewLightbox = $previewLightbox !== '' ? $previewLightbox : $preview;
			$lightboxHref = $previewLightbox !== ''
				? yandexDiskPhotoPreviewProxyUrl($previewLightbox)
				: yandexDiskLightboxPreviewProxyUrl($ruta);
		else:
			$lightboxHref = yandexDiskLightboxPreviewProxyUrl($ruta);
		endif;
		$lightboxIcono = $tipo === 'video' ? '▶️' : '👁️';
		$lightboxEtiqueta = $tipo === 'video' ? 'Abrir video en lightbox' : 'Abrir imagen en lightbox';
		$previewSrc = $preview !== ''
			? (($esPhotos || $desdeUnlimited) ? yandexDiskPhotoPreviewProxyUrl($preview) : yandexDiskPreviewProxyUrl($ruta, 'M'))
			: '';
		$ubicacion = $esPhotos ? $rutaVisible : dirname($ruta);

		$miniatura = $previewSrc !== ''
			? '<img src="' . escaparHtml($previewSrc) . '" alt="' . escaparHtml($nombre) . '" loading="lazy">'
			: '<span class="yandex-remoto-placeholder">' . escaparHtml($tipoTexto) . '</span>';
		$badgeOrigen = $desdeUnlimited
			? '<span class="yandex-remoto-origen" title="' . escaparHtml($esPhotos ? 'Yandex Photos: From unlimited storage' : 'Path inicia con /photounlim/') . '">' . ($esPhotos ? 'Photos' : 'Photounlim') . '</span>'
			: '';
		$claseOrigen = $desdeUnlimited ? ' yandex-remoto-unlimited' : '';
		$atributoOrigen = $esPhotos
			? ' data-yandex-photo-id="' . escaparHtml($photoId) . '"'
			: ' data-yandex-path="' . escaparHtml($ruta) . '"';
		$atributosMovimiento = (!$esPhotos && !$desdeUnlimited && $ruta !== '')
			? ' data-yandex-movable="1" data-yandex-name="' . escaparHtml($nombre) . '"'
			: '';

		$html .=
			'<article id="art_' . escaparHtml($id) . '" data-panel-id="' . escaparHtml($panelId) . '"' . $atributoOrigen . $atributosMovimiento . ' class="yandex-remoto-articulo yandex-remoto-' . escaparHtml($tipo) . $claseOrigen . '" tabindex="0">' .
			'<figure>' .
			'<button type="button" class="yandex-remoto-preview" title="' . escaparHtml($rutaVisible) . '" aria-label="Mostrar detalles de ' . escaparHtml($nombre) . '">' .
			$miniatura .
			$badgeOrigen .
			($tipo === 'video' ? '<span class="yandex-remoto-video-indicador">Video</span>' : '') .
			'</button>' .
			'<button type="button" class="abrir-lightbox yandex-lightbox-boton" data-lightbox-href="' . escaparHtml($lightboxHref) . '" data-lightbox-tipo="' . escaparHtml($tipo) . '" aria-label="' . escaparHtml($lightboxEtiqueta) . '" title="' . escaparHtml($lightboxEtiqueta) . '">' . $lightboxIcono . '</button>' .
			'<figcaption>' .
			'<div><small>' . escaparHtml($ubicacion) . '</small><br>' . escaparHtml($nombre) . '</div>' .
			($meta !== '' ? '<small>' . escaparHtml($meta) . '</small>' : '') .
			'</figcaption>' .
			'</figure>' .
			'</article>' .
			renderizarDetalleYandexDisk($item, $id);
		$indice++;
	endforeach;

	return $html;
}

function renderizarDetalleYandexDisk(array $item, string $id): string
{
	$nombre = (string) ($item['nombre'] ?? '');
	$ruta = (string) ($item['ruta'] ?? '');
	$namespace = (string) ($item['namespace'] ?? 'disk');
	$esPhotos = $namespace === 'photos';
	$photoId = (string) ($item['photo_id'] ?? $item['id'] ?? '');
	$rutaVisible = $esPhotos ? (string) ($item['ruta_visible'] ?? 'From unlimited storage') : $ruta;
	$tipo = (string) ($item['tipo'] ?? '');
	$tipoTexto = $tipo === 'video' ? 'Video' : 'Imagen';
		$urlYandex = $esPhotos ? (string) ($item['url'] ?? '') : (string) ($item['url'] ?? yandexDiskUrlCliente($ruta));
		$preview = (string) ($item['preview'] ?? '');
		$previewCopia = (string) ($item['preview_lightbox'] ?? '');
		if ($previewCopia === ''):
			$previewCopia = $preview;
		endif;
		$origen = (string) ($item['origen'] ?? 'Yandex Disk');
	$exif = formatearJsonCortoYandexDisk($item['exif'] ?? []);

	$metadatos = [
		'Nombre' => $nombre,
		($esPhotos ? 'ID de Photos' : 'Ruta') => $esPhotos ? $photoId : $ruta,
		($esPhotos ? 'Ubicación' : '') => $esPhotos ? $rutaVisible : '',
		'Origen' => $origen,
		'Tipo' => $tipoTexto,
		'MIME' => (string) ($item['mime'] ?? ''),
		'Media type' => (string) ($item['media_type'] ?? ''),
		'Tamaño' => (string) ($item['tamano_legible'] ?? ''),
		'Bytes' => isset($item['tamano']) && $item['tamano'] !== null ? (string) $item['tamano'] : '',
		'Creado' => formatearFechaYandexDisk((string) ($item['creado'] ?? '')),
		'Modificado' => formatearFechaYandexDisk((string) ($item['modificado'] ?? '')),
		'Resource ID' => (string) ($item['resource_id'] ?? ''),
		'MD5' => (string) ($item['md5'] ?? ''),
		'SHA-256' => (string) ($item['sha256'] ?? ''),
		'EXIF' => $exif,
		'Preview API' => $preview !== '' ? 'Disponible' : '',
		'URL pública' => (string) ($item['public_url'] ?? ''),
	];

	$html =
		'<section id="pie_' . escaparHtml($id) . '" class="panel-articulo panel-yandex-remoto" hidden>' .
		'<div class="yandex-detalle-encabezado">' .
		'<h2>' . escaparHtml($nombre) . '</h2>' .
		'<p>' . escaparHtml($rutaVisible) . '</p>' .
		'</div>' .
		'<div class="yandex-detalle-acciones">' .
		'<button type="button" class="yandex-descarga-boton" data-yandex-copy-path="' . escaparHtml($ruta) . '" data-yandex-copy-photo-id="' . escaparHtml($photoId) . '" data-yandex-copy-name="' . escaparHtml($nombre) . '" data-yandex-copy-type="' . escaparHtml($tipo) . '" data-yandex-copy-preview="' . escaparHtml($previewCopia) . '">Descargar</button>' .
		($urlYandex !== '' ? '<a class="yandex-abrir-boton" href="' . escaparHtml($urlYandex) . '" target="_blank" rel="noopener noreferrer">Abrir en Yandex</a>' : '') .
		(!$esPhotos ? '<button type="button" class="yandex-papelera-boton" data-yandex-trash-path="' . escaparHtml($ruta) . '" data-yandex-trash-id="' . escaparHtml($id) . '">Enviar a papelera</button>' : '') .
		'</div>' .
		'<details open class="yandex-metadatos">' .
		'<summary>Metadatos de Yandex Disk</summary>' .
		'<dl>';

	foreach ($metadatos as $etiqueta => $valor):
		if ($valor === ''):
			continue;
		endif;
		$html .= '<dt>' . escaparHtml($etiqueta) . '</dt><dd>' . escaparHtml($valor) . '</dd>';
	endforeach;

	$html .=
		'</dl>' .
		'</details>' .
		'</section>';

	return $html;
}

function paginacionYandexDisk(int $paginaActual, int $totalPaginas, int $ver, string $ruta, string $estilo = 'completo', string $vista = 'disk', string $orden = 'name'): string
{
	if (!in_array($estilo, ['completo', 'condensado'], true)):
		$estilo = 'completo';
	endif;

	$paginaActual = max(1, $paginaActual);
	$totalPaginas = max(1, $totalPaginas);
	$ver = max(3, min(21, $ver));
	$ruta = normalizarRutaYandexDisk($ruta);
	$orden = normalizarOrdenYandexDisk($orden);
	$base = '?panel=yandex&ver=' . $ver . '&yandex_path=' . rawurlencode($ruta);
	if ($orden !== 'name'):
		$base .= '&yandex_sort=' . rawurlencode($orden);
	endif;
	$paginaRangeId = 'yandex-pagina-range-' . substr(md5($estilo . '-' . $paginaActual . '-' . $totalPaginas . '-' . $ruta . '-' . $orden), 0, 10);
	$verRangeId = 'yandex-ver-range-' . substr(md5('ver-' . $estilo . '-' . $paginaActual . '-' . $ver . '-' . $ruta . '-' . $orden), 0, 10);
	$verMarkersId = 'yandex-ver-markers-' . substr(md5('markers-' . $verRangeId), 0, 10);

	$html = '<nav class="paginación ' . escaparHtml($estilo) . '">';
	if ($paginaActual > 1):
		$html .=
			'<a href="' . escaparHtml($base . '&pagina=1') . '" style="text-decoration:none;" title="Inicio">⏮️</a>' .
			' <a href="' . escaparHtml($base . '&pagina=' . ($paginaActual - 1)) . '" style="text-decoration:none;" title="Anterior">◀️</a>';
	else:
		$html .= '<span style="opacity:0.3">⏮️ ◀️</span>';
	endif;

	$html .=
		' <form method="get" class="paginacion-pagina-form" data-paginacion-form="pagina">' .
		'<input type="hidden" name="panel" value="yandex">' .
		'<input type="hidden" name="ver" value="' . escaparHtml((string) $ver) . '">' .
		'<input type="hidden" name="yandex_path" value="' . escaparHtml($ruta) . '">' .
		($orden !== 'name' ? '<input type="hidden" name="yandex_sort" value="' . escaparHtml($orden) . '">' : '') .
		'<label class="paginacion-rango-pagina" for="' . escaparHtml($paginaRangeId) . '">' .
		'<span class="paginacion-rango-etiqueta">Página <output for="' . escaparHtml($paginaRangeId) . '" data-paginacion-pagina-valor>' . $paginaActual . '</output>/' . $totalPaginas . '</span>' .
		'<input id="' . escaparHtml($paginaRangeId) . '" type="range" name="pagina" min="1" max="' . $totalPaginas . '" step="1" value="' . $paginaActual . '" data-paginacion-pagina-range>' .
		'</label>' .
		'<button type="submit" class="paginacion-ir">Ir</button>' .
		'</form> ';

	if ($totalPaginas > $paginaActual):
		$html .=
			'<a href="' . escaparHtml($base . '&pagina=' . ($paginaActual + 1)) . '" style="text-decoration:none;" title="Siguiente">▶️</a> ' .
			'<a href="' . escaparHtml($base . '&pagina=' . $totalPaginas) . '" style="text-decoration:none;" title="Última página">⏭️</a> ';
	else:
		$html .= '<span style="opacity:0.3">▶️ ⏭️</span>';
	endif;

	$html .=
		' <form method="get" class="paginacion-ver-form">' .
		'<input type="hidden" name="panel" value="yandex">' .
		'<input type="hidden" name="yandex_path" value="' . escaparHtml($ruta) . '">' .
		($orden !== 'name' ? '<input type="hidden" name="yandex_sort" value="' . escaparHtml($orden) . '">' : '') .
		'<input type="hidden" name="pagina" value="' . $paginaActual . '">' .
		'<span class="slider">' .
		'<input id="' . escaparHtml($verRangeId) . '" type="range" name="ver" min="3" max="21" step="1" value="' . $ver . '" list="' . escaparHtml($verMarkersId) . '"/>' .
		'<datalist id="' . escaparHtml($verMarkersId) . '">';
	for ($i = 3; $i <= 21; $i += 3):
		$html .= '<option value="' . $i . '">' . $i . '</option>';
	endfor;
	$html .= '</datalist><div class="ticks">';
	for ($i = 3; $i <= 21; $i += 3):
		$html .= '<span style="--v:' . $i . '">' . $i . '</span>';
	endfor;
	$html .=
		'</div></span>' .
		'<input type="submit" value="Ver">' .
		'</form>' .
		'</nav>';

	return $html;
}
