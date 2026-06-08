<?php
require_once __DIR__ . '/soporte.php';
require_once __DIR__ . '/configuracion.php';

define("BREW_BIN", '/opt/homebrew/bin/');
define("CARPETA", definirRaíz());
define("UBICACIONES", obtenerUbicaciones());
define("FFPROBE_DISPONIBLE", detectarFfprobe());

if (isset($_GET['media'])):
	if ($_GET['media'] == 'fotos'):
		define("FOTOS", TRUE);
	else:
		define("FOTOS", FALSE);
	endif;
	if ($_GET['media'] == 'videos'):
		define("VIDEOS", TRUE);
	else:
		define("VIDEOS", FALSE);
	endif;
else:
	define("FOTOS", FALSE);
	define("VIDEOS", FALSE);
endif;

// Globales para parámetros de SD/InvokeAI
if (!isset($nombreModelo)):
	$nombreModelo = null;
endif;

/**
 * Determina y devuelve la ruta raíz de la aplicación.
 *
 * @return string Ruta absoluta de la raíz o la subruta válida dentro de la raíz.
 *
 * @note Usa el superglobal $_GET['ruta'] para determinar la subruta.
 */
function definirRaíz(): string
{
	return resolverRutaProyecto($_GET['ruta'] ?? '', 'dir', true) ?? proyectoRaiz();
}

/**
 * Detecta si ffprobe está instalado y disponible en el sistema.
 *
 * Ejecuta el comando ffprobe -version via shell para verificar su existencia. Si el comando devuelve una salida que contiene "ffprobe version", se considera que ffprobe está instalado y accesible a través de la ruta definida en BREW_BIN.
 *
 * @return bool True si ffprobe está instalado y disponible, False en caso contrario.
 */
function detectarFfprobe(): bool
{
	$comando = BREW_BIN . "ffprobe -version 2>&1";
	$resultado = shell_exec($comando);
	if (
		$resultado
		&& strpos($resultado, 'ffprobe version') !== false
	):
		return TRUE;
	else:
		return FALSE;
	endif;
}

/**
 * Obtiene las ubicaciones desde la base de datos SQLite.
 *
 * Lee los datos de ubicaciones desde un archivo SQLite combinando información
 * de las tablas 'ubicaciones' y 'cache_geo' mediante la clave de caché.
 * Cada ubicación se indexa por el hash MD5 de su nombre.
 *
 * @return array Un array asociativo donde las claves son hashes MD5 de las ubicaciones
 *               y los valores son arrays con las siguientes claves:
 *               - Location (string): Nombre de la ubicación
 *               - GPSPosition (string): Coordenadas GPS
 *               - Country (string): País
 *               - CountryCode (string): Código del país
 *               - State (string): Estado/Provincia
 *               - City (string): Ciudad
 *               Retorna un array vacío si ocurre un error o no se encuentran datos.
 *
 * @throws E_USER_WARNING Si el archivo de base de datos no existe.
 * @throws E_USER_WARNING Si hay error al conectar a la base de datos.
 * @throws E_USER_WARNING Si hay error al ejecutar la consulta SQL.
 */
function obtenerUbicaciones(): array
{
	$dbFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'datos' . DIRECTORY_SEPARATOR . 'ubicaciones.sqlite';
	if (!file_exists($dbFile)):
		trigger_error("Archivo de base de datos no encontrado: $dbFile", E_USER_WARNING);
		return [];
	endif;

	try {
		$pdo = new PDO("sqlite:$dbFile");
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	} catch (\PDOException $e) {
		trigger_error("Error al conectar a la base de datos: [{$e->getCode()}] {$e->getMessage()}", E_USER_WARNING);
		return [];
	}

	$sql = "
	SELECT u.Location, u.GPSPosition,
		c.Country, c.CountryCode, c.State, c.City
	FROM ubicaciones u
	JOIN cache_geo c ON u.cache_key = c.cache_key
	";

	try {
		$filas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
	} catch (\PDOException $e) {
		trigger_error("Error al obtener ubicaciones: [{$e->getCode()}] {$e->getMessage()}", E_USER_WARNING);
		return [];
	} finally {
		$pdo = null;
	}

	$ubicaciones = [];
	foreach ($filas as $fila):
		if (
			array_key_exists('Location', $fila)
			&& !empty($fila['Location'])
		):
			$locationHash = md5($fila['Location']);
		else:
			continue;
		endif;
		$campos = ['Location', 'GPSPosition', 'Country', 'CountryCode', 'State', 'City'];
		foreach ($campos as $campo):
			if (array_key_exists($campo, $fila)):
				$ubicaciones[$locationHash][$campo] = $fila[$campo];
			else:
				$ubicaciones[$locationHash][$campo] = null;
			endif;
		endforeach;
	endforeach;

	return $ubicaciones;
}

/**
 * Inicializa el esquema SQLite de identidades y usuarios conocidos.
 *
 * La tabla `identidades` conserva la lista de nombres sugeridos como JSON y
 * `nombres_usuario` permite que varios usuarios/alias apunten a una identidad.
 *
 * @param PDO $pdo Conexión SQLite abierta.
 * @return void
 */
