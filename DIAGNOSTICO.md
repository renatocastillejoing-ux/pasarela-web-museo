# Diagnóstico técnico — Falla en la pasarela de pagos de la web de tickets

**Museo Larco · Área de TI**
**Alcance:** `https://www.museolarco.org/tickets/` — el proceso de compra en línea
falla al momento de generar el cobro y muestra “502”.

---

## 1. Resumen ejecutivo

- **La pasarela de pagos (Culqi) está operativa.** Se comprobó de forma directa,
  incluyendo un **cobro real exitoso (HTTP 201, `venta_exitosa`)**.
- **La falla se origina en el código del propio sitio** (tema de WordPress
  `mltheme`), no en Culqi.
- Se identificaron **defectos concretos y reproducibles** en el código del sitio,
  con su mecanismo exacto (secciones 4 y 5).
- El “502” que se observa en consola **no es un error del servidor ni de Culqi**:
  es un valor fijo que el propio JavaScript del sitio devuelve cuando su llamada
  falla, ocultando el error real.
- **Nota metodológica importante:** este diagnóstico **no depende** de identificar
  quién o cuándo se modificó el código. Se sostiene en que los defectos **están
  presentes y se reproducen** en el código actual, y en que Culqi está probado
  funcionando. Un defecto latente puede permanecer inactivo mucho tiempo y
  manifestarse cuando cambia una condición del entorno (ver sección 6).

---

## 2. Metodología

1. **Aislar** el problema con código nuevo, mínimo, fuera de WordPress, para
   separar “la pasarela” de “el sitio”.
2. **Reproducir** la falla en la web real y observar el tráfico de red y la consola.
3. **Rastrear** el código del sitio (JavaScript del tema → PHP del backend → logs).
4. **Verificar** cada hipótesis con una prueba concreta antes de aceptarla o
   descartarla.

No se modificó producción durante el diagnóstico; las pruebas de cobro se hicieron
en un entorno aislado propio (incluido en este repositorio, carpeta
`entorno-prueba/`).

---

## 3. Pruebas e hipótesis (en orden)

Cada bloque indica la hipótesis evaluada, cómo se probó, qué se revisó y el
veredicto. Varias hipótesis provienen de las posturas planteadas durante la
incidencia; se evaluaron todas por igual.

### H1 — “Es un problema del servicio de Culqi (está caído o cambió su API)”
- **Prueba A:** se envió una petición inválida directamente a la API de Culqi
  (`POST /v2/charges`).
  **Resultado:** Culqi respondió **HTTP 401** con un cuerpo de error estructurado
  (`invalid_request_error`). Ante datos inválidos Culqi devuelve un 4xx normal,
  **nunca un 502**.
- **Prueba B:** se construyó una página + backend mínimos (sin WordPress ni las
  librerías del sitio) que cobran directo contra Culqi.
  **Resultado:** **cobro EXITOSO — HTTP 201, `venta_exitosa` (`AUT0000`)**, cargo
  real de S/ 1.00. Culqi funciona de extremo a extremo.
- **Veredicto: DESCARTADA.** Culqi está operativo y su contrato de `/v2/charges`
  no cambió; con el mismo tipo de payload devuelve 201 hoy.

### H2 — “Se envía una petición mal formada a Culqi y por eso falla”
- **Prueba:** se envió a Culqi el **mismo payload** que usa el sitio (`amount`,
  `currency_code`, `email`, `source_id`, `description`).
  **Resultado:** **HTTP 201**. El payload es válido y Culqi lo acepta.
- Complemento: una petición deliberadamente mal formada devolvió **401**, no 502.
- **Veredicto: DESCARTADA.** El formato del payload no es la causa, y una petición
  mal formada tampoco produciría un 502.

### H3 — “El ‘502’ es un error del servidor (el backend PHP se cae)”
- **Se revisó:** la consola del navegador y la pestaña **Network** al pagar.
  **Resultado:** al intentar pagar **no se envía ninguna petición** a
  `charge.php` ni a `order.php` (0 solicitudes). El navegador **ni siquiera llega
  al servidor**.
