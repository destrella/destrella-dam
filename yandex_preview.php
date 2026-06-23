<?php

ini_set("pcre.jit", "0");
set_time_limit(60);

require_once __DIR__ . '/src/yandexDisk.php';

$configuracion = cargarConfiguracion();
$photoPreview = str_replace(["\r", "\n"], '', trim((string) ($_GET['photo_preview'] ?? '')));
$ruta = normalizarRutaYandexDisk($_GET['path'] ?? '');
$tamano = normalizarTamanoPreviewYandexDisk($_GET['size'] ?? 'M');

if ($photoPreview !== ''):
	$preview = obtenerPreviewDesdeUrlYandexDisk($configuracion, $photoPreview);
	$claveCache = $photoPreview;
elseif ($ruta !== '/'):
	$preview = obtenerPreviewYandexDisk($configuracion, $ruta, $tamano);
	$claveCache = $ruta . '|' . $tamano;
else:
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo 'Ruta de preview inválida.';
	exit;
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
$etag = '"' . sha1($claveCache . '|' . strlen($cuerpo)) . '"';

header('Cache-Control: private, max-age=3600');
header('ETag: ' . $etag);
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($cuerpo));
header('X-Content-Type-Options: nosniff');

if (
	isset($_SERVER['HTTP_IF_NONE_MATCH'])
	&& trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag
):
	http_response_code(304);
	exit;
endif;

echo $cuerpo;

?>
