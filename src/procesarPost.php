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
	return resolverRutaProyecto($entrada, 'dir', true);
}

function resolverArchivoLocalAbrible(?string $ruta): ?string
{
	return resolverRutaProyecto($ruta, 'file', false);
}

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
	$indice = (($pagina - 1) * $ver) + ($ver - 1);

	if (!isset($resultados[$indice])):
		http_response_code(204);
		exit;
	endif;

	echo crearBloque($resultados[$indice][0], $id, $resultados[$indice][1]);
elseif (array_key_exists('estado_metadatos', $json)):
	$ruta = resolverRutaProyecto($json['ruta'] ?? null, 'file', false);
	$id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($json['id'] ?? ''));
	$media = ($json['media'] ?? '') === 'vid' ? 'vid' : 'img';

	if ($id === '' || $ruta === null):
		http_response_code(400);
		echo 'No se pudo actualizar el estado del formulario.';
		exit;
	endif;

	echo crearBloque($ruta, $id, $media);
elseif (array_key_exists('corregir_region', $json)):
	$ruta = resolverRutaProyecto($json['ruta'] ?? null, 'file', false);
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
elseif (array_key_exists('subject', $json)):
	$rutaMetadatos = resolverRutaProyecto($json['ruta'] ?? null, 'file', false);
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
		argumentoExifTool('SpecialInstructions', '')
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
	$rutaOrigen = resolverRutaProyecto($json['listo'] ?? null, 'file', false);
	if ($rutaOrigen === null):
		http_response_code(400);
		echo 'Archivo a mover inválido.';
		exit;
	endif;

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

	if (!empty($fecha)):
		$ruta .= DIRECTORY_SEPARATOR . $fecha;
		if (!is_dir($ruta)):
			mkdir($ruta, 0755, true);
		endif;
	elseif (!is_dir($ruta)):
		mkdir($ruta, 0755, true);
	endif;

	$ruta .= DIRECTORY_SEPARATOR . basename($rutaOrigen);

	if (rename($rutaOrigen, $ruta)):
		$tipoIndice = tipoMultimediaDesdeRuta($ruta);
		$indiceMovido = $tipoIndice !== null && moverIndicePalabrasClaveArchivo($rutaOrigen, $ruta, $tipoIndice);
		if (!$indiceMovido):
			eliminarIndicePalabrasClaveArchivo($rutaOrigen, false);
		endif;
		if (!$indiceMovido && $tipoIndice !== null):
				actualizarIndicePalabrasClave([[$ruta, $tipoIndice]], 0, true);
		endif;
		if (!$indiceMovido):
			limpiarPalabrasClaveSinUso();
		endif;
		echo "1\n:<br>" . escaparHtml(rutaRelativaDesdeProyecto($ruta));
		$carpetaOriginal = dirname($rutaOrigen);
		limpiarDirectoriosVacios(
			$carpetaOriginal,
			proyectoRaiz()
		);
	else:
		echo 'Archivo a mover: ' . escaparHtml($rutaOrigen);
		echo 'Ubicación nueva: ' . escaparHtml($ruta);
	endif;
elseif (array_key_exists('borrar', $json)):
	$rutaBorrar = resolverRutaProyecto($json['borrar'] ?? null, 'file', false);
	if ($rutaBorrar === null):
		http_response_code(400);
		echo 'Archivo a borrar inválido.';
		exit;
	endif;
	$directorioBasura = proyectoRaiz() . DIRECTORY_SEPARATOR . '.basura';
	if (!is_dir($directorioBasura)):
		mkdir($directorioBasura, 0755, true);
	endif;
	$adonde = $directorioBasura . DIRECTORY_SEPARATOR . basename($rutaBorrar);
	if (rename($rutaBorrar, $adonde)):
		eliminarIndicePalabrasClaveArchivo($rutaBorrar, false);
		eliminarIndicePalabrasClaveArchivo($adonde, false);
		limpiarPalabrasClaveSinUso();
		echo "1\n.";
	else:
		echo 'Archivo a mover: ' . escaparHtml($rutaBorrar);
		echo 'Ubicación nueva: ' . escaparHtml($adonde);
	endif;
elseif (array_key_exists('extraer', $json)):
	$rutaExtraer = resolverRutaProyecto($json['extraer'] ?? null, 'file', false);
	echo $rutaExtraer !== null ? extraerIFrame($rutaExtraer) : 'No se encontró un archivo válido para extraer.';
elseif (array_key_exists('convertir', $json)):
	$rutaConvertir = resolverRutaProyecto($json['convertir'] ?? null, 'file', false);
	echo $rutaConvertir !== null ? convertirWebP($rutaConvertir) : 'No se encontró un archivo válido para convertir.';
endif;
exit;
?>
