<?php

ini_set("pcre.jit", "0");
set_time_limit(300);

require_once __DIR__ . '/src/yandexDisk.php';

function responderErrorYandexMedia(int $codigo, string $mensaje): void
{
	if ($codigo < 400 || $codigo > 599):
		$codigo = 502;
	endif;
	http_response_code($codigo);
	header('Content-Type: text/plain; charset=UTF-8');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	echo $mensaje;
	exit;
}

function hostYandexMediaPermitido(string $url): bool
{
	if (filter_var($url, FILTER_VALIDATE_URL) === false):
		return false;
	endif;

	$host = parse_url($url, PHP_URL_HOST);
	if (!is_string($host)):
		return false;
	endif;

	$host = mb_strtolower($host, 'UTF-8');
	foreach (['disk.yandex.ru', 'disk.yandex.net', 'storage.yandex.net'] as $dominio):
		if ($host === $dominio || str_ends_with($host, '.' . $dominio)):
			return true;
		endif;
	endforeach;

	return false;
}

function hostYandexMediaAutorizable(string $url): bool
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

function resolverRedireccionYandexMedia(string $actual, string $location): string
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

function cabecerasRespuestaCurl(string $cabeceras): array
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

function descargarContenidoYandexMedia(string $url, string $token, string $rango = '', bool $autorizar = true): array
{
	if (!function_exists('curl_init')):
		return ['ok' => false, 'status' => 0, 'error' => 'La extensión cURL de PHP no está disponible.', 'headers' => [], 'body' => ''];
	endif;
	if (!hostYandexMediaPermitido($url)):
		return ['ok' => false, 'status' => 0, 'error' => 'Host de media no permitido.', 'headers' => [], 'body' => ''];
	endif;

	$actual = $url;
	for ($intento = 0; $intento < 3; $intento++):
		$curl = curl_init($actual);
		if ($curl === false):
			return ['ok' => false, 'status' => 0, 'error' => 'No se pudo preparar la descarga multimedia.', 'headers' => [], 'body' => ''];
		endif;

		$cabecerasPeticion = [
			'Accept: */*',
			'Accept-Encoding: identity',
			'User-Agent: DAM-local/1.0',
		];
		if ($autorizar && hostYandexMediaAutorizable($actual)):
			$cabecerasPeticion[] = 'Authorization: OAuth ' . $token;
		endif;
		if ($rango !== ''):
			$cabecerasPeticion[] = 'Range: ' . $rango;
		endif;

		$opciones = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT => 240,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_HTTPHEADER => $cabecerasPeticion,
		];
		if (defined('CURL_IPRESOLVE_V4')):
			$opciones[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
		endif;
		curl_setopt_array($curl, $opciones);

		$respuesta = curl_exec($curl);
		$errorCurl = curl_error($curl);
		$errorCodigo = curl_errno($curl);
		$codigo = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		$tamanoCabeceras = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);

		if ($respuesta === false):
			if (defined('CURLE_OPERATION_TIMEDOUT') && $errorCodigo === CURLE_OPERATION_TIMEDOUT):
				$errorCurl = 'Yandex Disk tardó demasiado en devolver el archivo multimedia.';
			endif;
			return ['ok' => false, 'status' => $codigo, 'error' => $errorCurl ?: 'No se pudo descargar el archivo multimedia.', 'headers' => [], 'body' => ''];
		endif;

		$cabeceras = substr((string) $respuesta, 0, $tamanoCabeceras);
		$body = substr((string) $respuesta, $tamanoCabeceras);
		$cabecerasRespuesta = cabecerasRespuestaCurl($cabeceras);

		if (in_array($codigo, [301, 302, 303, 307, 308], true)):
			$siguiente = resolverRedireccionYandexMedia($actual, (string) ($cabecerasRespuesta['location'] ?? ''));
			if ($siguiente !== '' && hostYandexMediaPermitido($siguiente)):
				$actual = $siguiente;
				continue;
			endif;
		endif;

		if ($codigo >= 200 && $codigo < 300):
			return ['ok' => true, 'status' => $codigo, 'error' => '', 'headers' => $cabecerasRespuesta, 'body' => $body];
		endif;

		return ['ok' => false, 'status' => $codigo, 'error' => 'Yandex Disk respondió con HTTP ' . $codigo . ' al abrir el archivo multimedia.', 'headers' => $cabecerasRespuesta, 'body' => ''];
	endfor;

	return ['ok' => false, 'status' => 502, 'error' => 'Yandex Disk devolvió demasiadas redirecciones.', 'headers' => [], 'body' => ''];
}

