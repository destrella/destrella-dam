<?php
ini_set("pcre.jit", "0");
require_once "src/funciones.php";

$pdo = conectarBaseNombres();
if (!$pdo):
	http_response_code(500);
	die("No se pudo abrir la base de nombres.");
endif;

function h($valor): string
{
	return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function normalizarValoresMultilinea(string $texto): array
{
	$valores = preg_split('/\R+/', $texto) ?: [];
	$valores = array_map(static fn($valor) => trim((string) $valor), $valores);
	$valores = array_filter($valores, static fn($valor) => $valor !== '');
	return array_values(array_unique($valores));
}

function normalizarUsuariosFormulario(string $texto): array
{
	$valores = preg_split('/[\r\n,]+/', $texto) ?: [];
	$usuarios = [];
	$vistos = [];
	foreach ($valores as $valor):
		$usuario = trim((string) $valor, " \t\n\r\0\x0B@");
		if ($usuario === ''):
			continue;
		endif;
		$clave = strtolower($usuario);
		if (isset($vistos[$clave])):
			continue;
		endif;
		$vistos[$clave] = true;
		$usuarios[] = $usuario;
	endforeach;
	return $usuarios;
}

function usuariosDeIdentidad(PDO $pdo, int $id): array
{
	$stmt = $pdo->prepare("
		SELECT usuario
		FROM nombres_usuario
		WHERE identidad_id = ?
		ORDER BY lower(usuario), usuario
	");
	$stmt->execute([$id]);
	return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'usuario');
}

function cargarIdentidad(PDO $pdo, int $id): ?array
{
	$stmt = $pdo->prepare("SELECT * FROM identidades WHERE id = ?");
	$stmt->execute([$id]);
	$fila = $stmt->fetch(PDO::FETCH_ASSOC);
	if (!$fila):
		return null;
	endif;
	$fila['nombres'] = decodificarNombresSugeridos($fila['nombres_json']);
	$fila['usuarios'] = usuariosDeIdentidad($pdo, $id);
	return $fila;
}

function eliminarIdentidadesVacias(PDO $pdo): void
{
	$pdo->exec("
		DELETE FROM identidades
		WHERE id NOT IN (
			SELECT DISTINCT identidad_id
			FROM nombres_usuario
		)
	");
}

function guardarIdentidad(PDO $pdo, int $id, array $usuarios, array $nombres): int
{
	if (empty($usuarios)):
		throw new RuntimeException('Agrega al menos un usuario o alias.');
	endif;
	if (empty($nombres)):
		throw new RuntimeException('Agrega al menos un nombre sugerido.');
	endif;

	$json = json_encode(array_values($nombres), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$pdo->beginTransaction();
	try {
		$stmt = $pdo->prepare("SELECT id FROM identidades WHERE nombres_json = ?");
		$stmt->execute([$json]);
		$identidadExistente = (int) ($stmt->fetchColumn() ?: 0);

		if ($identidadExistente > 0):
			$identidadId = $identidadExistente;
			$pdo->prepare("UPDATE identidades SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$identidadId]);
		elseif ($id > 0):
			$pdo->prepare("
				UPDATE identidades
				SET nombres_json = ?, updated_at = CURRENT_TIMESTAMP
				WHERE id = ?
			")->execute([$json, $id]);
			$identidadId = $id;
		else:
			$pdo->prepare("INSERT INTO identidades (nombres_json) VALUES (?)")->execute([$json]);
			$identidadId = (int) $pdo->lastInsertId();
		endif;

		if ($id > 0 && $identidadId === $id):
			$pdo->prepare("DELETE FROM nombres_usuario WHERE identidad_id = ?")->execute([$identidadId]);
		elseif ($id > 0 && $identidadId !== $id):
			$pdo->prepare("DELETE FROM nombres_usuario WHERE identidad_id = ?")->execute([$id]);
			$pdo->prepare("DELETE FROM identidades WHERE id = ?")->execute([$id]);
		endif;

		$stmtUsuario = $pdo->prepare("
			INSERT INTO nombres_usuario (usuario, identidad_id)
			VALUES (?, ?)
			ON CONFLICT(usuario) DO UPDATE SET
				identidad_id = excluded.identidad_id,
				updated_at = CURRENT_TIMESTAMP
		");
		foreach ($usuarios as $usuario):
			$stmtUsuario->execute([$usuario, $identidadId]);
		endforeach;

		eliminarIdentidadesVacias($pdo);
		$pdo->commit();
		return $identidadId;
	} catch (Throwable $e) {
		$pdo->rollBack();
		throw $e;
	}
}

function normalizarPerPageNombres(mixed $valor): int
{
	$opciones = [10, 25, 50, 100];
	$perPage = (int) $valor;
	return in_array($perPage, $opciones, true) ? $perPage : 25;
}

function paginaIdentidad(PDO $pdo, int $id, int $perPage): int
{
	$stmt = $pdo->query("
		SELECT
			i.id,
			(SELECT MIN(lower(u.usuario)) FROM nombres_usuario u WHERE u.identidad_id = i.id) AS orden
		FROM identidades i
		WHERE EXISTS (
			SELECT 1
			FROM nombres_usuario u
			WHERE u.identidad_id = i.id
		)
		ORDER BY orden ASC, i.id ASC
	");
	$posicion = 1;
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $fila):
		if ((int) $fila['id'] === $id):
			return max(1, (int) ceil($posicion / max(1, $perPage)));
		endif;
		$posicion++;
	endforeach;
	return 1;
}

function idElementoIdentidad(int $id): string
{
	return 'identidad-' . $id;
}

$mensaje = $_GET['ok'] ?? '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'):
	$accion = $_POST['accion'] ?? '';
	try {
			if ($accion === 'guardar'):
				$id = (int) ($_POST['id'] ?? 0);
				$usuarios = normalizarUsuariosFormulario($_POST['usuarios'] ?? '');
				$nombres = normalizarValoresMultilinea($_POST['nombres'] ?? '');
				$identidadId = guardarIdentidad($pdo, $id, $usuarios, $nombres);
				$perPageDestino = normalizarPerPageNombres($_POST['perPage'] ?? $_GET['perPage'] ?? 25);
				$paginaDestino = paginaIdentidad($pdo, $identidadId, $perPageDestino);
				$query = http_build_query([
					'page' => $paginaDestino,
					'perPage' => $perPageDestino,
					'ok' => 'guardado',
					'destacado' => $identidadId,
				]);
				header('Location: nombres.php?' . $query . '#' . idElementoIdentidad($identidadId));
				exit;
		elseif ($accion === 'eliminar_identidad'):
			$id = (int) ($_POST['id'] ?? 0);
			if ($id > 0):
				$pdo->prepare("DELETE FROM identidades WHERE id = ?")->execute([$id]);
			endif;
			header('Location: nombres.php?ok=eliminado');
			exit;
		elseif ($accion === 'eliminar_usuario'):
			$usuario = trim($_POST['usuario'] ?? '');
			$id = (int) ($_POST['id'] ?? 0);
			if ($usuario !== ''):
				$pdo->prepare("DELETE FROM nombres_usuario WHERE usuario = ?")->execute([$usuario]);
				eliminarIdentidadesVacias($pdo);
			endif;
			$destino = $id > 0 && cargarIdentidad($pdo, $id) ? '?edit=' . $id . '&ok=alias-eliminado' : '?ok=alias-eliminado';
			header('Location: nombres.php' . $destino);
			exit;
		endif;
	} catch (Throwable $e) {
		$error = $e->getMessage();
	}
endif;

$editData = null;
if (isset($_GET['edit'])):
	$editData = cargarIdentidad($pdo, (int) $_GET['edit']);
endif;

$q = trim($_GET['q'] ?? '');
$perPageOptions = [10, 25, 50, 100];
$perPage = normalizarPerPageNombres($_GET['perPage'] ?? 25);
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = "WHERE EXISTS (SELECT 1 FROM nombres_usuario u WHERE u.identidad_id = i.id)";
$params = [];
if ($q !== ''):
	$where = "
		WHERE i.nombres_json LIKE :q
			OR EXISTS (
				SELECT 1
				FROM nombres_usuario u
				WHERE u.identidad_id = i.id
					AND u.usuario LIKE :q
			)
	";
	$params[':q'] = '%' . $q . '%';
endif;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM identidades i $where");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages):
	$page = $totalPages;
	$offset = ($page - 1) * $perPage;
endif;

$sql = "
	SELECT
		i.id,
		i.nombres_json,
		(SELECT COUNT(*) FROM nombres_usuario u WHERE u.identidad_id = i.id) AS total_usuarios,
		(SELECT MIN(lower(u.usuario)) FROM nombres_usuario u WHERE u.identidad_id = i.id) AS orden
	FROM identidades i
	$where
	ORDER BY orden ASC, i.id ASC
	LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $clave => $valor):
	$stmt->bindValue($clave, $valor, PDO::PARAM_STR);
endforeach;
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$row):
	$row['nombres'] = decodificarNombresSugeridos($row['nombres_json']);
	$row['usuarios'] = usuariosDeIdentidad($pdo, (int) $row['id']);
endforeach;
unset($row);

$formUsuarios = $editData ? implode("\n", $editData['usuarios']) : '';
$formNombres = $editData ? implode("\n", $editData['nombres']) : '';
$destacadoId = (int) ($_GET['destacado'] ?? 0);
$totalIdentidadesGlobal = (int) $pdo->query("
	SELECT COUNT(*)
	FROM identidades i
	WHERE EXISTS (
		SELECT 1
		FROM nombres_usuario u
		WHERE u.identidad_id = i.id
	)
")->fetchColumn();
$formularioAbierto = $editData || $totalIdentidadesGlobal === 0;
?>
<!DOCTYPE html>
<html lang="es"<?php echo atributoTemaConfiguracion(); ?>>
<head>
	<meta charset="UTF-8">
	<title>Nombres</title>
	<link href="estilos.css?v=<?php echo filemtime('estilos.css'); ?>" rel="stylesheet">
	<style>
		html {
			min-height: 100%;
			height: auto;
			overflow: auto;
			font-size: 14px;
		}

		body.nombres-page {
			--nombres-surface: color-mix(in srgb, var(--bg-body) 94%, var(--text-body) 6%);
			--nombres-input-bg: color-mix(in srgb, var(--bg-body) 88%, var(--text-body) 12%);
			--nombres-link: var(--accent-primary);

			min-height: 100%;
			height: auto;
			overflow: auto;
			margin: 0;
			padding: 1rem;
			background: var(--bg-body);
			color: var(--text-body);
			font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
			font-size: 1rem;
			line-height: 1.4;
		}

		.nombres-page,
		.nombres-page * {
			box-sizing: border-box;
		}

		.nombres-page p {
			margin: 0 0 1rem;
		}

		.nombres-layout {
			display: grid;
			grid-template-columns: minmax(18rem, 25rem) minmax(0, 1fr);
			gap: 1rem;
			align-items: start;
		}

		.nombres-panel {
			position: sticky;
			top: 1rem;
		}

		.nombres-panel,
		article.nombres-card,
		.nombres-toolbar {
			width: auto;
			min-width: 0;
			border: 1px solid var(--border-color);
			background: var(--nombres-surface);
			border-radius: 6px;
			box-shadow: none;
		}

		.nombres-panel,
		article.nombres-card {
			padding: .85rem;
		}

		.nombres-panel h1 {
			margin: 0 0 1rem;
			font-size: 2rem;
			line-height: 1.1;
		}

		.nombres-panel form {
			display: grid;
			gap: .65rem;
		}

		.nombres-panel label {
			font-weight: 700;
		}

		.nombres-panel textarea,
		.nombres-toolbar input,
		.nombres-toolbar select {
			width: 100%;
			max-width: 100%;
			margin: 0;
			border: 1px solid var(--border-color);
			border-radius: 4px;
			background: var(--nombres-input-bg);
			color: var(--text-body);
			font: inherit;
		}

		.nombres-panel textarea {
			min-height: 7rem;
			padding: .55rem;
			resize: vertical;
			font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
			font-size: .95rem;
			line-height: 1.35;
		}

		.nombres-form-actions {
			display: flex;
			justify-content: center;
			gap: .5rem;
			margin-top: .35rem;
		}

		.nombres-toolbar {
			display: grid;
			grid-template-columns: minmax(12rem, 1fr) 9rem 2.75rem;
			gap: .5rem;
			align-items: stretch;
			margin-bottom: 1rem;
			padding: .6rem;
		}

		.nombres-toolbar input,
		.nombres-toolbar select {
			min-height: 2.75rem;
			padding: 0 .65rem;
		}

		.nombres-page button,
		.nombres-button,
		.nombres-acciones a {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: .25rem;
			min-height: 2.25rem;
			margin: 0;
			padding: .4rem .65rem;
			border: 1px solid var(--btn-border);
			border-radius: 4px;
			background: var(--btn-bg);
			color: var(--btn-text);
			box-shadow: none;
			text-shadow: none;
			font: inherit;
			font-size: .95rem;
			line-height: 1;
			text-decoration: none;
			cursor: pointer;
		}

		.nombres-page button:hover,
		.nombres-button:hover,
		.nombres-acciones a:hover {
			background: var(--btn-hover-bg);
			border-color: var(--btn-hover-border);
			text-decoration: none;
		}

		.nombres-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr));
			gap: .75rem;
			align-items: stretch;
		}

		article.nombres-card {
			display: flex;
			flex-direction: column;
			gap: .55rem;
			overflow: hidden;
		}

		.nombres-card h2 {
			margin: 0;
			font-size: .95rem;
			line-height: 1.25;
			overflow-wrap: anywhere;
		}

		.nombres-lista {
			display: flex;
			flex-wrap: wrap;
			gap: .35rem;
			margin: 0;
			padding: 0;
			list-style: none;
		}

		.nombres-lista li {
			display: inline-flex;
			align-items: center;
			gap: .35rem;
			max-width: 100%;
			min-width: 0;
			border: 1px solid var(--border-color);
			border-radius: 4px;
			padding: .25rem .3rem .25rem .45rem;
			background: rgba(255, 255, 255, .06);
		}

		.nombres-usuario {
			min-width: 0;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
			font-size: .95rem;
		}

		.nombres-lista form,
		.nombres-acciones form {
			display: inline-flex;
			margin: 0;
		}

		.nombres-lista button {
			width: 1.65rem;
			min-width: 1.65rem;
			height: 1.65rem;
			min-height: 1.65rem;
			padding: 0;
			font-size: 1rem;
			line-height: 1;
		}

		.nombres-acciones {
			display: flex;
			flex-wrap: wrap;
			gap: .4rem;
			align-items: center;
			margin-top: auto;
			padding-top: .35rem;
		}

		.nombres-danger {
			width: 2.25rem;
			padding: 0;
		}

		.nombres-paginacion {
			display: flex;
			flex-wrap: wrap;
			gap: .35rem;
			margin-top: 1rem;
		}

		.nombres-paginacion a,
		.nombres-paginacion span {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			min-width: 2rem;
			min-height: 2rem;
			padding: .2rem .45rem;
			border: 1px solid var(--border-color);
			border-radius: 4px;
		}

		.nombres-paginacion a,
		.nombres-link {
			color: var(--nombres-link);
		}

		.nombres-mensaje,
		.nombres-error {
			margin-bottom: .75rem;
			padding: .6rem .75rem;
			border-radius: 4px;
		}

		.nombres-mensaje {
			background: color-mix(in srgb, var(--outline-success) 20%, transparent);
			border: 1px solid var(--outline-success);
		}

		.nombres-error {
			background: rgba(168, 0, 0, .22);
			border: 1px solid #ff6b6b;
		}

		@media (max-width: 760px) {
			body.nombres-page {
				padding: .75rem;
			}

			.nombres-layout,
			.nombres-toolbar {
				grid-template-columns: 1fr;
			}

			.nombres-panel {
				position: static;
			}
		}

		.nombres-admin .nombres-panel {
			position: static;
			margin-bottom: 1rem;
		}

		.nombres-admin .nombres-panel > summary {
			cursor: pointer;
			font-size: 1.35rem;
			font-weight: 900;
			line-height: 1.1;
		}

		body.nombres-page.configuracion-admin {
			padding: 0;
		}
	</style>
</head>
<body class="nombres-page configuracion-admin">
	<div class="configuracion-layout">
		<?php echo menuConfiguracion('nombres'); ?>
		<main class="configuracion-contenido nombres-admin">

	<?php if ($mensaje): ?>
		<div class="nombres-mensaje"><?php echo h($mensaje); ?></div>
	<?php endif; ?>
	<?php if ($error): ?>
		<div class="nombres-error"><?php echo h($error); ?></div>
	<?php endif; ?>

		<details class="nombres-panel admin-form-details"<?php echo $formularioAbierto ? ' open' : ''; ?>>
			<summary><?php echo $editData ? 'Editar identidad' : 'Agregar identidad'; ?></summary>
				<form method="post">
					<input type="hidden" name="accion" value="guardar">
					<input type="hidden" name="id" value="<?php echo h($editData['id'] ?? '0'); ?>">
					<input type="hidden" name="perPage" value="<?php echo h($perPage); ?>">

				<label for="usuarios">Usuarios / alias</label>
				<textarea id="usuarios" name="usuarios" required placeholder="miina_matsumoto&#10;otro_alias"><?php echo h($formUsuarios); ?></textarea>

				<label for="nombres">Nombres sugeridos</label>
				<textarea id="nombres" name="nombres" required placeholder="松本みいな&#10;Matsumoto Miina"><?php echo h($formNombres); ?></textarea>

				<div class="nombres-form-actions">
					<button type="submit" title="Guardar">💾 Guardar</button>
					<?php if ($editData): ?>
						<a href="nombres.php" class="nombres-button" title="Nuevo">➕ Nuevo</a>
					<?php endif; ?>
				</div>
			</form>
		</details>

		<section>
			<form method="get" class="nombres-toolbar">
				<input type="search" name="q" placeholder="Buscar usuario o nombre" value="<?php echo h($q); ?>">
				<select name="perPage">
					<?php foreach ($perPageOptions as $opt): ?>
						<option value="<?php echo $opt; ?>"<?php echo $opt === $perPage ? ' selected' : ''; ?>><?php echo $opt; ?> por página</option>
					<?php endforeach; ?>
				</select>
				<button type="submit">🔍</button>
			</form>

				<div class="nombres-grid">
					<?php foreach ($rows as $row): ?>
						<?php
						$rowId = (int) $row['id'];
						$esDestacada = $destacadoId === $rowId;
						$editParams = [
							'edit' => $rowId,
							'page' => $page,
							'perPage' => $perPage,
						];
						if ($q !== ''):
							$editParams['q'] = $q;
						endif;
						?>
						<article id="<?php echo h(idElementoIdentidad($rowId)); ?>" class="nombres-card<?php echo $esDestacada ? ' admin-card-destacada' : ''; ?>"<?php echo $esDestacada ? ' tabindex="-1" data-admin-destacado="1"' : ''; ?>>
							<h2><?php echo h(implode(' / ', $row['nombres'])); ?></h2>
						<ul class="nombres-lista">
							<?php foreach ($row['usuarios'] as $usuario): ?>
								<li>
									<span class="nombres-usuario"><?php echo h($usuario); ?></span>
									<form method="post" onsubmit="return confirm('¿Eliminar este alias?')">
										<input type="hidden" name="accion" value="eliminar_usuario">
										<input type="hidden" name="id" value="<?php echo h($row['id']); ?>">
										<input type="hidden" name="usuario" value="<?php echo h($usuario); ?>">
										<button type="submit" title="Eliminar alias">×</button>
									</form>
								</li>
							<?php endforeach; ?>
							</ul>
							<div class="nombres-acciones">
								<a href="nombres.php?<?php echo h(http_build_query($editParams)); ?>" title="Editar">✏️ Editar</a>
							<form method="post" onsubmit="return confirm('¿Eliminar la identidad y todos sus alias?')">
								<input type="hidden" name="accion" value="eliminar_identidad">
								<input type="hidden" name="id" value="<?php echo h($row['id']); ?>">
								<button type="submit" class="nombres-danger" title="Eliminar identidad">🗑️</button>
							</form>
						</div>
					</article>
				<?php endforeach; ?>
			</div>

			<nav class="nombres-paginacion" aria-label="Paginación">
				<?php for ($i = 1; $i <= $totalPages; $i++): ?>
					<?php
					$url = 'nombres.php?page=' . $i . '&perPage=' . $perPage;
					if ($q !== ''):
						$url .= '&q=' . urlencode($q);
					endif;
					?>
					<?php if ($i === $page): ?>
						<span><b><?php echo $i; ?></b></span>
					<?php else: ?>
						<a href="<?php echo h($url); ?>"><?php echo $i; ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</nav>
		</section>
		</main>
	</div>
	<script>
		document.addEventListener('DOMContentLoaded', () => {
			const destacado = document.querySelector('[data-admin-destacado="1"]');
			if (!destacado) return;
			destacado.scrollIntoView({ block: 'center', inline: 'nearest' });
			destacado.focus({ preventScroll: true });
		});
	</script>
	</body>
	</html>
