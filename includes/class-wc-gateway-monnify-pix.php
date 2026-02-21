<?php
if (!defined('ABSPATH')) exit;

class WC_Gateway_Monnify_Pix extends WC_Payment_Gateway
{
  const META_CHARGE_ID     = '_monnify_charge_id';
  const META_REFERENCE_ID  = '_monnify_reference_id';
  const META_TXID          = '_monnify_txid';
  const META_QR_URL        = '_monnify_qr_url';
  const META_COPIA_COLA    = '_monnify_copia_e_cola';
  const META_CHECKOUT_URL  = '_monnify_checkout_url';
  const META_STATUS        = '_monnify_status';
  const META_LAST_ERROR    = '_monnify_last_error';

  public function __construct()
  {
    $this->id                 = 'monnify_pix';
    $this->method_title       = 'Monnify Pix';
    $this->method_description = 'Pague com Pix via Monnify (cobrança + webhook + polling).';
    $this->has_fields         = false;

    // Opcional: ícone no checkout
    // $this->icon = MONNIFY_PIX_WC_URL . 'assets/pix.svg';

    $this->supports = ['products'];

    $this->init_form_fields();
    $this->init_settings();

    $this->title       = $this->get_option('title');
    $this->description = $this->get_option('description');
    $this->enabled     = $this->get_option('enabled');

    add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

    // Exibir Pix no thank you / ver pedido / email
    add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
    add_action('woocommerce_view_order', [$this, 'view_order_page'], 10);
    add_action('woocommerce_email_instructions', [$this, 'email_instructions'], 10, 3);

    // Compatibilidade HPOS
    add_action('before_woocommerce_init', function () {
      if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
      }
    });
  }

  public function init_form_fields()
  {
    $webhook_url = rest_url('monnify/v1/webhook');

    $this->form_fields = [
      'enabled' => [
        'title'   => 'Ativar',
        'type'    => 'checkbox',
        'label'   => 'Ativar Monnify Pix',
        'default' => 'no',
      ],

      'title' => [
        'title'   => 'Título',
        'type'    => 'text',
        'default' => 'Pix (Monnify)',
      ],

      'description' => [
        'title'   => 'Descrição',
        'type'    => 'textarea',
        'default' => 'Pague via Pix. Você verá o QR Code e o código Copia e Cola após finalizar.',
      ],

      'base_url' => [
        'title'       => 'Base URL API',
        'type'        => 'text',
        'default'     => 'https://api.monnify.com.br',
        'description' => 'Ex: https://api.monnify.com.br',
      ],

      'secret_token' => [
        'title'       => 'Token de Autenticação (Bearer)',
        'type'        => 'password',
        'default'     => '',
        'description' => 'Cole aqui o token Bearer (YOUR_SECRET_TOKEN).',
      ],

      'webhook_token' => [
        'title'       => 'Token do Webhook (segurança)',
        'type'        => 'password',
        'default'     => wp_generate_password(24, false),
        'description' => 'Envie este token no header: <code>X-Monnify-Webhook-Token</code>',
      ],

      'polling_enabled' => [
        'title'   => 'Polling de status',
        'type'    => 'checkbox',
        'label'   => 'Ativar polling (fallback se webhook falhar)',
        'default' => 'yes',
      ],

      'polling_interval' => [
        'title'             => 'Intervalo de polling (min)',
        'type'              => 'number',
        'default'           => 2,
        'custom_attributes' => ['min' => 1, 'max' => 30],
      ],

      'webhook_info' => [
        'title'       => 'URL do Webhook',
        'type'        => 'title',
        'description' => '<code>' . esc_html($webhook_url) . '</code><br>Cadastre esta URL na Monnify e envie o header <code>X-Monnify-Webhook-Token</code>.',
      ],
    ];
  }

  private function api()
  {
    if (!class_exists('Monnify_API')) {
      // Evita fatal se a classe não tiver sido incluída ainda
      throw new Exception('Monnify_API não carregada. Verifique os includes do plugin.');
    }

    return new Monnify_API(
      (string) $this->get_option('base_url'),
      (string) $this->get_option('secret_token'),
      20
    );
  }

  private function normalize_paid_status($status)
  {
    $s = mb_strtolower(trim((string) $status));

    // você citou: concluida, concluido, pago, paid
    $paid_values = [
      'paid',
      'pago',
      'concluida',
      'concluído',
      'concluida',
      'concluido',
      'completed',
      'concluída',
    ];

    return in_array($s, $paid_values, true) ? 'paid' : $s;
  }

  public function validate_fields()
  {
    // Sem campos extras no checkout (has_fields = false)
    return true;
  }

  public function process_payment($order_id)
  {
    $order = wc_get_order($order_id);
    if (!$order) {
      wc_add_notice('Pedido inválido.', 'error');
      return ['result' => 'fail'];
    }

    $token = (string) $this->get_option('secret_token');
    if (!$token) {
      wc_add_notice('Monnify Pix: token Bearer não configurado no gateway.', 'error');
      $order->add_order_note('Monnify: token Bearer não configurado.');
      return ['result' => 'fail'];
    }

    // Total em centavos (sua API parece trabalhar com cents)
    $amount_cents = (int) round(((float) $order->get_total()) * 100);

    // Dados do cliente (WooCommerce)
    $nome  = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $email = (string) $order->get_billing_email();
    $phone = (string) $order->get_billing_phone();

    // Ajuste aqui caso seu CPF esteja em outro meta/campo
    $cpf = (string) $order->get_meta('_billing_cpf');

    $payload = [
      'type'        => 'immediate',
      'amount'      => $amount_cents,
      'customer_id' => (int) $order->get_customer_id(),
      'description' => 'Pedido #' . $order->get_order_number(),
      'metadata'    => [
        'nome'         => $nome,
        'cpf'          => $cpf,
        'email'        => $email,
        'phone'        => $phone,
        'wc_order_id'  => (int) $order->get_id(),
        'wc_order_key' => (string) $order->get_order_key(),
      ],
    ];

    try {
      $resp = $this->api()->create_charge($payload);
    } catch (Throwable $e) {
      wc_add_notice('Falha ao conectar na Monnify: ' . $e->getMessage(), 'error');
      $order->update_meta_data(self::META_LAST_ERROR, $e->getMessage());
      $order->save();
      return ['result' => 'fail'];
    }

    if (empty($resp['ok']) || empty($resp['json']['success'])) {
      $msg = 'Falha ao criar cobrança Pix.';
      if (!empty($resp['json']['message'])) $msg .= ' ' . $resp['json']['message'];

      wc_add_notice($msg, 'error');

      $order->update_meta_data(self::META_LAST_ERROR, wp_json_encode($resp));
      $order->add_order_note('Monnify: erro ao criar cobrança. ' . wp_json_encode($resp));
      $order->save();

      return ['result' => 'fail'];
    }

    $data = $resp['json']['data'] ?? [];
    $pix  = $data['pix'] ?? ($data['payment'] ?? []);

    $charge_id    = $data['id'] ?? null;
    $reference_id = $data['reference_id'] ?? null;
    $status       = $data['status'] ?? 'pending';

    $copia_e_cola = $pix['copia_e_cola'] ?? $pix['qr_code'] ?? ($pix['emv'] ?? null);

    // Se vier base64 real (imagem), você pode tratar depois. Hoje seu response usa URL.
    $qr_url = $pix['qr_code_url'] ?? $pix['qrcode_image_url'] ?? $pix['qr_code_base64'] ?? null;

    $txid         = $pix['txid'] ?? null;
    $checkout_url = $pix['checkout_url'] ?? null;

    // Salva dados no pedido (HPOS safe)
    $order->update_meta_data(self::META_CHARGE_ID, $charge_id);
    $order->update_meta_data(self::META_REFERENCE_ID, $reference_id);
    $order->update_meta_data(self::META_TXID, $txid);
    $order->update_meta_data(self::META_QR_URL, $qr_url);
    $order->update_meta_data(self::META_COPIA_COLA, $copia_e_cola);
    $order->update_meta_data(self::META_CHECKOUT_URL, $checkout_url);
    $order->update_meta_data(self::META_STATUS, (string) $status);
    $order->update_meta_data(self::META_LAST_ERROR, '');
    $order->save();

    // Coloca como aguardando pagamento
    $order->update_status('on-hold', 'Monnify: Pix gerado, aguardando pagamento.');

    // Agenda polling (se ativo)
    if ($this->get_option('polling_enabled') === 'yes' && class_exists('Monnify_Polling')) {
      Monnify_Polling::schedule_check($order->get_id());
    }

    // Redireciona para "Pedido recebido"
    return [
      'result'   => 'success',
      'redirect' => $this->get_return_url($order),
    ];
  }

  public function thankyou_page($order_id)
  {
    $order = wc_get_order($order_id);
    if (!$order) return;

    echo $this->render_pix_box($order);
  }

  public function view_order_page($order_id)
  {
    $order = wc_get_order($order_id);
    if (!$order) return;

    if ($order->get_payment_method() !== $this->id) return;

    echo $this->render_pix_box($order);
  }

  public function email_instructions($order, $sent_to_admin, $plain_text)
  {
    if ($sent_to_admin) return;
    if (!$order || $order->get_payment_method() !== $this->id) return;
    if (!in_array($order->get_status(), ['on-hold', 'pending'], true)) return;

    $copia    = (string) $order->get_meta(self::META_COPIA_COLA);
    $checkout = (string) $order->get_meta(self::META_CHECKOUT_URL);

    if ($plain_text) {
      echo "\nPague via Pix\n";
      if ($checkout) echo "Link: {$checkout}\n";
      if ($copia) echo "Copia e Cola:\n{$copia}\n";
      return;
    }

    echo '<h2>Pague via Pix</h2>';
    if ($checkout) echo '<p><a href="' . esc_url($checkout) . '">Abrir checkout Pix</a></p>';
    if ($copia) echo '<p><strong>Copia e Cola</strong><br><code style="display:block;white-space:pre-wrap">' . esc_html($copia) . '</code></p>';
  }

  private function render_pix_box(WC_Order $order)
  {
    $status   = (string) $order->get_meta(self::META_STATUS);
    $qr_url   = (string) $order->get_meta(self::META_QR_URL);
    $copia    = (string) $order->get_meta(self::META_COPIA_COLA);
    $txid     = (string) $order->get_meta(self::META_TXID);
    $charge   = (string) $order->get_meta(self::META_CHARGE_ID);
    $checkout = (string) $order->get_meta(self::META_CHECKOUT_URL);

    $normalized = $this->normalize_paid_status($status);

    ob_start(); ?>
      <section class="woocommerce-order-details" style="margin-top:16px;padding:16px;border:1px solid #e5e7eb;border-radius:12px;">
        <h2>Pagamento via Pix</h2>

        <p>
          <strong>Status:</strong>
          <?php echo esc_html($order->get_status()); ?>
          (Monnify: <?php echo esc_html($normalized ?: 'pending'); ?>)
        </p>

        <?php if ($txid): ?>
          <p><strong>TXID:</strong> <?php echo esc_html($txid); ?></p>
        <?php endif; ?>

        <?php if ($checkout): ?>
          <p>
            <a class="button" href="<?php echo esc_url($checkout); ?>" target="_blank" rel="noopener">
              Abrir checkout Pix
            </a>
          </p>
        <?php endif; ?>

        <?php if ($qr_url): ?>
          <div style="max-width:320px;margin:12px 0;">
            <img
              src="<?php echo esc_url($qr_url); ?>"
              alt="QR Code Pix"
              style="width:100%;height:auto;border-radius:12px;border:1px solid #eee;"
            >
          </div>
        <?php endif; ?>

        <?php if ($copia): ?>
          <p><strong>Copia e Cola</strong></p>

          <textarea id="monnify-copia" readonly style="width:100%;min-height:110px;white-space:pre-wrap;"><?php
            echo esc_textarea($copia);
          ?></textarea>

          <p style="margin-top:8px;">
            <button type="button" class="button" id="monnify-copy-btn">Copiar código Pix</button>
          </p>

          <script>
            (function () {
              const btn = document.getElementById('monnify-copy-btn');
              const ta  = document.getElementById('monnify-copia');
              if (!btn || !ta) return;

              btn.addEventListener('click', async function () {
                try {
                  await navigator.clipboard.writeText(ta.value);
                  btn.textContent = 'Copiado!';
                  setTimeout(() => (btn.textContent = 'Copiar código Pix'), 1500);
                } catch (e) {
                  // fallback
                  ta.focus();
                  ta.select();
                  document.execCommand('copy');
                  btn.textContent = 'Copiado!';
                  setTimeout(() => (btn.textContent = 'Copiar código Pix'), 1500);
                }
              });
            })();
          </script>
        <?php endif; ?>

        <?php if ($charge): ?>
          <p style="opacity:.8;font-size:12px;margin-top:10px;">
            ID da cobrança: <?php echo esc_html($charge); ?>
          </p>
        <?php endif; ?>
      </section>
    <?php
    return ob_get_clean();
  }
}