function inicializarBaseNombres(PDO $pdo): void
{
	$pdo->exec("PRAGMA foreign_keys = ON");
	$pdo->exec("
		CREATE TABLE IF NOT EXISTS identidades (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			nombres_json TEXT NOT NULL UNIQUE,
			created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
		)
	");
	$pdo->exec("
		CREATE TABLE IF NOT EXISTS nombres_usuario (
			usuario TEXT PRIMARY KEY,
			identidad_id INTEGER NOT NULL,
			created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
			FOREIGN KEY(identidad_id) REFERENCES identidades(id) ON DELETE CASCADE
		)
	");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_nombres_usuario_identidad ON nombres_usuario(identidad_id)");
	$pdo->exec("CREATE INDEX IF NOT EXISTS idx_nombres_usuario_lower ON nombres_usuario(lower(usuario))");
}

/**
 * Abre la base de nombres/alias.
 *
 * @return PDO|null Conexión lista para usarse, o null si no se puede abrir.
 */
function conectarBaseNombres(): ?PDO
{
	static $pdo = null;
	static $fallo = false;

	if ($pdo instanceof PDO):
		return $pdo;
	endif;
	if ($fallo):
		return null;
	endif;

	$datosDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'datos';
	if (!is_dir($datosDir) && !mkdir($datosDir, 0775, true)):
		trigger_error("No se pudo crear el directorio de datos: $datosDir", E_USER_WARNING);
		$fallo = true;
		return null;
	endif;

	$dbFile = $datosDir . DIRECTORY_SEPARATOR . 'nombres.sqlite';
	try {
		$pdo = new PDO("sqlite:$dbFile");
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		inicializarBaseNombres($pdo);
	} catch (\PDOException $e) {
		trigger_error("Error al conectar la base de nombres: [{$e->getCode()}] {$e->getMessage()}", E_USER_WARNING);
		$fallo = true;
		return null;
	}

	return $pdo;
}

/**
 * Convierte el JSON de nombres sugeridos en un arreglo limpio.
 *
 * @param string|null $json Lista JSON guardada en SQLite.
 * @return array<int, string>
 */
function decodificarNombresSugeridos(?string $json): array
{
	if (empty($json)):
		return [];
	endif;

	$nombres = json_decode($json, true);
	if (!is_array($nombres)):
		return [];
	endif;

	$nombres = array_map(static fn($valor) => trim((string) $valor), $nombres);
	$nombres = array_filter($nombres, static fn($valor) => $valor !== '');
	return array_values(array_unique($nombres));
}

/**
 * Busca los nombres reales/transliteraciones asociados a un usuario o alias.
 *
 * @param string $usuario Nombre de usuario inferido desde el archivo.
 * @return array<int, string> Nombres sugeridos.
 */
function obtenerNombresPorUsuario(string $usuario): array
{
	$usuario = trim($usuario);
	if ($usuario === ''):
		return [];
	endif;

	static $cache = [];
	$cacheKey = strtolower($usuario);
	if (array_key_exists($cacheKey, $cache)):
		return $cache[$cacheKey];
	endif;

	$pdo = conectarBaseNombres();
	if (!$pdo):
		$cache[$cacheKey] = [];
		return [];
	endif;

	try {
		$stmt = $pdo->prepare("
			SELECT i.nombres_json
			FROM nombres_usuario u
			JOIN identidades i ON i.id = u.identidad_id
			WHERE lower(u.usuario) = lower(:usuario)
			ORDER BY CASE WHEN u.usuario = :usuario THEN 0 ELSE 1 END
			LIMIT 1
		");
		$stmt->execute([':usuario' => $usuario]);
		$nombres = decodificarNombresSugeridos($stmt->fetchColumn() ?: null);
	} catch (\PDOException $e) {
		trigger_error("Error al obtener nombres para $usuario: [{$e->getCode()}] {$e->getMessage()}", E_USER_WARNING);
		$nombres = [];
	}

	$cache[$cacheKey] = $nombres;
	return $nombres;
}

/**
 * Obtiene y devuelve valores específicos de los metadatos de un archivo usando Exiftool.
 *
 * @param string $ruta Ruta absoluta o relativa al archivo multimedia.
 * @param array $etiquetas Array opcional de etiquetas Exiftool específicas a retornar.
 * @return array Array con el resultado de la extracción y datos del comando.
 *
 * @example
 * $meta = obtenerMetadatos('img.jpg', ['Model', 'Make']);
 */
function obtenerMetadatos(string $ruta, array $etiquetas = []): array
{
	$argumentos = ['exiftool', '-n', '-s'];
	if (!empty($etiquetas)):
		foreach ($etiquetas as $etiqueta):
			$etiqueta = trim((string) $etiqueta, '- ');
			if (preg_match('/^[A-Za-z0-9:_#-]+$/', $etiqueta)):
				$argumentos[] = '-' . $etiqueta;
			endif;
		endforeach;
	endif;
	$argumentos[] = $ruta;
	$comando = comandoBrewSeguro($argumentos);

	$resultado = exec($comando, $salida, $código);
	if (
		$resultado !== false
		&& ($código === 0 || $código === 1)
	): // exiftool devuelve 1 si hay advertencias pero aún así obtiene datos
		$resultado = [];
		foreach ($salida as $línea):
			$partes = explode(':', $línea, 2);
			if (count($partes) == 2):
				$clave = trim($partes[0]);
				$valor = trim($partes[1]);
				$resultado[$clave] = $valor;
			endif;
		endforeach;
		return ['resultado' => $resultado, 'salida' => $salida, 'código' => $código, 'comando' => $comando];
	else:
		return ['resultado' => false, 'salida' => $salida, 'código' => $código, 'comando' => $comando];
	endif;
}

function normalizarOrientacionExif(mixed $orientacion): int
{
	if (is_string($orientacion)):
		$orientacionTexto = mb_strtolower($orientacion, 'UTF-8');
		if (
			str_contains($orientacionTexto, 'transpose')
			|| str_contains($orientacionTexto, 'transposed')
			|| str_contains($orientacionTexto, 'left top')
			|| (str_contains($orientacionTexto, 'mirror horizontal') && str_contains($orientacionTexto, '270'))
		):
			return 5;
		elseif (
			str_contains($orientacionTexto, 'transverse')
			|| str_contains($orientacionTexto, 'right bottom')
			|| (str_contains($orientacionTexto, 'mirror horizontal') && str_contains($orientacionTexto, '90'))
		):
			return 7;
		elseif (str_contains($orientacionTexto, 'mirror horizontal') || str_contains($orientacionTexto, 'flip horizontal') || str_contains($orientacionTexto, 'left right')):
			return 2;
		elseif (str_contains($orientacionTexto, '180') || str_contains($orientacionTexto, 'bottom right')):
			return 3;
		elseif (str_contains($orientacionTexto, 'mirror vertical') || str_contains($orientacionTexto, 'flip vertical') || str_contains($orientacionTexto, 'bottom left')):
			return 4;
		elseif (str_contains($orientacionTexto, '90 cw') || str_contains($orientacionTexto, 'right top')):
			return 6;
		elseif (str_contains($orientacionTexto, '270') || str_contains($orientacionTexto, '90 ccw') || str_contains($orientacionTexto, 'left bottom')):
			return 8;
		endif;
	endif;

	$orientacion = (int) $orientacion;
	if ($orientacion === 90):
		return 6;
	elseif ($orientacion === 180):
		return 3;
	elseif ($orientacion === 270):
		return 8;
	endif;

	return $orientacion;
}

function valorRotacionVideoDesdeOrientacion(mixed $orientacion): int
{
	$texto = trim((string) $orientacion);
	if ($texto === ''):
		return 0;
	endif;

	if (preg_match('/-?\d+/', $texto, $coincidencias)):
		$grados = ((int) $coincidencias[0]) % 360;
		if ($grados < 0):
			$grados += 360;
		endif;
		if (in_array($grados, [90, 180, 270], true)):
			return $grados;
		elseif ($grados === 0):
			return 0;
		endif;
	endif;

	return match (normalizarOrientacionExif($orientacion)) {
		3 => 180,
		5, 8 => 270,
		6, 7 => 90,
		default => 0,
	};
}

function valorOrientacionFormulario(mixed $orientacion, string $tipo): int
{
	return $tipo === 'vid'
		? valorRotacionVideoDesdeOrientacion($orientacion)
		: normalizarOrientacionExif($orientacion);
}

function opcionesOrientacionFormulario(string $ruta, string $tipo, mixed $orientacion): array
{
	$extension = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
	if ($tipo === 'vid' || in_array($extension, ['mp4', 'mov'], true)):
		return [
			0 => 'Sin rotación',
			90 => 'Rotar 90° a la derecha',
			180 => 'Rotar 180°',
			270 => 'Rotar 90° a la izquierda',
		];
	endif;

	$opcionesExif = [
		0 => 'Sin orientación',
		1 => 'Horizontal (normal)',
		2 => 'Reflejo horizontal',
		3 => 'Rotar 180°',
		4 => 'Reflejo vertical',
		5 => 'Transponer',
		6 => 'Rotar 90° a la derecha',
		7 => 'Transversal',
		8 => 'Rotar 90° a la izquierda',
	];

	if (in_array($extension, ['jpg', 'jpeg', 'heic', 'webp'], true)):
		return $opcionesExif;
	endif;

	$seleccionada = valorOrientacionFormulario($orientacion, $tipo);
	$opciones = [0 => 'Sin orientación'];
	if ($seleccionada > 0):
		$opciones[$seleccionada] = $opcionesExif[$seleccionada] ?? ('Orientación actual (' . $seleccionada . ')');
	endif;
	return $opciones;
}

/**
 * Extrae y formatea parámetros de Stable Diffusion desde un texto.
 *
 * Procesa un texto que contiene información de generación de imágenes
 * de Stable Diffusion, extrayendo el prompt, prompt negativo, parámetros
 * técnicos y LoRAs, devolviéndolos como HTML formateado.
 *
 * @param string $texto El texto completo con los parámetros de SD a procesar.
 *                       Esperado en formato: "prompt\nNegative prompt: ...\nSteps: ..."
 *
 * @return string HTML formateado en un elemento <details> con los parámetros
 *                 organizados en secciones (Prompt, Prompt Negativo, Otros).
 *                 Incluye sanitización de todas las salidas HTML.
 *
 * @example
 * $texto = "A beautiful landscape\nNegative prompt: ugly\nSteps: 20, Sampler: euler";
 * echo parámetrosSD($texto);
 *
 * @note
 * - Divide parámetros respetando comillas (split seguro por comas)
 * - Detecta y procesa LoRAs con patrón <lora:nombre:peso>
 * - Maneja el parámetro "img2img hires fix" como JSON pseudo-formateado
 * - Parámetros se clasifican como "importantes" u "otros" según su tipo
 * - Todos los valores son escapados con htmlspecialchars() para prevenir XSS
 * - El modelo se muestra primero en la lista, con hash como tooltip si existe
 */
function parámetrosSD(string $texto): string
{
	global $nombreModelo;
	$texto = trim($texto);

	$prompt = '';
	$negativo = '';
	$parámetros = '';

	// 1️⃣ Prompt principal
	if (preg_match('/^(.*?)(?:Negative prompt:|Steps:)/s', $texto, $m)):
		$prompt = trim($m[1]);
	else:
		$prompt = $texto;
	endif;

	// 2️⃣ Negative prompt
	if (preg_match('/Negative prompt:(.*?)(?:Steps:|$)/s', $texto, $m)):
		$negativo = trim($m[1]);
	endif;

	// 3️⃣ Parámetros técnicos
	if (preg_match('/Steps:(.*)$/s', $texto, $m)):
		$parámetros = 'Steps:' . trim($m[1]);
	endif;

	$importantes = [];
	$otros = [];
	$nombreModelo = null;
	$hashModelo = null;

	if ($parámetros !== ''):

		// Divide por comas que NO estén dentro de comillas
		$elementos = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $parámetros);

		foreach ($elementos as $elemento):

			if (!str_contains($elemento, ':'))
				continue;

			[$clave, $valor] = array_map('trim', explode(':', $elemento, 2));

			$valor = trim($valor);

			switch (strtolower($clave)):

				case 'model':
					$nombreModelo = htmlspecialchars($valor);
					break;

				case 'model hash':
					$hashModelo = htmlspecialchars($valor);
					break;

				case 'img2img hires fix':

					// Quitar comillas externas
					$limpio = trim($valor, '"');

					// Convertir pseudo-json a json válido
					$json = str_replace("'", '"', $limpio);
					$datos = json_decode($json, true);

					if (is_array($datos)):

						$sub = '<li><b>Hires Fix:</b><ul>';

						foreach ($datos as $k => $v):
							$sub .= '<li>' .
								htmlspecialchars($k) .
								': ' .
								htmlspecialchars((string) $v) .
								'</li>';
						endforeach;

						$sub .= '</ul></li>';
						$importantes[] = $sub;

					else:
						$importantes[] =
							'<li><b>Hires Fix:</b> ' .
							htmlspecialchars($limpio) .
							'</li>';
					endif;

					break;

				case 'size':
					$importantes[] = '<li><b>Size:</b> 📐 ' . htmlspecialchars($valor) . '</li>';
					break;

				case 'seed':
					$importantes[] = '<li><b>Seed:</b> 🎲 ' . htmlspecialchars($valor) . '</li>';
					break;

				case 'steps':
				case 'sampler':
				case 'cfg scale':
				case 'denoising strength':
				case 'clip skip':
				case 'schedule type':
				case 'hires upscale':
				case 'hires upscaler':
				case 'version':
					$importantes[] =
						'<li><b>' . htmlspecialchars($clave) . ':</b> ' .
						htmlspecialchars($valor) .
						'</li>';
					break;

				default:
					$otros[] =
						'<li><b>' . htmlspecialchars($clave) . ':</b> ' .
						htmlspecialchars($valor) .
						'</li>';

			endswitch;
		endforeach;
	endif;

	// 4️⃣ Detectar LoRAs en el prompt
	preg_match_all('/<lora:([^:>]+):?([^>]*)>/', $prompt, $lorasEncontrados, PREG_SET_ORDER);
	$listaLoras = [];

	foreach ($lorasEncontrados as $lora):
		$nombre = htmlspecialchars($lora[1]);
		$peso = isset($lora[2]) && $lora[2] !== '' ? htmlspecialchars($lora[2]) : '1.0';
		$listaLoras[] = "<li>🧠 <b>$nombre</b> (weight $peso)</li>";
	endforeach;

	// 5️⃣ Construcción HTML
	$html = '';

	// 🔹 Prompt
	if ($prompt !== ''):
		$html .= '<fieldset>';
		$html .= '<legend>Prompt</legend>';
		$html .= '<div class="prompt">' . htmlspecialchars($prompt) . '</div>';
		$html .= '</fieldset>';
	endif;

	// 🔹 Prompt negativo
	if ($negativo !== ''):
		$html .= '<fieldset>';
		$html .= '<legend>Prompt Negativo</legend>';
		$html .= '<div class="negative-prompt">' . htmlspecialchars($negativo) . '</div>';
		$html .= '</fieldset>';
	endif;

	// 🔹 Otros parámetros
	if ($nombreModelo || !empty($importantes) || !empty($otros) || !empty($listaLoras)):

		$html .= '<fieldset>';
		$html .= '<legend>Otros</legend>';
		$html .= '<ul>';

		// 🔥 Modelo primero
		if ($nombreModelo):
			$tooltip = $hashModelo ? ' title="Hash: ' . $hashModelo . '"' : '';
			$html .=
				'<li class="model"' . $tooltip . '>' .
				'🔥 <b>Modelo:</b> ' . $nombreModelo .
				'</li>';
		endif;

		// LoRAs
		foreach ($listaLoras as $l):
			$html .= $l;
		endforeach;

		// Importantes
		foreach ($importantes as $imp):
			$html .= $imp;
		endforeach;

		// Resto
		foreach ($otros as $o):
			$html .= $o;
		endforeach;

		$html .= '</ul>';
		$html .= '</fieldset>';
	endif;

	return '<details open class="psd metadata-ia"><summary>Parámetros SD</summary>' . $html . '</details>';
}

/**
 * Analiza metadatos de ComfyUI (Prompt y Workflow) y genera HTML similar a parámetrosSD.
 *
 * @param string $promptJson   JSON de la clave "Prompt" (estructura de nodos de ComfyUI).
 * @param string|null $workflowJson JSON de la clave "Workflow" (información extendida, opcional).
 * @return string HTML con los parámetros extraídos, o cadena vacía si no hay datos.
 */
function parámetrosCUI(string $promptJson, ?string $workflowJson = null): string
{
	$promptData = json_decode($promptJson, true);
	$workflowData = $workflowJson ? json_decode($workflowJson, true) : null;

	if (!is_array($promptData)):
		return '';
	endif;

	$nodes = $promptData;

	// Variables a extraer
	$positivePrompt = '';
	$negativePrompt = '';
	$model = '';
	$vae = '';
	$seed = '';
	$steps = '';
	$cfg = '';
	$sampler = '';
	$scheduler = '';
	$width = '';
	$height = '';
	$loras = [];
	$otherParams = [];

	// 1️⃣ Encontrar el nodo KSampler (contiene referencias a positive/negative)
	$ksamplerNode = null;
	foreach ($nodes as $nodeId => $node):
		if (isset($node['class_type']) && $node['class_type'] === 'KSampler'):
			$ksamplerNode = $node;
			break;
		endif;
	endforeach;

	// 2️⃣ Si hay KSampler, extraer referencias y parámetros directos
	if ($ksamplerNode && isset($ksamplerNode['inputs'])):
		$inputs = $ksamplerNode['inputs'];

		// Referencia al prompt positivo
		if (isset($inputs['positive']) && is_array($inputs['positive']) && count($inputs['positive']) >= 1):
			$positiveNodeId = $inputs['positive'][0];
			if (isset($nodes[$positiveNodeId]) && isset($nodes[$positiveNodeId]['inputs']['text'])):
				$positivePrompt = $nodes[$positiveNodeId]['inputs']['text'];
			endif;
		endif;

		// Referencia al prompt negativo
		if (isset($inputs['negative']) && is_array($inputs['negative']) && count($inputs['negative']) >= 1):
			$negativeNodeId = $inputs['negative'][0];
			if (isset($nodes[$negativeNodeId]) && isset($nodes[$negativeNodeId]['inputs']['text'])):
				$negativePrompt = $nodes[$negativeNodeId]['inputs']['text'];
			endif;
		endif;

		// Parámetros del sampler
		$seed = $inputs['seed'] ?? '';
		$steps = $inputs['steps'] ?? '';
		$cfg = $inputs['cfg'] ?? '';
		$sampler = $inputs['sampler_name'] ?? '';
		$scheduler = $inputs['scheduler'] ?? '';
	endif;

	// 3️⃣ Recorrer todos los nodos para extraer otros datos (modelo, VAE, tamaño, LoRAs, etc.)
	foreach ($nodes as $nodeId => $node):
		if (!isset($node['class_type'])):
			continue;
		endif;

		$class = $node['class_type'];
		$inputs = $node['inputs'] ?? [];

		switch ($class):
			case 'UNETLoader':
				if (isset($inputs['unet_name'])):
					$model = $inputs['unet_name'];
				endif;
				break;

			case 'VAELoader':
				if (isset($inputs['vae_name'])):
					$vae = $inputs['vae_name'];
				endif;
				break;

			case 'EmptyLatentImage':
				$width = $inputs['width'] ?? '';
				$height = $inputs['height'] ?? '';
				break;

			case 'ModelSamplingSD3':
				if (isset($inputs['shift'])):
					$otherParams['shift'] = $inputs['shift'];
				endif;
				break;

			case 'DualCLIPLoader':
				if (isset($inputs['clip_name1'])):
					$otherParams['text_encoder1'] = $inputs['clip_name1'];
				endif;
				if (isset($inputs['clip_name2'])):
					$otherParams['text_encoder2'] = $inputs['clip_name2'];
				endif;
				break;

			default:
				// Detectar nodos de LoRA (LoraLoader, LoraLoaderModelOnly, etc.)
				if (strpos($class, 'LoraLoader') !== false):
					$loraName = $inputs['lora_name'] ?? '';
					$loraWeight = $inputs['strength_model'] ?? $inputs['strength'] ?? 1.0;
					if ($loraName):
						$loras[] = ['name' => $loraName, 'weight' => $loraWeight];
					endif;
				endif;
				break;
		endswitch;
	endforeach;

	// 4️⃣ Información adicional desde el Workflow (notas, etc.)
	$workflowNotes = '';
	if ($workflowData && isset($workflowData['nodes'])):
		foreach ($workflowData['nodes'] as $node):
			if (isset($node['type']) && $node['type'] === 'MarkdownNote'):
				$widgets = $node['widgets_values'] ?? [];
				if (!empty($widgets) && is_array($widgets)):
					$workflowNotes .= implode(' ', $widgets);
				endif;
			endif;
		endforeach;
	endif;

	// 5️⃣ Construcción del HTML
	$html = '';

	// Prompt positivo
	if ($positivePrompt !== ''):
		$html .= '<fieldset>';
		$html .= '<legend>Prompt</legend>';
		$html .= '<div class="prompt">' . htmlspecialchars($positivePrompt) . '</div>';
		$html .= '</fieldset>';
	endif;

	// Prompt negativo
	if ($negativePrompt !== ''):
		$html .= '<fieldset>';
		$html .= '<legend>Prompt Negativo</legend>';
		$html .= '<div class="negative-prompt">' . htmlspecialchars($negativePrompt) . '</div>';
		$html .= '</fieldset>';
	endif;

	// Otros parámetros
	if (
		$model !== '' || $vae !== '' || $seed !== '' || $steps !== '' || $cfg !== '' ||
		$sampler !== '' || $scheduler !== '' || $width !== '' || $height !== '' ||
		!empty($loras) || !empty($otherParams) || $workflowNotes !== ''
	):

		$html .= '<fieldset>';
		$html .= '<legend>Otros</legend>';
		$html .= '<ul>';

		// Modelo
		if ($model !== ''):
			global $nombreModelo;
			$nombreModelo = $model;
			$html .= '<li class="model">🔥 <b>Modelo:</b> ' . htmlspecialchars($model) . '</li>';
		endif;

		// VAE
		if ($vae !== ''):
			$html .= '<li><b>VAE:</b> ' . htmlspecialchars($vae) . '</li>';
		endif;

		// LoRAs
		foreach ($loras as $lora):
			$html .= '<li>🧠 <b>' . htmlspecialchars($lora['name']) . '</b> (weight ' . htmlspecialchars((string) $lora['weight']) . ')</li>';
		endforeach;

		// Dimensiones
		if ($width !== '' && $height !== ''):
			$html .= '<li><b>Size:</b> 📐 ' . htmlspecialchars("{$width} × {$height}") . '</li>';
		endif;

		// Parámetros del sampler
		if ($seed !== ''):
			$html .= '<li><b>Seed:</b> 🎲 ' . htmlspecialchars((string) $seed) . '</li>';
		endif;
		if ($steps !== ''):
			$html .= '<li><b>Steps:</b> ' . htmlspecialchars((string) $steps) . '</li>';
		endif;
		if ($cfg !== ''):
			$html .= '<li><b>CFG scale:</b> ' . htmlspecialchars((string) $cfg) . '</li>';
		endif;
		if ($sampler !== ''):
			$html .= '<li><b>Sampler:</b> ' . htmlspecialchars($sampler) . '</li>';
		endif;
		if ($scheduler !== ''):
			$html .= '<li><b>Scheduler:</b> ' . htmlspecialchars($scheduler) . '</li>';
		endif;

		// Otros parámetros extra (shift, text_encoders, etc.)
		foreach ($otherParams as $key => $value):
			$label = ucfirst(str_replace('_', ' ', $key));
			$html .= '<li><b>' . htmlspecialchars($label) . ':</b> ' . htmlspecialchars((string) $value) . '</li>';
		endforeach;

		// Notas del workflow
		if ($workflowNotes !== ''):
			$html .= '<li><details><summary><b>Notas:</b></summary> ' . nl2br(htmlspecialchars($workflowNotes)) . '</details></li>';
		endif;

		$html .= '</ul>';
		$html .= '</fieldset>';
	endif;

	// Envolver en <details> si hay contenido
	if ($html !== ''):
		$html = '<details open class="psd metadata-ia"><summary>Parámetros ComfyUI</summary>' . $html . '</details>';
	endif;

	return $html;
}

/**
 * Parser robusto de parámetros de InvokeAI.
 * Extrae metadata desde:
 *  - Invokeai_metadata
 *  - Invokeai_graph
 *  - Invokeai_workflow
 *
 * Funciona incluso cuando metadata es null y solo existen graph/workflow.
 */

/**
 * Extrae de forma segura el valor de un nodo en el payload de InvokeAI.
 * Maneja anidaciones comunes como 'value'.
 *
 * @param array $data Array de datos del nodo a examinar.
 * @param string $clave Clave del array a extraer.
 * @return mixed Valor del nodo o null si no se encuentra.
 */
function valorNodo($data, $clave)
{
	// $data puede ser un array de campos (incluyendo posibles 'inputs' ya fusionados)
	if (!isset($data[$clave])):
		return null;
	endif;

	$v = $data[$clave];

	// Si el valor es un array con la clave 'value', se retorna ese subvalor (común en algunos nodos)
	if (is_array($v) && isset($v['value'])):
		return $v['value'];
	endif;

	return $v;
}

/**
 * Detecta y clasifica el tipo/base del modelo de SD o InvokeAI en base a su nombre.
 *
 * @param mixed $modeloInfo Nombre del modelo o array de metadata del modelo.
 * @return string Nombre genérico del tipo de modelo (FLUX, SDXL, SD3, SD1.5, etc.) o 'Stable Diffusion'.
 */
function detectarTipoModelo($modeloInfo)
{
	// Acepta un string (nombre) o un array con 'name' y opcionalmente 'base'
	$nombre = is_array($modeloInfo) ? ($modeloInfo['name'] ?? '') : $modeloInfo;
	$base = is_array($modeloInfo) ? ($modeloInfo['base'] ?? '') : '';

	if (!$nombre):
		return null;
	endif;

	$n = strtolower($nombre);
	$b = strtolower($base);

	// Priorizar el campo 'base' si está disponible y es reconocible
	if (strpos($b, 'flux') !== false):
		return 'FLUX';
	endif;
	if (strpos($b, 'sdxl') !== false):
		return 'SDXL';
	endif;
	if (strpos($b, 'sd3') !== false):
		return 'SD3';
	endif;
	if (strpos($b, 'sd2') !== false):
		return 'SD2';
	endif;
	if (strpos($b, 'sd1') !== false):
		return 'SD1.5';
	endif;

	// Si no hay base útil, buscar en el nombre
	if (strpos($n, 'flux') !== false):
		return 'FLUX';
	endif;
	if (strpos($n, 'sdxl') !== false || strpos($n, 'xl') !== false):
		return 'SDXL';
	endif;
	if (strpos($n, 'sd3') !== false):
		return 'SD3';
	endif;
	if (strpos($n, '2.1') !== false || strpos($n, 'sd2') !== false):
		return 'SD2';
	endif;
	if (strpos($n, '1.5') !== false || strpos($n, 'sd15') !== false):
		return 'SD1.5';
	endif;
	if (strpos($n, '1.4') !== false || strpos($n, 'sd14') !== false):
		return 'SD1.4';
	endif;

	return 'Stable Diffusion';
}

/**
 * Analiza el payload JSON genérico devuelto por InvokeAI y consolida sus parámetros dispersos.
 * Extrae modelos, semilla, prompts fragmentados y adaptadores de control.
 *
 * @param array $datos Datos parseados desde la información Exif del archivo.
 * @return string HTML estructurado con la reconstrucción del historial de generación.
 */
function parámetrosInvokeAI(array $datos): string
{

	$meta = $datos['Invokeai_metadata'] ?? null;
	$graph = $datos['Invokeai_graph'] ?? null;
	$workflow = $datos['Invokeai_workflow'] ?? null;

	if (!is_null($meta) && !is_array($meta)):
		$meta = json_decode($meta, true);
	endif;
	if (!is_null($graph) && !is_array($graph)):
		$graph = json_decode($graph, true);
	endif;
	if (!is_null($workflow) && !is_array($workflow)):
		$workflow = json_decode($workflow, true);
	endif;

	$nodes = [];

	// Extraer nodos desde graph (considerando posibles anidaciones)
	if (is_array($graph)):
		if (isset($graph['nodes'])):
			$nodes = $graph['nodes'];
		elseif (isset($graph['graph']['nodes'])):
			$nodes = $graph['graph']['nodes'];
		endif;
	endif;

	// Si no se encontraron en graph, probar con workflow
	if (empty($nodes) && is_array($workflow)):
		if (isset($workflow['nodes'])):
			$nodes = $workflow['nodes'];
		elseif (isset($workflow['graph']['nodes'])):
			$nodes = $workflow['graph']['nodes'];
		endif;
	endif;

	$prompt = '';
	$leftPrompt = '';
	$rightPrompt = '';
	$negativo = '';

	$modelo = null;
	$modelHash = null;
	$tipoModelo = null;

	$vae = null;

	$width = null;
	$height = null;

	$seed = null;
	$steps = null;
	$cfg = null;
	$scheduler = null;

	$clipSkip = null;
	$strength = null;

	$hrf = false;
	$hrfMethod = null;
	$hrfStrength = null;

	$refiner = null;
	$tipoRefiner = null;

	$loras = [];
	$controlnets = [];
	$ipadapters = [];
	$t2iadapters = [];

	/* -------- METADATA DIRECTO (incluye modelo) -------- */

	if (is_array($meta)):

		$prompt = $meta['positive_prompt'] ?? '';
		$negativo = $meta['negative_prompt'] ?? '';

		$width = $meta['width'] ?? null;
		$height = $meta['height'] ?? null;

		$seed = $meta['seed'] ?? null;

		$cfg = $meta['cfg_scale'] ?? null;
		$steps = $meta['steps'] ?? null;
		$scheduler = $meta['scheduler'] ?? null;

		$clipSkip = $meta['clip_skip'] ?? null;
		$strength = $meta['strength'] ?? null;

		$hrf = $meta['hrf_enabled'] ?? false;
		$hrfMethod = $meta['hrf_method'] ?? null;
		$hrfStrength = $meta['hrf_strength'] ?? null;

		// --- Modelo desde metadatos ---
		if (isset($meta['model'])):
			$modelMeta = $meta['model'];
			if (is_array($modelMeta)):
				$modelo = $modelMeta['name'] ?? null;
				$modelHash = $modelMeta['hash'] ?? null;
				$tipoModelo = detectarTipoModelo($modelMeta);
			elseif (is_string($modelMeta)):
				$modelo = $modelMeta;
				$tipoModelo = detectarTipoModelo($modelMeta);
			endif;
		endif;

		// --- VAE desde metadatos ---
		if (isset($meta['vae'])):
			$vaeMeta = $meta['vae'];
			if (is_array($vaeMeta)):
				$vae = $vaeMeta['name'] ?? null;
			elseif (is_string($vaeMeta)):
				$vae = $vaeMeta;
			endif;
		endif;
	endif;

	/* -------- ANALISIS DE NODOS (completa información faltante) -------- */

	foreach ($nodes as $n):

		$data = $n['data'] ?? $n;  // algunos formatos envuelven el nodo en 'data'

		// Fusionar campos directos con posibles 'inputs'
		$allFields = $data;
		if (isset($data['inputs']) && is_array($data['inputs'])):
			$allFields = array_merge($data, $data['inputs']);
		endif;

		$type = $allFields['type'] ?? '';

		/* ----- Modelo (solo si aún no tenemos nombre) ----- */
		if (!$modelo):
			$m = valorNodo($allFields, 'model');
			if ($m):
				$nombreModelo = null;
				$hashModelo = null;
				if (is_array($m)):
					$nombreModelo = $m['name'] ?? null;
					$hashModelo = $m['hash'] ?? null;
				elseif (is_string($m)):
					$nombreModelo = $m;
				endif;

				if ($nombreModelo):
					if (strpos($type, 'refiner') !== false):
						$refiner = $nombreModelo;
						$tipoRefiner = detectarTipoModelo($m);
					else:
						$modelo = $nombreModelo;
						$modelHash = $hashModelo;
						$tipoModelo = detectarTipoModelo($m);
					endif;
				endif;
			endif;
		endif;

		/* ----- VAE (si aún no tenemos) ----- */
		if (!$vae):
			$v = valorNodo($allFields, 'vae_model') ?? valorNodo($allFields, 'vae');
			if ($v):
				if (is_array($v)):
					$vae = $v['name'] ?? null;
				elseif (is_string($v)):
					$vae = $v;
				endif;
			endif;
		endif;

		/* ----- LoRAs ----- */
		if (strpos($type, 'lora') !== false):
			$l = valorNodo($allFields, 'lora');
			if ($l):
				$loras[] = [
					'nombre' => is_array($l) ? ($l['name'] ?? '') : (string) $l,
					'peso' => valorNodo($allFields, 'weight') ?? 1.0
				];
			endif;
		endif;

		/* ----- Prompts (prioridad a positive/negative explícitos) ----- */
		$posPrompt = valorNodo($allFields, 'positive_prompt');
		if ($posPrompt && $prompt === ''):
			$prompt = $posPrompt;
		endif;
		$negPrompt = valorNodo($allFields, 'negative_prompt');
		if ($negPrompt && $negativo === ''):
			$negativo = $negPrompt;
		endif;

		// Si no se encontraron los específicos, buscar 'prompt' genérico
		$genericPrompt = valorNodo($allFields, 'prompt');
		if ($genericPrompt):
			if ($prompt === ''):
				$prompt = $genericPrompt;
			elseif ($negativo === ''):
				$negativo = $genericPrompt;
			endif;
		endif;

		// Encontrar prompts tipo string_left o string_right
		$lPrompt = valorNodo($allFields, 'string_left');
		$rPrompt = valorNodo($allFields, 'string_right');
		if ($lPrompt):
			if ($leftPrompt === ''):
				$leftPrompt = $lPrompt;
			endif;
		endif;
		if ($rPrompt):
			if ($rightPrompt === ''):
				$rightPrompt = $rPrompt;
			endif;
		endif;

		/* ----- Denoise / steps / cfg / scheduler ----- */
		if (strpos($type, 'denoise') !== false || strpos($type, 'tiled') !== false):
			$steps = valorNodo($allFields, 'steps') ?? $steps;
			$cfg = valorNodo($allFields, 'cfg_scale') ?? $cfg;
			$scheduler = valorNodo($allFields, 'scheduler') ?? $scheduler;
		endif;

		/* ----- Noise (tamaño y semilla) ----- */
		if ($type === 'noise' || strpos($type, 'noise') !== false):
			$seed = valorNodo($allFields, 'seed') ?? $seed;
			$width = valorNodo($allFields, 'width') ?? $width;
			$height = valorNodo($allFields, 'height') ?? $height;
		endif;

		/* ----- Adapters ----- */
		if (strpos($type, 'controlnet') !== false):
			$controlnets[] = $type;
		endif;
		if (strpos($type, 'ip_adapter') !== false):
			$ipadapters[] = $type;
		endif;
		if (strpos($type, 't2i_adapter') !== false):
			$t2iadapters[] = $type;
		endif;

		/* ----- Clip Skip, Strength, etc. ----- */
		$clipSkip = valorNodo($allFields, 'clip_skip') ?? $clipSkip;
		$strength = valorNodo($allFields, 'strength') ?? $strength;
	endforeach;

	/* -------- FORMATEO HTML (sin cambios relevantes) -------- */

	$importantes = [];
	$otros = [];

	if ($width && $height):
		$importantes[] = "<li><b>Size:</b> 📐 " . htmlspecialchars("$width × $height") . "</li>";
	endif;

	if ($seed !== null):
		$importantes[] = "<li><b>Seed:</b> 🎲 " . htmlspecialchars((string) $seed) . "</li>";
	endif;

	if ($steps):
		$importantes[] = "<li><b>Steps:</b> " . htmlspecialchars((string) $steps) . "</li>";
	endif;

	if ($cfg):
		$importantes[] = "<li><b>CFG scale:</b> " . htmlspecialchars((string) $cfg) . "</li>";
	endif;

	if ($scheduler):
		$importantes[] = "<li><b>Scheduler:</b> " . htmlspecialchars($scheduler) . "</li>";
	endif;

	if ($clipSkip):
		$importantes[] = "<li><b>Clip Skip:</b> " . htmlspecialchars((string) $clipSkip) . "</li>";
	endif;

	if ($strength):
		$importantes[] = "<li><b>Denoising:</b> " . htmlspecialchars((string) $strength) . "</li>";
	endif;

	if ($vae):
		$otros[] = "<li><b>VAE:</b> " . htmlspecialchars($vae) . "</li>";
	endif;

	if ($hrf):
		$otros[] = "<li><b>Hires Fix:</b> " . htmlspecialchars("$hrfMethod ($hrfStrength)") . "</li>";
	endif;

	if ($refiner):
		$otros[] = "<li><b>Refiner:</b> " . htmlspecialchars($refiner) . " ($tipoRefiner)</li>";
	endif;

	if ($controlnets):
		$otros[] = "<li><b>ControlNet:</b> " . count($controlnets) . " active</li>";
	endif;

	if ($ipadapters):
		$otros[] = "<li><b>IP Adapter:</b> " . count($ipadapters) . " active</li>";
	endif;

	if ($t2iadapters):
		$otros[] = "<li><b>T2I Adapter:</b> " . count($t2iadapters) . " active</li>";
	endif;

	if ($prompt && $leftPrompt && $rightPrompt):
		if (str_starts_with($prompt, $leftPrompt) && str_ends_with($prompt, $rightPrompt)):
			// Si el prompt ya incluye los prompts izquierdo y derecho, no los mostramos por separado
			$prompt = substr($prompt, strlen($leftPrompt), strlen($prompt) - strlen($leftPrompt) - strlen($rightPrompt));
		endif;
	endif;

	if ($leftPrompt):
		$lPrompt = '<fieldset>';
		$lPrompt .= '<legend>Prompt Left</legend>';
		$lPrompt .= htmlspecialchars($leftPrompt);
		$lPrompt .= '</fieldset>';
		$leftPrompt = $lPrompt;
	else:
		$leftPrompt = '';
	endif;

	if ($rightPrompt):
		$rPrompt = '<fieldset>';
		$rPrompt .= '<legend>Prompt Right</legend>';
		$rPrompt .= htmlspecialchars($rightPrompt);
		$rPrompt .= '</fieldset>';
		$rightPrompt = $rPrompt;
	else:
		$rightPrompt = '';
	endif;

	$html = '';

	if ($prompt):
		$html .= '<fieldset>';
		$html .= '<legend>Prompt</legend>';
		$html .= '<div class="prompt">' . $leftPrompt . htmlspecialchars($prompt) . $rightPrompt . '</div>';
		$html .= '</fieldset>';
	elseif ($leftPrompt || $rightPrompt):
		$html .= '<fieldset>';
		$html .= '<legend>Prompt</legend>';
		if ($leftPrompt):
			$html .= '<div class="prompt">' . $leftPrompt . '</div>';
		endif;
		if ($rightPrompt):
			$html .= '<div class="prompt">' . $rightPrompt . '</div>';
		endif;
		$html .= '</fieldset>';
	endif;

	if ($negativo):
		$html .= '<fieldset>';
		$html .= '<legend>Prompt Negativo</legend>';
		$html .= '<div class="negative-prompt">' . htmlspecialchars($negativo) . '</div>';
		$html .= '</fieldset>';
	endif;

	$html .= '<fieldset>';
	$html .= '<legend>Otros</legend>';
	$html .= '<ul>';

	if ($modelo):
		$tooltip = $modelHash ? ' title="Hash: ' . htmlspecialchars($modelHash) . '"' : '';
		$tipoTxt = $tipoModelo ? " ($tipoModelo)" : '';
		$html .= '<li class="model"' . $tooltip . '>🔥 <b>Modelo:</b> ' . htmlspecialchars($modelo) . $tipoTxt . '</li>';
	endif;

	foreach ($loras as $l):
		$html .= "<li>🧠 <b>" . htmlspecialchars($l['nombre']) . "</b> (weight " . htmlspecialchars((string) $l['peso']) . ")</li>";
	endforeach;

	foreach ($importantes as $imp):
		$html .= $imp;
	endforeach;

	foreach ($otros as $o):
		$html .= $o;
	endforeach;

	$html .= '</ul>';
	$html .= '</fieldset>';

	global $nombreModelo;
	$nombreModelo = $modelo; // Para uso global si se necesita
	return '<details open class="psd metadata-ia"><summary>Parámetros InvokeAI</summary>' . $html . '</details>';
}



/**
 * Lee el metadato extendido de macOS (Spotlight) llamado kMDItemWhereFroms.
 * Usado comúnmente para rastrear la URL de origen de archivos descargados en Mac.
 *
 * @param string $ruta Ruta al archivo local.
 * @return string El valor extraído o cadena vacía si falla o no existe.
 */
function leerMetadatoExtendido(string $ruta): string
{
	if (!file_exists($ruta)):
		return 'no existe' . $ruta;
	endif;
	$rutaEscapada = escapeshellarg($ruta);

	// mdls devuelve metadatos de Spotlight en formato texto
	$comando = "mdls -name kMDItemWhereFroms $rutaEscapada";
	$linea = exec($comando, $salida, $código);

	if ($salida === null || !is_array($salida)):
		return '';
	endif;

	$elvalor = $salida[array_key_last($salida) - 1] ?? implode("\n", $salida);

	return trim($elvalor);
}

/**
 * Extrae un I-frame (keyframe) representativo de un archivo de video y lo guarda como JPG.
 * Utiliza FFmpeg para la extracción y ExifTool para copiar los metadatos originales al nuevo JPG.
 *
 * @param string $rutaVideo Ruta absoluta o relativa al video.
 * @return string Salida de consola y mensajes de estado, en formato HTML.
 */
function extraerIFrame(string $rutaVideo): string
{
	if (empty($rutaVideo) || !file_exists($rutaVideo)):
		return "No se encontró el archivo {$rutaVideo}.";
	endif;

	$ruta = pathinfo($rutaVideo);
	$ext = '.jpg';
	$rutaImagen = $ruta['dirname'] . DIRECTORY_SEPARATOR . $ruta['filename'] . $ext;

	$comando =
		"ffmpeg" .
		" -i " . escapeshellarg($rutaVideo) .
		" -vf \"select=eq(pict_type\\,I)\"" .
		" -fps_mode passthrough" .
		" -frames:v 1 " .
		($ext == 'jpg' ? ' -q:v 2' : '') .
		escapeshellarg($rutaImagen);
	//echo $comando.'<br>';
	exec(BREW_BIN . $comando, $salidaFfmpeg, $returnVarFfmpeg);

	if (!empty($salidaFfmpeg)):
		$salidaF = array_map('formatearRespuesta', $salidaFfmpeg);
	else:
		$salidaF = ['Respuesta vacía de FFmpeg.'];
	endif;

	if ($returnVarFfmpeg !== 0):
		return "[{$returnVarFfmpeg}]<br>" . implode("<br>", $salidaF);
	endif;
	$marcadorImagenExtraida = '<span hidden data-archivo-extraido="' . htmlspecialchars($rutaImagen, ENT_QUOTES, 'UTF-8') . '"></span>';
	$final = $marcadorImagenExtraida . formatearRespuesta('1. Imagen extraída con éxito: ' . $rutaImagen);

	$comandoExifTool = "exiftool  -overwrite_original -tagsFromFile " . escapeshellarg($rutaVideo) . " " . escapeshellarg($rutaImagen);
	exec(BREW_BIN . $comandoExifTool, $salidaExiftool, $returnVarExiftool);

	if (!empty($salidaExiftool)):
		$salidaE = array_map('formatearRespuesta', $salidaExiftool);
	else:
		$salidaE = ['Respuesta vacía de Exiftool.'];
	endif;

	if ($returnVarExiftool !== 0):
		return "{$final}<br>[{$returnVarExiftool}]<br>" . implode("<br>", $salidaE);
	endif;
	$final .= '<br>' . formatearRespuesta('2. Metadatos copiados con éxito.');

	return $final;
}

function resolverVideoExtraccion(?string $rutaVideo): ?string
{
	$real = resolverRutaProyecto($rutaVideo, 'file', false);
	if (!$real):
		return null;
	endif;

	$extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
	if (!in_array($extension, ['mp4', 'mov', 'm4v', 'webm', 'mkv', 'avi'], true)):
		return null;
	endif;

	return $real;
}

function tasaVideoDesdeFraccion(string $fraccion): float
{
	if (!str_contains($fraccion, '/')):
		return is_numeric($fraccion) ? (float) $fraccion : 0.0;
	endif;

	[$numerador, $denominador] = array_map('floatval', explode('/', $fraccion, 2));
	if ($denominador <= 0):
		return 0.0;
	endif;

	return $numerador / $denominador;
}

function obtenerInformacionVideoExtraccion(string $rutaVideo): array
{
	$info = [
		'ancho' => 0,
		'alto' => 0,
		'duracion' => 0.0,
		'fps' => 0.0,
		'frames' => 0,
		'frames_estimados' => true,
	];
	if (!FFPROBE_DISPONIBLE || !is_file($rutaVideo)):
		return $info;
	endif;

	$comando =
		BREW_BIN .
		'ffprobe -v error -select_streams v:0 ' .
		'-show_entries stream=width,height,nb_frames,avg_frame_rate,r_frame_rate,duration:format=duration ' .
		'-of json ' .
		escapeshellarg($rutaVideo) .
		' 2>/dev/null';
	$datos = json_decode((string) shell_exec($comando), true);
	if (!is_array($datos)):
		return $info;
	endif;

	$stream = $datos['streams'][0] ?? [];
	$formato = $datos['format'] ?? [];
	$duracion = (float) ($stream['duration'] ?? $formato['duration'] ?? 0);
	$fps = tasaVideoDesdeFraccion((string) ($stream['avg_frame_rate'] ?? $stream['r_frame_rate'] ?? '0/1'));
	$frames = (int) ($stream['nb_frames'] ?? 0);
	$estimados = true;
	if ($frames > 0):
		$estimados = false;
	elseif ($duracion > 0 && $fps > 0):
		$frames = (int) round($duracion * $fps);
	endif;

	return [
		'ancho' => (int) ($stream['width'] ?? 0),
		'alto' => (int) ($stream['height'] ?? 0),
		'duracion' => $duracion,
		'fps' => $fps,
		'frames' => max(0, $frames),
		'frames_estimados' => $estimados,
	];
}

function obtenerKeyframesVideo(string $rutaVideo): array
{
	if (!FFPROBE_DISPONIBLE || !is_file($rutaVideo)):
		return [];
	endif;

	$comando =
		BREW_BIN .
		'ffprobe -v error -select_streams v:0 -skip_frame nokey ' .
		'-show_entries frame=best_effort_timestamp_time,pkt_pts_time,pkt_dts_time ' .
		'-of csv=p=0 ' .
		escapeshellarg($rutaVideo) .
		' 2>/dev/null';
	$salida = [];
	exec($comando, $salida, $returnVar);
	if ($returnVar !== 0 || empty($salida)):
		return [];
	endif;

	$tiempos = [];
	foreach ($salida as $linea):
		foreach (explode(',', trim($linea)) as $valor):
			$valor = trim($valor);
			if ($valor === '' || !is_numeric($valor)):
				continue;
			endif;
			$clave = number_format((float) $valor, 6, '.', '');
			$tiempos[$clave] = (float) $valor;
			break;
		endforeach;
	endforeach;
	ksort($tiempos, SORT_NUMERIC);

	return array_values($tiempos);
}

function formatoExtraccionAvifSoportado(): bool
{
	static $soportado = null;
	if ($soportado !== null):
		return $soportado;
	endif;

	$soportado =
		ffmpegMuxerDisponible('avif')
		&& ffmpegEncoderDisponible('libaom-av1');

	return $soportado;
}

function ffmpegMuxerDisponible(string $muxer): bool
{
	static $muxers = null;
	if ($muxers === null):
		$muxers = (string) shell_exec(BREW_BIN . 'ffmpeg -hide_banner -muxers 2>/dev/null');
	endif;

	return (bool) preg_match('/^\s*E\s+' . preg_quote($muxer, '/') . '\b/im', $muxers);
}

function ffmpegEncoderDisponible(string $encoder): bool
{
	static $encoders = null;
	if ($encoders === null):
		$encoders = (string) shell_exec(BREW_BIN . 'ffmpeg -hide_banner -encoders 2>/dev/null');
	endif;

	return (bool) preg_match('/^\s*V\S*\s+' . preg_quote($encoder, '/') . '\b/im', $encoders);
}

function formatoExtraccionWebpSoportado(): bool
{
	static $soportado = null;
	if ($soportado !== null):
		return $soportado;
	endif;

	$soportado =
		(ffmpegMuxerDisponible('webp') && ffmpegEncoderDisponible('libwebp'))
		|| cwebpDisponible();

	return $soportado;
}

function cwebpDisponible(): bool
{
	return is_executable(BREW_BIN . 'cwebp');
}

function formatosExtraccionFrameSoportados(): array
{
	$formatos = ['jpg', 'png'];
	if (formatoExtraccionWebpSoportado()):
		$formatos[] = 'webp';
	endif;
	if (formatoExtraccionAvifSoportado()):
		$formatos[] = 'avif';
	endif;

	return $formatos;
}

function argumentosFormatoFrame(string $formato): string
{
	return match ($formato) {
		'jpg' => '-q:v 2 -update 1',
		'png' => '-compression_level 3 -update 1',
		'webp' => '-c:v libwebp -q:v 90 -compression_level 4 -update 1',
		'avif' => '-c:v libaom-av1 -still-picture 1 -crf 24 -b:v 0 -update 1',
		default => '-q:v 2 -update 1',
	};
}

function formatearValorExtraccionFrame(string $modo, int|float $valor): string
{
	if ($modo === 'timestamp'):
		$valor = max(0, (float) $valor);
		$texto = number_format($valor, 2, '.', '');
		$texto = rtrim(rtrim($texto, '0'), '.');
		return $texto === '' ? '0' : $texto;
	endif;

	return (string) max(0, (int) round((float) $valor));
}

function construirRutaImagenExtraida(string $rutaVideo, string $modo, int|float $indice, string $formato): string
{
	$info = pathinfo($rutaVideo);
	return $info['dirname'] .
		DIRECTORY_SEPARATOR .
		$info['filename'] .
		'-' .
		$modo .
		'_' .
		formatearValorExtraccionFrame($modo, $indice) .
		'.' .
		$formato;
}

function construirRutaPreviewFrame(string $rutaVideo, string $modo, int|float $indice): string
{
	$root = dirname(__DIR__);
	$dir = $root . DIRECTORY_SEPARATOR . '.posters' . DIRECTORY_SEPARATOR . 'extracciones';
	if (!is_dir($dir)):
		mkdir($dir, 0755, true);
	endif;

	return $dir . DIRECTORY_SEPARATOR . sha1($rutaVideo) . '-' . $modo . '_' . formatearValorExtraccionFrame($modo, $indice) . '.jpg';
}

function extraerFrameSeleccionadoVideo(
	string $rutaVideo,
	string $rutaImagen,
	string $modo,
	int|float $indice,
	string $formato,
	array $keyframes = []
): array {
	$modo = in_array($modo, ['keyframe', 'frame', 'timestamp'], true) ? $modo : 'frame';
	$indice = max(0, (float) $indice);
	if (!in_array($formato, formatosExtraccionFrameSoportados(), true)):
		$formato = 'jpg';
	endif;

	$argumentosFormato = argumentosFormatoFrame($formato);
	$convertirConCwebp = $formato === 'webp' && !ffmpegEncoderDisponible('libwebp') && cwebpDisponible();
	$rutaSalidaFfmpeg = $convertirConCwebp ? $rutaImagen . '.tmp.png' : $rutaImagen;
	if ($convertirConCwebp):
		$argumentosFormato = argumentosFormatoFrame('png');
		@unlink($rutaSalidaFfmpeg);
	endif;
	$salida = [];
	if ($modo === 'keyframe'):
		$indice = (int) round($indice);
		$keyframes = $keyframes ?: obtenerKeyframesVideo($rutaVideo);
		if (!array_key_exists($indice, $keyframes)):
			return [
				'ok' => false,
				'codigo' => 1,
				'comando' => '',
				'salida' => ['No existe el keyframe con índice ' . $indice . '.'],
			];
		endif;
		$segundo = number_format((float) $keyframes[$indice], 6, '.', '');
		$comando =
			BREW_BIN .
			'ffmpeg -hide_banner -loglevel error -y -ss ' . escapeshellarg($segundo) .
			' -i ' . escapeshellarg($rutaVideo) .
			' -frames:v 1 ' .
			$argumentosFormato . ' ' .
			escapeshellarg($rutaSalidaFfmpeg) .
			' 2>&1';
	elseif ($modo === 'frame'):
		$indice = (int) round($indice);
		$filtro = 'select=eq(n\,' . $indice . ')';
		$comando =
			BREW_BIN .
			'ffmpeg -hide_banner -loglevel error -y -i ' . escapeshellarg($rutaVideo) .
			' -vf ' . escapeshellarg($filtro) .
			' -vsync vfr -frames:v 1 ' .
			$argumentosFormato . ' ' .
			escapeshellarg($rutaSalidaFfmpeg) .
			' 2>&1';
	else:
		$segundo = number_format($indice, 6, '.', '');
		$comando =
			BREW_BIN .
			'ffmpeg -hide_banner -loglevel error -y -ss ' . escapeshellarg($segundo) .
			' -i ' . escapeshellarg($rutaVideo) .
			' -frames:v 1 ' .
			$argumentosFormato . ' ' .
			escapeshellarg($rutaSalidaFfmpeg) .
			' 2>&1';
	endif;

	exec($comando, $salida, $codigo);
	$comandoMostrar = preg_replace('/^' . preg_quote(BREW_BIN, '/') . '/', '', $comando);
	if ($codigo === 0 && $convertirConCwebp && is_file($rutaSalidaFfmpeg) && filesize($rutaSalidaFfmpeg) > 0):
		$comandoCwebp =
			BREW_BIN .
			'cwebp -quiet -q 90 ' .
			escapeshellarg($rutaSalidaFfmpeg) .
			' -o ' .
			escapeshellarg($rutaImagen) .
			' 2>&1';
		$salidaCwebp = [];
		exec($comandoCwebp, $salidaCwebp, $codigoCwebp);
		$salida = array_merge($salida, $salidaCwebp);
		$codigo = $codigoCwebp;
		$comandoMostrar .= "\n" . preg_replace('/^' . preg_quote(BREW_BIN, '/') . '/', '', $comandoCwebp);
		@unlink($rutaSalidaFfmpeg);
	endif;
	$ok = $codigo === 0 && is_file($rutaImagen) && filesize($rutaImagen) > 0;

	return [
		'ok' => $ok,
		'codigo' => $codigo,
		'comando' => $comandoMostrar,
		'salida' => $salida ?: ($ok ? ['Frame extraído.'] : ['Respuesta vacía de FFmpeg.']),
	];
}

function copiarMetadatosVideoAImagen(string $rutaVideo, string $rutaImagen): array
{
	$comando =
		BREW_BIN .
		'exiftool -overwrite_original -tagsFromFile ' .
		escapeshellarg($rutaVideo) .
		' ' .
		escapeshellarg($rutaImagen) .
		' 2>&1';
	$salida = [];
	exec($comando, $salida, $codigo);

	return [
		'ok' => $codigo === 0,
		'codigo' => $codigo,
		'comando' => preg_replace('/^' . preg_quote(BREW_BIN, '/') . '/', '', $comando),
		'salida' => $salida ?: ['Respuesta vacía de ExifTool.'],
	];
}

/**
 * Convierte una imagen (TIF, PNG, JPG) a formato WebP usando cwebp.
 * Como paso posterior, copia sus metadatos usando Exiftool.
 *
 * @param string $rutaImagen Ruta de la imagen original.
 * @return string Log de ejecución o respuesta formateada indicando éxito.
 */
function convertirWebP(string $rutaImagen): string
{
	if (empty($rutaImagen) || !file_exists($rutaImagen)):
		return "No se encontró el archivo {$rutaImagen}.";
	endif;

	$ruta = pathinfo($rutaImagen);
	$ext = '.webp';
	$rutaWebP = $ruta['dirname'] . DIRECTORY_SEPARATOR . $ruta['filename'] . $ext;

	$comando =
		"cwebp" .
		" -q 69" .
		" " . escapeshellarg($rutaImagen) .
		" -o " . escapeshellarg($rutaWebP);
	exec(BREW_BIN . $comando, $salidaCwebp, $returnVarCwebp);

	if (!empty($salidaCwebp)):
		$salidaF = array_map('formatearRespuesta', $salidaCwebp);
	else:
		$salidaF = ['Respuesta vacía de cwebp.'];
	endif;

	if ($returnVarCwebp !== 0):
		return "[{$returnVarCwebp}]<br>" . implode("<br>", $salidaF);
	endif;

	$comandoExifTool = "exiftool -overwrite_original -tagsFromFile " . escapeshellarg($rutaImagen) . " " . escapeshellarg($rutaWebP);
	exec(BREW_BIN . $comandoExifTool, $salidaExiftool, $returnVarExiftool);

	if (!empty($salidaExiftool)):
		$salidaE = array_map('formatearRespuesta', $salidaExiftool);
	else:
		$salidaE = ['Respuesta vacía de Exiftool.'];
	endif;

	if ($returnVarExiftool !== 0):
		$salidaE = array_merge($salidaF, $salidaE);
		return "[{$returnVarExiftool}]<br>" . implode("<br>", $salidaE);
	endif;

	return formatearRespuesta('1. Imagen convertida con éxito: ' . $rutaWebP);
}

/**
 * Convierte un código de país alfabético de dos letras (ISO 3166-1 alpha-2) en un emoji de bandera.
 *
 * @param string $cc Código de país (ej. 'US', 'ES', 'MX').
 * @return string El emoji correspondiente a la bandera del país, vacío si $cc es inválido.
 */
function banderaDesdeCountryCode(string $cc): string
{
	$cc = strtoupper($cc);
	$flag = '';
	foreach (str_split($cc) as $char):
		$flag .= mb_chr(127397 + ord($char), 'UTF-8');
	endforeach;
	return $flag;
}
/**
 * Genera el markup HTML de un <figcaption> estándar.
 * Incluye la carpeta contenedora, el nombre de archivo, y sus metadatos base (dimensiones y peso en disco).
 *
 * @param string $ruta Nombre del archivo o ruta a mostrar en el enlace.
 * @param string $dimensiones Texto representando sus dimensiones relativas (ej. "1920×1080").
 * @return string Código HTML formateado del caption.
 */
function mostrarFigcaption(string $ruta, string $dimensiones): string
{
	if (empty($ruta)):
		return '';
	endif;

	$tamaño = obtenerTamanioArchivo($ruta);
	$directorio = htmlspecialchars(dirname($ruta), ENT_QUOTES, 'UTF-8');
	$archivo = htmlspecialchars(basename($ruta), ENT_QUOTES, 'UTF-8');

	return '<figcaption>' .
		'<div>' . // Para text-overflow:ellipsis;
		'<small>' . $directorio . '</small><br>' .
		$archivo .
		'</div>' .
		'<small>' . $dimensiones . ' [' . $tamaño . ']</small>' .
		'</figcaption>';
}
/**
 * Genera el botón que abre la media en el lightbox sin convertir toda la vista previa en enlace.
 *
 * @param string $ruta Ruta web del archivo que debe abrir el lightbox.
 * @param string $tipo Tipo de media ('image' o 'video').
 * @return string Botón HTML listo para inyectarse en el <figure>.
 */
function mostrarBotonLightbox(string $ruta, string $tipo): string
{
	if (empty($ruta)):
		return '';
	endif;

	$rutaAttr = htmlspecialchars($ruta, ENT_QUOTES, 'UTF-8');
	$tipoAttr = htmlspecialchars($tipo, ENT_QUOTES, 'UTF-8');
	$esVideo = $tipo === 'video';
	$icono = $esVideo ? '▶️' : '👁️';
	$etiqueta = $esVideo ? 'Abrir video en lightbox' : 'Abrir imagen en lightbox';
	$etiquetaAttr = htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8');

	return '<button type="button" class="abrir-lightbox" data-lightbox-href="' . $rutaAttr . '" data-lightbox-tipo="' . $tipoAttr . '" aria-label="' . $etiquetaAttr . '" title="' . $etiquetaAttr . '">' . $icono . '</button>';
}
/**
 * Identifica la red social más probable a partir de URLs, tokens o metadatos de origen.
 *
 * @param string $texto Fragmentos de metadatos, xattr o notas donde buscar señales de origen.
 * @return string Nombre de la red social detectada, o cadena vacía si no hay coincidencia.
 */
function detectarRedSocialEnTexto(string $texto): string
{
	if (empty($texto)):
		return '';
	endif;

	$texto = mb_strtolower($texto, 'UTF-8');
	$redes = [
		'Instagram' => ['instagram.', 'cdninstagram.com'],
		'Facebook' => ['facebook.com', 'fbcdn.net', 'fb.com'],
		'Meta (Facebook/Instagram)' => ['fbmd'],
		'Threads' => ['threads.net'],
		'WhatsApp' => ['whatsapp.com', 'wa.me'],
		'X (Twitter)' => ['twitter.com', 'x.com', 'twimg.com', 't.co/'],
		'TikTok' => ['tiktok.com', 'tiktokcdn.com', 'muscdn.com'],
		'Reddit' => ['reddit.com', 'redd.it'],
		'Pinterest' => ['pinterest.', 'pinimg.com'],
		'LinkedIn' => ['linkedin.com', 'licdn.com'],
		'Telegram' => ['telegram.org', 't.me'],
		'OnlyFans' => ['onlyfans.com'],
		'Fansly' => ['fansly.com'],
		'VK' => ['vk.com', 'userapi.com'],
		'Snapchat' => ['snapchat.com', 'sc-cdn.net'],
		'Bluesky' => ['bsky.app', 'bsky.social', 'cdn.bsky.app'],
		'Tumblr' => ['tumblr.com'],
		'YouTube' => ['youtube.com', 'youtu.be', 'ytimg.com'],
		'Flickr' => ['flickr.com', 'staticflickr.com'],
		'Patreon' => ['patreon.com'],
		'Hidden' => ['hidden.com'],
	];

	foreach ($redes as $red => $patrones):
		foreach ($patrones as $patron):
			if (str_contains($texto, $patron)):
				return $red;
			endif;
		endforeach;
	endforeach;

	return '';
}
/**
 * Genera una advertencia visual para metadatos que pueden revelar origen o tracking.
 *
 * @param string $etiqueta Nombre del campo o señal detectada.
 * @param string $valor Valor del campo.
 * @return string HTML de advertencia.
 */
function advertenciaMetadatosHtml(string $etiqueta, string $valor): string
{
	$etiqueta = htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8');
	$valor = htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');

	return '<div class="advertencia-metadatos advertencia-tracking"><b>¡' . $etiqueta . '!</b> ' . $valor . '</div>';
}
/**
 * Calcula el tamaño en disco de un archivo y devuelve una representación legible por el usuario.
 * Escala automáticamente el valor a bytes, KB, MB o GB según su peso final.
 *
 * @param string $rutaArchivo Ruta absoluta o relativa del archivo objetivo.
 * @return string Representación escalar del tamaño (ej. '12.4 MB').
 */
function obtenerTamanioArchivo(string $rutaArchivo): string
{
	// Obtiene el tamaño del archivo en bytes
	$tamanioBytes = filesize($rutaArchivo);

	if ($tamanioBytes < 1024):
		return $tamanioBytes . ' bytes';
	elseif ($tamanioBytes < 1048576): // 1024 * 1024
		$tamanioKB = $tamanioBytes / 1024;
		return round($tamanioKB, 2) . ' KB';
	elseif ($tamanioBytes < 1073741824): // 1024 * 1024 * 1024
		$tamanioMB = $tamanioBytes / 1048576; // 1024 * 1024
		return round($tamanioMB, 2) . ' MB';
	else:
		$tamanioGB = $tamanioBytes / 1073741824; // 1024 * 1024 * 1024
		return round($tamanioGB, 2) . ' GB';
	endif;
}
/**
 * Genera el HTML semántico (<figure>, <video>, <figcaption>) de un archivo de video local.
 * Recupera sus resoluciones por medio de FFprobe si es posible, e intenta mostrar un póster.
 *
 * @param string $ruta Ubicación del archivo de video.
 * @param string|int $id ID numérico o identificador único para el DOM.
 * @return string|false HTML estructurado o FALSE en error.
 */
function mostrarVideo($ruta, $id)
{
	if (empty($ruta) || !is_file($ruta)):
		return FALSE;
	endif;
	$html = $dimensiones = $resultado = $salida = '';
	$comando = comandoBrewSeguro([
		'ffprobe',
		'-v',
		'error',
		'-select_streams',
		'v:0',
		'-show_entries',
		'stream=width,height',
		'-of',
		'csv=s=x:p=0',
		$ruta
	]);
	if (FFPROBE_DISPONIBLE):
		$resultado = exec($comando, $salida);
		if ($resultado && is_string($resultado)):
			$dimensiones = trim(str_replace('x', '×', $resultado));
		endif;
	endif;

	$ruta = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $ruta);
	$poster = generarJPGtemporal($ruta);
	$posterAttr = '';
	if (!empty($poster)):
		$rutaPosterAbsoluta = dirname(__DIR__) . DIRECTORY_SEPARATOR . $poster;
		$posterVersion = file_exists($rutaPosterAbsoluta) ? '?v=' . filemtime($rutaPosterAbsoluta) : '';
		$posterAttr = ' poster="' . htmlspecialchars($poster . $posterVersion, ENT_QUOTES, 'UTF-8') . '"';
	endif;
	$html .=
		'<figure>' .
			'<video' .
			' id="img_' . $id . '"' .
			' src="' . $ruta . '?v=' . filemtime($ruta) . '"' .
			' data-ruta="' . $ruta . '"' .
			' data-tipo="vid"' .
			//' loop'.
			' preload="metadata"' .
			$posterAttr .
		' controls' .
		'></video>' .
		mostrarBotonLightbox($ruta, 'video') .
		mostrarFigcaption($ruta, $dimensiones) .
		'</figure>';

	return $html;
}
/**
 * Genera el entorno HTML primario (<figure>, <img>) para mostrar una imagen.
 * Agrega conversiones en tiempo real en caso de ser formato HEIC de Apple (para soporte base HTML5).
 * Adicionalmente, adjunta un SVG overlay opcional y maneja atributos responsivos de lazzy load.
 *
 * @param string $ruta Ubicación del archivo de imagen.
 * @param string|int $id Identificador del bloque de imagen.
 * @param string $imageSize Opcional, las proporciones "ancho x alto" de la imagen en bruto.
 * @param string $svg Nodo o layer SVG a superponer.
 * @return string|false Componente visual armado o FALSE en caso de error de archivo.
 */
