<?php
ini_set("pcre.jit", "0");
set_time_limit(300);

require_once __DIR__ . '/src/funciones.php';

function extraer_h(mixed $valor): string
{
	return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function volverExtraccion(?string $valor): string
{
	$valor = trim((string) $valor);
	if ($valor === ''):
		return 'index.php';
	endif;
	if (
		str_starts_with($valor, 'http://dam.local/')
		|| str_starts_with($valor, 'https://dam.local/')
		|| str_starts_with($valor, 'index.php')
		|| str_starts_with($valor, './index.php')
		|| str_starts_with($valor, '/')
		|| str_starts_with($valor, '?')
	):
		return $valor;
	endif;

	return 'index.php';
}

function renderizarLogExtraccion(array $logs): string
{
	if (empty($logs)):
		return '';
	endif;

	$html = '<details class="extraccion-log" open><summary>Salida</summary>';
	foreach ($logs as $log):
		$html .= '<div class="extraccion-log-bloque">';
		if (!empty($log['comando'])):
			$html .= '<code>' . extraer_h($log['comando']) . '</code>';
		endif;
		if (!empty($log['salida'])):
			$html .= '<pre>' . extraer_h(implode("\n", $log['salida'])) . '</pre>';
		endif;
		$html .= '</div>';
	endforeach;
	$html .= '</details>';

	return $html;
}

$configuracion = cargarConfiguracion();
$entradaVideo = $_POST['video'] ?? $_GET['video'] ?? '';
$video = resolverVideoExtraccion($entradaVideo);
$volver = volverExtraccion($_POST['volver'] ?? $_GET['volver'] ?? '');
$formatos = formatosExtraccionFrameSoportados();
$modoEntrada = (string) ($_POST['modo'] ?? 'keyframe');
$modo = in_array($modoEntrada, ['keyframe', 'frame', 'timestamp'], true) ? $modoEntrada : 'keyframe';
$indiceEntrada = str_replace(',', '.', (string) ($_POST['indice'] ?? '0'));
$indiceBruto = is_numeric($indiceEntrada) ? (float) $indiceEntrada : 0.0;
$formato = in_array(($_POST['formato'] ?? 'jpg'), $formatos, true) ? (string) ($_POST['formato'] ?? 'jpg') : 'jpg';
$accion = (string) ($_POST['accion'] ?? '');
$mensaje = '';
$error = $video === null ? 'No se encontró un video válido dentro del proyecto.' : '';
$logs = [];
$previewRelativa = '';
$imagenFinalRelativa = '';
$info = $video !== null ? obtenerInformacionVideoExtraccion($video) : [];
$keyframes = $video !== null ? obtenerKeyframesVideo($video) : [];
$duracion = (float) ($info['duracion'] ?? 0);
$fps = (float) ($info['fps'] ?? 0);
$frames = (int) ($info['frames'] ?? 0);
$keyframesTotal = count($keyframes);
$indiceMaxFrame = max(0, $frames - 1);
$indiceMaxKeyframe = max(0, $keyframesTotal - 1);
$indiceMaxTimestamp = max(0, $duracion);
$indiceMaxTimestampUI = round($indiceMaxTimestamp, 2);
$indice = match ($modo) {
	'timestamp' => min(max(0, $indiceBruto), $indiceMaxTimestamp),
	'frame' => min(max(0, (int) round($indiceBruto)), $indiceMaxFrame),
	default => min(max(0, (int) round($indiceBruto)), $indiceMaxKeyframe),
};

if ($video !== null && $_SERVER['REQUEST_METHOD'] === 'POST'):
	if ($accion === 'previsualizar'):
		$rutaPreview = construirRutaPreviewFrame($video, $modo, $indice);
		$resultado = extraerFrameSeleccionadoVideo($video, $rutaPreview, $modo, $indice, 'jpg', $keyframes);
		$logs[] = $resultado;
		if ($resultado['ok']):
			$previewRelativa = urlVisualizacion($rutaPreview) . '?v=' . filemtime($rutaPreview);
			$mensaje = 'Preview actualizado.';
		else:
			$error = 'No se pudo generar el preview.';
		endif;
	elseif ($accion === 'extraer'):
		$rutaImagen = construirRutaImagenExtraida($video, $modo, $indice, $formato);
		$resultado = extraerFrameSeleccionadoVideo($video, $rutaImagen, $modo, $indice, $formato, $keyframes);
		$logs[] = $resultado;
		if ($resultado['ok']):
			$metadatos = copiarMetadatosVideoAImagen($video, $rutaImagen);
			$logs[] = $metadatos;
			actualizarIndicePalabrasClave([[$rutaImagen, 'img']], 0, true);
			limpiarPalabrasClaveSinUso();
			// Etiqueta Finder tanto al video como a la imagen extraída
			agregarEtiquetasFinder($video, 'DAM PHP');
			agregarEtiquetasFinder($rutaImagen, 'DAM PHP', 'Imagen extraída de video');
			$imagenFinalRelativa = urlVisualizacion($rutaImagen) . '?v=' . filemtime($rutaImagen);
			$mensaje = $metadatos['ok']
				? 'Imagen extraída con metadatos heredados.'
				: 'Imagen extraída, pero ExifTool reportó una advertencia al copiar metadatos.';
		else:
			$error = 'No se pudo extraer la imagen.';
		endif;
	endif;
endif;

// Usar la ruta real (absoluta para archivos fuera del proyecto) en el
// formulario para que resolverVideoExtraccion() pueda resolverla.
$videoRelativo = $video !== null ? $video : '';
$indiceMaxActual = match ($modo) {
	'timestamp' => $indiceMaxTimestampUI,
	'frame' => $indiceMaxFrame,
	default => $indiceMaxKeyframe,
};
$stepIndiceActual = $modo === 'timestamp' ? '0.01' : '1';
$valorIndiceActual = $modo === 'timestamp'
	? formatearValorExtraccionFrame('timestamp', $indice)
	: formatearValorExtraccionFrame($modo, $indice);
?>
<!DOCTYPE html>
<html lang="es" class="extraccion-html"<?php echo atributoTemaConfiguracion($configuracion); ?>>
<head>
	<meta charset="UTF-8">
	<title>Extraer imagen</title>
	<link href="estilos.css?v=<?php echo filemtime('estilos.css'); ?>" rel="stylesheet">
</head>
<body class="configuracion-page configuracion-admin extraccion-page">
	<div class="configuracion-layout">
		<?php echo menuConfiguracion(''); ?>

		<main class="configuracion-contenido">
			<section class="configuracion-card extraccion-card">
				<div class="extraccion-encabezado">
					<h1>Extraer imagen</h1>
					<a href="<?php echo extraer_h($volver); ?>" class="extraccion-volver">← Volver</a>
				</div>

				<?php if ($mensaje !== ''): ?>
					<div class="configuracion-mensaje"><?php echo extraer_h($mensaje); ?></div>
				<?php endif; ?>
				<?php if ($error !== ''): ?>
					<div class="configuracion-error"><?php echo extraer_h($error); ?></div>
				<?php endif; ?>

				<?php if ($video !== null): ?>
					<div class="extraccion-video-info">
						<div>
							<span>Video</span>
							<strong title="<?php echo extraer_h($videoRelativo); ?>"><?php echo extraer_h(basename($video)); ?></strong>
						</div>
						<div>
							<span>Dimensiones</span>
							<strong><?php echo (int) ($info['ancho'] ?? 0); ?>×<?php echo (int) ($info['alto'] ?? 0); ?></strong>
						</div>
						<div>
							<span>Duración</span>
							<strong><?php echo extraer_h(number_format($duracion, 2)); ?> s</strong>
						</div>
						<div>
							<span>Frames</span>
							<strong><?php echo $frames > 0 ? extraer_h($frames . (($info['frames_estimados'] ?? false) ? ' aprox.' : '')) : 'N/D'; ?></strong>
						</div>
						<div>
							<span>FPS</span>
							<strong><?php echo $fps > 0 ? extraer_h(number_format($fps, 3)) : 'N/D'; ?></strong>
						</div>
						<div>
							<span>Keyframes</span>
							<strong><?php echo $keyframesTotal > 0 ? extraer_h($keyframesTotal) : 'N/D'; ?></strong>
						</div>
					</div>

					<form method="post" class="configuracion-form extraccion-form">
						<input type="hidden" name="video" value="<?php echo extraer_h($videoRelativo); ?>">
						<input type="hidden" name="volver" value="<?php echo extraer_h($volver); ?>">

						<div class="extraccion-controles">
							<label>
								<span>Tipo</span>
								<select
									name="modo"
									id="modo-extraccion"
									data-max-keyframe="<?php echo $indiceMaxKeyframe; ?>"
									data-max-frame="<?php echo $indiceMaxFrame; ?>"
									data-max-timestamp="<?php echo extraer_h(number_format($indiceMaxTimestampUI, 2, '.', '')); ?>"
								>
									<option value="keyframe"<?php echo $modo === 'keyframe' ? ' selected' : ''; ?>>Keyframe</option>
									<option value="frame"<?php echo $modo === 'frame' ? ' selected' : ''; ?>>Frame</option>
									<option value="timestamp"<?php echo $modo === 'timestamp' ? ' selected' : ''; ?>>Timestamp</option>
								</select>
							</label>

							<label class="extraccion-slider-label">
								<span>Índice</span>
								<input
									type="range"
									name="indice"
									id="indice-extraccion"
									min="0"
									max="<?php echo extraer_h($indiceMaxActual); ?>"
									step="<?php echo extraer_h($stepIndiceActual); ?>"
									value="<?php echo extraer_h($valorIndiceActual); ?>"
								>
								<output id="valor-indice-extraccion" for="indice-extraccion"><?php echo extraer_h($modo === 'timestamp' ? $valorIndiceActual . ' s' : ucfirst($modo) . ' ' . $valorIndiceActual); ?></output>
							</label>

							<label>
								<span>Formato</span>
								<select name="formato">
									<?php foreach ($formatos as $opcion): ?>
										<option value="<?php echo extraer_h($opcion); ?>"<?php echo $formato === $opcion ? ' selected' : ''; ?>><?php echo extraer_h($opcion); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</div>

						<div class="configuracion-acciones extraccion-acciones">
							<button type="submit" name="accion" value="previsualizar">Previsualizar</button>
							<button type="submit" name="accion" value="extraer">Extraer imagen</button>
						</div>
					</form>

					<?php if ($previewRelativa !== '' || $imagenFinalRelativa !== ''): ?>
						<div class="extraccion-preview">
							<?php if ($previewRelativa !== ''): ?>
								<h2>Preview</h2>
								<img src="<?php echo extraer_h($previewRelativa); ?>" alt="Preview del frame seleccionado">
							<?php endif; ?>
							<?php if ($imagenFinalRelativa !== ''): ?>
								<h2>Imagen generada</h2>
								<a href="<?php echo extraer_h($imagenFinalRelativa); ?>">
									<img src="<?php echo extraer_h($imagenFinalRelativa); ?>" alt="Imagen extraída">
								</a>
								<p><?php echo extraer_h(strtok($imagenFinalRelativa, '?')); ?></p>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php echo renderizarLogExtraccion($logs); ?>
				<?php endif; ?>
			</section>
		</main>
	</div>

	<script>
		const modo = document.getElementById('modo-extraccion');
		const indice = document.getElementById('indice-extraccion');
		const valorIndice = document.getElementById('valor-indice-extraccion');
		if (modo && indice) {
			const formatearTiempo = (valor) => {
				const numero = Number(valor || 0);
				return `${numero.toLocaleString('es-MX', {
					minimumFractionDigits: 0,
					maximumFractionDigits: 2
				})} s`;
			};
			const formatearValor = () => {
				if (!valorIndice) return;
				if (modo.value === 'timestamp') {
					valorIndice.textContent = formatearTiempo(indice.value);
				} else {
					const etiqueta = modo.value === 'keyframe' ? 'Keyframe' : 'Frame';
					valorIndice.textContent = `${etiqueta} ${Math.round(Number(indice.value || 0))}`;
				}
			};
			const actualizarMaximo = () => {
				const max = modo.value === 'keyframe'
					? modo.dataset.maxKeyframe
					: (modo.value === 'timestamp' ? modo.dataset.maxTimestamp : modo.dataset.maxFrame);
				indice.max = max || '0';
				indice.step = modo.value === 'timestamp' ? '0.01' : '1';
				if (Number(indice.value) > Number(indice.max)) indice.value = indice.max;
				if (modo.value !== 'timestamp') indice.value = String(Math.round(Number(indice.value || 0)));
				formatearValor();
			};
			modo.addEventListener('change', actualizarMaximo);
			indice.addEventListener('input', formatearValor);
			actualizarMaximo();
		}
	</script>
</body>
</html>
