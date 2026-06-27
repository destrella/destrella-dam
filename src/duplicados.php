<?php

const DUPLICADOS_HASH_DB_VERSION = 2;
const DUPLICADOS_JOB_VERSION = 1;
const DUPLICADOS_FIRMA_VERSION = 2;
const DUPLICADOS_SCORE_MINIMO = 70;
const DUPLICADOS_BUCKET_PROBABLE_MAX = 250;
const DUPLICADOS_BUCKET_NOMBRE_ITEMS_MAX = 12000;
const DUPLICADOS_CANDIDATOS_PROBABLES_MAX = 2000;
const DUPLICADOS_CONTEO_GRUPOS_MAX = 50000;
const DUPLICADOS_GRUPOS_POR_PAGINA = 24;
const DUPLICADOS_YANDEX_JOB_VERSION = 1;
const DUPLICADOS_YANDEX_RESUMEN_VERSION = 2;
const DUPLICADOS_YANDEX_HASH_DELAY_US = 2000000;
const DUPLICADOS_YANDEX_HASH_DELAY_JITTER_US = 500000;
const DUPLICADOS_YANDEX_HASH_MAX_POR_TRABAJO = 300;
const DUPLICADOS_YANDEX_HASH_REVISAR_TTL = 604800;
const DUPLICADOS_YANDEX_HASH_ERRORES_CONSECUTIVOS_MAX = 3;
const DUPLICADOS_YANDEX_CATALOGO_JOB_VERSION = 1;
const DUPLICADOS_YANDEX_CATALOGO_LOTE = 100;
const DUPLICADOS_YANDEX_CATALOGO_DELAY_US = 2000000;
const DUPLICADOS_YANDEX_CATALOGO_DELAY_JITTER_US = 750000;
const DUPLICADOS_YANDEX_CATALOGO_MAX_PETICIONES = 240;
const DUPLICADOS_YANDEX_CATALOGO_COOLDOWN_US = 600000000;
const DUPLICADOS_YANDEX_CATALOGO_ERROR_COOLDOWN_US = 1800000000;
const DUPLICADOS_YANDEX_CATALOGO_ERRORES_CONSECUTIVOS_MAX = 3;
const DUPLICADOS_VIDEO_FRAMES_FIRMA = 4;

function duplicadosDirectorioDatos(): string
{
	return proyectoRaiz() . DIRECTORY_SEPARATOR . 'datos';
}

function duplicadosDirectorioTrabajos(): string
{
	return duplicadosDirectorioDatos() . DIRECTORY_SEPARATOR . 'duplicados_jobs';
}

function duplicadosYandexDirectorioTrabajos(): string
{
	return duplicadosDirectorioDatos() . DIRECTORY_SEPARATOR . 'duplicados_yandex_jobs';
}

function duplicadosYandexCatalogoDirectorioTrabajos(): string
{
	return duplicadosDirectorioDatos() . DIRECTORY_SEPARATOR . 'yandex_catalogo_jobs';
}

function duplicadosRutaBaseDatos(): string
{
	return duplicadosDirectorioDatos() . DIRECTORY_SEPARATOR . 'duplicados_hashes.sqlite';
}

function duplicadosRutaTrabajoActual(): string
{
	return duplicadosDirectorioDatos() . DIRECTORY_SEPARATOR . 'duplicados_job_actual.json';
}

function duplicadosYandexRutaTrabajoActual(): string
{
	return duplicadosDirectorioDatos() . DIRECTORY_SEPARATOR . 'duplicados_yandex_job_actual.json';
}

function duplicadosYandexCatalogoRutaTrabajoActual(): string
{
	return duplicadosDirectorioDatos() . DIRECTORY_SEPARATOR . 'yandex_catalogo_job_actual.json';
}

function duplicadosRutaTrabajo(string $id): string
{
	return duplicadosDirectorioTrabajos() . DIRECTORY_SEPARATOR . basename($id) . '.json';
}

function duplicadosYandexRutaTrabajo(string $id): string
{
	return duplicadosYandexDirectorioTrabajos() . DIRECTORY_SEPARATOR . basename($id) . '.json';
}

function duplicadosYandexCatalogoRutaTrabajo(string $id): string
{
	return duplicadosYandexCatalogoDirectorioTrabajos() . DIRECTORY_SEPARATOR . basename($id) . '.json';
}

function duplicadosRutaLockTrabajo(string $id): string
{
	return duplicadosDirectorioTrabajos() . DIRECTORY_SEPARATOR . basename($id) . '.lock';
}

function duplicadosYandexRutaLockTrabajo(string $id): string
{
	return duplicadosYandexDirectorioTrabajos() . DIRECTORY_SEPARATOR . basename($id) . '.lock';
}

function duplicadosYandexCatalogoRutaLockTrabajo(string $id): string
{
	return duplicadosYandexCatalogoDirectorioTrabajos() . DIRECTORY_SEPARATOR . basename($id) . '.lock';
}

function duplicadosRutaLogWorker(): string
{
	return duplicadosDirectorioDatos() . DIRECTORY_SEPARATOR . 'duplicados_worker.log';
}

function duplicadosYandexRutaLogWorker(): string
{
	return duplicadosDirectorioDatos() . DIRECTORY_SEPARATOR . 'duplicados_yandex_worker.log';
}

function duplicadosYandexCatalogoRutaLogWorker(): string
{
	return duplicadosDirectorioDatos() . DIRECTORY_SEPARATOR . 'yandex_catalogo_worker.log';
}

function duplicadosPrepararDatos(): bool
{
	foreach ([duplicadosDirectorioDatos(), duplicadosDirectorioTrabajos(), duplicadosYandexDirectorioTrabajos(), duplicadosYandexCatalogoDirectorioTrabajos()] as $directorio):
		if (!is_dir($directorio) && !mkdir($directorio, 0755, true)):
			return false;
		endif;
	endforeach;

	return is_dir(duplicadosDirectorioDatos()) && is_writable(duplicadosDirectorioDatos());
}

function duplicadosAsegurarColumna(PDO $pdo, array $columnas, string $nombre, string $definicion): void
{
	if (in_array($nombre, $columnas, true)):
		return;
	endif;

	$pdo->exec('ALTER TABLE archivos_hash ADD COLUMN ' . $nombre . ' ' . $definicion);
}

