<?php
ini_set("pcre.jit", "0");
define('ESTE', $_SERVER['SCRIPT_NAME']);

require_once('src/funciones.php');

// API METADATA LECTURA SEGURA (Alternativa para WebP)
// Usa resolverRutaTolerante para soportar archivos fuera del proyecto.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_meta'])):
	$ruta = resolverRutaTolerante($_GET['ajax_meta'] ?? null, 'file', false);
	if ($ruta !== null):
		$meta = obtenerMetadatos($ruta);
		$res = $meta['resultado'] ?? [];

		$regions = [];
		$rNames = valoresRegionMetadato($res, 'RegionName');
		$rXs = valoresRegionMetadato($res, 'RegionAreaX');
		$rYs = valoresRegionMetadato($res, 'RegionAreaY');
		$rWs = valoresRegionMetadato($res, 'RegionAreaW');
		$rHs = valoresRegionMetadato($res, 'RegionAreaH');
		$rUnits = valoresRegionMetadato($res, 'RegionAreaUnit');
		$rTypes = valoresRegionMetadato($res, 'RegionType');
		$totalRegions = max(count($rNames), count($rXs), count($rYs), count($rWs), count($rHs));

		for ($idx = 0; $idx < $totalRegions; $idx++):
			$name = trim((string) valorRegionMetadato($rNames, $idx, ''));
			$x = valorRegionMetadato($rXs, $idx, null);
			$y = valorRegionMetadato($rYs, $idx, null);
			$w = valorRegionMetadato($rWs, $idx, null);
			$h = valorRegionMetadato($rHs, $idx, null);
			if ($name === '' || $x === null || $y === null || $w === null || $h === null):
				continue;
			endif;

			$regions[] = [
				'Name' => $name,
				'Type' => trim((string) valorRegionMetadato($rTypes, $idx, 'Face')),
				'Area' => [
					'x' => (float) $x,
					'y' => (float) $y,
					'w' => (float) $w,
					'h' => (float) $h,
					'unit' => trim((string) valorRegionMetadato($rUnits, $idx, 'normalized'))
				]
			];
		endfor;

		header('Content-Type: application/json');
		echo json_encode([
			'Subject' => $res['Subject'] ?? '',
			'Copyright' => $res['Copyright'] ?? '',
			'Orientation' => $res['Orientation'] ?? 1,
			'AppliedToDimensions' => [
				'w' => isset($res['RegionAppliedToDimensionsW']) ? (float) $res['RegionAppliedToDimensionsW'] : null,
				'h' => isset($res['RegionAppliedToDimensionsH']) ? (float) $res['RegionAppliedToDimensionsH'] : null
			],
			'RegionList' => $regions
		]);
		exit;
	endif;
endif;