function mostrarImagen($ruta, $id, string $imageSize = '', $svg = '')
{
	if (empty($ruta) || !is_file($ruta)):
		return FALSE;
	endif;
	$html = '';

	if (!empty($imageSize)):
		if (strpos($imageSize, 'x') !== false):
			$dimensiones = explode('x', $imageSize);
		elseif (strpos($imageSize, ' ') !== false):
			$dimensiones = explode(' ', $imageSize);
		endif;
	else:
		$dimensiones = getimagesize($ruta);
	endif;
	if ($dimensiones):
		$atributos =
			' width="' . $dimensiones[0] . '"' .
			' height="' . $dimensiones[1] . '"';
	else:
		$atributos = '';
		$dimensiones = ['', ''];
	endif;

	$rutaweb = $rutaweboriginal = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $ruta);

	if (str_ends_with(strtolower($rutaweb), '.heic')):
		$rutaweb = generarJPGtemporal($rutaweb);
		$rutaweb = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $rutaweb);
	endif;
	$html .=
		'<figure>' .
			'<img' .
			' id="img_' . $id . '"' .
			' src="' . $rutaweb . '?v=' . filemtime($ruta) . '"' .
			' data-ruta="' . $rutaweboriginal . '"' .
			$atributos .
			' loading="lazy"' .
		' data-tipo="img"' .
		'>' .
		$svg .
		mostrarBotonLightbox($rutaweb, 'image') .
		mostrarFigcaption($rutaweboriginal, $dimensiones[0] . '×' . $dimensiones[1]) .
		'</figure>';

	return $html;
}

