<?php

/*
 * Plugin Name: Paylith WooCommerce
 * Plugin URI: http://www.paylith.com
 * Description: Paylith üyeliğiniz ile WooCommerce üzerinden ödeme almanız için gerekli altyapı.
 * Version: 1.0.0
 * Author: Paylith
 * Author URI: https://github.com/paylith
 */

if (! defined('ABSPATH')) {
    exit;
}

define('PAYLITH_VERSION', '1.0.0');
define('PAYLITH_LANG_PATH', plugin_basename(dirname(__FILE__)) . '/i18n/languages/');
define('PAYLITH_PLUGIN_DIR', plugin_dir_url(__FILE__));

add_action('plugins_loaded', 'woocommerce_paylith');
add_filter('woocommerce_payment_gateways', 'paylith_init_woocommerce');

function paylith_init_woocommerce()
{
    $methods[] = 'Paylith';

    return $methods;
}

function paylith_load_languages()
{
    load_plugin_textdomain('woocommerce-paylith', false, PAYLITH_LANG_PATH);
}

function woocommerce_paylith()
{
    paylith_load_languages();

    class Paylith extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'paylith';
            $this->icon = PAYLITH_PLUGIN_DIR . 'images/cards.png';
            $this->has_fields = true;
            $this->method_title = __('Paylith', 'woocommerce-paylith');;
            $this->method_description = __('Easy Checkout', 'woocommerce-paylith');
            $this->order_button_text = __('Pay With Card', 'woocommerce-paylith');

            $this->paylith_init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');

            $this->paylith_api_key = trim($this->get_option('paylith_api_key'));
            $this->paylith_api_secret = trim($this->get_option('paylith_api_secret'));

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_receipt_' . $this->id, [$this, 'paylith_receipt_page']);
            add_action('woocommerce_api_paylith', [$this, 'paylith_response']);
        }

        public function paylith_generate_hash(
            string $api_key,
            string $api_secret,
            string $conversation_id,
            string $user_id,
            string $user_email,
            string $user_ip_address
        ) {
            $hash_str = [
                'apiKey' => $api_key,
                'conversationId' => $conversation_id,
                'userEmail' => $user_email,
                'userId' => $user_id,
                'userIpAddress' => $user_ip_address,
            ];

            $hash = hash_hmac('sha256', implode('|', $hash_str) . $api_secret, $api_key);

            return hash_hmac('md5', $hash, $api_key);
        }

        public function paylith_init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => __('Status', 'woocommerce-paylith'),
                    'type' => 'checkbox',
                    'label' => __('Aktif', 'woocommerce'),
                    'default' => 'yes',
                ],
                'title' => [
                    'title' => __('Payment Value Message', 'woocommerce-paylith'),
                    'type' => 'text',
                    'description' => __('This message will show to the user during checkout.', 'woocommerce-paylith'),
                    'default' => __('Banka / Kredi Kartı ile Öde', 'woocommerce-paylith'),
                    'desc_tip' => true,
                ],
                'description' => [
                    'title' => __('Payment Form Description Value', 'woocommerce-paylith'),
                    'type' => 'text',
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce-paylith'),
                    'default' => __('Pay with your credit card via Paylith.', 'woocommerce-paylith'),
                    'desc_tip' => true,
                ],
                'paylith_api_key' => [
                    'title' => __('Api Key', 'woocommerce-paylith'),
                    'type' => 'text',
                    'default' => '',
                ],
                'paylith_api_secret' => [
                    'title' => __('Secret Key', 'woocommerce-paylith'),
                    'type' => 'text',
                    'default' => '',
                ],
            ];
        }

        public function admin_options()
        {
            ?>
            <h2><?php _e('Paylith WooCommerce', 'woocommerce') ?></h2>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
                <tr valign="top">
                    <th scope="row" class="titledesc">
                        <label><?php _e('Callback URL', 'woocommerce-paylith') ?></label>
                    </th>

                    <td class="forminp">
                        <fieldset>
                            <input class="input-text regular-input" type="text" value="<?php echo $this->get_callback_url() ?>" readonly/>
                        </fieldset>
                    </td>
                </tr>
            </table>
            <?php
        }

        public function process_payment($order_id)
        {
            global $woocommerce;

            $order = new WC_Order($order_id);
            $order->update_status('unpaid', __('Paylith üzerinden ödeme işleminin tamamlanması bekleniyor.', 'woocommerce'));

            $woocommerce
                ->cart
                ->empty_cart();

            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            ];
        }

        public function paylith_receipt_page($order_id)
        {
            $order = new WC_Order($order_id);

            $paylith_api_key = $this->get_option('paylith_api_key');
            $paylith_secret_key = $this->get_option('paylith_api_secret');
            $paylith_token = $this->paylith_generate_hash(
                $paylith_api_key,
                $paylith_secret_key,
                $order_id,
                $order->get_user_id(),
                $order->get_billing_email(),
                $order->get_customer_ip_address(),
            );

            $payload = [
                'apiKey' => $paylith_api_key,
                'token' => $paylith_token,
                'conversationId' => (string) $order_id,
                'userId' => $order->get_user_id(),
                'userEmail' => $order->get_billing_email(),
                'userIpAddress' => $order->get_customer_ip_address(),
                'productApi' => true,
                'productData' => [
                    'name' => sprintf(__('Order #%s', 'woocommerce'), $order->get_order_number()),
                    'amount' => (number_format($order->get_total(), 2, '.', '') * 100),
                    'paymentChannels' => [1],
                ],
            ];

            $payload = json_encode($payload);

            $args = [
                'timeout' => '90',
                'redirection' => '5',
                'httpversion' => '1.0',
                'sslverify' => false,
                'blocking' => true,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Content-Length' => strlen($payload),
                ],
                'body' => $payload,
            ];

            $response = wp_remote_post('https://api.paylith.com/v1/token', $args);

            if (is_wp_error($response)) {
                return 'Failed to handle response. Error: ' . $response->get_error_message();
            }

            $result = wp_remote_retrieve_body($response);

            try {
                $result = json_decode($result, true);
            } catch (Exception $e) {
                return 'Failed to handle response';
            }

            if ($result['status'] == 'success') {
                header('Location:' . $result['paymentLink']);
            } else {
                echo 'Entegrasyon bilgileri geçersiz!';
            }
        }

        public function paylith_response()
        {
            if (! $_POST || $_POST['status'] != 'SUCCESS') {
                die('[Paylith]: Invalid request');
            }

            $order_id = isset($_POST['orderId']) ? $_POST['orderId'] : null;
            $payment_amount = isset($_POST['paymentAmount']) ? $_POST['paymentAmount'] : null;
            $conversation_id = isset($_POST['conversationId']) ? $_POST['conversationId'] : null;
            $user_id = isset($_POST['userId']) ? $_POST['userId'] : null;
            $status = isset($_POST['status']) ? $_POST['status'] : null;
            $hash = isset($_POST['hash']) ? $_POST['hash'] : null;

            if (! $conversation_id || ! $order_id || ! $payment_amount || ! $status || ! $user_id || ! $hash) {
                die('[Paylith]: Bad request');
            }

            $paylith_api_key = $this->get_option('paylith_api_key');
            $paylith_api_secret = $this->get_option('paylith_api_secret');

            $parameters = [
                'orderId' => $order_id,
                'paymentAmount' => $payment_amount,
                'conversationId' => $conversation_id,
                'userId' => $user_id,
                'status' => $status,
            ];

            ksort($parameters);

            $hash_string = implode('|', $parameters);
            $generated_hash = hash_hmac('sha256', $hash_string . $paylith_api_secret, $paylith_api_key);
            $generated_hash = hash_hmac('md5', $generated_hash, $paylith_api_key);

            if ($generated_hash != $hash) {
                die('[Paylith]: Calculated hashes doesn\'t match!');
            }

            $order = new WC_Order($conversation_id);
            if ($order->get_status() != 'processing' || $order->get_status() != 'completed') {
                if ($status == 'SUCCESS') {
                    $order->payment_complete();
                    $order->add_order_note("Ödeme onaylandı.<br />Sipariş numarası: $conversation_id");
                } else {
                    $order->update_status('failed', "Sipariş iptal edildi.<br />Sipariş numarası: $conversation_id", 'woothemes');
                }
            } else {
                die('[Paylith]: Unexpected order status: ' . $order->get_status());
            }

            die('OK');
        }

        private function get_callback_url()
        {
            return str_replace('https:', 'http:', add_query_arg('wc-api', 'paylith', home_url('/')));
        }
    }
}
