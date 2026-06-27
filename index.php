<?php
ini_set("pcre.jit", "0");
set_time_limit(300);

require_once "src/funciones.php";
require_once "src/catalogo.php";
require_once "src/vistaPrincipal.php";
require_once "src/yandexDisk.php";
require_once "src/duplicados.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST'):
	require_once 'src/procesarPost.php';
endif;

$configuracion = cargarConfiguracion();
$omitir = carpetasIgnoradasConfiguracion($configuracion);

$html = '';

$raizNavegacion = obtenerRaizNavegacion();
$unarchivo = ($_GET['archivo'] ?? NULL);
$rutaIterador = CARPETA;
$archivoval = '';
$rutaBase = $raizNavegacion . DIRECTORY_SEPARATOR;
$palabraClaveActiva = obtenerPalabraClaveActiva();
$mediaActual = isset($_GET['media']) && in_array($_GET['media'], ['fotos', 'videos'], true)
	? $_GET['media']
	: '';
$yandexDiskConfigurado = yandexDiskApiKeyConfiguracion($configuracion) !== '';
$rutaYandexDisk = rutaYandexDiskDesdeFuente($_GET);
$ordenYandexDisk = ordenYandexDiskDesdeFuente($_GET);
if (ordenYandexDiskEsUltimosSubidos($ordenYandexDisk)):
	$rutaYandexDisk = '/';
endif;
$vistaYandexDisk = 'disk';
$panelSolicitado = (string) ($_GET['panel'] ?? '');
$panelDuplicadosActivo = $panelSolicitado === 'duplicados' && $palabraClaveActiva === '';
$panelYandexActivo = $panelSolicitado === 'yandex' && $yandexDiskConfigurado && $palabraClaveActiva === '';
$panelPalabrasActivo = !$panelYandexActivo && !$panelDuplicadosActivo && ($palabraClaveActiva !== '' || $panelSolicitado === 'palabras');
$panelCarpetasActivo = !$panelYandexActivo && !$panelDuplicadosActivo && !$panelPalabrasActivo;
$panelYandexUltimosSubidos = $panelYandexActivo && ordenYandexDiskEsUltimosSubidos($ordenYandexDisk);
$forzarYandexRemoto = $panelYandexUltimosSubidos && !empty($_GET['yandex_refresh']);
$modoYandexSolicitado = $panelYandexActivo;
if ($unarchivo):
	// Intentar resolver contra la raíz de navegación (home),
	// con caída a la raíz del proyecto si la ruta no está en home.
	$rutaSolicitada = resolverRutaNavegacion($unarchivo, 'any', false);
	if ($rutaSolicitada !== null && is_dir($rutaSolicitada)):
		$rutaIterador = $rutaSolicitada;
		$archivoval = ' value="' . escaparHtml(rutaRelativaDesdeRaizNavegacion($rutaSolicitada)) . '"';
		$unarchivo = NULL;
	elseif ($rutaSolicitada !== null && is_file($rutaSolicitada)):
		$unarchivo = $rutaSolicitada;
		$archivoval = ' value="' . escaparHtml(rutaRelativaDesdeRaizNavegacion($rutaSolicitada)) . '"';
	else:
		$unarchivo = NULL;
		$archivoval = ' value="' . escaparHtml(rutaRelativaDesdeRaizNavegacion(CARPETA)) . '"';
	endif;
elseif ($rutaIterador === $raizNavegacion || CARPETA == __DIR__):
	$archivoval = ' ';
else:
	$elvalordearchivo = str_ireplace($raizNavegacion, '', $rutaIterador);
	if (empty($elvalordearchivo) || $elvalordearchivo === $rutaIterador):
		$elvalordearchivo = str_ireplace(__DIR__, '', $rutaIterador);
	endif;
	if (!empty($elvalordearchivo)):
		$elvalordearchivo = trim($elvalordearchivo, '/');
	endif;
	$archivoval = ' value="' . escaparHtml($elvalordearchivo) . '"';