/**
 * Obtiene el timestamp del keyframe más útil para un poster de video.
 *
 * Usa el keyframe más cercano al segundo objetivo. Se conserva como fallback
 * para videos donde no se pueda decodificar un frame normal con precisión.
 */
function obtenerTiempoKeyframePoster(string $rutaAbsoluta, float $segundoObjetivo = 1.0): float
{
	if (!FFPROBE_DISPONIBLE):
		return $segundoObjetivo;
	endif;

	$comando =
		BREW_BIN .
		'ffprobe -v error -select_streams v:0 -skip_frame nokey ' .
		'-show_entries frame=best_effort_timestamp_time,pkt_pts_time ' .
		'-of csv=p=0 ' .
		escapeshellarg($rutaAbsoluta) .
		' 2>/dev/null';
	$salida = [];
	exec($comando, $salida, $returnVar);

	if ($returnVar !== 0 || empty($salida)):
		return $segundoObjetivo;
	endif;

	$tiempos = [];
	foreach ($salida as $linea):
		foreach (explode(',', trim($linea)) as $valor):
			$valor = trim($valor);
			if ($valor !== '' && is_numeric($valor)):
				$tiempos[] = (float) $valor;
				break;
			endif;
		endforeach;
	endforeach;

	if (empty($tiempos)):
		return $segundoObjetivo;
	endif;

	sort($tiempos, SORT_NUMERIC);
	$mejor = $tiempos[0];
	foreach ($tiempos as $tiempo):
		if (abs($tiempo - $segundoObjetivo) < abs($mejor - $segundoObjetivo)):
			$mejor = $tiempo;
		endif;
	endforeach;

	return $mejor;
}

/**
 * Obtiene la duración del video para crear candidatos de poster lejos del cierre.
 */
function obtenerDuracionVideo(string $rutaAbsoluta): float
{
	if (!FFPROBE_DISPONIBLE):
		return 0.0;
	endif;

	$comando =
		BREW_BIN .
		'ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 ' .
		escapeshellarg($rutaAbsoluta) .
		' 2>/dev/null';
	$resultado = trim((string) shell_exec($comando));

	return is_numeric($resultado) ? (float) $resultado : 0.0;
}

/**
 * Comprueba si el JPG generado tiene suficiente información visual.
 */
function posterJPGEsAprovechable(string $rutaPoster): bool
{
	if (!file_exists($rutaPoster) || filesize($rutaPoster) === 0):
		return false;
	endif;
	if (!extension_loaded('gd')):
		return true;
	endif;

	$imagen = @imagecreatefromjpeg($rutaPoster);
	if (!$imagen):
		return true;
	endif;

	$ancho = imagesx($imagen);
	$alto = imagesy($imagen);
	$pasoX = max(1, (int) floor($ancho / 32));
	$pasoY = max(1, (int) floor($alto / 32));
	$suma = 0;
	$maximo = 0;
	$muestras = 0;

	for ($y = 0; $y < $alto; $y += $pasoY):
		for ($x = 0; $x < $ancho; $x += $pasoX):
			$rgb = imagecolorat($imagen, $x, $y);
			$r = ($rgb >> 16) & 0xFF;
			$g = ($rgb >> 8) & 0xFF;
			$b = $rgb & 0xFF;
			$luma = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);
			$suma += $luma;
			$maximo = max($maximo, $luma);
			$muestras++;
		endfor;
	endfor;
	if ($muestras === 0):
		return true;
	endif;

	$promedio = $suma / $muestras;

	return $promedio > 10 || $maximo > 35;
}

/**
 * Extrae un frame de video en un tiempo específico.
 */
function extraerFramePosterVideo(string $rutaVideo, string $rutaPoster, float $segundo, bool $soloKeyframe = false): bool
{
	$segundo = max(0, $segundo);
	$cmd =
		BREW_BIN .
		'ffmpeg -hide_banner -loglevel error -y -ss ' . escapeshellarg(number_format($segundo, 6, '.', '')) .
		($soloKeyframe ? ' -skip_frame nokey' : '') .
		' -i ' . escapeshellarg($rutaVideo) .
		' -frames:v 1 -q:v 2 -update 1 ' .
		escapeshellarg($rutaPoster) .
		' 2>/dev/null';
	exec($cmd);

	return file_exists($rutaPoster) && filesize($rutaPoster) > 0;
}

/**
 * Genera un poster de video evitando frames completamente negros.
 */
function generarPosterVideo(string $rutaVideo, string $rutaPoster): bool
{
	$duracion = obtenerDuracionVideo($rutaVideo);
	$limite = $duracion > 0 ? max(0, $duracion - 0.15) : 0;
	$candidatos = [1.0, 2.0];
	if ($duracion > 0):
		$candidatos[] = $duracion * 0.25;
		$candidatos[] = $duracion * 0.5;
		$candidatos[] = $duracion * 0.75;
	endif;
	$candidatos[] = 0.0;

	$tiempos = [];
	foreach ($candidatos as $tiempo):
		$tiempo = (float) $tiempo;
		if ($limite > 0):
			$tiempo = min($tiempo, $limite);
		endif;
		$clave = number_format(max(0, $tiempo), 3, '.', '');
		$tiempos[$clave] = (float) $clave;
	endforeach;

	$rutaTemporal = $rutaPoster . '.tmp.jpg';
	$rutaFallback = $rutaPoster . '.fallback.jpg';
	@unlink($rutaTemporal);
	@unlink($rutaFallback);

	foreach ($tiempos as $tiempo):
		@unlink($rutaTemporal);
		if (!extraerFramePosterVideo($rutaVideo, $rutaTemporal, $tiempo)):
			continue;
		endif;
		if (!file_exists($rutaFallback)):
			@copy($rutaTemporal, $rutaFallback);
		endif;
		if (posterJPGEsAprovechable($rutaTemporal)):
			@rename($rutaTemporal, $rutaPoster);
			@unlink($rutaFallback);
			return true;
		endif;
	endforeach;

	$keyframe = obtenerTiempoKeyframePoster($rutaVideo, 1.0);
	if ($limite > 0):
		$keyframe = min($keyframe, $limite);
	endif;
	@unlink($rutaTemporal);
	if (extraerFramePosterVideo($rutaVideo, $rutaTemporal, $keyframe, true)):
		if (!file_exists($rutaFallback)):
			@copy($rutaTemporal, $rutaFallback);
		endif;
		if (posterJPGEsAprovechable($rutaTemporal)):
			@rename($rutaTemporal, $rutaPoster);
			@unlink($rutaFallback);
			return true;
		endif;
	endif;

	if (file_exists($rutaFallback)):
		@rename($rutaFallback, $rutaPoster);
		@unlink($rutaTemporal);
		return true;
	endif;

	@unlink($rutaTemporal);
	return false;
}

/**
 * Crea una copia temporal formato JPG (caché local) extraída de fuentes más pesadas.
 * Útil para videos o imágenes en formato HEIC de iPhone que no muestran preview en navegadores.
 * Transcodifica la media utilizando ImageMagick y FFmpeg, devolviendo la ubicación nueva.
 *
 * @param string $ruta Archivo problemático/pesado en cuestión.
 * @return string Ruta relativa resultante del `.jpg` provisorio generado.
 */
function generarJPGtemporal(string $ruta): string
{
	$rutaRaiz = dirname(__DIR__);
	$rutaAbsoluta = $ruta;
	if (!str_starts_with($ruta, '/')) {
		$rutaAbsoluta = $rutaRaiz . DIRECTORY_SEPARATOR . $ruta;
	} else {
		$ruta = str_replace($rutaRaiz . DIRECTORY_SEPARATOR, '', $ruta);
	}

	$infoarchivo = pathinfo($ruta);
	$dirname = trim($infoarchivo['dirname'] ?? '', '. /');
	$prefijo = empty($dirname) ? '' : str_replace(DIRECTORY_SEPARATOR, '-', $dirname) . '-';

	$nombretemp = '.posters' . DIRECTORY_SEPARATOR . $prefijo . $infoarchivo['filename'] . '.jpg';
	$rutaAbsolutaTemp = $rutaRaiz . DIRECTORY_SEPARATOR . $nombretemp;

	if (!is_dir($rutaRaiz . DIRECTORY_SEPARATOR . '.posters')) {
		mkdir($rutaRaiz . DIRECTORY_SEPARATOR . '.posters', 0755, true);
	}

	$ext = strtolower($infoarchivo['extension'] ?? '');
	$esVideo = in_array($ext, ['mov', 'mp4', 'mkv', 'webm', 'avi', 'm4v']);
	$posterNecesitaActualizar =
		!file_exists($rutaAbsolutaTemp)
		|| filesize($rutaAbsolutaTemp) == 0
		|| filemtime($rutaAbsolutaTemp) < filemtime($rutaAbsoluta)
		|| ($esVideo && filemtime($rutaAbsolutaTemp) < filemtime(__FILE__));

	if (
		$posterNecesitaActualizar
	):
		if (in_array($ext, ['heic', 'avif', 'tiff', 'tif'])):
			exec(BREW_BIN . 'magick convert ' . escapeshellarg($rutaAbsoluta) . ' ' . escapeshellarg($rutaAbsolutaTemp));
		elseif ($esVideo):
			generarPosterVideo($rutaAbsoluta, $rutaAbsolutaTemp);
		endif;
	endif;

	if (file_exists($rutaAbsolutaTemp) && filesize($rutaAbsolutaTemp) > 0):
		return $nombretemp;
	else:
		return '';
	endif;
}
/**
 * Ensambla un DOM `<svg>` estructurado para delinear y rotular regiones de reconocimiento facial u objetos,
 * mapeados a traves de los metadatos 'Region*' preexistentes en la foto.
 * Respeta la rotación EXIF nativa (Orientation) para que las cajas coordinen visualmente.
 *
 * @param array|false $regiones Conjunto pre-filtrado de etiquetas 'Region'.
 * @param string $id Identificador base de la foto a decorar.
 * @param string $ruta Enlace del archivo, si las dimensiones precomputadas de la region fallan, se recurre a un `getimagesize()` de emergencia.
 * @param int|string $rotación Valor numérico rotacional Exif. Por defecto 1 (horizontal normal).
 * @return string|void Markup SVG listo en raw para inyectar encima de la etiqueta dom, o void si no hay regiones.
 */
function dibujarSVG($regiones, $id, $ruta, $rotación = 1)
{
	if (empty($regiones)):
		return;
	endif;

	$dimensionesArchivo = getimagesize($ruta);
	$anchoArchivo = (float) ($dimensionesArchivo[0] ?? 0);
	$altoArchivo = (float) ($dimensionesArchivo[1] ?? 0);
	if (array_key_exists('RegionAppliedToDimensionsW', $regiones) && array_key_exists('RegionAppliedToDimensionsH', $regiones)):
		$anchoCoordenadas = (float) $regiones['RegionAppliedToDimensionsW'];
		$altoCoordenadas = (float) $regiones['RegionAppliedToDimensionsH'];
	else:
		$anchoCoordenadas = $anchoArchivo;
		$altoCoordenadas = $altoArchivo;
	endif;
	if ($anchoCoordenadas <= 0 || $altoCoordenadas <= 0):
		$anchoCoordenadas = $anchoArchivo;
		$altoCoordenadas = $altoArchivo;
	endif;

	$orientacion = normalizarOrientacionExif($rotación);

	$intercambiaDimensiones = in_array($orientacion, [5, 6, 7, 8], true);
	$anchoVisual = $intercambiaDimensiones ? $altoArchivo : $anchoArchivo;
	$altoVisual = $intercambiaDimensiones ? $anchoArchivo : $altoArchivo;
	if ($anchoVisual <= 0 || $altoVisual <= 0):
		$anchoVisual = $anchoCoordenadas;
		$altoVisual = $altoCoordenadas;
	endif;

	$coordenadasYaVisuales =
		abs($anchoCoordenadas - $anchoVisual) < 1
		&& abs($altoCoordenadas - $altoVisual) < 1;
	$coordenadasParecenVisuales =
		$intercambiaDimensiones
		&& abs($anchoArchivo - $altoArchivo) >= 1
		&& $coordenadasYaVisuales;
	$debeTransformar = $orientacion !== 1 && !$coordenadasParecenVisuales;

	$svg = '';
	$i = 1;
	$rborde = '#129a00';
	$tcolor = '#ffffff';
	$tborde = '#000000';
	$rbancho = '6px';
	if (is_array($regiones) && array_key_exists('RegionName', $regiones)):
		foreach ($regiones['RegionName'] as $k => $r):
			$ralto = $regiones['RegionAreaH'][$k];
			$rancho = $regiones['RegionAreaW'][$k];
			$x = $regiones['RegionAreaX'][$k] - ($rancho / 2);
			$y = $regiones['RegionAreaY'][$k] - ($ralto / 2);
			if ($regiones['RegionAreaUnit'][$k] == 'normalized'):
				$ralto = $ralto * $altoCoordenadas;
				$rancho = $rancho * $anchoCoordenadas;
				$x = $x * $anchoCoordenadas;
				$y = $y * $altoCoordenadas;
			endif;

			if ($debeTransformar):
				$originalX = $x;
				$originalY = $y;
				$originalAncho = $rancho;
				$originalAlto = $ralto;
				switch ($orientacion):
					case 2: // Espejo horizontal
						$x = $anchoCoordenadas - ($originalX + $originalAncho);
						break;
					case 3: // Rotar 180
						$x = $anchoCoordenadas - ($originalX + $originalAncho);
						$y = $altoCoordenadas - ($originalY + $originalAlto);
						break;
					case 4: // Espejo vertical
						$y = $altoCoordenadas - ($originalY + $originalAlto);
						break;
					case 5: // Transpose
						$x = $originalY;
						$y = $originalX;
						$rancho = $originalAlto;
						$ralto = $originalAncho;
						break;
					case 6: // Rotar 90 CW
						$x = $altoCoordenadas - ($originalY + $originalAlto);
						$y = $originalX;
						$rancho = $originalAlto;
						$ralto = $originalAncho;
						break;
					case 7: // Transverse
						$x = $altoCoordenadas - ($originalY + $originalAlto);
						$y = $anchoCoordenadas - ($originalX + $originalAncho);
						$rancho = $originalAlto;
						$ralto = $originalAncho;
						break;
					case 8: // Rotar 270 CW
						$x = $originalY;
						$y = $anchoCoordenadas - ($originalX + $originalAncho);
						$rancho = $originalAlto;
						$ralto = $originalAncho;
						break;
				endswitch;
			endif;

			$nombre = htmlspecialchars((string) $regiones['RegionName'][$k], ENT_QUOTES, 'UTF-8');
			$rid = '_' . $id . '_' . $i;
			$i++;
			$svg .= '<rect id="region' . $rid . '" height="' . $ralto . '" width="' . $rancho . '" y="' . $y . '" x="' . $x . '" stroke="' . $rborde . '" stroke-width="' . $rbancho . '" fill="none"/>';
			$svg .= '<text xml:space="preserve" text-anchor="start" id="texto_' . $rid . '" font-family="monospace" font-size="24px" y="' . ($y + 16) . '" x="' . ($x + 4) . '" stroke-width="6" paint-order="stroke" stroke="' . $tborde . '" fill="' . $tcolor . '">' . $nombre . '</text>';
		endforeach;
	endif;

	$svg = '<svg width="' . $anchoVisual . '" height="' . $altoVisual . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $anchoVisual . ' ' . $altoVisual . '" preserveAspectRatio="xMinYMin" class="svg_regiones">' . $svg . '</svg>';

	return $svg;
}

function valoresRegionMetadato(array $meta, string $campo): array
{
	if (!isset($meta[$campo])):
		return [];
	endif;
	if (is_array($meta[$campo])):
		return array_values($meta[$campo]);
	endif;

	return array_map('trim', explode(',', (string) $meta[$campo]));
}

function valorRegionMetadato(array $valores, int $indice, mixed $default = ''): mixed
{
	if (array_key_exists($indice, $valores)):
		return $valores[$indice];
	endif;
	if (count($valores) === 1):
		return $valores[0];
	endif;

	return $default;
}

function numeroRegionExif(float|int|string $numero): string
{
	$numero = (float) $numero;
	if (abs($numero) < 0.000000000001):
		$numero = 0.0;
	endif;

	$texto = rtrim(rtrim(sprintf('%.14F', $numero), '0'), '.');
	return $texto === '-0' ? '0' : $texto;
}

function limitarNumero(float $valor, float $min, float $max): float
{
	return max($min, min($max, $valor));
}

/**
 * Invierte X o Y en las regiones XMP MWG, sin modificar otros metadatos del archivo.
 *
 * @return array{ok:bool, comando:string, salida:array<int,string>, codigo:int}
 */
function corregirOrientacionRegiones(string $ruta, string $eje): array
{
	$salida = [];
	if (!in_array($eje, ['horizontal', 'vertical'], true) || !is_file($ruta)):
		return ['ok' => false, 'comando' => '', 'salida' => ['Error: solicitud inválida.'], 'codigo' => 1];
	endif;

	$etiquetas = [
		'Orientation',
		'RegionAppliedToDimensionsW',
		'RegionAppliedToDimensionsH',
		'RegionAreaX',
		'RegionAreaY',
		'RegionAreaW',
		'RegionAreaH',
		'RegionAreaUnit',
		'RegionName',
		'RegionType'
	];
	$meta = obtenerMetadatos($ruta, $etiquetas)['resultado'] ?? [];
	if (!is_array($meta)):
		$meta = [];
	endif;

	$orientacion = normalizarOrientacionExif($meta['Orientation'] ?? 1);
	if ($orientacion <= 1):
		return ['ok' => false, 'comando' => '', 'salida' => ['Error: el archivo no tiene rotación/orientación EXIF que corregir.'], 'codigo' => 1];
	endif;

	$nombres = valoresRegionMetadato($meta, 'RegionName');
	$xs = valoresRegionMetadato($meta, 'RegionAreaX');
	$ys = valoresRegionMetadato($meta, 'RegionAreaY');
	$anchos = valoresRegionMetadato($meta, 'RegionAreaW');
	$altos = valoresRegionMetadato($meta, 'RegionAreaH');
	$unidades = valoresRegionMetadato($meta, 'RegionAreaUnit');
	$tipos = valoresRegionMetadato($meta, 'RegionType');
	$total = max(count($nombres), count($xs), count($ys), count($anchos), count($altos));
	if ($total <= 0 || empty($xs) || empty($ys) || empty($anchos) || empty($altos)):
		return ['ok' => false, 'comando' => '', 'salida' => ['Error: no se encontraron regiones completas.'], 'codigo' => 1];
	endif;

	$dimensionesArchivo = getimagesize($ruta);
	$anchoCoordenadas = (float) ($meta['RegionAppliedToDimensionsW'] ?? $dimensionesArchivo[0] ?? 0);
	$altoCoordenadas = (float) ($meta['RegionAppliedToDimensionsH'] ?? $dimensionesArchivo[1] ?? 0);
	if ($anchoCoordenadas <= 0 || $altoCoordenadas <= 0):
		return ['ok' => false, 'comando' => '', 'salida' => ['Error: no se pudieron determinar las dimensiones aplicadas de la región.'], 'codigo' => 1];
	endif;

	$regionList = [];
	for ($i = 0; $i < $total; $i++):
		$nombre = trim((string) valorRegionMetadato($nombres, $i, ''));
		$x = (float) valorRegionMetadato($xs, $i, 0);
		$y = (float) valorRegionMetadato($ys, $i, 0);
		$ancho = (float) valorRegionMetadato($anchos, $i, 0);
		$alto = (float) valorRegionMetadato($altos, $i, 0);
		$unidad = trim((string) valorRegionMetadato($unidades, $i, 'normalized'));
		$tipo = trim((string) valorRegionMetadato($tipos, $i, 'Face'));
		if ($nombre === '' || $ancho <= 0 || $alto <= 0):
			continue;
		endif;

		if (mb_strtolower($unidad, 'UTF-8') === 'normalized'):
			if ($eje === 'horizontal'):
				$x = limitarNumero(1 - $x, 0, 1);
			else:
				$y = limitarNumero(1 - $y, 0, 1);
			endif;
		else:
			if ($eje === 'horizontal'):
				$x = limitarNumero($anchoCoordenadas - $x, 0, $anchoCoordenadas);
			else:
				$y = limitarNumero($altoCoordenadas - $y, 0, $altoCoordenadas);
			endif;
		endif;

		$regionList[] =
			'{Area={W=' . numeroRegionExif($ancho) .
			', H=' . numeroRegionExif($alto) .
			', X=' . numeroRegionExif($x) .
			', Y=' . numeroRegionExif($y) .
			', Unit=' . $unidad .
			'}, Name=' . $nombre .
			', Type=' . ($tipo !== '' ? $tipo : 'Face') .
			'}';
	endfor;

	if (empty($regionList)):
		return ['ok' => false, 'comando' => '', 'salida' => ['Error: no quedaron regiones válidas para escribir.'], 'codigo' => 1];
	endif;

	$regionInfo =
		'{AppliedToDimensions={W=' . numeroRegionExif($anchoCoordenadas) .
		',H=' . numeroRegionExif($altoCoordenadas) .
		',Unit=pixel}, RegionList=[' . implode(', ', $regionList) . ']}';
	$comando = BREW_BIN . 'exiftool -P -overwrite_original -charset utf8 ' .
		escapeshellarg('-XMP-mwg-rs:RegionInfo=' . $regionInfo) . ' ' .
		escapeshellarg($ruta);
	exec($comando, $salida, $codigo);

	return [
		'ok' => $codigo === 0,
		'comando' => $comando,
		'salida' => $salida,
		'codigo' => $codigo
	];
}
/**
 * Parsea a partir de un string irregular la fecha y hora.
 * Esta cadena usualmente se extrae de un metadato de fecha, y el método utiliza regex básicos/offsets
 * para decodificar su tupla.
 *
 * @param string $texto Texto originar recuperado de propiedades Exif/XMP (Ej: 2024-08-12T10-11-03...).
 * @return array Una tupla estructurada: index 0 (string YYYY-MM-DDTHH:MM:SS), index 1 (string timezone offset).
 */
