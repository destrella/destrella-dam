<?php

const CATALOGO_MULTIMEDIA_DB_VERSION = 1;

function catalogoDirectorioDatos(): string
{
	return proyectoRaiz() . DIRECTORY_SEPARATOR . 'datos';
}

function catalogoRutaBaseDatos(): string
{
	return catalogoDirectorioDatos() . DIRECTORY_SEPARATOR . 'catalogo_multimedia.sqlite';
}

function catalogoPrepararDatos(): bool
{
	$directorio = catalogoDirectorioDatos();
	if (!is_dir($directorio) && !mkdir($directorio, 0755, true)):
		return false;
	endif;

	return is_dir($directorio) && is_writable($directorio);
}

function conectarCatalogoMultimedia(): ?PDO
{
	static $pdo = null;
	if ($pdo instanceof PDO):
		return $pdo;
	endif;
	if (!catalogoPrepararDatos()):
		return null;
	endif;

	try {
		$pdo = new PDO('sqlite:' . catalogoRutaBaseDatos());
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec('PRAGMA journal_mode = WAL');
		$pdo->exec('PRAGMA foreign_keys = ON');
		$pdo->exec("
			CREATE TABLE IF NOT EXISTS medios (
				ruta TEXT PRIMARY KEY,
				ruta_relativa TEXT NOT NULL DEFAULT '',
				directorio TEXT NOT NULL,
				nombre TEXT NOT NULL,
				extension TEXT NOT NULL,
				tipo TEXT NOT NULL,
				mtime INTEGER NOT NULL,
				tamano INTEGER NOT NULL,
				ancho INTEGER NOT NULL DEFAULT 0,
				alto INTEGER NOT NULL DEFAULT 0,
				duracion REAL NOT NULL DEFAULT 0,
				md5 TEXT NOT NULL DEFAULT '',
				sha256 TEXT NOT NULL DEFAULT '',
				contenido_hash TEXT NOT NULL DEFAULT '',
				perceptual_hash TEXT NOT NULL DEFAULT '',
				firma_version INTEGER NOT NULL DEFAULT 0,
				hash_actualizado INTEGER NOT NULL DEFAULT 0,
				geo INTEGER NOT NULL DEFAULT 0,
				regiones INTEGER NOT NULL DEFAULT 0,
				rotacion INTEGER NOT NULL DEFAULT 0,
				palabras INTEGER NOT NULL DEFAULT 0,
				sugerencias INTEGER NOT NULL DEFAULT 0,
				duplicadas INTEGER NOT NULL DEFAULT 0,
				tracking INTEGER NOT NULL DEFAULT 0,
				metadatos_actualizado INTEGER NOT NULL DEFAULT 0,
				palabras_actualizado INTEGER NOT NULL DEFAULT 0,
				existente INTEGER NOT NULL DEFAULT 1,
				verificado INTEGER NOT NULL DEFAULT 0,
				actualizado INTEGER NOT NULL DEFAULT 0
			)
		");
		$pdo->exec("
			CREATE TABLE IF NOT EXISTS estado_catalogo (
				clave TEXT PRIMARY KEY,
				valor TEXT NOT NULL
			)
		");
		$pdo->exec('CREATE INDEX IF NOT EXISTS idx_catalogo_mtime ON medios(existente, mtime DESC, ruta ASC)');
		$pdo->exec('CREATE INDEX IF NOT EXISTS idx_catalogo_tipo_mtime ON medios(existente, tipo, mtime DESC)');
		$pdo->exec('CREATE INDEX IF NOT EXISTS idx_catalogo_directorio ON medios(directorio)');
		$pdo->exec('CREATE INDEX IF NOT EXISTS idx_catalogo_md5 ON medios(md5)');
		$pdo->exec('CREATE INDEX IF NOT EXISTS idx_catalogo_sha256 ON medios(sha256)');
		$pdo->exec('CREATE INDEX IF NOT EXISTS idx_catalogo_contenido ON medios(contenido_hash)');
		catalogoGuardarEstado($pdo, 'version', (string) CATALOGO_MULTIMEDIA_DB_VERSION);
	} catch (PDOException $e) {
		trigger_error("Error al abrir catalogo multimedia: [{$e->getCode()}] {$e->getMessage()}", E_USER_WARNING);
		$pdo = null;
	}

	return $pdo;
}

function catalogoGuardarEstado(PDO $pdo, string $clave, string $valor): void
{
	$stmt = $pdo->prepare("
		INSERT INTO estado_catalogo (clave, valor)
		VALUES (:clave, :valor)
		ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor
	");
	$stmt->execute([':clave' => $clave, ':valor' => $valor]);
}

function catalogoLeerEstado(string $clave, string $default = ''): string
{
	$pdo = conectarCatalogoMultimedia();
	if (!$pdo):
		return $default;
	endif;

	try {
		$stmt = $pdo->prepare('SELECT valor FROM estado_catalogo WHERE clave = :clave LIMIT 1');
		$stmt->execute([':clave' => $clave]);
		$valor = $stmt->fetchColumn();
		return is_string($valor) ? $valor : $default;
	} catch (PDOException $e) {
		return $default;
	}
}

function catalogoNormalizarRuta(string $ruta): string
{
	$ruta = trim(str_replace('\\', '/', $ruta));
	if ($ruta === ''):
		return '';
	endif;

	$real = realpath($ruta);
	if ($real !== false):
		return str_replace('\\', '/', $real);
	endif;

	if (function_exists('normalizarRutaIndicePalabrasClave')):
		return normalizarRutaIndicePalabrasClave($ruta);
	endif;

	if (str_starts_with($ruta, '/')):
		return $ruta;
	endif;

	return str_replace('\\', '/', proyectoRaiz() . DIRECTORY_SEPARATOR . ltrim($ruta, '/'));
}

function catalogoDatosArchivo(string $ruta, ?string $tipo = null, array $extra = []): ?array
{
	$ruta = catalogoNormalizarRuta($ruta);
	if ($ruta === '' || !is_file($ruta)):
		return null;
	endif;

	$tipo = in_array($tipo, ['img', 'vid'], true) ? $tipo : (tipoMultimediaDesdeRuta($ruta) ?? '');
	if ($tipo === ''):
		return null;
	endif;

	$extension = mb_strtolower((string) pathinfo($ruta, PATHINFO_EXTENSION), 'UTF-8');
	$datos = [
		'ruta' => $ruta,
		'ruta_relativa' => rutaRelativaParaParametro($ruta),
		'directorio' => str_replace('\\', '/', dirname($ruta)),
		'nombre' => basename($ruta),
		'extension' => $extension,
		'tipo' => $tipo,
		'mtime' => filemtime($ruta) ?: 0,
		'tamano' => filesize($ruta) ?: 0,
		'ancho' => 0,
		'alto' => 0,
		'duracion' => 0.0,
		'md5' => '',
		'sha256' => '',
		'contenido_hash' => '',
		'perceptual_hash' => '',
		'firma_version' => 0,
		'hash_actualizado' => 0,
		'geo' => 0,
		'regiones' => 0,
		'rotacion' => 0,
		'palabras' => 0,
		'sugerencias' => 0,
		'duplicadas' => 0,
		'tracking' => 0,
		'metadatos_actualizado' => 0,
		'palabras_actualizado' => 0,
		'existente' => 1,
		'verificado' => 0,
		'actualizado' => time(),
	];

	foreach ($extra as $clave => $valor):
		if (array_key_exists($clave, $datos)):
			$datos[$clave] = $valor;
		endif;
	endforeach;

	return $datos;
}

function catalogoStatementGuardar(PDO $pdo): PDOStatement
{
	static $statements = [];
	$key = spl_object_id($pdo);
	if (isset($statements[$key])):
		return $statements[$key];
	endif;

	$statements[$key] = $pdo->prepare("
		INSERT INTO medios (
			ruta, ruta_relativa, directorio, nombre, extension, tipo, mtime, tamano,
			ancho, alto, duracion, md5, sha256, contenido_hash, perceptual_hash,
			firma_version, hash_actualizado, geo, regiones, rotacion, palabras,
			sugerencias, duplicadas, tracking, metadatos_actualizado,
			palabras_actualizado, existente, verificado, actualizado
		) VALUES (
			:ruta, :ruta_relativa, :directorio, :nombre, :extension, :tipo, :mtime, :tamano,
			:ancho, :alto, :duracion, :md5, :sha256, :contenido_hash, :perceptual_hash,
			:firma_version, :hash_actualizado, :geo, :regiones, :rotacion, :palabras,
			:sugerencias, :duplicadas, :tracking, :metadatos_actualizado,
			:palabras_actualizado, :existente, :verificado, :actualizado
		)
		ON CONFLICT(ruta) DO UPDATE SET
			ruta_relativa = excluded.ruta_relativa,
			directorio = excluded.directorio,
			nombre = excluded.nombre,
			extension = excluded.extension,
			tipo = excluded.tipo,
			mtime = excluded.mtime,
			tamano = excluded.tamano,
			ancho = CASE
				WHEN excluded.ancho > 0 THEN excluded.ancho
				WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN 0
				ELSE medios.ancho
			END,
			alto = CASE
				WHEN excluded.alto > 0 THEN excluded.alto
				WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN 0
				ELSE medios.alto
			END,
			duracion = CASE
				WHEN excluded.duracion > 0 THEN excluded.duracion
				WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN 0
				ELSE medios.duracion
			END,
			md5 = CASE
				WHEN excluded.md5 <> '' THEN excluded.md5
				WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN ''
				ELSE medios.md5
			END,
			sha256 = CASE
				WHEN excluded.sha256 <> '' THEN excluded.sha256
				WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN ''
				ELSE medios.sha256
			END,
			contenido_hash = CASE
				WHEN excluded.contenido_hash <> '' THEN excluded.contenido_hash
				WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN ''
				ELSE medios.contenido_hash
			END,
			perceptual_hash = CASE
				WHEN excluded.perceptual_hash <> '' THEN excluded.perceptual_hash
				WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN ''
				ELSE medios.perceptual_hash
			END,
			firma_version = CASE
				WHEN excluded.firma_version > 0 THEN excluded.firma_version
				WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN 0
				ELSE medios.firma_version
			END,
			hash_actualizado = CASE
				WHEN excluded.hash_actualizado > 0 THEN excluded.hash_actualizado
				WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN 0
				ELSE medios.hash_actualizado
			END,
			geo = CASE WHEN excluded.metadatos_actualizado > 0 THEN excluded.geo WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN 0 ELSE medios.geo END,
			regiones = CASE WHEN excluded.metadatos_actualizado > 0 THEN excluded.regiones WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN 0 ELSE medios.regiones END,
			rotacion = CASE WHEN excluded.metadatos_actualizado > 0 THEN excluded.rotacion WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN 0 ELSE medios.rotacion END,
			palabras = CASE WHEN excluded.metadatos_actualizado > 0 OR excluded.palabras_actualizado > 0 THEN excluded.palabras WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN 0 ELSE medios.palabras END,
			sugerencias = CASE WHEN excluded.metadatos_actualizado > 0 THEN excluded.sugerencias WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN 0 ELSE medios.sugerencias END,
			duplicadas = CASE WHEN excluded.metadatos_actualizado > 0 THEN excluded.duplicadas WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN 0 ELSE medios.duplicadas END,
			tracking = CASE WHEN excluded.metadatos_actualizado > 0 THEN excluded.tracking WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN 0 ELSE medios.tracking END,
			metadatos_actualizado = CASE
				WHEN excluded.metadatos_actualizado > 0 THEN excluded.metadatos_actualizado
				WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN 0
				ELSE medios.metadatos_actualizado
			END,
			palabras_actualizado = CASE
				WHEN excluded.palabras_actualizado > 0 THEN excluded.palabras_actualizado
				WHEN medios.mtime <> excluded.mtime OR medios.tamano <> excluded.tamano THEN 0
				ELSE medios.palabras_actualizado
			END,
			existente = excluded.existente,
			verificado = CASE WHEN excluded.verificado > 0 THEN excluded.verificado ELSE medios.verificado END,
			actualizado = excluded.actualizado
	");
	return $statements[$key];
}

function catalogoGuardarMedio(PDO $pdo, array $datos): bool
{
	$campos = [
		'ruta', 'ruta_relativa', 'directorio', 'nombre', 'extension', 'tipo', 'mtime', 'tamano',
		'ancho', 'alto', 'duracion', 'md5', 'sha256', 'contenido_hash', 'perceptual_hash',
		'firma_version', 'hash_actualizado', 'geo', 'regiones', 'rotacion', 'palabras',
		'sugerencias', 'duplicadas', 'tracking', 'metadatos_actualizado', 'palabras_actualizado',
		'existente', 'verificado', 'actualizado'
	];
	$bindings = [];
	foreach ($campos as $campo):
		$bindings[':' . $campo] = $datos[$campo] ?? match ($campo) {
			'duracion' => 0.0,
			'existente' => 1,
			'actualizado' => time(),
			default => 0,
		};
	endforeach;

	try {
		catalogoStatementGuardar($pdo)->execute($bindings);
		return true;
	} catch (PDOException $e) {
		return false;
	}
}

function catalogoRegistrarArchivo(string $ruta, ?string $tipo = null, array $extra = []): bool
{
	$pdo = conectarCatalogoMultimedia();
	$datos = catalogoDatosArchivo($ruta, $tipo, $extra);
	if (!$pdo || !$datos):
		return false;
	endif;

	return catalogoGuardarMedio($pdo, $datos);
}

function catalogoEliminarMedio(string $ruta): void
{
	$pdo = conectarCatalogoMultimedia();
	if (!$pdo):
		return;
	endif;

	$rutas = function_exists('rutasEquivalentesIndicePalabrasClave')
		? rutasEquivalentesIndicePalabrasClave($ruta)
		: [$ruta];
	$stmt = $pdo->prepare('UPDATE medios SET existente = 0, actualizado = :actualizado WHERE ruta = :ruta');
	foreach ($rutas as $candidata):
		$normalizada = catalogoNormalizarRuta((string) $candidata);
		if ($normalizada === ''):
			continue;
		endif;
		$stmt->execute([':ruta' => $normalizada, ':actualizado' => time()]);
	endforeach;
}

function catalogoLimpiarFirmasMedio(string $ruta): void
{
	$pdo = conectarCatalogoMultimedia();
	if (!$pdo):
		return;
	endif;

	$ruta = catalogoNormalizarRuta($ruta);
	if ($ruta === ''):
		return;
	endif;

	$stmt = $pdo->prepare("
		UPDATE medios
		SET md5 = '', sha256 = '', contenido_hash = '', perceptual_hash = '',
			firma_version = 0, hash_actualizado = 0, actualizado = :actualizado
		WHERE ruta = :ruta
	");
	$stmt->execute([':ruta' => $ruta, ':actualizado' => time()]);
}

function catalogoActualizarFirmasMedio(string $ruta, string $tipo, array $hashes): void
{
	catalogoRegistrarArchivo($ruta, $tipo, [
		'ancho' => (int) ($hashes['ancho'] ?? 0),
		'alto' => (int) ($hashes['alto'] ?? 0),
		'duracion' => (float) ($hashes['duracion'] ?? 0.0),
		'md5' => strtolower((string) ($hashes['md5'] ?? '')),
		'sha256' => strtolower((string) ($hashes['sha256'] ?? '')),
		'contenido_hash' => strtolower((string) ($hashes['contenido_hash'] ?? '')),
		'perceptual_hash' => strtolower((string) ($hashes['perceptual_hash'] ?? '')),
		'firma_version' => (int) ($hashes['firma_version'] ?? 0),
		'hash_actualizado' => time(),
	]);
}

function catalogoActualizarEstadoMetadatos(string $ruta, array $estado): void
{
	catalogoRegistrarArchivo($ruta, null, [
		'geo' => !empty($estado['geo']) ? 1 : 0,
		'regiones' => !empty($estado['regiones']) ? 1 : 0,
		'rotacion' => !empty($estado['rotacion']) ? 1 : 0,
		'palabras' => !empty($estado['palabras']) ? 1 : 0,
		'sugerencias' => !empty($estado['sugerencias']) ? 1 : 0,
		'duplicadas' => !empty($estado['duplicadas']) ? 1 : 0,
		'tracking' => !empty($estado['tracking']) ? 1 : 0,
		'metadatos_actualizado' => time(),
	]);
}

function catalogoActualizarPalabrasMedio(string $ruta, string $tipo, array $palabras): void
{
	catalogoRegistrarArchivo($ruta, $tipo, [
		'palabras' => !empty($palabras) ? 1 : 0,
		'palabras_actualizado' => time(),
	]);
}

function catalogoRutaDentroDeBase(string $ruta, string $base): bool
{
	$ruta = rtrim(str_replace('\\', '/', $ruta), '/');
	$base = rtrim(str_replace('\\', '/', $base), '/');
	return $ruta === $base || str_starts_with($ruta, $base . '/');
}

function catalogoResultadosMultimedia(
	string $rutaIterador,
	?string $unarchivo,
	array $omitir,
	?string $media = null
): ?array {
	unset($omitir);
	$pdo = conectarCatalogoMultimedia();
	if (!$pdo || catalogoLeerEstado('ultimo_sync', '') === ''):
		return null;
	endif;

	$rootCatalogado = catalogoLeerEstado('root', proyectoRaiz());
	$rutaBase = realpath($rutaIterador);
	if (!$rutaBase):
		return [];
	endif;
	$rutaBase = str_replace('\\', '/', $rutaBase);
	$rootCatalogado = catalogoNormalizarRuta($rootCatalogado);
	if ($rootCatalogado !== '' && !catalogoRutaDentroDeBase($rutaBase, $rootCatalogado)):
		return null;
	endif;

	$filtrarFotos = false;
	$filtrarVideos = false;
	if ($media === 'fotos'):
		$filtrarFotos = true;
	elseif ($media === 'videos'):
		$filtrarVideos = true;
	elseif ($media === null):
		$filtrarFotos = defined('FOTOS') && FOTOS;
		$filtrarVideos = defined('VIDEOS') && VIDEOS;
	endif;

	$tipos = [];
	if ($filtrarFotos || (!$filtrarFotos && !$filtrarVideos)):
		$tipos[] = 'img';
	endif;
	if ($filtrarVideos || (!$filtrarFotos && !$filtrarVideos)):
		$tipos[] = 'vid';
	endif;
	if (empty($tipos)):
		return [];
	endif;

	$params = [':existente' => 1];
	$where = ['existente = :existente'];
	if ($unarchivo !== null):
		$rutaArchivo = catalogoNormalizarRuta($unarchivo);
		$where[] = 'ruta = :ruta';
		$params[':ruta'] = $rutaArchivo;
	else:
		$prefijo = rtrim($rutaBase, '/') . '/';
		$where[] = 'substr(ruta, 1, :prefijo_len) = :prefijo';
		$params[':prefijo'] = $prefijo;
		$params[':prefijo_len'] = strlen($prefijo);
	endif;

	if (count($tipos) === 1):
		$where[] = 'tipo = :tipo';
		$params[':tipo'] = $tipos[0];
	endif;

	try {
		$stmt = $pdo->prepare('SELECT ruta, tipo FROM medios WHERE ' . implode(' AND ', $where) . ' ORDER BY mtime DESC, ruta ASC');
		$stmt->execute($params);
		$filas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (PDOException $e) {
		return null;
	}

	$resultados = [];
	foreach ($filas as $fila):
		$ruta = (string) ($fila['ruta'] ?? '');
		$tipo = (string) ($fila['tipo'] ?? '');
		if ($ruta !== '' && in_array($tipo, $tipos, true)):
			$resultados[] = [$ruta, $tipo];
		endif;
	endforeach;

	return $resultados;
}

function catalogoMigrarDesdeDuplicados(PDO $pdo): int
{
	$rutaDb = catalogoDirectorioDatos() . DIRECTORY_SEPARATOR . 'duplicados_hashes.sqlite';
	if (!is_file($rutaDb)):
		return 0;
	endif;

	try {
		$origen = new PDO('sqlite:' . $rutaDb);
		$origen->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$filas = $origen->query('SELECT * FROM archivos_hash')->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (PDOException $e) {
		return 0;
	}

	$total = 0;
	foreach ($filas as $fila):
		$ruta = catalogoNormalizarRuta((string) ($fila['ruta'] ?? ''));
		if ($ruta === '' || !is_file($ruta)):
			continue;
		endif;
		$tipo = (string) ($fila['tipo'] ?? (tipoMultimediaDesdeRuta($ruta) ?? ''));
		$vigente = (int) ($fila['mtime'] ?? -1) === (int) filemtime($ruta)
			&& (int) ($fila['tamano'] ?? -1) === (int) filesize($ruta);
		$extra = [];
		if ($vigente):
			$extra = [
				'ancho' => (int) ($fila['ancho'] ?? 0),
				'alto' => (int) ($fila['alto'] ?? 0),
				'duracion' => (float) ($fila['duracion'] ?? 0.0),
				'md5' => strtolower((string) ($fila['md5'] ?? '')),
				'sha256' => strtolower((string) ($fila['sha256'] ?? '')),
				'contenido_hash' => strtolower((string) ($fila['contenido_hash'] ?? '')),
				'perceptual_hash' => strtolower((string) ($fila['perceptual_hash'] ?? '')),
				'firma_version' => (int) ($fila['firma_version'] ?? 0),
				'hash_actualizado' => (int) ($fila['actualizado'] ?? time()),
			];
		endif;
		$datos = catalogoDatosArchivo($ruta, $tipo, $extra);
		if ($datos && catalogoGuardarMedio($pdo, $datos)):
			$total++;
		endif;
	endforeach;

	return $total;
}

function catalogoMigrarDesdeFiltros(PDO $pdo): int
{
	if (function_exists('conectarBaseFiltrosMetadatos')):
		conectarBaseFiltrosMetadatos();
	endif;
	$rutaDb = catalogoDirectorioDatos() . DIRECTORY_SEPARATOR . 'filtros_metadatos.sqlite';
	if (!is_file($rutaDb)):
		return 0;
	endif;

	try {
		$origen = new PDO('sqlite:' . $rutaDb);
		$origen->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$filas = $origen->query("
			SELECT
				a.ruta,
				a.tipo,
				a.mtime AS palabras_mtime,
				a.tamano AS palabras_tamano,
				a.actualizado AS palabras_actualizado,
				e.geo,
				e.regiones,
				e.rotacion,
				e.palabras,
				e.sugerencias,
				e.duplicadas,
				e.tracking,
				e.mtime AS meta_mtime,
				e.tamano AS meta_tamano,
				e.actualizado AS metadatos_actualizado
			FROM archivos_palabras_clave a
			LEFT JOIN estados e ON e.ruta = a.ruta
			UNION
			SELECT
				e.ruta,
				'',
				0,
				0,
				0,
				e.geo,
				e.regiones,
				e.rotacion,
				e.palabras,
				e.sugerencias,
				e.duplicadas,
				e.tracking,
				e.mtime,
				e.tamano,
				e.actualizado
			FROM estados e
			LEFT JOIN archivos_palabras_clave a ON a.ruta = e.ruta
			WHERE a.ruta IS NULL
		")->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (PDOException $e) {
		return 0;
	}

	$total = 0;
	foreach ($filas as $fila):
		$ruta = catalogoNormalizarRuta((string) ($fila['ruta'] ?? ''));
		if ($ruta === '' || !is_file($ruta)):
			continue;
		endif;
		$tipo = (string) ($fila['tipo'] ?? '');
		$tipo = in_array($tipo, ['img', 'vid'], true) ? $tipo : (tipoMultimediaDesdeRuta($ruta) ?? '');
		if ($tipo === ''):
			continue;
		endif;
		$palabrasVigentes = (int) ($fila['palabras_mtime'] ?? -1) === (int) filemtime($ruta)
			&& (int) ($fila['palabras_tamano'] ?? -1) === (int) filesize($ruta);
		$metadatosVigentes = (int) ($fila['meta_mtime'] ?? -1) === (int) filemtime($ruta)
			&& (int) ($fila['meta_tamano'] ?? -1) === (int) filesize($ruta);
		$extra = [];
		if ($palabrasVigentes):
			$extra['palabras'] = (int) ($fila['palabras'] ?? 0);
			$extra['palabras_actualizado'] = (int) ($fila['palabras_actualizado'] ?? time());
		endif;
		if ($metadatosVigentes):
			foreach (['geo', 'regiones', 'rotacion', 'palabras', 'sugerencias', 'duplicadas', 'tracking'] as $campo):
				$extra[$campo] = (int) ($fila[$campo] ?? 0);
			endforeach;
			$extra['metadatos_actualizado'] = (int) ($fila['metadatos_actualizado'] ?? time());
		endif;
		$datos = catalogoDatosArchivo($ruta, $tipo, $extra);
		if ($datos && catalogoGuardarMedio($pdo, $datos)):
			$total++;
		endif;
	endforeach;

	return $total;
}

function catalogoSincronizarFilesystem(PDO $pdo, string $root, array $omitir, ?callable $emitir = null, int $limite = 0): int
{
	$root = catalogoNormalizarRuta($root);
	if ($root === '' || !is_dir($root)):
		return 0;
	endif;

	$emitir ??= static function (array $evento): void {
	};
	$limite = max(0, $limite);
	$run = time();
	$total = 0;
	$emitir(['tipo' => 'filesystem_inicio', 'mensaje' => 'Escaneando archivos locales...']);

	$iterador = obtenerIteradorArchivos($root, null, $omitir);
	foreach ($iterador as $archivo):
		if (!$archivo->isFile()):
			continue;
		endif;
		$ruta = $archivo->getPathname();
		$tipo = tipoMultimediaDesdeRuta($ruta);
		if ($tipo === null):
			continue;
		endif;
		$datos = catalogoDatosArchivo($ruta, $tipo, ['verificado' => $run]);
		if ($datos && catalogoGuardarMedio($pdo, $datos)):
			$total++;
			if ($total % 500 === 0):
				$emitir(['tipo' => 'filesystem_progreso', 'total' => $total, 'mensaje' => $total . ' archivos catalogados...']);
			endif;
		endif;
		if ($limite > 0 && $total >= $limite):
			break;
		endif;
	endforeach;

	if ($limite === 0):
		$prefijo = rtrim($root, '/') . '/';
		$stmt = $pdo->prepare("
			UPDATE medios
			SET existente = 0, actualizado = :actualizado
			WHERE existente = 1
				AND substr(ruta, 1, :prefijo_len) = :prefijo
				AND verificado <> :verificado
		");
		$stmt->execute([
			':actualizado' => time(),
			':prefijo' => $prefijo,
			':prefijo_len' => strlen($prefijo),
			':verificado' => $run,
		]);
	endif;

	$emitir(['tipo' => 'filesystem_fin', 'total' => $total, 'mensaje' => $total . ' archivos locales catalogados.']);
	return $total;
}

function catalogoMigrarExistente(?array $omitir = null, ?callable $emitir = null, int $limite = 0, ?string $root = null): array
{
	$pdo = conectarCatalogoMultimedia();
	if (!$pdo):
		return ['ok' => false, 'error' => 'No se pudo abrir el catalogo.', 'total' => 0];
	endif;

	$emitir ??= static function (array $evento): void {
	};
	$omitir ??= carpetasIgnoradasConfiguracion();
	$root ??= proyectoRaiz();
	$inicio = microtime(true);
	$emitir(['tipo' => 'inicio', 'mensaje' => 'Migrando catalogo multimedia...']);

	$duplicados = 0;
	$filtros = 0;
	$filesystem = 0;
	try {
		$pdo->beginTransaction();
		$duplicados = catalogoMigrarDesdeDuplicados($pdo);
		$filtros = catalogoMigrarDesdeFiltros($pdo);
		$filesystem = catalogoSincronizarFilesystem($pdo, $root, $omitir, $emitir, $limite);
		catalogoGuardarEstado($pdo, 'root', catalogoNormalizarRuta($root));
		catalogoGuardarEstado($pdo, 'ultimo_sync', (string) time());
		catalogoGuardarEstado($pdo, 'ultimo_sync_limite', (string) max(0, $limite));
		$pdo->commit();
	} catch (Throwable $e) {
		if ($pdo->inTransaction()):
			$pdo->rollBack();
		endif;
		return ['ok' => false, 'error' => $e->getMessage(), 'total' => 0];
	}

	$total = 0;
	try {
		$total = (int) $pdo->query('SELECT COUNT(*) FROM medios WHERE existente = 1')->fetchColumn();
	} catch (PDOException $e) {
		$total = 0;
	}

	$resultado = [
		'ok' => true,
		'duplicados' => $duplicados,
		'filtros' => $filtros,
		'filesystem' => $filesystem,
		'total' => $total,
		'duracion' => round(microtime(true) - $inicio, 3),
		'db' => catalogoRutaBaseDatos(),
	];
	$emitir(['tipo' => 'fin', 'mensaje' => 'Catalogo multimedia listo.', 'resultado' => $resultado]);
	return $resultado;
}
