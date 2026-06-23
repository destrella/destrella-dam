<?php

function configuracionPorDefecto(): array
{
	return [
		'elementos_por_pagina' => 8,
		'tema' => 'sistema',
		'estado_arbol' => 'expandido',
		'ruta_archivar' => 'listas',
		'yandex_disk_api_key' => '',
		'formato_temporal_imagen' => formatoTemporalImagenPorDefecto(),
		'carpetas_ignoradas' => ['.basura', 'css', 'js', 'src', 'datos', '.posters', '.dtrash'],
	];
}

function rutaEjecutableConfiguracion(string $binario): ?string
{
	foreach (['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', '/bin'] as $directorio):
		$ruta = $directorio . DIRECTORY_SEPARATOR . $binario;
		if (is_executable($ruta)):
			return $ruta;
		endif;
	endforeach;
	return null;
}

function magickTemporalWebpSoportado(): bool
{
	static $soportado = null;
	if ($soportado !== null):
		return $soportado;
	endif;

	$magick = rutaEjecutableConfiguracion('magick');
	if ($magick === null || !function_exists('shell_exec')):
		$soportado = false;
		return $soportado;
	endif;

	$salida = @shell_exec(escapeshellarg($magick) . ' -list format WEBP 2>/dev/null');
	$soportado = is_string($salida) && stripos($salida, 'WEBP') !== false;
	return $soportado;
}

function magickTemporalImagenDisponible(): bool
{
	return rutaEjecutableConfiguracion('magick') !== null;
}

function conversorTemporalImagenDisponible(): bool
{
	return magickTemporalImagenDisponible()
		|| rutaEjecutableConfiguracion('sips') !== null
		|| rutaEjecutableConfiguracion('exiftool') !== null;
}

function webpTemporalImagenSoportado(): bool
{
	return conversorTemporalImagenDisponible()
		&& (magickTemporalWebpSoportado() || rutaEjecutableConfiguracion('cwebp') !== null);
}

function formatoTemporalImagenPorDefecto(): string
{
	return webpTemporalImagenSoportado() ? 'webp' : 'jpg';
}

function rutaArchivoConfiguracion(): string
{
	return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'datos' . DIRECTORY_SEPARATOR . 'configuracion.json';
}

function normalizarCarpetasIgnoradas(mixed $valor): array
{
	$valores = is_array($valor) ? $valor : preg_split('/[\r\n,]+/', (string) $valor);
	$salida = [];
	$vistos = [];
	foreach (($valores ?: []) as $carpeta):
		$carpeta = str_replace('\\', '/', (string) $carpeta);
		// Si es una ruta absoluta (empieza con /), solo limpiar espacios y
		// barra final, pero preservar la barra inicial.
		if (str_starts_with($carpeta, '/')):
			$carpeta = rtrim($carpeta, "/ \t\n\r\0\x0B");
		else:
			$carpeta = trim($carpeta, "/ \t\n\r\0\x0B");
		endif;
		if ($carpeta === ''):
			continue;
		endif;
		$clave = mb_strtolower($carpeta, 'UTF-8');
		if (isset($vistos[$clave])):
			continue;
		endif;
		$vistos[$clave] = true;
		$salida[] = $carpeta;
	endforeach;
	return $salida;
}

function normalizarRutaRelativaConfiguracion(string $ruta, string $fallback): string
{
	$ruta = trim(str_replace('\\', '/', $ruta));
	$ruta = trim($ruta, "/ \t\n\r\0\x0B");
	if ($ruta === '' || str_starts_with($ruta, '/') || preg_match('~(^|/)\.\.(/|$)~', $ruta)):
		return $fallback;
	endif;
	return $ruta;
}

function normalizarYandexDiskApiKey(mixed $valor): string
{
	$token = trim((string) $valor);
	$token = preg_replace('/^Authorization:\s*/i', '', $token) ?? $token;
	$token = preg_replace('/^(OAuth|Bearer)\s+/i', '', $token) ?? $token;
	return trim($token);
}

function normalizarFormatoTemporalImagen(mixed $valor): string
{
	$formato = strtolower(trim((string) $valor));
	if (!in_array($formato, ['jpg', 'webp'], true)):
		$formato = formatoTemporalImagenPorDefecto();
	endif;
	if ($formato === 'webp' && !webpTemporalImagenSoportado()):
		return 'jpg';
	endif;
	return $formato;
}

