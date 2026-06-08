<?php
declare(strict_types=1);

mb_internal_encoding('UTF-8');
ini_set('pcre.jit', '0');
set_time_limit(300);
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

$ROOT = __DIR__ . '/imgs';
$EXIF = '/opt/homebrew/bin/exiftool';

if (!class_exists('Normalizer')) {
	die('❌ Extensión intl (Normalizer) no disponible');
}

/* -------- TAGS A NORMALIZAR -------- */

$TAGS = [
	'XMP-dc:Subject'        => 'array',
	'Copyright'             => 'string',
	'XMP-mwg-rs:RegionName' => 'array',
	'Title'                 => 'string',
	'Description'           => 'string',
];

/* -------- UTILIDADES -------- */

function exifRead(string $file, string $tag): string {
	global $EXIF;
	$out = shell_exec(
		$EXIF.' -s -s -s '.escapeshellarg('-'.$tag).' '.escapeshellarg($file)
	);
	return trim((string)$out);
}

function exifArg(string $tag, string $value): string {
	return escapeshellarg('-'.$tag.'='.$value);
}

function splitValues(string $raw): array {
	return array_map(
		'trim',
		preg_split('/[.,\n]/u', $raw, -1, PREG_SPLIT_NO_EMPTY)
	);
}

function normalize(string $s): string {
	$normalizado = Normalizer::normalize($s, Normalizer::FORM_C);
	return is_string($normalizado) ? $normalizado : $s;
}

function unicodeMetadataFiles(string $root): Generator {
	if (!is_dir($root)) {
		return;
	}

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
	);

	foreach ($it as $f) {
		if ($f->isFile() && preg_match('/\.(jpe?g|webp|mp4)$/i', $f->getFilename())) {
			yield $f->getPathname();
		}
	}
}

/* -------- CONTAR ARCHIVOS -------- */

$totalFiles = 0;
foreach (unicodeMetadataFiles($ROOT) as $_) {
	$totalFiles++;
}

/* -------- HTML INICIAL -------- */

echo <<<HTML
<!doctype html>
<meta charset="utf-8">
<title>Normalización Unicode en metadatos</title>
<style>
body{font-family:system-ui;margin:2em}
progress{width:100%;height:20px}
.scan{color:#555}
.fix{color:#b60}
.ok{color:green}
code{font-family:monospace}
</style>

<h1>Escaneo y corrección Unicode</h1>

<p>Archivos totales: <strong>$totalFiles</strong></p>

<progress id="bar" max="$totalFiles" value="0"></progress>
<div id="status">Iniciando…</div>
<hr>
HTML;

$processed = 0;
$fixed = 0;

/* -------- PROCESO PRINCIPAL -------- */

foreach (unicodeMetadataFiles($ROOT) as $file) {
	set_time_limit(30);
	$processed++;

	echo "<div class='scan'>🔍 ".htmlspecialchars($file, ENT_QUOTES, 'UTF-8')."</div>";

	$changed = false;

	foreach ($TAGS as $tag => $type) {
		$raw = exifRead($file, $tag);
		if ($raw === '') continue;

		if ($type === 'string') {
			$norm = normalize($raw);
			if ($norm !== $raw) {
				shell_exec(
					$EXIF.' -P -overwrite_original '.exifArg($tag, $norm).' '
					.escapeshellarg($file)
				);
				$changed = true;
			}
		} else {
			$vals = splitValues($raw);
			$fixedVals = [];
			$tagChanged = false;
			foreach ($vals as $v) {
				$n = normalize($v);
				if ($n !== $v) $tagChanged = true;
				$fixedVals[] = $n;
			}
			$fixedVals = array_values(array_unique($fixedVals));

			if ($tagChanged) {
				shell_exec(
					$EXIF.' -P -overwrite_original '.exifArg($tag, '').' '
					.escapeshellarg($file)
				);
				$args = '';
				foreach ($fixedVals as $v) {
					$args .= ' '.escapeshellarg('-'.$tag.'+='.$v);
				}
				shell_exec(
					$EXIF.' -P -overwrite_original '.$args.' '
					.escapeshellarg($file)
				);
				$changed = true;
			}
		}
	}

	if ($changed) {
		$fixed++;
		echo "<div class='fix'>✔ Reparado</div>";
	}

	echo "<script>
		document.getElementById('bar').value = $processed;
		document.getElementById('status').textContent =
			'Procesados: $processed / $totalFiles · Reparados: $fixed';
	</script>";

	flush();
}

/* -------- FINAL -------- */

echo <<<HTML
<hr>
<p class="ok">✔ Proceso finalizado</p>
<p>Total procesados: <strong>$processed</strong></p>
<p>Archivos corregidos: <strong>$fixed</strong></p>
HTML;

?>
