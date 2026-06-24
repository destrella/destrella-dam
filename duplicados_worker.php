<?php

if (PHP_SAPI !== 'cli'):
	exit(1);
endif;

require_once __DIR__ . '/src/funciones.php';
require_once __DIR__ . '/src/vistaPrincipal.php';
require_once __DIR__ . '/src/yandexDisk.php';
require_once __DIR__ . '/src/duplicados.php';

$id = (string) ($argv[1] ?? '');
duplicadosEjecutarTrabajo($id);
