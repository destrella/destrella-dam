<?php

ini_set("pcre.jit", "0");
set_time_limit(60);

require_once __DIR__ . '/src/funciones.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$configuracion = cargarConfiguracion();
$root = realpath(proyectoRaiz()) ?: proyectoRaiz();
$carpetas = listarCarpetas($root, carpetasIgnoradasConfiguracion($configuracion));

$opciones = [
	[
		'valor' => '',
		'etiqueta' => 'Raíz del proyecto',
	],
];

foreach ($carpetas as $carpeta):
	if (!is_dir($carpeta)):
		continue;
	endif;
	$relativa = rutaRelativaDesdeProyecto($carpeta);
	if ($relativa === ''):
		continue;
	endif;
	$opciones[] = [
		'valor' => $relativa,
		'etiqueta' => $relativa,
	];
endforeach;

usort($opciones, static function (array $a, array $b): int {
	if (($a['valor'] ?? '') === ''):
		return -1;
	endif;
	if (($b['valor'] ?? '') === ''):
		return 1;
	endif;
	return strnatcasecmp((string) ($a['etiqueta'] ?? ''), (string) ($b['etiqueta'] ?? ''));
});

echo json_encode([
	'ok' => true,
	'root' => $root,
	'carpetas' => $opciones,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

?>
