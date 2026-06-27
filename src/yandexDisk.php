<?php

require_once __DIR__ . '/configuracion.php';

const YANDEX_DISK_API_BASE = 'https://cloud-api.yandex.net/v1/disk';
const YANDEX_DISK_UNLIMITED_PATH = '/Unlimited storage';
const YANDEX_DISK_PHOTOS_LABEL = 'Photos / Unlimited storage';
const YANDEX_DISK_CACHE_VERSION = 1;
const YANDEX_DISK_CACHE_TTL = 600;
const YANDEX_DISK_LIGHTBOX_PREVIEW_SIZE = 'XXXL';
const YANDEX_DISK_PREVIEW_CACHE_VERSION = 1;

function estadoYandexDiskVacio(bool $configurada): array
{
	return [
		'configurada' => $configurada,
		'ok' => false,
		'error' => '',
		'items' => [],
		'directorios' => [],
		'multimedia' => [],
		'limit' => 0,
		'offset' => 0,
		'total' => 0,
		'total_consultado' => 0,
		'total_directorios' => 0,
		'total_multimedia' => 0,
		'total_multimedia_conocido' => true,
		'truncado' => false,
		'ruta' => '/',
		'vista' => 'disk',
		'orden' => 'name',
		'espacio' => null,
		'cache' => [
			'hit' => false,
			'stale' => false,
			'key' => '',
			'created_at' => 0,
		],
		'unlimited' => null,
	];
}

function rutaDirectorioCacheYandexDisk(): string
{
	return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'datos' . DIRECTORY_SEPARATOR . 'yandex_cache';
}

function rutaArchivoIndiceYandexDisk(): string
{
	return rutaDirectorioCacheYandexDisk() . DIRECTORY_SEPARATOR . 'recursos_index.json';
}

function rutaDirectorioCacheMiniaturasYandexDisk(): string
{
	return dirname(__DIR__) . DIRECTORY_SEPARATOR . '.posters' . DIRECTORY_SEPARATOR . 'Yandex';
}

function prepararDirectorioCacheYandexDisk(): bool
{
	$directorio = rutaDirectorioCacheYandexDisk();
	if (!is_dir($directorio) && !mkdir($directorio, 0755, true)):
		return false;
	endif;

	return is_dir($directorio) && is_writable($directorio);
}

function prepararDirectorioCacheMiniaturasYandexDisk(?string $subdirectorio = null): bool
{
	$directorio = $subdirectorio ?? rutaDirectorioCacheMiniaturasYandexDisk();
	if (!is_dir($directorio) && !mkdir($directorio, 0755, true)):
		return false;
	endif;

	return is_dir($directorio) && is_writable($directorio);
}

function ordenarRecursivoClaveYandexDisk(mixed $valor): mixed
{
	if (!is_array($valor)):
		return $valor;
	endif;

	ksort($valor);
	foreach ($valor as $clave => $contenido):
		$valor[$clave] = ordenarRecursivoClaveYandexDisk($contenido);
	endforeach;

	return $valor;
}