function devolverFecha(string $texto): array
{
	if (empty($texto)):
		return ['', ''];
	endif;

	$fecha = [
		'año' => '',
		'mes' => '',
		'día' => '',
		'hora' => '',
		'minuto' => '',
		'segundo' => '',
	];
	$zona = '';
	$zonaDefault = '-0600';
	if (str_ends_with(substr($texto, 0, 11), 'T')):
		// 2024-08-12T10-11-03 Anna Bridget Barrett.mp4
		// 2024-08-12T09-23-10.000Z Mieun Jo.mp4
		// 2024-12-12T13-51-00-0600 Karla Cejas Gutiérrez.mp4
		$fecha['año'] = substr($texto, 0, 4) . '-';
		$fecha['mes'] = substr($texto, 5, 2) . '-';
		$fecha['día'] = substr($texto, 8, 2) . 'T';
		$fecha['hora'] = substr($texto, 11, 2) . ':';
		$fecha['minuto'] = substr($texto, 14, 2) . ':';
		$fecha['segundo'] = substr($texto, 17, 2);
		if (substr($texto, 19, 1) == '-'):
			$zona = substr($texto, 19, 5);
		elseif (substr($texto, 23, 1) == 'Z'):
			$zona = '-0000';
		else:
			$zona = $zonaDefault;
		endif;
	elseif (str_ends_with(substr($texto, 0, 11), ' ')):
		// 2024-08-12 Mieun Jo.mp4
		$fecha['año'] = substr($texto, 0, 4) . '-';
		$fecha['mes'] = substr($texto, 5, 2) . '-';
		$fecha['día'] = substr($texto, 8, 2) . 'T';
		$fecha['hora'] = '00:';
		$fecha['minuto'] = '00:';
		$fecha['segundo'] = '00';
		$zona = '-0000';
	elseif (str_ends_with(substr($texto, 0, 9), '_')):
		// 20251007_225444-4206013910-cyberillustrious_v40-DPM++ 2M-25
		$fecha['año'] = substr($texto, 0, 4) . '-';
		$fecha['mes'] = substr($texto, 4, 2) . '-';
		$fecha['día'] = substr($texto, 6, 2) . 'T';
		$fecha['hora'] = substr($texto, 9, 2) . ':';
		$fecha['minuto'] = substr($texto, 11, 2) . ':';
		$fecha['segundo'] = substr($texto, 13, 2);
		$zona = $zonaDefault;
	endif;

	return [implode('', $fecha), $zona];
}
/**
 * Orquesta la creación del bloque contenedor principal (`<article>`) para archivos multimedia en la vista.
 * Lee metadatos (EXIF, XMP, IA), sugiere valores, genera el Markup de formulario para actualización,
 * y delega el renderizado de la previa (imagen o video) a otros métodos auxiliares.
 *
 * @param string $ruta Ubicación del archivo fuente en el sistema temporal o definitivo.
 * @param string|int $id Identificador correlativo usado para entrelazar el DOM con JavaScript.
 * @param string $tipo Tipo de medio a renderizar ('img' o 'vid'). Por defecto 'img'.
 * @return string|false Componente `<article>` con todo el panel de administración, o FALSE en error.
 */
function crearBloque($ruta, $id, $tipo = 'img')
{
	if (empty($ruta) || !is_file($ruta)):
		return FALSE;
	endif;

	$regiones = FALSE;
	$clasesarticle = [];
	$sugerido = [];

	// Metadatos
	$salidaExiftool = obtenerMetadatos($ruta)['resultado'] ?? [];

	$meta = [];
	$regiones = [];
	foreach ($salidaExiftool as $k => $s):
		if (str_starts_with($k, 'Region')):
			if (stripos($k, 'AppliedTo') === FALSE):
				$regiones[$k] = array_map('trim', explode(',', $s));
			else:
				$regiones[$k] = $s;
			endif;
		elseif ($k == 'DateTimeOriginal'):
			// 19 o 25 = OK
			if (
				strlen($s) != 19
				&& strlen($s) != 25
			):
				//echo '<pre>$s: '.$s.'</pre>';
				$sugerido[] = 'createDate';
				$info = explode(' ', $s);
				$fecha = $info[0];
				$tiempo = explode('-', $info[1]);
				$meta[$k] = $fecha . ' ' . $tiempo[0] . ':00';
				if (array_key_exists(1, $tiempo)):
					$meta[$k] .= '-' . $tiempo[1];
				endif;
			else:
				$meta[$k] = $s;
			endif;
		else:
			$meta[$k] = $s;
		endif;
	endforeach;
	//$html .= '<pre>'.var_dump_pre($meta, 'meta').'</pre>';
	//return $html.'</article>';
	// Subject
	$subject = $nsfw = '';
	if (isset($meta['Subject'])):
		$subject = $meta['Subject'];
		// Colorear NSFW
		if (strpos($meta['Subject'], '+18') !== FALSE):
			$nsfw = ' style="background-color:#FF000018;"';
		endif;
	endif;
	if (isset($meta['Keyword'])):
		if (empty($subject)):
			$subject = $meta['Keyword'];
		else:
			$subject .= ', ' . $meta['Keyword'];
		endif;
		$sugerido[] = 'Subject';
	endif;
	if (isset($meta['Keywords'])):
		if (empty($subject)):
			$subject = $meta['Keywords'];
		else:
			$subject .= ', ' . $meta['Keywords'];
		endif;
		$sugerido[] = 'Subject';
	endif;
	$palabrasClaveDuplicadas = tienePalabrasClaveDuplicadas($subject);

	//PNG:PARAMETERS (SD)
	global $nombreModelo;
	$parámetrosIA = '';
	$tieneMetadatosIA = false;
	if (isset($meta['Parameters'])):
		$tieneMetadatosIA = true;
		$parámetrosIA .= parámetrosSD($meta['Parameters']);
	endif;

	//InvokeAI
	if (
		isset($meta['Invokeai_metadata'])
		|| isset($meta['Invokeai_graph'])
		|| isset($meta['Invokeai_workflow'])
	):
		$tieneMetadatosIA = true;
		$parámetrosIA .= parámetrosInvokeAI($meta);
	endif;

	//POMPT (ComfyUI)
	if (isset($meta['Prompt'])):
		$tieneMetadatosIA = true;
		$parámetrosIA .= parámetrosCUI($meta['Prompt'], $meta['Workflow'] ?? null);
	endif;

	// Rotación
	$orientacion = '0';
	if (isset($meta['Rotation'])):
		$orientacion = $meta['Rotation'];
	elseif (isset($meta['Orientation'])):
		$orientacion = $meta['Orientation'];
	endif;
	if (!empty($orientacion)):
		$clasesarticle[] = 'rotacion_' . $orientacion;
	endif;


	if (!empty($clasesarticle)):
		$clasesarticle = ' class="' . implode(' ', $clasesarticle) . '"';
	else:
		$clasesarticle = '';
	endif;
	$tieneGeolocalizacion = isset($meta['GPSLatitude'], $meta['GPSLongitude']);
	$html = '<article id="art_' . $id . '" data-panel-id="pie_' . $id . '" tabindex="0"' . $clasesarticle . $nsfw . '>';

	if ($tipo == 'img'):
		//$html .= '<details><summary>~~~REGIONES~~~~</summary>'.var_dump_pre($regiones).'</details>';
		if (!empty($regiones)):
			$svg = dibujarSVG($regiones, $id, $ruta, $orientacion);
		else:
			$svg = '';
		endif;

		$imageSize = $meta['ImageSize'] ?? '';
		if (
			empty($imageSize)
			&& array_key_exists('ImageWidth', $meta)
		):
			$imageSize = $meta['ImageWidth'] . 'x' . ($meta['ImageHeight'] ?? '');
		endif;
		$html .= mostrarImagen($ruta, $id, $imageSize, $svg);
	elseif ($tipo == 'vid'):
		$html .= mostrarVideo($ruta, $id);
		$resultado = '';
	endif;
	$rutaLocalAttr = htmlspecialchars((string) $ruta, ENT_QUOTES, 'UTF-8');
	$html .=
		'</article>' .
		'<section id="pie_' . $id . '" class="panel-articulo" data-metadatos-ia="' . ($tieneMetadatosIA ? '1' : '0') . '" data-geolocalizacion="' . ($tieneGeolocalizacion ? '1' : '0') . '" data-palabras-duplicadas="' . ($palabrasClaveDuplicadas ? '1' : '0') . '" hidden>' .
		'<div class="ruta-local-contenedor">' .
		'<input type="text" value="' . $rutaLocalAttr . '" class="ruta_local" disabled>' .
		'<button type="button" id="abrir_archivo_' . $id . '" class="ruta-local-abrir" onclick="abrirArchivo(\'' . $id . '\')" title="Abrir archivo" aria-label="Abrir archivo">↗</button>' .
		'</div>' .
		'<form id="formulario_' . $id . '">';
	// Fecha de creación
	// 2024-08-12T10-11-03 Anna Bridget Barrett.mp4
	// 2024-08-12T09-23-10.000Z Mieun Jo.mp4
	// 2024-08-12 Mieun Jo.mp4
	// 20251007_225444-4206013910-cyberillustrious_v40-DPM++ 2M-25
	list($createDate, $zonaHoraria) = devolverFecha(basename($ruta));

	$copyrightdefault = '';
	$crtmp = '';
	$fechaArchivo = '';
	if (isset($meta['DateTimeOriginal'])):
		$crtmp = substr($meta['DateTimeOriginal'], 0, 4);
		$fechaArchivo = $meta['DateTimeOriginal'];

		//$html .= '<pre><small>CD<br>'.$createDate.'</small></pre>';
		//$html .= '<div style="font-family:monospace;font-size:.6em;">DTO '.$meta['DateTimeOriginal'].'</div>';
	elseif (!empty($createDate)):
		$crtmp = substr($createDate, 0, 4);
		$sugerido[] = 'createDate';
		$sugerido[] = 'Offset';
	elseif (isset($meta['FileModifyDate'])):
		$crtmp = substr($meta['FileModifyDate'], 0, 4);
		$fechaArchivo = $meta['FileModifyDate'];
		$sugerido[] = 'createDate';
		$sugerido[] = 'Offset';
	endif;


	if (!empty($fechaArchivo)):
		$tmp = explode(' ', $fechaArchivo);
		$tmp[0] = str_replace(':', '-', $tmp[0]);
		$tmpZA = substr($tmp[1], 8);
		$tmp[1] = substr($tmp[1], 0, 8);
		$fechaArchivo = implode('T', $tmp);
		if (empty($createDate)):
			$createDate = $fechaArchivo;
		else:
			$createDate = ($createDate < $fechaArchivo) ? $createDate : $fechaArchivo;
		endif;
		if (empty($zonaHoraria)):
			$zonaHoraria = $tmpZA;
		endif;
	endif;

	if (is_numeric($crtmp)):
		$copyrightdefault = '©' . $crtmp . ' ';
		$crntmp = explode(' ', pathinfo($ruta)['filename']);
		unset($crntmp[0]);
		if (!empty($crntmp)):
			if (is_array($crntmp)):
				foreach ($crntmp as $k => $v):
					if (
						str_starts_with($v, 'IMG_')
						|| str_starts_with($v, 'photo')
					):
						unset($crntmp[$k]);
					endif;
					if (str_ends_with($v, '_source')):
						unset($crntmp[$k]);
					endif;
					if (str_ends_with($v, ')')):
						$v = preg_replace('/\(\d+\)$/', '', $v);
						$crntmp[$k] = $v;
					endif;
				endforeach;
			endif;
			$nombreabuscar = implode(' ', $crntmp);
			$nombresSugeridos = obtenerNombresPorUsuario($nombreabuscar);
			if (!empty($nombresSugeridos)):
				$copyrightdefault .= implode(' ', $nombresSugeridos);
				if (!isset($meta['Parameters'])):
					if (!$subject):
						$subject = '' . implode(', ', $nombresSugeridos) . ', ';
						$sugerido[] = 'Subject';
					elseif (in_array('Subject', $sugerido)):
						$subject = implode(', ', $nombresSugeridos) . ', ' . $subject;
					endif;
				endif;
			else:
				$copyrightdefault .= $nombreabuscar;
				if (!$subject && !isset($meta['Parameters'])):
					if (!str_starts_with($nombreabuscar, 'cosplay')):
						$subject = '' . $nombreabuscar . ', ';
					else:
						$subject = 'cosplay, ';
					endif;
					$sugerido[] = 'Subject';
				endif;
			endif;
		endif;
	endif;

	if (!$subject):
		if (isset($meta['Parameters'])):
			$tmp = explode('Model:', $meta['Parameters']);

			if (isset($tmp[1])):
				$tmp = explode(',', $tmp[1])[0];
				$tmp = trim($tmp);
				// Separar versión
				$tmp = explode('_', $tmp, 2);
				if (
					isset($tmp[1])
					&& !str_starts_with($tmp[1], 'v')
				):
					$tmp[1] = 'v' . $tmp[1];
				endif;
				$subject = 'ai, ' . implode(', ', $tmp) . ', ';
			else:
				$subject = 'ai, grid, ';
			endif;
			$sugerido[] = 'Subject';
		elseif (
			isset($meta['Invokeai_metadata'])
			|| isset($meta['Invokeai_graph'])
			|| isset($meta['Invokeai_workflow'])
		):
			if ($nombreModelo):
				// Separar versión
				$tmp = explode('_', $nombreModelo, 2);
				if (
					isset($tmp[1])
					&& !str_starts_with($tmp[1], 'v')
				):
					$tmp[1] = 'v' . $tmp[1];
				endif;
				$subject = 'ai, ' . implode(', ', $tmp) . ', ';
			else:
				$subject = 'ai, ' . json_encode($nombreModelo) . ', ';
			endif;
			$sugerido[] = 'Subject';
		elseif (isset($meta['Prompt'])):
			// ComfyUI
			if ($nombreModelo):
				$subject = 'ai, ' . pathinfo($nombreModelo)['filename'] . ', ';
			endif;
		endif;
	endif;

	// GEO
	$txtgeo = '';
	$locationval = '';
	if (!empty($meta['Location'])):
		$locationval = $meta['Location'];
	endif;
	if ($tieneGeolocalizacion):
		$txtgeo .= '<div class="metadata-geo" style="font-family:monospace;font-size:.8rem;background-color:#0002;padding:.25em;">';
		if (!empty($locationval)):
			$txtgeo .= '<b>' . htmlspecialchars($locationval, ENT_QUOTES, 'UTF-8') . '</b><br>';
		endif;
		$txtgeo .=
			'<span style="user-select:none">📍 </span>' .
			htmlspecialchars((string) $meta['GPSLatitude'], ENT_QUOTES, 'UTF-8') . ',' .
			htmlspecialchars((string) $meta['GPSLongitude'], ENT_QUOTES, 'UTF-8');
		if (isset($meta['City'])):
			$txtgeo .= '<br>🗺️ ' . htmlspecialchars((string) $meta['City'], ENT_QUOTES, 'UTF-8');
		endif;
		if (isset($meta['State'])):
			$txtgeo .= ', ' . htmlspecialchars((string) $meta['State'], ENT_QUOTES, 'UTF-8');
		endif;
		if (isset($meta['Country'])):
			$txtgeo .= '<br>' .
				banderaDesdeCountryCode($meta['CountryCode'] ?? '') . ' ' .
				htmlspecialchars((string) $meta['Country'], ENT_QUOTES, 'UTF-8');
		endif;
		$txtgeo .= '</div>';
	endif;

	$camposTracking = ['SpecialInstructions', 'Instructions', 'IPTCDigest'];
	foreach ($camposTracking as $campoTracking):
		if (isset($meta[$campoTracking])):
			$html .= advertenciaMetadatosHtml($campoTracking, $meta[$campoTracking]);
		endif;
	endforeach;

	// Fecha, hora
	$clase = '';
	if (in_array('createDate', $sugerido)):
		$clase = ' class="sugerido"';
	endif;
	// Media Created Date (videos de series y películas)
	if (isset($meta['ContentCreateDate'])):
		$html .= '<fieldset style="font-size:.8rem;margin:0;padding:.25rem;"><legend style="font-weight:900;">Content Create Date</legend>' . escaparHtml($meta['ContentCreateDate']) . '</fieldset>';
	endif;
	$html .=
		'<input' .
		' type="datetime-local"' .
		' id="createdate_' . $id . '"' .
		' name="createdate"' .
		' value="' . escaparHtml($createDate) . '"' .
		$clase .
		' style="display:inline-block;"' .
		'>';
	// Offset
	$clase = '';
	if (in_array('Offset', $sugerido)):
		$clase = ' class="sugerido"';
	endif;
	$html .=
		' <input' .
		' type="text"' .
		' id="offsettime_' . $id . '"' .
		' name="offsettime"' .
		' value="' . escaparHtml($zonaHoraria) . '"' .
		$clase .
		' style="display:inline-block;width:7em;"' .
		' placeholder="-0000"' .
		'>';


	$html .=
		'<input' .
		' type="text"' .
		' id="title_' . $id . '"' .
		' name="title"' .
		' value="' . escaparHtml($meta['Title'] ?? '') . '"' .
		' placeholder="Título"' .
		'>';

	// Unificar notas de usuario: Description, UserComment, ImageDescription
	$notas = [];
	if (isset($meta['Description'])):
		$notas[] = $meta['Description'];
	endif;
	if (isset($meta['UserComment'])):
		$notas[] = $meta['UserComment'];
	endif;
	if (isset($meta['ImageDescription'])):
		$notas[] = $meta['ImageDescription'];
	endif;
	$notas = array_unique($notas);
	$html .=
		'<b>Descripción:</b>' .
		'<textarea' .
		' id="dsc_' . $id . '"' .
		' name="description"' .
		' class="descripción ancho100"' .
		'>' . escaparHtml(implode(' | ', $notas)) .
		'</textarea>';

	$subject = formatearPalabrasClave($subject);

	// Palabras clave (Subject)
	$clases = ['subject', 'ancho100'];
	if (in_array('Subject', $sugerido)):
		$clases[] = 'sugerido';
	endif;
	if ($palabrasClaveDuplicadas):
		$clases[] = 'requiere-guardar';
	endif;
	$html .=
		'<b>Palabras clave:</b>' .
		'<textarea' .
		' id="txt_' . $id . '"' .
		' name="subject"' .
		' class="' . implode(' ', $clases) . '"' .
		($palabrasClaveDuplicadas ? ' title="Guardar de nuevo para eliminar palabras clave duplicadas"' : '') .
		'>' . escaparHtml($subject) .
		'</textarea>';

	// Copyright
	if (isset($meta['Copyright'])):
		$copyright = $meta['Copyright'];
	elseif (isset($meta['Parameters'])):
		$copyright = '';
	else:
		$copyright = $copyrightdefault;
		$sugerido[] = 'Copyright';
	endif;
	$clase = '';
	if (in_array('Copyright', $sugerido)):
		$clase = ' class="sugerido"';
	endif;
	$html .=
		'<input' .
		' type="text"' .
		' id="copyright_' . $id . '"' .
		' name="copyright"' .
		' value="' . escaparHtml($copyright) . '"' .
		' placeholder="Copyright"' .
		$clase .
		'>';

	// Nombre de ubicación
	$html .=
		'<input' .
		' type="text"' .
		' id="location_' . $id . '"' .
		' name="location"' .
		' value="' . escaparHtml($locationval) . '"' .
		' placeholder="Nombre de ubicación"' .
		' list="Ubicaciones"' .
		'>';

	$html .= $txtgeo;
	$html .=
		'<select' .
		' id="orientacion_' . $id . '"' .
		' name="orientation"' .
		'>';
		$orientaciones = opcionesOrientacionFormulario($ruta, $tipo, $orientacion);
		$orientacionSeleccionada = valorOrientacionFormulario($orientacion, $tipo);
		foreach ($orientaciones as $v => $o):
			$sel = ((int) $v === $orientacionSeleccionada) ? ' selected' : '';
			$html .= '<option value="' . (int) $v . '"' . $sel . '>[' . (int) $v . '] ' . escaparHtml($o) . '</option>';
		endforeach;
	$html .=
		'</select>';

	// Parámetro extendido (macOS)
	$metaExtendido = leerMetadatoExtendido($ruta);
	$redSocial = $xattr = '';
	$notasTexto = implode(',', $notas);
	$camposOrigen = [
		$notasTexto,
		$meta['SpecialInstructions'] ?? '',
		$meta['Instructions'] ?? '',
		$meta['Source'] ?? '',
		$meta['Credit'] ?? '',
		$meta['URL'] ?? '',
	];
	$redSocial = detectarRedSocialEnTexto(implode(' ', $camposOrigen));
	$redSocialXattr = detectarRedSocialEnTexto($metaExtendido);
	if (
		!empty($metaExtendido)
		&& !str_contains($metaExtendido, '(null)')
	):
		$metaExtendido = trim($metaExtendido, '" ');
		if (!empty($metaExtendido)):
			$claseXattr = 'fuente metadata-xattr';
			$resumenXattr = 'kMDItemWhereFroms (xattr)';
			if (!empty($redSocialXattr)):
				$resumenXattr .= ' · ' . $redSocialXattr;
			endif;
			$xattr =
				'<details class="' . $claseXattr . '" open>' .
				'<summary>' . htmlspecialchars($resumenXattr, ENT_QUOTES, 'UTF-8') . '</summary>' .
				'<div>' .
				htmlspecialchars(trim($metaExtendido, '"'), ENT_QUOTES, 'UTF-8') .
				'</div>' .
				'</details>';
		endif;
	endif;
	if (
		!empty($redSocial)
	):
		$html .= advertenciaMetadatosHtml('Origen social detectado', $redSocial);
	endif;


	$invokeAI = false;
	if (
		isset($meta['Invokeai_metadata'])
		|| isset($meta['Invokeai_graph'])
		|| isset($meta['Invokeai_workflow'])
	):
		$invokeAI = true;
	endif;
	$stableDiffusion = false;
	if (isset($meta['Parameters'])):
		$stableDiffusion = true;
	endif;
	$comfyUI = false;
	if (isset($meta['Prompt'])):
		$comfyUI = true;
	endif;
	$redSocialSugerida = $redSocial ?: $redSocialXattr;
	$otroscampos = ['Make', 'Model', 'Software'];
	$html .= '<fieldset class="otros-campos" open><legend>Otros campos</legend>';
	foreach ($otroscampos as $campo):
		$valorActualCampo = isset($meta[$campo]) ? trim((string) $meta[$campo]) : '';
		if ($valorActualCampo !== ''):
			$html .=
				'<div><b><small>' . $campo . ':</small></b>' .
				'<input' .
				' type="text"' .
				' name="' . strtolower($campo) . '"' .
				' id="' . strtolower($campo) . '_' . $id . '"' .
				' value="' . htmlspecialchars((string) $meta[$campo], ENT_QUOTES, 'UTF-8') . '"' .
				' list="lista-' . $campo . '"' .
				' autocomplete="off"' .
				'></div>';
		else:
			$valor = '';
			$sugerido = '';
			if ($invokeAI):
				if ($campo == 'Software'):
					$IAIVersion = 'InvokeAI';
					if (isset($meta['Invokeai_metadata'])):
						$todoslosdatos = json_decode($meta['Invokeai_metadata'], true);
						if (isset($todoslosdatos['app_version'])):
							$IAIVersion .= ' ' . $todoslosdatos['app_version'];
						endif;
					endif;
					$valor = ' value="' . escaparHtml($IAIVersion) . '"';
					$sugerido = ' class="sugerido"';
				elseif ($campo == 'Model'):
					$valor = ' value="' . escaparHtml($nombreModelo ?? '') . '"';
					$sugerido = ' class="sugerido"';
				endif;
			elseif ($stableDiffusion):
				if ($campo == 'Software'):
					$SDVersion = explode('Version: ', $meta['Parameters']);

					if (isset($SDVersion[1])):
						$SDVersion = explode(',', $SDVersion[1])[0];
						$SDVersion = trim($SDVersion);
						$valor = ' value="' . escaparHtml('Automatic1111 ' . $SDVersion) . '"';
						$sugerido = ' class="sugerido"';
					else:
					endif;
				elseif ($campo == 'Model'):
					$valor = ' value="' . escaparHtml($nombreModelo ?? '') . '"';
					$sugerido = ' class="sugerido"';
				endif;
			elseif ($comfyUI):
				if ($campo == 'Software'):
					$valor = ' value="ComfyUI"';
					$sugerido = ' class="sugerido"';
				elseif ($campo == 'Model'):
					$valor = ' value="' . escaparHtml($nombreModelo ?? '') . '"';
					$sugerido = ' class="sugerido"';
				endif;
			elseif (!empty($redSocialSugerida)):
				if ($campo == 'Make'):
					$valor = ' value="Red social"';
					$sugerido = ' class="sugerido"';
				elseif ($campo == 'Model'):
					$valor = ' value="' . htmlspecialchars($redSocialSugerida, ENT_QUOTES, 'UTF-8') . '"';
					$sugerido = ' class="sugerido"';
				endif;
			endif;
			$html .=
				'<input' .
				' type="text"' .
				' id="' . strtolower($campo) . '_' . $id . '"' .
				' name="' . strtolower($campo) . '"' .
				$valor .
				' placeholder="' . $campo . '"' .
				' list="lista-' . $campo . '"' .
				' autocomplete="off"' .
				$sugerido .
				'>';
		endif;
	endforeach;
	$html .= '</fieldset>';
	$orientacionCorregible = normalizarOrientacionExif($orientacion);
	$tieneRegionesCorregibles =
		$tipo == 'img'
		&& $orientacionCorregible > 1
		&& isset(
			$regiones['RegionName'],
			$regiones['RegionAreaX'],
			$regiones['RegionAreaY'],
			$regiones['RegionAreaW'],
			$regiones['RegionAreaH']
		);
	// Botones
	$html .=
		'<div class="botones">' .
		'<button type="button" id="btn_' . $id . '" onclick="guardar(\'' . $id . '\')" title="Guardar datos">💾</button>';
	if ($tipo == 'img'):
		$html .=
			' <a href="caras.php?foto=' . urlencode(str_replace(dirname(__DIR__) . '/', '', $ruta)) . '" title="Etiquetar personas">🧑🏽</a>';
		if ($tieneRegionesCorregibles):
			$html .=
				' <button type="button" id="region_horizontal_' . $id . '" onclick="corregirRegiones(\'' . $id . '\', \'horizontal\')" title="Corregir regiones por espejo horizontal" aria-label="Corregir regiones por espejo horizontal">↔️</button>' .
				' <button type="button" id="region_vertical_' . $id . '" onclick="corregirRegiones(\'' . $id . '\', \'vertical\')" title="Corregir regiones por espejo vertical" aria-label="Corregir regiones por espejo vertical">↕️</button>';
		endif;
		if (!str_ends_with(strtolower($ruta), '.webp')):
			$html .=
				' <button type="button" id="convertir_' . $id . '" onclick="convertir(\'' . $id . '\')" title="Convertir a WebP">🔁</button>';
		endif;
	elseif ($tipo == 'vid'):
		$html .=
			' <button type="button" id="extraer_' . $id . '" onclick="extraer(\'' . $id . '\')" title="Extraer imagen">⚙️</button>';
	endif;
	$html .=
		' <button type="button" id="blisto_' . $id . '" onclick="mover(\'' . $id . '\', \'archivar\')" title="Archivar">🗃️</button>' .
		' — <button type="button" id="bborra_' . $id . '" onclick="mover(\'' . $id . '\', \'borrar\')" title="Descartar">🗑️</button>' .
		'</div>';//.botones

	// Mensaje de respuesta
	$html .=
		'<div id="respuesta_' . $id . '" class="respuestas"></div>';

	$html .= $xattr;

	$html .= $parámetrosIA;

	// Exif
	$resalta = [
		'Parameters' => 'FFFF6b',
		'Invokeai_metadata' => 'FFFF6b',
		'Invokeai_workflow' => 'FF6B6B',
		'Invokeai_graph' => '6bFF6B'
	];

	$html .=
		'<details class="exiftool">' .
		'<summary>exiftool</summary>' .
		'<pre>';
	foreach ($meta as $k => $v):
		$clave = htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8');
		if (array_key_exists($k, $resalta)):
			$clave = '<span style="color:#' . $resalta[$k] . ';">' . $clave . '</span>';
		endif;
		if (is_array($v)):
			$v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		endif;
		$html .= $clave . ': ' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . "\n";
	endforeach;
	$html .=
		'</pre>' .
		'</details>' .
		'</form>' .
		'</section>';

	return $html;
}