$configuracion = cargarConfiguracion();
$photoId = normalizarPhotoIdYandexDisk($_GET['photo_id'] ?? '');
$ruta = normalizarRutaYandexDisk($_GET['path'] ?? '');

$token = yandexDiskApiKeyConfiguracion($configuracion);
if ($token === ''):
	responderErrorYandexMedia(401, 'No hay API Key de Yandex.Disk configurada.');
endif;

if ($photoId !== ''):
	$tipoPhoto = (string) ($_GET['type'] ?? '') === 'video' ? 'video' : 'image';
	$nombrePhoto = str_replace(["\r", "\n", "\0"], '', trim((string) ($_GET['name'] ?? '')));
	if ($nombrePhoto === ''):
		$nombrePhoto = $tipoPhoto === 'video' ? 'yandex-photo-video-' . $photoId : 'yandex-photo-' . $photoId;
	endif;
	$item = [
		'nombre' => $nombrePhoto,
		'tipo' => $tipoPhoto,
		'mime' => $tipoPhoto === 'video' ? 'video/mp4' : 'image/jpeg',
	];
	$mime = (string) $item['mime'];
	$descarga = obtenerUrlDescargaYandexPhoto($configuracion, $photoId);
	if (!($descarga['ok'] ?? false)):
		responderErrorYandexMedia((int) ($descarga['status'] ?? 502), (string) ($descarga['error'] ?? 'No se pudo preparar la media de Yandex Photos.'));
	endif;
else:
	if ($ruta === '/'):
		responderErrorYandexMedia(400, 'Ruta multimedia inválida.');
	endif;

	$recurso = obtenerRecursoYandexDisk($configuracion, $ruta);
	if (!($recurso['ok'] ?? false)):
		responderErrorYandexMedia((int) ($recurso['status'] ?? 502), (string) ($recurso['error'] ?? 'No se pudo leer el recurso multimedia de Yandex Disk.'));
	endif;

	$item = is_array($recurso['recurso'] ?? null) ? $recurso['recurso'] : [];
	$mime = (string) ($item['mime'] ?? '');
	if ($mime === '' || !(str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/'))):
		$mime = ((string) ($item['tipo'] ?? 'image')) === 'video' ? 'video/mp4' : 'image/jpeg';
	endif;

	$descarga = obtenerUrlDescargaYandexDisk($configuracion, $ruta);
	if (!($descarga['ok'] ?? false)):
		responderErrorYandexMedia((int) ($descarga['status'] ?? 502), (string) ($descarga['error'] ?? 'No se pudo preparar la media de Yandex Disk.'));
	endif;
endif;

$href = str_replace(["\r", "\n"], '', (string) $descarga['href']);
if (!hostYandexMediaPermitido($href)):
	responderErrorYandexMedia(502, 'Yandex Disk devolvió un destino multimedia no permitido.');
endif;

$rango = '';
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=\d*-\d*$/', (string) $_SERVER['HTTP_RANGE'])):
	$rango = (string) $_SERVER['HTTP_RANGE'];
endif;

$contenido = descargarContenidoYandexMedia($href, $token, $rango, true);
if (!($contenido['ok'] ?? false) && in_array((int) ($contenido['status'] ?? 0), [401, 403], true)):
	$contenido = descargarContenidoYandexMedia($href, $token, $rango, false);
endif;

if (!($contenido['ok'] ?? false)):
	responderErrorYandexMedia((int) ($contenido['status'] ?? 502), (string) ($contenido['error'] ?? 'No se pudo abrir la media de Yandex Disk.'));
endif;

$cabeceras = is_array($contenido['headers'] ?? null) ? $contenido['headers'] : [];
$body = (string) ($contenido['body'] ?? '');
$codigo = (int) ($contenido['status'] ?? 200);
$nombre = (string) ($item['nombre'] ?? ($photoId !== '' ? 'yandex-photo-' . $photoId : basename($ruta)));
$nombreFallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nombre) ?: 'yandex-media';

http_response_code($codigo === 206 ? 206 : 200);
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($body));
header('Cache-Control: private, max-age=300');
header('Accept-Ranges: bytes');
header('Content-Disposition: inline; filename="' . $nombreFallback . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
header('X-Content-Type-Options: nosniff');

if (isset($cabeceras['content-range']) && $codigo === 206):
	header('Content-Range: ' . $cabeceras['content-range']);
endif;
if (isset($cabeceras['etag'])):
	header('ETag: ' . $cabeceras['etag']);
endif;
if (isset($cabeceras['last-modified'])):
	header('Last-Modified: ' . $cabeceras['last-modified']);
endif;

echo $body;

?>
