<?php

ini_set("pcre.jit", "0");
set_time_limit(60);

require_once __DIR__ . '/src/funciones.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$esYandex = !empty($_GET['yandex']);

if ($esYandex):
	require_once __DIR__ . '/src/catalogo.php';
	$opciones = [];
	$pdo = conectarCatalogoMultimedia();
	if ($pdo):
		$stmt = $pdo->query("
			SELECT DISTINCT ruta_remota
			FROM medios
			WHERE origen = 'yandex' AND existente = 1
			ORDER BY ruta_remota ASC
		");
		$vistas = ['/'=>true];
		while ($fila = $stmt->fetch(PDO::FETCH_ASSOC)):
			$ruta = (string) ($fila['ruta_remota'] ?? '');
			if ($ruta === '' || $ruta === '/'):
				continue;
			endif;
			$directorio = dirname($ruta);
			if ($directorio === '.' || $directorio === '\\'):
				$directorio = '/';
			endif;
			if (!isset($vistas[$directorio])):
				$vistas[$directorio] = true;
				$opciones[] = [
					'valor' => $directorio,
					'etiqueta' => $directorio,
				];
			endif;
			$partes = explode('/', trim($directorio, '/'));
			$acumulada = '';
			foreach ($partes as $parte):
				$acumulada = $acumulada === '' ? '/' . $parte : $acumulada . '/' . $parte;
				if (!isset($vistas[$acumulada])):
					$vistas[$acumulada] = true;
					$opciones[] = [
						'valor' => $acumulada,
						'etiqueta' => $acumulada,
					];
				endif;
			endforeach;
		endwhile;
	endif;
	usort($opciones, static fn(array $a, array $b): int => strnatcasecmp((string) ($a['valor'] ?? ''), (string) ($b['valor'] ?? '')));
	array_unshift($opciones, ['valor' => '/', 'etiqueta' => 'Raíz de Yandex.Disk']);
else:
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
endif;

echo json_encode([
	'ok' => true,
	'carpetas' => $opciones,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

?>