- **Se rastreó el código:** `js/main.js` → `js/services/impl/index.js` →
  `js/services/index.js`.
  **Hallazgo:** en `services/index.js` el “502” es un **valor fijo** en el código
  (`let statusCode = 502;`) que se devuelve dentro del `catch` cuando la llamada
  falla. **No es una respuesta HTTP del servidor.**
- **Veredicto: DESCARTADA (como error de servidor).** El “502” lo **inventa el
  propio JavaScript del sitio** y enmascara el error real.

### H4 — “El PHP del backend tiene un error fatal que rompe el cargo”
- **Se revisó:** los logs del servidor (`error_log` del tema y de la carpeta
  `ajax/`).
  **Hallazgo:** solo **warnings inofensivos** (`Undefined array key` sobre
  `$_SESSION['tickets']`), presentes **desde 2024**. **Cero errores fatales, cero
  errores de Culqi.** Además, `ajax/charge.php` hace `echo` sin fijar código HTTP,
  por lo que en éxito responde **200** (relevante en la sección 5).
- **Veredicto: DESCARTADA.** El servidor no se cae; el PHP usa el SDK de Culqi
  correctamente.

### H5 — “WordPress eliminó jQuery en la última actualización y por eso rompió”
- **Se revisó en vivo** la página de tickets y **los changelogs oficiales** de
  WordPress 7.1.2, 7.1 y el anuncio de la versión.
  **Hallazgos:**
  - El sitio corre **WordPress 6.5.12** (no 7.1.2). Tanto 6.5.12 como 7.1.2
    (ambos del 22-sep) son **parches de seguridad** que revisaron **un solo
    archivo, `wp-includes/template.php`**.
  - El jQuery del sitio es **1.12.4 cargado desde `ajax.googleapis.com`** (CDN
    externo, fijado en el tema), **no** el jQuery de WordPress. **jQuery Migrate
    ni siquiera se carga.**
  - En el changelog de WordPress 7.1 la palabra “migrate” aparece **0 veces**;
    el jQuery del core **no fue removido**.
- **Veredicto: DESCARTADA.** WordPress no eliminó jQuery; y aunque lo hubiera
  hecho, no afectaría al jQuery externo que el tema carga por su cuenta.

### H6 — “El pago no se envía por una fragilidad en el JavaScript del sitio”
- **Se revisó:** `js/services/index.js` (la clase `Service`; los métodos privados
  `#http2`/`#http3` envían con **`$.ajax`** de jQuery).
  Observación: `$.ajax` depende de que el alias global `$` esté disponible, lo cual
  **no está garantizado** en esa página.
- **Prueba:** se replicó el flujo del sitio con **el mismo código** pero
  reemplazando `$.ajax` por **`fetch` nativo**.
  **Resultado:** la petición **sí se envía** y Culqi responde **201** (verificado
  con un cobro real de S/ 1.00 en el entorno aislado).
- **Veredicto: CONFIRMADA.** El envío del cargo falla en el JavaScript del sitio;
  con `fetch` funciona.

### H7 — “Hay un `SyntaxError` en la página de pago (JavaScript inválido)”
- **Se observó en consola:** `Uncaught SyntaxError: Unexpected token ')'` en la
  página de pago.
- **Se rastreó:** HTML renderizado (la comparación quedaba como `== )`) →
  `template-tickets.php` (línea 664) → `ticketp1.php` (líneas 22-23).
  **Hallazgo:** las variables `$CURRENT_DAY` y `$CURRENT_HOUR` se **definen
  únicamente en `ticketp1.php`**, que **solo se incluye en el paso 1**
  (selección de entradas). Pero se **usan en un script del pie de página** que
  corre en **todos los pasos**. En el **paso 3 (pago)**, `ticketp1.php` no se
  carga → las variables quedan **indefinidas** → el HTML generado es
  `if (jQuery('#day').val() == ) {` → **SyntaxError**.
- **Veredicto: CONFIRMADA.** Es un **defecto de alcance de variable en el tema**
  (detalle en la sección 5, Defecto 1).

