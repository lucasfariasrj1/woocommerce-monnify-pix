<?php
if (!defined('ABSPATH')) exit;

class Monnify_Webhook {

  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
  }

  public static function register_routes() {
    register_rest_route('monnify/v1', '/webhook', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'handle'],
      'permission_callback' => '__return_true',
    ]);
  }

  private static function get_gateway_settings() {
    $settings = get_option('woocommerce_monnify_pix_settings', []);
    return is_array($settings) ? $settings : [];
  }

  public static function handle(WP_REST_Request $request) {
    $settings = self::get_gateway_settings();
    $expected = $settings['webhook_token'] ?? '';

    $token = $request->get_header('x-monnify-webhook-token');
    if (!$expected || !$token || !hash_equals($expected, $token)) {
      return new WP_REST_Response(['ok' => false, 'error' => 'unauthorized'], 401);
    }

    $payload = $request->get_json_params();
    if (!is_array($payload)) {
      return new WP_REST_Response(['ok' => false, 'error' => 'invalid_json'], 400);
    }

    // Tente localizar o pedido:
    // 1) metadata.wc_order_id
    // 2) charge_id no payload
    $order_id = $payload['metadata']['wc_order_id'] ?? null;
    $charge_id = $payload['data']['charge_id'] ?? ($payload['charge_id'] ?? null);
    $status = $payload['data']['status'] ?? ($payload['status'] ?? null);

    $order = null;

    if ($order_id) {
      $order = wc_get_order((int)$order_id);
    }

    if (!$order && $charge_id) {
      $order_id = self::find_order_id_by_charge_id($charge_id);
      if ($order_id) $order = wc_get_order((int)$order_id);
    }

    if (!$order) {
      return new WP_REST_Response(['ok' => false, 'error' => 'order_not_found'], 404);
    }

    self::apply_status_to_order($order, (string)$status, $payload);

    return new WP_REST_Response(['ok' => true], 200);
  }

  private static function find_order_id_by_charge_id($charge_id) {
    $query = new WC_Order_Query([
      'limit' => 1,
      'type' => 'shop_order',
      'meta_key' => '_monnify_charge_id',
      'meta_value' => (string)$charge_id,
      'return' => 'ids',
    ]);
    $ids = $query->get_orders();
    return !empty($ids) ? (int)$ids[0] : 0;
  }

  public static function apply_status_to_order(WC_Order $order, $status, $payload = []) {
    $s = mb_strtolower(trim((string)$status));

    // Seu backend citou: concluida, concluido, pago, paid
    $paid_values = ['paid', 'pago', 'concluida', 'concluído', 'concluida', 'concluido', 'completed'];

    $order->update_meta_data('_monnify_last_webhook', wp_json_encode($payload));
    $order->update_meta_data('_monnify_status', $s);
    $order->save();

    if (in_array($s, $paid_values, true)) {
      if ($order->is_paid()) return;

      $order->payment_complete(); // marca pago e registra transação
      $order->add_order_note('Monnify: pagamento confirmado via webhook. Status=' . $s);
      return;
    }

    if ($s === 'pending') {
      if ($order->get_status() !== 'on-hold') {
        $order->update_status('on-hold', 'Monnify: pendente (webhook).');
      }
      return;
    }

    if ($s === 'canceled' || $s === 'cancelled' || $s === 'cancelado') {
      $order->update_status('cancelled', 'Monnify: cancelado (webhook).');
    }
  }
}