/**
 * Crea un iterador recursivo para el sistema de archivos o aisla un único archivo en la iteración.
 * Útil para tareas de procesamiento masivo evitando cargar todo en memoria.
 *
 * @param string $baseDir Directorio raíz donde comenzar la búsqueda.
 * @param string|null $archivo Nombre de archivo opcional si se requiere aislar.
 * @param array $ignoreDirs Diretorios a ignorar/saltar en el proceso.
 * @return RecursiveIteratorIterator Objeto nativo de SPL preparado para itarar hojas.
 */
function obtenerIteradorArchivos(
	string $baseDir,
	?string $archivo = null,
	array $ignoreDirs = []
): RecursiveIteratorIterator {
	$baseReal = realpath($baseDir);
	if ($baseReal === false):
		throw new RuntimeException('Directorio base inválido.');
	endif;
	$baseDir = rtrim($baseReal, DIRECTORY_SEPARATOR);

	// ─────────────────────────────
	// MODO: UN SOLO ARCHIVO
	// ─────────────────────────────
	if ($archivo):
		$archivo = trim(str_replace('\\', '/', $archivo));
		$candidata = str_starts_with($archivo, '/')
			? $archivo
			: $baseDir . DIRECTORY_SEPARATOR . ltrim($archivo, '/');
		$ruta = realpath($candidata);

		if (!$ruta || !rutaDentroDeDirectorio($ruta, $baseDir) || !is_file($ruta)):
			// En caso de que se ingrese una ruta inválida o el archivo no exista,
			// mostrar la ruta principal por defecto (ignorando la búsqueda de este archivo)
			$archivo = null;
		else:
			$spl = new SplFileInfo($ruta);
			$single = new SingleFileRecursiveIterator($spl);
			return new RecursiveIteratorIterator($single, RecursiveIteratorIterator::LEAVES_ONLY);
		endif;
	endif;

	// ─────────────────────────────
	// MODO: RECORRER DIRECTORIO
	// ─────────────────────────────
	$dir = new RecursiveDirectoryIterator(
		$baseDir,
		RecursiveDirectoryIterator::SKIP_DOTS
	);

	$filter = new RecursiveCallbackFilterIterator(
		$dir,
		function ($current, $key, $iterator) use ($ignoreDirs) {

			if ($current->isDir() && in_array($current->getFilename(), $ignoreDirs, true)):
				return false;
			endif;

			return true;
		}
	);

	return new RecursiveIteratorIterator($filter);
}

/**
 * Devuelve los archivos multimedia visibles para una ruta con el mismo orden usado
 * por la vista principal.
 *
 * @param string $rutaIterador Directorio base a recorrer.
 * @param string|null $unarchivo Archivo específico si la vista se limita a uno.
 * @param array $omitir Directorios a ignorar durante el recorrido.
 * @param string|null $media Filtro opcional: 'fotos', 'videos' o null para usar GET/constantes.
 * @return array Lista ordenada descendente por fecha: [ruta, tipo].
 */
function obtenerResultadosMultimedia(
	string $rutaIterador,
	?string $unarchivo,
	array $omitir,
	?string $media = null
): array {
	$filtrarFotos = false;
	$filtrarVideos = false;

	if ($media === 'fotos'):
		$filtrarFotos = true;
	elseif ($media === 'videos'):
		$filtrarVideos = true;
	elseif ($media === null):
		$filtrarFotos = defined("FOTOS") && FOTOS;
		$filtrarVideos = defined("VIDEOS") && VIDEOS;
	endif;

	$incluirFotos = $filtrarFotos || (!$filtrarFotos && !$filtrarVideos);
	$incluirVideos = $filtrarVideos || (!$filtrarFotos && !$filtrarVideos);
	$resultados = [];
	$iterador = obtenerIteradorArchivos($rutaIterador, $unarchivo, $omitir);

	foreach ($iterador as $archivo):
		if (!$archivo->isFile()):
			continue;
		endif;

		switch (strtolower($archivo->getExtension())):
			case 'jpg':
			case 'jpeg':
			case 'webp':
			case 'png':
			case 'heic':
				if ($incluirFotos):
					$idx = filemtime($archivo->getPathname());
					while (array_key_exists($idx, $resultados)):
						$idx += 1;
					endwhile;
					$resultados[$idx] = [$archivo->getPathname(), 'img'];
				endif;
				break;
			case 'mp4':
			case 'mov':
				if ($incluirVideos):
					$idx = filemtime($archivo->getPathname());
					while (array_key_exists($idx, $resultados)):
						$idx += 1;
					endwhile;
					$resultados[$idx] = [$archivo->getPathname(), 'vid'];
				endif;
				break;
		endswitch;
	endforeach;

	krsort($resultados);
	return array_values($resultados);
}

/**
 * Definición central de filtros binarios de metadatos de la vista principal.
 *
 * @return array<string, array{etiqueta:string, con:string, sin:string}>
 */
function obtenerDefinicionesFiltrosMetadatos(): array
{
	return [
		'geo' => [
			'etiqueta' => 'Geolocalización',
			'con' => 'Con geolocalización',
			'sin' => 'Sin geolocalización'
		],
		'regiones' => [
			'etiqueta' => 'Regiones',
			'con' => 'Con regiones',
			'sin' => 'Sin regiones'
		],
		'rotacion' => [
			'etiqueta' => 'Rotación',
			'con' => 'Con rotación',
			'sin' => 'Sin rotación'
		],
		'palabras' => [
			'etiqueta' => 'Palabras clave',
			'con' => 'Con palabras clave',
			'sin' => 'Sin palabras clave'
		],
		'sugerencias' => [
			'etiqueta' => 'Sugerencias',
			'con' => 'Con sugerencias',
			'sin' => 'Sin sugerencias'
		],
		'duplicadas' => [
			'etiqueta' => 'Duplicadas',
			'con' => 'Con duplicadas',
			'sin' => 'Sin duplicadas'
		],
		'tracking' => [
			'etiqueta' => 'Tracking',
			'con' => 'Con advertencia',
			'sin' => 'Sin advertencia'
		],
	];
}

/**
 * Lee los filtros de metadatos activos desde una fuente GET-like.
 *
 * @param array|null $fuente Fuente alternativa para pruebas; por defecto usa $_GET.
 * @return array<string, string> Valores 'con', 'sin' o cadena vacía.
 */
function obtenerFiltrosMetadatosDesdeFuente(?array $fuente = null): array
{
	$fuente ??= $_GET;
	$filtros = [];
	foreach (obtenerDefinicionesFiltrosMetadatos() as $clave => $definicion):
		$valor = (string) ($fuente[$clave] ?? '');
		$filtros[$clave] = in_array($valor, ['con', 'sin'], true) ? $valor : '';
	endforeach;

	return $filtros;
}

function hayFiltrosMetadatosActivos(array $filtros): bool
{
	foreach ($filtros as $valor):
		if ($valor !== ''):
			return true;
		endif;
	endforeach;

	return false;
}

/**
 * Construye query params para conservar filtros activos en links.
 */
function parametrosFiltrosMetadatos(?array $filtros = null): string
{
	$filtros ??= obtenerFiltrosMetadatosDesdeFuente();
	$activos = array_filter($filtros, fn($valor) => $valor !== '');
	if (empty($activos)):
		return '';
	endif;

	return '&' . http_build_query($activos);
}

/**
 * Construye inputs hidden para conservar filtros activos en formularios GET.
 */
