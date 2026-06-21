<?php

/**
 * Proxy de archivos multimedia.
 *
 * Sirve archivos multimedia que están fuera del document root del servidor
 * (por ejemplo, dentro del home del usuario ~/). PHP puede leerlos, pero el
 * servidor web no los sirve directamente porque están fuera del docroot.
 *
 * Uso: servir.php?f=/Users/destrella/Downloads/photo.jpg
 *      servir.php?f=relativa/a/una/carpeta/imagen.jpg
 *
 * @see obtenerRaizNavegacion()
 */

ini_set("pcre.jit", "0");
set_time_limit(300);

require_once __DIR__ . '/src/funciones.php';

$rutaSolicitada = $_GET['f'] ?? '';

if ($rutaSolicitada === ''):
	http_response_code(400);
	echo '400 Bad Request: Missing file parameter';
	exit;
endif;

// Resolver la ruta contra la raíz de navegación (home) y proyecto
$rutaReal = resolverRutaNavegacion($rutaSolicitada, 'file', false);

if (!$rutaReal):
	http_response_code(404);
	echo '404 Not Found: ' . escaparHtml($rutaSolicitada);
	exit;
endif;

// Validar que es un archivo multimedia conocido
$extensionesPermitidas = [
	'jpg', 'jpeg', 'webp', 'png', 'heic', 'gif',
	'mp4', 'mov', 'mkv', 'webm', 'avi', 'm4v',
];
$ext = strtolower(pathinfo($rutaReal, PATHINFO_EXTENSION));
if (!in_array($ext, $extensionesPermitidas, true)):
	http_response_code(403);
	echo '403 Forbidden: Unsupported file type';
	exit;
endif;

// Mapear extensiones a MIME types
$mimeTypes = [
	'jpg'  => 'image/jpeg',
	'jpeg' => 'image/jpeg',
	'webp' => 'image/webp',
	'png'  => 'image/png',
	'heic' => 'image/heic',
	'gif'  => 'image/gif',
	'mp4'  => 'video/mp4',
	'mov'  => 'video/quicktime',
	'mkv'  => 'video/x-matroska',
	'webm' => 'video/webm',
	'avi'  => 'video/x-msvideo',
	'm4v'  => 'video/x-m4v',
];
$mime = $mimeTypes[$ext] ?? 'application/octet-stream';
$esVideo = str_starts_with($mime, 'video/');

// Headers de caché
$timestamp = filemtime($rutaReal);
$etag = '"' . md5($rutaReal . $timestamp) . '"';

header('Cache-Control: public, max-age=86400, immutable');
header('ETag: ' . $etag);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($rutaReal));
header('X-Content-Type-Options: nosniff');

// Responder 304 si el navegador tiene el recurso vigente
if (
	isset($_SERVER['HTTP_IF_NONE_MATCH'])
	&& trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag
):
	http_response_code(304);
	exit;
endif;

// Para videos, soportar byte-range requests (necesario para seek)
if ($esVideo && isset($_SERVER['HTTP_RANGE'])):
	$tamano = filesize($rutaReal);
	preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $coincidencias);
	$inicio = intval($coincidencias[1] ?? 0);
	$fin = isset($coincidencias[2]) && $coincidencias[2] !== ''
		? intval($coincidencias[2])
		: $tamano - 1;

	if ($inicio > $fin || $inicio >= $tamano):
		http_response_code(416);
		header('Content-Range: bytes */' . $tamano);
		exit;
	endif;

	http_response_code(206);
	header('Accept-Ranges: bytes');
	header('Content-Range: bytes ' . $inicio . '-' . $fin . '/' . $tamano);
	header('Content-Length: ' . ($fin - $inicio + 1));

	$gestor = fopen($rutaReal, 'rb');
	if ($gestor):
		fseek($gestor, $inicio);
		echo fread($gestor, $fin - $inicio + 1);
		fclose($gestor);
	endif;
	exit;
endif;

// Servir archivo completo
readfile($rutaReal);
