<?php
require_once __DIR__ . '/src/configuracion.php';

$configuracion = cargarConfiguracion();
$mensaje = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'):
	try {
		$configuracion = guardarConfiguracion([
			'elementos_por_pagina' => $_POST['elementos_por_pagina'] ?? '',
			'tema' => $_POST['tema'] ?? '',
			'estado_arbol' => $_POST['estado_arbol'] ?? '',
			'ruta_archivar' => $_POST['ruta_archivar'] ?? '',
			'yandex_disk_api_key' => $_POST['yandex_disk_api_key'] ?? '',
			'formato_temporal_imagen' => $_POST['formato_temporal_imagen'] ?? '',
			'carpetas_ignoradas' => $_POST['carpetas_ignoradas'] ?? '',
		]);
		$mensaje = 'Configuración guardada.';
	} catch (Throwable $e) {
		$error = $e->getMessage();
	}
endif;

function config_h($valor): string
{
	return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

$carpetasIgnoradas = implode("\n", $configuracion['carpetas_ignoradas'] ?? []);
$formatoTemporalImagen = formatoTemporalImagenConfiguracion($configuracion);
$webpTemporalSoportado = webpTemporalImagenSoportado();
?>
<!DOCTYPE html>
<html lang="es"<?php echo atributoTemaConfiguracion($configuracion); ?>>
<head>
	<meta charset="UTF-8">
	<title>Configuración</title>
	<link href="estilos.css?v=<?php echo filemtime('estilos.css'); ?>" rel="stylesheet">
</head>
<body class="configuracion-page configuracion-admin">
	<div class="configuracion-layout">
		<?php echo menuConfiguracion('configuracion'); ?>

		<main class="configuracion-contenido">
			<section class="configuracion-card">
				<h1>Configuración</h1>

				<?php if ($mensaje !== ''): ?>
					<div class="configuracion-mensaje"><?php echo config_h($mensaje); ?></div>
				<?php endif; ?>
				<?php if ($error !== ''): ?>
					<div class="configuracion-error"><?php echo config_h($error); ?></div>
				<?php endif; ?>

				<form method="post" class="configuracion-form">
					<label>
						<span>Elementos por página</span>
						<input type="number" name="elementos_por_pagina" min="3" max="21" value="<?php echo config_h($configuracion['elementos_por_pagina']); ?>">
					</label>

					<label>
						<span>Tema</span>
						<select name="tema">
							<option value="sistema"<?php echo $configuracion['tema'] === 'sistema' ? ' selected' : ''; ?>>Sistema</option>
							<option value="claro"<?php echo $configuracion['tema'] === 'claro' ? ' selected' : ''; ?>>Claro</option>
							<option value="oscuro"<?php echo $configuracion['tema'] === 'oscuro' ? ' selected' : ''; ?>>Oscuro</option>
						</select>
					</label>

					<label>
						<span>Columna de carpetas</span>
						<select name="estado_arbol">
							<option value="expandido"<?php echo $configuracion['estado_arbol'] === 'expandido' ? ' selected' : ''; ?>>Expandido</option>
							<option value="colapsado"<?php echo $configuracion['estado_arbol'] === 'colapsado' ? ' selected' : ''; ?>>Colapsado</option>
						</select>
					</label>

					<label>
						<span>Ruta para archivar</span>
						<input type="text" name="ruta_archivar" value="<?php echo config_h($configuracion['ruta_archivar']); ?>">
					</label>

					<label>
						<span>API Key de Yandex.Disk</span>
						<input type="password" name="yandex_disk_api_key" autocomplete="off" value="<?php echo config_h($configuracion['yandex_disk_api_key'] ?? ''); ?>" placeholder="OAuth token">
					</label>

					<label>
						<span>Formato de temporales de imagen</span>
						<select name="formato_temporal_imagen">
							<option value="webp"<?php echo $formatoTemporalImagen === 'webp' ? ' selected' : ''; ?><?php echo $webpTemporalSoportado ? '' : ' disabled'; ?>>WebP<?php echo $webpTemporalSoportado ? '' : ' (no disponible)'; ?></option>
							<option value="jpg"<?php echo $formatoTemporalImagen === 'jpg' ? ' selected' : ''; ?>>JPG</option>
						</select>
						<small>Se usa para convertir previews temporales de RAW, HEIC, AVIF y TIFF dentro de .posters.</small>
					</label>

					<label>
						<span>Carpetas a ignorar</span>
						<textarea name="carpetas_ignoradas" rows="8"><?php echo config_h($carpetasIgnoradas); ?></textarea>
					</label>

					<div class="configuracion-acciones">
						<button type="submit">💾 Guardar</button>
					</div>
				</form>

				<section class="configuracion-herramienta">
					<h2>Normalización Unicode de metadatos</h2>
					<p>Escanea la biblioteca multimedia y corrige textos de metadatos que no estén normalizados en Unicode NFC, como nombres, títulos, descripciones, copyright y regiones de caras.</p>
					<a href="fix_unicode_metadata_live_progress.php">Abrir normalizador Unicode</a>
				</section>
			</section>
		</main>
	</div>
</body>
</html>