### H8 — “No se cambió nada en el desarrollo; Culqi cambió cómo recibe la data”
- **Se revisó:** de dónde salen los datos que alimentan el flujo.
  **Hallazgos:**
  - Las variables que rompen el JavaScript (`$CURRENT_DAY`, `$CURRENT_HOUR`) se
    calculan con `date()` de PHP **dentro del propio tema** (`ticketp1.php`), no
    provienen de Culqi. **Culqi no genera el HTML del sitio.**
  - El resto de la configuración del flujo se obtiene de
    `MANAGEDOMAIN/api/config/` — el **propio backend de gestión** del museo —,
    tampoco de Culqi.
- **Sobre “no se cambió nada”:** esta premisa **no descarta un defecto de código
  que está presente y se reproduce**. Tres precisiones:
  1. El **entorno sí cambió** de forma objetiva: WordPress se actualizó a
     **6.5.12 el 22-sep** (fecha de modificación de `wp-includes/version.php` y
     `template.php`). Un defecto latente puede activarse ante un cambio de
     entorno sin que se toque una sola línea del sitio.
  2. El archivo `template-tickets.php` figura **modificado hoy**, de modo que el
     código del sitio **sí se está editando**.
  3. Lo decisivo: **no es necesario** determinar quién o cuándo cambió algo. Los
     **artefactos que fallan** —JavaScript inválido, la petición que no se envía,
     el “502” inventado— son **generados por el código del sitio**. Culqi no
     puede producir ninguno de ellos.
- **Veredicto:** la premisa “no se cambió nada” no exonera al código. El origen
  temporal es secundario; los defectos son del sitio y se reproducen.

---

## 4. Archivos revisados y cadena de rastreo

**Frontend (JavaScript del tema):**
- Página de tickets (HTML renderizado) → mostró los errores en las líneas 1216,
  1678 y el flujo de pago.
- `js/main.js` → importa de → `js/services/impl/index.js` → usa la clase de →
  `js/services/index.js` (aquí está el `$.ajax` y el “502” fijo) y
  `js/config/checkout.js` (configuración de Culqi Checkout v4).

**Backend (PHP del tema):**
- `ajax/order.php`, `ajax/charge.php`, `ajax/card.php` — endpoints que llaman al
  SDK de Culqi. Se confirmó que el SDK se usa correctamente.
- `template-tickets.php` — plantilla de la página; contiene el script del pie que
  produce el `SyntaxError` (línea 664) y la carga de configuración vía cURL a
  `MANAGEDOMAIN/api/config/`.
- `ticketp1.php` — paso 1; **aquí se definen** `$CURRENT_DAY`/`$CURRENT_HOUR`
  (líneas 22-23).
- `settings.php` — definiciones de llaves y constantes.

**Logs:**
- `error_log` de Apache — resultó **ruido de bots** (escaneos a otras rutas y
  subdominios); sin relación con el pago.
- `error_log` del tema — solo warnings por `$_SESSION['tickets']`.

**Inspección en vivo:** versión de WordPress (6.5.12), versión y origen de jQuery
(1.12.4 desde Google), lista de scripts cargados.

**Documentación oficial:** changelogs de WordPress 7.1.2, 7.1 y anuncio de versión.

---

## 5. Defectos identificados (causas)

**Defecto 1 — `SyntaxError` por variable fuera de alcance (bloqueante en el paso de pago).**
`$CURRENT_DAY`/`$CURRENT_HOUR` se definen solo en `ticketp1.php` (paso 1) pero se
usan en el script global de `template-tickets.php` (línea 664), que corre en el
paso 3. Al estar indefinidas, el navegador recibe `== )` → JavaScript inválido.

**Defecto 2 — El cargo no se envía (`$.ajax`).**
`js/services/index.js` envía con `$.ajax` (jQuery); en esa página `$` no está
disponible de forma confiable, la llamada falla **antes de enviarse** y el código
la disfraza de “502”. Con `fetch` nativo la petición sí se envía (verificado: 201).

**Defecto 3 — “502” inventado que oculta el error real.**
El valor `502` está fijo en el código y se devuelve en el `catch`, lo que llevó a
buscar erróneamente un problema de servidor/Culqi.

