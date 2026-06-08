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