endif;

$errorDirectorio = '';
if ($modoYandexSolicitado):
	$resultados = [];
elseif ($panelDuplicadosActivo):
	$resultados = [];
elseif ($palabraClaveActiva !== ''):
	$resultados = obtenerResultadosPorPalabraClave($palabraClaveActiva, $mediaActual);
else:
	// Verificar que el directorio sea legible antes de escanear.
	// macOS restringe el acceso a ciertas carpetas (Downloads, etc.).
	if (!is_dir($rutaIterador)):
		$errorDirectorio = 'La ruta no existe o no es un directorio: ' . escaparHtml($rutaIterador);
		$resultados = [];
	elseif (!is_readable($rutaIterador)):
		$errorDirectorio = 'La carpeta no es accesible: ' . escaparHtml($rutaIterador) .
			'. macOS puede estar bloqueando el acceso. Revisa los permisos en Preferencias del Sistema ' .
			'→ Privacidad y Seguridad → Archivos y Carpetas.';
		$resultados = [];
	else:
		$resultadosRuta = obtenerResultadosMultimedia($rutaIterador, $unarchivo, $omitir);
		actualizarIndicePalabrasClave($resultadosRuta, 250);
		$resultados = $resultadosRuta;
	endif;
endif;
$filtrosMetadatos = obtenerFiltrosMetadatosDesdeFuente();
$totalSinFiltros = count($resultados);
$resultados = filtrarResultadosPorMetadatos($resultados, $filtrosMetadatos);
$palabrasClaveIndexadas = $panelPalabrasActivo ? obtenerPalabrasClaveIndexadas() : [];

// Listar carpetas desde la raíz de navegación (home del usuario)
// para que el árbol de directorios comience desde ~/
$carpetas = [];
if ($panelCarpetasActivo):
	$carpetas = listarCarpetas($raizNavegacion, $omitir);
	$carpetas = array_map(function ($carpeta) use ($rutaBase) {
		return str_replace($rutaBase, "", $carpeta);
	}, $carpetas);
	sort($carpetas);
endif;

$rutaActivaArbol = trim(str_replace('\\', '/', str_replace($rutaBase, '', $rutaIterador)), '/');
$arbolCarpetas = $panelCarpetasActivo ? construirArbolDirectorios($carpetas, $rutaActivaArbol) : '';

$datalists =
	'<datalist id="Ubicaciones">';
$tmpubi = [];
foreach (UBICACIONES as $ubi):
	$tmpubi[] = $ubi['Location'];
endforeach;
sort($tmpubi);
$datalists .= '<option value="';
$datalists .= implode('"><option value="', $tmpubi);
$datalists .= '">';
$datalists .= '</datalist>';

$marcas = ['Red social'];
$datalists .= '<datalist id="lista-Make">';
foreach ($marcas as $m):
	$datalists .= '<option value="' . $m . '">';
endforeach;
$datalists .= '</datalist>';

$modelos = ['Instagram', 'Facebook', 'Meta (Facebook/Instagram)', 'Threads', 'WhatsApp', 'X (Twitter)', 'TikTok', 'Reddit', 'Pinterest', 'LinkedIn', 'Telegram', 'OnlyFans', 'Fansly', 'VK', 'Snapchat', 'Bluesky', 'Tumblr', 'YouTube', 'Flickr', 'Patreon'];
$datalists .= '<datalist id="lista-Model">';
foreach ($modelos as $m):
	$datalists .= '<option value="' . $m . '">';
endforeach;
$datalists .= '</datalist>';

$página_actual = isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1;
$paginaSolicitada = $página_actual;
$elementos_por_pagina = isset($_GET['ver']) ? (int) $_GET['ver'] : (int) $configuracion['elementos_por_pagina'];
if ($elementos_por_pagina < 1):
	$elementos_por_pagina = max(1, (int) $configuracion['elementos_por_pagina']);
