<?php
session_start();
/**
 * Crear una Orden con Culqi PHP — CORREGIDO.
 *
 * NOTA IMPORTANTE: para pagos con TARJETA la orden NO es necesaria. Las Órdenes
 * de Culqi son para Yape / PagoEfectivo / transferencia, exigen client_details
 * VÁLIDOS y un mínimo de S/ 6. Si solo se cobra con tarjeta, se puede omitir esta
 * llamada por completo.
 *
 * Cambios respecto al original:
 *  1) Devuelve 201 en éxito (main.js espera statusCode === 201) y 400 + JSON en error.
 *  2) Blinda los datos de $_SESSION['tickets'] con valor por defecto (sin warnings).
 */

try {
  require '../vendor/autoload.php';
  include_once dirname(__FILE__) . '/../vendor/culqi/culqi-php/lib/culqi.php';
  include_once '../settings.php';

  $culqi = new Culqi\Culqi(array('api_key' => SECRET_KEY));
  $tickets = $_SESSION['tickets'] ?? array();

  $order = $culqi->Orders->create(
    array(
      "amount" => $_POST["amount"],            // mínimo de 6 soles
      "currency_code" => $_POST["currency_code"],
      "description" => $_POST["description"],
      "order_number" => "#id-" . time(),
      "client_details" => array(
        "first_name" => $tickets['nombres'] ?? '',
        "last_name" => $tickets['apellidos'] ?? '',
        "email" => $tickets['email'] ?? '',
        "phone_number" => "999145221"
      ),
      "expiration_date" => time() + 24 * 60 * 60,
      "confirm" => false,
    )
  );

  http_response_code(201);
  header('Content-Type: application/json');
  echo json_encode($order);
} catch (Exception $e) {
  error_log($e->getMessage());
  http_response_code(400);
  header('Content-Type: application/json');
  echo json_encode(array("object" => "error", "message" => $e->getMessage()));
}
