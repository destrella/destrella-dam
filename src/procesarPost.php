<?php
$json = file_get_contents('php://input');
$json = json_decode($json, TRUE);

if (!is_array($json)):
	http_response_code(400);
	echo 'Solicitud inválida.';
	exit;
endif;

function resolverDirectorioVista(?string $ruta, ?string $archivo = null): ?string
{
	$entrada = trim((string) ($ruta ?? ''));
	if ($entrada === ''):
		$entrada = trim((string) ($archivo ?? ''));
	endif;
	return resolverRutaTolerante($entrada, 'dir', true);
}

function resolverArchivoLocalAbrible(?string $ruta): ?string
{
	return resolverRutaTolerante($ruta, 'file', false);
}

function rutaDestinoDisponible(string $directorio, string $nombreArchivo): string
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

function resultadoOperacionArchivo(
	bool $ok,
	string $origen,
	string $destino = '',
	string $error = '',
	?string $directorioOrigen = null,
	?string $directorioEliminado = null,
	array $directoriosEliminados = []
): array
{
	$directorioOrigen ??= dirname($origen);
	return [
		'ok' => $ok,
		'origen' => rutaRelativaDesdeProyecto($origen),
		'destino' => $destino !== '' ? rutaRelativaDesdeProyecto($destino) : '',
		'directorio_origen' => rutaRelativaDesdeProyecto($directorioOrigen),
		'directorio_origen_eliminado' => !is_dir($directorioOrigen),
		'directorio_eliminado' => $directorioEliminado !== null ? rutaRelativaDesdeProyecto($directorioEliminado) : '',
		'directorios_eliminados' => array_map('rutaRelativaDesdeProyecto', $directoriosEliminados),
		'error' => $error,
	];
}

function candidatosDirectoriosLimpieza(string $directorio, string $rootPermitido): array
{
	$directorioReal = realpath($directorio);
	$rootReal = realpath($rootPermitido);
	if (!$directorioReal || !$rootReal):
		return [];
	endif;

	$rootReal = rtrim($rootReal, DIRECTORY_SEPARATOR);
	if ($directorioReal !== $rootReal && !str_starts_with($directorioReal, $rootReal . DIRECTORY_SEPARATOR)):
		return [];
	endif;

	$directorios = [];
	while ($directorioReal !== $rootReal):
		$directorios[] = $directorioReal;
		$directorioReal = dirname($directorioReal);
		if ($directorioReal !== $rootReal && !str_starts_with($directorioReal, $rootReal . DIRECTORY_SEPARATOR)):
			break;
		endif;
	endwhile;

	return $directorios;
}