function duplicadosMigrarBase(PDO $pdo): void
{
	$filas = $pdo->query('PRAGMA table_info(archivos_hash)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
	$columnas = array_map(fn($fila) => (string) ($fila['name'] ?? ''), $filas);

	duplicadosAsegurarColumna($pdo, $columnas, 'ancho', "INTEGER NOT NULL DEFAULT 0");
	duplicadosAsegurarColumna($pdo, $columnas, 'alto', "INTEGER NOT NULL DEFAULT 0");
	duplicadosAsegurarColumna($pdo, $columnas, 'duracion', "REAL NOT NULL DEFAULT 0");
	duplicadosAsegurarColumna($pdo, $columnas, 'contenido_hash', "TEXT NOT NULL DEFAULT ''");
	duplicadosAsegurarColumna($pdo, $columnas, 'perceptual_hash', "TEXT NOT NULL DEFAULT ''");
	duplicadosAsegurarColumna($pdo, $columnas, 'firma_version', "INTEGER NOT NULL DEFAULT 0");

	$pdo->exec('CREATE INDEX IF NOT EXISTS idx_archivos_hash_contenido ON archivos_hash(contenido_hash)');
	$pdo->exec('CREATE INDEX IF NOT EXISTS idx_archivos_hash_perceptual ON archivos_hash(perceptual_hash)');
	$pdo->exec('CREATE INDEX IF NOT EXISTS idx_archivos_hash_dimensiones ON archivos_hash(tipo, ancho, alto)');
}

function conectarBaseDuplicados(): ?PDO
{
	static $pdo = null;
	if ($pdo instanceof PDO):
		return $pdo;
	endif;
	if (!duplicadosPrepararDatos()):
		return null;
	endif;

	try {
		$pdo = new PDO('sqlite:' . duplicadosRutaBaseDatos());
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec('PRAGMA journal_mode = WAL');
		$pdo->exec("
			CREATE TABLE IF NOT EXISTS archivos_hash (
				ruta TEXT PRIMARY KEY,
				nombre TEXT NOT NULL,
				tipo TEXT NOT NULL,
				mtime INTEGER NOT NULL,
				tamano INTEGER NOT NULL,
				md5 TEXT NOT NULL,
				sha256 TEXT NOT NULL,
				error TEXT NOT NULL DEFAULT '',
				actualizado INTEGER NOT NULL DEFAULT 0
			)
		");
		$pdo->exec('CREATE INDEX IF NOT EXISTS idx_archivos_hash_md5 ON archivos_hash(md5)');
		$pdo->exec('CREATE INDEX IF NOT EXISTS idx_archivos_hash_sha256 ON archivos_hash(sha256)');
		$pdo->exec('CREATE INDEX IF NOT EXISTS idx_archivos_hash_tamano ON archivos_hash(tamano)');
		$pdo->exec("
			CREATE TABLE IF NOT EXISTS conteos_origen (
				clave TEXT PRIMARY KEY,
				base TEXT NOT NULL,
				firma TEXT NOT NULL,
				local INTEGER NOT NULL DEFAULT 0,
				local_mas INTEGER NOT NULL DEFAULT 0,
				remoto INTEGER NOT NULL DEFAULT 0,
				remoto_mas INTEGER NOT NULL DEFAULT 0,
				mixto INTEGER NOT NULL DEFAULT 0,
				mixto_mas INTEGER NOT NULL DEFAULT 0,
				actualizado INTEGER NOT NULL DEFAULT 0
			)
		");
		duplicadosMigrarBase($pdo);
	} catch (PDOException $e) {
		trigger_error("Error al abrir indice de duplicados: [{$e->getCode()}] {$e->getMessage()}", E_USER_WARNING);
		$pdo = null;
	}

	return $pdo;
}

function duplicadosNormalizarRutaLocal(string $ruta): string
{
	$real = realpath($ruta);
	if ($real !== false):
		return str_replace('\\', '/', $real);
	endif;

	return str_replace('\\', '/', $ruta);
}

function duplicadosRutaLocalDentroDeBase(string $ruta, ?string $base): bool
{
	if ($base === null || $base === ''):
		return true;
	endif;
	if (is_file($ruta)):
		return rutaDentroDeDirectorio($ruta, $base);
	endif;

	$baseReal = realpath($base);
	if (!$baseReal):
		return false;
	endif;
	$baseNormalizada = rtrim(str_replace('\\', '/', $baseReal), '/');
	$rutaNormalizada = str_replace('\\', '/', duplicadosNormalizarRutaLocal($ruta));
	return $rutaNormalizada === $baseNormalizada || str_starts_with($rutaNormalizada, $baseNormalizada . '/');
}

function duplicadosResolverBaseLocal(mixed $ruta): string
{
	$entrada = trim((string) $ruta);
	$resuelta = resolverRutaTolerante($entrada, 'dir', true);
	if ($resuelta !== null):
		return $resuelta;
	endif;

	return CARPETA;
}

function duplicadosFormatearFecha(?int $timestamp): string
{
	if ($timestamp === null || $timestamp <= 0):
		return '';
	endif;

	return date('Y-m-d H:i', $timestamp);
}

function duplicadosHashCorto(string $hash, int $largo = 16): string
{
	$hash = trim($hash);
	if ($hash === '' || strlen($hash) <= $largo):
		return $hash;
	endif;

	return substr($hash, 0, $largo) . '...';
}

function duplicadosTextoDimensiones(array $item): string
{
	$ancho = (int) ($item['ancho'] ?? 0);
	$alto = (int) ($item['alto'] ?? 0);
	return $ancho > 0 && $alto > 0 ? $ancho . '×' . $alto : '';
}

function duplicadosTextoDuracion(array $item): string
{
	$duracion = (float) ($item['duracion'] ?? 0);
	if ($duracion <= 0):
		return '';
	endif;
	$minutos = (int) floor($duracion / 60);
	$segundos = (int) round($duracion - ($minutos * 60));
	if ($segundos >= 60):
		$minutos++;
		$segundos -= 60;
	endif;

	return $minutos > 0 ? sprintf('%d:%02d', $minutos, $segundos) : $segundos . ' s';
}

function duplicadosCalcularHashesArchivo(string $ruta): array
{
	$fh = @fopen($ruta, 'rb');
	if ($fh === false):
		return ['ok' => false, 'md5' => '', 'sha256' => '', 'error' => 'No se pudo abrir el archivo.'];
	endif;

	$md5 = hash_init('md5');
	$sha256 = hash_init('sha256');
	while (!feof($fh)):
		$chunk = fread($fh, 1024 * 1024);
		if ($chunk === false):
			fclose($fh);
			return ['ok' => false, 'md5' => '', 'sha256' => '', 'error' => 'No se pudo leer el archivo completo.'];
		endif;
		if ($chunk === ''):
			continue;
		endif;
		hash_update($md5, $chunk);
		hash_update($sha256, $chunk);
	endwhile;
	fclose($fh);

	return [
		'ok' => true,
		'md5' => hash_final($md5),
		'sha256' => hash_final($sha256),
		'error' => '',
	];
}

function duplicadosCrearImagenGd(string $ruta): ?GdImage
{
	if (!function_exists('imagecreatefromstring')):
		return null;
	endif;
	if (!is_file($ruta) || filesize($ruta) > 120 * 1024 * 1024):
		return null;
	endif;

	$contenido = @file_get_contents($ruta);
	if (!is_string($contenido) || $contenido === ''):
		return null;
	endif;

	$imagen = @imagecreatefromstring($contenido);
	return $imagen instanceof GdImage ? $imagen : null;
}

function duplicadosHashPixelesGd(GdImage $imagen, int $ancho, int $alto): string
{
	if ($ancho <= 0 || $alto <= 0 || ($ancho * $alto) > 12000000):
		return '';
	endif;

	$hash = hash_init('sha256');
	hash_update($hash, $ancho . 'x' . $alto . '|rgb24|');
	for ($y = 0; $y < $alto; $y++):
		for ($x = 0; $x < $ancho; $x++):
			$color = imagecolorat($imagen, $x, $y);
			$r = ($color >> 16) & 0xFF;
			$g = ($color >> 8) & 0xFF;
			$b = $color & 0xFF;
			hash_update($hash, chr($r) . chr($g) . chr($b));
		endfor;
	endfor;

	return hash_final($hash);
}

function duplicadosDHashGd(GdImage $imagen): string
{
	$mini = imagecreatetruecolor(9, 8);
	if (!$mini):
		return '';
	endif;
	imagecopyresampled($mini, $imagen, 0, 0, 0, 0, 9, 8, imagesx($imagen), imagesy($imagen));

	$bits = '';
	for ($y = 0; $y < 8; $y++):
		for ($x = 0; $x < 8; $x++):
			$izquierda = imagecolorat($mini, $x, $y);
			$derecha = imagecolorat($mini, $x + 1, $y);
			$li = ((($izquierda >> 16) & 0xFF) * 0.299) + ((($izquierda >> 8) & 0xFF) * 0.587) + (($izquierda & 0xFF) * 0.114);
			$ld = ((($derecha >> 16) & 0xFF) * 0.299) + ((($derecha >> 8) & 0xFF) * 0.587) + (($derecha & 0xFF) * 0.114);
			$bits .= $li > $ld ? '1' : '0';
		endfor;
	endfor;
	duplicadosLiberarImagenGd($mini);

	$hex = '';
	for ($i = 0; $i < 64; $i += 4):
		$hex .= dechex(bindec(substr($bits, $i, 4)));
	endfor;

	return $hex;
}

function duplicadosLiberarImagenGd(?GdImage &$imagen): void
{
	$imagen = null;
}

function duplicadosFfmpegDisponible(): bool
{
	static $disponible = null;
	if ($disponible !== null):
		return $disponible;
	endif;

	$binario = (defined('BREW_BIN') ? BREW_BIN : '') . 'ffmpeg';
	if (is_executable($binario)):
		$disponible = true;
		return true;
	endif;

	if (!function_exists('shell_exec')):
		$disponible = false;
		return false;
	endif;

	$resultado = (string) @shell_exec('command -v ffmpeg 2>/dev/null');
	$disponible = trim($resultado) !== '';
	return $disponible;
}

function duplicadosComandoFfmpeg(): string
{
	$binario = (defined('BREW_BIN') ? BREW_BIN : '') . 'ffmpeg';
	return is_executable($binario) ? $binario : 'ffmpeg';
}

function duplicadosTiemposFirmaVideo(float $duracion): array
{
	if ($duracion <= 0):
		return [0.25, 1.0, 2.0, 3.0];
	endif;

	$limite = max(0.0, $duracion - 0.15);
	$fracciones = $duracion < 3.0 ? [0.18, 0.42, 0.66, 0.88] : [0.12, 0.35, 0.6, 0.85];
	$tiempos = [];
	foreach ($fracciones as $fraccion):
		$tiempo = min($limite, max(0.05, $duracion * $fraccion));
		$clave = number_format($tiempo, 3, '.', '');
		$tiempos[$clave] = (float) $clave;
	endforeach;

	return array_values($tiempos);
}

function duplicadosExtraerFrameVideoGd(string $ruta, float $segundo): ?GdImage
{
	if (!duplicadosFfmpegDisponible() || !function_exists('imagecreatefromstring')):
		return null;
	endif;

	$tmpBase = tempnam(sys_get_temp_dir(), 'dam_video_frame_');
	if ($tmpBase === false):
		return null;
	endif;
	@unlink($tmpBase);
	$tmp = $tmpBase . '.jpg';
	$opcionesHw = PHP_OS_FAMILY === 'Darwin' ? [' -hwaccel videotoolbox', ''] : [''];
	foreach ($opcionesHw as $opcionHw):
		@unlink($tmp);
		$cmd = duplicadosComandoFfmpeg() .
			' -hide_banner -loglevel error -y' . $opcionHw .
			' -ss ' . escapeshellarg(number_format(max(0, $segundo), 6, '.', '')) .
			' -i ' . escapeshellarg($ruta) .
			' -frames:v 1 -vf ' . escapeshellarg('scale=160:-1') .
			' -q:v 4 -update 1 ' . escapeshellarg($tmp) .
			' 2>/dev/null';
		@exec($cmd);
		if (!is_file($tmp) || filesize($tmp) <= 0):
			continue;
		endif;
		if (function_exists('posterJPGEsAprovechable') && !posterJPGEsAprovechable($tmp)):
			continue;
		endif;

		$imagen = duplicadosCrearImagenGd($tmp);
		@unlink($tmp);
		return $imagen;
	endforeach;

	@unlink($tmp);
	return null;
}

function duplicadosCalcularFirmaVideoFrames(string $ruta, float $duracion): array
{
	$dhashes = [];
	foreach (duplicadosTiemposFirmaVideo($duracion) as $segundo):
		$imagen = duplicadosExtraerFrameVideoGd($ruta, $segundo);
		if (!$imagen instanceof GdImage):
			continue;
		endif;
		$dhash = duplicadosDHashGd($imagen);
		duplicadosLiberarImagenGd($imagen);
		if ($dhash !== ''):
			$dhashes[] = $dhash;
		endif;
		if (count($dhashes) >= DUPLICADOS_VIDEO_FRAMES_FIRMA):
			break;
		endif;
	endforeach;

	$dhashes = array_values($dhashes);
	if (count($dhashes) < 2):
		return ['contenido_hash' => '', 'perceptual_hash' => ''];
	endif;

	$payload = 'video-frames-v2|' . implode('|', $dhashes);
	return [
		'contenido_hash' => hash('sha256', $payload),
		'perceptual_hash' => implode('', $dhashes),
	];
}

function duplicadosCalcularFirmaArchivo(string $ruta, string $tipo): array
{
	$firma = [
		'ancho' => 0,
		'alto' => 0,
		'duracion' => 0.0,
		'contenido_hash' => '',
		'perceptual_hash' => '',
		'firma_version' => DUPLICADOS_FIRMA_VERSION,
	];

	if ($tipo === 'img'):
		$dimensiones = @getimagesize($ruta);
		if (is_array($dimensiones)):
			$firma['ancho'] = (int) ($dimensiones[0] ?? 0);
			$firma['alto'] = (int) ($dimensiones[1] ?? 0);
		endif;

		$pixeles = (int) $firma['ancho'] * (int) $firma['alto'];
		$imagen = $pixeles > 0 && $pixeles <= 40000000 ? duplicadosCrearImagenGd($ruta) : null;
		if ($imagen instanceof GdImage):
			$ancho = imagesx($imagen);
			$alto = imagesy($imagen);
			$firma['ancho'] = $firma['ancho'] ?: $ancho;
			$firma['alto'] = $firma['alto'] ?: $alto;
			$firma['contenido_hash'] = duplicadosHashPixelesGd($imagen, $ancho, $alto);
			$firma['perceptual_hash'] = duplicadosDHashGd($imagen);
			duplicadosLiberarImagenGd($imagen);
		endif;
	elseif ($tipo === 'vid'):
		$info = function_exists('obtenerInformacionVideoExtraccion')
			? obtenerInformacionVideoExtraccion($ruta)
			: [];
		$firma['ancho'] = (int) ($info['ancho'] ?? 0);
		$firma['alto'] = (int) ($info['alto'] ?? 0);
		$firma['duracion'] = (float) ($info['duracion'] ?? 0.0);
		$firma = array_replace($firma, duplicadosCalcularFirmaVideoFrames($ruta, (float) $firma['duracion']));
	endif;

	return $firma;
}

function duplicadosFilaLocalVigente(array $fila): bool
{
	$ruta = (string) ($fila['ruta'] ?? '');
	if ($ruta === '' || !is_file($ruta)):
		return false;
	endif;

	return (int) ($fila['mtime'] ?? -1) === (int) filemtime($ruta)
		&& (int) ($fila['tamano'] ?? -1) === (int) filesize($ruta)
		&& (string) ($fila['md5'] ?? '') !== ''
		&& (string) ($fila['sha256'] ?? '') !== '';
}

function duplicadosFirmaVersionRequerida(string $tipo): int
{
	return $tipo === 'vid' ? DUPLICADOS_FIRMA_VERSION : 1;
}

function duplicadosFilaLocalFirmaActualizada(array $fila): bool
{
	if (!duplicadosFilaLocalVigente($fila)):
		return false;
	endif;

	return (int) ($fila['firma_version'] ?? 0) >= duplicadosFirmaVersionRequerida((string) ($fila['tipo'] ?? ''));
}

function duplicadosHashLocalVigente(PDO $pdo, string $ruta): ?array
{
	$stmt = $pdo->prepare('SELECT * FROM archivos_hash WHERE ruta = :ruta LIMIT 1');
	$stmt->execute([':ruta' => duplicadosNormalizarRutaLocal($ruta)]);
	$fila = $stmt->fetch(PDO::FETCH_ASSOC);
	if (!is_array($fila) || !duplicadosFilaLocalVigente($fila)):
		return null;
	endif;

	if (!duplicadosFilaLocalFirmaActualizada($fila)):
		return null;
	endif;

	return $fila;
}

function duplicadosHashLocalIntacto(PDO $pdo, string $ruta): ?array
{
	$stmt = $pdo->prepare('SELECT * FROM archivos_hash WHERE ruta = :ruta LIMIT 1');
	$stmt->execute([':ruta' => duplicadosNormalizarRutaLocal($ruta)]);
	$fila = $stmt->fetch(PDO::FETCH_ASSOC);
	if (!is_array($fila) || !duplicadosFilaLocalVigente($fila)):
		return null;
	endif;

	return $fila;
}

function duplicadosHashesDesdeFilaLocal(array $fila): array
{
	return [
		'ok' => true,
		'md5' => strtolower((string) ($fila['md5'] ?? '')),
		'sha256' => strtolower((string) ($fila['sha256'] ?? '')),
		'error' => '',
	];
}

function duplicadosGuardarHashLocal(PDO $pdo, string $ruta, string $tipo, array $hashes): bool
{
	$ruta = duplicadosNormalizarRutaLocal($ruta);
	if (!is_file($ruta)):
		return false;
	endif;
	if (!in_array($tipo, ['img', 'vid'], true)):
		$tipo = tipoMultimediaDesdeRuta($ruta) ?? '';
	endif;
	if ($tipo === ''):
		return false;
	endif;

	$stmt = $pdo->prepare("
		INSERT INTO archivos_hash (
			ruta, nombre, tipo, mtime, tamano, md5, sha256, error,
			ancho, alto, duracion, contenido_hash, perceptual_hash, firma_version, actualizado
		)
		VALUES (
			:ruta, :nombre, :tipo, :mtime, :tamano, :md5, :sha256, :error,
			:ancho, :alto, :duracion, :contenido_hash, :perceptual_hash, :firma_version, :actualizado
		)
		ON CONFLICT(ruta) DO UPDATE SET
			nombre = excluded.nombre,
			tipo = excluded.tipo,
			mtime = excluded.mtime,
			tamano = excluded.tamano,
			md5 = excluded.md5,
			sha256 = excluded.sha256,
			error = excluded.error,
			ancho = excluded.ancho,
			alto = excluded.alto,
			duracion = excluded.duracion,
			contenido_hash = excluded.contenido_hash,
			perceptual_hash = excluded.perceptual_hash,
			firma_version = excluded.firma_version,
			actualizado = excluded.actualizado
	");
	$stmt->execute([
		':ruta' => $ruta,
		':nombre' => basename($ruta),
		':tipo' => $tipo,
		':mtime' => filemtime($ruta),
		':tamano' => filesize($ruta),
		':md5' => (string) ($hashes['md5'] ?? ''),
		':sha256' => (string) ($hashes['sha256'] ?? ''),
		':error' => (string) ($hashes['error'] ?? ''),
		':ancho' => (int) ($hashes['ancho'] ?? 0),
		':alto' => (int) ($hashes['alto'] ?? 0),
		':duracion' => (float) ($hashes['duracion'] ?? 0.0),
		':contenido_hash' => (string) ($hashes['contenido_hash'] ?? ''),
		':perceptual_hash' => (string) ($hashes['perceptual_hash'] ?? ''),
		':firma_version' => (int) ($hashes['firma_version'] ?? DUPLICADOS_FIRMA_VERSION),
		':actualizado' => time(),
	]);

	if (function_exists('catalogoActualizarFirmasMedio')):
		catalogoActualizarFirmasMedio($ruta, $tipo, $hashes);
	endif;

	return true;
}

function duplicadosEliminarHashLocal(string $ruta): void
{
	$pdo = conectarBaseDuplicados();
	if (!$pdo):
		return;
	endif;

	foreach (rutasEquivalentesIndicePalabrasClave($ruta) as $candidata):
		try {
			$stmt = $pdo->prepare('DELETE FROM archivos_hash WHERE ruta = :ruta');
			$stmt->execute([':ruta' => duplicadosNormalizarRutaLocal($candidata)]);
			if (function_exists('catalogoLimpiarFirmasMedio')):
				catalogoLimpiarFirmasMedio($candidata);
			endif;
		} catch (PDOException $e) {
			continue;
		}
	endforeach;
}

function duplicadosLimpiarHashLocalAusente(PDO $pdo, string $ruta): void
{
	$ruta = duplicadosNormalizarRutaLocal($ruta);
	if ($ruta === '' || is_file($ruta)):
		return;
	endif;

	try {
		$stmt = $pdo->prepare('DELETE FROM archivos_hash WHERE ruta = :ruta');
		$stmt->execute([':ruta' => $ruta]);
	} catch (PDOException $e) {
		return;
	}

	if (function_exists('catalogoEliminarMedio')):
		catalogoEliminarMedio($ruta);
	endif;
}

function duplicadosItemLocalDesdeFila(array $fila): ?array
{
	if (!duplicadosFilaLocalVigente($fila)):
		return null;
	endif;

	$ruta = duplicadosNormalizarRutaLocal((string) $fila['ruta']);
	$tamano = (int) ($fila['tamano'] ?? 0);
	$mtime = (int) ($fila['mtime'] ?? 0);
	return [
		'id' => 'local:' . $ruta,
		'origen' => 'local',
		'origen_etiqueta' => 'Local',
		'ruta' => $ruta,
		'nombre' => (string) ($fila['nombre'] ?? basename($ruta)),
		'tipo' => (string) ($fila['tipo'] ?? ''),
		'tamano' => $tamano,
		'tamano_legible' => yandexDiskFormatoTamano($tamano),
		'modificado' => duplicadosFormatearFecha($mtime),
		'modificado_ts' => $mtime,
		'md5' => strtolower((string) ($fila['md5'] ?? '')),
		'sha256' => strtolower((string) ($fila['sha256'] ?? '')),
		'ancho' => (int) ($fila['ancho'] ?? 0),
		'alto' => (int) ($fila['alto'] ?? 0),
		'duracion' => (float) ($fila['duracion'] ?? 0.0),
			'contenido_hash' => strtolower((string) ($fila['contenido_hash'] ?? '')),
			'perceptual_hash' => strtolower((string) ($fila['perceptual_hash'] ?? '')),
			'firma_version' => (int) ($fila['firma_version'] ?? 0),
			'mime' => '',
			'url' => '?' . http_build_query(['archivo' => rutaRelativaParaParametro($ruta)], '', '&', PHP_QUERY_RFC3986),
		];
}

function duplicadosObtenerLocalesIndexados(?string $base = null): array
{
	$pdo = conectarBaseDuplicados();
	if (!$pdo):
		return [];
	endif;

	try {
		$stmt = $pdo->query('SELECT * FROM archivos_hash ORDER BY mtime DESC, ruta ASC');
		$filas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (PDOException $e) {
		return [];
	}

	$items = [];
	foreach ($filas as $fila):
		$ruta = (string) ($fila['ruta'] ?? '');
		if (!duplicadosRutaLocalDentroDeBase($ruta, $base)):
			continue;
		endif;
		if ($ruta === '' || !is_file($ruta)):
			if ($ruta !== ''):
				duplicadosLimpiarHashLocalAusente($pdo, $ruta);
			endif;
			continue;
		endif;
		$item = duplicadosItemLocalDesdeFila($fila);
		if ($item !== null):
			$items[] = $item;
		endif;
	endforeach;

	return $items;
}

function duplicadosResumenLocal(?string $base = null): array
{
	$pdo = conectarBaseDuplicados();
	$resumen = [
		'indexados' => 0,
			'vigentes' => 0,
			'stale' => 0,
			'actualizadas' => 0,
			'por_actualizar' => 0,
			'errores' => 0,
			'actualizado' => 0,
		];
	if (!$pdo):
		return $resumen;
	endif;

	try {
		$stmt = $pdo->query('SELECT * FROM archivos_hash ORDER BY actualizado DESC');
		$filas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (PDOException $e) {
		return $resumen;
	}

	foreach ($filas as $fila):
		$ruta = (string) ($fila['ruta'] ?? '');
		if (!duplicadosRutaLocalDentroDeBase($ruta, $base)):
			continue;
		endif;
		if ($ruta === '' || !is_file($ruta)):
			if ($ruta !== ''):
				duplicadosLimpiarHashLocalAusente($pdo, $ruta);
			endif;
			continue;
		endif;
		$resumen['indexados']++;
		if ((string) ($fila['error'] ?? '') !== ''):
			$resumen['errores']++;
		endif;
			if (duplicadosFilaLocalVigente($fila)):
				$resumen['vigentes']++;
				if (duplicadosFilaLocalFirmaActualizada($fila)):
					$resumen['actualizadas']++;
				else:
					$resumen['por_actualizar']++;
				endif;
			else:
				$resumen['stale']++;
			endif;
		$resumen['actualizado'] = max($resumen['actualizado'], (int) ($fila['actualizado'] ?? 0));
	endforeach;

	return $resumen;
}

function duplicadosTimestamp(mixed $valor): int
{
	if (is_numeric($valor)):
		return max(0, (int) $valor);
	endif;
	$timestamp = strtotime((string) $valor);
	return $timestamp !== false ? $timestamp : 0;
}

function duplicadosObtenerYandexCatalogo(): ?array
{
	$pdo = function_exists('conectarCatalogoMultimedia') ? conectarCatalogoMultimedia() : null;
	if (!$pdo):
		return null;
	endif;

	try {
		$stmt = $pdo->query("
			SELECT
				ruta, ruta_remota, nombre, tipo, mime, tamano, mtime,
				md5, sha256, ancho, alto, duracion, contenido_hash, perceptual_hash, url, actualizado
			FROM medios
			WHERE origen = 'yandex'
				AND existente = 1
				AND (md5 <> '' OR sha256 <> '')
			ORDER BY mtime DESC, ruta_remota ASC
		");
		$filas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (PDOException $e) {
		return null;
	}

	if (empty($filas)):
		return null;
	endif;

	$items = [];
	$actualizado = 0;
	foreach ($filas as $fila):
		$item = duplicadosItemYandexDesdeCatalogoFila($fila);
		if ($item === null):
			continue;
		endif;
		$actualizado = max($actualizado, (int) ($fila['actualizado'] ?? 0));
		$items[(string) $item['id']] = $item;
	endforeach;

	return [
		'items' => array_values($items),
		'total' => count($items),
		'actualizado' => $actualizado,
	];
}

function duplicadosItemYandexDesdeCatalogoFila(array $fila): ?array
{
	$ruta = normalizarRutaYandexDisk((string) ($fila['ruta_remota'] ?? ''));
	if ($ruta === '/'):
		$ruta = normalizarRutaYandexDisk(preg_replace('/^yandex:/', '', (string) ($fila['ruta'] ?? '')) ?? '');
	endif;
	if ($ruta === '/'):
		return null;
	endif;
	$tipo = (string) ($fila['tipo'] ?? '');
	$tipoYandex = match ($tipo) {
		'img' => 'image',
		'vid' => 'video',
		default => $tipo,
	};
	$tamano = (int) ($fila['tamano'] ?? 0);
	$mtime = (int) ($fila['mtime'] ?? 0);

	return [
		'id' => 'yandex:' . $ruta,
		'origen' => 'yandex',
		'origen_etiqueta' => 'Yandex.Disk',
		'ruta' => $ruta,
		'nombre' => (string) ($fila['nombre'] ?? basename($ruta)),
		'tipo' => $tipoYandex,
		'mime' => (string) ($fila['mime'] ?? ''),
		'tamano' => $tamano,
		'tamano_legible' => yandexDiskFormatoTamano($tamano),
		'modificado' => duplicadosFormatearFecha($mtime),
		'modificado_ts' => $mtime,
		'md5' => strtolower((string) ($fila['md5'] ?? '')),
		'sha256' => strtolower((string) ($fila['sha256'] ?? '')),
		'ancho' => (int) ($fila['ancho'] ?? 0),
		'alto' => (int) ($fila['alto'] ?? 0),
		'duracion' => (float) ($fila['duracion'] ?? 0.0),
		'contenido_hash' => strtolower((string) ($fila['contenido_hash'] ?? '')),
		'perceptual_hash' => strtolower((string) ($fila['perceptual_hash'] ?? '')),
		'exif' => [],
		'url' => urlPanelYandexDisk(dirname($ruta) ?: '/'),
		'url_externa' => (string) ($fila['url'] ?? '') ?: yandexDiskUrlCliente($ruta),
	];
}

function duplicadosEntradaYandexDesdeCatalogoFila(array $fila): ?array
{
	$item = duplicadosItemYandexDesdeCatalogoFila($fila);
	if ($item === null):
		return null;
	endif;

	return [
		'ruta' => (string) $item['ruta'],
		'nombre' => (string) $item['nombre'],
		'tamano' => (int) ($item['tamano'] ?? 0),
		'md5' => (string) ($item['md5'] ?? ''),
		'sha256' => (string) ($item['sha256'] ?? ''),
		'hash_actualizado' => (int) ($fila['hash_actualizado'] ?? 0),
		'hash_intentos' => 0,
	];
}

function duplicadosYandexCatalogoResumen(): array
{
	$pdo = function_exists('conectarCatalogoMultimedia') ? conectarCatalogoMultimedia() : null;
	$resumen = [
		'total' => 0,
		'con_hash' => 0,
		'sin_hash' => 0,
		'md5' => 0,
		'sha256' => 0,
		'pendientes_hash' => 0,
		'actualizado' => 0,
	];
	if (!$pdo):
		return $resumen;
	endif;

	$cutoff = time() - DUPLICADOS_YANDEX_HASH_REVISAR_TTL;
	try {
		$stmt = $pdo->prepare("
			SELECT
				COUNT(*) AS total,
				SUM(CASE WHEN md5 <> '' OR sha256 <> '' THEN 1 ELSE 0 END) AS con_hash,
				SUM(CASE WHEN md5 = '' AND sha256 = '' THEN 1 ELSE 0 END) AS sin_hash,
				SUM(CASE WHEN md5 <> '' THEN 1 ELSE 0 END) AS md5_total,
				SUM(CASE WHEN sha256 <> '' THEN 1 ELSE 0 END) AS sha256_total,
				SUM(CASE WHEN (md5 = '' OR sha256 = '') AND hash_actualizado <= :cutoff THEN 1 ELSE 0 END) AS pendientes_hash,
				MAX(CASE WHEN hash_actualizado > actualizado THEN hash_actualizado ELSE actualizado END) AS actualizado
			FROM medios
			WHERE origen = 'yandex'
				AND existente = 1
		");
		$stmt->execute([':cutoff' => $cutoff]);
		$fila = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
	} catch (PDOException $e) {
		return $resumen;
	}

	return [
		'total' => (int) ($fila['total'] ?? 0),
		'con_hash' => (int) ($fila['con_hash'] ?? 0),
		'sin_hash' => (int) ($fila['sin_hash'] ?? 0),
		'md5' => (int) ($fila['md5_total'] ?? 0),
		'sha256' => (int) ($fila['sha256_total'] ?? 0),
		'pendientes_hash' => (int) ($fila['pendientes_hash'] ?? 0),
		'actualizado' => (int) ($fila['actualizado'] ?? 0),
	];
}

function duplicadosYandexCatalogoCandidatosHash(bool $forzar, int $limite): array
{
	$pdo = function_exists('conectarCatalogoMultimedia') ? conectarCatalogoMultimedia() : null;
	if (!$pdo):
		return ['total' => 0, 'items' => []];
	endif;

	$limite = max(1, $limite);
	$cutoff = time() - DUPLICADOS_YANDEX_HASH_REVISAR_TTL;
	$wherePendiente = $forzar
		? '1 = 1'
		: '(md5 = \'\' OR sha256 = \'\') AND hash_actualizado <= :cutoff';

	try {
		$totalStmt = $pdo->prepare("
			SELECT COUNT(*)
			FROM medios
			WHERE origen = 'yandex'
				AND existente = 1
				AND $wherePendiente
		");
		if (!$forzar):
			$totalStmt->bindValue(':cutoff', $cutoff, PDO::PARAM_INT);
		endif;
		$totalStmt->execute();
		$total = (int) $totalStmt->fetchColumn();

		$stmt = $pdo->prepare("
			SELECT
				ruta, ruta_remota, nombre, tipo, mime, tamano, mtime,
				md5, sha256, ancho, alto, duracion, contenido_hash, perceptual_hash,
				url, actualizado, hash_actualizado
			FROM medios
			WHERE origen = 'yandex'
				AND existente = 1
				AND $wherePendiente
			ORDER BY hash_actualizado ASC, actualizado ASC, ruta_remota ASC
			LIMIT :limite
		");
		if (!$forzar):
			$stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_INT);
		endif;
		$stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
		$stmt->execute();
		$filas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (PDOException $e) {
		return ['total' => 0, 'items' => []];
	}

	$items = [];
	foreach ($filas as $fila):
		$entrada = duplicadosEntradaYandexDesdeCatalogoFila($fila);
		if ($entrada !== null):
			$items[] = $entrada;
		endif;
	endforeach;

	return ['total' => $total, 'items' => $items];
}

function duplicadosYandexMarcarRevisionCatalogo(string $rutaRemota): void
{
	$pdo = function_exists('conectarCatalogoMultimedia') ? conectarCatalogoMultimedia() : null;
	if (!$pdo):
		return;
	endif;
	$rutaRemota = normalizarRutaYandexDisk($rutaRemota);
	if ($rutaRemota === '/'):
		return;
	endif;
	$rutaCatalogo = 'yandex:' . $rutaRemota;
	try {
		$stmt = $pdo->prepare("
			UPDATE medios
			SET hash_actualizado = :hash_actualizado,
				actualizado = :actualizado
			WHERE origen = 'yandex'
				AND (ruta = :ruta OR ruta_remota = :ruta_remota)
		");
		$stmt->execute([
			':hash_actualizado' => time(),
			':actualizado' => time(),
			':ruta' => $rutaCatalogo,
			':ruta_remota' => $rutaRemota,
		]);
	} catch (PDOException $e) {
		return;
	}
}

function duplicadosTipoVistaPrevia(array $item): string
{
	$origen = (string) ($item['origen'] ?? '');
	$tipo = mb_strtolower((string) ($item['tipo'] ?? ''), 'UTF-8');
	$mime = mb_strtolower((string) ($item['mime'] ?? ''), 'UTF-8');
	$ruta = (string) ($item['ruta'] ?? '');
	$extension = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

	if ($origen === 'local'):
		return match ($tipo) {
			'img' => 'image',
			'vid' => 'video',
			default => match (tipoMultimediaDesdeRuta($ruta)) {
				'img' => 'image',
				'vid' => 'video',
				default => '',
			},
		};
	endif;

	if ($tipo === 'image' || $tipo === 'photo' || str_starts_with($mime, 'image/')):
		return 'image';
	endif;
	if ($tipo === 'video' || str_starts_with($mime, 'video/')):
		return 'video';
	endif;

	return match ($extension) {
		'jpg', 'jpeg', 'webp', 'png', 'gif', 'heic', 'heif', 'avif' => 'image',
		'mp4', 'mov', 'm4v', 'webm' => 'video',
		default => '',
	};
}

function duplicadosUrlVistaPrevia(array $item): string
{
	$ruta = (string) ($item['ruta'] ?? '');
	if ($ruta === ''):
		return '';
	endif;

	$tipoVista = duplicadosTipoVistaPrevia($item);
	if ((string) ($item['origen'] ?? '') === 'local'):
		if (!is_file($ruta) || $tipoVista === ''):
			return '';
		endif;
		if ($tipoVista === 'image' && imagenRequiereTemporalNavegador(pathinfo($ruta, PATHINFO_EXTENSION))):
			$temporal = generarJPGtemporal($ruta);
			if ($temporal !== ''):
				$rutaTemporal = proyectoRaiz() . DIRECTORY_SEPARATOR . $temporal;
				return is_file($rutaTemporal)
					? agregarCacheBuster(urlVisualizacion($rutaTemporal), firmaCacheArchivo($rutaTemporal))
					: urlVisualizacion($rutaTemporal);
			endif;
			return '';
		endif;
		return urlVisualizacion($ruta);
	endif;
	if ((string) ($item['origen'] ?? '') === 'yandex'):
		return match ($tipoVista) {
			'image' => yandexDiskPreviewProxyUrl($ruta, 'L'),
			'video' => yandexDiskMediaProxyUrl($ruta),
			default => '',
		};
	endif;

	return '';
}

function duplicadosUrlMedia(array $item): string
{
	$ruta = (string) ($item['ruta'] ?? '');
	if ($ruta === ''):
		return '';
	endif;

	$tipoVista = duplicadosTipoVistaPrevia($item);
	if ((string) ($item['origen'] ?? '') === 'yandex' && $tipoVista === 'image'):
		return yandexDiskLightboxPreviewProxyUrl($ruta);
	endif;

	return duplicadosUrlVistaPrevia($item);
}

function duplicadosEtiquetaAccion(array $item): string
{
	return (string) ($item['origen'] ?? '') === 'yandex' ? 'Enviar a papelera' : 'Descartar';
}

function duplicadosAtributosItem(array $item): string
{
	$atributos = [
		'data-duplicado-item' => '1',
		'data-duplicado-origen' => (string) ($item['origen'] ?? ''),
		'data-duplicado-origen-etiqueta' => (string) ($item['origen_etiqueta'] ?? $item['origen'] ?? ''),
		'data-duplicado-ruta' => (string) ($item['ruta'] ?? ''),
		'data-duplicado-nombre' => (string) ($item['nombre'] ?? ''),
		'data-duplicado-kind' => duplicadosTipoVistaPrevia($item),
		'data-duplicado-preview' => duplicadosUrlVistaPrevia($item),
		'data-duplicado-media' => duplicadosUrlMedia($item),
		'data-duplicado-open' => (string) ($item['url_externa'] ?? $item['url'] ?? ''),
		'data-duplicado-action-label' => duplicadosEtiquetaAccion($item),
		'data-duplicado-md5' => (string) ($item['md5'] ?? ''),
		'data-duplicado-sha256' => (string) ($item['sha256'] ?? ''),
		'data-duplicado-contenido-hash' => (string) ($item['contenido_hash'] ?? ''),
		'data-duplicado-perceptual-hash' => (string) ($item['perceptual_hash'] ?? ''),
		'data-duplicado-tamano' => (string) ($item['tamano_legible'] ?? ''),
		'data-duplicado-modificado' => (string) ($item['modificado'] ?? ''),
		'data-duplicado-dimensiones' => duplicadosTextoDimensiones($item),
		'data-duplicado-duracion' => duplicadosTextoDuracion($item),
		'data-duplicado-mime' => (string) ($item['mime'] ?? ''),
	];

	$html = '';
	foreach ($atributos as $nombre => $valor):
		$html .= ' ' . $nombre . '="' . escaparHtml($valor) . '"';
	endforeach;

	return $html;
}

function duplicadosGrupoEsPixelesSinMetadatos(array $grupo): bool
{
	if ((int) ($grupo['score'] ?? 0) !== 95):
		return false;
	endif;

	$razones = is_array($grupo['razones'] ?? null) ? $grupo['razones'] : [];
	foreach ($razones as $razon):
		$normalizada = strtr(mb_strtolower((string) $razon, 'UTF-8'), [
			'á' => 'a',
			'é' => 'e',
			'í' => 'i',
			'ó' => 'o',
			'ú' => 'u',
			'ü' => 'u',
			'ñ' => 'n',
		]);
		$normalizada = preg_replace('/[^a-z0-9]+/', ' ', $normalizada) ?? '';
		if (str_contains(trim((string) $normalizada), 'pixeles identicos sin metadatos')):
			return true;
		endif;
	endforeach;

	return false;
}

function duplicadosRutaDentroDeListas(string $ruta): bool
{
	$baseListas = str_replace('\\', '/', rtrim(proyectoRaiz(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'listas');
	$ruta = str_replace('\\', '/', $ruta);

	return $ruta === $baseListas || str_starts_with($ruta, $baseListas . '/');
}

function duplicadosNombreSinBeforeHighresFix(string $nombre): string
{
	return str_replace('-before-highres-fix', '', $nombre);
}

function duplicadosGrupoTieneParBeforeHighresFix(array $items): bool
{
	$nombres = [];
	foreach ($items as $item):
		$nombre = (string) ($item['nombre'] ?? basename((string) ($item['ruta'] ?? '')));
		if ($nombre !== ''):
			$nombres[$nombre] = true;
		endif;
	endforeach;

	foreach (array_keys($nombres) as $nombre):
		if (!str_contains($nombre, '-before-highres-fix')):
			continue;
		endif;
		if (isset($nombres[duplicadosNombreSinBeforeHighresFix($nombre)])):
			return true;
		endif;
	endforeach;

	return false;
}

function duplicadosAccionesSugeridasGrupo(array $grupo): string
{
	if (!duplicadosGrupoEsPixelesSinMetadatos($grupo)):
		return '';
	endif;

	$items = array_values(array_filter(
		is_array($grupo['items'] ?? null) ? $grupo['items'] : [],
		static function ($item): bool {
			if (!is_array($item) || (string) ($item['origen'] ?? '') !== 'local'):
				return false;
			endif;
			return in_array(duplicadosTipoVistaPrevia($item), ['image', 'video'], true);
		}
	));
	if (count($items) < 2):
		return '';
	endif;

	$html = '';
	$hayListas = false;
	$hayFueraListas = false;
	foreach ($items as $item):
		$enListas = duplicadosRutaDentroDeListas((string) ($item['ruta'] ?? ''));
		$hayListas = $hayListas || $enListas;
		$hayFueraListas = $hayFueraListas || !$enListas;
	endforeach;
	if ($hayListas && $hayFueraListas):
		$html .= '<button type="button" data-duplicado-regla="mantener-listas">Mantener listas</button>';
	endif;

	if (duplicadosGrupoTieneParBeforeHighresFix($items)):
		$html .= '<button type="button" data-duplicado-regla="descartar-before-highres">Descartar before-highres</button>';
	endif;

	$html .=
		'<button type="button" data-duplicado-regla="mantener-mas-antiguo">Mantener más antiguo</button>' .
		'<button type="button" data-duplicado-regla="mantener-mas-nuevo">Mantener más nuevo</button>';

	return $html;
}

function duplicadosNormalizarClaveMetadato(string $clave): string
{
	return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($clave, 'UTF-8')) ?? '';
}

function duplicadosValorMetadatoTexto(mixed $valor, int $limite = 12000): string
{
	if (is_array($valor)):
		$valor = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	elseif (is_bool($valor)):
		$valor = $valor ? 'true' : 'false';
	elseif ($valor === null):
		$valor = '';
	else:
		$valor = (string) $valor;
	endif;

	$valor = trim((string) $valor);
	if ($limite > 0 && mb_strlen($valor, 'UTF-8') > $limite):
		return mb_substr($valor, 0, $limite, 'UTF-8') . "\n...[valor recortado para la vista]";
	endif;

	return $valor;
}

function duplicadosAplanarMetadatos(array $metadatos, string $prefijo = '', int &$conteo = 0): array
{
	$salida = [];
	foreach ($metadatos as $clave => $valor):
		if ($conteo >= 600):
			break;
		endif;
		$claveTexto = trim($prefijo . (string) $clave);
		if ($claveTexto === ''):
			$claveTexto = 'Campo';
		endif;
		if (is_array($valor)):
			$esAsociativo = array_keys($valor) !== range(0, count($valor) - 1);
			if ($esAsociativo && count($valor) <= 80):
				$subconteo = $conteo;
				$aplanado = duplicadosAplanarMetadatos($valor, $claveTexto . '.', $subconteo);
				if (!empty($aplanado)):
					$conteo = $subconteo;
					$salida += $aplanado;
					continue;
				endif;
			endif;
		endif;
		$salida[$claveTexto] = duplicadosValorMetadatoTexto($valor);
		$conteo++;
	endforeach;

	return $salida;
}

function duplicadosMetadatoDestacado(string $clave): bool
{
	$clave = duplicadosNormalizarClaveMetadato($clave);
	$exactas = [
		'title',
		'description',
		'imagedescription',
		'captionabstract',
		'subject',
		'keywords',
		'hierarchicalsubject',
		'artist',
		'creator',
		'byline',
		'copyright',
		'copyrightnotice',
		'software',
		'creatortool',
		'processingsoftware',
		'createdate',
		'datecreated',
		'datetimeoriginal',
		'creationdate',
		'modifydate',
		'orientation',
		'make',
		'model',
		'lensmodel',
		'parameters',
		'prompt',
		'negativeprompt',
		'invokeaimetadata',
		'invokeaigraph',
		'invokeaiworkflow',
		'workflow',
	];
	if (in_array($clave, $exactas, true)):
		return true;
	endif;

	foreach (['prompt', 'parameters', 'workflow', 'invokeai', 'comfyui', 'metadata', 'software', 'creator', 'subject', 'keyword'] as $fragmento):
		if (str_contains($clave, $fragmento)):
			return true;
		endif;
	endforeach;

	return false;
}

function duplicadosTipoComparacionMetadato(string $clave): string
{
	$clave = duplicadosNormalizarClaveMetadato($clave);
	$ignorar = [
		'nombre',
		'rutalocal',
		'rutayandex',
		'sourcefile',
		'filename',
		'directory',
		'filepath',
		'filesize',
		'filetype',
		'filetypeextension',
		'mimetype',
		'filepermissions',
		'fileattributes',
		'fileowner',
		'filegroup',
		'fileinode',
		'filemode',
		'filesystemflags',
		'exifbyteorder',
		'exifversion',
		'xmptoolkit',
		'mediadataoffset',
		'mediadatasize',
		'resourceid',
		'yandexresourceid',
	];
	if (in_array($clave, $ignorar, true)):
		return 'ignorar';
	endif;

	foreach (['ruta', 'path', 'directory'] as $fragmento):
		if (str_contains($clave, $fragmento)):
			return 'ignorar';
		endif;
	endforeach;

	$volatiles = [
		'modificado',
		'created',
		'modified',
		'creationdate',
		'createdate',
		'datecreated',
		'modifydate',
		'metadatadate',
		'filecreatedate',
		'filemodifydate',
		'fileaccessdate',
		'fileinodechangedate',
		'filechangdate',
		'filemodificationdatetime',
		'fileaccessdatetime',
		'fileinodechangedatetime',
		'digitalcreationdate',
		'digitalcreationtime',
		'profiledatetime',
		'year',
	];
	if (in_array($clave, $volatiles, true)):
		return 'volatil';
	endif;

	if (
		preg_match('/(?:^|file|metadata|profile|digital)(?:create|created|modify|modified|access|inode|change|date|time|timestamp)/', $clave)
		|| preg_match('/(?:date|time|timestamp)$/', $clave)
	):
		return 'volatil';
	endif;

	return 'normal';
}

function duplicadosHashComparacionMetadato(string $valor): string
{
	$valor = str_replace(["\r\n", "\r"], "\n", $valor);
	$valor = preg_replace('/[ \t]+/u', ' ', $valor) ?? $valor;
	$valor = preg_replace('/\n{3,}/u', "\n\n", $valor) ?? $valor;
	return sha1(trim($valor));
}

function duplicadosRenderFilaMetadato(string $clave, string $valor): string
{
	$claveComparacion = duplicadosNormalizarClaveMetadato($clave);
	$tipoComparacion = duplicadosTipoComparacionMetadato($clave);
	$hashComparacion = duplicadosHashComparacionMetadato($valor);
	return
		'<div class="duplicados-metadatos-fila" data-duplicado-metadato-fila="1" data-duplicado-metadato-clave="' . escaparHtml($claveComparacion) . '" data-duplicado-metadato-tipo="' . escaparHtml($tipoComparacion) . '" data-duplicado-metadato-hash="' . escaparHtml($hashComparacion) . '">' .
		'<dt>' . escaparHtml($clave) . '</dt>' .
		'<dd>' . nl2br(escaparHtml($valor)) . '</dd>' .
		'</div>';
}

function duplicadosRenderMetadatosArchivo(array $metadatos, string $titulo, string $subtitulo = ''): string
{
	$conteo = 0;
	$metadatos = duplicadosAplanarMetadatos($metadatos, '', $conteo);
	$total = count($metadatos);
	$destacados = [];
	foreach ($metadatos as $clave => $valor):
		if ($valor !== '' && duplicadosMetadatoDestacado($clave)):
			$destacados[$clave] = $valor;
		endif;
		if (count($destacados) >= 12):
			break;
		endif;
	endforeach;

	$filas = [];
	$caracteres = 0;
	foreach ($metadatos as $clave => $valor):
		$caracteres += strlen($clave) + strlen($valor);
		if ($caracteres > 180000):
			$filas[] = duplicadosRenderFilaMetadato('Salida recortada', 'La vista recortó metadatos para mantenerse ligera.');
			break;
		endif;
		$filas[] = duplicadosRenderFilaMetadato((string) $clave, (string) $valor);
	endforeach;

	$html =
		'<section class="duplicados-metadatos-archivo">' .
		'<div class="duplicados-metadatos-cabecera">' .
		'<strong>' . escaparHtml($titulo) . '</strong>' .
		'<span>' . $total . ' campo' . ($total === 1 ? '' : 's') . '</span>' .
		'</div>' .
		($subtitulo !== '' ? '<p>' . escaparHtml($subtitulo) . '</p>' : '');

	if ($total === 0):
		return $html . '<p class="duplicados-metadatos-vacio">No hay metadatos disponibles en cache para este archivo.</p></section>';
	endif;

	if (!empty($destacados)):
		$html .= '<dl class="duplicados-metadatos-destacados">';
		foreach ($destacados as $clave => $valor):
			$html .= duplicadosRenderFilaMetadato((string) $clave, (string) $valor);
		endforeach;
		$html .= '</dl>';
	endif;

	$html .=
		'<details class="duplicados-metadatos-detalle" open>' .
		'<summary>Todos los metadatos</summary>' .
		'<dl class="duplicados-metadatos-lista">' . implode('', $filas) . '</dl>' .
		'</details>' .
		'</section>';

	return $html;
}

function duplicadosNormalizarFechaMetadato(string $valor): string
{
	$valor = trim($valor);
	$valor = preg_replace('/^(\d{4}):(\d{2}):(\d{2})(.*)$/', '$1-$2-$3$4', $valor) ?? $valor;
	$valor = str_replace('T', ' ', $valor);
	$valor = preg_replace('/\s+/', ' ', $valor) ?? $valor;

	return trim($valor);
}

function duplicadosTimestampDesdeMetadato(string $valor): ?int
{
	$valor = duplicadosNormalizarFechaMetadato($valor);
	if ($valor === ''):
		return null;
	endif;
	if (is_numeric($valor) && (float) $valor > 100000000):
		return (int) round((float) $valor);
	endif;

	$timestamp = strtotime($valor);
	return $timestamp === false ? null : $timestamp;
}

function duplicadosTimestampNacimientoArchivo(string $ruta): ?int
{
	if (!is_file($ruta)):
		return null;
	endif;

	if (PHP_OS_FAMILY === 'Darwin'):
		$salida = [];
		$codigo = 1;
		@exec(comandoSeguro(['stat', '-f', '%B', $ruta]) . ' 2>/dev/null', $salida, $codigo);
		$timestamp = isset($salida[0]) ? (int) trim((string) $salida[0]) : 0;
		if ($codigo === 0 && $timestamp > 0):
			return $timestamp;
		endif;
	endif;

	$ctime = @filectime($ruta);
	if ($ctime !== false && $ctime > 0):
		return (int) $ctime;
	endif;
	$mtime = @filemtime($ruta);
	return $mtime === false ? null : (int) $mtime;
}

function duplicadosTimestampCreacionMetadatos(array $metadatos, string $ruta): ?int
{
	$valores = [];
	foreach ($metadatos as $clave => $valor):
		$normalizada = duplicadosNormalizarClaveMetadato((string) $clave);
		if ($normalizada !== ''):
			$valores[$normalizada] = (string) $valor;
		endif;
	endforeach;

	$prioridades = [
		['datetimeoriginal'],
		['createdate'],
		['datecreated', 'timecreated'],
		['datecreated'],
		['digitalcreationdate', 'digitalcreationtime'],
		['digitalcreationdate'],
		['creationdate'],
		['datetimecreated'],
		['mediacreatedate'],
		['contentcreatedate'],
		['filecreatedate'],
	];
	foreach ($prioridades as $claves):
		$partes = [];
		foreach ($claves as $clave):
			if (!isset($valores[$clave]) || trim($valores[$clave]) === ''):
				$partes = [];
				break;
			endif;
			$partes[] = $valores[$clave];
		endforeach;
		if (empty($partes)):
			continue;
		endif;
		$timestamp = duplicadosTimestampDesdeMetadato(implode(' ', $partes));
		if ($timestamp !== null):
			return $timestamp;
		endif;
	endforeach;

	return duplicadosTimestampNacimientoArchivo($ruta);
}

function duplicadosMapaMetadatosNormales(array $metadatos): array
{
	$mapa = [];
	foreach ($metadatos as $clave => $valor):
		$claveNormalizada = duplicadosNormalizarClaveMetadato((string) $clave);
		if ($claveNormalizada === '' || duplicadosTipoComparacionMetadato((string) $clave) !== 'normal'):
			continue;
		endif;
		$mapa[$claveNormalizada] = duplicadosHashComparacionMetadato((string) $valor);
	endforeach;
	ksort($mapa, SORT_STRING);

	return $mapa;
}

function duplicadosDiferenciasMetadatosNormales(array $archivos): array
{
	$claves = [];
	foreach ($archivos as $archivo):
		foreach (array_keys($archivo['normales'] ?? []) as $clave):
			$claves[$clave] = true;
		endforeach;
	endforeach;

	$diferencias = [];
	foreach (array_keys($claves) as $clave):
		$valores = [];
		foreach ($archivos as $archivo):
			$valores[] = (string) (($archivo['normales'] ?? [])[$clave] ?? '__ausente__');
		endforeach;
		if (count(array_unique($valores)) > 1):
			$diferencias[] = $clave;
		endif;
	endforeach;
	sort($diferencias, SORT_STRING);

	return $diferencias;
}

function duplicadosArchivosLocalesParaReglaFecha(array $rutas, array &$ausentes = []): array
{
	$archivos = [];
	$vistas = [];
	$ausentes = [];
	foreach ($rutas as $ruta):
		$ruta = trim((string) $ruta);
		if ($ruta === ''):
			continue;
		endif;
		$rutaLocal = resolverRutaTolerante($ruta, 'file', false);
		if ($rutaLocal === null):
			$ausentes[] = $ruta;
			continue;
		endif;
		if (isset($vistas[$rutaLocal])):
			continue;
		endif;
		$metadatosRaw = obtenerMetadatos($rutaLocal);
		$metadatos = is_array($metadatosRaw['resultado'] ?? null) ? $metadatosRaw['resultado'] : [];
		$conteo = 0;
		$aplanados = duplicadosAplanarMetadatos($metadatos, '', $conteo);
		$archivos[] = [
			'ruta' => $rutaLocal,
			'nombre' => basename($rutaLocal),
			'normales' => duplicadosMapaMetadatosNormales($aplanados),
			'timestamp_creacion' => duplicadosTimestampCreacionMetadatos($aplanados, $rutaLocal),
		];
		$vistas[$rutaLocal] = true;
	endforeach;

	return $archivos;
}

function duplicadosRespuestaReglaFechaAjax(array $rutas, string $modo): array
{
	$modo = $modo === 'nuevo' ? 'nuevo' : 'antiguo';
	$ausentes = [];
	$archivos = duplicadosArchivosLocalesParaReglaFecha($rutas, $ausentes);
	if (count($archivos) < 2):
		return [
			'ok' => false,
			'error' => 'Se necesitan al menos dos archivos locales disponibles para aplicar la regla.',
			'rutas_ausentes' => $ausentes,
		];
	endif;

	$diferencias = duplicadosDiferenciasMetadatosNormales($archivos);

	foreach ($archivos as $archivo):
		if (($archivo['timestamp_creacion'] ?? null) === null):
			return [
				'ok' => false,
				'error' => 'No se pudo determinar la fecha de creación de todos los archivos.',
				'rutas_ausentes' => $ausentes,
			];
		endif;
	endforeach;

	$fechas = array_unique(array_map(static fn($archivo) => (int) $archivo['timestamp_creacion'], $archivos));
	if (count($fechas) < 2):
		return [
			'ok' => false,
			'error' => 'Las fechas de creación no tienen diferencias suficientes para elegir una versión.',
			'rutas_ausentes' => $ausentes,
		];
	endif;

	usort($archivos, static function (array $a, array $b) use ($modo): int {
		$comparacion = ((int) $a['timestamp_creacion']) <=> ((int) $b['timestamp_creacion']);
		if ($modo === 'nuevo'):
			$comparacion *= -1;
		endif;
		return $comparacion !== 0 ? $comparacion : strcmp((string) $a['ruta'], (string) $b['ruta']);
	});

	$mantener = $archivos[0];
	$descartar = array_slice($archivos, 1);
	$advertencia = '';
	if (!empty($diferencias)):
		$advertencia = 'Aviso: hay metadatos relevantes diferentes: ' .
			implode(', ', array_slice($diferencias, 0, 8)) .
			(count($diferencias) > 8 ? '...' : '') .
			'.';
	endif;

	return [
		'ok' => true,
		'modo' => $modo,
		'seguro' => empty($diferencias),
		'advertencia' => $advertencia,
		'diferencias' => $diferencias,
		'rutas_ausentes' => $ausentes,
		'mantener' => [
			'ruta' => $mantener['ruta'],
			'nombre' => $mantener['nombre'],
			'timestamp_creacion' => (int) $mantener['timestamp_creacion'],
		],
		'rutas_descartar' => array_values(array_map(static fn($archivo) => (string) $archivo['ruta'], $descartar)),
		'mensaje' => 'Se mantendrá ' . $mantener['nombre'] . ' y se descartarán ' . count($descartar) . ' archivo' . (count($descartar) === 1 ? '' : 's') . '.',
	];
}

function duplicadosBuscarYandexCachePorRuta(string $ruta): ?array
{
	$ruta = normalizarRutaYandexDisk($ruta);
	if ($ruta === '/'):
		return null;
	endif;

	$pdo = function_exists('conectarCatalogoMultimedia') ? conectarCatalogoMultimedia() : null;
	if (!$pdo):
		return null;
	endif;

	try {
		$stmt = $pdo->prepare("
			SELECT
				ruta, ruta_remota, nombre, tipo, mime, tamano, mtime,
				md5, sha256, ancho, alto, duracion, contenido_hash, perceptual_hash,
				url, actualizado, hash_actualizado
			FROM medios
			WHERE origen = 'yandex'
				AND existente = 1
				AND (ruta = :ruta_catalogo OR ruta_remota = :ruta_remota)
			LIMIT 1
		");
		$stmt->execute([
			':ruta_catalogo' => 'yandex:' . $ruta,
			':ruta_remota' => $ruta,
		]);
		$fila = $stmt->fetch(PDO::FETCH_ASSOC);
	} catch (PDOException $e) {
		return null;
	}
	if (!is_array($fila)):
		return null;
	endif;

	return duplicadosItemYandexDesdeCatalogoFila($fila);
}

function duplicadosRespuestaMetadatosAjax(string $origen, string $ruta): array
{
	$origen = $origen === 'yandex' ? 'yandex' : 'local';
	if ($origen === 'yandex'):
		$item = duplicadosBuscarYandexCachePorRuta($ruta);
		if ($item === null):
			return ['ok' => false, 'error' => 'No se encontró el archivo remoto en el cache de Yandex.Disk.'];
		endif;
		$metadatos = [
			'Nombre' => (string) ($item['nombre'] ?? basename((string) ($item['ruta'] ?? ''))),
			'Ruta Yandex' => (string) ($item['ruta'] ?? ''),
			'MIME' => (string) ($item['mime'] ?? ''),
			'Tipo' => (string) ($item['tipo'] ?? ''),
			'Tamaño' => (string) ($item['tamano_legible'] ?? ''),
			'Modificado' => (string) ($item['modificado'] ?? ''),
			'MD5' => (string) ($item['md5'] ?? ''),
			'SHA-256' => (string) ($item['sha256'] ?? ''),
			'Píxeles' => (string) ($item['contenido_hash'] ?? ''),
			'dHash' => (string) ($item['perceptual_hash'] ?? ''),
			'Dimensiones' => duplicadosTextoDimensiones($item),
			'Duración' => duplicadosTextoDuracion($item),
		];
		$exif = is_array($item['exif'] ?? null) ? $item['exif'] : [];
		if (!empty($exif)):
			$metadatos['EXIF'] = $exif;
		endif;

		return [
			'ok' => true,
			'html' => duplicadosRenderMetadatosArchivo(
				$metadatos,
				'Metadatos en cache de Yandex.Disk',
				'Yandex.Disk sólo expone los metadatos que estén presentes en su respuesta cacheada; no se descarga el archivo multimedia.'
			),
		];
	endif;

	$rutaLocal = resolverRutaTolerante($ruta, 'file', false);
	if ($rutaLocal === null):
		return ['ok' => false, 'error' => 'No se pudo resolver la ruta local del archivo.'];
	endif;

	$metadatos = [
		'Nombre' => basename($rutaLocal),
		'Ruta local' => $rutaLocal,
		'Tamaño' => yandexDiskFormatoTamano((int) filesize($rutaLocal)),
		'Modificado' => duplicadosFormatearFecha((int) filemtime($rutaLocal)),
	];
	$resultado = obtenerMetadatos($rutaLocal);
	if (is_array($resultado['resultado'] ?? null)):
		$metadatos += $resultado['resultado'];
	endif;

	if (($resultado['resultado'] ?? false) === false):
		$metadatos['Lectura exiftool'] = 'No se pudieron leer metadatos completos con exiftool.';
		return [
			'ok' => true,
			'html' => duplicadosRenderMetadatosArchivo($metadatos, 'Metadatos locales'),
		];
	endif;

	return [
		'ok' => true,
		'html' => duplicadosRenderMetadatosArchivo($metadatos, 'Metadatos locales leídos con exiftool'),
	];
}

/**
 * @deprecated El nombre se conserva por compatibilidad; la fuente de datos es el catálogo SQLite.
 */
function duplicadosObtenerYandexCache(): array
{
	static $cache = null;
	if ($cache !== null):
		return $cache;
	endif;

	$resumen = duplicadosYandexCatalogoResumen();
	$cache = [
		'items' => [],
		'total' => (int) ($resumen['total'] ?? 0),
		'actualizado' => (int) ($resumen['actualizado'] ?? 0),
	];

	return $cache;
}

function duplicadosNombreNormalizado(array $item): string
{
	$nombre = (string) ($item['nombre'] ?? basename((string) ($item['ruta'] ?? '')));
	$nombre = preg_replace('/\.[^.]+$/', '', $nombre) ?? $nombre;
	$nombre = mb_strtolower($nombre, 'UTF-8');
	$nombre = preg_replace('/\b(?:copy|copia|duplicate|duplicado|edited|editado)\b/u', '', $nombre) ?? $nombre;
	$nombre = preg_replace('/\s*[\(\[]\d+[\)\]]$/u', '', $nombre) ?? $nombre;
	$nombre = preg_replace('/[^\pL\pN]+/u', ' ', $nombre) ?? $nombre;
	return trim(preg_replace('/\s+/u', ' ', $nombre) ?? $nombre);
}

function duplicadosExtension(array $item): string
{
	return mb_strtolower(pathinfo((string) ($item['nombre'] ?? $item['ruta'] ?? ''), PATHINFO_EXTENSION), 'UTF-8');
}

function duplicadosHexHamming(string $a, string $b): ?int
{
	$a = strtolower(trim($a));
	$b = strtolower(trim($b));
	if ($a === '' || $b === '' || strlen($a) !== strlen($b) || preg_match('/^[0-9a-f]+$/', $a . $b) !== 1):
		return null;
	endif;

	$distancia = 0;
	$largo = strlen($a);
	for ($i = 0; $i < $largo; $i++):
		$xor = hexdec($a[$i]) ^ hexdec($b[$i]);
		$distancia += substr_count(decbin($xor), '1');
	endfor;

	return $distancia;
}

function duplicadosScoreItems(array $a, array $b): array
{
	$razones = [];
	$shaA = strtolower(trim((string) ($a['sha256'] ?? '')));
	$shaB = strtolower(trim((string) ($b['sha256'] ?? '')));
	if ($shaA !== '' && hash_equals($shaA, $shaB)):
		return ['score' => 100, 'metodo' => 'SHA-256 exacto', 'hash' => $shaA, 'razones' => ['SHA-256 exacto']];
	endif;

	$md5A = strtolower(trim((string) ($a['md5'] ?? '')));
	$md5B = strtolower(trim((string) ($b['md5'] ?? '')));
	if ($md5A !== '' && hash_equals($md5A, $md5B) && !($shaA !== '' && $shaB !== '' && !hash_equals($shaA, $shaB))):
		return ['score' => 100, 'metodo' => 'MD5 exacto', 'hash' => $md5A, 'razones' => ['MD5 exacto']];
	endif;

	$contenidoA = strtolower(trim((string) ($a['contenido_hash'] ?? '')));
	$contenidoB = strtolower(trim((string) ($b['contenido_hash'] ?? '')));
	if ($contenidoA !== '' && hash_equals($contenidoA, $contenidoB)):
		return ['score' => 95, 'metodo' => 'Contenido visual', 'hash' => $contenidoA, 'razones' => ['Pixeles idénticos sin metadatos']];
	endif;

	$tipoA = duplicadosTipoVistaPrevia($a);
	$tipoB = duplicadosTipoVistaPrevia($b);
	$ambosVideo = $tipoA === 'video' && $tipoB === 'video';
	$soporte = 0;
	$soportePerceptual = 0;
	if ($tipoA !== '' && $tipoA === $tipoB):
		$soporte += 5;
		$razones[] = 'Mismo tipo de medio';
	endif;

	$scoreVisual = 0;
	$perceptualA = strtolower(trim((string) ($a['perceptual_hash'] ?? '')));
	$perceptualB = strtolower(trim((string) ($b['perceptual_hash'] ?? '')));
	$distancia = duplicadosHexHamming($perceptualA, $perceptualB);
	if ($distancia !== null):
		if ($ambosVideo):
			$bits = max(64, strlen($perceptualA) * 4);
			$limiteCercano = max(4, (int) round($bits * 0.04));
			$limiteCompatible = max(8, (int) round($bits * 0.08));
			if ($distancia === 0):
				$scoreVisual = 78;
				$razones[] = 'Frames perceptuales idénticos';
			elseif ($distancia <= $limiteCercano):
				$scoreVisual = max(70, 76 - (int) floor($distancia / 2));
				$razones[] = 'Frames perceptuales cercanos (' . $distancia . ')';
			elseif ($distancia <= $limiteCompatible):
				$scoreVisual = max(62, 68 - (int) floor(($distancia - $limiteCercano) / 2));
				$razones[] = 'Frames perceptuales compatibles (' . $distancia . ')';
			endif;
		else:
			if ($distancia === 0):
				$scoreVisual = 68;
				$razones[] = 'dHash perceptual idéntico';
			elseif ($distancia <= 4):
				$scoreVisual = 66 - $distancia;
				$razones[] = 'dHash perceptual cercano (' . $distancia . ')';
			elseif ($distancia <= 8):
				$scoreVisual = 58 - ($distancia - 4);
				$razones[] = 'dHash perceptual compatible (' . $distancia . ')';
			endif;
		endif;
	endif;

	$anchoA = (int) ($a['ancho'] ?? 0);
	$altoA = (int) ($a['alto'] ?? 0);
	$anchoB = (int) ($b['ancho'] ?? 0);
	$altoB = (int) ($b['alto'] ?? 0);
	if ($anchoA > 0 && $altoA > 0 && $anchoB > 0 && $altoB > 0):
		if ($anchoA === $anchoB && $altoA === $altoB):
			$puntos = $ambosVideo ? 8 : 22;
			$soporte += $puntos;
			$soportePerceptual += $puntos;
			$razones[] = 'Mismas dimensiones';
		elseif ($anchoA === $altoB && $altoA === $anchoB):
			$puntos = $ambosVideo ? 5 : 16;
			$soporte += $puntos;
			$soportePerceptual += $puntos;
			$razones[] = 'Dimensiones rotadas';
		endif;
	endif;

	$duracionA = (float) ($a['duracion'] ?? 0);
	$duracionB = (float) ($b['duracion'] ?? 0);
	if ($duracionA > 0 && $duracionB > 0):
		$diferencia = abs($duracionA - $duracionB);
		$referencia = max($duracionA, $duracionB);
		if ($diferencia <= ($ambosVideo ? 0.25 : 1)):
			$puntos = $ambosVideo ? 10 : 30;
			$soporte += $puntos;
			$soportePerceptual += $puntos;
			$razones[] = 'Duración casi idéntica';
		elseif ($diferencia <= ($ambosVideo ? 1.0 : 3)):
			$puntos = $ambosVideo ? 6 : 22;
			$soporte += $puntos;
			$soportePerceptual += $puntos;
			$razones[] = 'Duración muy cercana';
		elseif ($referencia > 0 && ($diferencia / $referencia) <= 0.02):
			$puntos = $ambosVideo ? 4 : 14;
			$soporte += $puntos;
			$soportePerceptual += $puntos;
			$razones[] = 'Duración compatible';
		endif;
	endif;

	$tamanoA = (int) ($a['tamano'] ?? 0);
	$tamanoB = (int) ($b['tamano'] ?? 0);
	if ($tamanoA > 0 && $tamanoB > 0):
		$diferenciaTamano = abs($tamanoA - $tamanoB) / max($tamanoA, $tamanoB);
		if ($diferenciaTamano <= 0.000001):
			$puntos = $ambosVideo ? 8 : 26;
			$soporte += $puntos;
			$soportePerceptual += $puntos;
			$razones[] = 'Mismo tamaño';
		elseif ($diferenciaTamano <= 0.01):
			$puntos = $ambosVideo ? 5 : 18;
			$soporte += $puntos;
			$soportePerceptual += $puntos;
			$razones[] = 'Tamaño casi igual';
		elseif ($diferenciaTamano <= 0.05):
			$puntos = $ambosVideo ? 2 : 10;
			$soporte += $puntos;
			$soportePerceptual += $puntos;
			$razones[] = 'Tamaño similar';
		endif;
	endif;

	$nombreA = duplicadosNombreNormalizado($a);
	$nombreB = duplicadosNombreNormalizado($b);
	if ($nombreA !== '' && $nombreB !== ''):
		if ($nombreA === $nombreB):
			$puntos = $ambosVideo ? 18 : 35;
			$soporte += $puntos;
			$soportePerceptual += $puntos;
			$razones[] = 'Nombre base igual';
		else:
			similar_text($nombreA, $nombreB, $porcentaje);
			if ($porcentaje >= 92):
				$puntos = $ambosVideo ? 10 : 24;
				$soporte += $puntos;
				$soportePerceptual += $puntos;
				$razones[] = 'Nombre muy parecido';
			elseif ($porcentaje >= 80):
				$puntos = $ambosVideo ? 5 : 14;
				$soporte += $puntos;
				$soportePerceptual += $puntos;
				$razones[] = 'Nombre parecido';
			endif;
		endif;
	endif;

	$fechaA = (int) ($a['modificado_ts'] ?? 0);
	$fechaB = (int) ($b['modificado_ts'] ?? 0);
	if ($fechaA > 0 && $fechaB > 0):
		$diferenciaFecha = abs($fechaA - $fechaB);
		if ($diferenciaFecha <= 60):
			$soporte += 8;
			$razones[] = 'Fechas muy cercanas';
		elseif ($diferenciaFecha <= 3600):
			$soporte += 4;
			$razones[] = 'Fechas cercanas';
		endif;
	endif;

	$extensionA = duplicadosExtension($a);
	$extensionB = duplicadosExtension($b);
	if ($extensionA !== '' && $extensionA === $extensionB):
		$soporte += $ambosVideo ? 2 : 4;
		$razones[] = 'Misma extensión';
	endif;

	if ($scoreVisual > 0):
		$score = min(94, $scoreVisual + min($ambosVideo ? 12 : 24, intdiv($soportePerceptual, 4)));
		$metodo = 'Perceptual';
	else:
		$score = min($ambosVideo ? 64 : 84, $soporte);
		$metodo = $ambosVideo ? 'Video heurístico' : 'Heurístico';
		if ($ambosVideo && $soporte > 0):
			$razones[] = 'Video sin firma visual de frames';
		endif;
	endif;

	return [
		'score' => $score,
		'metodo' => $metodo,
		'hash' => '',
		'razones' => array_values(array_unique($razones)),
	];
}

function duplicadosAgregarBucket(array &$buckets, string $clave, int $indice): void
{
	$clave = trim($clave);
	if ($clave === ''):
		return;
	endif;
	if (!array_key_exists($clave, $buckets)):
		$buckets[$clave] = $indice;
		return;
	endif;
	if ($buckets[$clave] === null):
		return;
	endif;
	if (!is_array($buckets[$clave])):
		$buckets[$clave] = [(int) $buckets[$clave], $indice];
		return;
	endif;
	$buckets[$clave][] = $indice;
	if (count($buckets[$clave]) > DUPLICADOS_BUCKET_PROBABLE_MAX):
		$buckets[$clave] = null;
	endif;
}

function duplicadosBucketsItem(array $item, int $indice, array &$buckets, bool $incluirNombre = true): void
{
	$tipoVista = duplicadosTipoVistaPrevia($item);
	$perceptual = strtolower(trim((string) ($item['perceptual_hash'] ?? '')));
	if ($perceptual !== ''):
		if ($tipoVista === 'video' && strlen($perceptual) >= 32):
			for ($offset = 0; $offset + 16 <= strlen($perceptual); $offset += 16):
				duplicadosAgregarBucket($buckets, 'vdhash:' . $offset . ':' . substr($perceptual, $offset, 4), $indice);
			endfor;
		else:
			duplicadosAgregarBucket($buckets, 'dhash:' . substr($perceptual, 0, 4), $indice);
		endif;
	endif;

	if ($incluirNombre):
		$nombre = duplicadosNombreNormalizado($item);
		if (mb_strlen($nombre, 'UTF-8') >= 4):
			duplicadosAgregarBucket($buckets, 'nombre:' . $tipoVista . ':' . $nombre, $indice);
		endif;
	endif;

	if ($tipoVista === 'video'):
		return;
	endif;

	$ancho = (int) ($item['ancho'] ?? 0);
	$alto = (int) ($item['alto'] ?? 0);
	if ($ancho > 0 && $alto > 0):
		duplicadosAgregarBucket($buckets, 'dim:' . $tipoVista . ':' . $ancho . 'x' . $alto, $indice);
	endif;

	$duracion = (float) ($item['duracion'] ?? 0);
	if ($duracion > 0 && $ancho > 0 && $alto > 0):
		duplicadosAgregarBucket($buckets, 'video:' . $ancho . 'x' . $alto . ':' . (int) round($duracion), $indice);
	endif;

	$tamano = (int) ($item['tamano'] ?? 0);
	if ($tamano > 0):
		duplicadosAgregarBucket($buckets, 'tamano:' . $tipoVista . ':' . $tamano, $indice);
	endif;
}

function duplicadosRegistrarClaveGrupo(string $clave, int $indice, array &$primeros, array &$grupos): void
{
	if ($clave === ''):
		return;
	endif;
	if (isset($grupos[$clave])):
		$grupos[$clave][] = $indice;
		return;
	endif;
	if (isset($primeros[$clave])):
		$grupos[$clave] = [$primeros[$clave], $indice];
		unset($primeros[$clave]);
		return;
	endif;

	$primeros[$clave] = $indice;
}

function duplicadosBuscarPadre(array &$padres, int $indice): int
{
	while ($padres[$indice] !== $indice):
		$padres[$indice] = $padres[$padres[$indice]];
		$indice = $padres[$indice];
	endwhile;

	return $indice;
}

function duplicadosUnir(array &$padres, int $a, int $b): void
{
	$pa = duplicadosBuscarPadre($padres, $a);
	$pb = duplicadosBuscarPadre($padres, $b);
	if ($pa !== $pb):
		$padres[$pb] = $pa;
	endif;
}

function duplicadosClavePar(int $a, int $b): string
{
	return $a < $b ? $a . ':' . $b : $b . ':' . $a;
}

function duplicadosMarcarParesCubiertos(array $indices, array &$cubiertos): void
{
	$indices = array_values(array_unique(array_map('intval', $indices)));
	$total = count($indices);
	for ($i = 0; $i < $total - 1; $i++):
		for ($j = $i + 1; $j < $total; $j++):
			$cubiertos[duplicadosClavePar($indices[$i], $indices[$j])] = true;
		endfor;
	endfor;
}

function duplicadosDescriptorDesdeIndices(array $items, array $indices, int $score, string $metodo, string $hash, array $razones): array
{
	$indices = array_values(array_unique(array_filter(
		array_map('intval', $indices),
		fn($indice) => isset($items[$indice])
	)));
	$origenes = [];
	foreach ($indices as $indice):
		$origen = (string) ($items[$indice]['origen'] ?? '');
		if ($origen !== ''):
			$origenes[$origen] = true;
		endif;
	endforeach;

	return [
		'indices' => $indices,
		'score' => $score,
		'metodo' => $metodo,
		'hash' => $hash,
		'razones' => array_values(array_unique(array_filter($razones))),
		'cruzado' => isset($origenes['local'], $origenes['yandex']),
		'conteo' => count($indices),
	];
}

function duplicadosOrdenarDescriptores(array &$descriptores): void
{
	usort($descriptores, static function (array $a, array $b): int {
		$score = ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0));
		if ($score !== 0):
			return $score;
		endif;
		$cruzado = ((int) ($b['cruzado'] ?? false)) <=> ((int) ($a['cruzado'] ?? false));
		if ($cruzado !== 0):
			return $cruzado;
		endif;
		$conteo = ((int) ($b['conteo'] ?? 0)) <=> ((int) ($a['conteo'] ?? 0));
		if ($conteo !== 0):
			return $conteo;
		endif;
		return strcmp((string) ($a['hash'] ?? ''), (string) ($b['hash'] ?? ''));
	});
}

function duplicadosAgregarProbableCandidato(array &$porScore, array $descriptor, int $maximo, int &$total): bool
{
	if ($maximo < 1 || (int) ($descriptor['conteo'] ?? 0) < 2):
		return false;
	endif;

	$score = max(DUPLICADOS_SCORE_MINIMO, min(99, (int) ($descriptor['score'] ?? 0)));
	$descriptor['score'] = $score;
	if ($total >= $maximo):
		$puntajes = array_map('intval', array_keys($porScore));
		$menor = empty($puntajes) ? $score : min($puntajes);
		if ($score <= $menor):
			return false;
		endif;
		array_shift($porScore[$menor]);
		if (empty($porScore[$menor])):
			unset($porScore[$menor]);
		endif;
		$total--;
	endif;

	$porScore[$score][] = $descriptor;
	$total++;
	return true;
}

function duplicadosNormalizarFiltroOrigen(mixed $valor): string
{
	$filtro = mb_strtolower(trim((string) $valor), 'UTF-8');
	return in_array($filtro, ['local', 'remoto', 'mixto'], true) ? $filtro : 'todos';
}

function duplicadosTipoOrigenGrupoDesdeItems(array $items): string
{
	$origenes = array_values(array_unique(array_filter(array_map(
		fn($item) => (string) ($item['origen'] ?? ''),
		$items
	))));
	$tieneLocal = in_array('local', $origenes, true);
	$tieneYandex = in_array('yandex', $origenes, true);
	if ($tieneLocal && $tieneYandex):
		return 'mixto';
	endif;
	if ($tieneYandex && !$tieneLocal):
		return 'remoto';
	endif;

	return 'local';
}

function duplicadosGrupoCoincideFiltro(array $grupo, string $filtro): bool
{
	$filtro = duplicadosNormalizarFiltroOrigen($filtro);
	if ($filtro === 'todos'):
		return true;
	endif;

	$tipo = (string) ($grupo['origen_tipo'] ?? '');
	if ($tipo === ''):
		$tipo = duplicadosTipoOrigenGrupoDesdeItems(is_array($grupo['items'] ?? null) ? $grupo['items'] : []);
	endif;
	return $tipo === $filtro;
}

function duplicadosGrupoDesdeIndices(array $items, array $indices, int $score, string $metodo, string $hash, array $razones): array
{
	$itemsGrupo = array_map(fn($indice) => $items[(int) $indice], array_values(array_unique($indices)));
	$origenes = array_values(array_unique(array_map(fn($item) => (string) ($item['origen'] ?? ''), $itemsGrupo)));
	$origenTipo = duplicadosTipoOrigenGrupoDesdeItems($itemsGrupo);
	$md5s = array_values(array_unique(array_filter(array_map(fn($item) => (string) ($item['md5'] ?? ''), $itemsGrupo))));
	$sha256s = array_values(array_unique(array_filter(array_map(fn($item) => (string) ($item['sha256'] ?? ''), $itemsGrupo))));
	usort($itemsGrupo, static function (array $a, array $b): int {
		$origen = strcmp((string) ($a['origen'] ?? ''), (string) ($b['origen'] ?? ''));
		if ($origen !== 0):
			return $origen;
		endif;
		return strnatcasecmp((string) ($a['ruta'] ?? ''), (string) ($b['ruta'] ?? ''));
	});

	return [
		'tipo' => $score >= 100 ? $metodo : 'Score probable',
		'hash' => $hash,
		'score' => $score,
		'metodo' => $metodo,
		'razones' => array_values(array_unique(array_filter($razones))),
		'items' => $itemsGrupo,
		'origenes' => $origenes,
		'md5s' => $md5s,
		'sha256s' => $sha256s,
		'origen_tipo' => $origenTipo,
		'cruzado' => $origenTipo === 'mixto',
		'conteo' => count($itemsGrupo),
	];
}

function duplicadosGruposDesdeDescriptores(array $items, array $descriptores): array
{
	$grupos = [];
	foreach ($descriptores as $descriptor):
		$grupos[] = duplicadosGrupoDesdeIndices(
			$items,
			(array) ($descriptor['indices'] ?? []),
			(int) ($descriptor['score'] ?? 0),
			(string) ($descriptor['metodo'] ?? 'Score probable'),
			(string) ($descriptor['hash'] ?? ''),
			(array) ($descriptor['razones'] ?? [])
		);
	endforeach;

	return $grupos;
}

function duplicadosPaginaDesdeDescriptores(array $items, array $descriptores, int $limite, int $offset, string $filtroOrigen = 'todos'): array
{
	$filtroOrigen = duplicadosNormalizarFiltroOrigen($filtroOrigen);
	duplicadosOrdenarDescriptores($descriptores);
	if ($filtroOrigen !== 'todos'):
		$grupos = [];
		$omitidos = 0;
		foreach ($descriptores as $descriptor):
			$grupo = duplicadosGrupoDesdeIndices(
				$items,
				(array) ($descriptor['indices'] ?? []),
				(int) ($descriptor['score'] ?? 0),
				(string) ($descriptor['metodo'] ?? 'Score probable'),
				(string) ($descriptor['hash'] ?? ''),
				(array) ($descriptor['razones'] ?? [])
			);
			if (!duplicadosGrupoCoincideFiltro($grupo, $filtroOrigen)):
				continue;
			endif;
			if ($omitidos < $offset):
				$omitidos++;
				continue;
			endif;
			$grupos[] = $grupo;
			if ($limite > 0 && count($grupos) >= $limite):
				break;
			endif;
		endforeach;
		return $grupos;
	endif;

	if ($limite > 0):
		$descriptores = array_slice($descriptores, $offset, $limite);
	elseif ($offset > 0):
		$descriptores = array_slice($descriptores, $offset);
	endif;

	return duplicadosGruposDesdeDescriptores($items, $descriptores);
}

function duplicadosClaveExactaItem(array $item): string
{
	$sha = strtolower(trim((string) ($item['sha256'] ?? '')));
	if ($sha !== ''):
		return 'SHA-256 exacto|' . $sha;
	endif;
	$md5 = strtolower(trim((string) ($item['md5'] ?? '')));
	return $md5 !== '' ? 'MD5 exacto|' . $md5 : '';
}

function duplicadosClaveVisualItem(array $item): string
{
	$contenido = strtolower(trim((string) ($item['contenido_hash'] ?? '')));
	return $contenido !== '' ? 'Contenido visual|' . $contenido : '';
}

function duplicadosAdjuntarCatalogo(PDO $pdo): bool
{
	try {
		$bases = $pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) ?: [];
		foreach ($bases as $base):
			if ((string) ($base['name'] ?? '') === 'catalogo'):
				return true;
			endif;
		endforeach;
		$pdo->exec('ATTACH DATABASE ' . $pdo->quote(catalogoRutaBaseDatos()) . ' AS catalogo');
		return true;
	} catch (PDOException $e) {
		return false;
	}
}

function duplicadosConstruirGruposMixtosExactos(?string $base, int $limite, int $offset): array
{
	$pdo = conectarBaseDuplicados();
	if (!$pdo || !function_exists('catalogoRutaBaseDatos') || !is_file(catalogoRutaBaseDatos()) || !duplicadosAdjuntarCatalogo($pdo)):
		return [];
	endif;

	$base = duplicadosNormalizarRutaLocal((string) $base);
	$prefijo = $base !== '' ? rtrim($base, '/') . '/' : '';
	$filtroBase = $prefijo !== '' ? ' AND substr(l.ruta, 1, :prefijo_len) = :prefijo' : '';
	$sql = "
		SELECT metodo, hash, MAX(orden) AS orden
		FROM (
			SELECT 'SHA-256 exacto' AS metodo, l.sha256 AS hash, MAX(l.mtime) AS orden
			FROM archivos_hash l
			INNER JOIN catalogo.medios y ON y.sha256 = l.sha256
			WHERE l.sha256 <> ''
				AND y.origen = 'yandex'
				AND y.existente = 1
				AND y.sha256 <> ''
				$filtroBase
			GROUP BY l.sha256
			UNION ALL
			SELECT 'MD5 exacto' AS metodo, l.md5 AS hash, MAX(l.mtime) AS orden
			FROM archivos_hash l
			INNER JOIN catalogo.medios y ON y.md5 = l.md5
			WHERE l.sha256 = ''
				AND y.sha256 = ''
				AND l.md5 <> ''
				AND y.origen = 'yandex'
				AND y.existente = 1
				AND y.md5 <> ''
				$filtroBase
			GROUP BY l.md5
		)
		GROUP BY metodo, hash
		ORDER BY orden DESC, hash ASC
		LIMIT :limite OFFSET :offset
	";

	try {
		$stmt = $pdo->prepare($sql);
		if ($prefijo !== ''):
			$stmt->bindValue(':prefijo', $prefijo, PDO::PARAM_STR);
			$stmt->bindValue(':prefijo_len', strlen($prefijo), PDO::PARAM_INT);
		endif;
		$stmt->bindValue(':limite', max(1, $limite), PDO::PARAM_INT);
		$stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
		$stmt->execute();
		$candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (PDOException $e) {
		return [];
	}

	$grupos = [];
	foreach ($candidatos as $candidato):
		$metodo = (string) ($candidato['metodo'] ?? '');
		$hash = (string) ($candidato['hash'] ?? '');
		if ($metodo === '' || $hash === ''):
			continue;
		endif;
		$campo = $metodo === 'SHA-256 exacto' ? 'sha256' : 'md5';
		$stmtLocal = $pdo->prepare("SELECT * FROM archivos_hash WHERE $campo = :hash ORDER BY ruta ASC");
		$stmtLocal->execute([':hash' => $hash]);
		$locales = [];
		foreach (($stmtLocal->fetchAll(PDO::FETCH_ASSOC) ?: []) as $filaLocal):
			$rutaLocal = (string) ($filaLocal['ruta'] ?? '');
			if (!duplicadosRutaLocalDentroDeBase($rutaLocal, $base) || !is_file($rutaLocal)):
				continue;
			endif;
			$item = duplicadosItemLocalDesdeFila($filaLocal);
			if ($item !== null):
				$locales[] = $item;
			endif;
		endforeach;
		if (empty($locales)):
			continue;
		endif;

		$stmtYandex = $pdo->prepare("
			SELECT
				ruta, ruta_remota, nombre, tipo, mime, tamano, mtime,
				md5, sha256, ancho, alto, duracion, contenido_hash, perceptual_hash, url, actualizado
			FROM catalogo.medios
			WHERE origen = 'yandex'
				AND existente = 1
				AND $campo = :hash
			ORDER BY ruta_remota ASC
		");
		$stmtYandex->execute([':hash' => $hash]);
		$remotos = [];
		foreach (($stmtYandex->fetchAll(PDO::FETCH_ASSOC) ?: []) as $filaYandex):
			$item = duplicadosItemYandexDesdeCatalogoFila($filaYandex);
			if ($item !== null):
				$remotos[] = $item;
			endif;
		endforeach;
		if (empty($remotos)):
			continue;
		endif;
		$itemsGrupo = array_values(array_merge($locales, $remotos));
		$grupos[] = duplicadosGrupoDesdeIndices(
			$itemsGrupo,
			array_keys($itemsGrupo),
			100,
			$metodo,
			$hash,
			[$metodo]
		);
	endforeach;

	return $grupos;
}

function duplicadosSqlGruposRemotosExactos(): string
{
	return "
		SELECT metodo, hash, MAX(orden) AS orden
		FROM (
			SELECT 'SHA-256 exacto' AS metodo, sha256 AS hash, MAX(mtime) AS orden
			FROM medios
			WHERE origen = 'yandex'
				AND existente = 1
				AND sha256 <> ''
			GROUP BY sha256
			HAVING COUNT(*) >= 2
			UNION ALL
			SELECT 'MD5 exacto' AS metodo, md5 AS hash, MAX(mtime) AS orden
			FROM medios
			WHERE origen = 'yandex'
				AND existente = 1
				AND sha256 = ''
				AND md5 <> ''
			GROUP BY md5
			HAVING COUNT(*) >= 2
		)
		GROUP BY metodo, hash
	";
}

function duplicadosConstruirGruposRemotosExactos(int $limite, int $offset): array
{
	$pdo = function_exists('conectarCatalogoMultimedia') ? conectarCatalogoMultimedia() : null;
	if (!$pdo):
		return [];
	endif;

	try {
		$stmt = $pdo->prepare(
			duplicadosSqlGruposRemotosExactos() . "
			ORDER BY orden DESC, hash ASC
			LIMIT :limite OFFSET :offset
		"
		);
		$stmt->bindValue(':limite', max(1, $limite), PDO::PARAM_INT);
		$stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
		$stmt->execute();
		$candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (PDOException $e) {
		return [];
	}

	$grupos = [];
	foreach ($candidatos as $candidato):
		$metodo = (string) ($candidato['metodo'] ?? '');
		$hash = (string) ($candidato['hash'] ?? '');
		if ($metodo === '' || $hash === ''):
			continue;
		endif;
		$campo = $metodo === 'SHA-256 exacto' ? 'sha256' : 'md5';
		$filtroShaVacio = $campo === 'md5' ? "AND sha256 = ''" : '';
		$stmtItems = $pdo->prepare("
			SELECT
				ruta, ruta_remota, nombre, tipo, mime, tamano, mtime,
				md5, sha256, ancho, alto, duracion, contenido_hash, perceptual_hash, url, actualizado
			FROM medios
			WHERE origen = 'yandex'
				AND existente = 1
				AND $campo = :hash
				$filtroShaVacio
			ORDER BY ruta_remota ASC
		");
		$stmtItems->execute([':hash' => $hash]);
		$items = [];
		foreach (($stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: []) as $fila):
			$item = duplicadosItemYandexDesdeCatalogoFila($fila);
			if ($item !== null):
				$items[] = $item;
			endif;
		endforeach;
		if (count($items) < 2):
			continue;
		endif;
		$grupos[] = duplicadosGrupoDesdeIndices(
			$items,
			array_keys($items),
			100,
			$metodo,
			$hash,
			[$metodo]
		);
	endforeach;

	return $grupos;
}

function duplicadosContarGruposRemotosExactos(int $maximo = DUPLICADOS_CONTEO_GRUPOS_MAX): array
{
	$pdo = function_exists('conectarCatalogoMultimedia') ? conectarCatalogoMultimedia() : null;
	if (!$pdo):
		return duplicadosConteoOrigenVacio(true);
	endif;

	try {
		$sql = 'SELECT COUNT(*) FROM (' . duplicadosSqlGruposRemotosExactos() . ')';
		$total = (int) $pdo->query($sql)->fetchColumn();
	} catch (PDOException $e) {
		return duplicadosConteoOrigenVacio(true);
	}

	return [
		'grupos' => min($maximo, $total),
		'mas' => $total > $maximo,
		'pendiente' => false,
	];
}

function duplicadosOrdenarGrupos(array &$grupos): void
{
	usort($grupos, static function (array $a, array $b): int {
		$score = ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0));
		if ($score !== 0):
			return $score;
		endif;
		$cruzado = ((int) ($b['cruzado'] ?? false)) <=> ((int) ($a['cruzado'] ?? false));
		if ($cruzado !== 0):
			return $cruzado;
		endif;
		$conteo = ((int) ($b['conteo'] ?? 0)) <=> ((int) ($a['conteo'] ?? 0));
		if ($conteo !== 0):
			return $conteo;
		endif;
		return strcmp((string) ($a['hash'] ?? ''), (string) ($b['hash'] ?? ''));
	});
}

function duplicadosConstruirGruposTodosPaginados(?string $base, int $limite, int $offset): array
{
	$limite = $limite > 0 ? $limite : DUPLICADOS_GRUPOS_POR_PAGINA;
	$necesarios = max(1, $offset + $limite);
	$mixtos = duplicadosConstruirGrupos($base, $necesarios, 0, 'mixto');
	$clavesMixtas = [];
	foreach ($mixtos as $grupo):
		$hash = (string) ($grupo['hash'] ?? '');
		if ($hash !== '' && (int) ($grupo['score'] ?? 0) >= 100):
			$clavesMixtas[(string) ($grupo['metodo'] ?? $grupo['tipo'] ?? '') . '|' . $hash] = true;
		endif;
	endforeach;

	$grupos = $mixtos;
	foreach (['local', 'remoto'] as $filtro):
		foreach (duplicadosConstruirGrupos($base, $necesarios, 0, $filtro) as $grupo):
			$hash = (string) ($grupo['hash'] ?? '');
			$clave = (string) ($grupo['metodo'] ?? $grupo['tipo'] ?? '') . '|' . $hash;
			if ($hash !== '' && (int) ($grupo['score'] ?? 0) >= 100 && isset($clavesMixtas[$clave])):
				continue;
			endif;
			$grupos[] = $grupo;
		endforeach;
	endforeach;
	if (empty($grupos)):
		return [];
	endif;

	duplicadosOrdenarGrupos($grupos);
	return array_slice($grupos, $offset, $limite);
}

function duplicadosConstruirGrupos(?string $base = null, int $limite = 200, int $offset = 0, string $filtroOrigen = 'todos'): array
{
	$limite = max(0, $limite);
	$offset = max(0, $offset);
	$filtroOrigen = duplicadosNormalizarFiltroOrigen($filtroOrigen);
	if ($filtroOrigen === 'todos'):
		return duplicadosConstruirGruposTodosPaginados($base, $limite, $offset);
	endif;
	if ($filtroOrigen === 'mixto'):
		return duplicadosConstruirGruposMixtosExactos($base, $limite > 0 ? $limite : DUPLICADOS_GRUPOS_POR_PAGINA, $offset);
	endif;
	if ($filtroOrigen === 'remoto'):
		return duplicadosConstruirGruposRemotosExactos($limite > 0 ? $limite : DUPLICADOS_GRUPOS_POR_PAGINA, $offset);
	endif;
	$paginaNecesaria = $limite > 0 ? $offset + $limite : PHP_INT_MAX;
	$items = duplicadosObtenerLocalesIndexados($base);
	$filtroOrigen = 'todos';
	$total = count($items);
	if ($total < 2):
		return [];
	endif;

	$buckets = [];
	$incluirBucketsNombre = $total <= DUPLICADOS_BUCKET_NOMBRE_ITEMS_MAX;
	foreach ($items as $indice => $item):
		duplicadosBucketsItem($item, $indice, $buckets, $incluirBucketsNombre);
	endforeach;

	$descriptores = [];
	$exactos = [];
	$visuales = [];
	$primerosExactos = [];
	$primerosVisuales = [];
	$exactoPorIndice = [];
	$visualPorIndice = [];
	if ($filtroOrigen === 'mixto'):
		$exactosYandex = [];
		$visualesYandex = [];
		foreach ($items as $indice => $item):
			if ((string) ($item['origen'] ?? '') !== 'yandex'):
				continue;
			endif;
			$claveExacta = duplicadosClaveExactaItem($item);
			if ($claveExacta !== ''):
				$exactosYandex[$claveExacta][] = $indice;
			endif;
			$claveVisual = duplicadosClaveVisualItem($item);
			if ($claveVisual !== ''):
				$visualesYandex[$claveVisual][] = $indice;
			endif;
		endforeach;
		foreach ($items as $indice => $item):
			if ((string) ($item['origen'] ?? '') !== 'local'):
				continue;
			endif;
			$claveExacta = duplicadosClaveExactaItem($item);
			if ($claveExacta !== '' && isset($exactosYandex[$claveExacta])):
				if (!isset($exactos[$claveExacta])):
					$exactos[$claveExacta] = $exactosYandex[$claveExacta];
				endif;
				$exactos[$claveExacta][] = $indice;
			endif;
			$claveVisual = duplicadosClaveVisualItem($item);
			if ($claveVisual !== '' && isset($visualesYandex[$claveVisual])):
				if (!isset($visuales[$claveVisual])):
					$visuales[$claveVisual] = $visualesYandex[$claveVisual];
				endif;
				$visuales[$claveVisual][] = $indice;
			endif;
		endforeach;
		unset($exactosYandex, $visualesYandex);
	else:
		foreach ($items as $indice => $item):
			$claveExacta = duplicadosClaveExactaItem($item);
			if ($claveExacta !== ''):
				duplicadosRegistrarClaveGrupo($claveExacta, $indice, $primerosExactos, $exactos);
			endif;
			$claveVisual = duplicadosClaveVisualItem($item);
			if ($claveVisual !== ''):
				duplicadosRegistrarClaveGrupo($claveVisual, $indice, $primerosVisuales, $visuales);
			endif;
		endforeach;
	endif;
	unset($primerosExactos, $primerosVisuales);

	foreach ($exactos as $clave => $indices):
		$indices = array_values(array_unique($indices));
		if (count($indices) < 2):
			continue;
		endif;
		[$metodo, $hash] = explode('|', $clave, 2);
		foreach ($indices as $indice):
			$exactoPorIndice[$indice] = $clave;
		endforeach;
		$descriptores[] = duplicadosDescriptorDesdeIndices($items, $indices, 100, $metodo, $hash, [$metodo]);
	endforeach;
	if ($filtroOrigen === 'todos' && $limite > 0 && count($descriptores) >= $paginaNecesaria):
		return duplicadosPaginaDesdeDescriptores($items, $descriptores, $limite, $offset, $filtroOrigen);
	endif;

	foreach ($visuales as $clave => $indices):
		$indices = array_values(array_unique($indices));
		if (count($indices) < 2):
			continue;
		endif;
		[$metodo, $hash] = explode('|', $clave, 2);
		$clavesBinarias = array_values(array_unique(array_map(
			fn($indice) => (string) ($items[$indice]['sha256'] ?? '') . '|' . (string) ($items[$indice]['md5'] ?? ''),
			$indices
		)));
		if (count($clavesBinarias) < 2):
			continue;
		endif;
		foreach ($indices as $indice):
			$visualPorIndice[$indice] = $clave;
		endforeach;
		$descriptores[] = duplicadosDescriptorDesdeIndices($items, $indices, 95, $metodo, $hash, ['Pixeles idénticos sin metadatos']);
	endforeach;
	if ($filtroOrigen === 'todos' && $limite > 0 && count($descriptores) >= $paginaNecesaria):
		return duplicadosPaginaDesdeDescriptores($items, $descriptores, $limite, $offset, $filtroOrigen);
	endif;

	$probablesPorScore = [];
	$totalProbables = 0;
	$paresCandidatos = [];
	$ventanaBusqueda = $limite > 0 ? $offset + $limite : DUPLICADOS_CANDIDATOS_PROBABLES_MAX;
	$maxProbables = max(DUPLICADOS_CANDIDATOS_PROBABLES_MAX, $ventanaBusqueda * 10);
	foreach ($buckets as $claveBucket => $indices):
		if (!is_array($indices)):
			continue;
		endif;
		if (
			str_starts_with($claveBucket, 'sha256:')
			|| str_starts_with($claveBucket, 'md5:')
			|| str_starts_with($claveBucket, 'contenido_hash:')
		):
			continue;
		endif;
		$indices = array_values(array_unique($indices));
		$conteo = count($indices);
		if ($conteo < 2 || $conteo > DUPLICADOS_BUCKET_PROBABLE_MAX):
			continue;
		endif;
		for ($i = 0; $i < $conteo - 1; $i++):
			for ($j = $i + 1; $j < $conteo; $j++):
				$a = (int) $indices[$i];
				$b = (int) $indices[$j];
				if ($filtroOrigen === 'mixto' && (string) ($items[$a]['origen'] ?? '') === (string) ($items[$b]['origen'] ?? '')):
					continue;
				endif;
				$clavePar = duplicadosClavePar($a, $b);
				if (isset($paresCandidatos[$clavePar])):
					continue;
				endif;
				if (
					(isset($exactoPorIndice[$a], $exactoPorIndice[$b]) && $exactoPorIndice[$a] === $exactoPorIndice[$b])
					|| (isset($visualPorIndice[$a], $visualPorIndice[$b]) && $visualPorIndice[$a] === $visualPorIndice[$b])
				):
					continue;
				endif;

				$comparacion = duplicadosScoreItems($items[$a], $items[$b]);
				$score = (int) ($comparacion['score'] ?? 0);
				if ($score < DUPLICADOS_SCORE_MINIMO || $score >= 100):
					continue;
				endif;
				$agregado = duplicadosAgregarProbableCandidato(
					$probablesPorScore,
					duplicadosDescriptorDesdeIndices(
						$items,
						[$a, $b],
						$score,
						(string) ($comparacion['metodo'] ?? 'Score probable'),
						(string) ($comparacion['hash'] ?? ''),
						(array) ($comparacion['razones'] ?? [])
					),
					$maxProbables,
					$totalProbables
				);
				if ($agregado):
					$paresCandidatos[$clavePar] = true;
				endif;
			endfor;
		endfor;
	endforeach;

	krsort($probablesPorScore, SORT_NUMERIC);
	foreach ($probablesPorScore as $candidatos):
		foreach ($candidatos as $descriptor):
			$descriptores[] = $descriptor;
		endforeach;
	endforeach;

	duplicadosOrdenarDescriptores($descriptores);
	if ($filtroOrigen !== 'todos'):
		return duplicadosPaginaDesdeDescriptores($items, $descriptores, $limite, $offset, $filtroOrigen);
	endif;

	if ($limite > 0):
		$descriptores = array_slice($descriptores, $offset, $limite);
	elseif ($offset > 0):
		$descriptores = array_slice($descriptores, $offset);
	endif;

	return duplicadosGruposDesdeDescriptores($items, $descriptores);
}

function duplicadosConteoOrigenVacio(bool $pendiente = true): array
{
	return [
		'grupos' => 0,
		'mas' => false,
		'pendiente' => $pendiente,
	];
}

function duplicadosConteosOrigenVacios(bool $pendiente = true): array
{
	return [
		'local' => duplicadosConteoOrigenVacio($pendiente),
		'remoto' => duplicadosConteoOrigenVacio($pendiente),
		'mixto' => duplicadosConteoOrigenVacio($pendiente),
		'pendiente' => $pendiente,
		'actualizado' => 0,
	];
}

function duplicadosClaveConteosOrigen(string $base): string
{
	$base = duplicadosNormalizarRutaLocal($base);
	return sha1($base === '' ? '__todos__' : $base);
}

function duplicadosFirmaConteosOrigen(string $base, array $local, array $yandexResumen): string
{
	return sha1(json_encode([
		'v' => 2,
		'base' => duplicadosNormalizarRutaLocal($base),
		'local_indexados' => (int) ($local['indexados'] ?? 0),
		'local_actualizado' => (int) ($local['actualizado'] ?? 0),
		'local_stale' => (int) ($local['stale'] ?? 0),
		'yandex_total' => (int) ($yandexResumen['total'] ?? 0),
		'yandex_con_hash' => (int) ($yandexResumen['con_hash'] ?? 0),
		'yandex_actualizado' => (int) ($yandexResumen['actualizado'] ?? 0),
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function duplicadosLeerConteosOrigenCache(PDO $pdo, string $base, string $firma): ?array
{
	try {
		$stmt = $pdo->prepare('SELECT * FROM conteos_origen WHERE clave = :clave LIMIT 1');
		$stmt->execute([':clave' => duplicadosClaveConteosOrigen($base)]);
		$fila = $stmt->fetch(PDO::FETCH_ASSOC);
	} catch (PDOException $e) {
		return null;
	}
	if (!is_array($fila)):
		return null;
	endif;

	$pendiente = (string) ($fila['firma'] ?? '') !== $firma;
	return [
		'local' => [
			'grupos' => (int) ($fila['local'] ?? 0),
			'mas' => !empty($fila['local_mas']),
			'pendiente' => $pendiente,
		],
		'remoto' => [
			'grupos' => (int) ($fila['remoto'] ?? 0),
			'mas' => !empty($fila['remoto_mas']),
			'pendiente' => $pendiente,
		],
		'mixto' => [
			'grupos' => (int) ($fila['mixto'] ?? 0),
			'mas' => !empty($fila['mixto_mas']),
			'pendiente' => $pendiente,
		],
		'pendiente' => $pendiente,
		'actualizado' => (int) ($fila['actualizado'] ?? 0),
	];
}

function duplicadosGuardarConteosOrigenCache(PDO $pdo, string $base, string $firma, array $conteos): void
{
	try {
		$stmt = $pdo->prepare("
			INSERT INTO conteos_origen (
				clave, base, firma, local, local_mas, remoto, remoto_mas, mixto, mixto_mas, actualizado
			) VALUES (
				:clave, :base, :firma, :local, :local_mas, :remoto, :remoto_mas, :mixto, :mixto_mas, :actualizado
			)
			ON CONFLICT(clave) DO UPDATE SET
				base = excluded.base,
				firma = excluded.firma,
				local = excluded.local,
				local_mas = excluded.local_mas,
				remoto = excluded.remoto,
				remoto_mas = excluded.remoto_mas,
				mixto = excluded.mixto,
				mixto_mas = excluded.mixto_mas,
				actualizado = excluded.actualizado
		");
		$stmt->execute([
			':clave' => duplicadosClaveConteosOrigen($base),
			':base' => duplicadosNormalizarRutaLocal($base),
			':firma' => $firma,
			':local' => (int) ($conteos['local']['grupos'] ?? 0),
			':local_mas' => !empty($conteos['local']['mas']) ? 1 : 0,
			':remoto' => (int) ($conteos['remoto']['grupos'] ?? 0),
			':remoto_mas' => !empty($conteos['remoto']['mas']) ? 1 : 0,
			':mixto' => (int) ($conteos['mixto']['grupos'] ?? 0),
			':mixto_mas' => !empty($conteos['mixto']['mas']) ? 1 : 0,
			':actualizado' => time(),
		]);
	} catch (PDOException $e) {
		return;
	}
}

function duplicadosAjustarConteosOrigenCache(?string $base, array $ajustes): array
{
	$pdo = conectarBaseDuplicados();
	if (!$pdo):
		return duplicadosConteosOrigenVacios(true);
	endif;

	$base = duplicadosNormalizarRutaLocal((string) $base);
	try {
		$stmt = $pdo->prepare('SELECT * FROM conteos_origen WHERE clave = :clave LIMIT 1');
		$stmt->execute([':clave' => duplicadosClaveConteosOrigen($base)]);
		$fila = $stmt->fetch(PDO::FETCH_ASSOC);
	} catch (PDOException $e) {
		return duplicadosConteosOrigenVacios(true);
	}
	if (!is_array($fila)):
		return duplicadosConteosOrigen($base, false);
	endif;

	$conteos = [
		'local' => [
			'grupos' => (int) ($fila['local'] ?? 0),
			'mas' => !empty($fila['local_mas']),
			'pendiente' => false,
		],
		'remoto' => [
			'grupos' => (int) ($fila['remoto'] ?? 0),
			'mas' => !empty($fila['remoto_mas']),
			'pendiente' => false,
		],
		'mixto' => [
			'grupos' => (int) ($fila['mixto'] ?? 0),
			'mas' => !empty($fila['mixto_mas']),
			'pendiente' => false,
		],
		'pendiente' => false,
		'actualizado' => time(),
	];

	foreach (['local', 'remoto', 'mixto'] as $origen):
		$delta = (int) ($ajustes[$origen] ?? 0);
		if ($delta === 0):
			continue;
		endif;
		$conteos[$origen]['grupos'] = max(0, (int) ($conteos[$origen]['grupos'] ?? 0) + $delta);
	endforeach;

	$local = duplicadosResumenLocal($base);
	$yandexResumen = duplicadosYandexCatalogoResumen();
	$firma = duplicadosFirmaConteosOrigen($base, $local, $yandexResumen);
	duplicadosGuardarConteosOrigenCache($pdo, $base, $firma, $conteos);

	return $conteos;
}

function duplicadosConteoAgregarGrupo(int &$total, bool &$mas, int $maximo): bool
{
	if ($total >= $maximo):
		$mas = true;
		return false;
	endif;
	$total++;
	return true;
}

function duplicadosContarGruposEnItems(array $items, string $filtroOrigen, int $maximo = DUPLICADOS_CONTEO_GRUPOS_MAX): array
{
	$totalItems = count($items);
	if ($totalItems < 2):
		return duplicadosConteoOrigenVacio(false);
	endif;

	$total = 0;
	$mas = false;
	$buckets = [];
	$incluirBucketsNombre = $totalItems <= DUPLICADOS_BUCKET_NOMBRE_ITEMS_MAX;
	foreach ($items as $indice => $item):
		duplicadosBucketsItem($item, $indice, $buckets, $incluirBucketsNombre);
	endforeach;

	$exactos = [];
	$visuales = [];
	$primerosExactos = [];
	$primerosVisuales = [];
	$exactoPorIndice = [];
	$visualPorIndice = [];
	foreach ($items as $indice => $item):
		$claveExacta = duplicadosClaveExactaItem($item);
		if ($claveExacta !== ''):
			duplicadosRegistrarClaveGrupo($claveExacta, $indice, $primerosExactos, $exactos);
		endif;
		$claveVisual = duplicadosClaveVisualItem($item);
		if ($claveVisual !== ''):
			duplicadosRegistrarClaveGrupo($claveVisual, $indice, $primerosVisuales, $visuales);
		endif;
	endforeach;
	unset($primerosExactos, $primerosVisuales);

	foreach ($exactos as $clave => $indices):
		$indices = array_values(array_unique($indices));
		if (count($indices) < 2):
			continue;
		endif;
		foreach ($indices as $indice):
			$exactoPorIndice[$indice] = $clave;
		endforeach;
		if (!duplicadosConteoAgregarGrupo($total, $mas, $maximo)):
			return ['grupos' => $total, 'mas' => $mas, 'pendiente' => false];
		endif;
	endforeach;

	foreach ($visuales as $clave => $indices):
		$indices = array_values(array_unique($indices));
		if (count($indices) < 2):
			continue;
		endif;
		$clavesBinarias = array_values(array_unique(array_map(
			fn($indice) => (string) ($items[$indice]['sha256'] ?? '') . '|' . (string) ($items[$indice]['md5'] ?? ''),
			$indices
		)));
		if (count($clavesBinarias) < 2):
			continue;
		endif;
		foreach ($indices as $indice):
			$visualPorIndice[$indice] = $clave;
		endforeach;
		if (!duplicadosConteoAgregarGrupo($total, $mas, $maximo)):
			return ['grupos' => $total, 'mas' => $mas, 'pendiente' => false];
		endif;
	endforeach;

	$totalProbables = 0;
	$maxProbables = max(DUPLICADOS_CANDIDATOS_PROBABLES_MAX, DUPLICADOS_CANDIDATOS_PROBABLES_MAX * 10);
	$paresCandidatos = [];
	foreach ($buckets as $claveBucket => $indices):
		if (!is_array($indices)):
			continue;
		endif;
		if (
			str_starts_with($claveBucket, 'sha256:')
			|| str_starts_with($claveBucket, 'md5:')
			|| str_starts_with($claveBucket, 'contenido_hash:')
		):
			continue;
		endif;
		$indices = array_values(array_unique($indices));
		$conteo = count($indices);
		if ($conteo < 2 || $conteo > DUPLICADOS_BUCKET_PROBABLE_MAX):
			continue;
		endif;
		for ($i = 0; $i < $conteo - 1; $i++):
			for ($j = $i + 1; $j < $conteo; $j++):
				$a = (int) $indices[$i];
				$b = (int) $indices[$j];
				if ($filtroOrigen === 'mixto' && (string) ($items[$a]['origen'] ?? '') === (string) ($items[$b]['origen'] ?? '')):
					continue;
				endif;
				$clavePar = duplicadosClavePar($a, $b);
				if (isset($paresCandidatos[$clavePar])):
					continue;
				endif;
				if (
					(isset($exactoPorIndice[$a], $exactoPorIndice[$b]) && $exactoPorIndice[$a] === $exactoPorIndice[$b])
					|| (isset($visualPorIndice[$a], $visualPorIndice[$b]) && $visualPorIndice[$a] === $visualPorIndice[$b])
				):
					continue;
				endif;

				$comparacion = duplicadosScoreItems($items[$a], $items[$b]);
				$score = (int) ($comparacion['score'] ?? 0);
				if ($score < DUPLICADOS_SCORE_MINIMO || $score >= 100):
					continue;
				endif;
				$totalProbables++;
				$paresCandidatos[$clavePar] = true;
				if ($totalProbables >= $maxProbables):
					$total += $totalProbables;
					if ($total > $maximo):
						$total = $maximo;
						$mas = true;
					endif;
					return ['grupos' => $total, 'mas' => $mas, 'pendiente' => false];
				endif;
			endfor;
		endfor;
	endforeach;

	$total += $totalProbables;
	if ($total > $maximo):
		$total = $maximo;
		$mas = true;
	endif;

	return ['grupos' => $total, 'mas' => $mas, 'pendiente' => false];
}

function duplicadosContarGruposOrigen(?string $base, string $filtroOrigen): array
{
	$filtroOrigen = duplicadosNormalizarFiltroOrigen($filtroOrigen);
	if ($filtroOrigen === 'mixto'):
		return duplicadosContarGruposMixtosExactos($base);
	endif;
	if ($filtroOrigen === 'remoto'):
		return duplicadosContarGruposRemotosExactos();
	endif;

	return duplicadosContarGruposEnItems(duplicadosObtenerLocalesIndexados($base), 'local');
}

function duplicadosContarGruposMixtosExactos(?string $base): array
{
	$grupos = duplicadosConstruirGruposMixtosExactos($base, DUPLICADOS_CONTEO_GRUPOS_MAX + 1, 0);
	$total = count($grupos);
	return [
		'grupos' => min(DUPLICADOS_CONTEO_GRUPOS_MAX, $total),
		'mas' => $total > DUPLICADOS_CONTEO_GRUPOS_MAX,
		'pendiente' => false,
	];
}

function duplicadosConteosOrigen(?string $base, bool $forzar = false, ?array $local = null, ?array $yandexResumen = null): array
{
	$pdo = conectarBaseDuplicados();
	if (!$pdo):
		return duplicadosConteosOrigenVacios(true);
	endif;

	$base = duplicadosNormalizarRutaLocal((string) $base);
	$local ??= duplicadosResumenLocal($base);
	$yandexResumen ??= duplicadosYandexCatalogoResumen();
	$firma = duplicadosFirmaConteosOrigen($base, $local, $yandexResumen);

	if (!$forzar):
		$cache = duplicadosLeerConteosOrigenCache($pdo, $base, $firma);
		if ($cache !== null):
			return $cache;
		endif;
		return duplicadosConteosOrigenVacios(true);
	endif;

	$conteos = [
		'local' => duplicadosContarGruposOrigen($base, 'local'),
		'remoto' => duplicadosContarGruposOrigen($base, 'remoto'),
		'mixto' => duplicadosContarGruposOrigen($base, 'mixto'),
		'pendiente' => false,
		'actualizado' => time(),
	];
	duplicadosGuardarConteosOrigenCache($pdo, $base, $firma, $conteos);

	return $conteos;
}

function duplicadosResumenGrupos(array $grupos): array
{
	$resumen = [
		'grupos' => count($grupos),
		'entradas' => 0,
		'cruzados' => 0,
		'exactos' => 0,
		'probables' => 0,
		'locales' => 0,
		'yandex' => 0,
		'remotos' => 0,
		'mixtos' => 0,
	];
	foreach ($grupos as $grupo):
		$resumen['entradas'] += (int) ($grupo['conteo'] ?? count($grupo['items'] ?? []));
		$origenTipo = (string) ($grupo['origen_tipo'] ?? '');
		if ($origenTipo === ''):
			$origenTipo = duplicadosTipoOrigenGrupoDesdeItems(is_array($grupo['items'] ?? null) ? $grupo['items'] : []);
		endif;
		if ($origenTipo === 'mixto'):
			$resumen['cruzados']++;
			$resumen['mixtos']++;
		elseif ($origenTipo === 'remoto'):
			$resumen['remotos']++;
		endif;
		if ((int) ($grupo['score'] ?? 0) >= 100):
			$resumen['exactos']++;
		else:
			$resumen['probables']++;
		endif;
		foreach (($grupo['items'] ?? []) as $item):
			if (($item['origen'] ?? '') === 'local'):
				$resumen['locales']++;
			elseif (($item['origen'] ?? '') === 'yandex'):
				$resumen['yandex']++;
			endif;
		endforeach;
	endforeach;

	return $resumen;
}

function duplicadosResumenVacio(bool $pendiente = false): array
{
	return [
		'grupos' => 0,
		'entradas' => 0,
		'cruzados' => 0,
		'exactos' => 0,
		'probables' => 0,
		'locales' => 0,
		'yandex' => 0,
		'remotos' => 0,
		'mixtos' => 0,
		'pendiente' => $pendiente,
	];
}

function duplicadosLeerJson(string $archivo): ?array
{
	if (!is_file($archivo)):
		return null;
	endif;
	$datos = json_decode((string) file_get_contents($archivo), true);
	return is_array($datos) ? $datos : null;
}

function duplicadosGuardarJson(string $archivo, array $datos): bool
{
	if (!duplicadosPrepararDatos()):
		return false;
	endif;
	$tmp = $archivo . '.tmp';
	$json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if (!is_string($json)):
		return false;
	endif;
	if (file_put_contents($tmp, $json, LOCK_EX) === false):
		return false;
	endif;

	return rename($tmp, $archivo);
}

function duplicadosLeerTrabajo(string $id): ?array
{
	$id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
	if ($id === ''):
		return null;
	endif;

	$trabajo = duplicadosLeerJson(duplicadosRutaTrabajo($id));
	if (!is_array($trabajo) || (int) ($trabajo['version'] ?? 0) !== DUPLICADOS_JOB_VERSION):
		return null;
	endif;

	return $trabajo;
}

function duplicadosLeerTrabajoActual(): ?array
{
	$actual = duplicadosLeerJson(duplicadosRutaTrabajoActual());
	$id = is_array($actual) ? (string) ($actual['id'] ?? '') : '';
	if ($id === ''):
		return null;
	endif;

	return duplicadosLeerTrabajo($id);
}

function duplicadosTrabajoActivo(?array $trabajo): bool
{
	if (!is_array($trabajo)):
		return false;
	endif;
	return in_array((string) ($trabajo['estado'] ?? ''), ['queued', 'scanning', 'hashing', 'cancelando'], true);
}

function duplicadosGuardarTrabajo(array $trabajo): bool
{
	$trabajo['updated_at'] = time();
	if (empty($trabajo['id'])):
		return false;
	endif;

	return duplicadosGuardarJson(duplicadosRutaTrabajo((string) $trabajo['id']), $trabajo);
}

function duplicadosActualizarTrabajo(string $id, array $cambios): ?array
{
	$trabajo = duplicadosLeerTrabajo($id);
	if ($trabajo === null):
		return null;
	endif;

	$trabajo = array_replace($trabajo, $cambios);
	duplicadosGuardarTrabajo($trabajo);
	return $trabajo;
}

function duplicadosRutaPhpCli(): string
{
	$candidatos = [];
	if (defined('PHP_BINARY')):
		$candidatos[] = PHP_BINARY;
	endif;
	if (defined('PHP_BINDIR')):
		$candidatos[] = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
	endif;
	$candidatos = array_merge($candidatos, [
		'/opt/homebrew/bin/php',
		'/usr/local/bin/php',
		'/usr/bin/php',
	]);

	foreach ($candidatos as $candidato):
		if (is_string($candidato) && is_executable($candidato) && preg_match('/php(?:\d+(?:\.\d+)?)?$/', basename($candidato))):
			return $candidato;
		endif;
	endforeach;

	return '';
}

function duplicadosLanzarWorker(string $id): array
{
	if (!function_exists('exec')):
		return ['ok' => false, 'error' => 'exec() no está disponible para lanzar el proceso de hashes.'];
	endif;

	$php = duplicadosRutaPhpCli();
	if ($php === ''):
		return ['ok' => false, 'error' => 'No se encontró un ejecutable PHP CLI.'];
	endif;

	$script = proyectoRaiz() . DIRECTORY_SEPARATOR . 'duplicados_worker.php';
	if (!is_file($script)):
		return ['ok' => false, 'error' => 'No se encontró duplicados_worker.php.'];
	endif;

	$cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($id);
	$log = escapeshellarg(duplicadosRutaLogWorker());
	@exec($cmd . ' > ' . $log . ' 2>&1 &');
	return ['ok' => true, 'error' => ''];
}

function duplicadosIniciarTrabajo(string $base, bool $forzar = false): array
{
	$activo = duplicadosLeerTrabajoActual();
	if (duplicadosTrabajoActivo($activo)):
		return ['ok' => true, 'job' => $activo, 'error' => ''];
	endif;

	$id = bin2hex(random_bytes(8));
	$base = duplicadosNormalizarRutaLocal($base);
	$trabajo = [
		'version' => DUPLICADOS_JOB_VERSION,
		'id' => $id,
		'estado' => 'queued',
		'base' => $base,
		'base_rel' => rutaRelativaParaParametro($base),
		'created_at' => time(),
		'updated_at' => time(),
		'started_at' => 0,
		'finished_at' => 0,
		'total' => 0,
		'procesados' => 0,
		'actualizados' => 0,
		'omitidos' => 0,
		'errores' => 0,
		'archivo_actual' => '',
		'mensaje' => 'Preparando trabajo de firmas...',
		'cancel_requested' => false,
		'forzar' => $forzar,
	];
	if (!duplicadosGuardarTrabajo($trabajo) || !duplicadosGuardarJson(duplicadosRutaTrabajoActual(), ['id' => $id])):
		return ['ok' => false, 'job' => $trabajo, 'error' => 'No se pudo guardar el estado del trabajo.'];
	endif;

	$lanzado = duplicadosLanzarWorker($id);
	if (!$lanzado['ok']):
		$trabajo = duplicadosActualizarTrabajo($id, [
			'estado' => 'error',
			'finished_at' => time(),
			'mensaje' => $lanzado['error'],
		]) ?? $trabajo;
	endif;

	return ['ok' => (bool) $lanzado['ok'], 'job' => $trabajo, 'error' => (string) ($lanzado['error'] ?? '')];
}

function duplicadosCancelarTrabajo(): array
{
	$trabajo = duplicadosLeerTrabajoActual();
	if (!is_array($trabajo)):
		return ['ok' => true, 'job' => null];
	endif;

	$id = (string) ($trabajo['id'] ?? '');
	$trabajo = duplicadosActualizarTrabajo($id, [
		'estado' => duplicadosTrabajoActivo($trabajo) ? 'cancelando' : (string) ($trabajo['estado'] ?? 'cancelado'),
		'cancel_requested' => true,
		'mensaje' => 'Cancelando trabajo...',
	]) ?? $trabajo;

	return ['ok' => true, 'job' => $trabajo];
}

function duplicadosEjecutarTrabajo(string $id): void
{
	$id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
	if ($id === ''):
		return;
	endif;
	set_time_limit(0);

	if (!duplicadosPrepararDatos()):
		return;
	endif;

	$lock = fopen(duplicadosRutaLockTrabajo($id), 'c');
	if ($lock === false):
		return;
	endif;
	if (!flock($lock, LOCK_EX | LOCK_NB)):
		fclose($lock);
		return;
	endif;

	$trabajo = duplicadosLeerTrabajo($id);
	if ($trabajo === null):
		flock($lock, LOCK_UN);
		fclose($lock);
		return;
	endif;

	$base = (string) ($trabajo['base'] ?? '');
	if ($base === '' || !is_dir($base)):
		duplicadosActualizarTrabajo($id, [
			'estado' => 'error',
			'finished_at' => time(),
			'mensaje' => 'La ruta local ya no está disponible.',
		]);
		flock($lock, LOCK_UN);
		fclose($lock);
		return;
	endif;

	duplicadosActualizarTrabajo($id, [
		'estado' => 'scanning',
		'started_at' => time(),
		'mensaje' => 'Buscando archivos locales...',
	]);

	try {
		$resultados = obtenerResultadosMultimediaEscaneo($base, null, carpetasIgnoradasConfiguracion(), null);
	} catch (Throwable $e) {
		duplicadosActualizarTrabajo($id, [
			'estado' => 'error',
			'finished_at' => time(),
			'mensaje' => 'No se pudo recorrer la ruta local: ' . $e->getMessage(),
		]);
		flock($lock, LOCK_UN);
		fclose($lock);
		return;
	}

	$total = count($resultados);
	$procesados = 0;
	$actualizados = 0;
	$omitidos = 0;
	$errores = 0;
	$forzar = !empty($trabajo['forzar']);
	$pdo = conectarBaseDuplicados();
	if (!$pdo):
		duplicadosActualizarTrabajo($id, [
			'estado' => 'error',
			'finished_at' => time(),
			'mensaje' => 'No se pudo abrir el índice local de firmas.',
		]);
		flock($lock, LOCK_UN);
		fclose($lock);
		return;
	endif;

	duplicadosActualizarTrabajo($id, [
		'estado' => 'hashing',
		'total' => $total,
		'mensaje' => 'Calculando firmas locales...',
	]);

	foreach ($resultados as $resultado):
		$trabajoActual = duplicadosLeerTrabajo($id);
		if (!empty($trabajoActual['cancel_requested'])):
			duplicadosActualizarTrabajo($id, [
				'estado' => 'cancelado',
				'finished_at' => time(),
				'procesados' => $procesados,
				'actualizados' => $actualizados,
				'omitidos' => $omitidos,
				'errores' => $errores,
				'mensaje' => 'Trabajo cancelado.',
			]);
			flock($lock, LOCK_UN);
			fclose($lock);
			return;
		endif;

		$ruta = (string) ($resultado[0] ?? '');
		$tipo = (string) ($resultado[1] ?? '');
		if ($ruta === '' || !is_file($ruta)):
			$procesados++;
			$errores++;
			continue;
		endif;
		if (!in_array($tipo, ['img', 'vid'], true)):
			$tipo = tipoMultimediaDesdeRuta($ruta) ?? '';
		endif;
		if ($tipo === ''):
			$procesados++;
			$omitidos++;
			continue;
		endif;

		$rutaNormalizada = duplicadosNormalizarRutaLocal($ruta);
		$vigente = $forzar ? null : duplicadosHashLocalVigente($pdo, $rutaNormalizada);
			if ($vigente !== null):
				$procesados++;
				$omitidos++;
			else:
				$intacto = $forzar ? null : duplicadosHashLocalIntacto($pdo, $rutaNormalizada);
				$hashes = $intacto !== null
					? duplicadosHashesDesdeFilaLocal($intacto)
					: duplicadosCalcularHashesArchivo($rutaNormalizada);
				if (!($hashes['ok'] ?? false)):
					$errores++;
				else:
					$hashes = array_replace($hashes, duplicadosCalcularFirmaArchivo($rutaNormalizada, $tipo));
				$actualizados++;
			endif;
			duplicadosGuardarHashLocal($pdo, $rutaNormalizada, $tipo, $hashes);
			$procesados++;
		endif;

		if ($procesados === $total || $procesados % 5 === 0):
			duplicadosActualizarTrabajo($id, [
				'estado' => 'hashing',
				'total' => $total,
				'procesados' => $procesados,
				'actualizados' => $actualizados,
				'omitidos' => $omitidos,
				'errores' => $errores,
				'archivo_actual' => rutaRelativaParaParametro($rutaNormalizada),
				'mensaje' => 'Calculando firmas locales: ' . $procesados . ' de ' . $total,
			]);
		endif;
	endforeach;

	duplicadosActualizarTrabajo($id, [
		'estado' => 'completado',
		'finished_at' => time(),
		'total' => $total,
		'procesados' => $procesados,
		'actualizados' => $actualizados,
		'omitidos' => $omitidos,
		'errores' => $errores,
		'archivo_actual' => '',
		'mensaje' => 'Índice de firmas locales actualizado.',
	]);

	flock($lock, LOCK_UN);
	fclose($lock);
}

/**
 * @deprecated El nombre se conserva por compatibilidad; la firma se calcula desde SQLite.
 */
function duplicadosYandexFirmaArchivosCache(int &$actualizado): string
{
	$resumen = duplicadosYandexCatalogoResumen();
	$actualizado = (int) ($resumen['actualizado'] ?? 0);
	return sha1(implode('|', [
		(int) ($resumen['total'] ?? 0),
		(int) ($resumen['con_hash'] ?? 0),
		(int) ($resumen['md5'] ?? 0),
		(int) ($resumen['sha256'] ?? 0),
		$actualizado,
	]));
}

/**
 * @deprecated El nombre se conserva por compatibilidad; el resumen se calcula desde SQLite.
 */
function duplicadosYandexResumenCache(bool $permitirRecalcular = true): array
{
	unset($permitirRecalcular);
	return duplicadosYandexCatalogoResumen();
}

function duplicadosYandexLeerTrabajo(string $id): ?array
{
	$id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
	if ($id === ''):
		return null;
	endif;

	$trabajo = duplicadosLeerJson(duplicadosYandexRutaTrabajo($id));
	if (!is_array($trabajo) || (int) ($trabajo['version'] ?? 0) !== DUPLICADOS_YANDEX_JOB_VERSION):
		return null;
	endif;

	return $trabajo;
}

function duplicadosYandexLeerTrabajoActual(): ?array
{
	$actual = duplicadosLeerJson(duplicadosYandexRutaTrabajoActual());
	$id = is_array($actual) ? (string) ($actual['id'] ?? '') : '';
	if ($id === ''):
		return null;
	endif;

	return duplicadosYandexLeerTrabajo($id);
}

function duplicadosYandexTrabajoActivo(?array $trabajo): bool
{
	if (!is_array($trabajo)):
		return false;
	endif;
	return in_array((string) ($trabajo['estado'] ?? ''), ['queued', 'preparando', 'consultando', 'cancelando'], true);
}

function duplicadosYandexGuardarTrabajo(array $trabajo): bool
{
	$trabajo['updated_at'] = time();
	if (empty($trabajo['id'])):
		return false;
	endif;

	return duplicadosGuardarJson(duplicadosYandexRutaTrabajo((string) $trabajo['id']), $trabajo);
}

function duplicadosYandexActualizarTrabajo(string $id, array $cambios): ?array
{
	$trabajo = duplicadosYandexLeerTrabajo($id);
	if ($trabajo === null):
		return null;
	endif;

	$trabajo = array_replace($trabajo, $cambios);
	duplicadosYandexGuardarTrabajo($trabajo);
	return $trabajo;
}

function duplicadosYandexLanzarWorker(string $id): array
{
	if (!function_exists('exec')):
		return ['ok' => false, 'error' => 'exec() no está disponible para lanzar el proceso de hashes de Yandex.'];
	endif;

	$php = duplicadosRutaPhpCli();
	if ($php === ''):
		return ['ok' => false, 'error' => 'No se encontró un ejecutable PHP CLI.'];
	endif;

	$script = proyectoRaiz() . DIRECTORY_SEPARATOR . 'duplicados_yandex_worker.php';
	if (!is_file($script)):
		return ['ok' => false, 'error' => 'No se encontró duplicados_yandex_worker.php.'];
	endif;

	$cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($id);
	$log = escapeshellarg(duplicadosYandexRutaLogWorker());
	@exec($cmd . ' > ' . $log . ' 2>&1 &');
	return ['ok' => true, 'error' => ''];
}

function duplicadosYandexIniciarTrabajo(bool $forzar = false): array
{
	$activo = duplicadosYandexLeerTrabajoActual();
	if (duplicadosYandexTrabajoActivo($activo)):
		return ['ok' => true, 'job' => $activo, 'error' => ''];
	endif;

	$id = bin2hex(random_bytes(8));
	$trabajo = [
		'version' => DUPLICADOS_YANDEX_JOB_VERSION,
		'id' => $id,
		'estado' => 'queued',
		'created_at' => time(),
		'updated_at' => time(),
		'started_at' => 0,
		'finished_at' => 0,
		'total' => 0,
		'procesados' => 0,
		'actualizados' => 0,
		'omitidos' => 0,
		'errores' => 0,
		'pendientes_restantes' => 0,
		'archivo_actual' => '',
		'mensaje' => 'Preparando consulta de hashes de Yandex...',
		'cancel_requested' => false,
		'forzar' => $forzar,
		'delay_ms' => intdiv(DUPLICADOS_YANDEX_HASH_DELAY_US, 1000),
		'limite_por_tanda' => DUPLICADOS_YANDEX_HASH_MAX_POR_TRABAJO,
	];
	if (!duplicadosYandexGuardarTrabajo($trabajo) || !duplicadosGuardarJson(duplicadosYandexRutaTrabajoActual(), ['id' => $id])):
		return ['ok' => false, 'job' => $trabajo, 'error' => 'No se pudo guardar el estado del trabajo de Yandex.'];
	endif;

	$lanzado = duplicadosYandexLanzarWorker($id);
	if (!$lanzado['ok']):
		$trabajo = duplicadosYandexActualizarTrabajo($id, [
			'estado' => 'error',
			'finished_at' => time(),
			'mensaje' => $lanzado['error'],
		]) ?? $trabajo;
	endif;

	return ['ok' => (bool) $lanzado['ok'], 'job' => $trabajo, 'error' => (string) ($lanzado['error'] ?? '')];
}

function duplicadosYandexCancelarTrabajo(): array
{
	$trabajo = duplicadosYandexLeerTrabajoActual();
	if (!is_array($trabajo)):
		return ['ok' => true, 'job' => null];
	endif;

	$id = (string) ($trabajo['id'] ?? '');
	$trabajo = duplicadosYandexActualizarTrabajo($id, [
		'estado' => duplicadosYandexTrabajoActivo($trabajo) ? 'cancelando' : (string) ($trabajo['estado'] ?? 'cancelado'),
		'cancel_requested' => true,
		'mensaje' => 'Cancelando consulta de Yandex...',
	]) ?? $trabajo;

	return ['ok' => true, 'job' => $trabajo];
}

function duplicadosYandexDormirConCadencia(): void
{
	$jitter = DUPLICADOS_YANDEX_HASH_DELAY_JITTER_US > 0
		? random_int(0, DUPLICADOS_YANDEX_HASH_DELAY_JITTER_US)
		: 0;
	usleep(DUPLICADOS_YANDEX_HASH_DELAY_US + $jitter);
}

function duplicadosYandexDebePausar(array $respuesta, int $erroresConsecutivos): bool
{
	$status = (int) ($respuesta['status'] ?? 0);
	if (in_array($status, [429, 503, 504], true)):
		return true;
	endif;

	$error = mb_strtolower((string) ($respuesta['error'] ?? ''), 'UTF-8');
	if ($error !== '' && (str_contains($error, 'tardó') || str_contains($error, 'timeout') || str_contains($error, 'timed out') || str_contains($error, 'too many'))):
		return true;
	endif;

	return $erroresConsecutivos >= DUPLICADOS_YANDEX_HASH_ERRORES_CONSECUTIVOS_MAX;
}

function duplicadosYandexRegistrarRevision(array $candidato, ?array $recurso, int $status, string $error, int $intentos): bool
{
	unset($status, $error, $intentos);
	$ruta = normalizarRutaYandexDisk((string) ($candidato['ruta'] ?? ''));
	if ($ruta === '/'):
		return false;
	endif;

	$pdo = function_exists('conectarCatalogoMultimedia') ? conectarCatalogoMultimedia() : null;
	if (!$pdo):
		return false;
	endif;

	if ($recurso !== null):
		$entrada = yandexDiskExtraerEntradaIndice($recurso, false);
		if ($entrada !== null):
			$entrada['ruta'] = normalizarRutaYandexDisk((string) ($entrada['ruta'] ?? $ruta));
			$guardado = function_exists('catalogoGuardarYandex') && catalogoGuardarYandex($pdo, $entrada, time());
			duplicadosYandexMarcarRevisionCatalogo($ruta);
			return $guardado;
		endif;
	endif;

	duplicadosYandexMarcarRevisionCatalogo($ruta);
	return true;
}

function duplicadosYandexEjecutarTrabajo(string $id): void
{
	$id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
	if ($id === ''):
		return;
	endif;
	set_time_limit(0);

	if (!duplicadosPrepararDatos()):
		return;
	endif;

	$lock = fopen(duplicadosYandexRutaLockTrabajo($id), 'c');
	if ($lock === false):
		return;
	endif;
	if (!flock($lock, LOCK_EX | LOCK_NB)):
		fclose($lock);
		return;
	endif;

	try {
		$trabajo = duplicadosYandexLeerTrabajo($id);
		if ($trabajo === null):
			return;
		endif;

		$configuracion = cargarConfiguracion();
		if (yandexDiskApiKeyConfiguracion($configuracion) === ''):
			duplicadosYandexActualizarTrabajo($id, [
				'estado' => 'error',
				'finished_at' => time(),
				'mensaje' => 'No hay API Key de Yandex.Disk configurada.',
			]);
			return;
		endif;

		duplicadosYandexActualizarTrabajo($id, [
			'estado' => 'preparando',
			'started_at' => time(),
			'mensaje' => 'Consultando catálogo SQLite de Yandex...',
		]);

		$forzar = !empty($trabajo['forzar']);
		$pendientes = duplicadosYandexCatalogoCandidatosHash($forzar, DUPLICADOS_YANDEX_HASH_MAX_POR_TRABAJO);
		$totalPendientes = (int) ($pendientes['total'] ?? 0);
		$candidatos = is_array($pendientes['items'] ?? null) ? $pendientes['items'] : [];
		$limite = count($candidatos);

		if ($limite <= 0):
			duplicadosYandexActualizarTrabajo($id, [
				'estado' => 'completado',
				'finished_at' => time(),
				'total' => 0,
				'procesados' => 0,
				'pendientes_restantes' => 0,
				'mensaje' => 'No hay hashes de Yandex pendientes por revisar.',
			]);
			return;
		endif;

		duplicadosYandexActualizarTrabajo($id, [
			'estado' => 'consultando',
			'total' => $limite,
			'pendientes_restantes' => max(0, $totalPendientes - $limite),
			'mensaje' => 'Consultando hashes de Yandex con cadencia baja...',
		]);

		$procesados = 0;
		$actualizados = 0;
		$omitidos = 0;
		$errores = 0;
		$erroresConsecutivos = 0;

		foreach ($candidatos as $candidato):
			$trabajoActual = duplicadosYandexLeerTrabajo($id);
			if (!empty($trabajoActual['cancel_requested'])):
				duplicadosYandexActualizarTrabajo($id, [
					'estado' => 'cancelado',
					'finished_at' => time(),
					'procesados' => $procesados,
					'actualizados' => $actualizados,
					'omitidos' => $omitidos,
					'errores' => $errores,
					'mensaje' => 'Consulta de Yandex cancelada.',
				]);
				return;
			endif;

			$ruta = normalizarRutaYandexDisk((string) ($candidato['ruta'] ?? ''));
			if ($ruta === '/'):
				$procesados++;
				$omitidos++;
				continue;
			endif;
			$nombre = (string) ($candidato['nombre'] ?? basename($ruta));
			duplicadosYandexActualizarTrabajo($id, [
				'estado' => 'consultando',
				'total' => $limite,
				'procesados' => $procesados,
				'actualizados' => $actualizados,
				'omitidos' => $omitidos,
				'errores' => $errores,
				'archivo_actual' => $ruta,
				'mensaje' => 'Yandex: consultando ' . ($procesados + 1) . ' de ' . $limite . ' · ' . $nombre,
			]);

			$intentos = (int) ($candidato['hash_intentos'] ?? 0) + 1;
			$respuesta = obtenerRecursoYandexDisk($configuracion, $ruta);
			$status = (int) ($respuesta['status'] ?? 0);
			if (!empty($respuesta['ok']) && is_array($respuesta['recurso'] ?? null)):
				$recurso = $respuesta['recurso'];
				$error = '';
				duplicadosYandexRegistrarRevision($candidato, $recurso, $status ?: 200, $error, $intentos);
				$tieneHash = trim((string) ($recurso['md5'] ?? '')) !== '' || trim((string) ($recurso['sha256'] ?? '')) !== '';
				if ($tieneHash):
					$actualizados++;
				else:
					$omitidos++;
				endif;
				$erroresConsecutivos = 0;
				else:
					$error = (string) ($respuesta['error'] ?? 'No se pudo consultar el recurso en Yandex.');
					if ($status === 404):
						if (function_exists('catalogoMarcarYandexAusente')):
							catalogoMarcarYandexAusente($ruta);
						endif;
						$omitidos++;
						$erroresConsecutivos = 0;
					else:
						duplicadosYandexRegistrarRevision($candidato, null, $status, $error, $intentos);
						$errores++;
						$erroresConsecutivos++;
					endif;
					if (duplicadosYandexDebePausar($respuesta, $erroresConsecutivos)):
						$procesados++;
						duplicadosYandexActualizarTrabajo($id, [
						'estado' => 'pausado',
						'finished_at' => time(),
						'total' => $limite,
						'procesados' => $procesados,
						'actualizados' => $actualizados,
						'omitidos' => $omitidos,
						'errores' => $errores,
						'archivo_actual' => '',
						'pendientes_restantes' => max(0, $totalPendientes - $procesados),
						'mensaje' => 'Yandex respondió lento o con errores; se pausó la tanda para no insistir.',
					]);
					return;
				endif;
			endif;

			$procesados++;
			duplicadosYandexActualizarTrabajo($id, [
				'estado' => 'consultando',
				'total' => $limite,
				'procesados' => $procesados,
				'actualizados' => $actualizados,
				'omitidos' => $omitidos,
				'errores' => $errores,
				'archivo_actual' => $ruta,
				'mensaje' => 'Yandex: ' . $procesados . ' de ' . $limite . ' revisados.',
			]);

			if ($procesados < $limite):
				duplicadosYandexDormirConCadencia();
			endif;
		endforeach;

		$pendientesRestantes = max(0, $totalPendientes - $procesados);
		$mensaje = $pendientesRestantes > 0
			? 'Tanda de Yandex completada. Quedan ' . $pendientesRestantes . ' pendientes para otra tanda.'
			: 'Hashes de Yandex actualizados.';
		duplicadosYandexActualizarTrabajo($id, [
			'estado' => 'completado',
			'finished_at' => time(),
			'total' => $limite,
			'procesados' => $procesados,
			'actualizados' => $actualizados,
			'omitidos' => $omitidos,
			'errores' => $errores,
			'archivo_actual' => '',
			'pendientes_restantes' => $pendientesRestantes,
			'mensaje' => $mensaje,
		]);
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

function duplicadosYandexCatalogoLeerTrabajo(string $id): ?array
{
	$id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
	if ($id === ''):
		return null;
	endif;

	$trabajo = duplicadosLeerJson(duplicadosYandexCatalogoRutaTrabajo($id));
	if (!is_array($trabajo) || (int) ($trabajo['version'] ?? 0) !== DUPLICADOS_YANDEX_CATALOGO_JOB_VERSION):
		return null;
	endif;

	return $trabajo;
}

function duplicadosYandexCatalogoLeerTrabajoActual(): ?array
{
	$actual = duplicadosLeerJson(duplicadosYandexCatalogoRutaTrabajoActual());
	$id = is_array($actual) ? (string) ($actual['id'] ?? '') : '';
	if ($id === ''):
		return null;
	endif;

	return duplicadosYandexCatalogoLeerTrabajo($id);
}

function duplicadosYandexCatalogoTrabajoActivo(?array $trabajo): bool
{
	if (!is_array($trabajo)):
		return false;
	endif;
	return in_array((string) ($trabajo['estado'] ?? ''), ['queued', 'catalogando', 'cancelando'], true);
}

function duplicadosYandexCatalogoGuardarTrabajo(array $trabajo): bool
{
	$trabajo['updated_at'] = time();
	if (empty($trabajo['id'])):
		return false;
	endif;

	return duplicadosGuardarJson(duplicadosYandexCatalogoRutaTrabajo((string) $trabajo['id']), $trabajo);
}

function duplicadosYandexCatalogoActualizarTrabajo(string $id, array $cambios): ?array
{
	$trabajo = duplicadosYandexCatalogoLeerTrabajo($id);
	if ($trabajo === null):
		return null;
	endif;

	$trabajo = array_replace($trabajo, $cambios);
	duplicadosYandexCatalogoGuardarTrabajo($trabajo);
	return $trabajo;
}

function duplicadosYandexCatalogoLanzarWorker(string $id): array
{
	if (!function_exists('exec')):
		return ['ok' => false, 'error' => 'exec() no está disponible para lanzar el catálogo de Yandex.'];
	endif;

	$php = duplicadosRutaPhpCli();
	if ($php === ''):
		return ['ok' => false, 'error' => 'No se encontró un ejecutable PHP CLI.'];
	endif;

	$script = proyectoRaiz() . DIRECTORY_SEPARATOR . 'yandex_catalogo_worker.php';
	if (!is_file($script)):
		return ['ok' => false, 'error' => 'No se encontró yandex_catalogo_worker.php.'];
	endif;

	$cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($id);
	$log = escapeshellarg(duplicadosYandexCatalogoRutaLogWorker());
	@exec('nohup ' . $cmd . ' > ' . $log . ' 2>&1 < /dev/null &');
	return ['ok' => true, 'error' => ''];
}

function duplicadosYandexCatalogoCrearTrabajo(): array
{
	$id = bin2hex(random_bytes(8));
	$run = time();
	return [
		'version' => DUPLICADOS_YANDEX_CATALOGO_JOB_VERSION,
		'id' => $id,
		'estado' => 'queued',
		'created_at' => $run,
		'updated_at' => $run,
		'started_at' => 0,
		'finished_at' => 0,
		'run' => $run,
		'total' => 0,
		'procesados' => 0,
			'peticiones' => 0,
			'directorios' => 0,
			'directorios_pendientes' => 1,
			'archivos' => 0,
			'actualizados' => 0,
			'ausentes' => 0,
			'omitidos' => 0,
			'errores' => 0,
		'ruta_actual' => '/',
		'offset_actual' => 0,
		'mensaje' => 'Preparando catálogo remoto de Yandex...',
		'cancel_requested' => false,
		'cola' => [['ruta' => '/', 'offset' => 0]],
		'directorios_vistos' => ['/'=> 1],
		'delay_ms' => intdiv(DUPLICADOS_YANDEX_CATALOGO_DELAY_US, 1000),
		'limite_por_lote' => DUPLICADOS_YANDEX_CATALOGO_LOTE,
		'max_peticiones_tanda' => DUPLICADOS_YANDEX_CATALOGO_MAX_PETICIONES,
		'cooldown_ms' => intdiv(DUPLICADOS_YANDEX_CATALOGO_COOLDOWN_US, 1000),
		'cooldown_error_ms' => intdiv(DUPLICADOS_YANDEX_CATALOGO_ERROR_COOLDOWN_US, 1000),
		'reanudar_en' => 0,
	];
}

function duplicadosYandexCatalogoIniciarTrabajo(bool $forzar = false): array
{
	$actual = duplicadosYandexCatalogoLeerTrabajoActual();
	if (duplicadosYandexCatalogoTrabajoActivo($actual)):
		if (
			is_array($actual)
			&& (string) ($actual['estado'] ?? '') === 'queued'
			&& duplicadosYandexCatalogoLockLibre((string) ($actual['id'] ?? ''))
		):
			$lanzado = duplicadosYandexCatalogoLanzarWorker((string) ($actual['id'] ?? ''));
			if (!$lanzado['ok']):
				$actual = duplicadosYandexCatalogoActualizarTrabajo((string) ($actual['id'] ?? ''), [
					'estado' => 'error',
					'finished_at' => time(),
					'mensaje' => $lanzado['error'],
				]) ?? $actual;
			endif;
			return ['ok' => (bool) $lanzado['ok'], 'job' => $actual, 'error' => (string) ($lanzado['error'] ?? '')];
		endif;
		return ['ok' => true, 'job' => $actual, 'error' => ''];
	endif;

	if (!$forzar && is_array($actual) && !empty($actual['cola']) && in_array((string) ($actual['estado'] ?? ''), ['pausado', 'completado_parcial'], true)):
		$id = (string) ($actual['id'] ?? '');
		$trabajo = duplicadosYandexCatalogoActualizarTrabajo($id, [
			'estado' => 'queued',
			'cancel_requested' => false,
			'finished_at' => 0,
			'cooldown_ms' => intdiv(DUPLICADOS_YANDEX_CATALOGO_COOLDOWN_US, 1000),
			'cooldown_error_ms' => intdiv(DUPLICADOS_YANDEX_CATALOGO_ERROR_COOLDOWN_US, 1000),
			'reanudar_en' => 0,
			'mensaje' => 'Reanudando catálogo remoto de Yandex...',
		]) ?? $actual;
	else:
		$trabajo = duplicadosYandexCatalogoCrearTrabajo();
		$id = (string) $trabajo['id'];
		if (!duplicadosYandexCatalogoGuardarTrabajo($trabajo) || !duplicadosGuardarJson(duplicadosYandexCatalogoRutaTrabajoActual(), ['id' => $id])):
			return ['ok' => false, 'job' => $trabajo, 'error' => 'No se pudo guardar el estado del catálogo de Yandex.'];
		endif;
	endif;

	$lanzado = duplicadosYandexCatalogoLanzarWorker((string) ($trabajo['id'] ?? ''));
	if (!$lanzado['ok']):
		$trabajo = duplicadosYandexCatalogoActualizarTrabajo((string) ($trabajo['id'] ?? ''), [
			'estado' => 'error',
			'finished_at' => time(),
			'mensaje' => $lanzado['error'],
		]) ?? $trabajo;
	endif;

	return ['ok' => (bool) $lanzado['ok'], 'job' => $trabajo, 'error' => (string) ($lanzado['error'] ?? '')];
}

function duplicadosYandexCatalogoLockLibre(string $id): bool
{
	$id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
	if ($id === ''):
		return false;
	endif;

	$lock = fopen(duplicadosYandexCatalogoRutaLockTrabajo($id), 'c');
	if ($lock === false):
		return false;
	endif;
	$libre = flock($lock, LOCK_EX | LOCK_NB);
	if ($libre):
		flock($lock, LOCK_UN);
	endif;
	fclose($lock);
	return $libre;
}

function duplicadosYandexCatalogoAutoReanudar(?array $trabajo): ?array
{
	if (!is_array($trabajo)):
		return $trabajo;
	endif;

	$id = (string) ($trabajo['id'] ?? '');
	$cola = is_array($trabajo['cola'] ?? null) ? $trabajo['cola'] : [];
	if ($id === '' || empty($cola)):
		return $trabajo;
	endif;

	$estado = (string) ($trabajo['estado'] ?? '');
	$reanudarEn = (int) ($trabajo['reanudar_en'] ?? 0);
	$cooldownMax = duplicadosYandexCatalogoSegundos(max(DUPLICADOS_YANDEX_CATALOGO_COOLDOWN_US, DUPLICADOS_YANDEX_CATALOGO_ERROR_COOLDOWN_US));
	$stale = (time() - (int) ($trabajo['updated_at'] ?? 0)) > ($cooldownMax + 300);

	if (duplicadosYandexCatalogoTrabajoActivo($trabajo)):
		if (!$stale || !duplicadosYandexCatalogoLockLibre($id)):
			return $trabajo;
		endif;
		$trabajo = duplicadosYandexCatalogoActualizarTrabajo($id, [
			'estado' => 'completado_parcial',
			'finished_at' => time(),
			'cancel_requested' => false,
			'reanudar_en' => 0,
			'mensaje' => 'El catálogo Yandex quedó interrumpido; reanudando automáticamente...',
		]) ?? $trabajo;
		$estado = (string) ($trabajo['estado'] ?? '');
	endif;

	$reanudarPausado = $estado === 'pausado' && $reanudarEn > 0 && $reanudarEn <= time();
	$reanudarParcial = $estado === 'completado_parcial' && $reanudarEn <= time();
	if (!$reanudarParcial && !$reanudarPausado):
		return $trabajo;
	endif;

	$resultado = duplicadosYandexCatalogoIniciarTrabajo(false);
	return is_array($resultado['job'] ?? null) ? $resultado['job'] : $trabajo;
}

function duplicadosYandexCatalogoCancelarTrabajo(): array
{
	$trabajo = duplicadosYandexCatalogoLeerTrabajoActual();
	if (!is_array($trabajo)):
		return ['ok' => true, 'job' => null];
	endif;

	$id = (string) ($trabajo['id'] ?? '');
	$trabajo = duplicadosYandexCatalogoActualizarTrabajo($id, [
		'estado' => duplicadosYandexCatalogoTrabajoActivo($trabajo) ? 'cancelando' : (string) ($trabajo['estado'] ?? 'cancelado'),
		'cancel_requested' => true,
		'mensaje' => 'Cancelando catálogo remoto de Yandex...',
	]) ?? $trabajo;

	return ['ok' => true, 'job' => $trabajo];
}

function duplicadosYandexCatalogoDormirConCadencia(): void
{
	$jitter = DUPLICADOS_YANDEX_CATALOGO_DELAY_JITTER_US > 0
		? random_int(0, DUPLICADOS_YANDEX_CATALOGO_DELAY_JITTER_US)
		: 0;
	usleep(DUPLICADOS_YANDEX_CATALOGO_DELAY_US + $jitter);
}

function duplicadosYandexCatalogoSegundos(int $microsegundos): int
{
	return max(1, (int) ceil(max(0, $microsegundos) / 1000000));
}

function duplicadosYandexCatalogoDormirCancelable(string $id, int $microsegundos): bool
{
	$restante = max(0, $microsegundos);
	while ($restante > 0):
		$trabajo = duplicadosYandexCatalogoLeerTrabajo($id);
		if (!empty($trabajo['cancel_requested'])):
			return false;
		endif;
		$pausa = min($restante, 5000000);
		usleep($pausa);
		$restante -= $pausa;
	endwhile;

	return true;
}

function duplicadosYandexCatalogoErrorReintetable(array $respuesta): bool
{
	$status = (int) ($respuesta['status'] ?? 0);
	if (in_array($status, [0, 429, 500, 502, 503, 504], true)):
		return true;
	endif;

	$error = mb_strtolower((string) ($respuesta['error'] ?? ''), 'UTF-8');
	return $error !== '' && (str_contains($error, 'tardó') || str_contains($error, 'timeout') || str_contains($error, 'timed out') || str_contains($error, 'too many'));
}

function duplicadosYandexCatalogoDebePausar(array $respuesta, int $erroresConsecutivos): bool
{
	$status = (int) ($respuesta['status'] ?? 0);
	if (in_array($status, [0, 401, 403, 429, 500, 502, 503, 504], true)):
		return true;
	endif;

	$error = mb_strtolower((string) ($respuesta['error'] ?? ''), 'UTF-8');
	if ($error !== '' && (str_contains($error, 'tardó') || str_contains($error, 'timeout') || str_contains($error, 'timed out') || str_contains($error, 'too many'))):
		return true;
	endif;

	return $erroresConsecutivos >= DUPLICADOS_YANDEX_CATALOGO_ERRORES_CONSECUTIVOS_MAX;
}

function duplicadosYandexCatalogoMensajePausa(array $respuesta): string
{
	$status = (int) ($respuesta['status'] ?? 0);
	$error = trim(preg_replace('/\s+/u', ' ', (string) ($respuesta['error'] ?? '')) ?? '');
	if (mb_strlen($error, 'UTF-8') > 180):
		$error = mb_substr($error, 0, 177, 'UTF-8') . '...';
	endif;

	if (in_array($status, [401, 403], true)):
		return 'Yandex respondió con error de autorización (HTTP ' . $status . '); revisa el token OAuth y vuelve a catalogar.';
	endif;

	if ($status === 400):
		return 'Yandex rechazó una ruta del catálogo (HTTP 400)' . ($error !== '' ? ': ' . $error : '') . '. Se pausó el catálogo.';
	endif;

	if ($status > 0):
		return 'Yandex respondió con HTTP ' . $status . ($error !== '' ? ': ' . $error : '') . '. Se pausó el catálogo.';
	endif;

	return 'Yandex respondió con un error no reintentable' . ($error !== '' ? ': ' . $error : '') . '. Se pausó el catálogo.';
}

function duplicadosYandexCatalogoConteo(): array
{
	$pdo = conectarCatalogoMultimedia();
	if (!$pdo):
		return ['total' => 0, 'actualizado' => 0];
	endif;

	try {
		$fila = $pdo->query("SELECT COUNT(*) AS total, MAX(actualizado) AS actualizado FROM medios WHERE origen = 'yandex' AND existente = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
		return [
			'total' => (int) ($fila['total'] ?? 0),
			'actualizado' => (int) ($fila['actualizado'] ?? 0),
		];
	} catch (PDOException $e) {
		return ['total' => 0, 'actualizado' => 0];
	}
}

function duplicadosYandexCatalogoEjecutarTrabajo(string $id): void
{
	$id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
	if ($id === ''):
		return;
	endif;
	set_time_limit(0);

	if (!duplicadosPrepararDatos()):
		return;
	endif;

	$lock = fopen(duplicadosYandexCatalogoRutaLockTrabajo($id), 'c');
	if ($lock === false):
		return;
	endif;
	if (!flock($lock, LOCK_EX | LOCK_NB)):
		fclose($lock);
		return;
	endif;

	try {
		$trabajo = duplicadosYandexCatalogoLeerTrabajo($id);
		if ($trabajo === null):
			return;
		endif;

		$configuracion = cargarConfiguracion();
		if (yandexDiskApiKeyConfiguracion($configuracion) === ''):
			duplicadosYandexCatalogoActualizarTrabajo($id, [
				'estado' => 'error',
				'finished_at' => time(),
				'mensaje' => 'No hay API Key de Yandex.Disk configurada.',
			]);
			return;
		endif;

		$pdo = conectarCatalogoMultimedia();
		if (!$pdo):
			duplicadosYandexCatalogoActualizarTrabajo($id, [
				'estado' => 'error',
				'finished_at' => time(),
				'mensaje' => 'No se pudo abrir el catálogo SQLite.',
			]);
			return;
		endif;

		$cola = is_array($trabajo['cola'] ?? null) ? array_values($trabajo['cola']) : [];
		$vistos = is_array($trabajo['directorios_vistos'] ?? null) ? $trabajo['directorios_vistos'] : ['/'=> 1];
		$run = (int) ($trabajo['run'] ?? time());
		$procesados = (int) ($trabajo['procesados'] ?? 0);
		$peticiones = (int) ($trabajo['peticiones'] ?? 0);
		$directorios = (int) ($trabajo['directorios'] ?? 0);
		$archivos = (int) ($trabajo['archivos'] ?? 0);
		$actualizados = (int) ($trabajo['actualizados'] ?? 0);
		$ausentes = (int) ($trabajo['ausentes'] ?? 0);
		$omitidos = (int) ($trabajo['omitidos'] ?? 0);
		$errores = (int) ($trabajo['errores'] ?? 0);
		$erroresConsecutivos = 0;
		$peticionesTanda = 0;

		duplicadosYandexCatalogoActualizarTrabajo($id, [
			'estado' => 'catalogando',
			'started_at' => (int) ($trabajo['started_at'] ?? 0) > 0 ? (int) $trabajo['started_at'] : time(),
			'mensaje' => 'Catalogando Yandex sin descargar multimedia...',
			'directorios_pendientes' => count($cola),
		]);

		while (!empty($cola)):
			$trabajoActual = duplicadosYandexCatalogoLeerTrabajo($id);
			if (!empty($trabajoActual['cancel_requested'])):
				duplicadosYandexCatalogoActualizarTrabajo($id, [
					'estado' => 'cancelado',
					'finished_at' => time(),
					'cola' => $cola,
					'directorios_vistos' => $vistos,
					'directorios_pendientes' => count($cola),
					'procesados' => $procesados,
					'peticiones' => $peticiones,
					'directorios' => $directorios,
					'archivos' => $archivos,
					'actualizados' => $actualizados,
					'ausentes' => $ausentes,
					'omitidos' => $omitidos,
					'errores' => $errores,
					'mensaje' => 'Catálogo remoto de Yandex cancelado.',
				]);
				return;
			endif;

			if ($peticionesTanda >= DUPLICADOS_YANDEX_CATALOGO_MAX_PETICIONES):
				$reanudarEn = time() + duplicadosYandexCatalogoSegundos(DUPLICADOS_YANDEX_CATALOGO_COOLDOWN_US);
				duplicadosYandexCatalogoActualizarTrabajo($id, [
					'estado' => 'catalogando',
					'finished_at' => 0,
					'cola' => $cola,
					'directorios_vistos' => $vistos,
					'directorios_pendientes' => count($cola),
					'procesados' => $procesados,
					'peticiones' => $peticiones,
					'directorios' => $directorios,
					'archivos' => $archivos,
					'actualizados' => $actualizados,
					'ausentes' => $ausentes,
					'omitidos' => $omitidos,
					'errores' => $errores,
					'reanudar_en' => $reanudarEn,
					'mensaje' => 'Pausa de seguridad de Yandex: ' . count($cola) . ' carpetas/páginas pendientes. Se reanuda automáticamente en ' . duplicadosYandexCatalogoSegundos(DUPLICADOS_YANDEX_CATALOGO_COOLDOWN_US) . ' segundos.',
				]);
				if (!duplicadosYandexCatalogoDormirCancelable($id, DUPLICADOS_YANDEX_CATALOGO_COOLDOWN_US)):
					duplicadosYandexCatalogoActualizarTrabajo($id, [
						'estado' => 'cancelado',
						'finished_at' => time(),
						'cola' => $cola,
						'directorios_vistos' => $vistos,
						'directorios_pendientes' => count($cola),
						'procesados' => $procesados,
						'peticiones' => $peticiones,
						'directorios' => $directorios,
						'archivos' => $archivos,
						'actualizados' => $actualizados,
						'ausentes' => $ausentes,
						'omitidos' => $omitidos,
						'errores' => $errores,
						'reanudar_en' => 0,
						'mensaje' => 'Catálogo remoto de Yandex cancelado durante la pausa de seguridad.',
					]);
					return;
				endif;
				$peticionesTanda = 0;
				duplicadosYandexCatalogoActualizarTrabajo($id, [
					'estado' => 'catalogando',
					'reanudar_en' => 0,
					'mensaje' => 'Reanudando catálogo remoto de Yandex...',
				]);
				continue;
			endif;

			$itemCola = array_shift($cola);
			$ruta = normalizarRutaYandexDisk((string) ($itemCola['ruta'] ?? '/'));
			$offset = max(0, (int) ($itemCola['offset'] ?? 0));
			duplicadosYandexCatalogoActualizarTrabajo($id, [
				'estado' => 'catalogando',
				'ruta_actual' => $ruta,
				'offset_actual' => $offset,
				'directorios_pendientes' => count($cola) + 1,
				'mensaje' => 'Yandex catálogo: leyendo ' . $ruta . ' desde ' . $offset . '.',
			]);

			$respuesta = obtenerDirectorioYandexDiskRemotoCatalogo($configuracion, $ruta, DUPLICADOS_YANDEX_CATALOGO_LOTE, $offset);
			$peticiones++;
			$peticionesTanda++;
			if (empty($respuesta['ok'])):
				$errores++;
				$erroresConsecutivos++;
				if (duplicadosYandexCatalogoDebePausar($respuesta, $erroresConsecutivos)):
					array_unshift($cola, ['ruta' => $ruta, 'offset' => $offset]);
					$reintetable = duplicadosYandexCatalogoErrorReintetable($respuesta);
					$reanudarEn = $reintetable ? time() + duplicadosYandexCatalogoSegundos(DUPLICADOS_YANDEX_CATALOGO_ERROR_COOLDOWN_US) : 0;
					duplicadosYandexCatalogoActualizarTrabajo($id, [
						'estado' => $reintetable ? 'catalogando' : 'pausado',
						'finished_at' => $reintetable ? 0 : time(),
						'cola' => $cola,
						'directorios_vistos' => $vistos,
						'directorios_pendientes' => count($cola),
						'procesados' => $procesados,
						'peticiones' => $peticiones,
						'directorios' => $directorios,
						'archivos' => $archivos,
						'actualizados' => $actualizados,
						'ausentes' => $ausentes,
						'omitidos' => $omitidos,
						'errores' => $errores,
						'reanudar_en' => $reanudarEn,
						'mensaje' => $reintetable
							? 'Yandex respondió lento o con error temporal; pausa prudente. Se reanuda automáticamente en ' . duplicadosYandexCatalogoSegundos(DUPLICADOS_YANDEX_CATALOGO_ERROR_COOLDOWN_US) . ' segundos.'
							: duplicadosYandexCatalogoMensajePausa($respuesta),
					]);
					if ($reintetable):
						if (!duplicadosYandexCatalogoDormirCancelable($id, DUPLICADOS_YANDEX_CATALOGO_ERROR_COOLDOWN_US)):
							duplicadosYandexCatalogoActualizarTrabajo($id, [
								'estado' => 'cancelado',
								'finished_at' => time(),
								'cola' => $cola,
								'directorios_vistos' => $vistos,
								'directorios_pendientes' => count($cola),
								'procesados' => $procesados,
								'peticiones' => $peticiones,
								'directorios' => $directorios,
								'archivos' => $archivos,
								'actualizados' => $actualizados,
								'ausentes' => $ausentes,
								'omitidos' => $omitidos,
								'errores' => $errores,
								'reanudar_en' => 0,
								'mensaje' => 'Catálogo remoto de Yandex cancelado durante la pausa por error temporal.',
							]);
							return;
						endif;
						$erroresConsecutivos = 0;
						$peticionesTanda = 0;
						duplicadosYandexCatalogoActualizarTrabajo($id, [
							'estado' => 'catalogando',
							'reanudar_en' => 0,
							'mensaje' => 'Reintentando catálogo remoto de Yandex...',
						]);
						continue;
					endif;
					return;
				endif;
				$omitidos++;
				continue;
			endif;

			$erroresConsecutivos = 0;
			$recursos = is_array($respuesta['recursos'] ?? null) ? $respuesta['recursos'] : [];
			$totalRemoto = max(0, (int) ($respuesta['total'] ?? count($recursos)));
			$consultados = count($recursos);
			$procesados += $consultados;
			$directorios++;

			try {
				$pdo->beginTransaction();
				foreach ($recursos as $recurso):
					if (!is_array($recurso)):
						continue;
					endif;
					$tipo = (string) ($recurso['type'] ?? 'file');
					if ($tipo === 'dir'):
						$rutaDir = normalizarRutaYandexDisk((string) ($recurso['path'] ?? $recurso['id'] ?? ''));
						if ($rutaDir !== '/' && empty($vistos[$rutaDir])):
							$vistos[$rutaDir] = 1;
							$cola[] = ['ruta' => $rutaDir, 'offset' => 0];
						endif;
						continue;
					endif;
					if ($tipo !== 'file' || !yandexDiskEsItemMultimedia($recurso)):
						$omitidos++;
						continue;
					endif;
					$archivos++;
					if (catalogoGuardarYandex($pdo, $recurso, $run)):
						$actualizados++;
					else:
						$omitidos++;
					endif;
				endforeach;
				$pdo->commit();
			} catch (Throwable $e) {
				if ($pdo->inTransaction()):
					$pdo->rollBack();
				endif;
				$errores++;
				duplicadosYandexCatalogoActualizarTrabajo($id, [
					'estado' => 'error',
					'finished_at' => time(),
					'cola' => $cola,
					'directorios_vistos' => $vistos,
					'directorios_pendientes' => count($cola),
					'errores' => $errores,
					'mensaje' => 'No se pudo guardar el catálogo de Yandex: ' . $e->getMessage(),
				]);
				return;
			}

			$siguienteOffset = $offset + $consultados;
			if ($consultados > 0 && $siguienteOffset < $totalRemoto):
				array_unshift($cola, ['ruta' => $ruta, 'offset' => $siguienteOffset]);
			endif;

			duplicadosYandexCatalogoActualizarTrabajo($id, [
				'estado' => 'catalogando',
				'total' => max((int) ($trabajo['total'] ?? 0), count($vistos)),
				'cola' => $cola,
				'directorios_vistos' => $vistos,
				'directorios_pendientes' => count($cola),
				'procesados' => $procesados,
				'peticiones' => $peticiones,
				'directorios' => $directorios,
				'archivos' => $archivos,
				'actualizados' => $actualizados,
				'ausentes' => $ausentes,
				'omitidos' => $omitidos,
				'errores' => $errores,
				'mensaje' => 'Yandex catálogo: ' . $actualizados . ' multimedia catalogados · ' . count($cola) . ' pendientes.',
			]);

			if (!empty($cola)):
				duplicadosYandexCatalogoDormirConCadencia();
			endif;
		endwhile;

		$ausentes = function_exists('catalogoMarcarYandexNoVerificados')
			? catalogoMarcarYandexNoVerificados($pdo, $run)
			: 0;
		catalogoGuardarEstado($pdo, 'ultimo_sync_yandex', (string) time());
		duplicadosYandexCatalogoActualizarTrabajo($id, [
			'estado' => 'completado',
			'finished_at' => time(),
			'total' => count($vistos),
			'cola' => [],
			'directorios_vistos' => $vistos,
			'directorios_pendientes' => 0,
			'procesados' => $procesados,
			'peticiones' => $peticiones,
			'directorios' => $directorios,
			'archivos' => $archivos,
			'actualizados' => $actualizados,
			'ausentes' => $ausentes,
			'omitidos' => $omitidos,
			'errores' => $errores,
			'ruta_actual' => '',
			'offset_actual' => 0,
			'mensaje' => 'Catálogo remoto de Yandex completado: ' . $actualizados . ' multimedia catalogados' . ($ausentes > 0 ? ' · ' . $ausentes . ' ausentes limpiados.' : '.'),
		]);
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

function duplicadosEstado(array $fuente = [], bool $incluirGrupos = false): array
{
	$base = duplicadosResolverBaseLocal($fuente['ruta'] ?? ($_GET['ruta'] ?? ''));
	$local = duplicadosResumenLocal($base);
	$grupos = $incluirGrupos ? duplicadosConstruirGrupos($base) : [];
	$trabajo = duplicadosLeerTrabajoActual();
	$trabajoYandex = duplicadosYandexLeerTrabajoActual();
	$trabajoCatalogoYandex = duplicadosYandexCatalogoLeerTrabajoActual();
	$trabajoCatalogoYandex = duplicadosYandexCatalogoAutoReanudar($trabajoCatalogoYandex);
	$yandexResumen = duplicadosYandexResumenCache($incluirGrupos && !duplicadosYandexTrabajoActivo($trabajoYandex));
	$yandex = [
		'total' => (int) ($yandexResumen['total'] ?? 0),
		'actualizado' => (int) ($yandexResumen['actualizado'] ?? 0),
	];
	$conteosOrigen = duplicadosConteosOrigen($base, false, $local, $yandexResumen);
	$catalogoYandex = duplicadosYandexCatalogoConteo();
	$trabajo = duplicadosTrabajoPublico($trabajo);
	$trabajoYandex = duplicadosTrabajoPublico($trabajoYandex);
	$trabajoCatalogoYandex = duplicadosTrabajoPublico($trabajoCatalogoYandex);

	return [
		'base' => $base,
		'base_rel' => rutaRelativaParaParametro($base),
		'yandex' => [
			'total' => (int) ($yandex['total'] ?? 0),
			'indexados' => (int) ($yandexResumen['total'] ?? 0),
			'con_hash' => (int) ($yandexResumen['con_hash'] ?? 0),
			'sin_hash' => (int) ($yandexResumen['sin_hash'] ?? 0),
			'md5' => (int) ($yandexResumen['md5'] ?? 0),
			'sha256' => (int) ($yandexResumen['sha256'] ?? 0),
			'pendientes_hash' => (int) ($yandexResumen['pendientes_hash'] ?? 0),
			'actualizado' => max((int) ($yandex['actualizado'] ?? 0), (int) ($yandexResumen['actualizado'] ?? 0)),
			'job' => $trabajoYandex,
			'catalogo_total' => (int) ($catalogoYandex['total'] ?? 0),
			'catalogo_actualizado' => (int) ($catalogoYandex['actualizado'] ?? 0),
			'catalog_job' => $trabajoCatalogoYandex,
		],
		'local' => $local,
		'grupos' => $grupos,
		'resumen' => $incluirGrupos ? duplicadosResumenGrupos($grupos) : duplicadosResumenVacio(true),
		'conteos_origen' => $conteosOrigen,
		'job' => $trabajo,
	];
}

function duplicadosTrabajoPublico(?array $trabajo): ?array
{
	if ($trabajo === null):
		return null;
	endif;

	unset(
		$trabajo['cola'],
		$trabajo['directorios_vistos']
	);

	return $trabajo;
}

function duplicadosEstadoPaginaGrupos(array $fuente = [], int $offset = 0, int $limite = DUPLICADOS_GRUPOS_POR_PAGINA, string $filtroOrigen = 'todos'): array
{
	$limite = max(1, min(100, $limite));
	$offset = max(0, $offset);
	$filtroOrigen = duplicadosNormalizarFiltroOrigen($filtroOrigen);
	$estado = duplicadosEstado($fuente, false);
	$grupos = duplicadosConstruirGrupos((string) ($estado['base'] ?? ''), $limite + 1, $offset, $filtroOrigen);
	$hayMas = count($grupos) > $limite;
	if ($hayMas):
		$grupos = array_slice($grupos, 0, $limite);
	endif;
	$conteo = count($grupos);

	return [
		'estado' => $estado,
		'grupos' => $grupos,
		'resumen' => duplicadosResumenGrupos($grupos),
		'paginacion' => [
			'offset' => $offset,
			'limit' => $limite,
			'conteo' => $conteo,
			'next_offset' => $offset + $conteo,
			'hay_mas' => $hayMas,
			'filtro_origen' => $filtroOrigen,
		],
	];
}

function duplicadosRespuestaAjax(array $estado, bool $ok = true, string $error = '', bool $incluirResultados = false): array
{
	$respuesta = [
		'ok' => $ok,
		'error' => $error,
		'estado' => [
			'base' => $estado['base'],
			'base_rel' => $estado['base_rel'],
			'yandex' => $estado['yandex'],
			'local' => $estado['local'],
			'resumen' => $estado['resumen'],
			'conteos_origen' => $estado['conteos_origen'] ?? duplicadosConteosOrigenVacios(true),
			'job' => $estado['job'],
		],
	];
	if ($incluirResultados):
		$respuesta['html_resultados'] = renderizarResultadosDuplicados($estado);
	endif;

	return $respuesta;
}

function duplicadosRespuestaPaginaGruposAjax(array $pagina, bool $ok = true, string $error = ''): array
{
	$estado = is_array($pagina['estado'] ?? null) ? $pagina['estado'] : duplicadosEstado([], false);
	return array_merge(
		duplicadosRespuestaAjax($estado, $ok, $error, false),
		[
			'html_grupos' => renderizarGruposDuplicados(is_array($pagina['grupos'] ?? null) ? $pagina['grupos'] : []),
			'resumen_pagina' => is_array($pagina['resumen'] ?? null) ? $pagina['resumen'] : duplicadosResumenVacio(false),
			'paginacion' => is_array($pagina['paginacion'] ?? null) ? $pagina['paginacion'] : [
				'offset' => 0,
				'limit' => DUPLICADOS_GRUPOS_POR_PAGINA,
				'conteo' => 0,
				'next_offset' => 0,
				'hay_mas' => false,
				'filtro_origen' => 'todos',
			],
		]
	);
}

function duplicadosTextoLocalFirmas(array $local, bool $incluirLocales = false): string
{
	$texto = (int) ($local['vigentes'] ?? 0) . ($incluirLocales ? ' locales con firma' : ' con firma');
	$pendientes = (int) ($local['por_actualizar'] ?? 0);
	if ($pendientes > 0):
		$texto .= ' · ' . $pendientes . ' por actualizar';
	endif;

	return $texto;
}

function duplicadosMensajeLocalFirmas(array $local, ?array $job): string
{
	if (duplicadosTrabajoActivo($job)):
		return (string) ($job['mensaje'] ?? 'Calculando firmas locales...');
	endif;

	$pendientes = (int) ($local['por_actualizar'] ?? 0);
	if ($pendientes > 0):
		return $pendientes . ' archivos pendientes de actualizar a firmas nuevas.';
	endif;

	return is_array($job) && (string) ($job['mensaje'] ?? '') !== ''
		? (string) $job['mensaje']
		: 'Índice de firmas locales listo.';
}

function duplicadosTextoConteoOrigen(array $conteos, string $origen): string
{
	$dato = is_array($conteos[$origen] ?? null) ? $conteos[$origen] : null;
	if ($dato === null):
		return '...';
	endif;
	if (!empty($dato['pendiente'])):
		return '...';
	endif;
	$texto = number_format((int) ($dato['grupos'] ?? 0), 0, '.', ',');
	return !empty($dato['mas']) ? $texto . '+' : $texto;
}

function renderizarBotonFiltroDuplicados(string $origen, string $titulo, string $descripcion, array $conteos): string
{
	$conteo = duplicadosTextoConteoOrigen($conteos, $origen);
	$title = $conteo === '...'
		? 'Conteo de grupos pendiente'
		: $conteo . ' grupos de duplicados';
	return
		'<button type="button" data-duplicados-filtro-origen="' . escaparHtml($origen) . '">' .
			'<span class="duplicados-filtro-encabezado">' .
				'<strong>' . escaparHtml($titulo) . '</strong>' .
				'<span class="duplicados-filtro-conteo" data-duplicados-conteo-origen="' . escaparHtml($origen) . '" title="' . escaparHtml($title) . '">' . escaparHtml($conteo) . '</span>' .
			'</span>' .
			'<span class="duplicados-filtro-descripcion">' . escaparHtml($descripcion) . '</span>' .
		'</button>';
}

function renderizarPanelDuplicados(array $estado): string
{
	$job = is_array($estado['job'] ?? null) ? $estado['job'] : null;
	$jobYandex = is_array($estado['yandex']['job'] ?? null) ? $estado['yandex']['job'] : null;
	$jobCatalogoYandex = is_array($estado['yandex']['catalog_job'] ?? null) ? $estado['yandex']['catalog_job'] : null;
	$activo = duplicadosTrabajoActivo($job) || duplicadosYandexTrabajoActivo($jobYandex) || duplicadosYandexCatalogoTrabajoActivo($jobCatalogoYandex);
	$local = $estado['local'] ?? [];
	$yandex = $estado['yandex'] ?? [];
	$resumen = $estado['resumen'] ?? [];
	$conteosOrigen = is_array($estado['conteos_origen'] ?? null) ? $estado['conteos_origen'] : duplicadosConteosOrigenVacios(true);
	$mensajes = [];
	$mensajes[] = 'Local: ' . duplicadosMensajeLocalFirmas($local, $job);
	if ($jobYandex):
		$mensajes[] = 'Yandex: ' . (string) ($jobYandex['mensaje'] ?? '');
	endif;
	if ($jobCatalogoYandex):
		$mensajes[] = 'Catálogo: ' . (string) ($jobCatalogoYandex['mensaje'] ?? '');
	endif;
	$mensaje = !empty($mensajes) ? implode(' · ', array_filter($mensajes)) : 'Sin trabajo activo.';
	$resumenTexto = !empty($resumen['pendiente'])
		? 'Grupos: carga asíncrona'
		: 'Grupos: ' . (int) ($resumen['grupos'] ?? 0) . ' · Exactos: ' . (int) ($resumen['exactos'] ?? 0) . ' · Probables: ' . (int) ($resumen['probables'] ?? 0);

	return
		renderizarFormularioRutaDuplicados($estado) .
		'<div class="duplicados-panel">' .
		'<div class="duplicados-panel-resumen">' .
		'<strong>Duplicados</strong>' .
		'<span>Local: ' . escaparHtml(duplicadosTextoLocalFirmas($local)) . '</span>' .
		'<span>Yandex firmas: ' . (int) ($yandex['total'] ?? 0) . ' con hash · ' . (int) ($yandex['pendientes_hash'] ?? 0) . ' pendientes</span>' .
		'<span>Catálogo Yandex: ' . (int) ($yandex['catalogo_total'] ?? 0) . ' multimedia</span>' .
		'<span>' . escaparHtml($resumenTexto) . '</span>' .
		'</div>' .
		'<div class="duplicados-panel-estado' . ($activo ? ' activo' : '') . '">' . escaparHtml($mensaje) . '</div>' .
		'<div class="duplicados-panel-filtros" data-duplicados-filtros-origen aria-label="Filtrar duplicados por origen">' .
		renderizarBotonFiltroDuplicados('local', 'Duplicados locales', 'Sólo archivos locales', $conteosOrigen) .
		renderizarBotonFiltroDuplicados('remoto', 'Duplicados remotos', 'Sólo archivos de Yandex', $conteosOrigen) .
		renderizarBotonFiltroDuplicados('mixto', 'Duplicados mixtos', 'Local y Yandex', $conteosOrigen) .
		'</div>' .
		'</div>';
}

function renderizarFormularioRutaDuplicados(array $estado): string
{
	$baseRel = (string) ($estado['base_rel'] ?? '');
	return
		'<form method="get" class="duplicados-ruta-form duplicados-ruta-form-lateral">' .
		'<input type="hidden" name="panel" value="duplicados">' .
		'<label for="duplicados-ruta">Ruta local<input id="duplicados-ruta" type="text" name="ruta" value="' . escaparHtml($baseRel) . '" autocomplete="off"></label>' .
		'<button type="submit">Cambiar ruta</button>' .
		'</form>';
}

function renderizarControlesDuplicados(array $estado): string
{
	$resumen = $estado['resumen'] ?? [];
	$local = $estado['local'] ?? [];
	$yandex = $estado['yandex'] ?? [];
	$actualizadoLocal = (int) ($local['actualizado'] ?? 0);
	$actualizadoYandex = (int) ($yandex['actualizado'] ?? 0);
	$textoLocalFirmas = duplicadosTextoLocalFirmas($local, true);
	$partes = !empty($resumen['pendiente'])
		? [
			'grupos por cargar',
			$textoLocalFirmas,
			(int) ($yandex['total'] ?? 0) . ' Yandex con hash',
			(int) ($yandex['pendientes_hash'] ?? 0) . ' Yandex pendientes',
			(int) ($yandex['catalogo_total'] ?? 0) . ' en catálogo Yandex',
		]
		: [
			(int) ($resumen['grupos'] ?? 0) . ' grupos',
			(int) ($resumen['exactos'] ?? 0) . ' exactos',
			(int) ($resumen['probables'] ?? 0) . ' probables',
			(int) ($resumen['cruzados'] ?? 0) . ' local/Yandex',
			$textoLocalFirmas,
			(int) ($yandex['total'] ?? 0) . ' Yandex con hash',
			(int) ($yandex['pendientes_hash'] ?? 0) . ' Yandex pendientes',
			(int) ($yandex['catalogo_total'] ?? 0) . ' en catálogo Yandex',
		];
	if ($actualizadoLocal > 0):
		$partes[] = 'Local ' . duplicadosFormatearFecha($actualizadoLocal);
	endif;
	if ($actualizadoYandex > 0):
		$partes[] = 'Yandex ' . duplicadosFormatearFecha($actualizadoYandex);
	endif;
	if ((int) ($yandex['catalogo_actualizado'] ?? 0) > 0):
		$partes[] = 'Catálogo Yandex ' . duplicadosFormatearFecha((int) $yandex['catalogo_actualizado']);
	endif;

	return
		'<div class="filtros-metadatos duplicados-controles">' .
		'<span class="filtros-metadatos-resumen">' . escaparHtml(implode(' · ', $partes)) . '</span>' .
		'</div>';
}

function renderizarVistaDuplicados(array $estado): string
{
	$base = (string) ($estado['base'] ?? '');
	$baseRel = (string) ($estado['base_rel'] ?? '');
	$job = is_array($estado['job'] ?? null) ? $estado['job'] : null;
	$yandex = is_array($estado['yandex'] ?? null) ? $estado['yandex'] : [];
	$jobYandex = is_array($yandex['job'] ?? null) ? $yandex['job'] : null;
	$jobCatalogoYandex = is_array($yandex['catalog_job'] ?? null) ? $yandex['catalog_job'] : null;
	$total = (int) ($job['total'] ?? 0);
	$procesados = (int) ($job['procesados'] ?? 0);
	$totalYandex = (int) ($jobYandex['total'] ?? 0);
	$procesadosYandex = (int) ($jobYandex['procesados'] ?? 0);
	$totalCatalogoYandex = max(1, (int) ($jobCatalogoYandex['total'] ?? 0), (int) ($jobCatalogoYandex['directorios'] ?? 0) + (int) ($jobCatalogoYandex['directorios_pendientes'] ?? 0));
	$procesadosCatalogoYandex = (int) ($jobCatalogoYandex['directorios'] ?? 0);
	$mensaje = duplicadosMensajeLocalFirmas(is_array($estado['local'] ?? null) ? $estado['local'] : [], $job);
	$mensajeYandex = $jobYandex
		? (string) ($jobYandex['mensaje'] ?? '')
		: ('Yandex: ' . (int) ($yandex['pendientes_hash'] ?? 0) . ' pendientes de revisión.');
	$mensajeCatalogoYandex = $jobCatalogoYandex
		? (string) ($jobCatalogoYandex['mensaje'] ?? '')
		: ('Catálogo Yandex: ' . (int) ($yandex['catalogo_total'] ?? 0) . ' multimedia registrados.');
	$activo = duplicadosTrabajoActivo($job);
	$activoYandex = duplicadosYandexTrabajoActivo($jobYandex);
	$activoCatalogoYandex = duplicadosYandexCatalogoTrabajoActivo($jobCatalogoYandex);

	return
		'<section id="duplicados-vista" class="duplicados-vista" data-duplicados-ruta="' . escaparHtml($baseRel) . '" data-duplicados-base="' . escaparHtml($base) . '" data-duplicados-page-size="' . DUPLICADOS_GRUPOS_POR_PAGINA . '">' .
		'<div class="duplicados-herramientas">' .
		'<div class="duplicados-herramientas-titulo">' .
		'<h1>Duplicados</h1>' .
		'<code>' . escaparHtml($baseRel !== '' ? $baseRel : $base) . '</code>' .
		'</div>' .
		'<div class="duplicados-acciones">' .
		'<button type="button" id="duplicados-iniciar">Actualizar firmas locales</button>' .
		'<button type="button" id="duplicados-recalcular">Recalcular</button>' .
		'<button type="button" id="duplicados-cancelar"' . ($activo ? '' : ' disabled') . '>Cancelar</button>' .
		'<button type="button" id="duplicados-yandex-catalogar">Catalogar Yandex</button>' .
		'<button type="button" id="duplicados-yandex-catalogar-cancelar"' . ($activoCatalogoYandex ? '' : ' disabled') . '>Cancelar catálogo</button>' .
		'<button type="button" id="duplicados-yandex-iniciar">Actualizar hashes Yandex</button>' .
		'<button type="button" id="duplicados-yandex-cancelar"' . ($activoYandex ? '' : ' disabled') . '>Cancelar Yandex</button>' .
		'</div>' .
		'<div class="duplicados-progreso duplicados-progreso-local">' .
		'<progress id="duplicados-progreso" max="' . max(1, $total) . '" value="' . max(0, $procesados) . '"></progress>' .
		'<span id="duplicados-mensaje">' . escaparHtml($mensaje) . '</span>' .
		'</div>' .
		'<div class="duplicados-progreso duplicados-progreso-yandex">' .
		'<progress id="duplicados-yandex-progreso" max="' . max(1, $totalYandex) . '" value="' . max(0, $procesadosYandex) . '"></progress>' .
		'<span id="duplicados-yandex-mensaje">' . escaparHtml($mensajeYandex) . '</span>' .
		'</div>' .
		'<div class="duplicados-progreso duplicados-progreso-yandex-catalogo">' .
		'<progress id="duplicados-yandex-catalogo-progreso" max="' . max(1, $totalCatalogoYandex) . '" value="' . max(0, $procesadosCatalogoYandex) . '"></progress>' .
		'<span id="duplicados-yandex-catalogo-mensaje">' . escaparHtml($mensajeCatalogoYandex) . '</span>' .
		'</div>' .
		'</div>' .
		'<label class="duplicados-buscador-label" for="duplicados-buscador">Filtrar resultados</label>' .
		'<input type="search" id="duplicados-buscador" class="duplicados-buscador" placeholder="Nombre, ruta, hash o score" autocomplete="off">' .
		'<div id="duplicados-resultados" data-duplicados-page-size="' . DUPLICADOS_GRUPOS_POR_PAGINA . '">' .
			'<div class="duplicados-lista" data-duplicados-lista></div>' .
			'<div class="duplicados-vacio" role="status">Cargando grupos de duplicados...</div>' .
		'</div>' .
		'<div id="duplicados-carga-mas" class="duplicados-carga-mas" role="status" aria-live="polite">' .
			'<button type="button" data-duplicados-cargar-mas>Cargar más grupos</button>' .
			'<span data-duplicados-carga-estado>Cargando...</span>' .
		'</div>' .
		'</section>';
}

function renderizarResultadosDuplicados(array $estado): string
{
	$grupos = is_array($estado['grupos'] ?? null) ? $estado['grupos'] : [];
	if (empty($grupos)):
		return '<div class="duplicados-vacio" role="status">No hay duplicados exactos ni probables con las firmas disponibles.</div>';
	endif;

	return '<div class="duplicados-lista">' . renderizarGruposDuplicados($grupos) . '</div>';
}

function renderizarGruposDuplicados(array $grupos): string
{
	$html = '';
	foreach ($grupos as $indice => $grupo):
		$items = is_array($grupo['items'] ?? null) ? $grupo['items'] : [];
		$usarDescarteDirecto = count($items) === 2;
		$tipo = (string) ($grupo['tipo'] ?? '');
		$hash = (string) ($grupo['hash'] ?? '');
		$score = (int) ($grupo['score'] ?? 100);
		$cruzado = !empty($grupo['cruzado']);
		$origenTipo = duplicadosNormalizarFiltroOrigen($grupo['origen_tipo'] ?? ($cruzado ? 'mixto' : 'todos'));
		if ($origenTipo === 'todos'):
			$origenTipo = duplicadosTipoOrigenGrupoDesdeItems($items);
		endif;
		$md5s = is_array($grupo['md5s'] ?? null) ? $grupo['md5s'] : [];
		$sha256s = is_array($grupo['sha256s'] ?? null) ? $grupo['sha256s'] : [];
		$razones = is_array($grupo['razones'] ?? null) ? array_slice($grupo['razones'], 0, 6) : [];
		$etiquetaScore = $score >= 100 ? 'Exacto' : 'Probable';
		$textoHash = $hash !== '' ? $tipo . ' ' . duplicadosHashCorto($hash, 24) : (string) ($grupo['metodo'] ?? $tipo);
		$busqueda = implode(' ', [
			$hash,
			'score ' . $score,
			$etiquetaScore,
			implode(' ', $razones),
			implode(' ', array_map(fn($item) => (string) ($item['ruta'] ?? '') . ' ' . (string) ($item['nombre'] ?? ''), $items)),
		]);
		$origenes = array_values(array_unique(array_map(fn($item) => (string) ($item['origen'] ?? ''), $items)));
		$accionGrupo = in_array('yandex', $origenes, true)
			? (in_array('local', $origenes, true) ? 'Procesar selección' : 'Enviar selección a papelera')
			: 'Descartar selección';
		$accionesOrigenExacto = '';
		if ($score >= 100 && $origenTipo === 'mixto' && in_array('local', $origenes, true) && in_array('yandex', $origenes, true)):
			$accionesOrigenExacto =
				'<button type="button" data-duplicado-descartar-origen="local">Descartar local</button>' .
				'<button type="button" data-duplicado-descartar-origen="yandex">Descartar remoto</button>';
		endif;
		$accionesSugeridas = duplicadosAccionesSugeridasGrupo($grupo);
		$html .=
			'<details class="duplicados-grupo duplicados-grupo-' . escaparHtml($origenTipo) . ($cruzado ? ' duplicados-grupo-cruzado' : '') . '" open data-duplicados-busqueda="' . escaparHtml($busqueda) . '" data-duplicado-grupo="1" data-duplicado-grupo-tipo="' . escaparHtml($origenTipo) . '" data-duplicado-razones="' . escaparHtml(implode(' · ', $razones)) . '">' .
				'<summary>' .
				'<span class="duplicados-grupo-titulo">' . count($items) . ' archivos' . ($cruzado ? ' · Local/Yandex' : '') . '</span>' .
				'<span class="duplicados-score duplicados-score-' . ($score >= 100 ? 'exacto' : 'probable') . '">Score ' . $score . ' · ' . escaparHtml($etiquetaScore) . '</span>' .
				'<code title="' . escaparHtml($hash !== '' ? $hash : $textoHash) . '">' . escaparHtml($textoHash) . '</code>' .
				'</summary>' .
				'<div class="duplicados-grupo-meta">' .
				'<div class="duplicados-hashes">' .
				(!empty($md5s) ? '<span>MD5: <code>' . escaparHtml(duplicadosHashCorto(implode(', ', $md5s), 40)) . '</code></span>' : '') .
				(!empty($sha256s) ? '<span>SHA-256: <code>' . escaparHtml(duplicadosHashCorto(implode(', ', $sha256s), 48)) . '</code></span>' : '') .
				(!empty($razones) ? '<span>Razones: ' . escaparHtml(implode(' · ', $razones)) . '</span>' : '') .
				'</div>' .
				'<div class="duplicados-grupo-acciones">' .
				$accionesOrigenExacto .
				$accionesSugeridas .
				'<button type="button" data-duplicado-descartar-grupo disabled>' . escaparHtml($accionGrupo) . '</button>' .
				'<span data-duplicado-seleccion-resumen>0 seleccionados</span>' .
				'</div>' .
				'</div>' .
				'<div class="duplicados-items">';
		foreach ($items as $item):
			$origen = (string) ($item['origen'] ?? '');
			$ruta = (string) ($item['ruta'] ?? '');
			$nombre = (string) ($item['nombre'] ?? basename($ruta));
			$tamano = (string) ($item['tamano_legible'] ?? '');
			$modificado = (string) ($item['modificado'] ?? '');
			$dimensiones = duplicadosTextoDimensiones($item);
			$duracion = duplicadosTextoDuracion($item);
			$meta = implode(' · ', array_filter([$tamano, $dimensiones, $duracion, $modificado], fn($valor) => $valor !== ''));
			$html .=
				'<div class="duplicados-item duplicados-item-' . escaparHtml($origen) . '"' . duplicadosAtributosItem($item) . ' tabindex="0" aria-label="Ver vista previa de ' . escaparHtml($nombre) . '">' .
				'<span class="duplicados-origen">' . escaparHtml((string) ($item['origen_etiqueta'] ?? $origen)) . '</span>' .
				'<div class="duplicados-item-info">' .
				'<strong title="' . escaparHtml($nombre) . '">' . escaparHtml($nombre) . '</strong>' .
				'<code title="' . escaparHtml($ruta) . '">' . escaparHtml($ruta) . '</code>' .
				($meta !== '' ? '<small>' . escaparHtml($meta) . '</small>' : '') .
				'</div>' .
				'<div class="duplicados-item-acciones">' .
				($usarDescarteDirecto
					? '<button type="button" data-duplicado-descartar-item>Descartar</button>'
					: '<button type="button" data-duplicado-seleccionar aria-pressed="false">Seleccionar para borrar</button>') .
				'</div>' .
				'</div>';
		endforeach;
		$html .= '</div></details>';
	endforeach;

	return $html;
}