// GUARDA LOS CAMBIOS EN UNA IMAGEN
if ($_SERVER['REQUEST_METHOD'] === 'POST'):
	$json = file_get_contents('php://input');
	$json = json_decode($json, TRUE);
	if (!is_array($json) || !isset($json['areas']) || !is_array($json['areas'])):
		http_response_code(400);
		die('<div class="response-message response-error">Solicitud inválida.</div>');
	endif;
	$archivoRegion = resolverRutaTolerante($json['archivo'] ?? null, 'file', false);
	if ($archivoRegion === null):
		http_response_code(400);
		die('<div class="response-message response-error">Archivo inválido.</div>');
	endif;
	$subject = [];
	$regiones = [];
	$regs = [];
	foreach ($json['areas'] as $area):
		var_dump_pre($area['nombre'], 'Nombre', FALSE);
		if (empty(trim($area['nombre']))):
			continue;
		endif;
		$area['nombre'] = normalizarTag($area['nombre']);
		$posX1 = min($area['x1'], $area['x2']);
		$posY1 = min($area['y1'], $area['y2']);
		$posX2 = max($area['x1'], $area['x2']);
		$posY2 = max($area['y1'], $area['y2']);
		$centroX = ceil(($posX1 + $posX2) / 2);
		$centroY = ceil(($posY1 + $posY2) / 2);
		$ancho = $posX2 - $posX1;
		$alto = $posY2 - $posY1;

		$regs[] = [
			'RegionName' => $area['nombre'],
			'RegionAreaX' => $centroX / $json['ancho'],
			'RegionAreaY' => $centroY / $json['alto'],
			'RegionAreaW' => $ancho / $json['ancho'],
			'RegionAreaH' => $alto / $json['alto'],
			'RegionType' => 'Face',
			'RegionAreaUnit' => 'normalized'
		];
		var_dump_pre($regs, 'Regiones', FALSE);
		$subject[] = $area['nombre'];
		$regiones[] = 'Area={W=' . $ancho / $json['ancho'] . ', H=' . $alto / $json['alto'] . ', X=' . $centroX / $json['ancho'] . ', Y=' . $centroY / $json['alto'] . ', Unit=normalized}, Name=' . $area['nombre'] . ', Type=Face';
	endforeach;
	var_dump_pre($regs, 'Regiones (todas)', FALSE);
	if (empty($regiones)):
		die('<div class="response-message response-error">No se enconrtaron regiones con nombre.</div>');
	endif;
	// Busca etiquetas existentes
	list($subject_actual, $nada) = leerXMP($archivoRegion);
	var_dump_pre($subject_actual, 'actuales', FALSE);

	if (!empty($subject_actual)):
		//echo $subject_actual.'<br>';
		$subject_actual = explode(',', $subject_actual);
		$subject = array_merge($subject, $subject_actual);
	endif;

	$subject = normalizarPalabrasClave($subject);
	$subject_args = [];
	if (!empty($subject)):
		$comando = comandoBrewSeguro([
			'exiftool',
			'-P',
			'-overwrite_original',
			'-Subject=',
			'-Keyword=',
			'-Keywords=',
			'-XMP-dc:Subject=',
			$archivoRegion
		]);
		exec($comando, $salidatag);
		foreach ($subject as $k => $tag):
			$subject_args[] = argumentoExifTool('XMP-dc:Subject', $tag, !empty($k));
		endforeach;
	endif;

	$regiones = '{' . implode('}, {', $regiones) . '}';
	$regionInfo = '{AppliedToDimensions={W=' . (float) $json['ancho'] . ',H=' . (float) $json['alto'] . ',Unit=pixel}, RegionList=[' . $regiones . ']}';
	$comando = comandoBrewSeguro(array_merge(
		[
			'exiftool',
			'-P',
			'-overwrite_original',
			argumentoExifTool('Instructions', ''),
			'-sep',
			','
		],
		$subject_args,
		[
			argumentoExifTool('XMP-mwg-rs:RegionInfo', $regionInfo),
			argumentoExifTool('IPTCDigest', 'new'),
			$archivoRegion
		]
	));

	var_dump_pre($regiones, '$regiones', FALSE);
	var_dump_pre($comando, '$comando', FALSE);
	$respuesta = exec($comando, $salida);
	$salida = array_map('formatearRespuesta', $salida);
	// Etiqueta Finder después de guardar regiones
	if (is_file($archivoRegion)):
		agregarEtiquetasFinder($archivoRegion, 'DAM PHP', 'Regiones guardadas');
	endif;
	//$salida = ['LOLO'];
	die('<div class="comando">' . escaparHtml($comando) . '</div>' . implode('<br>', $salida));
endif;

// Resolver la ruta con tolerancia (proyecto o home).
// Necesitamos dos representaciones:
//   $rutaReal   → ruta absoluta real (para POST, data-file, info)
//   $archivoUrl → URL de visualización (para <img src>, acepta servir.php)
$rutaReal = ($_GET['foto'] ?? FALSE);
$rutaReal = $rutaReal
	? resolverRutaTolerante($rutaReal, 'file', false)
	: FALSE;
$archivoUrl = $rutaReal ? urlVisualizacion($rutaReal) : FALSE;

if (!$rutaReal):
	//OBTENER LAS IMÁGENES DE LA CARPETA
	$imgs = [];
	$iterador = new SortedIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CARPETA)));
	foreach ($iterador as $archivo):
		if ($archivo->isFile()):
			switch ($archivo->getExtension()):
				case 'jpg':
				case 'jpeg':
				case 'webp':
					$rutarelativa = str_replace(__DIR__ . '/', '', $archivo->getPathname());
					$imgs[] = $rutarelativa;
					break;
			endswitch;
		endif;
	endforeach;
	$imgsPaths = $imgs;
	$imgsUrls = array_map('urlVisualizacion', $imgsPaths);
	$imgs = '"' . implode('","', $imgsUrls) . '"';
	$imgsDataPaths = '"' . implode('","', $imgsPaths) . '"';
else:
	$imgs = '"' . $archivoUrl . '"';
	$imgsDataPaths = '"' . $rutaReal . '"';
