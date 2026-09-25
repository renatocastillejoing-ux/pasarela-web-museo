# Entorno de prueba aislado

Código nuevo, mínimo, **sin WordPress ni jQuery**, para probar el cobro con
Culqi de forma limpia.

## Ejecutar

```bash
pip install -r requirements.txt
cp .env.example .env          # y pon tus llaves de Culqi
python server.py              # http://127.0.0.1:8891
```

## Páginas

- **`/`** (`index.html`) — Cobro directo contra Culqi. Muestra la respuesta
  EXACTA de Culqi (status + cuerpo). Sirve para comprobar que Culqi funciona.
- **`/corregido`** (`index-corregido.html`) — Réplica del flujo del sitio con la
  clase `Service` **corregida** (usa `fetch` en vez de `$.ajax`). Demuestra que,
  con esa corrección, la petición **sí se envía** a `ajax/charge.php` y se evalúa
  el éxito con `statusCode === 201`, igual que `main.js`.

## Cómo leer el resultado

| Lo que ves | Qué significa |
|---|---|
| ✔ "Token generado" | El frontend de Culqi tokeniza bien. |
| ✅ "CARGO EXITOSO" (HTTP 201) | Culqi funciona de punta a punta. |
| ⚠ Error 4xx de Culqi | Culqi **responde** (validación/rechazo). No es un 502; el servicio está operativo. |
| 502 "no se pudo contactar" | Problema de red de esta máquina hacia Culqi. |

## Notas

- **Modo LIVE**: con llaves `_live_` el cobro es **real**. Para probar sin dinero
  usa llaves `_test_` y la tarjeta de prueba `4111 1111 1111 1111`, fecha futura, CVV `123`.
- El `.env` con llaves reales **no se sube a git** (está en `.gitignore`).
