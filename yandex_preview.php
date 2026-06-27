<?php

ini_set("pcre.jit", "0");
set_time_limit(60);

require_once __DIR__ . '/src/yandexDisk.php';

function responderPreviewYandexDisk(string $cuerpo, string $mime, string $claveCache, string $estadoCache): void
{
	$mime = trim(explode(';', $mime)[0] ?? $mime);
	if ($mime === '' || !str_starts_with(mb_strtolower($mime, 'UTF-8'), 'image/')):
		$mime = 'image/jpeg';
	endif;

	$etag = '"' . sha1($claveCache . '|' . $mime . '|' . strlen($cuerpo)) . '"';
	header('Cache-Control: private, max-age=86400');
	header('ETag: ' . $etag);
	header('X-Yandex-Preview-Cache: ' . $estadoCache);
	header('X-Content-Type-Options: nosniff');

	if (
		isset($_SERVER['HTTP_IF_NONE_MATCH'])
		&& trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag
	):
		http_response_code(304);
		exit;
	endif;

	header('Content-Type: ' . $mime);
	header('Content-Length: ' . strlen($cuerpo));
	echo $cuerpo;
	exit;
}

$configuracion = cargarConfiguracion();
$photoPreview = str_replace(["\r", "\n"], '', trim((string) ($_GET['photo_preview'] ?? '')));
$ruta = normalizarRutaYandexDisk($_GET['path'] ?? '');
$tamano = normalizarTamanoPreviewYandexDisk($_GET['size'] ?? 'M');

if ($photoPreview !== ''):
	$claveCache = claveCacheMiniaturaYandexDisk('photo_preview|' . $photoPreview);
elseif ($ruta !== '/'):
	$claveCache = claveCacheMiniaturaYandexDisk('resource|' . $ruta . '|' . $tamano);
else:
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo 'Ruta de preview inválida.';
	exit;
endif;

$cache = leerCacheMiniaturaYandexDisk($claveCache);
if ($cache !== null):
	responderPreviewYandexDisk((string) ($cache['body'] ?? ''), (string) ($cache['mime'] ?? 'image/jpeg'), $claveCache, 'HIT');
endif;

if ($photoPreview !== ''):
	$preview = obtenerPreviewDesdeUrlYandexDisk($configuracion, $photoPreview);
else:
	$preview = obtenerPreviewYandexDisk($configuracion, $ruta, $tamano);
endif;

if (!($preview['ok'] ?? false)):
	$codigo = (int) ($preview['status'] ?? 502);
	if ($codigo < 400 || $codigo > 599):
		$codigo = 502;
	endif;
	http_response_code($codigo);
	header('Content-Type: text/plain; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
	echo (string) ($preview['error'] ?? 'No se pudo cargar la miniatura de Yandex Disk.');
	exit;
endif;

$cuerpo = (string) ($preview['body'] ?? '');
$mime = (string) ($preview['mime'] ?? 'image/jpeg');
guardarCacheMiniaturaYandexDisk($claveCache, $cuerpo, $mime);

responderPreviewYandexDisk($cuerpo, $mime, $claveCache, 'MISS');

?>
