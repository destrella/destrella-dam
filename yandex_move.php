<?php

ini_set("pcre.jit", "0");
set_time_limit(300);

require_once __DIR__ . '/src/yandexDisk.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responderYandexMove(int $codigo, array $datos): void
{
	if ($codigo < 100 || $codigo > 599):
		$codigo = 500;
	endif;
	http_response_code($codigo);
	echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'):
	responderYandexMove(405, ['ok' => false, 'error' => 'Método no permitido.']);
endif;

$entrada = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($entrada)):
	$entrada = $_POST;
endif;

$rutasEntrada = $entrada['paths'] ?? $entrada['rutas'] ?? [];
if (!is_array($rutasEntrada)):
	$rutasEntrada = [];
endif;

$rutas = [];
foreach ($rutasEntrada as $ruta):
	if (!is_scalar($ruta)):
		continue;
	endif;
	$normalizada = normalizarRutaYandexDisk($ruta);
	if ($normalizada === '/' || yandexDiskRutaEsUnlimited($normalizada)):
		continue;
	endif;
	$rutas[$normalizada] = $normalizada;
endforeach;
$rutas = array_values($rutas);

if (empty($rutas)):
	responderYandexMove(400, ['ok' => false, 'error' => 'No hay archivos de Yandex Disk válidos para mover.']);
endif;
if (count($rutas) > 100):
	responderYandexMove(400, ['ok' => false, 'error' => 'Selecciona 100 archivos o menos por operación.']);
endif;

$destino = normalizarRutaYandexDisk($entrada['destino'] ?? $entrada['destination'] ?? '/');
$configuracion = cargarConfiguracion();
$resultados = [];
$errores = [];
$procesados = 0;

foreach ($rutas as $ruta):
	$resultado = moverRecursoYandexDisk($configuracion, $ruta, $destino);
	$ok = (bool) ($resultado['ok'] ?? false);
	if ($ok):
		$procesados++;
	else:
		$errores[] = [
			'origen' => $ruta,
			'destino' => (string) ($resultado['destino'] ?? ''),
			'error' => (string) ($resultado['error'] ?? 'No se pudo mover el archivo.'),
			'status' => (int) ($resultado['status'] ?? 0),
		];
	endif;
	$resultados[] = [
		'ok' => $ok,
		'origen' => (string) ($resultado['origen'] ?? $ruta),
		'destino' => (string) ($resultado['destino'] ?? ''),
		'error' => (string) ($resultado['error'] ?? ''),
		'status' => (int) ($resultado['status'] ?? 0),
		'cache' => $resultado['cache'] ?? null,
	];
endforeach;

responderYandexMove(200, [
	'ok' => true,
	'destino' => $destino,
	'procesados' => $procesados,
	'errores' => $errores,
	'resultados' => $resultados,
]);

?>
