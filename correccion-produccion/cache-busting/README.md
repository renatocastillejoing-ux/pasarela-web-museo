# Cache-busting (para que el arreglo llegue a los usuarios)

Al reemplazar `services/index.js` por la versión corregida **no basta con subir
el archivo**: los navegadores (y un posible CDN) pueden seguir sirviendo la
versión vieja cacheada. Y como `services/index.js` se carga como **módulo**
(`import ... from "./services/index.js"`), **no se puede versionar con un simple
`?v=`** en la etiqueta `<script>` (el import se cachea por su URL literal).

## Solución (resuelta) — forzar revalidación

Copiar el archivo **`.htaccess`** de esta carpeta a:

```
wp-content/themes/mltheme/js/.htaccess
```

Hace que el navegador **revalide** cada `.js` con el servidor en cada carga
(`Cache-Control: no-cache, must-revalidate`). Al subir la versión corregida, su
fecha/ETag cambia y el navegador descarga el archivo nuevo automáticamente —
también los importados por módulos. El costo es mínimo (respuestas `304` cuando
no cambió).

Esto resuelve el problema de raíz sin tener que editar los `import` del código.

## Complemento opcional (versionar la entrada)

Además, en la plantilla que imprime la página de tickets, se puede versionar el
script de entrada para forzar recarga tras cada cambio de `main.js`:

```php
<script type="module"
        src="<?php echo URL_BASE; ?>/js/main.js?v=<?php echo filemtime(get_stylesheet_directory() . '/js/main.js'); ?>"
        defer></script>
```

`filemtime` cambia el `?v=` solo cuando `main.js` cambia. Ojo: esto versiona la
ENTRADA, pero no los `import` internos — por eso el `.htaccess` de arriba es lo
que garantiza que también se actualicen los módulos importados.

## Si hay CDN o plugin de caché

Si el sitio usa Cloudflare u otro CDN, o un plugin de caché (WP Rocket, LiteSpeed,
W3 Total Cache, etc.), **purgar la caché** después de desplegar. El `.htaccess`
controla el navegador y el origen, pero el edge del CDN puede tener su propia
copia hasta que se purgue.

## Después de estabilizar (opcional, rendimiento)

Cuando el arreglo esté estable, se puede volver a caché larga con **URLs
versionadas** (nombre con hash o `?v=` en todas las rutas, incluidos los
`import`) para recuperar el cacheo agresivo. Mientras se resuelve la incidencia,
la revalidación del `.htaccess` es la opción segura.