function moverArchivoADirectorio(string $rutaOrigen, string $directorioDestino, bool $mantenerEnIndice = true, bool $permitirMismoDirectorio = false): array
{
	$directorioOrigen = dirname($rutaOrigen);
	if (!is_dir($directorioDestino) && !mkdir($directorioDestino, 0755, true)):
		return resultadoOperacionArchivo(false, $rutaOrigen, '', 'No se pudo crear la carpeta destino.', $directorioOrigen);
	endif;

	$destinoReal = realpath($directorioDestino);
	$origenDirReal = realpath($directorioOrigen);
	if (!$destinoReal || !$origenDirReal || !rutaDentroDeDirectorio($destinoReal, proyectoRaiz())):
		return resultadoOperacionArchivo(false, $rutaOrigen, '', 'Carpeta destino inválida.', $directorioOrigen);
	endif;

	if (!$permitirMismoDirectorio && $destinoReal === $origenDirReal):
		return resultadoOperacionArchivo(false, $rutaOrigen, '', 'El archivo ya está en esa carpeta.', $directorioOrigen);
	endif;

	$rutaDestino = rutaDestinoDisponible($destinoReal, basename($rutaOrigen));

	// Intentar rename primero (mismo sistema de archivos).
	// Si falla, copiar + borrar (sistemas de archivos distintos).
	if (!@rename($rutaOrigen, $rutaDestino)):
		if (!copy($rutaOrigen, $rutaDestino)):
			return resultadoOperacionArchivo(false, $rutaOrigen, $rutaDestino, 'No se pudo mover el archivo (rename ni copy funcionaron).', $directorioOrigen);
		endif;
		if (!@unlink($rutaOrigen)):
			@unlink($rutaDestino);
			return resultadoOperacionArchivo(false, $rutaOrigen, $rutaDestino, 'Se copió el archivo pero no se pudo eliminar el original.', $directorioOrigen);
		endif;
	endif;

	if ($mantenerEnIndice):
		$tipoIndice = tipoMultimediaDesdeRuta($rutaDestino);
		$indiceMovido = $tipoIndice !== null && moverIndicePalabrasClaveArchivo($rutaOrigen, $rutaDestino, $tipoIndice);
		if (!$indiceMovido):
			eliminarIndicePalabrasClaveArchivo($rutaOrigen, false);
		endif;
		if (!$indiceMovido && $tipoIndice !== null):
			actualizarIndicePalabrasClave([[$rutaDestino, $tipoIndice]], 0, true);
		endif;
		if (!$indiceMovido):
			limpiarPalabrasClaveSinUso();
		endif;
	else:
		eliminarIndicePalabrasClaveArchivo($rutaOrigen, false);
		eliminarIndicePalabrasClaveArchivo($rutaDestino, false);
		limpiarPalabrasClaveSinUso();
	endif;

	// Limpiar directorios vacíos. Si el origen está dentro del proyecto,
	// usar la raíz del proyecto como barrera; si está fuera, no limpiar.
	$origenEnProyecto = rutaDentroDeDirectorio($rutaOrigen, proyectoRaiz());
	$rootLimpieza = $origenEnProyecto ? proyectoRaiz() : dirname($rutaOrigen);
	$directoriosLimpieza = candidatosDirectoriosLimpieza($directorioOrigen, $rootLimpieza);
	limpiarDirectoriosVacios($directorioOrigen, $rootLimpieza);
	$directoriosEliminados = array_values(array_filter(
		$directoriosLimpieza,
		static fn($directorio) => !is_dir($directorio)
	));
	$directorioEliminado = !empty($directoriosEliminados) ? end($directoriosEliminados) : null;
	return resultadoOperacionArchivo(true, $rutaOrigen, $rutaDestino, '', $directorioOrigen, $directorioEliminado ?: null, $directoriosEliminados);
}

function archivarArchivo(string $rutaOrigen, array $configuracion): array
{
	$ruta = proyectoRaiz() . DIRECTORY_SEPARATOR . rtrim($configuracion['ruta_archivar'] ?? 'listas', '/');
	$fecha = '';
	$datos = obtenerMetadatos($rutaOrigen)['salida'];
	foreach ($datos as $d):
		if (str_starts_with($d, 'DateTimeOriginal')):
			$fecha = trim(explode(':', $d, 2)[1]);
			$fecha = explode(' ', $fecha)[0];
			$fecha = str_replace(':', DIRECTORY_SEPARATOR, $fecha);
			break;
		endif;
	endforeach;

	if ($fecha !== ''):
		$ruta .= DIRECTORY_SEPARATOR . $fecha;
	endif;

	return moverArchivoADirectorio($rutaOrigen, $ruta, true, true);
}

function descartarArchivo(string $rutaOrigen): array
{
	$directorioBasura = proyectoRaiz() . DIRECTORY_SEPARATOR . '.basura';
	return moverArchivoADirectorio($rutaOrigen, $directorioBasura, false, true);
}

function normalizarSubcarpetaDestino(mixed $valor): ?string
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

function resolverDestinoLote(mixed $destino, mixed $subcarpeta): array
{
	$base = resolverRutaProyecto((string) ($destino ?? ''), 'dir', true);
	if ($base === null):
		return ['ok' => false, 'ruta' => '', 'error' => 'La carpeta destino no es válida.'];
	endif;

	$subcarpeta = normalizarSubcarpetaDestino($subcarpeta);
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

	return ['ok' => true, 'ruta' => $real, 'error' => ''];
}

function registrarHeaderDirectorioEliminado(array $resultado): void
{
	if (!empty($resultado['directorio_origen_eliminado']) && !empty($resultado['directorio_origen'])):
		$directorio = !empty($resultado['directorio_eliminado']) ? $resultado['directorio_eliminado'] : $resultado['directorio_origen'];
		header('X-DAM-Deleted-Dir: ' . rawurlencode($directorio));
	endif;
}

