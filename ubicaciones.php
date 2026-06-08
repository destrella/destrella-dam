<?php
ini_set("pcre.jit", "0");
require_once __DIR__ . '/src/configuracion.php';
$dbFile = __DIR__.DIRECTORY_SEPARATOR.'datos'.DIRECTORY_SEPARATOR.'ubicaciones.sqlite';

$pdo = new PDO("sqlite:$dbFile");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ================================
   CREAR TABLAS
================================ */

$pdo->exec("
CREATE TABLE IF NOT EXISTS cache_geo (
	cache_key TEXT PRIMARY KEY,
	lat REAL,
	lon REAL,
	Country TEXT,
	CountryCode TEXT,
	State TEXT,
	City TEXT
)");

$pdo->exec("
CREATE TABLE IF NOT EXISTS ubicaciones (
	id TEXT PRIMARY KEY,
	Location TEXT,
	GPSPosition TEXT,
	cache_key TEXT,
	FOREIGN KEY(cache_key) REFERENCES cache_geo(cache_key)
)");

/* ================================
   FUNCIONES AUXILIARES
================================ */

function normalizarCiudad($a){
	return $a['city']
		?? $a['town']
		?? $a['village']
		?? $a['municipality']
		?? $a['suburb']
		?? null;
}

function normalizarEstado($a){
	return $a['state']
		?? $a['province']
		?? $a['region']
		?? $a['state_district']
		?? $a['county']
		?? $a['borough']
		?? null;
}

function h($valor): string
{
	return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function normalizarSortUbicaciones(mixed $valor): string
{
	return in_array($valor, ['Location', 'Country', 'GPSPosition'], true) ? (string) $valor : 'Location';
}

function normalizarPerPageUbicaciones(mixed $valor): int
{
	$opciones = [5, 10, 20, 50, 100];
	$perPage = (int) $valor;
	return in_array($perPage, $opciones, true) ? $perPage : 10;
}

function idElementoUbicacion(string $id): string
{
	$slug = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $id), '-');
	return 'ubicacion-' . ($slug !== '' ? $slug : md5($id));
}

function paginaUbicacion(PDO $pdo, string $id, string $sort, int $perPage): int
{
	$order = match (normalizarSortUbicaciones($sort)) {
		'Country' => "COALESCE(c.Country, '') COLLATE NOCASE ASC, COALESCE(c.State, '') COLLATE NOCASE ASC, COALESCE(c.City, '') COLLATE NOCASE ASC, u.Location COLLATE NOCASE ASC, u.id ASC",
		'GPSPosition' => "u.GPSPosition COLLATE NOCASE ASC, u.Location COLLATE NOCASE ASC, u.id ASC",
		default => "u.Location COLLATE NOCASE ASC, u.id ASC",
	};
	$stmt = $pdo->query("
		SELECT u.id
		FROM ubicaciones u
		JOIN cache_geo c ON u.cache_key = c.cache_key
		ORDER BY $order
	");
	$posicion = 1;
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $fila):
		if ((string) $fila['id'] === $id):
			return max(1, (int) ceil($posicion / max(1, $perPage)));
		endif;
		$posicion++;
	endforeach;
	return 1;
}

/* ================================
   ACCIONES (DELETE / EDIT)
================================ */

if(isset($_GET['delete'])){
	$stmt = $pdo->prepare("DELETE FROM ubicaciones WHERE id = ?");
	$stmt->execute([$_GET['delete']]);
	header("Location: ".$_SERVER['PHP_SELF']);
	exit;
}

$editData = null;
if(isset($_GET['edit'])){
	$stmt = $pdo->prepare("SELECT * FROM ubicaciones WHERE id = ?");
	$stmt->execute([$_GET['edit']]);
	$editData = $stmt->fetch(PDO::FETCH_ASSOC);
}

/* ================================
   PROCESAR FORMULARIO
================================ */

if($_SERVER['REQUEST_METHOD'] === 'POST'){

	$location = trim($_POST['Location'] ?? '');
	$gps = trim($_POST['GPSCoordinates'] ?? '', ' ,');

	if($location && preg_match('/^\s*(-?\d+(\.\d+)?)\s*,\s*(-?\d+(\.\d+)?)\s*$/', $gps, $m)){
		$lat = (float)$m[1];
		$lon = (float)$m[3];

		// 🔥 REDONDEO SOLO PARA CACHE
		$latCache = round($lat, 3);
		$lonCache = round($lon, 3);

		$cacheKey = $latCache . ',' . $lonCache;

		// Buscar si ya existe
		$stmt = $pdo->prepare("SELECT * FROM cache_geo WHERE cache_key = ?");
		$stmt->execute([$cacheKey]);
		$geo = $stmt->fetch(PDO::FETCH_ASSOC);

		if(!$geo){

			$url = "https://nominatim.openstreetmap.org/reverse?"
				 . "format=json"
				 . "&lat=$latCache"
				 . "&lon=$lonCache"
				 . "&addressdetails=1"
				 . "&accept-language=es,en";

			$opts = [
				"http" => [
					"header" => "User-Agent: MiProyectoGeo/1.0\r\n"
				]
			];

			$context = stream_context_create($opts);
			$json = file_get_contents($url, false, $context);

			if($json){

				$data = json_decode($json, true);
				$address = $data['address'] ?? [];

				$country = $address['country'] ?? null;
				$countryCode = strtoupper($address['country_code'] ?? '');
				$state = normalizarEstado($address);
				$city = normalizarCiudad($address);

				$stmt = $pdo->prepare("
					INSERT INTO cache_geo
					(cache_key, lat, lon, Country, CountryCode, State, City)
					VALUES (?, ?, ?, ?, ?, ?, ?)
				");

				$stmt->execute([
					$cacheKey,
					$latCache,
					$lonCache,
					$country,
					$countryCode,
					$state,
					$city
				]);

				$geo = [
					'Country'=>$country,
					'CountryCode'=>$countryCode,
					'State'=>$state,
					'City'=>$city
				];
			}
		}

			if($geo){

				$id = $_POST['id'] ?: md5($location);

			$stmt = $pdo->prepare("
				INSERT OR REPLACE INTO ubicaciones
				(id, Location, GPSPosition, cache_key)
				VALUES (?, ?, ?, ?)
			");

				$stmt->execute([
					$id,
					$location,
					"$lat,$lon", // 👈 VERBATIM
					$cacheKey
				]);
				$sortDestino = normalizarSortUbicaciones($_POST['sort'] ?? $_GET['sort'] ?? 'Location');
				$perPageDestino = normalizarPerPageUbicaciones($_POST['perPage'] ?? $_GET['perPage'] ?? 10);
				$paginaDestino = paginaUbicacion($pdo, (string) $id, $sortDestino, $perPageDestino);
				$query = http_build_query([
					'page' => $paginaDestino,
					'sort' => $sortDestino,
					'perPage' => $perPageDestino,
					'ok' => 'guardado',
					'destacado' => $id,
				]);
				header('Location: ubicaciones.php?' . $query . '#' . idElementoUbicacion((string) $id));
				exit;
			}
		}
	}


/* ================================
   PAGINACIÓN + ORDEN + BÚSQUEDA
================================ */

$allowedSort = ['Location','Country','GPSPosition'];
$sort = normalizarSortUbicaciones($_GET['sort'] ?? 'Location');

// búsqueda
$q = trim($_GET['q'] ?? '');

// tamaño de página
$perPageOptions = [5,10,20,50,100];
$perPage = normalizarPerPageUbicaciones($_GET['perPage'] ?? 10);

// página
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// filtro dinámico
$where = '';
$params = [];

if($q !== ''){
	$where = "WHERE u.Location LIKE :q
			  OR c.Country LIKE :q
			  OR c.State LIKE :q
			  OR c.City LIKE :q";
	$params[':q'] = "%$q%";
}

// total
$stmt = $pdo->prepare("
SELECT COUNT(*)
FROM ubicaciones u
JOIN cache_geo c ON u.cache_key = c.cache_key
$where
");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

// datos
$sql = "
SELECT u.id, u.Location, u.GPSPosition,
	   c.Country, c.CountryCode, c.State, c.City
FROM ubicaciones u
JOIN cache_geo c ON u.cache_key = c.cache_key
$where
ORDER BY $sort ASC
LIMIT $perPage OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$destacadoId = (string) ($_GET['destacado'] ?? '');
$totalUbicacionesGlobal = (int) $pdo->query("SELECT COUNT(*) FROM ubicaciones")->fetchColumn();
$formularioAbierto = $editData || $totalUbicacionesGlobal === 0;

/* ================================
   EXPORT DEFINE()
================================ */
function exportDefine($rows, string $destacadoId = '', string $sort = 'Location', string $q = '', int $perPage = 10, int $page = 1){
/*	$out = "define('UBICACIONES', [\n";
	foreach($rows as $r){
		$out .= "\tmd5('".$r['Location']."') => [\n";
		foreach(['Location','GPSPosition','Country','CountryCode','State','City'] as $k){
			$val = addslashes($r[$k] ?? '');
			$out .= "\t\t'$k'=>'$val',\n";
		}
		$out .= "\t],\n";
	}
	$out .= "]);";
	return $out;
*/
	$html = '';
	foreach ($rows as $r):
		$cc = strtoupper($r['CountryCode'] ?? '');
		$bandera = '<span class="bandera">';
		$i = 1;
		foreach (str_split($cc) as $char):
			$bandera .= mb_chr(127397 + ord($char), 'UTF-8');
			if($i == 2):
				break;
			else:
				$i++;
			endif;
		endforeach;
		$bandera .= '</span>';

		$id = (string) $r['id'];
		$idElemento = idElementoUbicacion($id);
		$destacada = $id !== '' && hash_equals($id, $destacadoId);
		$editParams = [
			'edit' => $id,
			'page' => $page,
			'sort' => $sort,
			'q' => $q,
			'perPage' => $perPage,
		];
		$location = h($r['Location'] ?? '');
		$html .= '<fieldset id="'.h($idElemento).'" class="ubicaciones-card'.($destacada ? ' admin-card-destacada' : '').'"'.($destacada ? ' tabindex="-1" data-admin-destacado="1"' : '').'>'.
			'<legend>'.$bandera.' '.$location.'</legend>'.
			'<div class="ubicaciones-card-actions">'.
			'<a href="?'.h(http_build_query($editParams)).'">✏️ Editar</a>'.
			'<a href="?delete='.h(urlencode($id)).'" onclick="return confirm(\'¿Eliminar?\')">🗑️ Eliminar</a>'.
			'</div>'.
			'<ul>';
		foreach(['Location','GPSPosition','Country','CountryCode','State','City'] as $k):
			$html .= '<li><b>'.h($k).'</b>: '.h($r[$k] ?? '').'</li>';
		endforeach;
		$html .= '</ul>'.

		'</fieldset>';
	endforeach;
	return $html;
}

?>
<!DOCTYPE html>
<html lang="es"<?php echo atributoTemaConfiguracion(); ?>>
<head>
<meta charset="UTF-8">
<title>Ubicaciones</title>
<link href="estilos.css?v=<?php echo filemtime('estilos.css'); ?>" rel="stylesheet">
<style>
html {
	min-height: 100%;
	height: auto;
	overflow: auto;
	font-size: 14px;
}

body.ubicaciones-page {
	--ubicaciones-surface: color-mix(in srgb, var(--bg-body) 94%, var(--text-body) 6%);
	--ubicaciones-input-bg: color-mix(in srgb, var(--bg-body) 88%, var(--text-body) 12%);
	--ubicaciones-muted: color-mix(in srgb, var(--text-body) 70%, var(--bg-body) 30%);
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

.ubicaciones-page,
.ubicaciones-page * {
	box-sizing: border-box;
}

.ubicaciones-nav {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	align-items: center;
	margin: 0 0 1rem;
}

.ubicaciones-link,
.ubicaciones-sort a,
.ubicaciones-card a,
.ubicaciones-paginacion a {
	color: var(--accent-primary);
}

.ubicaciones-link {
	display: inline-flex;
	align-items: center;
	min-height: 2.2rem;
	padding: 0.35rem 0.65rem;
	border: 1px solid currentColor;
	border-radius: 4px;
	text-decoration: none;
}

.ubicaciones-layout {
	display: grid;
	grid-template-columns: minmax(18rem, 25rem) minmax(0, 1fr);
	gap: 1rem;
	align-items: start;
}

.ubicaciones-panel {
	position: sticky;
	top: 1rem;
}

.ubicaciones-panel,
.ubicaciones-toolbar,
.ubicaciones-sort,
.ubicaciones-card {
	border: 1px solid var(--border-color);
	border-radius: 6px;
	background: var(--ubicaciones-surface);
	box-shadow: 0 2px 10px var(--shadow-color);
}

.ubicaciones-panel,
.ubicaciones-card {
	padding: 0.85rem;
}

.ubicaciones-panel h1,
.ubicaciones-contenido h2 {
	margin: 0;
	line-height: 1.1;
}

.ubicaciones-panel h1 {
	margin-bottom: 1rem;
	font-size: 2rem;
}

.ubicaciones-contenido h2 {
	margin-bottom: 0.75rem;
	font-size: 1.5rem;
}

.ubicaciones-form {
	display: grid;
	gap: 0.75rem;
}

.ubicaciones-form label {
	display: block;
	margin-bottom: 0.25rem;
	font-weight: 700;
}

.ubicaciones-form input,
.ubicaciones-toolbar input,
.ubicaciones-toolbar select {
	width: 100%;
	max-width: 100%;
	min-height: 2.5rem;
	margin: 0;
	padding: 0 0.65rem;
	border: 1px solid var(--border-color);
	border-radius: 4px;
	background: var(--ubicaciones-input-bg);
	color: var(--text-body);
	font: inherit;
}

.ubicaciones-form input:focus,
.ubicaciones-toolbar input:focus,
.ubicaciones-toolbar select:focus {
	outline: 2px solid var(--accent-primary);
	outline-offset: 1px;
}

.ubicaciones-actions {
	display: flex;
	justify-content: flex-start;
}

.ubicaciones-page button {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-height: 2.5rem;
	padding: 0 0.85rem;
	border: 1px solid var(--btn-border);
	border-radius: 4px;
	background: var(--btn-bg);
	color: var(--btn-text);
	font: inherit;
	font-weight: 700;
	cursor: pointer;
}

.ubicaciones-toolbar {
	display: grid;
	grid-template-columns: minmax(12rem, 1fr) 9rem auto;
	gap: 0.5rem;
	align-items: center;
	margin-bottom: 0.75rem;
	padding: 0.6rem;
}

.ubicaciones-sort {
	display: flex;
	flex-wrap: wrap;
	gap: 0.35rem;
	align-items: center;
	margin-bottom: 0.75rem;
	padding: 0.6rem;
	color: var(--ubicaciones-muted);
}

.ubicaciones-sort span {
	font-weight: 700;
	color: var(--text-body);
}

.ubicaciones-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr));
	gap: 0.75rem;
}

.ubicaciones-card {
	min-width: 0;
	margin: 0;
	text-align: left;
}

.ubicaciones-card legend {
	max-width: 100%;
	padding: 0 0.25rem;
	font-weight: 900;
	overflow-wrap: anywhere;
}

.ubicaciones-card ul {
	margin: 0;
	padding: 0.25em 0.5em 0.25em 1.25em;
	text-align: left;
}

.ubicaciones-card li {
	margin: 0.15rem 0;
	overflow-wrap: anywhere;
}

.ubicaciones-card-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	margin-bottom: 0.5rem;
}

.bandera {
	pointer-events: none;
	font-size: 1.6rem;
	line-height: 1;
	vertical-align: middle;
}

.ubicaciones-paginacion {
	display: flex;
	flex-wrap: wrap;
	gap: 0.35rem;
	align-items: center;
	margin-top: 1rem;
}

.ubicaciones-paginacion a,
.ubicaciones-paginacion span {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 2rem;
	min-height: 2rem;
	padding: 0.2rem 0.45rem;
	border: 1px solid var(--border-color);
	border-radius: 4px;
	background: var(--ubicaciones-surface);
	text-decoration: none;
}

.ubicaciones-paginacion span {
	border-color: var(--accent-primary);
	color: var(--text-body);
	font-weight: 900;
}

@media (max-width: 760px) {
	body.ubicaciones-page {
		padding: 0.75rem;
	}

	.ubicaciones-layout,
	.ubicaciones-toolbar {
		grid-template-columns: 1fr;
	}

	.ubicaciones-panel {
		position: static;
	}
}

.ubicaciones-admin .ubicaciones-panel {
	position: static;
	margin-bottom: 1rem;
}

.ubicaciones-admin .ubicaciones-panel > summary {
	cursor: pointer;
	font-size: 1.35rem;
	font-weight: 900;
	line-height: 1.1;
}

body.ubicaciones-page.configuracion-admin {
	padding: 0;
}
</style>
</head>
<body class="ubicaciones-page configuracion-admin">
<div class="configuracion-layout">
	<?php echo menuConfiguracion('ubicaciones'); ?>
	<main class="configuracion-contenido ubicaciones-admin">
	<details class="ubicaciones-panel admin-form-details" aria-labelledby="ubicaciones-form-titulo"<?php echo $formularioAbierto ? ' open' : ''; ?>>
		<summary id="ubicaciones-form-titulo"><?= $editData ? 'Editar ubicación' : 'Agregar ubicación' ?></summary>

		<form method="post" class="ubicaciones-form">
			<input type="hidden" name="id" value="<?= htmlspecialchars($editData['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
			<input type="hidden" name="sort" value="<?= h($sort) ?>">
			<input type="hidden" name="perPage" value="<?= h($perPage) ?>">

			<div>
				<label for="ubicacion-location">Location</label>
				<input id="ubicacion-location" type="text" name="Location" required value="<?= htmlspecialchars($editData['Location'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
			</div>

			<div>
				<label for="ubicacion-gps">GPSCoordinates (lat,lon)</label>
				<input id="ubicacion-gps" type="text" name="GPSCoordinates" required value="<?= htmlspecialchars($editData['GPSPosition'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
			</div>

			<div class="ubicaciones-actions">
				<button type="submit">
					<?= $editData ? 'Actualizar' : 'Guardar' ?>
				</button>
			</div>
		</form>
	</details>

	<section class="ubicaciones-contenido" aria-labelledby="ubicaciones-lista-titulo">
		<h2 id="ubicaciones-lista-titulo">Ubicaciones</h2>

		<form method="get" class="ubicaciones-toolbar">
			<input type="text" name="q"
				   placeholder="Buscar (Location, Country, State, City)"
				   value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>">

			<select name="perPage">
				<?php foreach($perPageOptions as $opt): ?>
					<option value="<?= $opt ?>" <?= $opt==$perPage?'selected':'' ?>>
						<?= $opt ?> por página
					</option>
				<?php endforeach; ?>
			</select>

			<input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">

			<button type="submit">🔍 Buscar</button>
		</form>
		<div class="ubicaciones-sort">
			<span>Ordenar por:</span>
			<a href="?sort=Location&q=<?= urlencode($q) ?>&perPage=<?= $perPage ?>">Location</a>
			<a href="?sort=Country&q=<?= urlencode($q) ?>&perPage=<?= $perPage ?>">Country</a>
			<a href="?sort=GPSPosition&q=<?= urlencode($q) ?>&perPage=<?= $perPage ?>">GPSPosition</a>
		</div>
		<div class="ubicaciones-grid">
			<?php echo exportDefine($rows, $destacadoId, $sort, $q, $perPage, $page);?>
		</div>
		<nav class="ubicaciones-paginacion" aria-label="Paginación">
		<?php for($i=1;$i<=$totalPages;$i++): ?>
			<?php if($i==$page): ?>
				<span><?= $i ?></span>
			<?php else: ?>
				<a href="?page=<?= $i ?>&sort=<?= urlencode($sort) ?>&q=<?= urlencode($q) ?>&perPage=<?= $perPage ?>">
				   <?= $i ?>
				</a>
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