endif;
$elementosPorPaginaYandex = max(3, min(21, $elementos_por_pagina));
$paginaYandexSolicitada = max(1, $paginaSolicitada);
$total_de_elementos = count($resultados);
$total_de_paginas = max(1, (int) ceil($total_de_elementos / $elementos_por_pagina));

if (
	$página_actual < 1
	|| $página_actual > $total_de_paginas
):
	$página_actual = 1;
endif;

$indice_inicial = ($página_actual - 1) * $elementos_por_pagina;
$indice_final = min($indice_inicial + $elementos_por_pagina, $total_de_elementos);

$resultados_paginados = array_slice($resultados, $indice_inicial, $elementos_por_pagina);

if (!$panelYandexActivo && !$panelDuplicadosActivo && !empty($resultados_paginados)):
	calentarCacheFiltrosMetadatos($resultados_paginados, true, true);
endif;

$i = 1;
foreach ($resultados_paginados as $archivo):
	$html .= crearBloqueLigero($archivo[0], $i, $archivo[1]);
	$i++;
endforeach;

$parametroVer = $_GET['ver'] ?? '';
if (
	!empty($parametroVer)
	&& is_numeric($parametroVer)
):
	$parametroVer = '<input type="hidden" name="ver" value="' . htmlspecialchars($parametroVer, ENT_QUOTES, 'UTF-8') . '">';
else:
	$parametroVer = '';
endif;

$parametroMedia = $_GET['media'] ?? '';
if (
	!empty($parametroMedia)
	&& in_array($parametroMedia, ['fotos', 'videos'])
):
	$parametroMedia = '<input type="hidden" name="media" value="' . htmlspecialchars($parametroMedia, ENT_QUOTES, 'UTF-8') . '">';
else:
	$parametroMedia = '';
endif;
$parametrosFiltrosOcultos = inputsOcultosFiltrosMetadatos($filtrosMetadatos);
$estadoYandexDiskNavegacion = [];
if ($panelYandexActivo && $panelYandexUltimosSubidos):
	$estadoYandexDiskNavegacion = estadoYandexDiskVacio(true);
	$estadoYandexDiskNavegacion['ok'] = true;
	$estadoYandexDiskNavegacion['ruta'] = '/';
	$estadoYandexDiskNavegacion['orden'] = 'name';
elseif ($panelYandexActivo):
	$estadoYandexDiskNavegacion = obtenerDirectorioYandexDisk($configuracion, $rutaYandexDisk, 200, 0);
	$estadoYandexDiskNavegacion['espacio'] = obtenerEspacioYandexDisk($configuracion);
endif;
$estadoYandexDisk = $panelYandexActivo
	? obtenerPaginaMultimediaYandexDisk(
		$configuracion,
		$rutaYandexDisk,
		$paginaYandexSolicitada,
		$elementosPorPaginaYandex,
		$ordenYandexDisk === 'name' ? $estadoYandexDiskNavegacion : null,
		$ordenYandexDisk,
		$forzarYandexRemoto
	)
	: [];
if (($estadoYandexDisk['ok'] ?? false) && $panelYandexActivo):
	$totalYandexMultimedia = max(0, (int) ($estadoYandexDisk['total_multimedia'] ?? 0));
	$totalPaginasYandex = max(1, (int) ceil($totalYandexMultimedia / $elementosPorPaginaYandex));
	if ($paginaYandexSolicitada > $totalPaginasYandex):
		$paginaYandexSolicitada = 1;
		$estadoYandexDisk = obtenerPaginaMultimediaYandexDisk(
			$configuracion,
			$rutaYandexDisk,
			1,
			$elementosPorPaginaYandex,
			$ordenYandexDisk === 'name' ? $estadoYandexDiskNavegacion : null,
			$ordenYandexDisk,
			$forzarYandexRemoto
		);
	endif;
