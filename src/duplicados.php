<?php

const DUPLICADOS_HASH_DB_VERSION = 2;
const DUPLICADOS_JOB_VERSION = 1;
const DUPLICADOS_FIRMA_VERSION = 1;
const DUPLICADOS_SCORE_MINIMO = 70;
const DUPLICADOS_BUCKET_PROBABLE_MAX = 250;
const DUPLICADOS_CANDIDATOS_PROBABLES_MAX = 2000;
const DUPLICADOS_GRUPOS_POR_PAGINA = 24;

function duplicadosDirectorioDatos(): string
{
	return proyectoRaiz() . DIRECTORY_SEPARATOR . 'datos';
}

function duplicadosDirectorioTrabajos(): string
{
	return duplicadosDirectorioDatos() . DIRECTORY_SEPARATOR . 'duplicados_jobs';
}

function duplicadosRutaBaseDatos(): string
{
	return duplicadosDirectorioDatos() . DIRECTORY_SEPARATOR . 'duplicados_hashes.sqlite';
}

function duplicadosRutaTrabajoActual(): string
{
	return duplicadosDirectorioDatos() . DIRECTORY_SEPARATOR . 'duplicados_job_actual.json';
}

function duplicadosRutaTrabajo(string $id): string
{
	return duplicadosDirectorioTrabajos() . DIRECTORY_SEPARATOR . basename($id) . '.json';
}

function duplicadosRutaLockTrabajo(string $id): string
{
	return duplicadosDirectorioTrabajos() . DIRECTORY_SEPARATOR . basename($id) . '.lock';
}

function duplicadosRutaLogWorker(): string
{
	return duplicadosDirectorioDatos() . DIRECTORY_SEPARATOR . 'duplicados_worker.log';
}

function duplicadosPrepararDatos(): bool
{
	foreach ([duplicadosDirectorioDatos(), duplicadosDirectorioTrabajos()] as $directorio):
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
	if (!is_file($ruta)):
		return false;
	endif;

	return rutaDentroDeDirectorio($ruta, $base);
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
	imagedestroy($mini);

	$hex = '';
	for ($i = 0; $i < 64; $i += 4):
		$hex .= dechex(bindec(substr($bits, $i, 4)));
	endfor;

	return $hex;
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
			imagedestroy($imagen);
		endif;
	elseif ($tipo === 'vid'):
		$info = function_exists('obtenerInformacionVideoExtraccion')
			? obtenerInformacionVideoExtraccion($ruta)
			: [];
		$firma['ancho'] = (int) ($info['ancho'] ?? 0);
		$firma['alto'] = (int) ($info['alto'] ?? 0);
		$firma['duracion'] = (float) ($info['duracion'] ?? 0.0);
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
		&& (string) ($fila['sha256'] ?? '') !== ''
		&& (int) ($fila['firma_version'] ?? 0) >= DUPLICADOS_FIRMA_VERSION;
}

function duplicadosHashLocalVigente(PDO $pdo, string $ruta): ?array
{
	$stmt = $pdo->prepare('SELECT * FROM archivos_hash WHERE ruta = :ruta LIMIT 1');
	$stmt->execute([':ruta' => duplicadosNormalizarRutaLocal($ruta)]);
	$fila = $stmt->fetch(PDO::FETCH_ASSOC);
	if (!is_array($fila) || !duplicadosFilaLocalVigente($fila)):
		return null;
	endif;

	return $fila;
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
		$resumen['indexados']++;
		if ((string) ($fila['error'] ?? '') !== ''):
			$resumen['errores']++;
		endif;
		if (duplicadosFilaLocalVigente($fila)):
			$resumen['vigentes']++;
		else:
			$resumen['stale']++;
		endif;
		$resumen['actualizado'] = max($resumen['actualizado'], (int) ($fila['actualizado'] ?? 0));
	endforeach;

	return $resumen;
}

function duplicadosValorNumericoProfundo(mixed $valor, array $campos): float
{
	if (!is_array($valor)):
		return 0.0;
	endif;

	$buscados = array_map(
		fn($campo) => preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $campo, 'UTF-8')),
		$campos
	);
	foreach ($valor as $clave => $contenido):
		$claveNormalizada = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $clave, 'UTF-8'));
		if (in_array($claveNormalizada, $buscados, true) && is_numeric($contenido)):
			return (float) $contenido;
		endif;
	endforeach;
	foreach ($valor as $contenido):
		if (is_array($contenido)):
			$encontrado = duplicadosValorNumericoProfundo($contenido, $campos);
			if ($encontrado > 0):
				return $encontrado;
			endif;
		endif;
	endforeach;

	return 0.0;
}

