<?php
/**
 * Plugin Name: Monnify Pix for WooCommerce (TESTE)
 * Description: Gateway Pix Monnify com cobrança, webhook e polling de status.
 * Version: 0.0.1
 * Author: LF Developer
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

define('MONNIFY_PIX_WC_VERSION', '0.0.1');
define('MONNIFY_PIX_WC_PATH', plugin_dir_path(__FILE__));
define('MONNIFY_PIX_WC_URL', plugin_dir_url(__FILE__));

/**
 * Inicializa o gateway após o WooCommerce carregar.
 */
add_action('plugins_loaded', 'monnify_pix_init_gateway', 20);

function monnify_pix_init_gateway() {
  // Garante que o WooCommerce carregou a base do gateway
  if (!class_exists('WC_Payment_Gateway')) {
    return;
  }

  // Inclui dependências do plugin (ordem importa)
  $gateway_file = MONNIFY_PIX_WC_PATH . 'includes/class-wc-gateway-monnify-pix.php';

  if (!file_exists($gateway_file)) {
    // Evita fatal caso o arquivo não exista
    error_log('[Monnify Pix] Arquivo do gateway não encontrado: ' . $gateway_file);
    return;
  }

  require_once $gateway_file;

  // Registra o gateway na lista do WooCommerce
  add_filter('woocommerce_payment_gateways', 'monnify_pix_register_gateway');
}

/**
 * Adiciona o gateway Monnify Pix na lista de métodos do WooCommerce.
 */
function monnify_pix_register_gateway($methods) {
  if (class_exists('WC_Gateway_Monnify_Pix')) {
    $methods[] = 'WC_Gateway_Monnify_Pix';
  } else {
    error_log('[Monnify Pix] Classe WC_Gateway_Monnify_Pix não carregada.');
  }
  return $methods;
}

/**
 * Link rápido "Configurações" na lista de plugins.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
  if (!class_exists('WooCommerce')) return $links;

  $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=monnify_pix');
  $links[] = '<a href="' . esc_url($url) . '">Configurações</a>';
  return $links;
});