endif;
if ($panelYandexActivo):
	$resultadosYandex = is_array($estadoYandexDisk['multimedia'] ?? null) ? $estadoYandexDisk['multimedia'] : [];
	$elementos_por_pagina = $elementosPorPaginaYandex;
	$totalSinFiltros = max(0, (int) ($estadoYandexDisk['total_multimedia'] ?? 0));
	$total_de_elementos = $totalSinFiltros;
	$total_de_paginas = max(1, (int) ceil($total_de_elementos / $elementos_por_pagina));
	$página_actual = $paginaYandexSolicitada;
	if ($página_actual < 1 || $página_actual > $total_de_paginas):
		$página_actual = 1;
	endif;
	$indice_inicial = max(0, (int) ($estadoYandexDisk['offset'] ?? (($página_actual - 1) * $elementos_por_pagina)));
	$indice_final = min($indice_inicial + count($resultadosYandex), $total_de_elementos);
	$resultados_paginados = $resultadosYandex;
	$errorDirectorio = '';
	if (!($estadoYandexDisk['ok'] ?? false)):
		$html = '<div class="error-directorio yandex-remoto-error" role="alert"><p>⚠️ ' . escaparHtml((string) ($estadoYandexDisk['error'] ?? 'No se pudo leer Yandex Disk.')) . '</p></div>';
	else:
		$html = renderizarMultimediaYandexDisk($resultados_paginados, $indice_inicial + 1, 'No hay multimedia remota en esta página.');
	endif;
endif;
$rutaDuplicados = $panelDuplicadosActivo ? (string) ($_GET['ruta'] ?? '') : '';
$estadoDuplicados = $panelDuplicadosActivo ? duplicadosEstado(['ruta' => $rutaDuplicados], false) : [];
if ($panelDuplicadosActivo):
	$html = renderizarVistaDuplicados($estadoDuplicados);
	$total_de_elementos = (int) ($estadoDuplicados['resumen']['entradas'] ?? 0);
	$totalSinFiltros = $total_de_elementos;
	$total_de_paginas = 1;
	$página_actual = 1;
	$indice_inicial = 0;
	$indice_final = $total_de_elementos;
	$resultados_paginados = [];
	$errorDirectorio = '';
endif;
$resumenYandexTotalSufijo = ($panelYandexActivo && !($estadoYandexDisk['total_multimedia_conocido'] ?? true)) ? '+' : '';
$resumenYandexUbicacion = $panelYandexActivo
	? (ordenYandexDiskEsUltimosSubidos($ordenYandexDisk) ? 'Últimos subidos' : $rutaYandexDisk)
	: '';
$listaPalabrasClave = $panelPalabrasActivo
	? renderizarListaPalabrasClave(
		$palabrasClaveIndexadas,
		$palabraClaveActiva,
		$elementos_por_pagina,
		$mediaActual,
		$filtrosMetadatos
	)
	: '';

$panelCarpetasHtml =
	'<form method="GET" id="panel-carpetas" class="explorador-carpetas panel-lateral" role="tabpanel" aria-labelledby="tab-carpetas" data-panel-loaded="' . ($panelCarpetasActivo ? '1' : '0') . '"' . ($panelCarpetasActivo ? '' : ' hidden') . '>';
if ($panelCarpetasActivo):
	$panelCarpetasHtml .=
		$parametroVer .
		$parametroMedia .
		$parametrosFiltrosOcultos .
		'<label class="buscador-carpetas-label" for="buscador-carpetas">Buscar carpeta</label>' .
		'<input type="search" id="buscador-carpetas" class="buscador-carpetas" placeholder="Nombre o ruta" autocomplete="off">' .
		'<div class="arbol-contenedor">' . $arbolCarpetas . '</div>';
endif;
$panelCarpetasHtml .= '</form>';

$panelPalabrasHtml =
	'<section id="panel-palabras" class="palabras-clave-panel panel-lateral" role="tabpanel" aria-labelledby="tab-palabras" data-panel-loaded="' . ($panelPalabrasActivo ? '1' : '0') . '"' . ($panelPalabrasActivo ? '' : ' hidden') . '>';