function normalizarConfiguracion(array $datos): array
{
	$defaults = configuracionPorDefecto();
	$config = array_replace($defaults, $datos);

	$config['elementos_por_pagina'] = max(3, min(21, (int) ($config['elementos_por_pagina'] ?? $defaults['elementos_por_pagina'])));
	if (!in_array($config['tema'], ['sistema', 'claro', 'oscuro'], true)):
		$config['tema'] = $defaults['tema'];
	endif;
	if (!in_array($config['estado_arbol'], ['expandido', 'colapsado'], true)):
		$config['estado_arbol'] = $defaults['estado_arbol'];
	endif;
	$config['ruta_archivar'] = normalizarRutaRelativaConfiguracion((string) ($config['ruta_archivar'] ?? ''), $defaults['ruta_archivar']);
	$config['yandex_disk_api_key'] = normalizarYandexDiskApiKey($config['yandex_disk_api_key'] ?? '');
	$config['formato_temporal_imagen'] = normalizarFormatoTemporalImagen($config['formato_temporal_imagen'] ?? $defaults['formato_temporal_imagen']);
	$config['carpetas_ignoradas'] = normalizarCarpetasIgnoradas($config['carpetas_ignoradas'] ?? []);

	return $config;
}

function cargarConfiguracion(): array
{
	static $configuracion = null;
	if ($configuracion !== null):
		return $configuracion;
	endif;

	$archivo = rutaArchivoConfiguracion();
	$datos = [];
	if (is_file($archivo)):
		$json = json_decode((string) file_get_contents($archivo), true);
		if (is_array($json)):
			$datos = $json;
		endif;
	endif;

	$configuracion = normalizarConfiguracion($datos);
	return $configuracion;
}

function guardarConfiguracion(array $datos): array
{
	$configuracion = normalizarConfiguracion($datos);
	$archivo = rutaArchivoConfiguracion();
	$directorio = dirname($archivo);
	if (!is_dir($directorio)):
		mkdir($directorio, 0755, true);
	endif;
	file_put_contents($archivo, json_encode($configuracion, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	return $configuracion;
}

function atributoTemaConfiguracion(?array $configuracion = null): string
{
	$configuracion ??= cargarConfiguracion();
	$tema = $configuracion['tema'] ?? 'sistema';
	if (!in_array($tema, ['claro', 'oscuro'], true)):
		return '';
	endif;
	return ' data-tema="' . htmlspecialchars($tema, ENT_QUOTES, 'UTF-8') . '"';
}

function carpetasIgnoradasConfiguracion(?array $configuracion = null): array
{
	$configuracion ??= cargarConfiguracion();
	return normalizarCarpetasIgnoradas($configuracion['carpetas_ignoradas'] ?? []);
}

function yandexDiskApiKeyConfiguracion(?array $configuracion = null): string
{
	$configuracion ??= cargarConfiguracion();
	return normalizarYandexDiskApiKey($configuracion['yandex_disk_api_key'] ?? '');
}

function formatoTemporalImagenConfiguracion(?array $configuracion = null): string
{
	$configuracion ??= cargarConfiguracion();
	return normalizarFormatoTemporalImagen($configuracion['formato_temporal_imagen'] ?? formatoTemporalImagenPorDefecto());
}

function extensionesMultimediaConfiguracion(): array
{
	return ['jpg', 'jpeg', 'webp', 'png', 'heic', 'gif', 'cr2', 'dng', 'mp4', 'mov', 'mkv', 'webm', 'avi', 'm4v'];
}

function contarMultimediaBasura(): int
{
	$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.basura';
	if (!is_dir($dir)):
		return 0;
	endif;

	$total = 0;
	$extensiones = array_flip(extensionesMultimediaConfiguracion());
	$iterador = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
	);
	foreach ($iterador as $archivo):
		if (!$archivo->isFile()):
			continue;
		endif;
		$ext = strtolower($archivo->getExtension());
		if (isset($extensiones[$ext])):
			$total++;
		endif;
	endforeach;
	return $total;
}

function menuConfiguracion(string $activa = 'configuracion'): string
{
	$basura = contarMultimediaBasura();
	$items = [
		'configuracion' => ['configuracion.php', '⚙️ Configuración'],
		'limpieza' => ['basurero.php', '🗑️ Limpieza'],
		'nombres' => ['nombres.php', '👤 Nombres'],
		'ubicaciones' => ['ubicaciones.php', '📍 Ubicaciones'],
	];

	$html = '<aside class="config-menu" aria-label="Configuración"><nav>';
	foreach ($items as $clave => [$href, $texto]):
		$clase = 'config-menu-link' . ($clave === $activa ? ' activo' : '');
		$html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="' . $clase . '">';
		$html .= '<span>' . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . '</span>';
		if ($clave === 'limpieza' && $basura > 0):
			$html .= '<span class="config-menu-badge" title="Elementos multimedia en .basura">' . $basura . '</span>';
		endif;
		$html .= '</a>';
	endforeach;
	$html .= '<a href="index.php" class="config-menu-link config-menu-volver">← Vista principal</a>';
	$html .= '</nav></aside>';
	return $html;
}

?>
