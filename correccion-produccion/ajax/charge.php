<?php
session_start();
/**
 * Crear un cargo con Culqi PHP — CORREGIDO.
 *
 * Cambios respecto al original:
 *  1) Devuelve el CÓDIGO HTTP correcto: 201 cuando el cargo se APRUEBA. main.js
 *     considera éxito solo con statusCode === 201; el original hacía `echo` sin
 *     fijar código (HTTP 200), así que un cobro exitoso se mostraba como
 *     "CARGO FALLIDO" aunque el cliente YA estaba cobrado (riesgo de cobro doble).
 *  2) Si el cargo queda en revisión 3DS (action_code REVIEW) devuelve 200, que
 *     es lo que main.js espera para disparar la autenticación 3DS.
 *  3) Ante error, devuelve 400 + un JSON de error (no texto suelto con 200), para
 *     que el front muestre el fallo real y NO lo confunda con un éxito.
 *  4) Los datos de $_SESSION['tickets'] se leen con valor por defecto para no
 *     generar warnings ni romper cuando la sesión no está poblada.
 */

try {
  require '../vendor/autoload.php';
  include_once dirname(__FILE__) . '/../vendor/culqi/culqi-php/lib/culqi.php';
  include_once '../settings.php';

  $culqi = new Culqi\Culqi(array('api_key' => SECRET_KEY));
  $encryption_params = array(
    "rsa_public_key" => RSA_PUBLIC_KEY,
    "rsa_id" => RSA_ID
  );

  $_SESSION['result'] = array();
  $_SESSION['creation_date'] = substr((string) (10000 * microtime(true)), 0, 15);

  $tickets = $_SESSION['tickets'] ?? array();

  // 3DS: la primera vez NO se envían parámetros 3DS.
  $tds = array();
  if (isset($_POST["eci"])) {
    $tds = array(
      "authentication_3DS" => array(
        "eci" => $_POST["eci"],
        "xid" => $_POST["xid"],
        "cavv" => $_POST["cavv"],
        "protocolVersion" => $_POST["protocolVersion"],
        "directoryServerTransactionId" => $_POST["directoryServerTransactionId"]
      )
    );
  }

  $req_body = array(
    "amount" => $_POST["amount"],
    "currency_code" => $_POST["currency_code"],
    "capture" => true,
    "email" => $_POST["email"],
    "source_id" => $_POST["token"],
    "description" => $_POST["description"],
    "antifraud_details" => array(
      "address" => "Andres Reyes 338",
      "address_city" => $tickets['paisname'] ?? '',
      "country_code" => $tickets['pais'] ?? '',
      "first_name" => $tickets['nombres'] ?? '',
      "last_name" => $tickets['apellidos'] ?? '',
      "phone_number" => "123456789",
      "device_finger_print_id" => $_POST["deviceId"] ?? ''
    ),
    "metadata" => array(
      "order_id" => $_SESSION['creation_date'],
      "user_id" => $tickets['email'] ?? '',
      "sponsor" => "Museo Larco"
    )
  );
  $with_tds = $req_body + $tds;

  if (ACTIVE_ENCRYPT) {
    $charge = $culqi->Charges->create($with_tds, $encryption_params);
  } else {
    $charge = $culqi->Charges->create($with_tds);
  }

  $resp = json_decode(json_encode($charge), true);
  $_SESSION['result'] = $resp;

  // action_code REVIEW => 3DS pendiente (200). En otro caso => aprobado (201).
  $actionCode = $resp['action_code'] ?? ($resp['outcome']['action_code'] ?? null);
  http_response_code($actionCode === 'REVIEW' ? 200 : 201);

  header('Content-Type: application/json');
  echo json_encode($charge);

} catch (Exception $e) {
  error_log($e->getMessage());
  http_response_code(400);
  $_SESSION['result'] = array("error" => $e->getMessage());
  header('Content-Type: application/json');
  echo json_encode(array("object" => "error", "message" => $e->getMessage()));
}
