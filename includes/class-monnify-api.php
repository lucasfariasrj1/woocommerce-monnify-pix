<?php
if (!defined('ABSPATH')) exit;

class Monnify_API {
  private $base_url;
  private $token;
  private $timeout;

  public function __construct($base_url, $token, $timeout = 20) {
    $this->base_url = rtrim($base_url, '/');
    $this->token = $token;
    $this->timeout = (int)$timeout;
  }

  private function headers() {
    return [
      'Content-Type'  => 'application/json',
      'Authorization' => 'Bearer ' . $this->token,
    ];
  }

  public function create_charge(array $payload) {
    $url = $this->base_url . '/tenant/charges';

    $res = wp_remote_post($url, [
      'timeout' => $this->timeout,
      'headers' => $this->headers(),
      'body'    => wp_json_encode($payload),
    ]);

    return $this->normalize_response($res);
  }

  public function get_charge_status($charge_id) {
    $url = $this->base_url . '/tenant/charges/' . rawurlencode($charge_id) . '/status';

    $res = wp_remote_get($url, [
      'timeout' => $this->timeout,
      'headers' => $this->headers(),
    ]);

    return $this->normalize_response($res);
  }

  private function normalize_response($res) {
    if (is_wp_error($res)) {
      return ['ok' => false, 'error' => $res->get_error_message()];
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    $json = json_decode($body, true);

    if ($code < 200 || $code >= 300) {
      return [
        'ok' => false,
        'error' => 'HTTP ' . $code,
        'raw' => $body,
        'json' => $json,
      ];
    }

    if (!is_array($json)) {
      return ['ok' => false, 'error' => 'Resposta inválida', 'raw' => $body];
    }

    return ['ok' => true, 'json' => $json];
  }
}