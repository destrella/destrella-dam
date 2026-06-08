# DAM local

Aplicación PHP local para revisar, etiquetar, archivar y limpiar una biblioteca multimedia. La app usa ExifTool para leer/escribir metadatos, FFmpeg/FFprobe para video y SQLite para catálogos auxiliares como ubicaciones, nombres sugeridos e índices de palabras clave.

## Requisitos

- PHP 8.1 o superior con `pdo_sqlite` y `mbstring`.
- ExifTool en `/opt/homebrew/bin/exiftool`.
- FFmpeg y FFprobe en `/opt/homebrew/bin/` para videos, posters y extracción de frames.
- `cwebp` opcional para conversión WebP.
- Extensión `intl` solo para `fix_unicode_metadata_live_progress.php`.

## Ejecución local

Desde la raíz del proyecto:

```bash
php -S 127.0.0.1:8020
```

Luego abrir `http://127.0.0.1:8020/index.php`.

## Estructura

- `index.php`: vista principal de revisión y etiquetado.
- `src/funciones.php`: operaciones de metadatos, multimedia, filtros e índice de palabras clave.
- `src/soporte.php`: helpers compartidos para HTML, rutas dentro del proyecto y comandos externos.
- `src/vistaPrincipal.php`: render helpers de árbol de carpetas y palabras clave.
- `src/procesarPost.php`: acciones AJAX para guardar metadatos, archivar, borrar, convertir y extraer.
- `configuracion.php`, `basurero.php`, `nombres.php`, `ubicaciones.php`: paneles administrativos.
- `scripts.js` y `estilos.css`: interacción y estilos de la interfaz.

## Datos locales

La biblioteca multimedia (`imgs/`, `listas/`), caches (`.posters/`), basura (`.basura/`, `.dtrash/`) y bases SQLite locales no se versionan. El `.gitignore` está configurado para mantener el repositorio centrado en código y documentación.

## Notas para revisores

- Las rutas recibidas por GET/POST se resuelven con helpers que impiden salir de la raíz del proyecto.
- Los comandos externos se construyen con argumentos escapados individualmente cuando escriben metadatos.
- La salida de herramientas CLI se escapa antes de renderizarse en HTML.
- Los recorridos grandes usan iteradores SPL para reducir copias de arrays y evitar cargas innecesarias en memoria.