if ($panelPalabrasActivo):
	$panelPalabrasHtml .=
		'<label class="buscador-carpetas-label" for="buscador-palabras-clave">Filtrar palabras</label>' .
		'<input type="search" id="buscador-palabras-clave" class="buscador-palabras-clave" placeholder="Palabra clave" autocomplete="off">' .
		'<button type="button" id="sincronizar-palabras-clave" class="sincronizar-palabras-clave">↻ Sincronizar palabras</button>' .
		'<div id="sincronizacion-palabras" class="sincronizacion-palabras" hidden>' .
		'<progress id="sincronizacion-palabras-progreso" value="0" max="1"></progress>' .
		'<div id="sincronizacion-palabras-mensaje" class="sincronizacion-palabras-mensaje">Preparando sincronización...</div>' .
		'</div>' .
		'<div id="palabras-clave-resumen" class="palabras-clave-resumen">' . count($palabrasClaveIndexadas) . ' palabras</div>' .
		'<div id="palabras-clave-contenedor" class="palabras-clave-contenedor">' .
		$listaPalabrasClave .
		'</div>';
endif;
$panelPalabrasHtml .= '</section>';

$panelDuplicadosHtml =
	'<section id="panel-duplicados" class="duplicados-panel-lateral panel-lateral" role="tabpanel" aria-labelledby="tab-duplicados" data-panel-loaded="' . ($panelDuplicadosActivo ? '1' : '0') . '"' . ($panelDuplicadosActivo ? '' : ' hidden') . '>';
if ($panelDuplicadosActivo):
	$panelDuplicadosHtml .= renderizarPanelDuplicados($estadoDuplicados);
endif;
$panelDuplicadosHtml .= '</section>';

$panelYandexHtml = '';
if ($yandexDiskConfigurado):
	$panelYandexHtml =
		'<section id="panel-yandex" class="yandex-disk-panel-lateral panel-lateral" role="tabpanel" aria-labelledby="tab-yandex" data-panel-loaded="' . ($panelYandexActivo ? '1' : '0') . '"' . ($panelYandexActivo ? '' : ' hidden') . '>' .
		($panelYandexActivo ? renderizarPanelYandexDisk($estadoYandexDiskNavegacion, $vistaYandexDisk, $ordenYandexDisk) : '') .
		'</section>';
endif;

?><!DOCTYPE html>
<html lang="es"<?php echo atributoTemaConfiguracion($configuracion); ?>>

<head>
	<meta charset="utf-8">
	<title>Etiquetar <?php echo $página_actual . " / " . $total_de_paginas; ?></title>
	<link href="estilos.css?v=<?php echo filemtime('estilos.css'); ?>" rel="stylesheet">
</head>

