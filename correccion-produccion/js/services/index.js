//import config  from "../config/index.js"

/**
 * Service — CORREGIDO.
 *
 * CAMBIO CLAVE: se reemplaza `$.ajax` (jQuery) por `fetch()` nativo.
 * Motivo: en la página, `$` (jQuery) NO está disponible (WordPress corre jQuery
 * en modo noConflict; por eso el error de consola "$ is not a function"). La
 * llamada `$.ajax(...)` lanzaba una excepción ANTES de enviar la petición, caía
 * al catch y devolvía un `statusCode: 502` inventado — sin llegar nunca al
 * servidor (por eso en Network no aparecía charge.php / order.php).
 *
 * `fetch` es nativo del navegador, no depende de jQuery, y así la petición SÍ
 * se envía. Se mantienen los mismos endpoints, los mismos campos del body
 * (form-urlencoded, que es lo que leen los PHP con $_POST) y la misma forma de
 * retorno `{ statusCode, data }` para no tocar nada de main.js.
 */
class Service {
  #BASE_URL = URL_BASE;

  // POST en application/x-www-form-urlencoded usando fetch.
  #post = async (endPoint, params) => {
    const data = new URLSearchParams();
    // No enviamos valores vacíos (evita mandar "undefined"/"null" al backend).
    Object.entries(params).forEach(([clave, valor]) => {
      if (valor !== undefined && valor !== null && valor !== "") {
        data.append(clave, valor);
      }
    });

    try {
      const response = await fetch(`${this.#BASE_URL}/${endPoint}`, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: data,
      });

      // charge.php a veces devuelve texto plano (mensaje de error de Culqi) en
      // vez de JSON; por eso parseamos con cuidado y no rompemos si no es JSON.
      const texto = await response.text();
      let cuerpo;
      try { cuerpo = JSON.parse(texto); } catch (_e) { cuerpo = texto; }

      return { statusCode: response.status, data: cuerpo };
    } catch (err) {
      // Solo se llega aquí si de verdad no se pudo contactar al servidor
      // (sin red / servidor caído). Ya NO es un 502 inventado.
      console.error("Error de red al llamar a", endPoint, err);
      return { statusCode: 0, data: null };
    }
  };

  // Aplana los parámetros 3DS (si existen) para enviarlos como campos planos.
  #params3DS = (body) => {
    const t = body.authentication_3DS;
    if (!t) return {};
    return {
      eci: t.eci,
      xid: t.xid,
      cavv: t.cavv,
      protocolVersion: t.protocolVersion,
      directoryServerTransactionId: t.directoryServerTransactionId,
    };
  };

  createOrder = async (body) => this.#post("ajax/order.php", {
    amount: body.amount,
    currency_code: body.currency_code,
    description: body.description,
  });

  createCard = async (body) => this.#post("ajax/card.php", {
    amount: body.amount,
    currency_code: body.currency_code,
    email: body.email,
    token: body.source_id,
    customer_id: body.customer_id,
    description: body.description,
    deviceId: body.antifraud_details?.device_finger_print_id,
    ...this.#params3DS(body),
  });

  createCharge = async (body) => this.#post("ajax/charge.php", {
    amount: body.amount,
    currency_code: body.currency_code,
    email: body.email,
    token: body.source_id,
    customer_id: body.customer_id,
    description: body.description,
    deviceId: body.antifraud_details?.device_finger_print_id,
    ...this.#params3DS(body),
  });
}

export default Service;
