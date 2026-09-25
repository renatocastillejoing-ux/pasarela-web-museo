# Pasarela de pagos — auditoría y corrección (Museo Larco)

Repositorio de la **auditoría del área de TI** sobre el fallo de pagos en la web
de tickets (`museolarco.org/tickets`), donde el proceso terminaba en un error
**502** al generar el cobro.

Incluye el **entorno de prueba aislado** con el que se aisló la causa y los
**archivos corregidos** listos para revisar/aplicar en el sitio.

> 📄 **Diagnóstico completo, prueba por prueba:** ver **[`DIAGNOSTICO.md`](DIAGNOSTICO.md)**
> (hipótesis evaluadas, cómo se comprobaron/descartaron, archivos revisados y hallazgos).

---

## Conclusión

**La pasarela de Culqi funciona correctamente.** El fallo está en el **código del
propio sitio** (WordPress, tema `mltheme`). Se identificaron **tres defectos** (más
un hallazgo de seguridad):

### 1) BLOQUEANTE — Frontend · `js/services/index.js`
La clase `Service` envía el pago con **`$.ajax`** (jQuery), pero en la página
**`$` no está disponible** (WordPress corre jQuery en modo *noConflict*; de ahí
el `$ is not a function` en consola). `$.ajax` **lanza una excepción antes de
enviar la petición**, cae al `catch` y devuelve un `statusCode: 502` **fijo e
inventado** en el código:

```js
#http3 = async (...) => {
  let statusCode = 502;                 // ← valor por defecto
  ...
  const response = await $.ajax({ ... });   // ← "$" no existe -> excepción
  ...
} catch (err) {
  return { statusCode: statusCode, data: null }   // ← devuelve el 502 inventado
}
```

Por eso en el Network **no aparece** ninguna petición a `charge.php`/`order.php`:
la llamada **nunca sale del navegador**. El "502" que se ve **no es real**.

**Corrección:** usar **`fetch()` nativo** en vez de `$.ajax` (no depende de jQuery).

### 2) PELIGROSO — Backend · `ajax/charge.php`
`main.js` da por exitoso el cobro solo si el servidor responde **HTTP 201**, pero
`charge.php` hace `echo` sin fijar código → responde **HTTP 200**. Es decir, aun
arreglando el frontend, **un cobro aprobado se mostraría como "CARGO FALLIDO"**.
**Riesgo:** el cliente queda cobrado y la web le dice que falló → posibles
**cobros dobles**.

**Corrección:** `charge.php` devuelve **201** al aprobar (200 si requiere 3DS,
400 en error) y se blindan los datos de sesión.

### 3) BLOQUEANTE — `SyntaxError` por variable fuera de alcance · `template-tickets.php` + `ticketp1.php`
En la página de pago aparece `Uncaught SyntaxError: Unexpected token ')'`. Origen:
`$CURRENT_DAY` / `$CURRENT_HOUR` se **definen solo en `ticketp1.php` (paso 1,
líneas 22-23)**, pero se **usan en el script global de `template-tickets.php`
(línea 664)** que corre en **todos los pasos**. En el **paso 3 (pago)**
`ticketp1.php` no se carga → las variables quedan indefinidas → el HTML sale
`if (jQuery('#day').val() == ) {` → JavaScript inválido.

**Corrección:** no depender de una variable de paso 1; usar el valor directo:
```php
if (jQuery('#day').val() == <?php echo date("Ymd"); ?>) {
  if ('<?php echo date("H").date("i"); ?>' > '<?php echo $_SESSION['START_TIME_FOR_DISCLAIMER'] ?? ''; ?>') {
```

### 🔴 Hallazgo de seguridad (aparte, urgente)
`settings.php` tiene la **llave secreta de Culqi en texto plano** dentro del tema
(más llaves RSA y una secreta antigua comentada). **Rotar la `sk_live`** en el
panel de Culqi y moverla a una variable de entorno fuera del `webroot`.

