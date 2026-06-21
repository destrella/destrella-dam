<?php

/**
 * Helpers transversales de infraestructura.
 *
 * Este archivo concentra utilidades pequeñas y deliberadamente aburridas para
 * que las páginas no repitan reglas de escapado, resolución de rutas o quoting
 * de comandos externos. La aplicación trabaja con archivos locales, por lo que
 * estas funciones priorizan no salir de la raíz del proyecto.
 */

function escaparHtml(mixed $valor): string
{
	return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function proyectoRaiz(): string
{
	return dirname(__DIR__);
}

function rutaDentroDeDirectorio(string $ruta, string $directorio): bool
{
	$rutaReal = realpath($ruta);
	$directorioReal = realpath($directorio);

	if (!$rutaReal || !$directorioReal):
		return false;
	endif;

	$directorioReal = rtrim($directorioReal, DIRECTORY_SEPARATOR);
	return $rutaReal === $directorioReal || str_starts_with($rutaReal, $directorioReal . DIRECTORY_SEPARATOR);
}

function resolverRutaProyecto(?string $entrada, string $tipo = 'any', bool $permitirRoot = true): ?string
{
	$root = realpath(proyectoRaiz());
	if (!$root):
		return null;
	endif;

	$ruta = trim(str_replace('\\', '/', (string) $entrada));
	if ($ruta === ''):
		return $permitirRoot ? $root : null;
	endif;

	$candidata = str_starts_with($ruta, '/')
		? $ruta
		: $root . DIRECTORY_SEPARATOR . ltrim($ruta, '/ ');
	$real = realpath($candidata);

	if (!$real || !rutaDentroDeDirectorio($real, $root)):
		return null;
	endif;

	if ($tipo === 'file' && !is_file($real)):
		return null;
	endif;
	if ($tipo === 'dir' && !is_dir($real)):
		return null;
	endif;

	return $real;
}

function rutaRelativaDesdeProyecto(string $ruta): string
{
	$root = str_replace('\\', '/', proyectoRaiz());
	$ruta = str_replace('\\', '/', $ruta);

	if (str_starts_with($ruta, $root . '/')):
		return substr($ruta, strlen($root) + 1);
	endif;

	return ltrim($ruta, '/');
}

/**
 * Obtiene la raíz de navegación del árbol de directorios.
 *
 * @return string Ruta absoluta de la raíz de navegación (home del usuario).
 *                 Si no se puede determinar el home, cae en proyectoRaiz().
 */
function obtenerRaizNavegacion(): string
{
	return $_SERVER['HOME'] ?? getenv('HOME') ?: proyectoRaiz();
}

/**
 * Resuelve una ruta de entrada contra la raíz de navegación (home).
 *
 * Similar a resolverRutaProyecto() pero usando la raíz de navegación como
 * directorio base. Es la función de resolución para el árbol de directorios
 * de la vista principal.
 *
 * @param string|null $entrada  Ruta o subruta a resolver.
 * @param string       $tipo    'any', 'file', o 'dir'.
 * @param bool         $permitirRoot Si true, entrada vacía devuelve la raíz.
 * @return string|null Ruta real resuelta, o null si no es válida.
 */
function resolverRutaNavegacion(?string $entrada, string $tipo = 'any', bool $permitirRoot = true): ?string
{
	$root = realpath(obtenerRaizNavegacion());
	if (!$root):
		return resolverRutaProyecto($entrada, $tipo, $permitirRoot);
	endif;

	$ruta = trim(str_replace('\\', '/', (string) $entrada));
	if ($ruta === ''):
		return $permitirRoot ? $root : null;
	endif;

	$candidata = str_starts_with($ruta, '/')
		? $ruta
		: $root . DIRECTORY_SEPARATOR . ltrim($ruta, '/ ');
	$real = realpath($candidata);

	// Intentar primero con la raíz de navegación
	if ($real && rutaDentroDeDirectorio($real, $root)):
		if ($tipo === 'file' && !is_file($real)):
			return null;
		endif;
		if ($tipo === 'dir' && !is_dir($real)):
			return null;
		endif;
		return $real;
	endif;

	// Fallback: resolver contra la raíz del proyecto
	return resolverRutaProyecto($entrada, $tipo, $permitirRoot);
}

/**
 * Devuelve la ruta relativa desde la raíz de navegación.
 *
 * @param string $ruta Ruta absoluta.
 * @return string Ruta relativa desde la raíz de navegación,
 *                o desde proyectoRaiz() como fallback.
 */
function rutaRelativaDesdeRaizNavegacion(string $ruta): string
{
	$root = str_replace('\\', '/', obtenerRaizNavegacion());
	$ruta = str_replace('\\', '/', $ruta);

	if (str_starts_with($ruta, $root . '/')):
		return substr($ruta, strlen($root) + 1);
	endif;

	return rutaRelativaDesdeProyecto($ruta);
}

/**
 * Resuelve una ruta probando primero contra la raíz del proyecto y,
 * si falla, contra la raíz de navegación (home).
 *
 * Las operaciones destructivas (borrar, archivar, convertir, extraer)
 * deben seguir usando resolverRutaProyecto() directamente.
 * Esta función es para operaciones de lectura, metadatos y apertura local.
 *
 * @param string|null $ruta Ruta a resolver.
 * @param string $tipo 'any', 'file' o 'dir'.
 * @param bool $permitirRoot Si true, entrada vacía devuelve la raíz.
 * @return string|null Ruta real resuelta, o null.
 */
function resolverRutaTolerante(?string $ruta, string $tipo = 'any', bool $permitirRoot = true): ?string
{
	$resuelta = resolverRutaProyecto($ruta, $tipo, $permitirRoot);
	if ($resuelta !== null):
		return $resuelta;
	endif;

	return resolverRutaNavegacion($ruta, $tipo, $permitirRoot);
}

/**
 * Agrega etiquetas (Finder tags) de color gris a un archivo en macOS.
 *
 * Usa Swift (siempre disponible en macOS) para fijar las etiquetas a través
 * de NSURL.tagNamesKey. Cada etiqueta se muestra en el Finder con el color
 * gris por defecto.
 *
 * @param string $rutaArchivo Ruta absoluta del archivo a etiquetar.
 * @param string ...$etiquetas Textos de las etiquetas a agregar.
 */
function agregarEtiquetasFinder(string $rutaArchivo, string ...$etiquetas): void
{
	if (!str_starts_with(PHP_OS, 'Darwin')):
		return;
	endif;

	if (!is_file($rutaArchivo)):
		return;
	endif;

	if (empty($etiquetas)):
		return;
	endif;

	// Escapar la ruta para el script Swift
	$swiftRuta = json_encode($rutaArchivo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$swiftEtiquetas = json_encode($etiquetas, JSON_UNESCAPED_UNICODE);

	$script = <<<SWIFT
import Foundation
let url = URL(fileURLWithPath: {$swiftRuta})
var current: [String] = []
if let vals = try? (url as NSURL).resourceValues(forKeys: [.tagNamesKey]),
   let tags = vals[.tagNamesKey] as? [String] {
    current = tags
}
let nuevos: [String] = {$swiftEtiquetas}
for t in nuevos {
    if !current.contains(t) {
        current.append(t)
    }
}
try (url as NSURL).setResourceValue(current, forKey: .tagNamesKey)
SWIFT;

	$comando = 'swift -e ' . escapeshellarg($script) . ' 2>/dev/null';
	shell_exec($comando);
}

function argumentoExifTool(string $etiqueta, mixed $valor, bool $append = false): string
{
	$etiqueta = trim($etiqueta, "- \t\n\r\0\x0B");
	if (!preg_match('/^[A-Za-z0-9:_#-]+$/', $etiqueta)):
		throw new InvalidArgumentException('Etiqueta ExifTool inválida: ' . $etiqueta);
	endif;

	return '-' . $etiqueta . ($append ? '+=' : '=') . (string) $valor;
}

function comandoSeguro(array $argumentos): string
{
	$argumentos = array_map(
		static fn($argumento) => escapeshellarg((string) $argumento),
		$argumentos
	);

	return implode(' ', $argumentos);
}

function comandoBrewSeguro(array $argumentos): string
{
	if (empty($argumentos)):
		throw new InvalidArgumentException('El comando no puede estar vacío.');
	endif;

	$binario = array_shift($argumentos);
	return rtrim(BREW_BIN, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binario . ' ' . comandoSeguro($argumentos);
}