function inputsOcultosFiltrosMetadatos(?array $filtros = null): string
{
	$filtros ??= obtenerFiltrosMetadatosDesdeFuente();
	$html = '';
	foreach ($filtros as $clave => $valor):
		if ($valor === ''):
			continue;
		endif;
		$html .= '<input type="hidden" name="' . htmlspecialchars($clave, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') . '">';
	endforeach;

	return $html;
}

function obtenerPalabraClaveActiva(?array $fuente = null): string
{
	$fuente ??= $_GET;
	return trim((string) ($fuente['palabra_clave'] ?? ''));
}

function parametroPalabraClaveActiva(?array $fuente = null): string
{
	$palabraClave = obtenerPalabraClaveActiva($fuente);
	if ($palabraClave === ''):
		return '';
	endif;

	return '&palabra_clave=' . rawurlencode($palabraClave);
}

function inputOcultoPalabraClaveActiva(?array $fuente = null): string
{
	$palabraClave = obtenerPalabraClaveActiva($fuente);
	if ($palabraClave === ''):
		return '';
	endif;

	return '<input type="hidden" name="palabra_clave" value="' . htmlspecialchars($palabraClave, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Devuelve la cadena Subject/Keyword/Keywords combinada desde metadatos.
 */
function obtenerTextoPalabrasClaveDesdeMeta(array $meta): string
{
	$partes = [];
	foreach (['Subject', 'Keyword', 'Keywords'] as $campo):
		$valor = textoMetadato($meta[$campo] ?? '');
		if ($valor !== ''):
			$partes[] = $valor;
		endif;
	endforeach;

	return implode(', ', $partes);
}

function textoMetadato(mixed $valor): string
{
	if (is_array($valor)):
		$partes = [];
		foreach ($valor as $item):
			$texto = textoMetadato($item);
			if ($texto !== ''):
				$partes[] = $texto;
			endif;
		endforeach;
		return implode(', ', $partes);
	endif;

	return trim((string) $valor);
}

function etiquetasFiltrosMetadatos(): array
{
	return [
		'Subject',
		'Keyword',
		'Keywords',
		'GPSLatitude',
		'GPSLongitude',
		'RegionName',
		'Orientation',
		'Rotation',
		'SpecialInstructions',
		'Instructions',
		'IPTCDigest',
		'Source',
		'Credit',
		'URL',
		'Description',
		'UserComment',
		'ImageDescription',
		'DateTimeOriginal',
		'FileModifyDate',
		'Copyright',
		'Parameters',
		'Invokeai_metadata',
		'Invokeai_graph',
		'Invokeai_workflow',
		'Prompt',
		'Make',
		'Model',
		'Software',
	];
}

function conectarBaseFiltrosMetadatos(): ?PDO
{
	static $pdo = null;
	if ($pdo instanceof PDO):
		return $pdo;
	endif;

	$datosDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'datos';
	if (!is_dir($datosDir)):
		mkdir($datosDir, 0755, true);
	endif;
	$dbFile = $datosDir . DIRECTORY_SEPARATOR . 'filtros_metadatos.sqlite';

	try {
		$pdo = new PDO("sqlite:$dbFile");
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec('PRAGMA foreign_keys = ON');
		$pdo->exec("
			CREATE TABLE IF NOT EXISTS estados (
				ruta TEXT PRIMARY KEY,
				mtime INTEGER NOT NULL,
				tamano INTEGER NOT NULL,
				xattr_eval INTEGER NOT NULL DEFAULT 0,
				geo INTEGER NOT NULL DEFAULT 0,
				regiones INTEGER NOT NULL DEFAULT 0,
				rotacion INTEGER NOT NULL DEFAULT 0,
				palabras INTEGER NOT NULL DEFAULT 0,
				sugerencias INTEGER NOT NULL DEFAULT 0,
				duplicadas INTEGER NOT NULL DEFAULT 0,
				tracking INTEGER NOT NULL DEFAULT 0,
				actualizado INTEGER NOT NULL DEFAULT 0
			)
		");
		$pdo->exec("
			CREATE TABLE IF NOT EXISTS archivos_palabras_clave (
				ruta TEXT PRIMARY KEY,
				mtime INTEGER NOT NULL,
				tamano INTEGER NOT NULL,
				tipo TEXT NOT NULL,
				actualizado INTEGER NOT NULL DEFAULT 0
			)
		");
		$pdo->exec("
			CREATE TABLE IF NOT EXISTS palabras_clave (
				clave TEXT PRIMARY KEY,
				palabra TEXT NOT NULL,
				actualizado INTEGER NOT NULL DEFAULT 0
			)
		");
		$pdo->exec("
			CREATE TABLE IF NOT EXISTS palabras_clave_archivos (
				clave TEXT NOT NULL,
				palabra TEXT NOT NULL,
				ruta TEXT NOT NULL,
				PRIMARY KEY (clave, ruta),
				FOREIGN KEY (ruta) REFERENCES archivos_palabras_clave(ruta) ON DELETE CASCADE
			)
		");
		$pdo->exec("CREATE INDEX IF NOT EXISTS idx_palabras_clave_palabra ON palabras_clave(palabra)");
		$pdo->exec("CREATE INDEX IF NOT EXISTS idx_palabras_clave_archivos_clave ON palabras_clave_archivos(clave)");
		$pdo->exec("CREATE INDEX IF NOT EXISTS idx_palabras_clave_archivos_palabra ON palabras_clave_archivos(palabra)");
		$pdo->exec("CREATE INDEX IF NOT EXISTS idx_archivos_palabras_clave_mtime ON archivos_palabras_clave(mtime DESC)");
		$pdo->exec("
			INSERT OR IGNORE INTO palabras_clave (clave, palabra, actualizado)
			SELECT clave, MIN(palabra), strftime('%s', 'now')
			FROM palabras_clave_archivos
			GROUP BY clave
		");
		$columnas = $pdo->query('PRAGMA table_info(estados)')->fetchAll(PDO::FETCH_ASSOC);
		$nombresColumnas = array_column($columnas, 'name');
		if (!in_array('rotacion', $nombresColumnas, true)):
			$pdo->exec('ALTER TABLE estados ADD COLUMN rotacion INTEGER NOT NULL DEFAULT 0');
			$pdo->exec('UPDATE estados SET mtime = -1');
		endif;
	} catch (\PDOException $e) {
		trigger_error("Error al abrir cache de filtros: [{$e->getCode()}] {$e->getMessage()}", E_USER_WARNING);
		$pdo = null;
	}

	return $pdo;
}

function tipoMultimediaDesdeRuta(string $ruta): ?string
{
	return match (strtolower(pathinfo($ruta, PATHINFO_EXTENSION))) {
		'jpg', 'jpeg', 'webp', 'png', 'heic' => 'img',
		'mp4', 'mov' => 'vid',
		default => null,
	};
}

function normalizarClavePalabraClave(string $palabra): string
{
	$palabra = normalizarTag(trim($palabra));
	$palabra = trim((string) preg_replace('/\s+/u', ' ', $palabra));
	return mb_strtolower($palabra, 'UTF-8');
}

function normalizarRutaIndicePalabrasClave(string $ruta): string
{
	$ruta = trim(str_replace('\\', '/', $ruta));
	if ($ruta === ''):
		return '';
	endif;

	$real = realpath($ruta);
	if ($real !== false):
		return str_replace('\\', '/', $real);
	endif;

	if (str_starts_with($ruta, '/')):
		return $ruta;
	endif;

	return str_replace('\\', '/', dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($ruta, '/'));
}

function rutasEquivalentesIndicePalabrasClave(string $ruta): array
{
	$root = str_replace('\\', '/', dirname(__DIR__));
	$original = trim(str_replace('\\', '/', $ruta));
	$normalizada = normalizarRutaIndicePalabrasClave($ruta);
	$rutas = [];
	foreach ([$original, $normalizada] as $candidata):
		if ($candidata !== ''):
			$rutas[$candidata] = true;
		endif;
	endforeach;

	if ($normalizada !== '' && str_starts_with($normalizada, $root . '/')):
		$rutas[substr($normalizada, strlen($root) + 1)] = true;
	endif;
	if ($original !== '' && !str_starts_with($original, '/')):
		$rutas[$root . '/' . ltrim($original, '/')] = true;
	endif;

	return array_keys($rutas);
}

function etiquetasIndicePalabrasClave(): array
{
	return ['Subject', 'Keyword', 'Keywords'];
}

function rutaPendienteIndicePalabrasClave(PDOStatement $stmt, string $ruta, string $tipo): bool
{
	if (!is_file($ruta)):
		return false;
	endif;

	$rutaIndice = normalizarRutaIndicePalabrasClave($ruta);
	$stmt->execute([':ruta' => $rutaIndice]);
	$fila = $stmt->fetch(PDO::FETCH_ASSOC);
	return (
		!$fila
		|| (int) $fila['mtime'] !== filemtime($rutaIndice)
		|| (int) $fila['tamano'] !== filesize($rutaIndice)
		|| (string) $fila['tipo'] !== $tipo
	);
}

function rutaRelativaProyecto(string $ruta): string
{
	return rutaRelativaDesdeProyecto($ruta);
}

function guardarIndicePalabrasClaveArchivo(
	string $ruta,
	string $tipo,
	array $palabras,
	?callable $emitir = null
): bool {
	$pdo = conectarBaseFiltrosMetadatos();
	$ruta = normalizarRutaIndicePalabrasClave($ruta);
	if (!$pdo || $ruta === '' || !is_file($ruta)):
		return false;
	endif;

	if (!in_array($tipo, ['img', 'vid'], true)):
		$tipo = tipoMultimediaDesdeRuta($ruta) ?? '';
	endif;
	if ($tipo === ''):
		return false;
	endif;

	$palabras = normalizarPalabrasClave($palabras);
	$ahora = time();

	try {
		$pdo->beginTransaction();
		$guardarArchivo = $pdo->prepare("
			INSERT INTO archivos_palabras_clave (ruta, mtime, tamano, tipo, actualizado)
			VALUES (:ruta, :mtime, :tamano, :tipo, :actualizado)
			ON CONFLICT(ruta) DO UPDATE SET
				mtime = excluded.mtime,
				tamano = excluded.tamano,
				tipo = excluded.tipo,
				actualizado = excluded.actualizado
		");
		$borrarPalabras = $pdo->prepare('DELETE FROM palabras_clave_archivos WHERE ruta = :ruta');
		$guardarPalabraBase = $pdo->prepare("
			INSERT INTO palabras_clave (clave, palabra, actualizado)
			VALUES (:clave, :palabra, :actualizado)
			ON CONFLICT(clave) DO UPDATE SET
				palabra = excluded.palabra,
				actualizado = excluded.actualizado
		");
		$guardarPalabraArchivo = $pdo->prepare("
			INSERT INTO palabras_clave_archivos (clave, palabra, ruta)
			VALUES (:clave, :palabra, :ruta)
			ON CONFLICT(clave, ruta) DO UPDATE SET
				palabra = excluded.palabra
		");

		$guardarArchivo->execute([
			':ruta' => $ruta,
			':mtime' => filemtime($ruta),
			':tamano' => filesize($ruta),
			':tipo' => $tipo,
			':actualizado' => $ahora,
		]);
		$borrarPalabras->execute([':ruta' => $ruta]);

		foreach ($palabras as $palabra):
			$clave = normalizarClavePalabraClave($palabra);
			if ($clave === ''):
				continue;
			endif;
			if ($emitir !== null):
				$emitir([
					'tipo' => 'palabra',
					'palabra' => $palabra,
					'clave' => $clave,
					'mensaje' => 'Guardando palabra clave ' . $palabra,
				]);
			endif;
			$guardarPalabraBase->execute([
				':clave' => $clave,
				':palabra' => $palabra,
				':actualizado' => $ahora,
			]);
			$guardarPalabraArchivo->execute([
				':clave' => $clave,
				':palabra' => $palabra,
				':ruta' => $ruta,
			]);
		endforeach;
		$pdo->commit();
	} catch (\Throwable $e) {
		if ($pdo->inTransaction()):
			$pdo->rollBack();
		endif;
		return false;
	}

	return true;
}

function actualizarIndicePalabrasClave(array $resultados, int $limite = 250, bool $forzar = false): int
{
	$pdo = conectarBaseFiltrosMetadatos();
	if (!$pdo):
		return 0;
	endif;

	$consultaEstado = $pdo->prepare('SELECT mtime, tamano, tipo FROM archivos_palabras_clave WHERE ruta = :ruta');
	$pendientes = [];
	foreach ($resultados as $resultado):
		$ruta = (string) ($resultado[0] ?? '');
		if ($ruta === '' || !is_file($ruta)):
			continue;
		endif;
		$rutaIndice = normalizarRutaIndicePalabrasClave($ruta);
		if ($rutaIndice === '' || !is_file($rutaIndice)):
			continue;
		endif;
		$tipo = (string) ($resultado[1] ?? '');
		if (!in_array($tipo, ['img', 'vid'], true)):
			$tipo = tipoMultimediaDesdeRuta($rutaIndice) ?? '';
		endif;
		if ($tipo === ''):
			continue;
		endif;
		if (!$forzar && !rutaPendienteIndicePalabrasClave($consultaEstado, $rutaIndice, $tipo)):
			continue;
		endif;

		$pendientes[] = [$rutaIndice, $tipo];
		if ($limite > 0 && count($pendientes) >= $limite):
			break;
		endif;
	endforeach;

	if (empty($pendientes)):
		return 0;
	endif;

	$rutas = array_column($pendientes, 0);
	$metadatos = obtenerMetadatosLote($rutas, etiquetasIndicePalabrasClave());
	$actualizados = 0;
	foreach ($pendientes as [$ruta, $tipo]):
		$meta = $metadatos[$ruta] ?? [];
		$palabras = normalizarPalabrasClave(obtenerTextoPalabrasClaveDesdeMeta(is_array($meta) ? $meta : []));
		if (guardarIndicePalabrasClaveArchivo($ruta, $tipo, $palabras)):
			$actualizados++;
		endif;
	endforeach;

	return $actualizados;
}

function limpiarPalabrasClaveSinUso(?callable $emitir = null): array
{
	$pdo = conectarBaseFiltrosMetadatos();
	if (!$pdo):
		return [];
	endif;

	try {
		$stmt = $pdo->query("
			SELECT k.clave, k.palabra
			FROM palabras_clave k
			LEFT JOIN palabras_clave_archivos p ON p.clave = k.clave
			GROUP BY k.clave
			HAVING COUNT(p.ruta) = 0
			ORDER BY k.palabra COLLATE NOCASE ASC
		");
		$filas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
		$borrar = $pdo->prepare('DELETE FROM palabras_clave WHERE clave = :clave');
		foreach ($filas as $fila):
			$palabra = (string) ($fila['palabra'] ?? '');
			if ($emitir !== null && $palabra !== ''):
				$emitir([
					'tipo' => 'limpieza',
					'palabra' => $palabra,
					'clave' => (string) ($fila['clave'] ?? ''),
					'mensaje' => 'Limpiando palabras clave sin usar: ' . $palabra,
				]);
			endif;
			$borrar->execute([':clave' => (string) ($fila['clave'] ?? '')]);
		endforeach;
		return $filas;
	} catch (\PDOException $e) {
		return [];
	}
}

function moverIndicePalabrasClaveArchivo(string $rutaOrigen, string $rutaDestino, ?string $tipo = null): bool
{
	$pdo = conectarBaseFiltrosMetadatos();
	$rutaDestinoIndice = normalizarRutaIndicePalabrasClave($rutaDestino);
	if (!$pdo || $rutaDestinoIndice === '' || !is_file($rutaDestinoIndice)):
		return false;
	endif;

	$rutasOrigen = rutasEquivalentesIndicePalabrasClave($rutaOrigen);
	$seleccionarArchivo = $pdo->prepare('SELECT ruta, tipo FROM archivos_palabras_clave WHERE ruta = :ruta LIMIT 1');
	$archivoOrigen = null;
	foreach ($rutasOrigen as $candidata):
		$seleccionarArchivo->execute([':ruta' => $candidata]);
		$archivoOrigen = $seleccionarArchivo->fetch(PDO::FETCH_ASSOC);
		if ($archivoOrigen):
			break;
		endif;
	endforeach;

	$palabras = [];
	$seleccionarPalabras = $pdo->prepare('SELECT clave, palabra FROM palabras_clave_archivos WHERE ruta = :ruta ORDER BY palabra COLLATE NOCASE ASC');
	foreach ($rutasOrigen as $candidata):
		$seleccionarPalabras->execute([':ruta' => $candidata]);
		foreach (($seleccionarPalabras->fetchAll(PDO::FETCH_ASSOC) ?: []) as $fila):
			$clave = (string) ($fila['clave'] ?? '');
			$palabra = (string) ($fila['palabra'] ?? '');
			if ($clave === '' || $palabra === '' || isset($palabras[$clave])):
				continue;
			endif;
			$palabras[$clave] = $palabra;
		endforeach;
	endforeach;

	if (!$archivoOrigen && empty($palabras)):
		return false;
	endif;

	if (!in_array($tipo, ['img', 'vid'], true)):
		$tipo = tipoMultimediaDesdeRuta($rutaDestinoIndice) ?? (string) ($archivoOrigen['tipo'] ?? '');
	endif;
	if (!in_array($tipo, ['img', 'vid'], true)):
		return false;
	endif;

	$ahora = time();
	try {
		$pdo->beginTransaction();
		$guardarArchivo = $pdo->prepare("
			INSERT INTO archivos_palabras_clave (ruta, mtime, tamano, tipo, actualizado)
			VALUES (:ruta, :mtime, :tamano, :tipo, :actualizado)
			ON CONFLICT(ruta) DO UPDATE SET
				mtime = excluded.mtime,
				tamano = excluded.tamano,
				tipo = excluded.tipo,
				actualizado = excluded.actualizado
		");
		$borrarPalabrasRuta = $pdo->prepare('DELETE FROM palabras_clave_archivos WHERE ruta = :ruta');
		$borrarArchivoRuta = $pdo->prepare('DELETE FROM archivos_palabras_clave WHERE ruta = :ruta');
		$guardarPalabraBase = $pdo->prepare("
			INSERT INTO palabras_clave (clave, palabra, actualizado)
			VALUES (:clave, :palabra, :actualizado)
			ON CONFLICT(clave) DO UPDATE SET
				palabra = excluded.palabra,
				actualizado = excluded.actualizado
		");
		$guardarPalabraArchivo = $pdo->prepare("
			INSERT INTO palabras_clave_archivos (clave, palabra, ruta)
			VALUES (:clave, :palabra, :ruta)
			ON CONFLICT(clave, ruta) DO UPDATE SET
				palabra = excluded.palabra
		");

		$borrarPalabrasRuta->execute([':ruta' => $rutaDestinoIndice]);
		$guardarArchivo->execute([
			':ruta' => $rutaDestinoIndice,
			':mtime' => filemtime($rutaDestinoIndice),
			':tamano' => filesize($rutaDestinoIndice),
			':tipo' => $tipo,
			':actualizado' => $ahora,
		]);
		foreach ($palabras as $clave => $palabra):
			$guardarPalabraBase->execute([
				':clave' => $clave,
				':palabra' => $palabra,
				':actualizado' => $ahora,
			]);
			$guardarPalabraArchivo->execute([
				':clave' => $clave,
				':palabra' => $palabra,
				':ruta' => $rutaDestinoIndice,
			]);
		endforeach;

		foreach ($rutasOrigen as $candidata):
			if ($candidata === $rutaDestinoIndice):
				continue;
			endif;
			$borrarPalabrasRuta->execute([':ruta' => $candidata]);
			$borrarArchivoRuta->execute([':ruta' => $candidata]);
		endforeach;
		$pdo->commit();
		limpiarPalabrasClaveSinUso();
		return true;
	} catch (\Throwable $e) {
		if ($pdo->inTransaction()):
			$pdo->rollBack();
		endif;
		return false;
	}
}

function eliminarIndicePalabrasClaveArchivo(string $ruta, bool $limpiarSinUso = true): void
{
	$pdo = conectarBaseFiltrosMetadatos();
	if (!$pdo):
		return;
	endif;

	try {
		$borrarPalabras = $pdo->prepare('DELETE FROM palabras_clave_archivos WHERE ruta = :ruta');
		$borrarArchivo = $pdo->prepare('DELETE FROM archivos_palabras_clave WHERE ruta = :ruta');
		foreach (rutasEquivalentesIndicePalabrasClave($ruta) as $candidata):
			$borrarPalabras->execute([':ruta' => $candidata]);
			$borrarArchivo->execute([':ruta' => $candidata]);
		endforeach;
		if ($limpiarSinUso):
			limpiarPalabrasClaveSinUso();
		endif;
	} catch (\PDOException $e) {
		return;
	}
}

function obtenerPalabrasClaveIndexadas(): array
{
	$pdo = conectarBaseFiltrosMetadatos();
	if (!$pdo):
		return [];
	endif;

	try {
		$stmt = $pdo->query("
			SELECT
				k.clave,
				k.palabra,
				COUNT(DISTINCT p.ruta) AS total
			FROM palabras_clave k
			LEFT JOIN palabras_clave_archivos p ON p.clave = k.clave
			LEFT JOIN archivos_palabras_clave a ON a.ruta = p.ruta
			GROUP BY k.clave
			HAVING total > 0
			ORDER BY k.palabra COLLATE NOCASE ASC
		");
		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (\PDOException $e) {
		return [];
	}
}

function consultarResultadosPorClaveNormalizada(string $clave, ?string $media = null): array
{
	$pdo = conectarBaseFiltrosMetadatos();
	if (!$pdo || $clave === ''):
		return [];
	endif;

	$tipo = null;
	if ($media === 'fotos'):
		$tipo = 'img';
	elseif ($media === 'videos'):
		$tipo = 'vid';
	endif;

	try {
		$sql = "
			SELECT a.ruta, a.tipo, a.mtime, a.tamano
			FROM palabras_clave_archivos p
			JOIN archivos_palabras_clave a ON a.ruta = p.ruta
			WHERE p.clave = :clave
		";
		if ($tipo !== null):
			$sql .= " AND a.tipo = :tipo";
		endif;
		$sql .= " ORDER BY a.mtime DESC, a.ruta DESC";
		$stmt = $pdo->prepare($sql);
		$stmt->bindValue(':clave', $clave, PDO::PARAM_STR);
		if ($tipo !== null):
			$stmt->bindValue(':tipo', $tipo, PDO::PARAM_STR);
		endif;
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (\PDOException $e) {
		return [];
	}
}

function obtenerResultadosPorPalabraClave(string $palabra, ?string $media = null): array
{
	$clave = normalizarClavePalabraClave($palabra);
	if ($clave === ''):
		return [];
	endif;

	$filas = consultarResultadosPorClaveNormalizada($clave, $media);
	$stale = [];
	$resultados = [];
	foreach ($filas as $fila):
		$ruta = (string) ($fila['ruta'] ?? '');
		if ($ruta === '' || !is_file($ruta)):
			eliminarIndicePalabrasClaveArchivo($ruta);
			continue;
		endif;
		$tipo = (string) ($fila['tipo'] ?? (tipoMultimediaDesdeRuta($ruta) ?? ''));
		if (
			(int) ($fila['mtime'] ?? 0) !== filemtime($ruta)
			|| (int) ($fila['tamano'] ?? 0) !== filesize($ruta)
		):
			$stale[] = [$ruta, $tipo];
			continue;
		endif;
		$resultados[] = [$ruta, $tipo];
	endforeach;

	if (!empty($stale)):
		actualizarIndicePalabrasClave($stale, 0, true);
		$resultados = [];
		foreach (consultarResultadosPorClaveNormalizada($clave, $media) as $fila):
			$ruta = (string) ($fila['ruta'] ?? '');
			if ($ruta !== '' && is_file($ruta)):
				$resultados[] = [$ruta, (string) $fila['tipo']];
			endif;
		endforeach;
	endif;

	return $resultados;
}

function sincronizarIndicePalabrasClaveCompleto(
	?array $omitir = null,
	?callable $emitir = null,
	int $limite = 0
): array {
	$pdo = conectarBaseFiltrosMetadatos();
	if (!$pdo):
		return [
			'archivos' => 0,
			'actualizados' => 0,
			'eliminadas' => 0,
			'palabras' => [],
		];
	endif;

	$emitir ??= static function (array $evento): void {
	};
	$omitir ??= carpetasIgnoradasConfiguracion();
	$root = dirname(__DIR__);
	$limite = max(0, $limite);

	$emitir([
		'tipo' => 'inicio',
		'actual' => 0,
		'total' => 0,
		'mensaje' => 'Buscando archivos...',
	]);
	$resultados = obtenerResultadosMultimedia($root, null, $omitir, null);
	$sincronizacionCompleta = $limite === 0;
	if ($limite > 0):
		$resultados = array_slice($resultados, 0, $limite);
	endif;
	$total = count($resultados);
	$emitir([
		'tipo' => 'total',
		'actual' => 0,
		'total' => $total,
		'mensaje' => 'Se encontraron ' . $total . ' archivos multimedia.',
	]);

	if ($sincronizacionCompleta):
		$rutasVivas = array_fill_keys(array_map(fn($fila) => (string) ($fila[0] ?? ''), $resultados), true);
		try {
			$stmt = $pdo->query('SELECT ruta FROM archivos_palabras_clave');
			foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $rutaIndexada):
				$rutaIndexada = (string) $rutaIndexada;
				if ($rutaIndexada === '' || isset($rutasVivas[$rutaIndexada])):
					continue;
				endif;
				$emitir([
					'tipo' => 'archivo',
					'actual' => 0,
					'total' => $total,
					'ruta' => rutaRelativaProyecto($rutaIndexada),
					'mensaje' => 'Quitando archivo inexistente del índice: ' . rutaRelativaProyecto($rutaIndexada),
				]);
				eliminarIndicePalabrasClaveArchivo($rutaIndexada, false);
			endforeach;
		} catch (\PDOException $e) {
			// Si falla esta limpieza, la sincronización de archivos existentes continúa.
		}
	endif;

	$actualizados = 0;
	foreach ($resultados as $indice => $resultado):
		$ruta = (string) ($resultado[0] ?? '');
		$tipo = (string) ($resultado[1] ?? '');
		if ($ruta === '' || !is_file($ruta)):
			continue;
		endif;
		if (!in_array($tipo, ['img', 'vid'], true)):
			$tipo = tipoMultimediaDesdeRuta($ruta) ?? '';
		endif;
		if ($tipo === ''):
			continue;
		endif;

		$actual = $indice + 1;
		$rutaRelativa = rutaRelativaProyecto($ruta);
		$emitir([
			'tipo' => 'archivo',
			'actual' => $actual,
			'total' => $total,
			'ruta' => $rutaRelativa,
			'mensaje' => 'Leyendo archivo ' . $rutaRelativa,
		]);

		$metadatos = obtenerMetadatosLote([$ruta], etiquetasIndicePalabrasClave());
		$meta = $metadatos[$ruta] ?? [];
		$palabras = normalizarPalabrasClave(obtenerTextoPalabrasClaveDesdeMeta(is_array($meta) ? $meta : []));
		$guardado = guardarIndicePalabrasClaveArchivo(
			$ruta,
			$tipo,
			$palabras,
			static function (array $evento) use ($emitir, $actual, $total): void {
				$evento['actual'] = $actual;
				$evento['total'] = $total;
				$emitir($evento);
			}
		);
		if ($guardado):
			$actualizados++;
		endif;
	endforeach;

	$eliminadas = [];
	if ($sincronizacionCompleta):
		$eliminadas = limpiarPalabrasClaveSinUso($emitir);
	endif;

	$palabras = obtenerPalabrasClaveIndexadas();
	$emitir([
		'tipo' => 'fin',
		'actual' => $total,
		'total' => $total,
		'archivos' => $total,
		'actualizados' => $actualizados,
		'eliminadas' => count($eliminadas),
		'palabras' => $palabras,
		'mensaje' => 'Sincronización terminada: ' . $actualizados . ' archivos actualizados.',
	]);

	return [
		'archivos' => $total,
		'actualizados' => $actualizados,
		'eliminadas' => count($eliminadas),
		'palabras' => $palabras,
	];
}

function leerEstadoFiltrosMetadatosCache(string $ruta, bool $requiereXattr = false): ?array
{
	$pdo = conectarBaseFiltrosMetadatos();
	if (!$pdo || !is_file($ruta)):
		return null;
	endif;

	try {
		$stmt = $pdo->prepare('SELECT * FROM estados WHERE ruta = :ruta');
		$stmt->execute([':ruta' => $ruta]);
		$fila = $stmt->fetch(PDO::FETCH_ASSOC);
	} catch (\PDOException $e) {
		return null;
	}

	if (
		!$fila
		|| (int) $fila['mtime'] !== filemtime($ruta)
		|| (int) $fila['tamano'] !== filesize($ruta)
		|| ($requiereXattr && (int) $fila['xattr_eval'] !== 1)
	):
		return null;
	endif;

	return [
		'geo' => (bool) $fila['geo'],
		'regiones' => (bool) $fila['regiones'],
		'rotacion' => (bool) $fila['rotacion'],
		'palabras' => (bool) $fila['palabras'],
		'sugerencias' => (bool) $fila['sugerencias'],
		'duplicadas' => (bool) $fila['duplicadas'],
		'tracking' => (bool) $fila['tracking'],
	];
}

function guardarEstadoFiltrosMetadatosCache(string $ruta, array $estado, bool $xattrEval = false): void
{
	$pdo = conectarBaseFiltrosMetadatos();
	if (!$pdo || !is_file($ruta)):
		return;
	endif;

	try {
		$stmt = $pdo->prepare("
			INSERT INTO estados (
				ruta, mtime, tamano, xattr_eval, geo, regiones, rotacion, palabras, sugerencias, duplicadas, tracking, actualizado
			) VALUES (
				:ruta, :mtime, :tamano, :xattr_eval, :geo, :regiones, :rotacion, :palabras, :sugerencias, :duplicadas, :tracking, :actualizado
			)
			ON CONFLICT(ruta) DO UPDATE SET
				mtime = excluded.mtime,
				tamano = excluded.tamano,
				xattr_eval = excluded.xattr_eval,
				geo = excluded.geo,
				regiones = excluded.regiones,
				rotacion = excluded.rotacion,
				palabras = excluded.palabras,
				sugerencias = excluded.sugerencias,
				duplicadas = excluded.duplicadas,
				tracking = excluded.tracking,
				actualizado = excluded.actualizado
		");
		$stmt->execute([
			':ruta' => $ruta,
			':mtime' => filemtime($ruta),
			':tamano' => filesize($ruta),
			':xattr_eval' => $xattrEval ? 1 : 0,
			':geo' => !empty($estado['geo']) ? 1 : 0,
			':regiones' => !empty($estado['regiones']) ? 1 : 0,
			':rotacion' => !empty($estado['rotacion']) ? 1 : 0,
			':palabras' => !empty($estado['palabras']) ? 1 : 0,
			':sugerencias' => !empty($estado['sugerencias']) ? 1 : 0,
			':duplicadas' => !empty($estado['duplicadas']) ? 1 : 0,
			':tracking' => !empty($estado['tracking']) ? 1 : 0,
			':actualizado' => time(),
		]);
	} catch (\PDOException $e) {
		return;
	}
}

function obtenerMetadatosLote(array $rutas, array $etiquetas): array
{
	$resultados = [];
	$rutas = array_values(array_filter($rutas, 'is_file'));
	foreach (array_chunk($rutas, 80) as $lote):
		$argumentos = ['exiftool', '-n', '-s', '-j'];
		foreach ($etiquetas as $etiqueta):
			$etiqueta = trim((string) $etiqueta, '- ');
			if (preg_match('/^[A-Za-z0-9:_#-]+$/', $etiqueta)):
				$argumentos[] = '-' . $etiqueta;
			endif;
		endforeach;
		foreach ($lote as $ruta):
			$argumentos[] = $ruta;
		endforeach;
		$comando = comandoBrewSeguro($argumentos);
		$salida = shell_exec($comando . ' 2>/dev/null');
		$filas = json_decode((string) $salida, true);
		if (!is_array($filas)):
			continue;
		endif;
		foreach ($filas as $fila):
			if (!isset($fila['SourceFile'])):
				continue;
			endif;
			$ruta = $fila['SourceFile'];
			unset($fila['SourceFile']);
			$resultados[$ruta] = $fila;
		endforeach;
	endforeach;

	return $resultados;
}

/**
 * Detecta si el formulario tendría al menos un campo sugerido para el archivo.
 */
function tieneSugerenciasMetadatos(string $ruta, array $meta, string $palabrasClave, bool $considerarXattr = true): bool
{
	if (isset($meta['DateTimeOriginal'])):
		$fecha = textoMetadato($meta['DateTimeOriginal']);
		if (strlen($fecha) !== 19 && strlen($fecha) !== 25):
			return true;
		endif;
	else:
		list($createDate) = devolverFecha(basename($ruta));
		if (!empty($createDate) || isset($meta['FileModifyDate'])):
			return true;
		endif;
	endif;

	if (isset($meta['Keyword']) || isset($meta['Keywords'])):
		return true;
	endif;

	$tienePalabras = !empty(normalizarPalabrasClave($palabrasClave));
	$stableDiffusion = isset($meta['Parameters']);
	$invokeAI = isset($meta['Invokeai_metadata']) || isset($meta['Invokeai_graph']) || isset($meta['Invokeai_workflow']);
	$comfyUI = isset($meta['Prompt']);

	if (!$tienePalabras):
		if ($stableDiffusion || $invokeAI):
			return true;
		endif;
		if (!$stableDiffusion):
			$fechaArchivo = textoMetadato($meta['DateTimeOriginal'] ?? $meta['FileModifyDate'] ?? '');
			$crtmp = is_string($fechaArchivo) && strlen($fechaArchivo) >= 4 ? substr($fechaArchivo, 0, 4) : '';
			if (!is_numeric($crtmp)):
				list($createDate) = devolverFecha(basename($ruta));
				$crtmp = substr($createDate, 0, 4);
			endif;
			if (is_numeric($crtmp)):
				$partesNombre = explode(' ', pathinfo($ruta, PATHINFO_FILENAME));
				unset($partesNombre[0]);
				foreach ($partesNombre as $k => $v):
					if (str_starts_with($v, 'IMG_') || str_starts_with($v, 'photo') || str_ends_with($v, '_source')):
						unset($partesNombre[$k]);
						continue;
					endif;
					if (str_ends_with($v, ')')):
						$partesNombre[$k] = preg_replace('/\(\d+\)$/', '', $v);
					endif;
				endforeach;
				$nombre = trim(implode(' ', $partesNombre));
				if ($nombre !== ''):
					return true;
				endif;
			endif;
		endif;
	endif;

	if (!isset($meta['Copyright']) && !$stableDiffusion):
		return true;
	endif;

	$makeVacio = textoMetadato($meta['Make'] ?? '') === '';
	$modelVacio = textoMetadato($meta['Model'] ?? '') === '';
	$softwareVacio = textoMetadato($meta['Software'] ?? '') === '';
	if (($stableDiffusion || $invokeAI || $comfyUI) && ($modelVacio || $softwareVacio)):
		return true;
	endif;

	if ($makeVacio || $modelVacio):
		$camposOrigen = [
			textoMetadato($meta['Description'] ?? ''),
			textoMetadato($meta['UserComment'] ?? ''),
			textoMetadato($meta['ImageDescription'] ?? ''),
			textoMetadato($meta['SpecialInstructions'] ?? ''),
			textoMetadato($meta['Instructions'] ?? ''),
			textoMetadato($meta['Source'] ?? ''),
			textoMetadato($meta['Credit'] ?? ''),
			textoMetadato($meta['URL'] ?? ''),
		];
		$redSocial = detectarRedSocialEnTexto(implode(' ', $camposOrigen));
		if (empty($redSocial) && $considerarXattr):
			$redSocial = detectarRedSocialEnTexto(leerMetadatoExtendido($ruta));
		endif;
		if (!empty($redSocial)):
			return true;
		endif;
	endif;

	return false;
}

/**
 * Calcula el estado de filtros desde metadatos ya leídos.
 *
 * @return array<string, bool>
 */
function calcularEstadoFiltrosMetadatos(string $ruta, array $meta, bool $considerarXattr = true): array
{
	$palabrasClave = obtenerTextoPalabrasClaveDesdeMeta($meta);
	$orientacion = normalizarOrientacionExif($meta['Orientation'] ?? 1);
	$rotacion = normalizarOrientacionExif($meta['Rotation'] ?? 1);
	$camposOrigen = [
		textoMetadato($meta['Description'] ?? ''),
		textoMetadato($meta['UserComment'] ?? ''),
		textoMetadato($meta['ImageDescription'] ?? ''),
		textoMetadato($meta['SpecialInstructions'] ?? ''),
		textoMetadato($meta['Instructions'] ?? ''),
		textoMetadato($meta['Source'] ?? ''),
		textoMetadato($meta['Credit'] ?? ''),
		textoMetadato($meta['URL'] ?? ''),
	];
	$camposTracking = ['SpecialInstructions', 'Instructions', 'IPTCDigest'];
	$tieneCampoTracking = false;
	foreach ($camposTracking as $campo):
		if (isset($meta[$campo]) && textoMetadato($meta[$campo]) !== ''):
			$tieneCampoTracking = true;
			break;
		endif;
	endforeach;

	return [
		'geo' => isset($meta['GPSLatitude'], $meta['GPSLongitude'])
			&& textoMetadato($meta['GPSLatitude']) !== ''
			&& textoMetadato($meta['GPSLongitude']) !== '',
		'regiones' => isset($meta['RegionName']) && textoMetadato($meta['RegionName']) !== '',
		'rotacion' => $orientacion > 1 || $rotacion > 1,
		'palabras' => !empty(normalizarPalabrasClave($palabrasClave)),
		'sugerencias' => tieneSugerenciasMetadatos($ruta, $meta, $palabrasClave, $considerarXattr),
		'duplicadas' => tienePalabrasClaveDuplicadas($palabrasClave),
		'tracking' => $tieneCampoTracking || detectarRedSocialEnTexto(implode(' ', $camposOrigen)) !== '',
	];
}

/**
 * Evalúa el estado de metadatos usado por los filtros de la vista principal.
 *
 * @return array<string, bool>
 */
function evaluarEstadoFiltrosMetadatos(string $ruta, bool $requiereXattr = false): array
{
	static $cache = [];
	$cacheKey = $ruta . '|' . ($requiereXattr ? '1' : '0');
	if (isset($cache[$cacheKey])):
		return $cache[$cacheKey];
	endif;

	$estadoCache = leerEstadoFiltrosMetadatosCache($ruta, $requiereXattr);
	if ($estadoCache !== null):
		$cache[$cacheKey] = $estadoCache;
		return $estadoCache;
	endif;

	$meta = obtenerMetadatos($ruta, etiquetasFiltrosMetadatos())['resultado'] ?? [];
	if (!is_array($meta)):
		$meta = [];
	endif;
	$estado = calcularEstadoFiltrosMetadatos($ruta, $meta, $requiereXattr);
	guardarEstadoFiltrosMetadatosCache($ruta, $estado, $requiereXattr);

	$cache[$cacheKey] = $estado;
	return $estado;
}

function calentarCacheFiltrosMetadatos(array $resultados, bool $requiereXattr = false): void
{
	$pendientes = [];
	foreach ($resultados as $resultado):
		$ruta = $resultado[0] ?? '';
		if ($ruta === '' || !is_file($ruta)):
			continue;
		endif;
		if (leerEstadoFiltrosMetadatosCache($ruta, $requiereXattr) === null):
			$pendientes[] = $ruta;
		endif;
	endforeach;

	if (empty($pendientes)):
		return;
	endif;

	$metadatos = obtenerMetadatosLote($pendientes, etiquetasFiltrosMetadatos());
	foreach ($pendientes as $ruta):
		$meta = $metadatos[$ruta] ?? [];
		$estado = calcularEstadoFiltrosMetadatos($ruta, is_array($meta) ? $meta : [], $requiereXattr);
		guardarEstadoFiltrosMetadatosCache($ruta, $estado, $requiereXattr);
	endforeach;
}

/**
 * Aplica filtros binarios de metadatos a la lista multimedia ya ordenada.
 */
function filtrarResultadosPorMetadatos(array $resultados, array $filtros): array
{
	if (!hayFiltrosMetadatosActivos($filtros)):
		return $resultados;
	endif;
	$requiereXattr = ($filtros['sugerencias'] ?? '') !== '';
	calentarCacheFiltrosMetadatos($resultados, $requiereXattr);

	return array_values(array_filter($resultados, function ($resultado) use ($filtros) {
		$requiereXattr = ($filtros['sugerencias'] ?? '') !== '';
		$estado = evaluarEstadoFiltrosMetadatos($resultado[0], $requiereXattr);
		foreach ($filtros as $clave => $valor):
			if ($valor === ''):
				continue;
			endif;
			$tiene = $estado[$clave] ?? false;
			if ($valor === 'con' && !$tiene):
				return false;
			endif;
			if ($valor === 'sin' && $tiene):
				return false;
			endif;
		endforeach;

		return true;
	}));
}

/**
 * Renderiza los controles de filtros de metadatos.
 */
function formularioFiltrosMetadatos(array $filtros, int $totalFiltrado, int $totalOriginal, string $rutaIterador, int $ver): string
{
	$ruta = trim(str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $rutaIterador), DIRECTORY_SEPARATOR);
	$palabraClave = obtenerPalabraClaveActiva();
	$mediaActual = isset($_GET['media']) && in_array($_GET['media'], ['fotos', 'videos'], true)
		? $_GET['media']
		: '';
	$html =
		'<form method="get" class="filtros-metadatos">' .
		'<input type="hidden" name="ver" value="' . htmlspecialchars((string) $ver, ENT_QUOTES, 'UTF-8') . '">';
	if ($palabraClave === '' && $ruta !== '' && is_dir($ruta)):
		$html .= '<input type="hidden" name="ruta" value="' . htmlspecialchars($ruta, ENT_QUOTES, 'UTF-8') . '">';
	endif;
	if ($palabraClave !== ''):
		$html .= '<input type="hidden" name="palabra_clave" value="' . htmlspecialchars($palabraClave, ENT_QUOTES, 'UTF-8') . '">';
	endif;

	$html .=
		'<span class="filtros-metadatos-resumen">' . $totalFiltrado . ' de ' . $totalOriginal . '</span>' .
		'<label>' .
		'<span>Media</span>' .
		'<select name="media">' .
		'<option value="">Cualquiera</option>' .
		'<option value="fotos"' . ($mediaActual === 'fotos' ? ' selected' : '') . '>Foto</option>' .
		'<option value="videos"' . ($mediaActual === 'videos' ? ' selected' : '') . '>Video</option>' .
		'</select>' .
		'</label>';
	foreach (obtenerDefinicionesFiltrosMetadatos() as $clave => $definicion):
		$valorActual = $filtros[$clave] ?? '';
		$html .=
			'<label>' .
			'<span>' . htmlspecialchars($definicion['etiqueta'], ENT_QUOTES, 'UTF-8') . '</span>' .
			'<select name="' . htmlspecialchars($clave, ENT_QUOTES, 'UTF-8') . '">' .
			'<option value="">Cualquiera</option>';
		foreach (['con', 'sin'] as $valor):
			$html .=
				'<option value="' . $valor . '"' . ($valorActual === $valor ? ' selected' : '') . '>' .
				htmlspecialchars($definicion[$valor], ENT_QUOTES, 'UTF-8') .
				'</option>';
		endforeach;
		$html .= '</select></label>';
	endforeach;
	$html .=
		'<button type="submit">Filtrar</button>' .
		(hayFiltrosMetadatosActivos($filtros) || $mediaActual !== '' ? '<a href="?' . http_build_query(array_filter([
			'ver' => $ver,
			'ruta' => $palabraClave === '' && $ruta !== '' ? $ruta : null,
			'palabra_clave' => $palabraClave !== '' ? $palabraClave : null,
		], fn($valor) => $valor !== null && $valor !== '')) . '">Limpiar</a>' : '') .
		'</form>';

	return $html;
}

/**
 * Lista recursivamente todas las subcarpetas dentro de un directorio base, omitiendo las especificadas.
 *
 * @param string $directorio Ruta de evaluación.
 * @param array $omitir Nombres exactos de las carpetas prohibidas / excluidas.
 * @return array Lista plana con las rutas absolutas resueltas a directorios.
 */
function listarCarpetas($directorio, $omitir)
{
	$directorio = realpath((string) $directorio);
	if (!$directorio || !is_dir($directorio)):
		return [];
	endif;

	$carpetas = [];
	$omitidas = array_fill_keys(array_map(
		static fn($nombre) => mb_strtolower((string) $nombre, 'UTF-8'),
		$omitir
	), true);

	$iterador = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator($directorio, RecursiveDirectoryIterator::SKIP_DOTS),
			static function (SplFileInfo $elemento) use ($omitidas): bool {
				if (!$elemento->isDir()):
					return false;
				endif;

				$nombre = $elemento->getFilename();
				if ($nombre === '' || $nombre[0] === '.'):
					return false;
				endif;

				return !isset($omitidas[mb_strtolower($nombre, 'UTF-8')]);
			}
		),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ($iterador as $elemento):
		$ruta = $elemento->getRealPath();
		if ($ruta !== false):
			$carpetas[] = $ruta;
		endif;
	endforeach;

	return $carpetas;
}

/**
 * Elimina recursivamente hacia arriba los directorios vacíos, respetando una barrera predefinida.
 * No eliminará directorios que cuenten con archivos ocultos o de sistema `.algo`, excepto
 * para eliminarlos automáticamente siempre y cuando no haya archivos "reales" visibles.
 *
 * @param string $directorio Directorio de arranque.
 * @param string $rootPermitido Directorio límite donde debe deternerse el flujo ascendente.
 * @return void
 */
function limpiarDirectoriosVacios(string $directorio, string $rootPermitido): void
{
	$directorioReal = realpath($directorio);
	$rootReal = realpath($rootPermitido);

	if (!$directorioReal || !$rootReal):
		return;
	endif;
	$rootReal = rtrim($rootReal, DIRECTORY_SEPARATOR);

	// Seguridad: asegurar que el directorio esté dentro del root permitido
	if ($directorioReal !== $rootReal && !str_starts_with($directorioReal, $rootReal . DIRECTORY_SEPARATOR)):
		return;
	endif;

	while ($directorioReal !== $rootReal):

		// Verificaciones extra
		if (
			!is_dir($directorioReal) ||
			is_link($directorioReal) ||
			!is_writable($directorioReal)
		):
			break;
		endif;

		$iterator = new FilesystemIterator(
			$directorioReal,
			FilesystemIterator::SKIP_DOTS
		);

		$tieneArchivosVisibles = false;

		foreach ($iterator as $item):

			// No seguir symlinks
			if ($item->isLink()):
				$tieneArchivosVisibles = true;
				break;
			endif;

			// Si es visible (no empieza con ".")
			if ($item->getFilename()[0] !== '.'):
				$tieneArchivosVisibles = true;
				break;
			endif;
		endforeach;

		if ($tieneArchivosVisibles):
			break;
		endif;

		// 1️⃣ Eliminar archivos ocultos primero
		foreach (new FilesystemIterator($directorioReal, FilesystemIterator::SKIP_DOTS) as $subItem):
			$nombre = $subItem->getFilename();
			if ($nombre[0] === '.' && !$subItem->isDir()):
				@unlink($subItem->getRealPath());
			endif;
		endforeach;

		// 2️⃣ Intentar borrar carpeta, si falla, salir
		if (!@rmdir($directorioReal)):
			break;
		endif;

		$directorioReal = dirname($directorioReal);

		// Revalidar que seguimos dentro del root
		if ($directorioReal !== $rootReal && !str_starts_with($directorioReal, $rootReal . DIRECTORY_SEPARATOR)):
			break;
		endif;
	endwhile;
}

/**
 * Envuelve el resultado de `var_dump()` en un marco visual (fieldset HTML con estilos css en línea)
 * para facilitar su depuración en el navegador.
 *
 * @param mixed $var La variable a imprimir.
 * @param string $nom El título que identificará a este volcado en la leyenda del recuadro.
 * @param bool $imprime Determina si se imprime directamente (echo) o si solamente devuelve la cadena (return).
 * @param bool $die Si es true y $imprime es true, detiene la ejecución del script (mata el proceso).
 * @return string|void Retorna el string HTML si $imprime es false.
 */
function var_dump_pre($var, $nom = "var_dump", $imprime = FALSE, $die = FALSE)
{
	$vardump =
		'<fieldset class="aviso vardump" style="box-sizing:border-box;min-width:auto;margin:.5rem;padding:.5rem;font-family:monospace;font-size:.8rem;border-radius:5px;box-shadow:2px 2px 8px;border:dotted thin;max-height:99vh;overflow:auto;box-sizing:border-box;">' .
		'<legend>' . escaparHtml($nom) . '</legend>' .
		'<pre>';
	ob_start();
	var_dump($var);
	$vardump .= escaparHtml(ob_get_clean()) . '</pre></fieldset>';

	if ($imprime):
		echo $vardump;
		if ($die):
			die("☠️");
		endif;
	else:
		return $vardump;
	endif;
}
/**
 * Colorea mensajes de salida de herramientas (ej. línea de comandos de exiftool, o ffmpeg)
 * devueltos típicamente en arrays. Asigna rojo a 'error', naranja a avisos y verde a éxito.
 *
 * @param string $r Línea o texto literal devuelto por la herramienta CLI.
 * @return string Mismo texto coloreado vía HTML `<span>`.
 */
function formatearRespuesta($r)
{
	$r = (string) $r;
	if (stripos($r, 'error') !== FALSE):
		$color = ' style="color: #f00;"';
	elseif ($r == '0 image files updated'):
		$color = ' style="color: orange;"';
	elseif (
		stripos($r, 'files updated') !== FALSE
		|| stripos($r, 'éxito') !== FALSE
	):
		$color = ' style="color: #0f0;"';
	else:
		$color = '';
	endif;
	return '<span' . $color . '>' . escaparHtml($r) . '</span>';
}
/**
 * Iterador personalizado de PHP SPL que hereda de `SplHeap` y permite ordenar en tiempo
 * real la información a iterar por ruta natural del archivo.
 * Recomendado para directorios con alta densidad de ficheros.
 */
class SortedIterator extends SplHeap
{
	public function __construct(Iterator $iterator)
	{
		foreach ($iterator as $item):
			$this->insert($item);
		endforeach;
	}

	public function compare($a, $b): int
	{
		return strcmp($a->getRealpath(), $b->getRealpath());
	}
}

/**
 * Envuelve un único archivo como iterador recursivo.
 *
 * Se declara una sola vez a nivel de módulo para evitar errores fatales por
 * redeclaración cuando la vista pide más de un recorrido durante el mismo
 * request.
 */
class SingleFileRecursiveIterator implements RecursiveIterator
{
	private SplFileInfo $file;
	private int $pos = 0;

	public function __construct(SplFileInfo $file)
	{
		$this->file = $file;
	}

	public function current(): SplFileInfo
	{
		return $this->file;
	}

	public function key(): int
	{
		return 0;
	}

	public function next(): void
	{
		$this->pos = 1;
	}

	public function rewind(): void
	{
		$this->pos = 0;
	}

	public function valid(): bool
	{
		return $this->pos === 0;
	}

	public function hasChildren(): bool
	{
		return false;
	}

	public function getChildren(): ?RecursiveIterator
	{
		return null;
	}
}

/**
 * Recupera metadatos del archivo a través de ExifTool (incluyendo Subject y Regions).
 * Reemplaza el antiguo y complejo parseo directo de bytes y XML.
 *
 * @param string $ruta Ruta del archivo a escanear.
 * @return array|string Posición 0: (string) Palabras clave csv. Posición 1: array multidimensional con áreas etiquetadas. Si falla string vacio o incompleto.
 */
function leerXMP($ruta)
{
	if (!is_file($ruta) || filesize($ruta) > 268000000):
		return ['', []];
	endif;

	$meta = obtenerMetadatos($ruta, ['Subject', 'RegionName', 'RegionAreaW', 'RegionAreaH', 'RegionAreaX', 'RegionAreaY'])['resultado'] ?? [];

	$subject = $meta['Subject'] ?? '';

	$regiones = [];
	foreach ($meta as $k => $s):
		if (str_starts_with($k, 'Region')):
			if (stripos($k, 'AppliedTo') === FALSE):
				$regiones[$k] = array_map('trim', explode(',', $s));
			else:
				$regiones[$k] = $s;
			endif;
		endif;
	endforeach;

	return [formatearPalabrasClave($subject), $regiones];
}
/**
 * Construye y devuelve el HTML dinámico de una barra inferior/superior de Paginación.
 * Maneja parámetros GET en memoria (`?media=...` o `?ruta=...`), ajusta el paginado (⏪, ⏩)
 * y establece visualizadores `input[type="range"]` para el zoom nativo o control por `$ver`.
 *
 * @param int|string $página_actual El bloque activo de página que se consume en la paginación de la UI.
 * @param int|string $total_de_paginas Tope máximo calculado de elementos entre el límite por página.
 * @param int|string $ver Parámetro que define el comportamiento del scroll / zoom de la grilla.
 * @param string $ruta Si existe `$ruta`, lo adjunta al listado para no perder el rastro GET.
 * @param string $estilo Estilo de paginación a utilizar: 'completo' o 'condensado'.
 * @return string Renderizado HTML completo de la caja del paginador form.
 */
function paginacion($página_actual, $total_de_paginas, $ver = 0, $ruta = '', $estilo = 'completo')
{
	$mediaparam = '';
	$mediahidden = '';
	$rutahidden = '';
	$filtroparam = parametrosFiltrosMetadatos();
	$filtrohidden = inputsOcultosFiltrosMetadatos();
	$palabraparam = parametroPalabraClaveActiva();
	$palabrahidden = inputOcultoPalabraClaveActiva();
	$palabraClaveActiva = obtenerPalabraClaveActiva();
	if (defined("VIDEOS") && VIDEOS):
		$mediaparam = '&media=videos';
		$mediahidden = '<input type="hidden" name="media" value="videos">';
	endif;
	if (defined("FOTOS") && FOTOS):
		$mediaparam = '&media=fotos';
		$mediahidden = '<input type="hidden" name="media" value="fotos">';
	endif;
	if (defined("VIDEOS") && VIDEOS && defined("FOTOS") && FOTOS):
		$mediaparam = '';
		$mediahidden = '';
	endif;

	$rutaparam = '';
	if ($palabraClaveActiva === ''):
		if (!empty($ruta)):
			$ruta = trim(str_replace(dirname(__DIR__), '', $ruta), DIRECTORY_SEPARATOR);
			if ($ruta !== '' && is_dir($ruta)):
				$rutaparam = '&ruta=' . rawurlencode($ruta);
				$rutahidden = '<input type="hidden" name="ruta" value="' . htmlspecialchars($ruta, ENT_QUOTES, 'UTF-8') . '">';
			endif;
		elseif (defined("CARPETA")):
			$ruta = trim(str_replace(dirname(__DIR__), '', CARPETA), DIRECTORY_SEPARATOR);
			if ($ruta !== '' && is_dir($ruta)):
				$rutaparam = '&ruta=' . rawurlencode($ruta);
				$rutahidden = '<input type="hidden" name="ruta" value="' . htmlspecialchars($ruta, ENT_QUOTES, 'UTF-8') . '">';
			endif;
		endif;
	endif;

	switch ($estilo):
		case 'completo':
		case 'condensado':
			break;
		default:
			$estilo = 'completo';
			break;
	endswitch;

	$html = '<nav class="paginación ' . $estilo . '">';
	$verparam = '';
	if ((bool) $ver):
		$verparam = '&ver=' . $ver;
	else:
		$ver = '3';
		$verparam = '&ver=3';
	endif;
	if (1 < $página_actual):
		$html .=
			'<a href="?pagina=1' . $verparam . $mediaparam . $rutaparam . $filtroparam . $palabraparam . '" style="text-decoration:none;" title="Inicio">' .
			'⏮️</a>' .
			' <a href="?pagina=' . ($página_actual - 1) . $verparam . $mediaparam . $rutaparam . $filtroparam . $palabraparam . '" style="text-decoration:none;" title="Anterior">' .
			'◀️</a>';
	else:
		$html .= '<span style="opacity:0.3">⏮️ ◀️</span>';
	endif;
	$html .= ' <span>' . estilizarNúmeros($página_actual) . '╱' . estilizarNúmeros($total_de_paginas) . '</span> ';
	if ($total_de_paginas > $página_actual):
		$html .=
			'<a href="?pagina=' . ($página_actual + 1) . $verparam . $mediaparam . $rutaparam . $filtroparam . $palabraparam . '" style="text-decoration:none;" title="Siguiente">' .
			'▶️</a> ' .
			'<a href="?pagina=' . $total_de_paginas . $verparam . $mediaparam . $rutaparam . $filtroparam . $palabraparam . '" style="text-decoration:none;" title="Última página">' .
			'⏭️</a> ';
	else:
		$html .= '<span style="opacity:0.3">▶️ ⏭️</span>';
	endif;
	$html .=
		' <form method="get" style="display:inline-block;vertical-align:middle;margin-left:2rem;">' .
		'📄<select name="pagina">';
	for ($i = 1; $i <= $total_de_paginas; $i++):
		if ($i == $página_actual):
			$selected = ' selected';
		else:
			$selected = '';
		endif;
		$html .= '<option value="' . $i . '" ' . $selected . '>';
		$html .= $i;
		$html .= '</option>';
	endfor;
	$html .=
		'</select> ' .
		'<span class="slider">' .
		'<input type="range" name="ver" min="3" max="21" step="1" value="' . $ver . '" list="markers"/>';
	$html .=
		'<datalist id="markers">';
	for ($i = 3; $i <= 21; $i += 3):
		$html .= '<option value="' . $i . '">';
		$html .= $i;
		$html .= '</option>';
	endfor;
	$html .= '</datalist>';

	$html .= '<div class="ticks">';
	for ($i = 3; $i <= 21; $i += 3):
		$html .= '<span style="--v:' . $i . '">';
		$html .= $i;
		$html .= '</span>';
	endfor;
	$html .= '</div>';
	$html .=
		'</span>' .
		$mediahidden .
		$filtrohidden .
		$rutahidden .
		$palabrahidden .
		'<input type="submit" value="Ver">' .
		'</form>' .
		'</nav>';

	return $html;
}
/**
 * Convierte un bloque numérico corriente a caracteres UNICODE 'Double-Struck' o especiales,
 * útil en paginaciones para resaltar estéticamente la página.
 *
 * @param mixed $número Conjunto de uno o múltiples dígitos (int o string).
 * @return string Equivalente en string unicode 𝟘𝟙𝟚𝟛𝟜𝟝𝟞𝟟𝟠𝟡 para estética.
 */
function estilizarNúmeros(mixed $número): string
{
	if (empty($número) && $número != 0):
		return '';
	endif;

	$numerales = ["𝟘", "𝟙", "𝟚", "𝟛", "𝟜", "𝟝", "𝟞", "𝟟", "𝟠", "𝟡"];
	$estilizado = '';

	$tmp = mb_str_split($número, 1, 'UTF-8');
	foreach ($tmp as $n):
		$estilizado .= $numerales[$n];
	endforeach;
	return $estilizado;
}
/**
 * Aplica normalización a forma C de la base UNICODE.
 * Asegura que vocales y tildes dispersos queden agrupados si el host soporta clase Normalizer.
 * Hace corrección de ortografía de algunas palabras comunes usadas por los usuarios
 *
 * @param string $tag Etiqueta cruda obtenida del SO.
 * @return string Etiqueta unificada NFD / NFC bajo base estandar de texto.
 */
function normalizarTag(string $tag): string
{
	if (class_exists('Normalizer')):
		$tag = Normalizer::normalize($tag, Normalizer::FORM_C);
	endif;

	$mal = [
		'ejericio',
		'lenceria',
	];
	$bien = [
		'ejercicio',
		'lencería'
	];
	$tag = str_replace($mal, $bien, $tag);
	return $tag;
}

/**
 * Normaliza una lista de palabras clave y elimina duplicados preservando el primer valor.
 *
 * @param mixed $valor Lista CSV, arreglo plano/anidado o valor simple.
 * @return array<int, string> Palabras clave listas para escribir o mostrar.
 */
function normalizarPalabrasClave(mixed $valor): array
{
	$partes = [];
	if (is_array($valor)):
		foreach ($valor as $item):
			$partes = array_merge($partes, normalizarPalabrasClave($item));
		endforeach;
	else:
		$partes = explode(',', (string) $valor);
	endif;

	$palabras = [];
	$vistas = [];
	foreach ($partes as $parte):
		$tag = normalizarTag(trim((string) $parte));
		$tag = trim((string) preg_replace('/\s+/u', ' ', $tag));
		if ($tag === ''):
			continue;
		endif;

		$clave = mb_strtolower($tag, 'UTF-8');
		if (isset($vistas[$clave])):
			continue;
		endif;
		$vistas[$clave] = true;
		$palabras[] = $tag;
	endforeach;

	return $palabras;
}

/**
 * Devuelve las palabras clave que aparecen más de una vez tras normalizarlas.
 *
 * @param mixed $valor Lista CSV, arreglo plano/anidado o valor simple.
 * @return array<int, string>
 */
function obtenerPalabrasClaveDuplicadas(mixed $valor): array
{
	$aplanar = function (mixed $entrada) use (&$aplanar): array {
		$partes = [];
		if (is_array($entrada)):
			foreach ($entrada as $item):
				$partes = array_merge($partes, $aplanar($item));
			endforeach;
			return $partes;
		endif;

		return explode(',', (string) $entrada);
	};
	$partes = $aplanar($valor);

	$vistas = [];
	$duplicadas = [];
	foreach ($partes as $parte):
		$tag = normalizarTag(trim((string) $parte));
		$tag = trim((string) preg_replace('/\s+/u', ' ', $tag));
		if ($tag === ''):
			continue;
		endif;

		$clave = mb_strtolower($tag, 'UTF-8');
		if (isset($vistas[$clave])):
			$duplicadas[] = $tag;
			continue;
		endif;
		$vistas[$clave] = true;
	endforeach;

	return normalizarPalabrasClave($duplicadas);
}

/**
 * Indica si una lista de palabras clave contiene duplicados normalizados.
 */
function tienePalabrasClaveDuplicadas(mixed $valor): bool
{
	return !empty(obtenerPalabrasClaveDuplicadas($valor));
}

/**
 * Devuelve una lista de palabras clave normalizada como CSV visible.
 */
function formatearPalabrasClave(mixed $valor): string
{
	return implode(', ', normalizarPalabrasClave($valor));
}
?>