> El servidor **no se cae**: el `error_log` solo tenía *warnings* inofensivos por
> `$_SESSION['tickets']` vacío (desde 2024); cero errores fatales y cero errores
> de Culqi.

### Validación del arreglo (con tarjeta real)

La réplica con el **código corregido** (`entorno-prueba/index-corregido.html`) se
probó con una tarjeta real y funcionó de punta a punta:

- `ajax/charge.php` respondió **HTTP 201** con `outcome.type = "venta_exitosa"`
  (`AUT0000`) — cargo `chr_live_…`, autorización de venta emitida.
- La petición **sí aparece** ahora en el Network (con `$.ajax` no aparecía ninguna).

Es decir: cambiando `$.ajax → fetch` y devolviendo `201` en `charge.php`, el pago
se completa y se muestra correctamente como exitoso.

---

## Cómo se llegó a esto (resumen de pruebas)

1. **Petición inválida directa a Culqi** → respondió **HTTP 401** estructurado
   (no 502). Culqi ante datos inválidos devuelve 4xx, nunca 502.
2. **Cobro real con código nuevo y aislado** → **HTTP 201, venta_exitosa**
   (AUT0000). Culqi funciona de extremo a extremo.
3. **Network en la web** al pagar → **0 peticiones** a `charge.php`/`order.php`:
   el navegador ni llama al servidor.
4. **Lectura del código del tema** (`main.js`, `services/index.js`,
   `ajax/*.php`, `template-tickets.php`, `ticketp1.php`) y del **`error_log`** →
   se ubicaron los defectos de arriba. **Detalle completo en [`DIAGNOSTICO.md`](DIAGNOSTICO.md).**

---

## Contenido del repositorio

| Carpeta | Qué es |
|---|---|
| `entorno-prueba/` | App mínima (FastAPI + HTML) para probar el cobro con Culqi de forma aislada, y la **réplica del flujo con el código corregido**. |
| `correccion-produccion/` | Los **3 archivos corregidos** para reemplazar en el sitio, con instrucciones de despliegue. |

- `correccion-produccion/js/services/index.js` → reemplaza `wp-content/themes/mltheme/js/services/index.js`
- `correccion-produccion/ajax/charge.php` → reemplaza `wp-content/themes/mltheme/ajax/charge.php`
- `correccion-produccion/ajax/order.php` → reemplaza `wp-content/themes/mltheme/ajax/order.php`

Ver `correccion-produccion/README.md` (qué cambió y cómo desplegar) y
`entorno-prueba/README.md` (cómo correr las pruebas).

---

## Recomendaciones

1. Aplicar los **archivos corregidos** (previo respaldo): `js/services/index.js`,
   `ajax/charge.php`, `ajax/order.php`.
2. **Corregir el `SyntaxError`** (Defecto 3) en `template-tickets.php` línea 664
   (usar `date("Ymd")` en vez de `$CURRENT_DAY`; ver `correccion-produccion/README.md`).
3. **Cache-busting del JS**: no basta con subir el archivo (los navegadores y un
   posible CDN sirven la versión cacheada, y `services/index.js` se carga como
   módulo, que no se versiona con `?v=`). Solución resuelta en
   `correccion-produccion/cache-busting/` (un `.htaccess` que fuerza revalidación).
4. **Rotar la llave secreta de Culqi** (está en texto plano en `settings.php`) y
   moverla fuera del código.
5. Para pagos con **tarjeta**, la **orden no es necesaria** (las Órdenes son para
   Yape/PagoEfectivo/transferencia y exigen mínimo S/ 6 + `client_details`
   válidos); se puede simplificar el flujo omitiéndola.
6. **Revisar en el panel de Culqi** si hay cargos aprobados que la web reportó
   como fallidos (por el Defecto 4), para descartar cobros no reflejados.

> Ningún archivo de este repo contiene llaves secretas. Las llaves van en un
> `.env` local (ignorado por git).
