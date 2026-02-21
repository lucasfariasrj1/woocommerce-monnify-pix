<?php
if (!defined('ABSPATH')) exit;

class Monnify_Polling {
  const HOOK = 'monnify_pix_poll_charge_status';

  public static function init() {
    add_action(self::HOOK, [__CLASS__, 'run_check'], 10, 1);
  }

  public static function schedule_check($order_id) {
    $settings = get_option('woocommerce_monnify_pix_settings', []);
    $interval_min = (int)($settings['polling_interval'] ?? 2);
    if ($interval_min < 1) $interval_min = 1;

    $timestamp = time() + ($interval_min * 60);

    // Action Scheduler (WooCommerce)
    if (function_exists('as_schedule_single_action')) {
      as_schedule_single_action($timestamp, self::HOOK, [(int)$order_id], 'monnify-pix');
      return;
    }

    // Fallback WP-Cron
    wp_schedule_single_event($timestamp, self::HOOK, [(int)$order_id]);
  }

  public static function run_check($order_id) {
    $order = wc_get_order((int)$order_id);
    if (!$order) return;

    if ($order->is_paid()) return;

    $status = $order->get_status();
    if (!in_array($status, ['on-hold', 'pending'], true)) return;

    $charge_id = (string)$order->get_meta('_monnify_charge_id');
    if (!$charge_id) return;

    $settings = get_option('woocommerce_monnify_pix_settings', []);
    $base_url = $settings['base_url'] ?? 'https://api.monnify.com.br';
    $token    = $settings['secret_token'] ?? '';

    if (!$token) return;

    $api = new Monnify_API($base_url, $token, 20);
    $resp = $api->get_charge_status($charge_id);

    if (!$resp['ok'] || empty($resp['json']['success'])) {
      $order->add_order_note('Monnify polling: falha ao consultar status. ' . wp_json_encode($resp));
      // Reagendar (evitar parar por instabilidade)
      self::schedule_check($order_id);
      return;
    }

    $data = $resp['json']['data'] ?? [];
    $remote_status = (string)($data['status'] ?? 'pending');

    // Aplica regra igual webhook
    Monnify_Webhook::apply_status_to_order($order, $remote_status, $resp['json']);

    // Se ainda não pagou, reagenda
    if (!$order->is_paid()) {
      self::schedule_check($order_id);
    }
  }
}