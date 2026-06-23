<?php

ini_set("pcre.jit", "0");
set_time_limit(60);

require_once __DIR__ . '/src/yandexDisk.php';

$configuracion = cargarConfiguracion();
$photoId = normalizarPhotoIdYandexDisk($_GET['photo_id'] ?? '');
$ruta = normalizarRutaYandexDisk($_GET['path'] ?? '');

if ($photoId !== ''):
	$descarga = obtenerUrlDescargaYandexPhoto($configuracion, $photoId);
elseif ($ruta !== '/'):
	$descarga = obtenerUrlDescargaYandexDisk($configuracion, $ruta);
else:
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo 'Ruta o ID de descarga inválidos.';
	exit;
endif;

if (!($descarga['ok'] ?? false)):
	$codigo = (int) ($descarga['status'] ?? 502);
	if ($codigo < 400 || $codigo > 599):
		$codigo = 502;
	endif;
	http_response_code($codigo);
	header('Content-Type: text/plain; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
	echo (string) ($descarga['error'] ?? 'No se pudo preparar la descarga de Yandex Disk.');
	exit;
endif;

$href = str_replace(["\r", "\n"], '', (string) $descarga['href']);
if (!yandexDiskUrlHostPermitido($href)):
	http_response_code(502);
	header('Content-Type: text/plain; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
	echo 'Yandex Disk devolvió un destino de descarga no permitido.';
	exit;
endif;

header('Cache-Control: no-store');
header('Location: ' . $href, true, 302);

?>
