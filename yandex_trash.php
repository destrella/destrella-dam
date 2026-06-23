<?php

ini_set("pcre.jit", "0");
set_time_limit(60);

require_once __DIR__ . '/src/yandexDisk.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST'):
	http_response_code(405);
	echo json_encode(['ok' => false, 'error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
	exit;
endif;

$entrada = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($entrada)):
	$entrada = $_POST;
endif;

$ruta = normalizarRutaYandexDisk($entrada['path'] ?? '');
if ($ruta === '/'):
	http_response_code(400);
	echo json_encode(['ok' => false, 'error' => 'Ruta de papelera inválida.'], JSON_UNESCAPED_UNICODE);
	exit;
endif;

$resultado = enviarPapeleraYandexDisk(cargarConfiguracion(), $ruta);
if (!($resultado['ok'] ?? false)):
	$codigo = (int) ($resultado['status'] ?? 502);
	if ($codigo < 400 || $codigo > 599):
		$codigo = 502;
	endif;
	http_response_code($codigo);
	echo json_encode([
		'ok' => false,
		'error' => (string) ($resultado['error'] ?? 'No se pudo enviar el archivo a la papelera.'),
	], JSON_UNESCAPED_UNICODE);
	exit;
endif;

echo json_encode([
	'ok' => true,
	'path' => $ruta,
], JSON_UNESCAPED_UNICODE);

?>