**Defecto 4 — Código de estado incorrecto en `charge.php` (riesgo de cobro doble).**
`main.js` da por exitoso el cobro solo si el servidor responde **201**, pero
`charge.php` responde **200**. Con el frontend corregido, un cobro **aprobado**
podría mostrarse como “fallido” → riesgo de que el cliente quede cobrado y
reintente.

**Hallazgo de seguridad (aparte, urgente).**
La **llave secreta de Culqi está en texto plano** dentro del tema
(`settings.php`), junto con llaves RSA y una llave secreta antigua comentada. Se
recomienda **rotar la llave secreta** en el panel de Culqi y moverla a una
variable de entorno fuera del `webroot`.

---

## 6. Sobre el disparador temporal (22-sep)

- **Dato objetivo:** WordPress se actualizó a **6.5.12 el 22-sep** (fecha de
  modificación de archivos del core). Ese parche solo tocó `template.php`
  (resolución de plantillas) por un tema de seguridad.
- **No se establece** un vínculo causal directo entre ese parche y los defectos
  de JavaScript descritos. Por honestidad técnica, **no afirmamos** que ese update
  haya sido la causa.
- **Tampoco “surgió de la nada”:** el software no se rompe espontáneamente. Lo
  esperable en un caso de “funcionó por años y falló de golpe” es un **defecto
  latente** en el código que se **activa al cambiar una condición** (entorno,
  datos, orden de carga o una edición). El entorno **sí** cambió ese día.
- **Conclusión de esta sección:** la identificación del disparador exacto es
  **secundaria**. La conclusión principal (los defectos están en el código del
  sitio y Culqi funciona) está probada de forma **independiente** del disparador.

---

## 7. Correcciones propuestas (y su validación)

1. **`Defecto 2` — Frontend:** reemplazar `$.ajax` por `fetch` en
   `js/services/index.js`. **Validado:** cobro real 201 con el flujo corregido en
   el entorno aislado. Archivo corregido en `correccion-produccion/`.
2. **`Defecto 4` — Backend:** `charge.php` debe devolver **201** al aprobar (200
   si requiere 3DS, 400 en error). Archivo corregido en `correccion-produccion/`.
3. **`Defecto 1` — SyntaxError:** en `template-tickets.php` línea 664, no depender
   de una variable de paso 1; usar el valor directo:
   ```php
   if (jQuery('#day').val() == <?php echo date("Ymd"); ?>) {
     if ('<?php echo date("H").date("i"); ?>' > '<?php echo $_SESSION['START_TIME_FOR_DISCLAIMER'] ?? ''; ?>') {
   ```
4. **Cache-busting:** al desplegar el JS corregido, forzar revalidación (ver
   `correccion-produccion/cache-busting/`).
5. **Seguridad:** rotar la `sk_live` y sacarla del código.

---

## 8. Conclusión

- **Culqi está descartado como causa** (probado con cobro real 201).
- La falla se debe a **defectos en el código del sitio** (tema `mltheme`),
  identificados, con mecanismo explicado y **reproducibles**.
- Las correcciones fueron **validadas** en un entorno aislado (cobro real 201 con
  el código corregido).
- El diagnóstico **no requiere** atribuir el cambio a nadie ni a una fecha: se
  sostiene en la evidencia del propio código y en que la pasarela funciona.

---

### Anexo — Evidencia clave

- **Culqi funciona:** cobro real `HTTP 201`, `outcome.type = "venta_exitosa"`,
  `code = "AUT0000"` (referencias `chr_live_…`).
- **El “502” es del sitio:** `js/services/index.js`, `let statusCode = 502;`
  devuelto en el `catch`.
- **SyntaxError:** `template-tickets.php` L664 usa `$CURRENT_DAY`; se define solo
  en `ticketp1.php` L22-23 (incluido solo en el paso 1).
- **Entorno:** WordPress 6.5.12; jQuery 1.12.4 desde `ajax.googleapis.com`; sin
  jQuery Migrate.

*Documento del Área de TI. El entorno de prueba y los archivos corregidos están en
este mismo repositorio (`entorno-prueba/`, `correccion-produccion/`).*
