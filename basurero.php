<?php
require_once "src/funciones.php";

// Si es una petición AJAX para procesar acciones
if (isset($_GET['accion'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache');
    
    // Configurar PHP para enviar el output inmediatamente
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);
    @ini_set('implicit_flush', true);
    ob_implicit_flush(true);
    while (ob_get_level() > 0) {
        $level = ob_get_level();
        ob_end_flush();
        if (ob_get_level() == $level) break;
    }

    $accion = $_GET['accion'];
    $rutaBase = realpath(__DIR__) ?: __DIR__;

    if ($accion === 'vaciar_basura') {
        $dir = $rutaBase . DIRECTORY_SEPARATOR . '.basura';
        if (is_dir($dir)) {
            $archivos = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            $total = iterator_count($archivos);
            echo "Iniciando vaciado del basurero ($total elementos)...\n";
            flush();
            
            $borrados = 0;
            // Volvemos a iterar porque iterator_count lo avanza al final
            $archivos = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($archivos as $fileinfo) {
                $ruta = $fileinfo->getRealPath();
                $nombre = $fileinfo->getFilename();
                if ($fileinfo->isDir()) {
                    if (@rmdir($ruta)) {
                        echo "Carpeta borrada: " . $nombre . "\n";
                    }
                } else {
                    if (@unlink($ruta)) {
                        echo "Archivo borrado: " . $nombre . "\n";
                    }
                }
                $borrados++;
                flush();
                usleep(5000); // 5ms de pausa para que el streaming sea visible
            }
            echo "Vaciado completado. $borrados elementos eliminados.\n";
        } else {
            echo "La carpeta .basura no existe.\n";
        }
        exit;
    }

    if ($accion === 'borrar_carpeta') {
        $carpeta = $_GET['carpeta'] ?? '';
        $rutaCompleta = resolverRutaProyecto($carpeta, 'dir', false);
        
        if ($rutaCompleta && $rutaCompleta !== $rutaBase && rutaDentroDeDirectorio($rutaCompleta, $rutaBase)) {
            $esVacia = true;
            $archivos = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rutaCompleta, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($archivos as $fileinfo) {
                if (!$fileinfo->isDir() && substr($fileinfo->getFilename(), 0, 1) !== '.') {
                    $esVacia = false;
                    break;
                }
            }
            
            if ($esVacia) {
                $archivos = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($rutaCompleta, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($archivos as $fileinfo) {
                    $rutaItem = $fileinfo->getRealPath();
                    if ($fileinfo->isDir()) {
                        @rmdir($rutaItem);
                    } else {
                        @unlink($rutaItem);
                        echo "Archivo oculto borrado: " . $fileinfo->getFilename() . "\n";
                        flush();
                    }
                }
                if (@rmdir($rutaCompleta)) {
                    echo "Carpeta borrada exitosamente: " . basename($rutaCompleta) . "\n";
                } else {
                    echo "Error al borrar carpeta: " . basename($rutaCompleta) . "\n";
                }
            } else {
                echo "La carpeta no está vacía.\n";
            }
        } else {
            echo "Ruta inválida.\n";
        }
        exit;
    }

    if ($accion === 'borrar_todas_carpetas') {
        echo "Buscando carpetas vacías en imgs y listas...\n";
        flush();
        $baseDirs = ['imgs', 'listas'];
        $borradas = 0;
        foreach ($baseDirs as $dirName) {
            $dir = __DIR__ . DIRECTORY_SEPARATOR . $dirName;
            if (is_dir($dir)) {
                $iterador = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iterador as $fileinfo) {
                    if ($fileinfo->isDir()) {
                        $rutaCompleta = $fileinfo->getRealPath();
                        // check if empty
                        $esVacia = true;
                        $inner = new DirectoryIterator($rutaCompleta);
                        foreach ($inner as $item) {
                            if (!$item->isDot() && substr($item->getFilename(), 0, 1) !== '.') {
                                $esVacia = false;
                                break;
                            }
                        }
                        if ($esVacia) {
                            // Borrar contenido oculto y borrarla
                            $innerIter = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator($rutaCompleta, RecursiveDirectoryIterator::SKIP_DOTS),
                                RecursiveIteratorIterator::CHILD_FIRST
                            );
                            foreach ($innerIter as $item) {
                                if ($item->isDir()) { @rmdir($item->getRealPath()); }
                                else { @unlink($item->getRealPath()); }
                            }
                            if (@rmdir($rutaCompleta)) {
                                echo "Carpeta vacía borrada: " . str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $rutaCompleta) . "\n";
                                $borradas++;
                                flush();
                                usleep(10000);
                            }
                        }
                    }
                }
            }
        }
        echo "Proceso completado. $borradas carpetas borradas.\n";
        exit;
    }

    if ($accion === 'borrar_temporales_extracciones') {
        $dir = $rutaBase . DIRECTORY_SEPARATOR . '.posters' . DIRECTORY_SEPARATOR . 'extracciones';
        if (is_dir($dir)) {
            $archivos = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            $total = iterator_count($archivos);
            echo "Borrando temporales de .posters/extracciones ($total elementos)...\n";
            flush();

            $borrados = 0;
            $archivos = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($archivos as $fileinfo) {
                $ruta = $fileinfo->getRealPath();
                $nombre = $fileinfo->getFilename();
                if ($fileinfo->isDir()) {
                    if (@rmdir($ruta)) {
                        echo "Carpeta temporal borrada: " . $nombre . "\n";
                    }
                } else {
                    if (@unlink($ruta)) {
                        echo "Temporal borrado: " . $nombre . "\n";
                    }
                }
                $borrados++;
                flush();
                usleep(5000);
            }
            echo "Limpieza completada. $borrados elementos eliminados.\n";
        } else {
            echo "La carpeta .posters/extracciones no existe.\n";
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es"<?php echo atributoTemaConfiguracion(); ?>>
<head>
    <meta charset="utf-8">
    <title>Limpieza / Basurero</title>
    <link href="estilos.css?v=<?php echo filemtime('estilos.css'); ?>" rel="stylesheet">
    <style>
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .link-inicio {
            display: inline-block;
            margin-bottom: 20px;
            font-size: 1.1rem;
            text-decoration: none;
            color: var(--accent-primary);
            border: 1px solid currentColor;
            padding: 5px 10px;
            border-radius: 5px;
            background: var(--btn-bg);
        }
        details {
            background: var(--code-bg);
            border: 1px solid var(--fieldset-border);
            border-radius: 5px;
            margin-bottom: 20px;
            padding: 10px;
        }
        summary {
            font-size: 1.2rem;
            font-weight: bold;
            cursor: pointer;
            outline: none;
        }
        .lista-scroll {
            max-height: 50vh;
            min-height: 300px;
            overflow-y: auto;
            margin-top: 10px;
            border: 1px solid var(--border-color);
            padding: 10px;
            background: rgba(0,0,0,0.1);
            border-radius: 5px;
        }
        .item-lista {
            display: flex;
            align-items: center;
            padding: 5px;
            border-bottom: 1px solid var(--border-color);
            gap: 10px;
        }
        .item-lista:last-child {
            border-bottom: none;
        }
        .miniatura {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 3px;
            background: #222;
        }
        .btn-accion {
            margin-top: 10px;
            padding: 8px 15px;
            background: var(--btn-bg);
            color: var(--text-body);
            border: 1px solid var(--btn-border);
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn-accion:hover {
            background: var(--btn-hover-bg);
            border-color: var(--btn-hover-border);
        }
        .btn-accion.peligro {
            background: rgba(217, 83, 79, 0.2);
            border-color: #d43f3a;
            color: #d9534f;
        }
        .btn-accion.peligro:hover {
            background: rgba(217, 83, 79, 0.4);
        }
        .consola {
            background: #1e1e1e;
            color: #0f0;
            font-family: monospace;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            height: 150px;
            overflow-y: auto;
            display: none;
            white-space: pre-wrap;
            font-size: 0.9em;
        }
        @media (prefers-color-scheme: dark) {
            .btn-accion.peligro {
                color: #ff6b6b;
                border-color: #ff6b6b;
            }
        }
    </style>
</head>
<body class="configuracion-admin">
    <div class="configuracion-layout">
        <?php echo menuConfiguracion('limpieza'); ?>
        <main class="configuracion-contenido">
    <div class="container">
        <h1>Limpieza de archivos</h1>

        <!-- Basura -->
        <details>
            <summary>Archivos en .basura</summary>
            <?php
            $dirBasura = __DIR__ . '/.basura';
            $archivosBasura = [];
            if (is_dir($dirBasura)) {
                $iterador = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dirBasura, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($iterador as $archivo) {
                    if ($archivo->isFile() && substr($archivo->getFilename(), 0, 1) !== '.') {
                        $archivosBasura[] = [
                            'ruta' => str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $archivo->getRealPath()),
                            'nombre' => $archivo->getFilename()
                        ];
                    }
                }
            }
            ?>
            <p>Total de archivos visibles: <?php echo count($archivosBasura); ?></p>
            <button class="btn-accion peligro" onclick="vaciarBasurero()">Vaciar basurero</button>
            <div id="consola-basura" class="consola"></div>
            
            <?php if (count($archivosBasura) > 0): ?>
            <div class="lista-scroll">
                <?php foreach ($archivosBasura as $archivo): ?>
                    <div class="item-lista">
                        <?php 
                        $ext = strtolower(pathinfo($archivo['nombre'], PATHINFO_EXTENSION));
                        $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                        if ($isImg): ?>
                            <img src="<?php echo htmlspecialchars($archivo['ruta']); ?>" class="miniatura" loading="lazy">
                        <?php else: ?>
                            <div class="miniatura" style="display:flex;align-items:center;justify-content:center;font-size:0.8rem;color:#fff;"><?php echo $ext; ?></div>
                        <?php endif; ?>
                        <span><?php echo htmlspecialchars($archivo['nombre']); ?></span>
                        <span style="font-size:0.8em;color:var(--grey-text);margin-left:auto;"><?php echo htmlspecialchars($archivo['ruta']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </details>

        <!-- Temporales de extracciones -->
        <details>
            <summary>Temporales en .posters/extracciones</summary>
            <?php
            $dirExtracciones = __DIR__ . '/.posters/extracciones';
            $archivosExtracciones = [];
            $bytesExtracciones = 0;
            if (is_dir($dirExtracciones)) {
                $iterador = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dirExtracciones, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($iterador as $archivo) {
                    if ($archivo->isFile()) {
                        $tamano = $archivo->getSize();
                        $bytesExtracciones += $tamano;
                        $archivosExtracciones[] = [
                            'ruta' => str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $archivo->getRealPath()),
                            'nombre' => $archivo->getFilename(),
                            'tamano' => $tamano,
                        ];
                    }
                }
            }
            usort($archivosExtracciones, static fn($a, $b) => strnatcasecmp($a['ruta'], $b['ruta']));
            $mbExtracciones = $bytesExtracciones > 0 ? number_format($bytesExtracciones / 1048576, 2) . ' MB' : '0 MB';
            ?>
            <p>Total de archivos temporales: <?php echo count($archivosExtracciones); ?> · <?php echo htmlspecialchars($mbExtracciones); ?></p>
            <button class="btn-accion peligro" onclick="borrarTemporalesExtracciones()">Borrar temporales de extracciones</button>
            <div id="consola-extracciones" class="consola"></div>

            <?php if (count($archivosExtracciones) > 0): ?>
            <div class="lista-scroll">
                <?php foreach ($archivosExtracciones as $archivo): ?>
                    <div class="item-lista">
                        <?php
                        $ext = strtolower(pathinfo($archivo['nombre'], PATHINFO_EXTENSION));
                        $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'], true);
                        if ($isImg): ?>
                            <img src="<?php echo htmlspecialchars($archivo['ruta']); ?>" class="miniatura" loading="lazy">
                        <?php else: ?>
                            <div class="miniatura" style="display:flex;align-items:center;justify-content:center;font-size:0.8rem;color:#fff;"><?php echo htmlspecialchars($ext); ?></div>
                        <?php endif; ?>
                        <span><?php echo htmlspecialchars($archivo['nombre']); ?></span>
                        <span style="font-size:0.8em;color:var(--grey-text);margin-left:auto;"><?php echo number_format($archivo['tamano'] / 1024, 1); ?> KB · <?php echo htmlspecialchars($archivo['ruta']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </details>

        <!-- Carpetas Vacías -->
        <details>
            <summary>Carpetas vacías en imgs y listas</summary>
            <?php
            $carpetasVacias = [];
            $baseDirs = ['imgs', 'listas'];
            foreach ($baseDirs as $dirName) {
                $dir = __DIR__ . DIRECTORY_SEPARATOR . $dirName;
                if (is_dir($dir)) {
                    $iterador = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($iterador as $fileinfo) {
                        if ($fileinfo->isDir()) {
                            $esVacia = true;
                            $inner = new DirectoryIterator($fileinfo->getRealPath());
                            foreach ($inner as $item) {
                                if (!$item->isDot() && substr($item->getFilename(), 0, 1) !== '.') {
                                    $esVacia = false;
                                    break;
                                }
                            }
                            if ($esVacia) {
                                $carpetasVacias[] = str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $fileinfo->getRealPath());
                            }
                        }
                    }
                }
            }
            sort($carpetasVacias);
            ?>
            <p>Total de carpetas vacías: <span id="count-carpetas"><?php echo count($carpetasVacias); ?></span></p>
            <button class="btn-accion peligro" onclick="borrarTodasCarpetas()">Borrar todas las carpetas vacías</button>
            <div id="consola-carpetas" class="consola"></div>

            <?php if (count($carpetasVacias) > 0): ?>
            <div class="lista-scroll" id="lista-carpetas">
                <?php foreach ($carpetasVacias as $carpeta): ?>
                    <div class="item-lista" id="fila-<?php echo md5($carpeta); ?>">
                        <span style="flex-grow: 1;"><?php echo htmlspecialchars($carpeta); ?></span>
                        <button class="btn-accion" style="margin-top:0;padding:5px 10px;" onclick="borrarCarpeta(<?php echo htmlspecialchars(json_encode($carpeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>, 'fila-<?php echo md5($carpeta); ?>')">Borrar</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </details>
    </div>
        </main>
    </div>

    <script>
    async function executeStream(url, consoleId) {
        const consoleEl = document.getElementById(consoleId);
        consoleEl.style.display = 'block';
        consoleEl.textContent = '';
        
        try {
            const response = await fetch(url);
            const reader = response.body.getReader();
            const decoder = new TextDecoder("utf-8");
            
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                
                const chunk = decoder.decode(value, { stream: true });
                consoleEl.textContent += chunk;
                consoleEl.scrollTop = consoleEl.scrollHeight;
            }
        } catch (e) {
            consoleEl.textContent += '\nError de red: ' + e.message;
        }
    }

    function vaciarBasurero() {
        if (confirm("¿Estás seguro de que deseas eliminar permanentemente todos los archivos en .basura?")) {
            executeStream('?accion=vaciar_basura', 'consola-basura').then(() => {
                setTimeout(() => location.reload(), 2000);
            });
        }
    }

    function borrarTodasCarpetas() {
        if (confirm("¿Estás seguro de que deseas borrar todas las carpetas vacías detectadas?")) {
            executeStream('?accion=borrar_todas_carpetas', 'consola-carpetas').then(() => {
                setTimeout(() => location.reload(), 2000);
            });
        }
    }

    function borrarTemporalesExtracciones() {
        if (confirm("¿Eliminar todos los archivos temporales de .posters/extracciones?")) {
            executeStream('?accion=borrar_temporales_extracciones', 'consola-extracciones').then(() => {
                setTimeout(() => location.reload(), 2000);
            });
        }
    }

    function borrarCarpeta(ruta, filaId) {
        if (confirm("¿Borrar la carpeta " + ruta + "?")) {
            const consoleEl = document.getElementById('consola-carpetas');
            consoleEl.style.display = 'block';
            
            fetch('?accion=borrar_carpeta&carpeta=' + encodeURIComponent(ruta))
            .then(res => res.text())
            .then(txt => {
                consoleEl.textContent += txt;
                consoleEl.scrollTop = consoleEl.scrollHeight;
                const fila = document.getElementById(filaId);
                if (fila) {
                    fila.remove();
                }
                const countEl = document.getElementById('count-carpetas');
                if (countEl) countEl.textContent = parseInt(countEl.textContent) - 1;
            })
            .catch(e => {
                consoleEl.textContent += '\nError: ' + e.message;
            });
        }
    }
    </script>
</body>
</html>
