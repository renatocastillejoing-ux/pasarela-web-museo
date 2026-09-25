"""
Entorno de prueba aislado — cargo con Culqi (código nuevo, sin WordPress).

Sirve para dos cosas:
  1) Cobro directo contra Culqi (index.html) → demuestra que Culqi funciona.
  2) Réplica del flujo del sitio con el código CORREGIDO (index-corregido.html)
     → demuestra que, cambiando $.ajax por fetch, la petición SÍ se envía.

Flujo:
    navegador (Checkout v4 + pk_...)  ->  token  tkn_...
    este backend (sk_...)             ->  POST https://api.culqi.com/v2/charges
Devuelve la respuesta EXACTA de Culqi (status + cuerpo), sin convertirla en 502.

Ejecutar:
    pip install -r requirements.txt
    copiar .env.example a .env y poner las llaves
    python server.py         # abre en http://127.0.0.1:8891
"""
from __future__ import annotations

import logging
import os
from pathlib import Path

import httpx
from dotenv import load_dotenv
from fastapi import FastAPI, Request
from fastapi.responses import FileResponse, JSONResponse
from pydantic import BaseModel

BASE = Path(__file__).resolve().parent
load_dotenv(BASE / ".env")

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
log = logging.getLogger("prueba_compra")


def _limpiar(v: str | None) -> str:
    return (v or "").replace("\r", "").replace("\n", "").strip().strip('"').strip("'")


PUBLIC_KEY = _limpiar(os.getenv("CULQI_PUBLIC_KEY"))
SECRET_KEY = _limpiar(os.getenv("CULQI_SECRET_KEY"))
MONTO = int(_limpiar(os.getenv("MONTO_CENTIMOS")) or "100")   # 100 = S/ 1.00
CURRENCY = "PEN"
CULQI_CHARGES = "https://api.culqi.com/v2/charges"
MODO = "LIVE" if "_live_" in SECRET_KEY else ("TEST" if "_test_" in SECRET_KEY else "?")

if not SECRET_KEY.startswith("sk_"):
    raise RuntimeError("Falta CULQI_SECRET_KEY (sk_...) en el archivo .env")
if not PUBLIC_KEY.startswith("pk_"):
    raise RuntimeError("Falta CULQI_PUBLIC_KEY (pk_...) en el archivo .env")

app = FastAPI(title="Prueba de compra Culqi")


class CargoIn(BaseModel):
    token: str
    email: str


@app.get("/api/config")
async def config():
    """Datos para el navegador. La llave secreta NUNCA se envía."""
    return {"public_key": PUBLIC_KEY, "amount": MONTO, "currency": CURRENCY, "modo": MODO}


@app.post("/api/cargo")
async def cargo(data: CargoIn):
    """Cobro directo (index.html): crea el cargo y devuelve la respuesta EXACTA de Culqi."""
    body = {
        "amount": MONTO,
        "currency_code": CURRENCY,
        "email": data.email,
        "source_id": data.token,
        "description": "Prueba de compra 1 sol",
    }
    log.info("POST /v2/charges  monto=%s source=%s...", MONTO, data.token[:12])
    try:
        async with httpx.AsyncClient(timeout=30) as cli:
            r = await cli.post(
                CULQI_CHARGES, json=body,
                headers={"Authorization": f"Bearer {SECRET_KEY}", "Content-Type": "application/json"},
            )
    except httpx.RequestError as e:
        log.error("No se pudo contactar a Culqi: %s", e)
        return JSONResponse(status_code=502, content={
            "ok": False, "etapa": "conexion_culqi",
            "detalle": f"No se pudo contactar a la API de Culqi: {e}"})
    try:
        cuerpo = r.json()
    except Exception:
        cuerpo = {"raw": r.text}
    log.info("Culqi respondio %s", r.status_code)
    return {"ok": r.status_code in (200, 201), "culqi_status": r.status_code, "culqi_response": cuerpo}


@app.post("/ajax/charge.php")
async def ajax_charge_php(request: Request):
    """
    Réplica local del ajax/charge.php CORREGIDO: recibe form-urlencoded (como lo
    manda la clase Service corregida), cobra en Culqi y devuelve el status real
    de Culqi (201 en éxito). Lo usa index-corregido.html.
    """
    form = await request.form()
    body = {
        "amount": int(form.get("amount") or MONTO),
        "currency_code": form.get("currency_code") or CURRENCY,
        "capture": True,
        "email": form.get("email") or "prueba@museolarco.org",
        "source_id": form.get("token") or "",
        "description": form.get("description") or "Replica corregida",
    }
    log.info("ajax/charge.php  monto=%s source=%s...", body["amount"], body["source_id"][:12])
    try:
        async with httpx.AsyncClient(timeout=30) as cli:
            r = await cli.post(
                CULQI_CHARGES, json=body,
                headers={"Authorization": f"Bearer {SECRET_KEY}", "Content-Type": "application/json"},
            )
    except httpx.RequestError as e:
        return JSONResponse(status_code=502, content={"object": "error", "message": str(e)})
    try:
        cuerpo = r.json()
    except Exception:
        cuerpo = {"raw": r.text}
    log.info("Culqi (replica) respondio %s", r.status_code)
    return JSONResponse(status_code=r.status_code, content=cuerpo)


@app.get("/corregido")
async def corregido():
    return FileResponse(BASE / "index-corregido.html")


@app.get("/")
async def index():
    return FileResponse(BASE / "index.html")


if __name__ == "__main__":
    import uvicorn

    log.info("Prueba de compra | modo=%s | monto=%d centimos (S/ %.2f)", MODO, MONTO, MONTO / 100)
    uvicorn.run(app, host="127.0.0.1", port=8891)