function duplicadosTimestamp(mixed $valor): int
{
	if (is_numeric($valor)):
		return max(0, (int) $valor);
	endif;
	$timestamp = strtotime((string) $valor);
	return $timestamp !== false ? $timestamp : 0;
}

function duplicadosAgregarItemYandex(array &$items, array $entrada): void
{
	$md5 = strtolower(trim((string) ($entrada['md5'] ?? '')));
	$sha256 = strtolower(trim((string) ($entrada['sha256'] ?? '')));
	if ($md5 === '' && $sha256 === ''):
		return;
	endif;

	$ruta = normalizarRutaYandexDisk($entrada['ruta'] ?? '');
	if ($ruta === '/'):
		return;
	endif;

	$tamano = isset($entrada['tamano']) && is_numeric($entrada['tamano']) ? (int) $entrada['tamano'] : null;
	$exif = is_array($entrada['exif'] ?? null) ? $entrada['exif'] : [];
	$ancho = (int) (($entrada['ancho'] ?? 0) ?: duplicadosValorNumericoProfundo($exif, ['width', 'imagewidth', 'exifimagewidth', 'pixelxdimension']));
	$alto = (int) (($entrada['alto'] ?? 0) ?: duplicadosValorNumericoProfundo($exif, ['height', 'imageheight', 'exifimageheight', 'pixelydimension']));
	$duracion = (float) (($entrada['duracion'] ?? 0) ?: duplicadosValorNumericoProfundo($exif, ['duration', 'duracion']));
	$modificadoOriginal = (string) ($entrada['modificado'] ?? '');
	$items['yandex:' . $ruta] = [
		'id' => 'yandex:' . $ruta,
		'origen' => 'yandex',
		'origen_etiqueta' => 'Yandex.Disk',
		'ruta' => $ruta,
		'nombre' => (string) ($entrada['nombre'] ?? basename($ruta)),
		'tipo' => (string) ($entrada['media_type'] ?? $entrada['tipo'] ?? ''),
		'mime' => (string) ($entrada['mime'] ?? ''),
		'tamano' => $tamano,
		'tamano_legible' => yandexDiskFormatoTamano($tamano),
		'modificado' => formatearFechaYandexDisk($modificadoOriginal),
		'modificado_ts' => duplicadosTimestamp($modificadoOriginal),
		'md5' => $md5,
		'sha256' => $sha256,
		'ancho' => $ancho,
		'alto' => $alto,
		'duracion' => $duracion,
		'contenido_hash' => strtolower((string) ($entrada['contenido_hash'] ?? '')),
		'perceptual_hash' => strtolower((string) ($entrada['perceptual_hash'] ?? '')),
		'exif' => $exif,
		'url' => urlPanelYandexDisk(dirname($ruta) ?: '/'),
		'url_externa' => yandexDiskUrlCliente($ruta),
	];
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

function duplicadosExtraerRecursosCacheYandex(array $datos): array
{
	$recursos = [];
	if (($datos['type'] ?? '') === 'file'):
		$recursos[] = $datos;
	endif;

	foreach ([
		$datos['_embedded']['items'] ?? null,
		$datos['items'] ?? null,
		$datos['resources'] ?? null,
	] as $items):
		if (!is_array($items)):
			continue;
		endif;
		foreach ($items as $item):
			if (is_array($item)):
				$recursos[] = $item;
			endif;
		endforeach;
	endforeach;

	return $recursos;
}

function duplicadosObtenerYandexCache(): array
{
	static $cache = null;
	if ($cache !== null):
		return $cache;
	endif;

	$items = [];
	$actualizado = 0;
	$indice = rutaArchivoIndiceYandexDisk();
	if (is_file($indice)):
		$actualizado = max($actualizado, (int) filemtime($indice));
		$datos = json_decode((string) file_get_contents($indice), true);
		if (is_array($datos) && (int) ($datos['version'] ?? 0) === YANDEX_DISK_CACHE_VERSION):
			foreach ((array) ($datos['resources'] ?? []) as $entrada):
				if (is_array($entrada)):
					duplicadosAgregarItemYandex($items, $entrada);
				endif;
			endforeach;
		endif;
	endif;

	foreach (glob(rutaDirectorioCacheYandexDisk() . DIRECTORY_SEPARATOR . 'resources*.json') ?: [] as $archivo):
		if (!is_file($archivo)):
			continue;
		endif;
		$actualizado = max($actualizado, (int) filemtime($archivo));
		$payload = json_decode((string) file_get_contents($archivo), true);
		if (!is_array($payload) || (int) ($payload['version'] ?? 0) !== YANDEX_DISK_CACHE_VERSION):
			continue;
		endif;
		$data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
		foreach (duplicadosExtraerRecursosCacheYandex($data) as $recurso):
			$entrada = yandexDiskExtraerEntradaIndice($recurso);
			if ($entrada !== null):
				duplicadosAgregarItemYandex($items, $entrada);
			endif;
		endforeach;
	endforeach;

	ksort($items);
	$cache = [
		'items' => array_values($items),
		'total' => count($items),
		'actualizado' => $actualizado,
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

	$anchoA = (int) ($a['ancho'] ?? 0);
	$altoA = (int) ($a['alto'] ?? 0);
	$anchoB = (int) ($b['ancho'] ?? 0);
	$altoB = (int) ($b['alto'] ?? 0);
	if ($anchoA > 0 && $altoA > 0 && $anchoB > 0 && $altoB > 0):
		if ($anchoA === $anchoB && $altoA === $altoB):
			$soporte += 22;
			$soportePerceptual += 22;
			$razones[] = 'Mismas dimensiones';
		elseif ($anchoA === $altoB && $altoA === $anchoB):
			$soporte += 16;
			$soportePerceptual += 16;
			$razones[] = 'Dimensiones rotadas';
		endif;
	endif;

	$duracionA = (float) ($a['duracion'] ?? 0);
	$duracionB = (float) ($b['duracion'] ?? 0);
	if ($duracionA > 0 && $duracionB > 0):
		$diferencia = abs($duracionA - $duracionB);
		$referencia = max($duracionA, $duracionB);
		if ($diferencia <= 1):
			$soporte += 30;
			$soportePerceptual += 30;
			$razones[] = 'Duración casi idéntica';
		elseif ($diferencia <= 3):
			$soporte += 22;
			$soportePerceptual += 22;
			$razones[] = 'Duración muy cercana';
		elseif ($referencia > 0 && ($diferencia / $referencia) <= 0.02):
			$soporte += 14;
			$soportePerceptual += 14;
			$razones[] = 'Duración compatible';
		endif;
	endif;

	$tamanoA = (int) ($a['tamano'] ?? 0);
	$tamanoB = (int) ($b['tamano'] ?? 0);
	if ($tamanoA > 0 && $tamanoB > 0):
		$diferenciaTamano = abs($tamanoA - $tamanoB) / max($tamanoA, $tamanoB);
		if ($diferenciaTamano <= 0.000001):
			$soporte += 26;
			$soportePerceptual += 26;
			$razones[] = 'Mismo tamaño';
		elseif ($diferenciaTamano <= 0.01):
			$soporte += 18;
			$soportePerceptual += 18;
			$razones[] = 'Tamaño casi igual';
		elseif ($diferenciaTamano <= 0.05):
			$soporte += 10;
			$soportePerceptual += 10;
			$razones[] = 'Tamaño similar';
		endif;
	endif;

	$nombreA = duplicadosNombreNormalizado($a);
	$nombreB = duplicadosNombreNormalizado($b);
	if ($nombreA !== '' && $nombreB !== ''):
		if ($nombreA === $nombreB):
			$soporte += 35;
			$soportePerceptual += 35;
			$razones[] = 'Nombre base igual';
		else:
			similar_text($nombreA, $nombreB, $porcentaje);
			if ($porcentaje >= 92):
				$soporte += 24;
				$soportePerceptual += 24;
				$razones[] = 'Nombre muy parecido';
			elseif ($porcentaje >= 80):
				$soporte += 14;
				$soportePerceptual += 14;
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
		$soporte += 4;
		$razones[] = 'Misma extensión';
	endif;

	if ($scoreVisual > 0):
		$score = min(94, $scoreVisual + min(24, intdiv($soportePerceptual, 4)));
		$metodo = 'Perceptual';
	else:
		$score = min(84, $soporte);
		$metodo = 'Heurístico';
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
	$buckets[$clave][] = $indice;
}

function duplicadosBucketsItem(array $item, int $indice, array &$buckets): void
{
	$perceptual = strtolower(trim((string) ($item['perceptual_hash'] ?? '')));
	if ($perceptual !== ''):
		duplicadosAgregarBucket($buckets, 'dhash:' . substr($perceptual, 0, 4), $indice);
	endif;

	$nombre = duplicadosNombreNormalizado($item);
	if (mb_strlen($nombre, 'UTF-8') >= 4):
		duplicadosAgregarBucket($buckets, 'nombre:' . duplicadosTipoVistaPrevia($item) . ':' . $nombre, $indice);
	endif;

	$ancho = (int) ($item['ancho'] ?? 0);
	$alto = (int) ($item['alto'] ?? 0);
	if ($ancho > 0 && $alto > 0):
		duplicadosAgregarBucket($buckets, 'dim:' . duplicadosTipoVistaPrevia($item) . ':' . $ancho . 'x' . $alto, $indice);
	endif;

	$duracion = (float) ($item['duracion'] ?? 0);
	if ($duracion > 0 && $ancho > 0 && $alto > 0):
		duplicadosAgregarBucket($buckets, 'video:' . $ancho . 'x' . $alto . ':' . (int) round($duracion), $indice);
	endif;

	$tamano = (int) ($item['tamano'] ?? 0);
	if ($tamano > 0):
		duplicadosAgregarBucket($buckets, 'tamano:' . duplicadosTipoVistaPrevia($item) . ':' . $tamano, $indice);
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

function duplicadosGrupoDesdeIndices(array $items, array $indices, int $score, string $metodo, string $hash, array $razones): array
{
	$itemsGrupo = array_map(fn($indice) => $items[(int) $indice], array_values(array_unique($indices)));
	$origenes = array_values(array_unique(array_map(fn($item) => (string) ($item['origen'] ?? ''), $itemsGrupo)));
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
		'cruzado' => in_array('local', $origenes, true) && in_array('yandex', $origenes, true),
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

function duplicadosPaginaDesdeDescriptores(array $items, array $descriptores, int $limite, int $offset): array
{
	duplicadosOrdenarDescriptores($descriptores);
	if ($limite > 0):
		$descriptores = array_slice($descriptores, $offset, $limite);
	elseif ($offset > 0):
		$descriptores = array_slice($descriptores, $offset);
	endif;

	return duplicadosGruposDesdeDescriptores($items, $descriptores);
}

function duplicadosConstruirGrupos(?string $base = null, int $limite = 200, int $offset = 0): array
{
	$limite = max(0, $limite);
	$offset = max(0, $offset);
	$paginaNecesaria = $limite > 0 ? $offset + $limite : PHP_INT_MAX;
	$items = array_values(array_merge(
		duplicadosObtenerLocalesIndexados($base),
		duplicadosObtenerYandexCache()['items']
	));
	$total = count($items);
	if ($total < 2):
		return [];
	endif;

	$buckets = [];
	foreach ($items as $indice => $item):
		duplicadosBucketsItem($item, $indice, $buckets);
	endforeach;

	$descriptores = [];
	$exactos = [];
	$visuales = [];
	$primerosExactos = [];
	$primerosVisuales = [];
	$exactoPorIndice = [];
	$visualPorIndice = [];
	foreach ($items as $indice => $item):
		$sha = strtolower(trim((string) ($item['sha256'] ?? '')));
		$md5 = strtolower(trim((string) ($item['md5'] ?? '')));
		$contenido = strtolower(trim((string) ($item['contenido_hash'] ?? '')));
		if ($sha !== ''):
			$clave = 'SHA-256 exacto|' . $sha;
			duplicadosRegistrarClaveGrupo($clave, $indice, $primerosExactos, $exactos);
		elseif ($md5 !== ''):
			$clave = 'MD5 exacto|' . $md5;
			duplicadosRegistrarClaveGrupo($clave, $indice, $primerosExactos, $exactos);
		endif;
		if ($contenido !== ''):
			$clave = 'Contenido visual|' . $contenido;
			duplicadosRegistrarClaveGrupo($clave, $indice, $primerosVisuales, $visuales);
		endif;
	endforeach;
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
	if ($limite > 0 && count($descriptores) >= $paginaNecesaria):
		return duplicadosPaginaDesdeDescriptores($items, $descriptores, $limite, $offset);
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
	if ($limite > 0 && count($descriptores) >= $paginaNecesaria):
		return duplicadosPaginaDesdeDescriptores($items, $descriptores, $limite, $offset);
	endif;

	$probablesPorScore = [];
	$totalProbables = 0;
	$paresCandidatos = [];
	$ventanaBusqueda = $limite > 0 ? $offset + $limite : DUPLICADOS_CANDIDATOS_PROBABLES_MAX;
	$maxProbables = max(DUPLICADOS_CANDIDATOS_PROBABLES_MAX, $ventanaBusqueda * 10);
	foreach ($buckets as $claveBucket => $indices):
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
	if ($limite > 0):
		$descriptores = array_slice($descriptores, $offset, $limite);
	elseif ($offset > 0):
		$descriptores = array_slice($descriptores, $offset);
	endif;

	return duplicadosGruposDesdeDescriptores($items, $descriptores);
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
	];
	foreach ($grupos as $grupo):
		$resumen['entradas'] += (int) ($grupo['conteo'] ?? count($grupo['items'] ?? []));
		if (!empty($grupo['cruzado'])):
			$resumen['cruzados']++;
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
			$hashes = duplicadosCalcularHashesArchivo($rutaNormalizada);
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

function duplicadosEstado(array $fuente = [], bool $incluirGrupos = false): array
{
	$base = duplicadosResolverBaseLocal($fuente['ruta'] ?? ($_GET['ruta'] ?? ''));
	$yandex = duplicadosObtenerYandexCache();
	$local = duplicadosResumenLocal($base);
	$grupos = $incluirGrupos ? duplicadosConstruirGrupos($base) : [];
	$trabajo = duplicadosLeerTrabajoActual();

	return [
		'base' => $base,
		'base_rel' => rutaRelativaParaParametro($base),
		'yandex' => [
			'total' => (int) ($yandex['total'] ?? 0),
			'actualizado' => (int) ($yandex['actualizado'] ?? 0),
		],
		'local' => $local,
		'grupos' => $grupos,
		'resumen' => $incluirGrupos ? duplicadosResumenGrupos($grupos) : duplicadosResumenVacio(true),
		'job' => $trabajo,
	];
}

function duplicadosEstadoPaginaGrupos(array $fuente = [], int $offset = 0, int $limite = DUPLICADOS_GRUPOS_POR_PAGINA): array
{
	$limite = max(1, min(100, $limite));
	$offset = max(0, $offset);
	$estado = duplicadosEstado($fuente, false);
	$grupos = duplicadosConstruirGrupos((string) ($estado['base'] ?? ''), $limite + 1, $offset);
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
			],
		]
	);
}

function renderizarPanelDuplicados(array $estado): string
{
	$job = is_array($estado['job'] ?? null) ? $estado['job'] : null;
	$activo = duplicadosTrabajoActivo($job);
	$mensaje = $job ? (string) ($job['mensaje'] ?? '') : 'Sin trabajo activo.';
	$local = $estado['local'] ?? [];
	$yandex = $estado['yandex'] ?? [];
	$resumen = $estado['resumen'] ?? [];
	$resumenTexto = !empty($resumen['pendiente'])
		? 'Grupos: carga asíncrona'
		: 'Grupos: ' . (int) ($resumen['grupos'] ?? 0) . ' · Exactos: ' . (int) ($resumen['exactos'] ?? 0) . ' · Probables: ' . (int) ($resumen['probables'] ?? 0);

	return
		'<div class="duplicados-panel">' .
		'<div class="duplicados-panel-resumen">' .
		'<strong>Duplicados</strong>' .
		'<span>Local: ' . (int) ($local['vigentes'] ?? 0) . ' con firma</span>' .
		'<span>Yandex cache: ' . (int) ($yandex['total'] ?? 0) . ' archivos</span>' .
		'<span>' . escaparHtml($resumenTexto) . '</span>' .
		'</div>' .
		'<div class="duplicados-panel-estado' . ($activo ? ' activo' : '') . '">' . escaparHtml($mensaje) . '</div>' .
		'</div>';
}

function renderizarControlesDuplicados(array $estado): string
{
	$baseRel = (string) ($estado['base_rel'] ?? '');
	$resumen = $estado['resumen'] ?? [];
	$local = $estado['local'] ?? [];
	$yandex = $estado['yandex'] ?? [];
	$actualizadoLocal = (int) ($local['actualizado'] ?? 0);
	$actualizadoYandex = (int) ($yandex['actualizado'] ?? 0);
	$partes = !empty($resumen['pendiente'])
		? [
			'grupos por cargar',
			(int) ($local['vigentes'] ?? 0) . ' locales con firma',
			(int) ($yandex['total'] ?? 0) . ' de Yandex cache',
		]
		: [
			(int) ($resumen['grupos'] ?? 0) . ' grupos',
			(int) ($resumen['exactos'] ?? 0) . ' exactos',
			(int) ($resumen['probables'] ?? 0) . ' probables',
			(int) ($resumen['cruzados'] ?? 0) . ' local/Yandex',
			(int) ($local['vigentes'] ?? 0) . ' locales con firma',
			(int) ($yandex['total'] ?? 0) . ' de Yandex cache',
		];
	if ($actualizadoLocal > 0):
		$partes[] = 'Local ' . duplicadosFormatearFecha($actualizadoLocal);
	endif;
	if ($actualizadoYandex > 0):
		$partes[] = 'Yandex ' . duplicadosFormatearFecha($actualizadoYandex);
	endif;

	return
		'<div class="filtros-metadatos duplicados-controles">' .
		'<span class="filtros-metadatos-resumen">' . escaparHtml(implode(' · ', $partes)) . '</span>' .
		'<form method="get" class="duplicados-ruta-form">' .
		'<input type="hidden" name="panel" value="duplicados">' .
		'<label for="duplicados-ruta">Ruta local<input id="duplicados-ruta" type="text" name="ruta" value="' . escaparHtml($baseRel) . '" autocomplete="off"></label>' .
		'<button type="submit">Cambiar ruta</button>' .
		'</form>' .
		'</div>';
}

function renderizarVistaDuplicados(array $estado): string
{
	$base = (string) ($estado['base'] ?? '');
	$baseRel = (string) ($estado['base_rel'] ?? '');
	$job = is_array($estado['job'] ?? null) ? $estado['job'] : null;
	$total = (int) ($job['total'] ?? 0);
	$procesados = (int) ($job['procesados'] ?? 0);
	$mensaje = $job ? (string) ($job['mensaje'] ?? '') : 'Índice local listo.';
	$activo = duplicadosTrabajoActivo($job);

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
		'</div>' .
		'<div class="duplicados-progreso">' .
		'<progress id="duplicados-progreso" max="' . max(1, $total) . '" value="' . max(0, $procesados) . '"></progress>' .
		'<span id="duplicados-mensaje">' . escaparHtml($mensaje) . '</span>' .
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
		$tipo = (string) ($grupo['tipo'] ?? '');
		$hash = (string) ($grupo['hash'] ?? '');
		$score = (int) ($grupo['score'] ?? 100);
		$cruzado = !empty($grupo['cruzado']);
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
		$html .=
			'<details class="duplicados-grupo' . ($cruzado ? ' duplicados-grupo-cruzado' : '') . '" open data-duplicados-busqueda="' . escaparHtml($busqueda) . '" data-duplicado-grupo="1">' .
			'<summary>' .
			'<span class="duplicados-grupo-titulo">' . count($items) . ' archivos' . ($cruzado ? ' · Local/Yandex' : '') . '</span>' .
			'<span class="duplicados-score duplicados-score-' . ($score >= 100 ? 'exacto' : 'probable') . '">Score ' . $score . ' · ' . escaparHtml($etiquetaScore) . '</span>' .
			'<code title="' . escaparHtml($hash !== '' ? $hash : $textoHash) . '">' . escaparHtml($textoHash) . '</code>' .
			'</summary>' .
			'<div class="duplicados-hashes">' .
			(!empty($md5s) ? '<span>MD5: <code>' . escaparHtml(duplicadosHashCorto(implode(', ', $md5s), 40)) . '</code></span>' : '') .
			(!empty($sha256s) ? '<span>SHA-256: <code>' . escaparHtml(duplicadosHashCorto(implode(', ', $sha256s), 48)) . '</code></span>' : '') .
			(!empty($razones) ? '<span>Razones: ' . escaparHtml(implode(' · ', $razones)) . '</span>' : '') .
			'</div>' .
			'<div class="duplicados-grupo-acciones">' .
			'<button type="button" data-duplicado-descartar-grupo disabled>' . escaparHtml($accionGrupo) . '</button>' .
			'<span data-duplicado-seleccion-resumen>0 seleccionados</span>' .
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
				'<button type="button" data-duplicado-seleccionar aria-pressed="false">Seleccionar para borrar</button>' .
				'</div>' .
				'</div>';
		endforeach;
		$html .= '</div></details>';
	endforeach;

	return $html;
}
