<?php

/**
 * Render helpers de la vista principal.
 *
 * Mantener estos helpers fuera de index.php permite revisar la composición de
 * HTML sin mezclarla con la lectura de parámetros, filtros y paginación.
 */

function ordenarArbolDirectorios(array &$nodos): void
{
	uksort($nodos, 'strnatcasecmp');
	foreach ($nodos as &$nodo):
		ordenarArbolDirectorios($nodo['hijos']);
	endforeach;
	unset($nodo);
}

function renderizarArbolDirectorios(array $nodos, string $rutaActiva): string
{
	$html = '';
	foreach ($nodos as $nombre => $nodo):
		$ruta = $nodo['ruta'];
		$hijos = $nodo['hijos'];
		$activo = ($rutaActiva !== '' && $ruta === $rutaActiva);
		$abierto = ($rutaActiva !== '' && str_starts_with($rutaActiva . '/', $ruta . '/'));
		$nombreAttr = escaparHtml($nombre);
		$rutaAttr = escaparHtml($ruta);
		$claseBoton = 'directorio-boton' . ($activo ? ' activo' : '');
		$boton =
			'<button type="submit" name="archivo" value="' . $rutaAttr . '" class="' . $claseBoton . '" title="' . $rutaAttr . '">' .
			'<span class="directorio-nombre">' . $nombreAttr . '</span>' .
			'<span class="directorio-ruta">' . $rutaAttr . '</span>' .
			'</button>';

		if (!empty($hijos)):
			$html .=
				'<li class="directorio-item">' .
				'<details class="directorio-nodo" data-path="' . $rutaAttr . '" data-name="' . $nombreAttr . '" data-open-default="' . ($abierto ? '1' : '0') . '"' . ($abierto ? ' open' : '') . '>' .
				'<summary>' . $boton . '</summary>' .
				'<ul>' . renderizarArbolDirectorios($hijos, $rutaActiva) . '</ul>' .
				'</details>' .
				'</li>';
		else:
			$html .=
				'<li class="directorio-item directorio-hoja" data-path="' . $rutaAttr . '" data-name="' . $nombreAttr . '">' .
				$boton .
				'</li>';
		endif;
	endforeach;

	return $html;
}

function construirArbolDirectorios(array $rutas, string $rutaActiva): string
{
	$arbol = [];
	foreach ($rutas as $ruta):
		$ruta = trim(str_replace('\\', '/', $ruta), '/');
		if ($ruta === ''):
			continue;
		endif;
		$partes = array_values(array_filter(explode('/', $ruta), fn($parte) => $parte !== ''));
		$cursor = &$arbol;
		$acumulada = [];
		foreach ($partes as $parte):
			$acumulada[] = $parte;
			if (!isset($cursor[$parte])):
				$cursor[$parte] = [
					'ruta' => implode('/', $acumulada),
					'hijos' => []
				];
			endif;
			$cursor = &$cursor[$parte]['hijos'];
		endforeach;
		unset($cursor);
	endforeach;

	ordenarArbolDirectorios($arbol);
	if (empty($arbol)):
		return '<p class="arbol-vacio">No hay carpetas.</p>';
	endif;

	return '<ul class="arbol-directorios">' . renderizarArbolDirectorios($arbol, $rutaActiva) . '</ul>';
}

function urlPalabraClave(string $palabra, int $ver, string $media, array $filtros): string
{
	$params = [
		'palabra_clave' => $palabra,
		'ver' => $ver,
	];
	if ($media !== ''):
		$params['media'] = $media;
	endif;
	foreach ($filtros as $clave => $valor):
		if ($valor !== ''):
			$params[$clave] = $valor;
		endif;
	endforeach;

	return '?' . http_build_query($params);
}

function renderizarListaPalabrasClave(array $palabras, string $palabraActiva, int $ver, string $media, array $filtros): string
{
	if (empty($palabras)):
		return '<p class="palabras-clave-vacio">Sin palabras clave indexadas.</p>';
	endif;

	$claveActiva = normalizarClavePalabraClave($palabraActiva);
	$html = '<div class="palabras-clave-lista" role="list">';
	foreach ($palabras as $fila):
		$palabra = (string) ($fila['palabra'] ?? '');
		$clave = (string) ($fila['clave'] ?? normalizarClavePalabraClave($palabra));
		if ($palabra === '' || $clave === ''):
			continue;
		endif;
		$total = (int) ($fila['total'] ?? 0);
		$activa = $claveActiva !== '' && $clave === $claveActiva;
		$html .=
			'<a role="listitem" class="palabra-clave-link' . ($activa ? ' activo' : '') . '"' .
			' href="' . escaparHtml(urlPalabraClave($palabra, $ver, $media, $filtros)) . '"' .
			' data-palabra="' . escaparHtml($palabra) . '"' .
			' data-palabra-busqueda="' . escaparHtml($clave) . '"' .
			' title="' . escaparHtml($palabra) . '">' .
			'<span class="palabra-clave-texto">' . escaparHtml($palabra) . '</span>' .
			'<span class="palabra-clave-total">' . $total . '</span>' .
			'</a>';
	endforeach;
	$html .= '</div>';

	return $html;
}