endif;

?><!DOCTYPE html>
<html lang="es">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Etiquetar rostros</title>
	<style>
		:root {
			color-scheme: light;
			--bg-body: #fffff6;
			--text-body: #000000;
			--border-color: #cccccc;
			--shadow-color: rgba(0, 0, 0, 0.2);
			--code-bg: #f5f5f5;
			--code-text: #333333;
			--accent-primary: #6b6bff;
			--accent-secondary: #4a4ac9;
			--negative-prompt: #c44;
			--btn-bg: rgba(102, 153, 255, 0.267);
			--btn-text: #ffffff;
			--btn-border: #20538d;
			--btn-hover-bg: rgba(102, 153, 255, 0.8);
			--btn-hover-border: #2a4e77;
			--btn-active-bg: #2e5481;
			--btn-active-border: #203e5f;
			--btn-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4), 0 1px 1px rgba(0, 0, 0, 0.2);
			--btn-active-shadow: inset 0 1px 4px rgba(0, 0, 0, 0.6);
			--grey-text: grey;
			--fieldset-border: #444;
			--surface-bg: color-mix(in srgb, var(--bg-body) 82%, var(--code-bg));
			--control-bg: color-mix(in srgb, var(--bg-body) 88%, white);
			--canvas-border: #000000;
			--selection-stroke: #d93025;
			--region-stroke: #128a32;
			--region-label-fill: #ffffff;
			--region-label-stroke: #000000;
		}

		@media (prefers-color-scheme: dark) {
			:root {
				color-scheme: dark;
				--bg-body: #191919;
				--text-body: #e0e0e0;
				--border-color: #444444;
				--shadow-color: rgba(0, 0, 0, 0.6);
				--code-bg: #1e1e1e;
				--code-text: #cccccc;
				--accent-primary: #8a8aff;
				--accent-secondary: #6b6bff;
				--negative-prompt: #ff6b6b;
				--btn-bg: rgba(102, 153, 255, 0.5);
				--btn-text: #ffffff;
				--btn-border: #3a5a8a;
				--btn-hover-bg: rgba(102, 153, 255, 0.9);
				--btn-hover-border: #4a6e9e;
				--btn-active-bg: #3a5f8a;
				--btn-active-border: #2a4a6a;
				--grey-text: #a0a0a0;
				--fieldset-border: #666666;
				--control-bg: color-mix(in srgb, var(--bg-body) 82%, #2a2a2a);
				--canvas-border: #444444;
				--selection-stroke: #ff6b6b;
				--region-stroke: #86efac;
			}
		}

		html {
			font-size: 12px;
			padding: 0;
			min-height: 100%;
		}

		body {
			margin: 0;
			min-height: 100vh;
			background-color: var(--bg-body);
			color: var(--text-body);
			font-family: sans-serif;
			font-size: clamp(0.8rem, 0.354rem + 2.0672vw, 1.8rem);
		}

		div,
		canvas,
		h1,
		fieldset,
		input,
		select,
		button {
			box-sizing: border-box;
		}

		a {
			color: var(--accent-primary);
		}

		a:hover {
			color: var(--accent-secondary);
		}

		.encabezado-caras {
			margin: 0;
			padding: 0.75rem 1rem 0.35rem;
			text-align: center;
			font-size: clamp(1.4rem, 1rem + 2vw, 2.4rem);
			line-height: 1.1;
		}

		.buscador-caras {
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 0.5rem;
			width: min(100%, 980px);
			margin: 0 auto 1rem;
			padding: 0 1rem;
		}

		.volver-link {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 2.2rem;
			height: 2.2rem;
			border-radius: 4px;
			text-decoration: none;
		}

		#canvas-container {
			display: flex;
			gap: 1rem;
			flex-wrap: wrap;
			justify-content: center;
			padding: 0 1rem 1.5rem;
		}

		canvas {
			border: 1px solid var(--canvas-border);
			max-width: 100%;
			display: block;
			box-shadow: 3px 3px 16px var(--shadow-color);
		}

		fieldset {
			min-width: auto;
			overflow-x: scroll;
			max-width: 100%;
			border-color: var(--fieldset-border);
		}

		.image-container {
			margin-bottom: 2rem;
			max-width: 100%;
			text-align: center;
		}

		.image-container:has(canvas[width="500"]) {
			max-width: 500px;
		}

		.image-container:has(canvas[width="1024"]) {
			max-width: 1024px;
		}

		.image-container:has(canvas[width="2048"]) {
			max-width: 2048px;
		}

		.info {
			margin-top: 0.75rem;
			padding: 0.5rem 0.65rem;
			background-color: var(--code-bg);
			color: var(--code-text);
			border: 1px solid var(--border-color);
			border-radius: 4px;
			box-shadow: 1px 1px 12px var(--shadow-color);
			font-family: Arial, sans-serif;
			font-size: 0.85rem;
		}

		.info:empty {
			display: none;
		}

		.info div:first-child {
			font-family: monospace;
			color: var(--text-body);
		}

		.info div:not(:first-child) {
			text-align: left;
		}

		.info div:not(:last-child) {
			margin-bottom: .3rem;
		}

		.input-container {
			display: inline-block;
		}

		input,
		select {
			min-height: 2.2rem;
			padding: 5px;
			border: 1px solid var(--border-color);
			border-radius: 4px;
			background: var(--control-bg);
			color: var(--text-body);
			font-family: inherit;
			font-size: 14px;
		}

		input[readonly] {
			color: var(--grey-text);
		}

		.input-container input {
			width: 200px;
		}

		.ruta-foto {
			width: min(100%, 640px);
		}

		.clear-button {
			margin-right: 25%;
		}

		.clear-button,
		.send-button,
		.boton-ver {
			margin-top: 10px;
			padding: 0.55rem 0.9rem;
			background: var(--btn-bg);
			color: var(--btn-text);
			border: 1px solid var(--btn-border);
			border-radius: 4px;
			box-shadow: var(--btn-shadow);
			font-family: inherit;
			font-size: 14px;
			cursor: pointer;
			transition: background 0.2s, border-color 0.2s, box-shadow 0.2s;
		}

		.clear-button:hover,
		.send-button:hover,
		.boton-ver:hover {
			background: var(--btn-hover-bg);
			border-color: var(--btn-hover-border);
		}

		.clear-button:active,
		.send-button:active,
		.boton-ver:active {
			background: var(--btn-active-bg);
			border-color: var(--btn-active-border);
			box-shadow: var(--btn-active-shadow);
		}

		.response-message {
			margin-top: 10px;
			padding: 0.5rem;
			background: var(--surface-bg);
			border: 1px solid var(--border-color);
			border-radius: 4px;
			font-family: monospace;
			word-break: break-all;
		}

		.response-error {
			color: var(--negative-prompt);
			background-color: color-mix(in srgb, var(--negative-prompt) 12%, transparent);
			border-color: color-mix(in srgb, var(--negative-prompt) 55%, var(--border-color));
		}

		.comando {
			max-width: 100%;
			color: var(--grey-text);
		}

		.size-selector {
			margin-top: 10px;
			padding: 5px;
			font-size: 14px;
		}

		@media (max-width: 720px) {
			.buscador-caras {
				flex-wrap: wrap;
			}

			.ruta-foto {
				flex: 1 1 100%;
			}
		}
	</style>