<body>
	<?php

	echo
		'<div id="layoutPrincipal" class="layout-principal" data-columna-carpetas-default="' . htmlspecialchars($configuracion['estado_arbol'], ENT_QUOTES, 'UTF-8') . '">' .
		'<aside id="columnaCarpetas" class="col-carpetas" aria-label="Carpetas">' .
		'<div class="col-carpetas-inner">' .
		'<div class="explorador-acciones">' .
		'<a href="configuracion.php" class="enlace-limpieza">⚙️ Configuración</a>' .
			'</div>' .
			'<div class="columna-tabs" role="tablist" aria-label="Navegación lateral">' .
			'<button type="button" id="tab-carpetas" class="columna-tab' . ($panelCarpetasActivo ? ' activo' : '') . '" data-sidebar-tab="carpetas" role="tab" aria-controls="panel-carpetas" aria-selected="' . ($panelCarpetasActivo ? 'true' : 'false') . '">Carpetas</button>' .
			'<button type="button" id="tab-palabras" class="columna-tab' . ($panelPalabrasActivo ? ' activo' : '') . '" data-sidebar-tab="palabras" role="tab" aria-controls="panel-palabras" aria-selected="' . ($panelPalabrasActivo ? 'true' : 'false') . '">Palabras clave</button>' .
			'<button type="button" id="tab-duplicados" class="columna-tab' . ($panelDuplicadosActivo ? ' activo' : '') . '" data-sidebar-tab="duplicados" role="tab" aria-controls="panel-duplicados" aria-selected="' . ($panelDuplicadosActivo ? 'true' : 'false') . '">Duplicados</button>' .
			($yandexDiskConfigurado ? '<button type="button" id="tab-yandex" class="columna-tab' . ($panelYandexActivo ? ' activo' : '') . '" data-sidebar-tab="yandex" role="tab" aria-controls="panel-yandex" aria-selected="' . ($panelYandexActivo ? 'true' : 'false') . '">Yandex Disk</button>' : '') .
			'</div>' .
			$panelCarpetasHtml .
			$panelPalabrasHtml .
			$panelDuplicadosHtml .
			$panelYandexHtml .
			'</div>' .
		'</aside>' .
		'<section class="col-contenido">' .
		($panelYandexActivo
			? paginacionYandexDisk($página_actual, $total_de_paginas, $elementos_por_pagina, $rutaYandexDisk, 'condensado', $vistaYandexDisk, $ordenYandexDisk)
			: ($panelDuplicadosActivo ? '' : paginacion($página_actual, $total_de_paginas, $elementos_por_pagina, $rutaIterador, 'condensado'))) .
		($panelYandexActivo
			? renderizarControlesYandexDisk($total_de_elementos, $resumenYandexTotalSufijo, $resumenYandexUbicacion, count($resultados_paginados), $rutaYandexDisk, $elementos_por_pagina, $ordenYandexDisk)
			: ($panelDuplicadosActivo ? renderizarControlesDuplicados($estadoDuplicados) : formularioFiltrosMetadatos($filtrosMetadatos, $total_de_elementos, $totalSinFiltros, $rutaIterador, $elementos_por_pagina))) .
		'<main>' .
		($errorDirectorio !== ''
			? '<div class="error-directorio" role="alert"><p>⚠️ ' . $errorDirectorio . '</p></div>'
			: '') .
		$html . '</main>' .
		($panelYandexActivo
			? paginacionYandexDisk($página_actual, $total_de_paginas, $elementos_por_pagina, $rutaYandexDisk, 'completo', $vistaYandexDisk, $ordenYandexDisk)
			: ($panelDuplicadosActivo ? '' : paginacion($página_actual, $total_de_paginas, $elementos_por_pagina, $rutaIterador))) .
		((isset($_GET['debug']) && $_GET['debug'] === '1') ? '<details class="resultados-debug"><summary>Resultados</summary>' . var_dump_pre($panelYandexActivo ? $estadoYandexDisk : ($panelDuplicadosActivo ? $estadoDuplicados : $resultados)) . '</details>' : '') .
		'</section>' .
		'<aside class="col-detalle" aria-label="Metadatos">' .
		'<div class="panel-detalle-placeholder"><div class="panel-detalle-mensaje panel-detalle-mensaje-info" role="status" aria-live="polite"><span class="panel-detalle-mensaje-icono" aria-hidden="true">i</span><div class="panel-detalle-mensaje-cuerpo">Selecciona un archivo para ver su información</div></div></div>' .
		'<div id="panelDetalleContenido" class="panel-detalle-contenido"></div>' .
		'</aside>' .
		'<button type="button" id="alternar-carpetas" class="alternar-carpetas" aria-controls="columnaCarpetas" aria-expanded="true" title="Colapsar carpetas">‹</button>' .
		'</div>' .
		$datalists;
	?>
	<script src="scripts.js?v=<?php echo filemtime('scripts.js'); ?>"></script>

</body>

</html>