function vistaDebeVolverARaiz(array $json): bool
{
	$entrada = trim((string) ($json['vista_ruta'] ?? ''));
	if ($entrada === ''):
		$entrada = trim((string) ($json['vista_archivo'] ?? ''));
	endif;
	if ($entrada === ''):
		return false;
	endif;

	return resolverDirectorioVista($json['vista_ruta'] ?? null, $json['vista_archivo'] ?? null) === null;
}

if (array_key_exists('operacion_lote', $json)):
	header('Content-Type: application/json; charset=UTF-8');
	$operacion = (string) ($json['operacion_lote'] ?? '');
	$rutasEntrada = is_array($json['rutas'] ?? null) ? $json['rutas'] : [];
	$rutas = [];
	foreach ($rutasEntrada as $rutaEntrada):
		$ruta = resolverRutaTolerante($rutaEntrada, 'file', false);
		if ($ruta !== null):
			$rutas[$ruta] = $ruta;
		endif;
	endforeach;

	if (!in_array($operacion, ['mover', 'archivar', 'borrar'], true) || empty($rutas)):
		http_response_code(400);
		echo json_encode([
			'ok' => false,
			'mensaje' => 'Operación o archivos inválidos.',
			'resultados' => [],
			'redirect_raiz' => false,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	endif;

	$destinoMover = null;
	if ($operacion === 'mover'):
		$destino = resolverDestinoLote($json['destino'] ?? '', $json['subcarpeta'] ?? '');
		if (!$destino['ok']):
			http_response_code(400);
			echo json_encode([
				'ok' => false,
				'mensaje' => $destino['error'],
				'resultados' => [],
				'redirect_raiz' => false,
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			exit;
		endif;
		$destinoMover = $destino['ruta'];
	endif;

	$configuracion = cargarConfiguracion();
	$resultadosOperacion = [];
	foreach ($rutas as $ruta):
		if ($operacion === 'mover'):
			$resultadosOperacion[] = moverArchivoADirectorio($ruta, $destinoMover, true, false);
		elseif ($operacion === 'archivar'):
			$resultadosOperacion[] = archivarArchivo($ruta, $configuracion);
		else:
			$resultadoDescarte = descartarArchivo($ruta);
			if ($resultadoDescarte['ok']):
				agregarEtiquetasFinder($ruta, 'DAM PHP', 'Descartado');
			endif;
			$resultadosOperacion[] = $resultadoDescarte;
		endif;
	endforeach;

	$ok = array_values(array_filter($resultadosOperacion, static fn($resultado) => !empty($resultado['ok'])));
	$errores = array_values(array_filter($resultadosOperacion, static fn($resultado) => empty($resultado['ok'])));
	echo json_encode([
		'ok' => !empty($ok) && empty($errores),
		'procesados' => count($ok),
		'errores' => $errores,
		'resultados' => $resultadosOperacion,
		'redirect_raiz' => vistaDebeVolverARaiz($json),
		'mensaje' => count($ok) . ' archivo' . (count($ok) === 1 ? '' : 's') . ' procesado' . (count($ok) === 1 ? '' : 's') . '.',
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
endif;

if (array_key_exists('sincronizar_palabras_clave', $json)):
	set_time_limit(0);
	header('Content-Type: application/x-ndjson; charset=UTF-8');
	header('Cache-Control: no-cache');
	header('X-Accel-Buffering: no');
	while (ob_get_level() > 0):
		@ob_end_flush();
	endwhile;
	@ob_implicit_flush(true);

	$emitirEvento = static function (array $evento): void {
		echo json_encode($evento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
		@flush();
	};

	try {
		$limite = max(0, (int) ($json['limite'] ?? 0));
		sincronizarIndicePalabrasClaveCompleto(carpetasIgnoradasConfiguracion(), $emitirEvento, $limite);
	} catch (\Throwable $e) {
		$emitirEvento([
			'tipo' => 'error',
			'mensaje' => 'Error al sincronizar palabras clave: ' . $e->getMessage(),
		]);
	}
	exit;
endif;

if (array_key_exists('relleno_pagina', $json)):
	$pagina = max(1, (int) ($json['pagina'] ?? 1));
	$ver = max(1, min(100, (int) ($json['ver'] ?? 6)));
	$id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($json['id'] ?? ''));
	if ($id === ''):
		$id = 'relleno' . time();
	endif;

	$rutaIterador = resolverDirectorioVista($json['ruta'] ?? null, $json['archivo'] ?? null);
	if ($rutaIterador === null):
		http_response_code(204);
		exit;
	endif;

	$media = in_array(($json['media'] ?? ''), ['fotos', 'videos'], true) ? $json['media'] : null;
	$palabraClave = obtenerPalabraClaveActiva($json);
	if ($palabraClave !== ''):
		$resultados = obtenerResultadosPorPalabraClave($palabraClave, $media);
	else:
		$omitir = carpetasIgnoradasConfiguracion();
		$resultados = obtenerResultadosMultimedia($rutaIterador, null, $omitir, $media);
	endif;
	$resultados = filtrarResultadosPorMetadatos($resultados, obtenerFiltrosMetadatosDesdeFuente($json));
	$indice = array_key_exists('indice', $json)
		? max(0, (int) $json['indice'])
		: (($pagina - 1) * $ver) + ($ver - 1);

	if (!isset($resultados[$indice])):
		http_response_code(204);
		exit;
	endif;

	echo crearBloque($resultados[$indice][0], $id, $resultados[$indice][1]);
elseif (array_key_exists('estado_metadatos', $json)):
	$ruta = resolverRutaTolerante($json['ruta'] ?? null, 'file', false);
	$id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($json['id'] ?? ''));
	$media = ($json['media'] ?? '') === 'vid' ? 'vid' : 'img';

	if ($id === '' || $ruta === null):
		http_response_code(400);
		echo 'No se pudo actualizar el estado del formulario.';
		exit;
	endif;

	echo crearBloque($ruta, $id, $media);
elseif (array_key_exists('corregir_region', $json)):
	$ruta = resolverRutaTolerante($json['ruta'] ?? null, 'file', false);
	$eje = (string) ($json['corregir_region'] ?? '');
	if ($ruta === null):
		http_response_code(400);
		echo '<code>Error: ruta inválida.</code>';
		exit;
	endif;
	$resultado = corregirOrientacionRegiones($ruta, $eje);
	$salida = !empty($resultado['salida'])
		? $resultado['salida']
		: ['Error: Respuesta vacía'];
	$salida = array_map('formatearRespuesta', $salida);
	echo
		'<code>' .
		($resultado['comando'] !== '' ? htmlspecialchars($resultado['comando'], ENT_QUOTES, 'UTF-8') . '<br>' : '') .
		implode('<br>', $salida) .
		'</code>';
elseif (array_key_exists('abrir_archivo', $json)):
	$rutaAbrir = resolverArchivoLocalAbrible($json['abrir_archivo'] ?? null);
	if ($rutaAbrir === null):
		http_response_code(400);
		echo '<code>Error: No se puede abrir la ruta solicitada.</code>';
		exit;
	endif;

	$open = is_executable('/usr/bin/open') ? '/usr/bin/open' : 'open';
	$comando = $open . ' ' . escapeshellarg($rutaAbrir);
	$salida = [];
	exec($comando . ' 2>&1', $salida, $codigo);
	echo '<code>' .
		htmlspecialchars($comando, ENT_QUOTES, 'UTF-8') .
		'<br>' .
		($codigo === 0
			? 'Archivo abierto con open.'
			: 'Error: open terminó con código ' . (int) $codigo) .
		(!empty($salida) ? '<br>' . implode('<br>', array_map('formatearRespuesta', $salida)) : '') .
		'</code>';
elseif (array_key_exists('abrir_carpeta', $json)):
	$rutaCarpeta = resolverArchivoLocalAbrible($json['abrir_carpeta'] ?? null);
	if ($rutaCarpeta === null):
		http_response_code(400);
		echo '<code>Error: No se puede abrir la ruta solicitada.</code>';
		exit;
	endif;

	$directorioPadre = dirname($rutaCarpeta);
	if (!is_dir($directorioPadre)):
		http_response_code(400);
		echo '<code>Error: La carpeta contenedora no existe.</code>';
		exit;
	endif;

	$open = is_executable('/usr/bin/open') ? '/usr/bin/open' : 'open';
	$comando = $open . ' ' . escapeshellarg($directorioPadre);
	$salida = [];
	exec($comando . ' 2>&1', $salida, $codigo);
	echo '<code>' .
		htmlspecialchars($comando, ENT_QUOTES, 'UTF-8') .
		'<br>' .
		($codigo === 0
			? 'Carpeta abierta con open.'
			: 'Error: open terminó con código ' . (int) $codigo) .
		(!empty($salida) ? '<br>' . implode('<br>', array_map('formatearRespuesta', $salida)) : '') .
		'</code>';
elseif (array_key_exists('subject', $json)):
	$rutaMetadatos = resolverRutaTolerante($json['ruta'] ?? null, 'file', false);
	if ($rutaMetadatos === null):
		http_response_code(400);
		echo '<code>Error: ruta de metadatos inválida.</code>';
		exit;
	endif;
	$mediaMetadatos = ($json['media'] ?? '') === 'vid' ? 'vid' : 'img';

	/*
	 * PARA PNG:
	 * exiftool -P -overwrite_original -charset utf8 -sep "," \
	 * -XMP:Subject="Foxy Pole Studio,lencería" \
	 * -XMP:Title="Foxy Pole Studio" \
	 * -XMP:Description="Lencería en el estudio de pole dance" \
	 * -XMP:DateCreated="2024:09:03 09:21:24" \
	 * -XMP:CreateDate="2024:09:03 09:21:24" \
	 * -XMP:ModifyDate="2024:09:03 09:21:24" \
	 * -XMP:Rights="Drea" \
	 * -FileCreateDate="2024:09:03 09:21:24" \
	 * -FileModifyDate="2024:09:03 09:21:24" \
	 * "imgs/Pole/_/poledrea/2024-09-03T09-21-24.000Z poledrea.png"
	 */
	$argumentos = [
		// Etiquetas de tracking que se limpian antes de escribir metadatos nuevos.
		argumentoExifTool('IPTCDigest', ''),
		argumentoExifTool('IPTC', ''),
		argumentoExifTool('SpecialInstructions', ''),
		argumentoExifTool('Instructions', ''),
		argumentoExifTool('LegacyIPTCDigest', '')
	];

	// OffsetTime
	if (!empty(trim($json['offsettime']))):
		$offtime = substr($json['offsettime'], 0, 3);
		$offtime .= ':';
		$offtime .= substr($json['offsettime'], -2, 2);
		if (trim($offtime, '-+') != '00:00'):
			$argumentos[] = argumentoExifTool('OffsetTime', $offtime);
			$argumentos[] = argumentoExifTool('OffsetTimeOriginal', $offtime);
			$argumentos[] = argumentoExifTool('OffsetTimeDigitized', $offtime);
		else:
			$offtime = '';
		endif;
	else:
		$offtime = '';
	endif;

	// Fecha y hora
	$createdate = trim((string) ($json['createdate'] ?? ''));
	$argumentos[] = argumentoExifTool('allDates', $createdate . $offtime);
	if ($mediaMetadatos == 'vid'):
		$argumentos[] = argumentoExifTool('Year', date('Y', strtotime($createdate)));
		$argumentos[] = argumentoExifTool('MediaCreateDate', $createdate);
		$argumentos[] = argumentoExifTool('MediaModifyDate', $createdate);
		$argumentos[] = argumentoExifTool('TrackCreateDate', $createdate);
		$argumentos[] = argumentoExifTool('TrackModifyDate', $createdate);
	endif;
	$argumentos[] = argumentoExifTool('FileCreateDate', $createdate);
	$argumentos[] = argumentoExifTool('FileModifyDate', $createdate);
	//echo '<pre>[allDates:'.$json['createdate'].']</pre>';

	// Campos input[text]
	$campos = ['title', 'copyright', 'make', 'model', 'software', 'description'];
	foreach ($campos as $campo):
		if (isset($json[$campo])):
			if (!empty(trim($json[$campo]))):
				$valor = normalizarTag(trim($json[$campo]));
				$argumentos[] = argumentoExifTool($campo, $valor);
				if ($campo == 'description'):
					$argumentos[] = argumentoExifTool('LongDescription', $valor);
					if ($mediaMetadatos == 'img'):
						$argumentos[] = argumentoExifTool('ImageDescription', $valor);
						$argumentos[] = argumentoExifTool('UserComment', $valor);
					elseif ($mediaMetadatos == 'vid'):
						$argumentos[] = argumentoExifTool('StoreDescription', $valor);
					endif;
				endif;
			else:
				$argumentos[] = argumentoExifTool($campo, '');
				if ($campo == 'description'):
					$argumentos[] = argumentoExifTool('LongDescription', '');
					if ($mediaMetadatos == 'img'):
						$argumentos[] = argumentoExifTool('ImageDescription', '');
						$argumentos[] = argumentoExifTool('UserComment', '');
					elseif ($mediaMetadatos == 'vid'):
						$argumentos[] = argumentoExifTool('StoreDescription', '');
					endif;
				endif;
			endif;
		endif;
	endforeach;

	$nombreUbicacion = '';
	if (isset($json['location'])):
		$nombreUbicacion = normalizarTag(trim((string) $json['location']));
		$nombreUbicacion = trim((string) preg_replace('/\s+/u', ' ', $nombreUbicacion));
	endif;

	// Subject
	$subject = normalizarPalabrasClave($json['subject'] ?? '');
	if ($nombreUbicacion !== ''):
		$subjectKeys = array_map(fn($tag) => mb_strtolower((string) $tag, 'UTF-8'), $subject);
		foreach (normalizarPalabrasClave($nombreUbicacion) as $tagUbicacion):
			$claveUbicacion = mb_strtolower($tagUbicacion, 'UTF-8');
			if (in_array($claveUbicacion, $subjectKeys, true)):
				continue;
			endif;
			$subject[] = $tagUbicacion;
			$subjectKeys[] = $claveUbicacion;
		endforeach;
	endif;
	$comandoLimpiarTags = comandoBrewSeguro([
		'exiftool',
		'-P',
		'-overwrite_original',
		'-Subject=',
		'-Keyword=',
		'-Keywords=',
		'-XMP-dc:Subject=',
		$rutaMetadatos
	]);
	exec($comandoLimpiarTags, $salidatag);
	if (!empty($subject)):
		foreach ($subject as $k => $tag):
			$argumentos[] = argumentoExifTool('XMP-dc:Subject', $tag, !empty($k));
		endforeach;
	endif;

	// Orientacion
	$rotar = '';
	if (isset($json['orientation'])):
		$orientacionFormulario = trim((string) $json['orientation']);
		if ($json['media'] == 'vid'):
			$rotacionVideo = valorRotacionVideoDesdeOrientacion($orientacionFormulario);
			$argumentos[] = argumentoExifTool('Rotation', $rotacionVideo > 0 ? (string) $rotacionVideo : '');
		elseif ($orientacionFormulario !== '' && $orientacionFormulario !== '0'):
			$orientacionExif = normalizarOrientacionExif($orientacionFormulario);
			if ($orientacionExif >= 1 && $orientacionExif <= 8):
				$argumentos[] = argumentoExifTool('Orientation#', $orientacionExif);
			endif;
		else:
			// 0, sin orientación
			$argumentos[] = argumentoExifTool('Orientation', '');
		endif;
	else:
		//echo 'Sin orientacion';
	endif;

	// Ubicación
	//echo 'Location:[';
	if ($nombreUbicacion !== ''):
		$argumentos[] = argumentoExifTool('Location', $nombreUbicacion);
		$locationKey = md5($nombreUbicacion);
		if (array_key_exists($locationKey, UBICACIONES)):
			foreach (UBICACIONES[$locationKey] as $k => $u):
				if (!$k || $k === 'Location'):
					continue;
				endif;
				switch ($k):
					case 'Country':
					case 'State':
					case 'City':
						$xmp = 'XMP:';
						break;
					default:
						$xmp = '';
				endswitch;
				$argumentos[] = argumentoExifTool($xmp . $k, UBICACIONES[$locationKey][$k]);
			endforeach;
		endif;
	endif;

	// Datos de geocodificación directa (resueltos con 🌐, no requieren UBICACIONES)
	$geoCountry = trim((string) ($json['geo_country'] ?? ''));
	$geoCountryCode = trim((string) ($json['geo_country_code'] ?? ''));
	$geoState = trim((string) ($json['geo_state'] ?? ''));
	$geoCity = trim((string) ($json['geo_city'] ?? ''));
	if ($geoCountry !== ''):
		$argumentos[] = argumentoExifTool('XMP:Country', $geoCountry);
	endif;
	if ($geoCountryCode !== ''):
		$argumentos[] = argumentoExifTool('XMP:CountryCode', $geoCountryCode);
	endif;
	if ($geoState !== ''):
		$argumentos[] = argumentoExifTool('XMP:State', $geoState);
	endif;
	if ($geoCity !== ''):
		$argumentos[] = argumentoExifTool('XMP:City', $geoCity);
	endif;
	//echo ']';

	$comando = comandoBrewSeguro(array_merge(
		['exiftool', '-P', '-overwrite_original', '-charset', 'utf8', '-sep', ','],
		$argumentos,
		[$rutaMetadatos]
	));
	$salida = [];
	$respuesta = exec($comando, $salida);
	if (is_file($rutaMetadatos)):
		actualizarIndicePalabrasClave([[$rutaMetadatos, $mediaMetadatos]], 0, true);
		limpiarPalabrasClaveSinUso();
		// Etiqueta Finder
		agregarEtiquetasFinder($rutaMetadatos, 'DAM PHP', 'Metadatos guardados');
	endif;
	if (!empty($salida)):
		$salida = array_map('formatearRespuesta', $salida);
	else:
		$salida = array_map('formatearRespuesta', ['Error: Respuesta vacía']);
	endif;
	echo
		'<code>' . escaparHtml($comando), '<br>' .
		implode('<br>', $salida) . '</code>';
elseif (array_key_exists('listo', $json)):
	$configuracion = cargarConfiguracion();
	$rutaOrigen = resolverRutaTolerante($json['listo'] ?? null, 'file', false);
	if ($rutaOrigen === null):
		http_response_code(400);
		echo 'Archivo a mover inválido.';
		exit;
	endif;

	$resultado = archivarArchivo($rutaOrigen, $configuracion);
	if ($resultado['ok']):
		registrarHeaderDirectorioEliminado($resultado);
		echo "1\n:<br>" . escaparHtml($resultado['destino']);
	else:
		echo 'Archivo a mover: ' . escaparHtml($rutaOrigen);
		echo 'Ubicación nueva: ' . escaparHtml($resultado['destino']);
		if ($resultado['error'] !== ''):
			echo '<br>' . escaparHtml($resultado['error']);
		endif;
	endif;
elseif (array_key_exists('borrar', $json)):
	$rutaBorrar = resolverRutaTolerante($json['borrar'] ?? null, 'file', false);
	if ($rutaBorrar === null):
		http_response_code(400);
		echo 'Archivo a borrar inválido.';
		exit;
	endif;

	$resultado = descartarArchivo($rutaBorrar);
	if ($resultado['ok']):
		agregarEtiquetasFinder($rutaBorrar, 'DAM PHP', 'Descartado');
		registrarHeaderDirectorioEliminado($resultado);
		echo "1\n.";
	else:
		echo 'Archivo a mover: ' . escaparHtml($rutaBorrar);
		echo 'Ubicación nueva: ' . escaparHtml($resultado['destino']);
		if ($resultado['error'] !== ''):
			echo '<br>' . escaparHtml($resultado['error']);
		endif;
	endif;
elseif (array_key_exists('extraer', $json)):
	$rutaExtraer = resolverRutaTolerante($json['extraer'] ?? null, 'file', false);
	echo $rutaExtraer !== null ? extraerIFrame($rutaExtraer) : 'No se encontró un archivo válido para extraer.';
elseif (array_key_exists('convertir', $json)):
	$rutaConvertir = resolverRutaTolerante($json['convertir'] ?? null, 'file', false);
	echo $rutaConvertir !== null ? convertirWebP($rutaConvertir) : 'No se encontró un archivo válido para convertir.';
elseif (array_key_exists('resolver_geo', $json)):
	header('Content-Type: application/json; charset=UTF-8');
	$lat = (float) ($json['lat'] ?? 0);
	$lon = (float) ($json['lon'] ?? 0);
	if ($lat === 0.0 && $lon === 0.0):
		echo json_encode(['ok' => false, 'error' => 'Coordenadas inválidas.']);
		exit;
	endif;
	$geo = resolverGeoNominatim($lat, $lon);
	$tieneDatos = $geo['Country'] !== null || $geo['City'] !== null;
	echo json_encode([
		'ok' => $tieneDatos,
		'country' => $geo['Country'] ?? '',
		'country_code' => $geo['CountryCode'] ?? '',
		'state' => $geo['State'] ?? '',
		'city' => $geo['City'] ?? '',
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
endif;
exit;
?>
