# Corrección de la pasarela (web WordPress del Museo Larco)

Archivos **corregidos** para reemplazar en el sitio. Rutas en producción
(cPanel → File Manager, bajo `public_html/wp-content/themes/mltheme/`):

| Archivo corregido (aquí) | Reemplaza en producción |
|---|---|
| `js/services/index.js` | `wp-content/themes/mltheme/js/services/index.js` |
| `ajax/charge.php` | `wp-content/themes/mltheme/ajax/charge.php` |
| `ajax/order.php` | `wp-content/themes/mltheme/ajax/order.php` |

## Qué se corrigió y por qué

**1. `js/services/index.js` — BUG BLOQUEANTE (frontend).**
La clase `Service` usaba `$.ajax` (jQuery), pero en la página `$` no está
disponible (WordPress corre jQuery en *noConflict*; de ahí el `$ is not a
function` en consola). `$.ajax` lanzaba excepción **antes de enviar la
petición**, caía al `catch` y devolvía un `statusCode: 502` **inventado** — sin
llegar nunca al servidor (por eso en Network no aparecía `charge.php`).
→ Se reemplazó `$.ajax` por **`fetch()` nativo**. Misma interfaz, mismos
endpoints, mismos campos. Ahora la petición SÍ se envía.

**2. `ajax/charge.php` — BUG PELIGROSO (backend).**
`main.js` considera éxito solo con `statusCode === 201`, pero `charge.php` hacía
`echo` sin fijar código → respondía **HTTP 200**. Con el frontend arreglado, un
cobro **exitoso** se habría mostrado como "CARGO FALLIDO" (cliente cobrado, web
dice que falló → riesgo de cobro doble).
→ Ahora devuelve **201** cuando el cargo se aprueba, **200** si queda en revisión
3DS (`action_code REVIEW`), y **400 + JSON** ante error. Además blinda
`$_SESSION['tickets']` (los warnings del log desaparecen).

**3. `ajax/order.php` — secundario.** Mismo arreglo de código de estado y de
sesión. Nota: para pagos con **tarjeta la orden no es necesaria** (las Órdenes
son para Yape/PagoEfectivo/transferencia y exigen mínimo S/ 6 + client_details
válidos). Si solo cobran con tarjeta, se puede omitir esa llamada.

## Cómo desplegar

1. **Respaldar** los 3 archivos actuales (cPanel → seleccionar → Download).
2. Reemplazar cada archivo por su versión corregida (Edit → pegar → Save, o subir).
3. **Cache-busting del JS** (importante): el navegador cachea `services/index.js`
   y, al ser un módulo, no se versiona con `?v=`. Copiar el `.htaccess` de
   `cache-busting/` a `wp-content/themes/mltheme/js/.htaccess` para forzar
   revalidación (ver `cache-busting/README.md`). Si hay CDN/plugin de caché,
   purgarlo tras desplegar.
4. Probar un pago real de prueba y confirmar en el **panel de Culqi** que el
   cargo aparece y que la web muestra el resultado correcto.

## Validado en aislado

El flujo corregido (misma clase `Service` con `fetch`) se replicó en un entorno
aislado (`../entorno-prueba/index-corregido.html` + endpoint local `ajax/charge.php`):
- La petición **sí se envía** y trae el **status real** de Culqi (ya no el 502
  inventado).
- Un cargo real de S/ 1.00 devolvió **HTTP 201 · venta_exitosa** (ver el README
  principal del repositorio).
