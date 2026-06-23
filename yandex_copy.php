<?php

ini_set("pcre.jit", "0");
set_time_limit(600);

require_once __DIR__ . '/src/funciones.php';
require_once __DIR__ . '/src/yandexDisk.php';

function responderJsonYandexCopia(int $codigo, array $datos): void
{
	http_response_code($codigo);
	header('Content-Type: application/json; charset=UTF-8');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function normalizarSubcarpetaYandexCopia(mixed $valor): ?string
{
	$nombre = trim(str_replace('\\', '/', (string) $valor));
	$nombre = trim($nombre, "/ \t\n\r\0\x0B");
	if ($nombre === ''):
		return '';
	endif;
	if (
		str_contains($nombre, '/')
		|| $nombre === '.'
		|| $nombre === '..'
		|| $nombre[0] === '.'
		|| preg_match('/[\x00-\x1F]/u', $nombre)
	):
		return null;
	endif;
	return $nombre;
}

function resolverDestinoYandexCopia(mixed $destino, mixed $subcarpeta): array
{
	$base = resolverRutaProyecto((string) ($destino ?? ''), 'dir', true);
	if ($base === null):
		return ['ok' => false, 'ruta' => '', 'error' => 'La carpeta destino no es válida.'];
	endif;

	$subcarpeta = normalizarSubcarpetaYandexCopia($subcarpeta);
	if ($subcarpeta === null):
		return ['ok' => false, 'ruta' => '', 'error' => 'El nombre de subcarpeta no es válido.'];
	endif;

	$ruta = $subcarpeta !== '' ? $base . DIRECTORY_SEPARATOR . $subcarpeta : $base;
	if (!is_dir($ruta) && !mkdir($ruta, 0755, true)):
		return ['ok' => false, 'ruta' => '', 'error' => 'No se pudo crear la subcarpeta destino.'];
	endif;

	$real = realpath($ruta);
	if (!$real || !rutaDentroDeDirectorio($real, proyectoRaiz())):
		return ['ok' => false, 'ruta' => '', 'error' => 'La carpeta destino queda fuera del proyecto.'];
	endif;
	if (!is_writable($real)):
		return ['ok' => false, 'ruta' => '', 'error' => 'La carpeta destino no tiene permisos de escritura.'];
	endif;

	return ['ok' => true, 'ruta' => $real, 'error' => ''];
}

function nombreArchivoYandexCopia(mixed $nombre, string $ruta, string $photoId): string
{
	$nombre = str_replace(["\r", "\n", "\0"], '', trim((string) $nombre));
	$nombre = basename(str_replace('\\', '/', $nombre));
	if ($nombre === '' && $ruta !== '/'):
		$nombre = basename($ruta);
	endif;
	if ($nombre === '' && $photoId !== ''):
		$nombre = 'yandex-photo-' . $photoId;
	endif;
	$nombre = preg_replace('/[\x00-\x1F]/u', '_', $nombre) ?? '';
	if ($nombre === '' || $nombre === '.' || $nombre === '..'):
		$nombre = 'yandex-media';
	endif;
	return $nombre;
}

function rutaDestinoDisponibleYandexCopia(string $directorio, string $nombreArchivo): string
{
	$destino = rtrim($directorio, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($nombreArchivo);
	if (!file_exists($destino)):
		return $destino;
	endif;

	$info = pathinfo($nombreArchivo);
	$nombre = $info['filename'] ?? basename($nombreArchivo);
	$extension = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';
	$i = 2;
	do {
		$destino = rtrim($directorio, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $nombre . ' ' . $i . $extension;
		$i++;
	} while (file_exists($destino));

	return $destino;
}

function cabecerasYandexCopia(string $cabeceras): array
{
	$bloques = array_values(array_filter(preg_split("/\r\n\r\n|\n\n|\r\r/", trim($cabeceras)) ?: []));
	$bloque = end($bloques);
	if (!is_string($bloque)):
		return [];
	endif;

	$resultado = [];
	foreach (preg_split("/\r\n|\n|\r/", $bloque) ?: [] as $linea):
		$posicion = strpos($linea, ':');
		if ($posicion === false):
			continue;
		endif;
		$nombre = mb_strtolower(trim(substr($linea, 0, $posicion)), 'UTF-8');
		$valor = trim(substr($linea, $posicion + 1));
		if ($nombre !== '' && $valor !== ''):
			$resultado[$nombre] = $valor;
		endif;
	endforeach;

	return $resultado;
}

function resolverRedireccionYandexCopia(string $actual, string $location): string
{
	$location = str_replace(["\r", "\n"], '', trim($location));
	if ($location === ''):
		return '';
	endif;
	if (filter_var($location, FILTER_VALIDATE_URL) !== false):
		return $location;
	endif;

	$partes = parse_url($actual);
	$esquema = is_string($partes['scheme'] ?? null) ? $partes['scheme'] : 'https';
	$host = is_string($partes['host'] ?? null) ? $partes['host'] : '';
	if ($host === ''):
		return '';
	endif;
	if (str_starts_with($location, '//')):
		return $esquema . ':' . $location;
	endif;
	if (str_starts_with($location, '/')):
		return $esquema . '://' . $host . $location;
	endif;

	$ruta = is_string($partes['path'] ?? null) ? $partes['path'] : '/';
	$base = rtrim(substr($ruta, 0, (int) strrpos($ruta, '/') + 1), '/');
	return $esquema . '://' . $host . $base . '/' . $location;
}

function descargarYandexCopiaAArchivo(string $url, string $token, string $archivoTemporal, bool $autorizar = true): array
{
	if (!function_exists('curl_init')):
		return ['ok' => false, 'status' => 0, 'error' => 'La extensión cURL de PHP no está disponible.', 'bytes' => 0];
	endif;
	if (!yandexDiskUrlHostPermitido($url)):
		return ['ok' => false, 'status' => 0, 'error' => 'Yandex Disk devolvió un destino de descarga no permitido.', 'bytes' => 0];
	endif;

	$actual = $url;
	for ($intento = 0; $intento < 4; $intento++):
		$archivo = @fopen($archivoTemporal, 'wb');
		if ($archivo === false):
			return ['ok' => false, 'status' => 0, 'error' => 'No se pudo crear el archivo temporal local.', 'bytes' => 0];
		endif;

		$cabecerasRespuesta = '';
		$curl = curl_init($actual);
		if ($curl === false):
			fclose($archivo);
			return ['ok' => false, 'status' => 0, 'error' => 'No se pudo preparar la descarga de Yandex Disk.', 'bytes' => 0];
		endif;

		$cabecerasPeticion = [
			'Accept: */*',
			'Accept-Encoding: identity',
			'User-Agent: DAM-local/1.0',
		];
		if ($autorizar && yandexDiskUrlHostAutorizable($actual)):
			$cabecerasPeticion[] = 'Authorization: OAuth ' . $token;
		endif;

		$opciones = [
			CURLOPT_FILE => $archivo,
			CURLOPT_HEADER => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT => 600,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_HTTPHEADER => $cabecerasPeticion,
			CURLOPT_HEADERFUNCTION => static function ($curl, string $cabecera) use (&$cabecerasRespuesta): int {
				$cabecerasRespuesta .= $cabecera;
				return strlen($cabecera);
			},
		];
		if (defined('CURL_IPRESOLVE_V4')):
			$opciones[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
		endif;
		if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')):
			$opciones[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
		endif;
		if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')):
			$opciones[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
		endif;
		curl_setopt_array($curl, $opciones);

		$okCurl = curl_exec($curl);
		$errorCurl = curl_error($curl);
		$errorCodigo = curl_errno($curl);
		$codigo = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		fclose($archivo);

		if ($okCurl === false):
			@unlink($archivoTemporal);
			if (defined('CURLE_OPERATION_TIMEDOUT') && $errorCodigo === CURLE_OPERATION_TIMEDOUT):
				$errorCurl = 'Yandex Disk tardó demasiado en descargar el archivo.';
			endif;
			return ['ok' => false, 'status' => $codigo, 'error' => $errorCurl ?: 'No se pudo descargar el archivo de Yandex Disk.', 'bytes' => 0];
		endif;

		$cabeceras = cabecerasYandexCopia($cabecerasRespuesta);
		if (in_array($codigo, [301, 302, 303, 307, 308], true)):
			$siguiente = resolverRedireccionYandexCopia($actual, (string) ($cabeceras['location'] ?? ''));
			@unlink($archivoTemporal);
			if ($siguiente !== '' && yandexDiskUrlHostPermitido($siguiente)):
				$actual = $siguiente;
				continue;
			endif;
			return ['ok' => false, 'status' => 502, 'error' => 'Yandex Disk devolvió una redirección no permitida.', 'bytes' => 0];
		endif;

		if ($codigo >= 200 && $codigo < 300):
			$bytes = is_file($archivoTemporal) ? (int) filesize($archivoTemporal) : 0;
			return ['ok' => true, 'status' => $codigo, 'error' => '', 'bytes' => $bytes];
		endif;

		@unlink($archivoTemporal);
		return ['ok' => false, 'status' => $codigo, 'error' => 'Yandex Disk respondió con HTTP ' . $codigo . ' al descargar el archivo.', 'bytes' => 0];
	endfor;

	@unlink($archivoTemporal);
	return ['ok' => false, 'status' => 502, 'error' => 'Yandex Disk devolvió demasiadas redirecciones.', 'bytes' => 0];
}

function yandexCopiaDebeGuardarPreviewTemporal(string $rutaDestino, string $tipoRemoto): bool
{
	$extension = strtolower(pathinfo($rutaDestino, PATHINFO_EXTENSION));
	$tipoRemoto = strtolower($tipoRemoto);
	return imagenRequiereTemporalNavegador($extension)
		|| $tipoRemoto === 'video'
		|| in_array($extension, ['mp4', 'mov', 'mkv', 'webm', 'avi', 'm4v'], true);
}

function guardarPreviewTemporalYandexCopia(
	array $configuracion,
	string $rutaYandex,
	string $photoId,
	string $previewUrl,
	string $rutaDestino
): string {
	$previewUrl = str_replace(["\r", "\n"], '', trim($previewUrl));
	$preview = ['ok' => false, 'status' => 0, 'error' => '', 'mime' => '', 'body' => ''];

	if ($photoId === '' && $rutaYandex !== '/'):
		$preview = obtenerPreviewYandexDisk($configuracion, $rutaYandex, YANDEX_DISK_LIGHTBOX_PREVIEW_SIZE);
	endif;
	if (!($preview['ok'] ?? false) && $previewUrl !== ''):
		$preview = obtenerPreviewDesdeUrlYandexDisk($configuracion, $previewUrl);
	endif;
	if (!($preview['ok'] ?? false)):
		return '';
	endif;

	return guardarPreviewTemporalDesdeContenido(
		$rutaDestino,
		(string) ($preview['body'] ?? ''),
		(string) ($preview['mime'] ?? 'image/jpeg')
	);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'):
	responderJsonYandexCopia(405, ['ok' => false, 'error' => 'Método no permitido.']);
endif;

$json = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($json)):
	responderJsonYandexCopia(400, ['ok' => false, 'error' => 'Solicitud inválida.']);
endif;

$configuracion = cargarConfiguracion();
$token = yandexDiskApiKeyConfiguracion($configuracion);
if ($token === ''):
	responderJsonYandexCopia(401, ['ok' => false, 'error' => 'No hay API Key de Yandex.Disk configurada.']);
endif;

$photoId = normalizarPhotoIdYandexDisk($json['photo_id'] ?? '');
$ruta = normalizarRutaYandexDisk($json['path'] ?? '');
if ($photoId === '' && $ruta === '/'):
	responderJsonYandexCopia(400, ['ok' => false, 'error' => 'Ruta o ID de Yandex inválido.']);
endif;
$tipoRemoto = strtolower(trim((string) ($json['tipo'] ?? '')));
$previewRemoto = (string) ($json['preview'] ?? '');

$destino = resolverDestinoYandexCopia($json['destino'] ?? '', $json['subcarpeta'] ?? '');
if (!$destino['ok']):
	responderJsonYandexCopia(400, ['ok' => false, 'error' => $destino['error']]);
endif;

$descarga = $photoId !== ''
	? obtenerUrlDescargaYandexPhoto($configuracion, $photoId)
	: obtenerUrlDescargaYandexDisk($configuracion, $ruta);
if (!($descarga['ok'] ?? false)):
	$codigo = (int) ($descarga['status'] ?? 502);
	if ($codigo < 400 || $codigo > 599):
		$codigo = 502;
	endif;
	responderJsonYandexCopia($codigo, ['ok' => false, 'error' => (string) ($descarga['error'] ?? 'No se pudo preparar la descarga de Yandex Disk.')]);
endif;

$href = str_replace(["\r", "\n"], '', (string) ($descarga['href'] ?? ''));
if ($href === '' || !yandexDiskUrlHostPermitido($href)):
	responderJsonYandexCopia(502, ['ok' => false, 'error' => 'Yandex Disk devolvió un destino de descarga no permitido.']);
endif;

$nombre = nombreArchivoYandexCopia($json['name'] ?? '', $ruta, $photoId);
$rutaDestino = rutaDestinoDisponibleYandexCopia((string) $destino['ruta'], $nombre);
$temporal = tempnam((string) $destino['ruta'], '.dam-yandex-');
if ($temporal === false):
	responderJsonYandexCopia(500, ['ok' => false, 'error' => 'No se pudo crear un archivo temporal local.']);
endif;

$resultado = descargarYandexCopiaAArchivo($href, $token, $temporal, true);
if (!($resultado['ok'] ?? false) && in_array((int) ($resultado['status'] ?? 0), [401, 403], true)):
	$resultado = descargarYandexCopiaAArchivo($href, $token, $temporal, false);
endif;

if (!($resultado['ok'] ?? false)):
	@unlink($temporal);
	$codigo = (int) ($resultado['status'] ?? 502);
	if ($codigo < 400 || $codigo > 599):
		$codigo = 502;
	endif;
	responderJsonYandexCopia($codigo, ['ok' => false, 'error' => (string) ($resultado['error'] ?? 'No se pudo copiar el archivo desde Yandex Disk.')]);
endif;

if (!@rename($temporal, $rutaDestino)):
	@unlink($temporal);
	responderJsonYandexCopia(500, ['ok' => false, 'error' => 'Se descargó el archivo temporal, pero no se pudo guardar con el nombre final.']);
endif;
@chmod($rutaDestino, 0644);

$previewTemporal = '';
if (yandexCopiaDebeGuardarPreviewTemporal($rutaDestino, $tipoRemoto)):
	$previewTemporal = guardarPreviewTemporalYandexCopia($configuracion, $ruta, $photoId, $previewRemoto, $rutaDestino);
endif;

$tipoIndice = tipoMultimediaDesdeRuta($rutaDestino);
if ($tipoIndice !== null):
	actualizarIndicePalabrasClave([[$rutaDestino, $tipoIndice]], 0, true);
endif;

$destinoRelativo = rutaRelativaDesdeProyecto($rutaDestino);
responderJsonYandexCopia(200, [
	'ok' => true,
	'destino' => $destinoRelativo,
	'nombre' => basename($rutaDestino),
	'bytes' => (int) ($resultado['bytes'] ?? filesize($rutaDestino)),
	'preview_temporal' => $previewTemporal,
	'mensaje' => 'Copiado en ' . $destinoRelativo,
]);

?>