function claveCacheYandexDisk(string $tipo, array $parametros): string
{
	$payload = [
		'version' => YANDEX_DISK_CACHE_VERSION,
		'tipo' => $tipo,
		'parametros' => ordenarRecursivoClaveYandexDisk($parametros),
	];

	return $tipo . '-' . sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function leerCacheYandexDisk(string $clave, ?int $ttl = YANDEX_DISK_CACHE_TTL): ?array
{
	$archivo = rutaDirectorioCacheYandexDisk() . DIRECTORY_SEPARATOR . basename($clave) . '.json';
	if (!is_file($archivo)):
		return null;
	endif;

	$contenido = file_get_contents($archivo);
	if ($contenido === false || trim($contenido) === ''):
		return null;
	endif;

	$cache = json_decode($contenido, true);
	if (!is_array($cache) || (int) ($cache['version'] ?? 0) !== YANDEX_DISK_CACHE_VERSION):
		return null;
	endif;

	$creado = (int) ($cache['created_at'] ?? 0);
	if ($creado <= 0):
		return null;
	endif;

	if ($ttl !== null && (time() - $creado) > $ttl):
		return null;
	endif;

	return is_array($cache['data'] ?? null) ? $cache : null;
}

function leerCacheVencidoYandexDisk(string $clave): ?array
{
	return leerCacheYandexDisk($clave, null);
}

function guardarCacheYandexDisk(string $clave, array $datos): void
{
	if (!prepararDirectorioCacheYandexDisk()):
		return;
	endif;

	$archivo = rutaDirectorioCacheYandexDisk() . DIRECTORY_SEPARATOR . basename($clave) . '.json';
	$payload = [
		'version' => YANDEX_DISK_CACHE_VERSION,
		'created_at' => time(),
		'data' => $datos,
	];

	file_put_contents($archivo, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function claveCacheMiniaturaYandexDisk(string $fuente): string
{
	return sha1(YANDEX_DISK_PREVIEW_CACHE_VERSION . '|' . $fuente);
}

function extensionCacheMiniaturaYandexDisk(string $mime): string
{
	$mime = mb_strtolower(trim(explode(';', $mime)[0] ?? ''), 'UTF-8');
	return match ($mime) {
		'image/png' => 'png',
		'image/webp' => 'webp',
		'image/gif' => 'gif',
		'image/avif' => 'avif',
		'image/bmp', 'image/x-ms-bmp' => 'bmp',
		default => 'jpg',
	};
}

function rutasCacheMiniaturaYandexDisk(string $clave): array
{
	$clave = preg_replace('/[^a-f0-9]/', '', mb_strtolower($clave, 'UTF-8')) ?: sha1($clave);
	$directorio = rutaDirectorioCacheMiniaturasYandexDisk() . DIRECTORY_SEPARATOR . substr($clave, 0, 2);
	return [
		'clave' => $clave,
		'dir' => $directorio,
		'meta' => $directorio . DIRECTORY_SEPARATOR . $clave . '.json',
	];
}

function leerCacheMiniaturaYandexDisk(string $clave): ?array
{
	$rutas = rutasCacheMiniaturaYandexDisk($clave);
	if (!is_file($rutas['meta'])):
		return null;
	endif;

	$meta = json_decode((string) file_get_contents($rutas['meta']), true);
	if (!is_array($meta) || (int) ($meta['version'] ?? 0) !== YANDEX_DISK_PREVIEW_CACHE_VERSION):
		return null;
	endif;

	$archivo = (string) ($meta['archivo'] ?? '');
	if ($archivo === '' || basename($archivo) !== $archivo):
		return null;
	endif;

	$rutaArchivo = $rutas['dir'] . DIRECTORY_SEPARATOR . $archivo;
	if (!is_file($rutaArchivo)):
		return null;
	endif;

	$cuerpo = file_get_contents($rutaArchivo);
	if ($cuerpo === false || $cuerpo === ''):
		return null;
	endif;

	$mime = (string) ($meta['mime'] ?? 'image/jpeg');
	if ($mime === '' || !str_starts_with(mb_strtolower($mime, 'UTF-8'), 'image/')):
		$mime = 'image/jpeg';
	endif;

	return [
		'ok' => true,
		'status' => 200,
		'error' => '',
		'mime' => $mime,
		'body' => $cuerpo,
		'cache' => [
			'hit' => true,
			'created_at' => (int) ($meta['created_at'] ?? 0),
			'archivo' => $rutaArchivo,
		],
	];
}

function guardarCacheMiniaturaYandexDisk(string $clave, string $cuerpo, string $mime): bool
{
	if ($cuerpo === ''):
		return false;
	endif;

	$rutas = rutasCacheMiniaturaYandexDisk($clave);
	if (!prepararDirectorioCacheMiniaturasYandexDisk($rutas['dir'])):
		return false;
	endif;

	$mime = (string) (explode(';', $mime)[0] ?? $mime);
	if ($mime === '' || !str_starts_with(mb_strtolower($mime, 'UTF-8'), 'image/')):
		$mime = 'image/jpeg';
	endif;

	$archivo = $rutas['clave'] . '.' . extensionCacheMiniaturaYandexDisk($mime);
	$rutaArchivo = $rutas['dir'] . DIRECTORY_SEPARATOR . $archivo;
	$temporal = $rutaArchivo . '.tmp.' . getmypid();
	if (file_put_contents($temporal, $cuerpo, LOCK_EX) === false):
		@unlink($temporal);
		return false;
	endif;
	if (!@rename($temporal, $rutaArchivo)):
		@unlink($temporal);
		return false;
	endif;

	$meta = [
		'version' => YANDEX_DISK_PREVIEW_CACHE_VERSION,
		'created_at' => time(),
		'mime' => $mime,
		'bytes' => strlen($cuerpo),
		'archivo' => $archivo,
	];
	return file_put_contents($rutas['meta'], json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

function yandexDiskCacheItemCoincideRuta(array $item, string $ruta): bool
{
	$meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
	foreach (['path', 'id'] as $campo):
		if (isset($item[$campo]) && is_scalar($item[$campo]) && normalizarRutaYandexDisk($item[$campo]) === $ruta):
			return true;
		endif;
		if (isset($meta[$campo]) && is_scalar($meta[$campo]) && normalizarRutaYandexDisk($meta[$campo]) === $ruta):
			return true;
		endif;
	endforeach;

	return false;
}

function yandexDiskCacheFiltrarItemsPorRuta(array $items, string $ruta, int &$eliminados): array
{
	$filtrados = [];
	foreach ($items as $item):
		if (is_array($item) && yandexDiskCacheItemCoincideRuta($item, $ruta)):
			$eliminados++;
			continue;
		endif;
		$filtrados[] = $item;
	endforeach;

	return $filtrados;
}

function yandexDiskReconstruirGruposMd5Indice(array &$indice): void
{
	$recursos = is_array($indice['resources'] ?? null) ? $indice['resources'] : [];
	ksort($recursos);
	$indice['resources'] = $recursos;
	$indice['md5_groups'] = [];

	$grupos = [];
	foreach ($indice['resources'] as $ruta => $entrada):
		if (!is_array($entrada)):
			continue;
		endif;
		$md5 = trim((string) ($entrada['md5'] ?? ''));
		if ($md5 === ''):
			continue;
		endif;
		$grupos[$md5][] = (string) $ruta;
	endforeach;

	foreach ($grupos as $md5 => $rutas):
		if (count($rutas) > 1):
			sort($rutas);
			$indice['md5_groups'][$md5] = $rutas;
		endif;
	endforeach;
	ksort($indice['md5_groups']);
}

function eliminarIndiceRecursoYandexDisk(string $ruta): bool
{
	$ruta = normalizarRutaYandexDisk($ruta);
	if ($ruta === '/' || !function_exists('catalogoMarcarYandexAusente')):
		return false;
	endif;

	return catalogoMarcarYandexAusente($ruta) > 0;
}

function depurarCacheRecursoYandexDisk(string $ruta): array
{
	$ruta = normalizarRutaYandexDisk($ruta);
	$resultado = [
		'ruta' => $ruta,
		'archivos_actualizados' => 0,
		'items_eliminados' => 0,
		'indice_actualizado' => false,
	];
	if ($ruta === '/' || !prepararDirectorioCacheYandexDisk()):
		return $resultado;
	endif;

	foreach (glob(rutaDirectorioCacheYandexDisk() . DIRECTORY_SEPARATOR . 'resources*.json') ?: [] as $archivo):
		if (!is_file($archivo)):
			continue;
		endif;

		$payload = json_decode((string) file_get_contents($archivo), true);
		if (!is_array($payload) || (int) ($payload['version'] ?? 0) !== YANDEX_DISK_CACHE_VERSION || !is_array($payload['data'] ?? null)):
			continue;
		endif;

		if (yandexDiskCacheItemCoincideRuta($payload['data'], $ruta)):
			if (@unlink($archivo)):
				$resultado['archivos_actualizados']++;
				$resultado['items_eliminados']++;
			endif;
			continue;
		endif;

		$eliminados = 0;
		if (is_array($payload['data']['_embedded']['items'] ?? null)):
			$payload['data']['_embedded']['items'] = yandexDiskCacheFiltrarItemsPorRuta($payload['data']['_embedded']['items'], $ruta, $eliminados);
			if ($eliminados > 0 && isset($payload['data']['_embedded']['total']) && is_numeric($payload['data']['_embedded']['total'])):
				$payload['data']['_embedded']['total'] = max(0, (int) $payload['data']['_embedded']['total'] - $eliminados);
			endif;
		endif;

		if (is_array($payload['data']['items'] ?? null)):
			$payload['data']['items'] = yandexDiskCacheFiltrarItemsPorRuta($payload['data']['items'], $ruta, $eliminados);
		endif;

		if ($eliminados <= 0):
			continue;
		endif;

		if (file_put_contents($archivo, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false):
			$resultado['archivos_actualizados']++;
			$resultado['items_eliminados'] += $eliminados;
		endif;
	endforeach;

	$resultado['indice_actualizado'] = eliminarIndiceRecursoYandexDisk($ruta);
	return $resultado;
}

function invalidarCacheDirectorioYandexDisk(string $ruta): int
{
	$ruta = normalizarRutaYandexDisk($ruta);
	if (!prepararDirectorioCacheYandexDisk()):
		return 0;
	endif;

	$eliminados = 0;
	foreach (glob(rutaDirectorioCacheYandexDisk() . DIRECTORY_SEPARATOR . 'resources-*.json') ?: [] as $archivo):
		if (!is_file($archivo)):
			continue;
		endif;
		$payload = json_decode((string) file_get_contents($archivo), true);
		if (!is_array($payload) || (int) ($payload['version'] ?? 0) !== YANDEX_DISK_CACHE_VERSION || !is_array($payload['data'] ?? null)):
			continue;
		endif;
		$rutaCache = (string) ($payload['data']['path'] ?? $payload['data']['_embedded']['path'] ?? '');
		if ($rutaCache !== '' && normalizarRutaYandexDisk($rutaCache) === $ruta && @unlink($archivo)):
			$eliminados++;
		endif;
	endforeach;

	return $eliminados;
}

function invalidarCacheUltimosSubidosYandexDisk(): int
{
	if (!prepararDirectorioCacheYandexDisk()):
		return 0;
	endif;

	$eliminados = 0;
	foreach (glob(rutaDirectorioCacheYandexDisk() . DIRECTORY_SEPARATOR . 'resources-last-uploaded-*.json') ?: [] as $archivo):
		if (is_file($archivo) && @unlink($archivo)):
			$eliminados++;
		endif;
	endforeach;
	return $eliminados;
}

function yandexDiskMarcarRecursoAusente(string $ruta, string $motivo = ''): array
{
	$ruta = normalizarRutaYandexDisk($ruta);
	$resultado = depurarCacheRecursoYandexDisk($ruta);
	$resultado['catalogo_actualizados'] = 0;
	$resultado['motivo'] = $motivo;
	if ($ruta === '/'):
		return $resultado;
	endif;

	$soporte = __DIR__ . DIRECTORY_SEPARATOR . 'soporte.php';
	if (is_file($soporte)):
		require_once $soporte;
	endif;
	$catalogo = __DIR__ . DIRECTORY_SEPARATOR . 'catalogo.php';
	if (is_file($catalogo)):
		require_once $catalogo;
	endif;
	if (function_exists('catalogoMarcarYandexAusente')):
		$resultado['catalogo_actualizados'] = catalogoMarcarYandexAusente($ruta);
	endif;

	return $resultado;
}

function yandexDiskExtraerValorTexto(array $item, array $campos, array $meta = []): string
{
	foreach ($campos as $campo):
		if (isset($item[$campo]) && is_scalar($item[$campo])):
			$valor = trim((string) $item[$campo]);
			if ($valor !== ''):
				return $valor;
			endif;
		endif;
		if (isset($meta[$campo]) && is_scalar($meta[$campo])):
			$valor = trim((string) $meta[$campo]);
			if ($valor !== ''):
				return $valor;
			endif;
		endif;
	endforeach;

	return '';
}

function yandexDiskExtraerEntradaIndice(array $item, bool $requiereHash = true): ?array
{
	$tipo = (string) ($item['type'] ?? 'file');
	if ($tipo !== 'file'):
		return null;
	endif;

	$meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
	$rutaFuente = yandexDiskExtraerValorTexto($item, ['path', 'ruta', 'id'], $meta);
	if ($rutaFuente === ''):
		return null;
	endif;

	$ruta = normalizarRutaYandexDisk($rutaFuente);
	if ($ruta === '/'):
		return null;
	endif;

	$md5 = yandexDiskExtraerValorTexto($item, ['md5'], $meta);
	$sha256 = yandexDiskExtraerValorTexto($item, ['sha256'], $meta);
	$esMultimedia = yandexDiskEsItemMultimedia($item);
	if ($requiereHash && $md5 === ''):
		return null;
	endif;
	if (!$requiereHash && !$esMultimedia):
		return null;
	endif;

	$nombre = yandexDiskExtraerValorTexto($item, ['name', 'nombre'], $meta);
	if ($nombre === ''):
		$nombre = basename($ruta);
	endif;

	$tamanoFuente = $item['size'] ?? $meta['size'] ?? null;
	$tamano = is_numeric($tamanoFuente) ? max(0, (int) $tamanoFuente) : null;
	$mime = yandexDiskExtraerValorTexto($item, ['mime_type', 'mimetype', 'mime'], $meta);
	$mediaType = mb_strtolower(yandexDiskExtraerValorTexto($item, ['media_type', 'mediatype'], $meta), 'UTF-8');
	if ($mediaType === '' && in_array(mb_strtolower((string) ($item['tipo'] ?? ''), 'UTF-8'), ['image', 'video'], true)):
		$mediaType = mb_strtolower((string) $item['tipo'], 'UTF-8');
	endif;
	$exif = is_array($item['exif'] ?? null) ? $item['exif'] : (is_array($meta['exif'] ?? null) ? $meta['exif'] : []);
	$ancho = yandexDiskEnteroDesdeCampos($item, ['width', 'image_width', 'ImageWidth'])
		?? yandexDiskEnteroDesdeCampos($meta, ['width', 'image_width', 'ImageWidth'])
		?? yandexDiskEnteroDesdeCampos($exif, ['width', 'image_width', 'ImageWidth', 'ExifImageWidth', 'PixelXDimension']);
	$alto = yandexDiskEnteroDesdeCampos($item, ['height', 'image_height', 'ImageHeight'])
		?? yandexDiskEnteroDesdeCampos($meta, ['height', 'image_height', 'ImageHeight'])
		?? yandexDiskEnteroDesdeCampos($exif, ['height', 'image_height', 'ImageHeight', 'ExifImageHeight', 'PixelYDimension']);
	$duracion = yandexDiskEnteroDesdeCampos($item, ['duration', 'duracion'])
		?? yandexDiskEnteroDesdeCampos($meta, ['duration', 'duracion'])
		?? yandexDiskEnteroDesdeCampos($exif, ['duration', 'duracion']);
	$desdeUnlimited = yandexDiskRutaEsPhotounlim($ruta) || yandexDiskRutaEsPhotounlim((string) ($item['id'] ?? ''));

	return [
		'ruta' => $ruta,
		'nombre' => $nombre,
		'tipo' => $tipo,
		'mime' => $mime,
		'media_type' => $mediaType,
		'es_multimedia' => $esMultimedia,
		'md5' => $md5,
		'sha256' => $sha256,
		'resource_id' => yandexDiskExtraerValorTexto($item, ['resource_id'], $meta),
		'tamano' => $tamano,
		'ancho' => $ancho ?? 0,
		'alto' => $alto ?? 0,
		'duracion' => $duracion ?? 0,
		'exif' => $exif,
		'creado' => yandexDiskFechaRecurso($item['created'] ?? $item['creado'] ?? $item['ctime'] ?? ''),
		'modificado' => yandexDiskFechaRecurso($item['modified'] ?? $item['modificado'] ?? $item['mtime'] ?? $item['utime'] ?? $item['created'] ?? $item['creado'] ?? $item['ctime'] ?? ''),
		'desde_unlimited' => $desdeUnlimited,
		'origen' => $desdeUnlimited ? 'From unlimited storage' : 'Yandex Disk',
		'visto_en' => gmdate(DATE_ATOM),
	];
}

function actualizarIndiceRecursosYandexDisk(array $recursos, bool $requiereHash = true): void
{
	if (!function_exists('conectarCatalogoMultimedia') || !function_exists('catalogoGuardarYandex')):
		return;
	endif;

	$pdo = conectarCatalogoMultimedia();
	if (!$pdo):
		return;
	endif;

	foreach ($recursos as $item):
		if (!is_array($item)):
			continue;
		endif;
		$entrada = yandexDiskExtraerEntradaIndice($item, $requiereHash);
		if ($entrada === null):
			continue;
		endif;
		catalogoGuardarYandex($pdo, $entrada, time());
	endforeach;
}

function actualizarIndiceEntradaYandexDisk(array $entrada): bool
{
	$ruta = normalizarRutaYandexDisk((string) ($entrada['ruta'] ?? ''));
	if ($ruta === '/'):
		return false;
	endif;
	if (!function_exists('conectarCatalogoMultimedia') || !function_exists('catalogoGuardarYandex')):
		return false;
	endif;

	$entrada['ruta'] = $ruta;
	$pdo = conectarCatalogoMultimedia();
	return $pdo ? catalogoGuardarYandex($pdo, $entrada, time()) : false;
}

function yandexDiskRutaVisible(string $ruta): string
{
	$ruta = trim(str_replace('\\', '/', $ruta));
	if (str_starts_with($ruta, 'disk:')):
		$ruta = substr($ruta, 5);
	endif;
	if ($ruta === '/disk'):
		return '/';
	endif;
	if (str_starts_with($ruta, '/disk/')):
		$ruta = substr($ruta, 5);
	endif;
	if ($ruta === '' || $ruta === '/'):
		return '/';
	endif;
	return '/' . ltrim($ruta, '/');
}

function yandexDiskUrlCliente(string $ruta): string
{
	$ruta = yandexDiskRutaVisible($ruta);
	$segmentos = array_values(array_filter(explode('/', trim($ruta, '/')), fn($segmento) => $segmento !== ''));
	if (empty($segmentos)):
		return 'https://disk.yandex.com/client/disk';
	endif;
	return 'https://disk.yandex.com/client/disk/' . implode('/', array_map('rawurlencode', $segmentos));
}

function yandexDiskPreviewProxyUrl(string $ruta, string $tamano = 'M'): string
{
	return 'yandex_preview.php?path=' . rawurlencode(normalizarRutaYandexDisk($ruta)) . '&size=' . rawurlencode($tamano);
}

function yandexDiskDownloadUrl(string $ruta): string
{
	return 'yandex_download.php?path=' . rawurlencode(normalizarRutaYandexDisk($ruta));
}

function yandexDiskMediaProxyUrl(string $ruta): string
{
	return 'yandex_media.php?path=' . rawurlencode(normalizarRutaYandexDisk($ruta));
}

function yandexDiskLightboxPreviewProxyUrl(string $ruta): string
{
	return yandexDiskPreviewProxyUrl($ruta, YANDEX_DISK_LIGHTBOX_PREVIEW_SIZE);
}

function yandexDiskPhotoPreviewProxyUrl(string $preview): string
{
	return 'yandex_preview.php?photo_preview=' . rawurlencode($preview);
}

function yandexDiskPhotoDownloadUrl(string $id): string
{
	return 'yandex_download.php?photo_id=' . rawurlencode(normalizarPhotoIdYandexDisk($id));
}

function yandexDiskPhotoMediaProxyUrl(string $id, string $tipo = 'image', string $nombre = ''): string
{
	$parametros = [
		'photo_id' => normalizarPhotoIdYandexDisk($id),
		'type' => $tipo === 'video' ? 'video' : 'image',
	];
	if ($nombre !== ''):
		$parametros['name'] = $nombre;
	endif;

	return 'yandex_media.php?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
}

function normalizarRutaYandexDisk(mixed $valor): string
{
	$ruta = yandexDiskRutaVisible((string) $valor);
	$partes = [];
	foreach (explode('/', trim($ruta, '/')) as $parte):
		$parte = trim($parte);
		if ($parte === '' || $parte === '.'):
			continue;
		endif;
		if ($parte === '..'):
			array_pop($partes);
			continue;
		endif;
		$partes[] = $parte;
	endforeach;

	return empty($partes) ? '/' : '/' . implode('/', $partes);
}

function rutaApiYandexDisk(string $ruta): string
{
	return 'disk:' . normalizarRutaYandexDisk($ruta);
}

function rutaYandexDiskDesdeFuente(?array $fuente = null): string
{
	$fuente ??= $_GET;
	return normalizarRutaYandexDisk($fuente['yandex_path'] ?? '/');
}

function normalizarOrdenYandexDisk(mixed $valor): string
{
	$orden = trim((string) $valor);
	$ordenNormalizado = mb_strtolower($orden, 'UTF-8');
	if (in_array($ordenNormalizado, ['last-uploaded', 'last_uploaded'], true)):
		return 'last-uploaded';
	endif;

	$descendente = str_starts_with($orden, '-');
	$campo = $descendente ? substr($orden, 1) : $orden;
	$campo = mb_strtolower(trim($campo), 'UTF-8');

	if (!in_array($campo, ['name', 'created', 'size', 'modified'], true)):
		$campo = 'name';
		$descendente = false;
	endif;

	return ($descendente ? '-' : '') . $campo;
}

function ordenYandexDiskEsUltimosSubidos(string $orden): bool
{
	return normalizarOrdenYandexDisk($orden) === 'last-uploaded';
}

function ordenYandexDiskDesdeFuente(?array $fuente = null): string
{
	$fuente ??= $_GET;
	return normalizarOrdenYandexDisk($fuente['yandex_sort'] ?? 'name');
}

function urlPanelYandexDisk(string $ruta, string $orden = 'name'): string
{
	$orden = normalizarOrdenYandexDisk($orden);
	$parametros = [
		'panel' => 'yandex',
		'yandex_path' => normalizarRutaYandexDisk($ruta),
	];
	if ($orden !== 'name'):
		$parametros['yandex_sort'] = $orden;
	endif;

	return '?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
}

function rutaPadreYandexDisk(string $ruta): ?string
{
	$ruta = normalizarRutaYandexDisk($ruta);
	if ($ruta === '/'):
		return null;
	endif;

	$partes = explode('/', trim($ruta, '/'));
	array_pop($partes);
	return empty($partes) ? '/' : '/' . implode('/', $partes);
}

function rutaHijaYandexDisk(string $directorio, string $nombre): string
{
	$directorio = normalizarRutaYandexDisk($directorio);
	$nombre = basename(str_replace('\\', '/', trim($nombre)));
	if ($nombre === '' || $nombre === '.' || $nombre === '..'):
		return '/';
	endif;

	return normalizarRutaYandexDisk(($directorio === '/' ? '' : $directorio) . '/' . $nombre);
}

function yandexDiskFormatoTamano(?int $bytes): string
{
	if ($bytes === null || $bytes < 0):
		return '';
	endif;

	$unidades = ['B', 'KB', 'MB', 'GB', 'TB'];
	$valor = (float) $bytes;
	$indice = 0;
	while ($valor >= 1024 && $indice < count($unidades) - 1):
		$valor /= 1024;
		$indice++;
	endwhile;

	$decimales = $indice === 0 ? 0 : 1;
	return number_format($valor, $decimales) . ' ' . $unidades[$indice];
}

function yandexDiskEnteroDesdeCampos(array $datos, array $campos): ?int
{
	foreach ($campos as $campo):
		if (isset($datos[$campo]) && is_numeric($datos[$campo])):
			return max(0, (int) $datos[$campo]);
		endif;
	endforeach;

	return null;
}

function obtenerEspacioYandexDisk(array $configuracion): array
{
	$token = yandexDiskApiKeyConfiguracion($configuracion);
	if ($token === ''):
		return ['ok' => false, 'status' => 401, 'error' => 'No hay API Key de Yandex.Disk configurada.'];
	endif;

	$claveCache = claveCacheYandexDisk('disk', ['_token_hash' => hash('sha256', $token)]);
	$cache = leerCacheYandexDisk($claveCache);
	$cacheVencido = null;
	if ($cache !== null):
		$respuesta = [
			'ok' => true,
			'status' => 200,
			'error' => '',
			'data' => $cache['data'],
		];
	else:
		$respuesta = yandexDiskPeticion('', [], $token, 10);
		if ($respuesta['ok'] && is_array($respuesta['data'] ?? null)):
			guardarCacheYandexDisk($claveCache, $respuesta['data']);
		else:
			$cacheVencido = leerCacheVencidoYandexDisk($claveCache);
			if ($cacheVencido !== null):
				$respuesta = [
					'ok' => true,
					'status' => 200,
					'error' => '',
					'data' => $cacheVencido['data'],
				];
			endif;
		endif;
	endif;
	if (!$respuesta['ok']):
		return [
			'ok' => false,
			'status' => (int) ($respuesta['status'] ?? 502),
			'error' => (string) ($respuesta['error'] ?? 'No se pudo leer el espacio usado de Yandex Disk.'),
		];
	endif;

	$datos = is_array($respuesta['data'] ?? null) ? $respuesta['data'] : [];
	$total = yandexDiskEnteroDesdeCampos($datos, ['total_space', 'totalSpace', 'disk_size']);
	$usado = yandexDiskEnteroDesdeCampos($datos, ['used_space', 'usedSpace']);
	if ($total === null || $usado === null):
		return ['ok' => false, 'status' => 502, 'error' => 'Yandex Disk no devolvió totalSpace/usedSpace.'];
	endif;

	$porcentaje = $total > 0 ? min(100, max(0, ($usado / $total) * 100)) : 0;

	return [
		'ok' => true,
		'status' => 200,
		'error' => '',
		'total' => $total,
		'usado' => $usado,
		'porcentaje' => $porcentaje,
		'total_legible' => yandexDiskFormatoTamano($total),
		'usado_legible' => yandexDiskFormatoTamano($usado),
	];
}

function normalizarTamanoPreviewYandexDisk(mixed $valor): string
{
	$tamano = trim((string) $valor);
	$tamanoNombre = mb_strtoupper($tamano, 'UTF-8');
	if (in_array($tamanoNombre, ['S', 'M', 'L', 'XL', 'XXL', 'XXXL'], true)):
		return $tamanoNombre;
	endif;

	$tamanoPersonalizado = mb_strtolower(preg_replace('/\s+/', '', $tamano) ?? '', 'UTF-8');
	$dimension = '[1-9]\d{0,4}';
	if (preg_match('/^(?:' . $dimension . '|' . $dimension . 'x|' . $dimension . 'x' . $dimension . '|x' . $dimension . ')$/', $tamanoPersonalizado) === 1):
		return $tamanoPersonalizado;
	endif;

	return 'M';
}

function normalizarPhotoIdYandexDisk(mixed $valor): string
{
	$id = str_replace(["\r", "\n", "\0"], '', trim((string) $valor));
	return mb_substr($id, 0, 240, 'UTF-8');
}

function yandexDiskNormalizarUrlYandex(mixed $valor): string
{
	$url = str_replace(["\r", "\n"], '', trim((string) $valor));
	if (str_starts_with($url, '//')):
		$url = 'https:' . $url;
	endif;
	return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
}

function yandexDiskUrlHostPermitido(string $url): bool
{
	if (filter_var($url, FILTER_VALIDATE_URL) === false):
		return false;
	endif;

	$host = parse_url($url, PHP_URL_HOST);
	if (!is_string($host)):
		return false;
	endif;

	$host = mb_strtolower($host, 'UTF-8');
	foreach (['disk.yandex.ru', 'disk.yandex.net', 'disk.yandex.com', 'storage.yandex.net', 'mds.yandex.net'] as $dominio):
		if ($host === $dominio || str_ends_with($host, '.' . $dominio)):
			return true;
		endif;
	endforeach;

	return false;
}

function yandexDiskUrlHostAutorizable(string $url): bool
{
	$host = parse_url($url, PHP_URL_HOST);
	if (!is_string($host)):
		return false;
	endif;

	$host = mb_strtolower($host, 'UTF-8');
	foreach (['disk.yandex.ru', 'disk.yandex.net'] as $dominio):
		if ($host === $dominio || str_ends_with($host, '.' . $dominio)):
			return true;
		endif;
	endforeach;

	return false;
}

function yandexDiskPeticion(string $recurso, array $parametros, string $token, int $timeout = 15, string $metodo = 'GET'): array
{
	if (!function_exists('curl_init')):
		return ['ok' => false, 'status' => 0, 'error' => 'La extensión cURL de PHP no está disponible.', 'data' => null];
	endif;

	$url = YANDEX_DISK_API_BASE . '/' . ltrim($recurso, '/');
	if (!empty($parametros)):
		$url .= '?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
	endif;

	$curl = curl_init($url);
	if ($curl === false):
		return ['ok' => false, 'status' => 0, 'error' => 'No se pudo preparar la petición a Yandex Disk.', 'data' => null];
	endif;

	$opciones = [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => max(5, $timeout),
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_HTTPHEADER => [
			'Accept: application/json',
			'Content-Type: application/json',
			'Authorization: OAuth ' . $token,
			'User-Agent: DAM-local/1.0',
		],
	];
	$metodo = strtoupper(trim($metodo));
	if ($metodo !== '' && $metodo !== 'GET'):
		$opciones[CURLOPT_CUSTOMREQUEST] = $metodo;
	endif;
	if (defined('CURL_IPRESOLVE_V4')):
		$opciones[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
	endif;
	curl_setopt_array($curl, $opciones);

	$cuerpo = curl_exec($curl);
	$errorCurl = curl_error($curl);
	$errorCodigo = curl_errno($curl);
	$codigo = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

	if ($cuerpo === false):
		if (defined('CURLE_OPERATION_TIMEDOUT') && $errorCodigo === CURLE_OPERATION_TIMEDOUT):
			$errorCurl = 'Yandex Disk tardó demasiado en responder. Intenta recargar la pestaña en unos segundos.';
		endif;
		return ['ok' => false, 'status' => $codigo, 'error' => $errorCurl ?: 'No se pudo conectar con Yandex Disk.', 'data' => null];
	endif;

	if (trim((string) $cuerpo) === ''):
		if ($codigo >= 200 && $codigo < 300):
			return ['ok' => true, 'status' => $codigo, 'error' => '', 'data' => []];
		endif;
		return ['ok' => false, 'status' => $codigo, 'error' => 'Yandex Disk devolvió una respuesta vacía.', 'data' => null];
	endif;

	$datos = json_decode((string) $cuerpo, true);
	if (!is_array($datos)):
		return ['ok' => false, 'status' => $codigo, 'error' => 'Yandex Disk devolvió una respuesta no válida.', 'data' => null];
	endif;

	if ($codigo < 200 || $codigo >= 300):
		$mensaje = (string) ($datos['message'] ?? $datos['description'] ?? '');
		if ($mensaje === ''):
			$mensaje = 'Yandex Disk respondió con HTTP ' . $codigo . '.';
		endif;
		return ['ok' => false, 'status' => $codigo, 'error' => $mensaje, 'data' => $datos];
	endif;

	return ['ok' => true, 'status' => $codigo, 'error' => '', 'data' => $datos];
}

function yandexDiskDescargarPreview(string $url, string $token, int $timeout = 15): array
{
	if (!function_exists('curl_init')):
		return ['ok' => false, 'status' => 0, 'error' => 'La extensión cURL de PHP no está disponible.', 'mime' => '', 'body' => ''];
	endif;
	if (filter_var($url, FILTER_VALIDATE_URL) === false):
		return ['ok' => false, 'status' => 0, 'error' => 'URL de preview inválida.', 'mime' => '', 'body' => ''];
	endif;

	if (!yandexDiskUrlHostPermitido($url)):
		return ['ok' => false, 'status' => 0, 'error' => 'Host de preview no permitido.', 'mime' => '', 'body' => ''];
	endif;

	$curl = curl_init($url);
	if ($curl === false):
		return ['ok' => false, 'status' => 0, 'error' => 'No se pudo preparar la descarga de preview.', 'mime' => '', 'body' => ''];
	endif;

	$cabeceras = [
		'Accept: image/*,*/*',
		'User-Agent: DAM-local/1.0',
	];
	if (yandexDiskUrlHostAutorizable($url)):
		$cabeceras[] = 'Authorization: OAuth ' . $token;
	endif;

	$opciones = [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_TIMEOUT => max(5, $timeout),
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_HTTPHEADER => $cabeceras,
	];
	if (defined('CURL_IPRESOLVE_V4')):
		$opciones[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
	endif;
	curl_setopt_array($curl, $opciones);

	$cuerpo = curl_exec($curl);
	$errorCurl = curl_error($curl);
	$errorCodigo = curl_errno($curl);
	$codigo = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
	$mime = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

	if ($cuerpo === false):
		if (defined('CURLE_OPERATION_TIMEDOUT') && $errorCodigo === CURLE_OPERATION_TIMEDOUT):
			$errorCurl = 'Yandex Disk tardó demasiado en devolver la miniatura.';
		endif;
		return ['ok' => false, 'status' => $codigo, 'error' => $errorCurl ?: 'No se pudo descargar la miniatura.', 'mime' => '', 'body' => ''];
	endif;

	if ($codigo < 200 || $codigo >= 300):
		return ['ok' => false, 'status' => $codigo, 'error' => 'Yandex Disk respondió con HTTP ' . $codigo . ' al descargar la miniatura.', 'mime' => $mime, 'body' => ''];
	endif;

	if ($mime === '' || !str_starts_with(mb_strtolower($mime, 'UTF-8'), 'image/')):
		$mime = 'image/jpeg';
	endif;

	return ['ok' => true, 'status' => $codigo, 'error' => '', 'mime' => $mime, 'body' => (string) $cuerpo];
}

function obtenerPreviewDesdeUrlYandexDisk(array $configuracion, string $url): array
{
	$token = yandexDiskApiKeyConfiguracion($configuracion);
	if ($token === ''):
		return ['ok' => false, 'status' => 401, 'error' => 'No hay API Key de Yandex.Disk configurada.', 'mime' => '', 'body' => ''];
	endif;

	return yandexDiskDescargarPreview($url, $token, 15);
}

function obtenerPreviewYandexDisk(array $configuracion, string $ruta, string $tamano = 'M'): array
{
	$token = yandexDiskApiKeyConfiguracion($configuracion);
	if ($token === ''):
		return ['ok' => false, 'status' => 401, 'error' => 'No hay API Key de Yandex.Disk configurada.', 'mime' => '', 'body' => ''];
	endif;

	$ruta = normalizarRutaYandexDisk($ruta);
	if ($ruta === '/'):
		return ['ok' => false, 'status' => 400, 'error' => 'Ruta de preview inválida.', 'mime' => '', 'body' => ''];
	endif;

	$respuesta = yandexDiskPeticion('resources', [
		'path' => rutaApiYandexDisk($ruta),
		'fields' => 'name,path,type,preview,mime_type',
		'preview_size' => normalizarTamanoPreviewYandexDisk($tamano),
		'preview_crop' => 'false',
	], $token, 10);

	if (!$respuesta['ok']):
		$status = (int) ($respuesta['status'] ?? 502);
		$limpieza = $status === 404 ? yandexDiskMarcarRecursoAusente($ruta, 'preview_404') : null;
		return ['ok' => false, 'status' => $status, 'error' => $respuesta['error'], 'mime' => '', 'body' => '', 'limpieza' => $limpieza];
	endif;

	$datos = is_array($respuesta['data'] ?? null) ? $respuesta['data'] : [];
	$preview = (string) ($datos['preview'] ?? '');
	if ($preview === ''):
		return ['ok' => false, 'status' => 404, 'error' => 'Yandex Disk no devolvió preview para este archivo.', 'mime' => '', 'body' => ''];
	endif;

	return yandexDiskDescargarPreview($preview, $token, 15);
}

function obtenerUrlDescargaYandexDisk(array $configuracion, string $ruta): array
{
	$token = yandexDiskApiKeyConfiguracion($configuracion);
	if ($token === ''):
		return ['ok' => false, 'status' => 401, 'error' => 'No hay API Key de Yandex.Disk configurada.', 'href' => ''];
	endif;

	$ruta = normalizarRutaYandexDisk($ruta);
	if ($ruta === '/'):
		return ['ok' => false, 'status' => 400, 'error' => 'Ruta de descarga inválida.', 'href' => ''];
	endif;

	$respuesta = yandexDiskPeticion('resources/download', [
		'path' => rutaApiYandexDisk($ruta),
		'fields' => 'href,method,templated',
	], $token, 10);

	if (!$respuesta['ok']):
		$status = (int) ($respuesta['status'] ?? 502);
		$limpieza = $status === 404 ? yandexDiskMarcarRecursoAusente($ruta, 'download_404') : null;
		return ['ok' => false, 'status' => $status, 'error' => $respuesta['error'], 'href' => '', 'limpieza' => $limpieza];
	endif;

	$datos = is_array($respuesta['data'] ?? null) ? $respuesta['data'] : [];
	$href = (string) ($datos['href'] ?? '');
	if ($href === '' || filter_var($href, FILTER_VALIDATE_URL) === false):
		return ['ok' => false, 'status' => 502, 'error' => 'Yandex Disk no devolvió una URL de descarga válida.', 'href' => ''];
	endif;

	return ['ok' => true, 'status' => 200, 'error' => '', 'href' => $href];
}

function obtenerUrlDescargaYandexPhoto(array $configuracion, string $id): array
{
	$token = yandexDiskApiKeyConfiguracion($configuracion);
	if ($token === ''):
		return ['ok' => false, 'status' => 401, 'error' => 'No hay API Key de Yandex.Disk configurada.', 'href' => ''];
	endif;

	$id = normalizarPhotoIdYandexDisk($id);
	if ($id === ''):
		return ['ok' => false, 'status' => 400, 'error' => 'ID de Photos inválido.', 'href' => ''];
	endif;

	$respuesta = yandexDiskPeticion('photos/' . rawurlencode($id) . '/download', [
		'fields' => 'href,method,templated',
	], $token, 20);

	if (!$respuesta['ok']):
		$error = (string) ($respuesta['error'] ?? 'No se pudo preparar la descarga de Photos.');
		if ((int) ($respuesta['status'] ?? 0) === 404):
			$error = 'Yandex Photos no encontró el original para este ID o el endpoint /v1/disk/photos/{id}/download no está disponible para este token.';
		endif;
		return ['ok' => false, 'status' => (int) ($respuesta['status'] ?? 502), 'error' => $error, 'href' => ''];
	endif;

	$datos = is_array($respuesta['data'] ?? null) ? $respuesta['data'] : [];
	$href = (string) ($datos['href'] ?? '');
	if ($href === '' || filter_var($href, FILTER_VALIDATE_URL) === false):
		return ['ok' => false, 'status' => 502, 'error' => 'Yandex Photos no devolvió una URL de descarga válida.', 'href' => ''];
	endif;

	return ['ok' => true, 'status' => 200, 'error' => '', 'href' => $href];
}

function obtenerRecursoYandexDisk(array $configuracion, string $ruta): array
{
	$token = yandexDiskApiKeyConfiguracion($configuracion);
	if ($token === ''):
		return ['ok' => false, 'status' => 401, 'error' => 'No hay API Key de Yandex.Disk configurada.', 'recurso' => null];
	endif;

	$ruta = normalizarRutaYandexDisk($ruta);
	if ($ruta === '/'):
		return ['ok' => false, 'status' => 400, 'error' => 'Ruta de recurso inválida.', 'recurso' => null];
	endif;

	// Intentar obtener del catálogo local primero
	if (function_exists('catalogoObtenerYandexPorRuta')):
		$catalogado = catalogoObtenerYandexPorRuta($ruta);
		if ($catalogado !== null):
			$recurso = yandexDiskRecursoDesdeCatalogo($catalogado);
			if ($recurso !== null):
				return ['ok' => true, 'status' => 200, 'error' => '', 'recurso' => $recurso, 'desde_catalogo' => true];
			endif;
		endif;
	endif;

	$respuesta = yandexDiskPeticion('resources', [
		'path' => rutaApiYandexDisk($ruta),
		'fields' => 'name,path,type,mime_type,media_type,md5,sha256,size,modified,created,preview,public_url,resource_id,exif',
		'preview_size' => 'M',
		'preview_crop' => 'false',
	], $token, 10);

	if (!$respuesta['ok']):
		$status = (int) ($respuesta['status'] ?? 502);
		$limpieza = $status === 404 ? yandexDiskMarcarRecursoAusente($ruta, 'resource_404') : null;
		return ['ok' => false, 'status' => $status, 'error' => $respuesta['error'], 'recurso' => null, 'limpieza' => $limpieza];
	endif;

	$datos = is_array($respuesta['data'] ?? null) ? $respuesta['data'] : [];
	$recurso = yandexDiskNormalizarRecurso($datos);
	if ($recurso === null || !($recurso['es_multimedia'] ?? false)):
		return ['ok' => false, 'status' => 415, 'error' => 'El recurso de Yandex Disk no es multimedia compatible.', 'recurso' => null];
	endif;

	return ['ok' => true, 'status' => 200, 'error' => '', 'recurso' => $recurso];
}

function yandexDiskRecursoDesdeCatalogo(array $catalogado): ?array
{
	$rutaRemota = (string) ($catalogado['ruta_remota'] ?? '');
	if ($rutaRemota === ''):
		return null;
	endif;

	$mime = (string) ($catalogado['mime'] ?? '');
	$tipo = (string) ($catalogado['tipo'] ?? '');
	$esImagen = str_starts_with($mime, 'image/') || $tipo === 'img';
	$esVideo = str_starts_with($mime, 'video/') || $tipo === 'vid';
	if (!$esImagen && !$esVideo):
		return null;
	endif;

	$tamano = max(0, (int) ($catalogado['tamano'] ?? 0));
	$creado = max(0, (int) ($catalogado['creado'] ?? 0));
	$modificado = max(0, (int) ($catalogado['mtime'] ?? 0));

	return [
		'ruta' => $rutaRemota,
		'nombre' => (string) ($catalogado['nombre'] ?? basename($rutaRemota)),
		'tipo' => $esImagen ? 'image' : 'video',
		'mime' => $mime,
		'media_type' => $esImagen ? 'image' : 'video',
		'es_multimedia' => true,
		'es_directorio' => false,
		'md5' => (string) ($catalogado['md5'] ?? ''),
		'sha256' => (string) ($catalogado['sha256'] ?? ''),
		'resource_id' => (string) ($catalogado['resource_id'] ?? ''),
		'tamano' => $tamano,
		'tamano_legible' => $tamano > 0 ? yandexDiskFormatoTamano($tamano) : '',
		'ancho' => max(0, (int) ($catalogado['ancho'] ?? 0)),
		'alto' => max(0, (int) ($catalogado['alto'] ?? 0)),
		'duracion' => max(0, (int) ($catalogado['duracion'] ?? 0)),
		'exif' => [],
		'creado' => $creado > 0 ? gmdate('c', $creado) : '',
		'modificado' => $modificado > 0 ? gmdate('c', $modificado) : '',
		'preview' => (string) ($catalogado['preview'] ?? ''),
		'preview_lightbox' => '',
		'public_url' => '',
		'url' => (string) ($catalogado['url'] ?? ''),
		'namespace' => 'disk',
		'desde_unlimited' => false,
		'origen' => 'Yandex Disk',
		'photo_id' => '',
		'ruta_visible' => $rutaRemota,
	];
}

function enviarPapeleraYandexDisk(array $configuracion, string $ruta): array
{
	$token = yandexDiskApiKeyConfiguracion($configuracion);
	if ($token === ''):
		return ['ok' => false, 'status' => 401, 'error' => 'No hay API Key de Yandex.Disk configurada.'];
	endif;

	$ruta = normalizarRutaYandexDisk($ruta);
	if ($ruta === '/'):
		return ['ok' => false, 'status' => 400, 'error' => 'Ruta de papelera inválida.'];
	endif;

	$respuesta = yandexDiskPeticion('resources', [
		'path' => rutaApiYandexDisk($ruta),
		'permanently' => 'false',
	], $token, 20, 'DELETE');

	if (!$respuesta['ok']):
		if ((int) ($respuesta['status'] ?? 0) === 404):
			$cache = yandexDiskMarcarRecursoAusente($ruta, 'trash_404');
			return [
				'ok' => true,
				'status' => 404,
				'error' => '',
				'data' => [],
				'cache' => $cache,
			];
		endif;
		return ['ok' => false, 'status' => (int) ($respuesta['status'] ?? 502), 'error' => $respuesta['error'] ?? 'No se pudo enviar el archivo a la papelera.'];
	endif;

	$cache = yandexDiskMarcarRecursoAusente($ruta, 'trash');

	return [
		'ok' => true,
		'status' => (int) ($respuesta['status'] ?? 200),
		'error' => '',
		'data' => $respuesta['data'] ?? [],
		'cache' => $cache,
	];
}

function moverRecursoYandexDisk(array $configuracion, string $origen, string $directorioDestino): array
{
	$token = yandexDiskApiKeyConfiguracion($configuracion);
	if ($token === ''):
		return ['ok' => false, 'status' => 401, 'error' => 'No hay API Key de Yandex.Disk configurada.'];
	endif;

	$origen = normalizarRutaYandexDisk($origen);
	$directorioDestino = normalizarRutaYandexDisk($directorioDestino);
	if ($origen === '/'):
		return ['ok' => false, 'status' => 400, 'error' => 'Ruta de origen inválida.'];
	endif;
	if (yandexDiskRutaEsUnlimited($origen)):
		return ['ok' => false, 'status' => 400, 'error' => 'Los archivos de Photos/photounlim no se pueden mover desde la API de recursos.'];
	endif;

	$destino = rutaHijaYandexDisk($directorioDestino, basename($origen));
	if ($destino === '/' || $destino === $origen):
		return ['ok' => false, 'status' => 400, 'error' => 'El archivo ya está en esa carpeta.'];
	endif;

	$respuesta = yandexDiskPeticion('resources/move', [
		'from' => rutaApiYandexDisk($origen),
		'path' => rutaApiYandexDisk($destino),
		'overwrite' => 'false',
		'fields' => 'href,method,templated',
	], $token, 30, 'POST');

	if (!$respuesta['ok']):
		if ((int) ($respuesta['status'] ?? 0) === 404):
			$cache = yandexDiskMarcarRecursoAusente($origen, 'move_404');
			return [
				'ok' => false,
				'status' => 404,
				'error' => 'Yandex Disk no encontró el archivo de origen; se limpió el cache local.',
				'origen' => $origen,
				'destino' => $destino,
				'cache' => $cache,
			];
		endif;
		return [
			'ok' => false,
			'status' => (int) ($respuesta['status'] ?? 502),
			'error' => (string) ($respuesta['error'] ?? 'No se pudo mover el archivo en Yandex Disk.'),
			'origen' => $origen,
			'destino' => $destino,
		];
	endif;

	$cache = yandexDiskMarcarRecursoAusente($origen, 'move');
	$cache['directorios_invalidados'] = 0;
	$padreOrigen = rutaPadreYandexDisk($origen);
	if ($padreOrigen !== null):
		$cache['directorios_invalidados'] += invalidarCacheDirectorioYandexDisk($padreOrigen);
	endif;
	$cache['directorios_invalidados'] += invalidarCacheDirectorioYandexDisk($directorioDestino);
	$cache['ultimos_subidos_invalidados'] = invalidarCacheUltimosSubidosYandexDisk();

	return [
		'ok' => true,
		'status' => (int) ($respuesta['status'] ?? 200),
		'error' => '',
		'origen' => $origen,
		'destino' => $destino,
		'directorio_destino' => $directorioDestino,
		'data' => $respuesta['data'] ?? [],
		'cache' => $cache,
	];
}

function yandexDiskEsItemMultimedia(array $item): bool
{
	$meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
	$media = mb_strtolower((string) ($item['media_type'] ?? $item['tipo'] ?? $meta['mediatype'] ?? ''), 'UTF-8');
	$mime = mb_strtolower((string) ($item['mime_type'] ?? $item['mime'] ?? $meta['mimetype'] ?? ''), 'UTF-8');
	$nombre = mb_strtolower((string) ($item['name'] ?? $item['nombre'] ?? $item['path'] ?? $item['ruta'] ?? $item['id'] ?? ''), 'UTF-8');
	return in_array($media, ['image', 'video'], true)
		|| str_starts_with($mime, 'image/')
		|| str_starts_with($mime, 'video/')
		|| preg_match('/\.(jpe?g|png|gif|webp|heic|avif|cr2|cr3|nef|arw|dng|raf|orf|rw2|pef|srw|mp4|mov|m4v|webm)$/i', $nombre) === 1;
}

function yandexDiskFechaRecurso(mixed $valor): string
{
	if (is_numeric($valor)):
		$timestamp = (int) $valor;
		return $timestamp > 0 ? gmdate(DATE_ATOM, $timestamp) : '';
	endif;
	return (string) $valor;
}

function yandexDiskNormalizarRecurso(array $item): ?array
{
	$tipoRecurso = (string) ($item['type'] ?? 'file');
	$ruta = normalizarRutaYandexDisk((string) ($item['path'] ?? $item['id'] ?? ''));
	$nombre = (string) ($item['name'] ?? basename($ruta));
	if ($ruta === '/' || $nombre === ''):
		return null;
	endif;
	$metaInterna = is_array($item['meta'] ?? null) ? $item['meta'] : [];
	$creado = yandexDiskFechaRecurso($item['created'] ?? $item['ctime'] ?? '');
	$modificado = yandexDiskFechaRecurso($item['modified'] ?? $item['mtime'] ?? $item['utime'] ?? $item['created'] ?? $item['ctime'] ?? '');

	if ($tipoRecurso === 'dir'):
		return [
			'namespace' => 'disk',
			'nombre' => $nombre,
			'ruta' => $ruta,
			'tipo' => 'dir',
			'es_directorio' => true,
			'es_multimedia' => false,
			'mime' => '',
			'tamano' => null,
			'tamano_legible' => '',
			'modificado' => $modificado,
			'preview' => '',
			'url' => yandexDiskUrlCliente($ruta),
		];
	endif;

	if ($tipoRecurso !== 'file' || !yandexDiskEsItemMultimedia($item)):
		return null;
	endif;

	$mime = (string) ($item['mime_type'] ?? $metaInterna['mimetype'] ?? '');
	$media = mb_strtolower((string) ($item['media_type'] ?? $metaInterna['mediatype'] ?? ''), 'UTF-8');
	$tipo = $media === 'video' || str_starts_with(mb_strtolower($mime, 'UTF-8'), 'video/')
		? 'video'
		: 'image';
	$tamanoFuente = $item['size'] ?? $metaInterna['size'] ?? null;
	$tamano = is_numeric($tamanoFuente)
		? max(0, (int) $tamanoFuente)
		: null;
	$preview = yandexDiskNormalizarUrlYandex($item['preview'] ?? '');
	if ($preview === ''):
		$preview = yandexDiskPhotoSeleccionarSizeUrl($metaInterna['sizes'] ?? [], 'M');
	endif;
	$previewLightbox = yandexDiskPhotoSeleccionarSizeUrl($metaInterna['sizes'] ?? [], YANDEX_DISK_LIGHTBOX_PREVIEW_SIZE);
	if ($previewLightbox === ''):
		$previewLightbox = $preview;
	endif;
	$url = yandexDiskNormalizarUrlYandex($item['public_url'] ?? '');
	if ($url === ''):
		$url = yandexDiskUrlCliente($ruta);
	endif;
	$exif = is_array($item['exif'] ?? null) ? $item['exif'] : [];
	$desdeUnlimited = yandexDiskRutaEsPhotounlim($ruta) || yandexDiskRutaEsPhotounlim((string) ($item['id'] ?? ''));
	$origen = $desdeUnlimited ? 'From unlimited storage' : 'Yandex Disk';

	return [
		'namespace' => 'disk',
		'nombre' => $nombre,
		'ruta' => $ruta,
		'tipo' => $tipo,
		'es_directorio' => false,
		'es_multimedia' => true,
		'mime' => $mime,
		'media_type' => $media,
		'md5' => (string) ($item['md5'] ?? ''),
		'sha256' => (string) ($item['sha256'] ?? ''),
		'resource_id' => (string) ($item['resource_id'] ?? $metaInterna['resource_id'] ?? ''),
		'public_url' => (string) ($item['public_url'] ?? ''),
		'creado' => $creado,
		'tamano' => $tamano,
		'tamano_legible' => yandexDiskFormatoTamano($tamano),
		'modificado' => $modificado,
		'photoslice_time' => yandexDiskFechaRecurso($metaInterna['photoslice_time'] ?? ''),
		'desde_unlimited' => $desdeUnlimited,
		'origen' => $origen,
		'exif' => $exif,
		'preview' => $preview,
		'preview_lightbox' => $previewLightbox,
		'url' => $url,
	];
}

function yandexDiskPhotoSeleccionarSizeUrl(mixed $sizes, string $preferida = 'M'): string
{
	if (!is_array($sizes) || empty($sizes)):
		return '';
	endif;

	$candidatos = [];
	foreach ($sizes as $size):
		if (is_string($size)):
			$url = $size;
			$nombre = '';
			$area = 0;
		elseif (is_array($size)):
			$url = (string) ($size['url'] ?? $size['href'] ?? '');
			$nombre = mb_strtoupper((string) ($size['name'] ?? $size['type'] ?? $size['size'] ?? ''), 'UTF-8');
			$ancho = isset($size['width']) && is_numeric($size['width']) ? (int) $size['width'] : 0;
			$alto = isset($size['height']) && is_numeric($size['height']) ? (int) $size['height'] : 0;
			$area = max(0, $ancho) * max(0, $alto);
		else:
			continue;
		endif;
		$url = yandexDiskNormalizarUrlYandex($url);
		if ($url === ''):
			continue;
		endif;
		$candidatos[] = [
			'url' => $url,
			'nombre' => $nombre,
			'area' => $area,
		];
	endforeach;

	if (empty($candidatos)):
		return '';
	endif;

	$preferida = mb_strtoupper(trim($preferida), 'UTF-8');
	$ordenPreferencias = $preferida === 'XXXL'
		? ['XXXL', 'XXL', 'XL', 'L', 'M', 'S', 'ORIGINAL', 'DEFAULT']
		: array_filter([$preferida, 'M', 'L', 'XL', 'XXL', 'XXXL', 'DEFAULT', 'ORIGINAL']);
	foreach ($ordenPreferencias as $nombreBuscado):
		foreach ($candidatos as $candidato):
			if ($candidato['nombre'] === $nombreBuscado):
				return $candidato['url'];
			endif;
		endforeach;
	endforeach;

	usort($candidatos, fn($a, $b) => ($b['area'] <=> $a['area']));
	return (string) ($candidatos[0]['url'] ?? '');
}

function yandexDiskExtraerItemsPhotos(array $datos): array
{
	foreach ([
		$datos['items'] ?? null,
		$datos['photos'] ?? null,
		$datos['_embedded']['items'] ?? null,
		$datos['_embedded']['photos'] ?? null,
	] as $items):
		if (is_array($items)):
			return $items;
		endif;
	endforeach;

	return [];
}

function yandexDiskTotalPhotos(array $datos, int $cantidadItems, int $offset, int $limite): array
{
	foreach ([
		$datos['total'] ?? null,
		$datos['_embedded']['total'] ?? null,
		$datos['count'] ?? null,
	] as $total):
		if (is_numeric($total)):
			return [max(0, (int) $total), true];
		endif;
	endforeach;

	$total = $offset + $cantidadItems;
	if ($cantidadItems >= $limite):
		$total++;
	endif;

	return [$total, $cantidadItems < $limite];
}

function yandexDiskNormalizarPhoto(array $item): ?array
{
	$id = normalizarPhotoIdYandexDisk($item['id'] ?? $item['photo_id'] ?? $item['resource_id'] ?? '');
	if ($id === ''):
		return null;
	endif;

	$mime = (string) ($item['mime_type'] ?? $item['mime'] ?? '');
	$media = mb_strtolower((string) ($item['media_type'] ?? $item['type'] ?? ''), 'UTF-8');
	$tipo = $media === 'video' || str_starts_with(mb_strtolower($mime, 'UTF-8'), 'video/')
		? 'video'
		: 'image';
	$nombre = trim((string) ($item['name'] ?? $item['filename'] ?? $item['title'] ?? ''));
	if ($nombre === ''):
		$nombre = $tipo === 'video' ? 'video-' . $id : 'photo-' . $id;
	endif;
	$tamano = isset($item['size']) && is_numeric($item['size'])
		? max(0, (int) $item['size'])
		: null;
	$sizes = is_array($item['sizes'] ?? null) ? $item['sizes'] : [];
	$preview = yandexDiskPhotoSeleccionarSizeUrl($sizes, 'M');
	$previewLightbox = yandexDiskPhotoSeleccionarSizeUrl($sizes, YANDEX_DISK_LIGHTBOX_PREVIEW_SIZE);
	if ($previewLightbox === ''):
		$previewLightbox = $preview;
	endif;
	$fecha = (string) ($item['created'] ?? $item['date'] ?? $item['taken_at'] ?? $item['uploaded'] ?? $item['modified'] ?? '');
	$exif = is_array($item['exif'] ?? null) ? $item['exif'] : [];

	return [
		'namespace' => 'photos',
		'id' => $id,
		'photo_id' => $id,
		'nombre' => $nombre,
		'ruta' => 'photos:' . $id,
		'ruta_visible' => 'From unlimited storage',
		'tipo' => $tipo,
		'es_directorio' => false,
		'es_multimedia' => true,
		'mime' => $mime,
		'media_type' => $media !== '' ? $media : $tipo,
		'md5' => (string) ($item['md5'] ?? ''),
		'sha256' => (string) ($item['sha256'] ?? ''),
		'resource_id' => '',
		'public_url' => '',
		'creado' => $fecha,
		'tamano' => $tamano,
		'tamano_legible' => yandexDiskFormatoTamano($tamano),
		'modificado' => $fecha,
		'desde_unlimited' => true,
		'origen' => 'From unlimited storage',
		'exif' => $exif,
		'sizes' => $sizes,
		'preview' => $preview,
		'preview_lightbox' => $previewLightbox,
		'url' => '',
		'raw' => $item,
	];
}

function yandexDiskRutaEsUnlimited(string $ruta): bool
{
	return yandexDiskRutaEsPhotounlim($ruta);
}

function yandexDiskRutaEsPhotounlim(string $ruta): bool
{
	foreach (explode('/', trim(yandexDiskRutaVisible($ruta), '/')) as $segmento):
		if (mb_strtolower($segmento, 'UTF-8') === 'photounlim'):
			return true;
		endif;
		break;
	endforeach;
	return false;
}

function detectarUnlimitedStorageYandexDisk(array $directorios, string $rutaActual = '/', int $totalActual = 0): ?array
{
	$totalDetectado = 0;
	foreach ($directorios as $item):
		if (yandexDiskRutaEsUnlimited((string) ($item['ruta'] ?? ''))):
			$totalDetectado++;
		endif;
	endforeach;

	if ($totalDetectado > 0):
		return [
			'ruta' => YANDEX_DISK_UNLIMITED_PATH,
			'url' => yandexDiskUrlCliente(YANDEX_DISK_UNLIMITED_PATH),
			'total' => $totalDetectado,
		];
	endif;

	if (yandexDiskRutaEsUnlimited($rutaActual) && $totalActual > 0):
		return [
			'ruta' => YANDEX_DISK_UNLIMITED_PATH,
			'url' => yandexDiskUrlCliente(YANDEX_DISK_UNLIMITED_PATH),
			'total' => $totalActual,
		];
	endif;

	return null;
}

function obtenerDirectorioYandexDisk(array $configuracion, string $ruta = '/', int $limite = 200, int $offset = 0, string $orden = 'name'): array
{
	$token = yandexDiskApiKeyConfiguracion($configuracion);
	$estado = estadoYandexDiskVacio($token !== '');
	$ruta = normalizarRutaYandexDisk($ruta);
	$orden = normalizarOrdenYandexDisk($orden);
	$ordenRecursos = ordenYandexDiskEsUltimosSubidos($orden) ? 'name' : $orden;
	$estado['ruta'] = $ruta;
	$estado['orden'] = $ordenRecursos;
	if ($token === ''):
		return $estado;
	endif;

	$limite = max(1, min(200, $limite));
	$offset = max(0, $offset);
	$estado['limit'] = $limite;
	$estado['offset'] = $offset;
	$directorios = [];
	$multimedia = [];
	$total = 0;
	$parametros = [
		'path' => rutaApiYandexDisk($ruta),
		'limit' => $limite,
		'offset' => $offset,
		'sort' => $ordenRecursos,
		'fields' => 'name,path,type,_embedded.total,_embedded.limit,_embedded.offset,_embedded.items.name,_embedded.items.path,_embedded.items.type,_embedded.items.mime_type,_embedded.items.media_type,_embedded.items.md5,_embedded.items.sha256,_embedded.items.size,_embedded.items.modified,_embedded.items.created,_embedded.items.preview,_embedded.items.public_url,_embedded.items.resource_id,_embedded.items.exif',
		'preview_size' => 'M',
		'preview_crop' => 'false',
	];
	$parametrosCache = $parametros;
	$parametrosCache['_token_hash'] = hash('sha256', $token);
	$claveCache = claveCacheYandexDisk('resources', $parametrosCache);
	$cache = leerCacheYandexDisk($claveCache);
	$cacheVencido = null;
	if ($cache !== null):
		$respuesta = [
			'ok' => true,
			'status' => 200,
			'error' => '',
			'data' => $cache['data'],
		];
		$estado['cache'] = [
			'hit' => true,
			'stale' => false,
			'key' => $claveCache,
			'created_at' => (int) ($cache['created_at'] ?? 0),
		];
	else:
		$respuesta = yandexDiskPeticion('resources', $parametros, $token);
		if ($respuesta['ok'] && is_array($respuesta['data'] ?? null)):
			guardarCacheYandexDisk($claveCache, $respuesta['data']);
			$estado['cache'] = [
				'hit' => false,
				'stale' => false,
				'key' => $claveCache,
				'created_at' => time(),
			];
		else:
			$cacheVencido = leerCacheVencidoYandexDisk($claveCache);
			if ($cacheVencido !== null):
				$respuesta = [
					'ok' => true,
					'status' => 200,
					'error' => '',
					'data' => $cacheVencido['data'],
				];
				$estado['cache'] = [
					'hit' => true,
					'stale' => true,
					'key' => $claveCache,
					'created_at' => (int) ($cacheVencido['created_at'] ?? 0),
				];
			else:
				$estado['cache']['key'] = $claveCache;
			endif;
		endif;
	endif;

	if (!$respuesta['ok']):
		$estado['error'] = $respuesta['error'];
		return $estado;
	endif;

	$datos = is_array($respuesta['data'] ?? null) ? $respuesta['data'] : [];
	$recursos = (array) ($datos['_embedded']['items'] ?? []);
	if (($datos['type'] ?? '') === 'file'):
		$recursos = [$datos];
	endif;
	actualizarIndiceRecursosYandexDisk($recursos);

	$total = (int) ($datos['_embedded']['total'] ?? count($recursos));
	$estado['limit'] = (int) ($datos['_embedded']['limit'] ?? $limite);
	$estado['offset'] = (int) ($datos['_embedded']['offset'] ?? $offset);

	foreach ($recursos as $item):
		if (!is_array($item)):
			continue;
		endif;
		$normalizado = yandexDiskNormalizarRecurso($item);
		if ($normalizado === null):
			continue;
		endif;
		if ($normalizado['es_directorio'] ?? false):
			$directorios[] = $normalizado;
		elseif ($normalizado['es_multimedia'] ?? false):
			$multimedia[] = $normalizado;
		endif;
	endforeach;

	$totalConsultado = count($recursos);

	$estado['ok'] = true;
	$estado['directorios'] = $directorios;
	$estado['multimedia'] = $multimedia;
	$estado['items'] = $directorios;
	$estado['total'] = $total ?: $totalConsultado;
	$estado['total_consultado'] = $totalConsultado;
	$estado['total_directorios'] = count($directorios);
	$estado['total_multimedia'] = count($multimedia);
	$estado['truncado'] = ($estado['total'] > ($estado['offset'] + $totalConsultado));
	$estado['unlimited'] = detectarUnlimitedStorageYandexDisk($directorios, $ruta, $estado['total']);

	return $estado;
}

function obtenerDirectorioYandexDiskRemotoCatalogo(array $configuracion, string $ruta = '/', int $limite = 100, int $offset = 0): array
{
	$token = yandexDiskApiKeyConfiguracion($configuracion);
	if ($token === ''):
		return ['ok' => false, 'status' => 401, 'error' => 'No hay API Key de Yandex.Disk configurada.', 'recursos' => [], 'total' => 0, 'limit' => 0, 'offset' => 0];
	endif;

	$ruta = normalizarRutaYandexDisk($ruta);
	$limite = max(1, min(200, $limite));
	$offset = max(0, $offset);
	$parametros = [
		'path' => rutaApiYandexDisk($ruta),
		'limit' => $limite,
		'offset' => $offset,
		'sort' => 'name',
		'fields' => 'name,path,type,_embedded.total,_embedded.limit,_embedded.offset,_embedded.items.name,_embedded.items.path,_embedded.items.type,_embedded.items.mime_type,_embedded.items.media_type,_embedded.items.md5,_embedded.items.sha256,_embedded.items.size,_embedded.items.modified,_embedded.items.created,_embedded.items.preview,_embedded.items.public_url,_embedded.items.resource_id,_embedded.items.exif',
		'preview_size' => 'M',
		'preview_crop' => 'false',
	];

	$respuesta = yandexDiskPeticion('resources', $parametros, $token, 20);
	if (!$respuesta['ok']):
		return [
			'ok' => false,
			'status' => (int) ($respuesta['status'] ?? 502),
			'error' => (string) ($respuesta['error'] ?? 'No se pudo leer Yandex Disk.'),
			'recursos' => [],
			'total' => 0,
			'limit' => $limite,
			'offset' => $offset,
		];
	endif;

	$datos = is_array($respuesta['data'] ?? null) ? $respuesta['data'] : [];
	$recursos = [];
	foreach ((array) ($datos['_embedded']['items'] ?? []) as $item):
		if (is_array($item)):
			$recursos[] = $item;
		endif;
	endforeach;
	if (($datos['type'] ?? '') === 'file'):
		$recursos = [$datos];
	endif;

	return [
		'ok' => true,
		'status' => (int) ($respuesta['status'] ?? 200),
		'error' => '',
		'recursos' => $recursos,
		'total' => (int) ($datos['_embedded']['total'] ?? count($recursos)),
		'limit' => (int) ($datos['_embedded']['limit'] ?? $limite),
		'offset' => (int) ($datos['_embedded']['offset'] ?? $offset),
		'ruta' => $ruta,
	];
}

function obtenerPaginaPhotosYandexDisk(array $configuracion, int $pagina = 1, int $limite = 21): array
{
	$token = yandexDiskApiKeyConfiguracion($configuracion);
	$estado = estadoYandexDiskVacio($token !== '');
	$estado['vista'] = 'photos';
	$estado['ruta'] = YANDEX_DISK_PHOTOS_LABEL;
	if ($token === ''):
		return $estado;
	endif;

	$pagina = max(1, $pagina);
	$limite = max(1, min(200, $limite));
	$offset = ($pagina - 1) * $limite;
	$estado['limit'] = $limite;
	$estado['offset'] = $offset;

	$respuesta = yandexDiskPeticion('photos', [
		'limit' => $limite,
		'offset' => $offset,
	], $token, 30);

	if (!$respuesta['ok']):
		$error = (string) ($respuesta['error'] ?? 'No se pudo leer Yandex Photos.');
		if ((int) ($respuesta['status'] ?? 0) === 404):
			$error = 'El endpoint de Yandex Photos (/v1/disk/photos) respondió 404. Los archivos "From unlimited storage" no se listan por ruta; esta vista necesita que el token tenga acceso al namespace Photos.';
		endif;
		$estado['error'] = $error;
		$estado['total_multimedia_conocido'] = false;
		return $estado;
	endif;

	$datos = is_array($respuesta['data'] ?? null) ? $respuesta['data'] : [];
	$items = yandexDiskExtraerItemsPhotos($datos);
	$multimedia = [];
	foreach ($items as $item):
		if (!is_array($item)):
			continue;
		endif;
		$normalizado = yandexDiskNormalizarPhoto($item);
		if ($normalizado !== null):
			$multimedia[] = $normalizado;
		endif;
	endforeach;

	[$total, $totalConocido] = yandexDiskTotalPhotos($datos, count($items), $offset, $limite);

	$estado['ok'] = true;
	$estado['items'] = $multimedia;
	$estado['multimedia'] = $multimedia;
	$estado['total'] = $total;
	$estado['total_consultado'] = count($items);
	$estado['total_multimedia'] = $totalConocido ? $total : max($total, $offset + count($multimedia));
	$estado['total_multimedia_conocido'] = $totalConocido;
	$estado['truncado'] = !$totalConocido;

	return $estado;
}

function obtenerPaginaUltimosSubidosYandexDisk(array $configuracion, int $pagina = 1, int $limite = 21, bool $forzarRemoto = false): array
{
	$token = yandexDiskApiKeyConfiguracion($configuracion);
	$estado = estadoYandexDiskVacio($token !== '');
	$estado['ruta'] = 'Últimos subidos';
	$estado['orden'] = 'last-uploaded';
	if ($token === ''):
		return $estado;
	endif;

	$pagina = max(1, $pagina);
	$limite = max(1, min(200, $limite));
	$offsetMultimedia = ($pagina - 1) * $limite;
	$objetivo = $offsetMultimedia + $limite + 1;
	$limiteRemoto = min(160, max($objetivo, $limite * 2));
	$estado['limit'] = $limite;
	$estado['offset'] = $offsetMultimedia;

	$parametros = [
		'limit' => $limiteRemoto,
		'fields' => 'limit,items.name,items.path,items.type,items.mime_type,items.media_type,items.md5,items.sha256,items.size,items.modified,items.created,items.preview,items.public_url,items.resource_id,items.exif',
		'preview_size' => 'M',
		'preview_crop' => 'false',
	];
	$parametrosCache = $parametros;
	$parametrosCache['_token_hash'] = hash('sha256', $token);
	$claveCache = claveCacheYandexDisk('resources-last-uploaded', $parametrosCache);
	$parametrosCacheLegado = $parametrosCache;
	$parametrosCacheLegado['limit'] = min(200, max($objetivo, $limite * 4));
	$parametrosCacheLegado['fields'] = 'limit,items.name,items.path,items.type,items.mime_type,items.media_type,items.md5,items.sha256,items.size,items.modified,items.created,items.preview,items.public_url,items.resource_id,items.exif,items.sizes';
	$claveCacheLegado = claveCacheYandexDisk('resources-last-uploaded', $parametrosCacheLegado);
	$cache = leerCacheYandexDisk($claveCache);
	if ($cache === null && $claveCacheLegado !== $claveCache):
		$cache = leerCacheYandexDisk($claveCacheLegado);
		if ($cache !== null):
			$claveCache = $claveCacheLegado;
		endif;
	endif;
	$cacheVencido = null;
	if ($cache !== null):
		$respuesta = [
			'ok' => true,
			'status' => 200,
			'error' => '',
			'data' => $cache['data'],
		];
		$estado['cache'] = [
			'hit' => true,
			'stale' => false,
			'key' => $claveCache,
			'created_at' => (int) ($cache['created_at'] ?? 0),
		];
	else:
		if (!$forzarRemoto):
			$cacheVencido = leerCacheVencidoYandexDisk($claveCache);
			if ($cacheVencido === null && $claveCacheLegado !== $claveCache):
				$cacheVencido = leerCacheVencidoYandexDisk($claveCacheLegado);
				if ($cacheVencido !== null):
					$claveCache = $claveCacheLegado;
				endif;
			endif;
		endif;
		if ($cacheVencido !== null):
			$respuesta = [
				'ok' => true,
				'status' => 200,
				'error' => '',
				'data' => $cacheVencido['data'],
			];
			$estado['cache'] = [
				'hit' => true,
				'stale' => true,
				'key' => $claveCache,
				'created_at' => (int) ($cacheVencido['created_at'] ?? 0),
			];
		else:
			$respuesta = yandexDiskPeticion('resources/last-uploaded', $parametros, $token, $forzarRemoto ? 12 : 5);
		endif;
		if ($respuesta['ok'] && is_array($respuesta['data'] ?? null)):
			if ($cacheVencido === null):
				guardarCacheYandexDisk($claveCache, $respuesta['data']);
				$estado['cache'] = [
					'hit' => false,
					'stale' => false,
					'key' => $claveCache,
					'created_at' => time(),
				];
			endif;
		elseif ($cacheVencido === null):
			$cacheVencido = leerCacheVencidoYandexDisk($claveCache);
			if ($cacheVencido === null && $claveCacheLegado !== $claveCache):
				$cacheVencido = leerCacheVencidoYandexDisk($claveCacheLegado);
				if ($cacheVencido !== null):
					$claveCache = $claveCacheLegado;
				endif;
			endif;
			if ($cacheVencido !== null):
				$respuesta = [
					'ok' => true,
					'status' => 200,
					'error' => '',
					'data' => $cacheVencido['data'],
				];
				$estado['cache'] = [
					'hit' => true,
					'stale' => true,
					'key' => $claveCache,
					'created_at' => (int) ($cacheVencido['created_at'] ?? 0),
				];
			else:
				$estado['cache']['key'] = $claveCache;
			endif;
		endif;
	endif;

	if (!$respuesta['ok']):
		$estado['error'] = (string) ($respuesta['error'] ?? 'No se pudo leer los últimos archivos subidos de Yandex Disk.');
		$estado['total_multimedia_conocido'] = false;
		return $estado;
	endif;

	$datos = is_array($respuesta['data'] ?? null) ? $respuesta['data'] : [];
	$recursos = [];
	foreach ((array) ($datos['items'] ?? []) as $item):
		if (is_array($item)):
			$recursos[] = $item;
		endif;
	endforeach;
	actualizarIndiceRecursosYandexDisk($recursos);

	$multimedia = [];
	foreach ($recursos as $item):
		$normalizado = yandexDiskNormalizarRecurso($item);
		if ($normalizado !== null && ($normalizado['es_multimedia'] ?? false)):
			$multimedia[] = $normalizado;
		endif;
	endforeach;

	$hayMas = count($recursos) >= $limiteRemoto && $limiteRemoto < 200;
	$totalMultimediaConocido = !$hayMas;
	$totalMultimedia = $totalMultimediaConocido
		? count($multimedia)
		: max($objetivo, count($multimedia));
	$multimediaPagina = array_slice($multimedia, $offsetMultimedia, $limite);

	$estado['ok'] = true;
	$estado['items'] = $multimediaPagina;
	$estado['multimedia'] = $multimediaPagina;
	$estado['total'] = count($recursos);
	$estado['total_consultado'] = count($recursos);
	$estado['total_multimedia'] = $totalMultimedia;
	$estado['total_multimedia_conocido'] = $totalMultimediaConocido;
	$estado['truncado'] = !$totalMultimediaConocido;

	return $estado;
}

function obtenerPaginaMultimediaYandexDisk(array $configuracion, string $ruta = '/', int $pagina = 1, int $limite = 21, ?array $primerLote = null, string $orden = 'name', bool $forzarRemoto = false): array
{
	$token = yandexDiskApiKeyConfiguracion($configuracion);
	$estado = estadoYandexDiskVacio($token !== '');
	$ruta = normalizarRutaYandexDisk($ruta);
	$orden = normalizarOrdenYandexDisk($orden);
	$estado['ruta'] = $ruta;
	$estado['orden'] = $orden;
	if ($token === ''):
		return $estado;
	endif;

	$pagina = max(1, $pagina);
	$limite = max(1, min(200, $limite));
	if (ordenYandexDiskEsUltimosSubidos($orden)):
		return obtenerPaginaUltimosSubidosYandexDisk($configuracion, $pagina, $limite, $forzarRemoto);
	endif;

	$lote = 200;
	$offsetMultimedia = ($pagina - 1) * $limite;
	$objetivo = $offsetMultimedia + $limite + 1;
	$offsetRemoto = 0;
	$totalRemoto = 0;
	$totalConsultado = 0;
	$multimedia = [];
	$agotado = false;
	$primerLoteDisponible = is_array($primerLote)
		&& normalizarRutaYandexDisk($primerLote['ruta'] ?? '/') === $ruta
		&& (int) ($primerLote['offset'] ?? 0) === 0
		&& normalizarOrdenYandexDisk($primerLote['orden'] ?? 'name') === $orden;

	while (count($multimedia) < $objetivo && !$agotado):
		if ($primerLoteDisponible):
			$loteEstado = $primerLote;
			$primerLoteDisponible = false;
		else:
			$loteEstado = obtenerDirectorioYandexDisk($configuracion, $ruta, $lote, $offsetRemoto, $orden);
		endif;
		if (!($loteEstado['ok'] ?? false)):
			$estado['error'] = (string) ($loteEstado['error'] ?? 'No se pudo leer Yandex Disk.');
			return $estado;
		endif;

		$consultados = (int) ($loteEstado['total_consultado'] ?? 0);
		$totalRemoto = max($totalRemoto, (int) ($loteEstado['total'] ?? 0));
		$totalConsultado += $consultados;

		foreach (($loteEstado['multimedia'] ?? []) as $item):
			if (!is_array($item)):
				continue;
			endif;
			$multimedia[] = $item;
		endforeach;

		$offsetRemoto += $consultados;
		$agotado = $consultados <= 0 || ($totalRemoto > 0 && $offsetRemoto >= $totalRemoto);
	endwhile;

	$totalMultimediaConocido = $agotado;
	$totalMultimedia = $totalMultimediaConocido
		? count($multimedia)
		: max($objetivo, count($multimedia));
	$multimediaPagina = array_slice($multimedia, $offsetMultimedia, $limite);

	$estado['ok'] = true;
	$estado['limit'] = $limite;
	$estado['offset'] = $offsetMultimedia;
	$estado['total'] = $totalRemoto;
	$estado['total_consultado'] = $totalConsultado;
	$estado['total_multimedia'] = $totalMultimedia;
	$estado['total_multimedia_conocido'] = $totalMultimediaConocido;
	$estado['multimedia'] = $multimediaPagina;
	$estado['truncado'] = !$agotado;

	return $estado;
}

function obtenerMultimediaYandexDisk(array $configuracion, int $limite = 80): array
{
	return obtenerDirectorioYandexDisk($configuracion, '/', $limite);
}

?>