</head>

<body>
	<h1 class="encabezado-caras">Etiquetar caras</h1>
	<?php
	$archivoval = '';
	if ($rutaReal):
		$archivoval = ' value="' . $rutaReal . '"';
	endif;
	$volver = '<a href="/" class="volver-link" title="Volver">⬅️</a> ';
	if (
		isset($_SERVER['HTTP_REFERER'])
		&& !empty($_SERVER['HTTP_REFERER'])
	):
		$volver = '<a href="' . $_SERVER['HTTP_REFERER'] . '" class="volver-link" title="Volver">⬅️</a> ';
	endif;
	?>
	<form method="GET" class="buscador-caras"><?php echo $volver; ?><input type="text" name="foto" <?php echo $archivoval; ?> class="ruta-foto"><button type="submit" class="boton-ver">Ver</button></form>
	<div id="canvas-container"></div>
	<script>
		// Lista de imágenes en la carpeta "caras"
		// imageFiles – URLs de visualización (para <img src>)
		// imageDataPaths – rutas reales del sistema (para POST, data-file, info)
		const imageFiles = [<?php echo $imgs; ?>];
		const imageDataPaths = [<?php echo $imgsDataPaths; ?>];
		const canvasContainer = document.getElementById('canvas-container');

		async function initializeGallery() {
			for (const [index, imageFile] of imageFiles.entries()) {
				await createImageCanvas(imageFile, index);
			}
		}

		async function createImageCanvas(imageFile, index) {
			// Ruta real del sistema para el archivo actual
			const imagePath = imageDataPaths[index] || imageFile;
			const imageContainer = document.createElement('div');
			imageContainer.className = 'image-container';

			const canvas = document.createElement('canvas');
			canvas.dataset.file = escape(imagePath);
			const ctx = canvas.getContext('2d');
			const infoDiv = document.createElement('div');
			infoDiv.className = 'info';
			infoDiv.id = `info-${index}`;

			// Crear controles
			const sizeSelector = createSizeSelector();
			const clearButton = createClearButton();
			const sendButton = createSendButton();

			// Cargar imagen
			const img = new Image();
			img.src = imageFile;

			await new Promise((resolve) => {
				img.onload = resolve;
			});

			// Configurar canvas inicial
			const rectangles = [];
			updateCanvasSize(canvas, ctx, img, rectangles, 500);
			if (esImagenSoportada(imagePath)) {
				await obtenerRectangulos(imagePath, rectangles, canvas, img);
			};

			// Configurar eventos
			setupCanvasEvents(canvas, ctx, img, rectangles, infoDiv, imageFile, imagePath);
			setupControlEvents(canvas, ctx, img, rectangles, infoDiv, sizeSelector, clearButton, sendButton, imageFile, imagePath);

			// Dibujar canvas
			redrawCanvas(canvas, ctx, img, rectangles);
			showInfo(infoDiv, img, imagePath, rectangles, ctx, canvas);

			// Agregar elementos al DOM
			imageContainer.appendChild(sizeSelector);
			imageContainer.appendChild(canvas);
			imageContainer.appendChild(infoDiv);
			imageContainer.appendChild(clearButton);
			imageContainer.appendChild(sendButton);
			canvasContainer.appendChild(imageContainer);
		}

		function createSizeSelector() {
			const selector = document.createElement('select');
			selector.className = 'size-selector';
			selector.innerHTML = `
				<option value="500">500px</option>
				<option value="1024">1024px</option>
				<option value="2048">2048px</option>
			`;
			return selector;
		}

		function createClearButton() {
			const button = document.createElement('button');
			button.className = 'clear-button';
			button.textContent = 'Limpiar';
			return button;
		}

		function createSendButton() {
			const button = document.createElement('button');
			button.className = 'send-button';
			button.textContent = 'Guardar';
			return button;
		}

		function cssVar(nombre, respaldo) {
			return getComputedStyle(document.documentElement).getPropertyValue(nombre).trim() || respaldo;
		}

		function setupCanvasEvents(canvas, ctx, img, rectangles, infoDiv, imageFile, imagePath) {
			let isDrawing = false;
			let startX, startY;

			canvas.addEventListener('mousedown', (event) => {
				const rect = canvas.getBoundingClientRect();
				startX = event.clientX - rect.left;
				startY = event.clientY - rect.top;
				isDrawing = true;
			});

			canvas.addEventListener('mousemove', (event) => {
				if (!isDrawing) return;

				const rect = canvas.getBoundingClientRect();
				const currentX = event.clientX - rect.left;
				const currentY = event.clientY - rect.top;

				redrawCanvas(canvas, ctx, img, rectangles);
				ctx.strokeStyle = cssVar('--selection-stroke', 'red');
				ctx.lineWidth = 2;
				ctx.strokeRect(startX, startY, currentX - startX, currentY - startY);
			});

			canvas.addEventListener('mouseup', (event) => {
				if (!isDrawing) return;

				const rect = canvas.getBoundingClientRect();
				const endX = event.clientX - rect.left;
				const endY = event.clientY - rect.top;

				rectangles.push({
					x1: startX,
					y1: startY,
					x2: endX,
					y2: endY,
					text: ''
				});

				isDrawing = false;
				redrawCanvas(canvas, ctx, img, rectangles);
				showInfo(infoDiv, img, imagePath, rectangles, ctx, canvas);
			});
		}

		function setupControlEvents(canvas, ctx, img, rectangles, infoDiv, sizeSelector, clearButton, sendButton, imageFile, imagePath) {
			sizeSelector.addEventListener('change', () => {
				const selectedSize = parseInt(sizeSelector.value);
				updateCanvasSize(canvas, ctx, img, rectangles, selectedSize);
				showInfo(infoDiv, img, imagePath, rectangles, ctx, canvas);
			});

			clearButton.addEventListener('click', () => {
				rectangles.length = 0;
				redrawCanvas(canvas, ctx, img, rectangles);
				showInfo(infoDiv, img, imagePath, rectangles, ctx, canvas);
			});

			sendButton.addEventListener('click', () => {
				sendData(imageFile, imagePath, img, rectangles, infoDiv);
			});
		}

		async function obtenerRectangulos(imageFile, rectangles, canvas, img) {
			// Usar la ruta real (imagePath) para la petición AJAX de metadatos
			const metaPath = imageDataPaths[imageFiles.indexOf(imageFile)] || imageFile;
			try {
				const response = await fetch(`caras.php?ajax_meta=${encodeURIComponent(metaPath)}`);
				if (!response.ok) return;
				const metadata = await response.json();

				let orientation = metadata?.Orientation || 1;
				if (typeof orientation === 'string') {
					const o = orientation.toLowerCase();
					if (o.includes('mirror horizontal') || o.includes('flip horizontal') || o.includes('left right')) orientation = 2;
					else if (o.includes('180') || o.includes('bottom right')) orientation = 3;
					else if (o.includes('mirror vertical') || o.includes('flip vertical') || o.includes('bottom left')) orientation = 4;
					else if (o.includes('transposed') || o.includes('left top')) orientation = 5;
					else if (o.includes('90 cw') || o.includes('right top')) orientation = 6;
					else if (o.includes('transverse') || o.includes('right bottom')) orientation = 7;
					else if (o.includes('270 cw') || o.includes('90 ccw') || o.includes('left bottom')) orientation = 8;
					else orientation = parseInt(o) || 1;
				}
				canvas.dataset.orientation = orientation;
				const appliedWidth = Number(metadata?.AppliedToDimensions?.w || 0);
				const appliedHeight = Number(metadata?.AppliedToDimensions?.h || 0);
				const coordinatesLookVisual =
					orientation >= 5 &&
					Math.abs(img.naturalWidth - img.naturalHeight) >= 1 &&
					appliedWidth > 0 &&
					appliedHeight > 0 &&
					Math.abs(appliedWidth - img.naturalWidth) < 1 &&
					Math.abs(appliedHeight - img.naturalHeight) < 1;
				const shouldTransformRegions = orientation !== 1 && !coordinatesLookVisual;

				const regionList = metadata?.RegionList || [];

				if (regionList && regionList.length > 0) {
					regionList.forEach(region => {
						if (region.Area && region.Name) {
							const cx = region.Area.x;
							const cy = region.Area.y;
							const w = region.Area.w;
							const h = region.Area.h;

							let raw_x1 = cx - w / 2;
							let raw_y1 = cy - h / 2;
							let raw_x2 = cx + w / 2;
							let raw_y2 = cy + h / 2;

							let vx1 = raw_x1;
							let vy1 = raw_y1;
							let vx2 = raw_x2;
							let vy2 = raw_y2;
							if (shouldTransformRegions) {
								[vx1, vy1] = transformRawToVisual(raw_x1, raw_y1, orientation);
								[vx2, vy2] = transformRawToVisual(raw_x2, raw_y2, orientation);
							}

							const final_x1 = Math.min(vx1, vx2) * canvas.width;
							const final_y1 = Math.min(vy1, vy2) * canvas.height;
							const final_x2 = Math.max(vx1, vx2) * canvas.width;
							const final_y2 = Math.max(vy1, vy2) * canvas.height;

							rectangles.push({
								x1: final_x1,
								y1: final_y1,
								x2: final_x2,
								y2: final_y2,
								text: region.Name
							});
						}
					});
				}
			} catch (error) {
				console.error(`Error procesando ${imageFile}:`, error);
			}
		}

		function transformRawToVisual(x, y, orientation) {
			let vx = x, vy = y;
			switch (orientation) {
				case 2: vx = 1 - x; break;
				case 3: vx = 1 - x; vy = 1 - y; break;
				case 4: vy = 1 - y; break;
				case 5: vx = y; vy = x; break;
				case 6: vx = 1 - y; vy = x; break;
				case 7: vx = 1 - y; vy = 1 - x; break;
				case 8: vx = y; vy = 1 - x; break;
			}
			return [vx, vy];
		}

		function transformVisualToRaw(x, y, orientation) {
			let rx = x, ry = y;
			switch (orientation) {
				case 2: rx = 1 - x; break;
				case 3: rx = 1 - x; ry = 1 - y; break;
				case 4: ry = 1 - y; break;
				case 5: rx = y; ry = x; break;
				case 6: rx = y; ry = 1 - x; break;
				case 7: rx = 1 - y; ry = 1 - x; break;
				case 8: rx = 1 - y; ry = x; break;
			}
			return [rx, ry];
		}


		// Función para actualizar el tamaño del canvas
		function updateCanvasSize(canvas, ctx, img, rectangles, newWidth) {
			// Guardar el factor de escala anterior
			const oldWidth = canvas.width;
			const scaleFactor = newWidth / oldWidth;

			// Calcular el nuevo tamaño manteniendo la relación de aspecto
			const aspectRatio = img.width / img.height;
			canvas.width = newWidth;
			canvas.height = newWidth / aspectRatio;

			// Escalar las coordenadas de todos los rectángulos
			rectangles.forEach(rect => {
				rect.x1 *= scaleFactor;
				rect.y1 *= scaleFactor;
				rect.x2 *= scaleFactor;
				rect.y2 *= scaleFactor;
			});

			// Redibujar la imagen y los rectángulos
			redrawCanvas(canvas, ctx, img, rectangles);
		}

		// Función para redibujar el canvas
		function redrawCanvas(canvas, ctx, img, rectangles) {
			// Limpiar el canvas
			ctx.clearRect(0, 0, canvas.width, canvas.height);

			// Dibujar la imagen
			ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

			// Dibujar todos los rectángulos y su texto
			rectangles.forEach(rect => {
				const width = rect.x2 - rect.x1;
				const height = rect.y2 - rect.y1;
				const posx = Math.min(rect.x1, rect.x2);
				const posy = Math.min(rect.y1, rect.y2);


				// Dibujar el rectángulo
				ctx.strokeStyle = cssVar('--region-stroke', 'green');
				ctx.lineWidth = 2;
				ctx.strokeRect(rect.x1, rect.y1, width, height);

				// Dibujar el texto dentro del rectángulo
				if (rect.text) {
					ctx.fillStyle = cssVar('--region-label-fill', 'white');
					ctx.font = '14px Arial';
					ctx.strokeStyle = cssVar('--region-label-stroke', 'black');
					ctx.lineWidth = 4;
					ctx.strokeText(rect.text, posx + 3, posy + 16);
					ctx.fillText(rect.text, posx + 3, posy + 16);
				}
			});
		}
		// Función para obtener el nombre de archivo de una ruta
		function basename(str, sep) {
			return str.substr(str.lastIndexOf(sep) + 1);
		}
		function esImagenSoportada(imageFile) {
			const supported = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'avif', 'tif', 'tiff'];
			const ext = imageFile.split('.').pop().toLowerCase();
			return supported.includes(ext);
		}
		// Función para mostrar la información
		async function showInfo(infoDiv, img, imageFile, rectangles, ctx, canvas) {
			// Escalar las coordenadas al tamaño original de la imagen
			const scaleX = img.width / canvas.width;
			const scaleY = img.height / canvas.height;

			//Leer metadatos
			if (esImagenSoportada(imageFile)) {
				try {
					// Alternativa segura vía PHP Backend (ExifTool)
					const response = await fetch(`caras.php?ajax_meta=${encodeURIComponent(imageFile)}`);
					if (response.ok) {
						const meta = await response.json();
						let rawSubject = meta.Subject;
						subject = Array.isArray(rawSubject) ? rawSubject.join(', ') : rawSubject || '';
						let rawCopyright = meta.Copyright;
						copyright = Array.isArray(rawCopyright) ? rawCopyright.join(', ') : rawCopyright || '';
					} else {
						subject = '';
						copyright = '';
					}
				} catch (error) {
					console.error('Error al leer metadatos del backend', error);
					subject = '';
					copyright = '';
				}
			} else {
				subject = '';
				copyright = '';
			}
			// Limpiar el contenido anterior
			imageName = basename(imageFile, '/');
			idih = `<div>${imageName}<br>${img.width}x${img.height}`;
			if (subject != '') {
				idih += `<br><input type="text" readonly value="${subject}" placeholder="Subject">`;
			}
			if (copyright != '') {
				idih += `<br><input type="text" readonly value="${copyright}" placeholder="Copyright">`;
			}
			idih += `</div>`;
			infoDiv.innerHTML = idih;

			// Mostrar información de cada rectángulo
			rectangles.forEach((rect, index) => {
				const originalX1 = Math.round(rect.x1 * scaleX);
				const originalY1 = Math.round(rect.y1 * scaleY);
				const originalX2 = Math.round(rect.x2 * scaleX);
				const originalY2 = Math.round(rect.y2 * scaleY);

				const width = Math.abs(originalX2 - originalX1);
				const height = Math.abs(originalY2 - originalY1);
				const centerX = Math.round((originalX1 + originalX2) / 2);
				const centerY = Math.round((originalY1 + originalY2) / 2);

				// Obtener los píxeles dentro del rectángulo
				const imageData = ctx.getImageData(rect.x1, rect.y1, width, height);
				// const pixels = imageData.data;

				// Crear un contenedor para la información del rectángulo
				const rectInfo = document.createElement('div');
				rectInfo.innerHTML = `Región ${index + 1}: `;
				/*
				- Puntos de clic: (${originalX1}, ${originalY1}) a (${originalX2}, ${originalY2})<br>
				- Ancho: ${width}, Alto: ${height}<br>
				- Centro: (${centerX}, ${centerY})<br>
				- Datos de píxeles (primer píxel): R=${pixels[0]}, G=${pixels[1]}, B=${pixels[2]}, A=${pixels[3]}<br>
			*/
				// Crear un campo de entrada de texto para el nombre del rectángulo
				const inputContainer = document.createElement('div');
				inputContainer.className = 'input-container';
				const input = document.createElement('input');
				input.type = 'text';
				input.placeholder = 'Ingresa un nombre';
				input.value = rect.text;

				// Actualizar el texto del rectángulo cuando se ingrese un valor
				input.addEventListener('input', () => {
					rect.text = input.value;
					redrawCanvas(canvas, ctx, img, rectangles);
				});

				inputContainer.appendChild(input);
				rectInfo.appendChild(inputContainer);
				infoDiv.appendChild(rectInfo);
				input.focus({ focusVisible: true });
			});
		}
		function sendData(imageFile, imagePath, img, rectangles, infoDiv) {
			const canvas = document.querySelector(`canvas[data-file="${escape(imagePath)}"]`);
			const orientation = parseInt(canvas.dataset.orientation || 1);

			let rawWidth = img.naturalWidth;
			let rawHeight = img.naturalHeight;
			if (orientation >= 5) {
				rawWidth = img.naturalHeight;
				rawHeight = img.naturalWidth;
			}

			const data = {
				archivo: imagePath,
				ancho: rawWidth,
				alto: rawHeight,
				areas: rectangles.map(rect => {
					const vx1 = Math.min(rect.x1, rect.x2) / canvas.width;
					const vy1 = Math.min(rect.y1, rect.y2) / canvas.height;
					const vx2 = Math.max(rect.x1, rect.x2) / canvas.width;
					const vy2 = Math.max(rect.y1, rect.y2) / canvas.height;

					let [rx1, ry1] = transformVisualToRaw(vx1, vy1, orientation);
					let [rx2, ry2] = transformVisualToRaw(vx2, vy2, orientation);

					return {
						x1: Math.round(Math.min(rx1, rx2) * rawWidth),
						y1: Math.round(Math.min(ry1, ry2) * rawHeight),
						x2: Math.round(Math.max(rx1, rx2) * rawWidth),
						y2: Math.round(Math.max(ry1, ry2) * rawHeight),
						nombre: rect.text || ''
					};
				})
			};

			// Mostrar loading
			//const originalText = infoDiv.querySelector('.send-button').textContent;
			//infoDiv.querySelector('.send-button').textContent = 'Guardando...';

			// Enviar datos al servidor
			fetch('<?php echo ESTE ?>', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify(data)
			})
				.then(response => response.text())
				.then(responseText => {
					// Mostrar respuesta
					const responseDiv = document.createElement('div');
					responseDiv.className = 'response-message';
					responseDiv.innerHTML = responseText;
					infoDiv.appendChild(responseDiv);

					// Restaurar texto del botón
					//infoDiv.querySelector('.send-button').textContent = originalText;
				})
				.catch(error => {
					console.error('Error:', error);
					const errorDiv = document.createElement('div');
					errorDiv.className = 'response-message response-error';
					errorDiv.textContent = `Error al guardar: ${error.message}`;
					infoDiv.appendChild(errorDiv);

					// Restaurar texto del botón
					//infoDiv.querySelector('.send-button').textContent = originalText;
				});
		}

		// Iniciar la galería
		initializeGallery();
	</script>
</body>

</html>
