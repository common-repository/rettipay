<?php // phpcs:disable
/**
 * Rettipay Payment Gateway
 *
 * Provides a Rettipay Payment Gateway for checkout
 *
 * @class       WC_Gateway_Rettipay
 * @extends     WC_Payment_Gateway
 * @since       1.0.0
 * @package     WooCommerce/Classes/Payment
 * @author      WooThemes
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * WC_Gateway_Rettipay Class.
 */
class WC_Gateway_Rettipay extends WC_Payment_Gateway
{
	/** @var bool Whether or not logging is enabled */
	public static $log_enabled = false;

	/** @var WC_Logger Logger instance */
	public static $log = false;

	/**
	 * Constructor for the RettiPay plugin.
	 */
	public function __construct()
	{
		$this->id                 = 'rettipay';
		// $this->icon 			  = plugin_dir_path(__FILE__) . 'assets/logo.png';
		$this->has_fields         = false;
		$this->order_button_text  = __('Proceed to RettiPay', 'rettipay');
		$this->method_title       = __('Rettipay', 'rettipay');
		$this->method_description = '<p>' .
			// translators: Introduction text at top of Rettipay settings page.
			__('A payment gateway that lets your customers pay for discounts on products with cryptocurrency via RettiPay.', 'rettipay')
			. '</p><p>' .
			sprintf(
				// translators: Introduction text at top of Rettipay settings page. Includes external URL.
				__('If you do not currently have a RettiPay account, you can set one up here: %s', 'rettipay'),
				'<a target="_blank" href="https://app.rettipay.com/register">https://app.rettipay.com/register</a>'
			);

		// Timeout after 3 days - longest confirmation time will be BTC,
		// and those usually time out around 3 days
		$this->timeout = (new WC_DateTime())->sub(new DateInterval('P3D'));

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		$this->title       = 'Cryptocurrency';
		$this->description = 'Pay with CJS and other cryptocurrencies';
		$this->debug       = 'yes' === $this->get_option('debug', 'no');

		self::$log_enabled = $this->debug;

		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_filter('woocommerce_order_data_store_cpt_get_orders_query', array($this, 'rettipay_custom_query_var'), 10, 2);
		add_action('woocommerce_api_wc_gateway_rettipay', array($this, 'rettipay_handle_webhook'));

		// Check when the user saves their settings if syncing is enabled
	}

	/**
	 * Logging method.
	 *
	 * @param string $message Log message.
	 * @param string $level   Optional. Default 'info'.
	 *     emergency|alert|critical|error|warning|notice|info|debug
	 */
	public static function log($message, $level = 'info')
	{
		if (self::$log_enabled) {
			if (empty(self::$log)) {
				self::$log = wc_get_logger();
			}
			self::$log->log($level, $message, array('source' => 'rettipay'));
		}
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields()
	{
		$this->form_fields = array(
			'enabled'        		    => array(
				'title'   => __('Enable/Disable', 'woocommerce'),
				'type'    => 'checkbox',
				'label'   => __('Enable RettiPay', 'rettipay'),
				'description' => 'Allow cryptocurrency transactions on your site with RettiPay.',
				'default' => 'no',
			),
			'sync_with_rettipay'        => array(
				'title'   => __('Enable/Disable', 'woocommerce'),
				'type'    => 'checkbox',
				'label'   => __('Sync your RettiPay products with our servers', 'rettipay'),
				'description' => 'Allows RettiPay to sync the products that you have RettiPay payments enabled to our servers. These products 
				will then be distributed to Discounts With Crypto. Please note that you must have a valid API key set for your products to sync.',
				'default' => 'no',
			),
			'site_wide_discount'        => array(
				'title'   => __('Enable/Disable', 'woocommerce'),
				'type'    => 'checkbox',
				'label'   => __('Enable Site Wide Product Discount Rules', 'rettipay'),
				'description' => 'Enable site wide discounts for all of your products that you allow RettiPay payments on. If this is enabled,
				then every product will follow the rules set below',
				'default' => 'no',
			),
			'global_user_pays' => array(
				'title'             => __('Product percentage to pay', 'rettipay'),
				'default'			=> '5',
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'step'          => 'any',
					'min'           => '1',
					'max'           => '100'
				),
				'description' => __('User will pay this much in cryptocurrency, as a percentage. For example,
				a value of "5" will let the user pay 5% of the product total in cryptocurrency.', 'rettipay'),
			),
			'global_user_discount' => array(
				'title'             => __('Product percentage of discount', 'rettipay'),
				'default'			=> '5',
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'step'          => 'any',
					'min'           => '1',
					'max'           => '100'
				),
				'description' => __('User will pay this much in cryptocurrency, as a percentage. For example,
				a value of "5" will let the user pay 5% of the product total in cryptocurrency.', 'rettipay'),
			),
			'api_key'        => array(
				'title'       => __('API Key', 'rettipay'),
				'type'        => 'text',
				'default'     => '',
				'description' => sprintf(
					// translators: Description field for API on settings page. Includes external link.
					__(
						'You can manage your API keys within the RettiPay Settings page, available here: %s',
						'rettipay'
					),
					esc_url('https://app.rettipay.com/dashboard/settings')
				),
			),
			'webhook_secret' => array(
				'title'       => __('Webhook Shared Secret', 'rettipay'),
				'type'        => 'text',
				'description' =>

				// translators: Instructions for setting up 'webhook shared secrets' on settings page.
				__('Using webhooks allows RettiPay to send payment confirmation messages to the website. To fill this out:', 'rettipay')

					. '<br /><br />' .

					// translators: Step 1 of the instructions for 'webhooks' on settings page.
					__('1. In your RettiPay settings page, scroll to the \'Webhooks\' section', 'rettipay')

					. '<br />' .

					// translators: Step 2 of the instructions for 'webhooks' on settings page. Includes webhook URL.
					sprintf(__('2. Click \'Add URL\' and paste the following URL: %s', 'rettipay'), add_query_arg('wc-api', 'WC_Gateway_Rettipay', home_url('/', 'https')))

					. '<br />' .

					// translators: Step 3 of the instructions for 'webhooks' on settings page.
					__('3. Click "Show shared secret" and paste into the box above.', 'rettipay'),

			),
			// 'show_icons'     => array(
			// 	'title'       => __('Show icons', 'rettipay'),
			// 	'type'        => 'checkbox',
			// 	'label'       => __('Display currency icons on checkout page.', 'rettipay'),
			// 	'default'     => 'false',
			// ),
			'debug'          => array(
				'title'       => __('Debug log', 'woocommerce'),
				'type'        => 'checkbox',
				'label'       => __('Enable logging', 'woocommerce'),
				'default'     => 'no',
				// translators: Description for 'Debug log' section of settings page.
				'description' => sprintf(__('Log RettiPay API events inside %s', 'rettipay'), '<code>' . WC_Log_Handler_File::get_log_file_path('rettipay') . '</code>'),
			),
		);
	}

	/**
	 * Process the payment and return the result.
	 * @param  int $order_id
	 * @return array
	 */
	public function process_payment($order_id)
	{
		global $woocommerce;
		$order = wc_get_order($order_id);

		// Store the products and metadata about them, and the order,
		// so that the merchant can see what products are popular with
		// cryptocurrency discounts/payments
		$products = array();
		$products_metadata = array();

		// Create description
		try {
			$order_items = array_map(function ($item) {
				return $item['quantity'] . ' x ' . $item['name'];
			}, $order->get_items());

			$description = mb_substr(implode(', ', $order_items), 0, 200);
		} catch (Exception $e) {
			$description = null;
		}

		foreach ($order->get_items() as $item) {
			$quantity = $item['quantity'];
			$price = get_post_meta($item['product_id'], '_price', true);
			$accept_rp = get_post_meta($item['product_id'], '_accept_rp_payment', true) == 'yes';
			$percent_user_pays = get_post_meta($item['product_id'], '_rp_user_pays', true);
			$percent_user_discount = get_post_meta($item['product_id'], '_rp_user_discount', true);

			$productVals = array(
				"quantity" => $quantity,
				"price" => $price,
				"accept_rp" => $accept_rp,
				"percent_user_pays" => $percent_user_pays,
				"percent_user_discount" => $percent_user_discount
			);

			array_push($products, $productVals);

			$product_metadata = array(
				'quantity' => $quantity,
				'price' => $price,
				'product_id' => $item['product_id'],
				'variation_id' => $item['variation_id'],
			);

			array_push($products_metadata, $product_metadata);
		}

		$rp_price_data = self::get_discount_and_total_user_will_pay($products);

		$this->init_api();

		// Create a new charge.
		$metadata = array(
			'order_id'  => $order->get_id(),
			'order_key' => $order->get_order_key(),
			'order_subtotal' => $order->get_subtotal(),
			'source' => 'woocommerce',
			'platform' => 'WooCommerce',
			'crypto_user_pays_in_fiat' => $rp_price_data['crypto_user_will_pay'],
			'fiat_user_discount' => $rp_price_data['discount_user_will_receive'],
			'user_location' => $this->get_user_location_metadata(),
			'products_metadata' => $products_metadata
		);

		$checkout_url = $woocommerce->cart->get_checkout_url();

		$result   = Rettipay_API_Handler::create_charge(
			$rp_price_data['crypto_user_will_pay'],
			get_woocommerce_currency(),
			$metadata,
			// $this->get_return_url($order),
			$checkout_url,
			null,
			$description,
			$this->get_cancel_url($order)
		);

		if (!$result[0]) {
			return array('result' => 'fail');
		}

		$charge = $result[1]['data'];

		// We're finished with using the order data to create a coupon,
		// so delete it
		wp_delete_post($order->get_id(), true);

		return array(
			'result'   => 'success',
			'redirect' => $charge['hosted_url'],
		);
	}

	/**
	 * Get the cancel url.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public function get_cancel_url($order)
	{
		$return_url = $order->get_cancel_order_url();

		if (is_ssl() || get_option('woocommerce_force_ssl_checkout') == 'yes') {
			$return_url = str_replace('http:', 'https:', $return_url);
		}

		return apply_filters('woocommerce_get_cancel_url', $return_url, $order);
	}

	/**
	 * Check payment statuses on orders and update order statuses.
	 */
	public function check_orders()
	{
		$this->init_api();

		// Check the status of non-archived Rettipay orders.
		$orders = wc_get_orders(array('rettipay_archived' => false, 'status'   => array('wc-pending')));
		foreach ($orders as $order) {
			$charge_id = $order->get_meta('_rettipay_charge_id');

			usleep(300000);  // Ensure we don't hit the rate limit.
			$result = Rettipay_API_Handler::send_request('payments/' . $charge_id);

			if (!$result[0]) {
				self::log('Failed to fetch order updates for: ' . $order->get_id());
				continue;
			}

			$timeline = $result[1]['data']['timeline'];
			self::log('Timeline: ' . print_r($timeline, true));
			$this->_update_order_status($order, $timeline);
		}
	}

	/**
	 * Handle requests sent to webhook.
	 */
	public function rettipay_handle_webhook()
	{
		$payload = file_get_contents('php://input');

		if (!empty($payload) && $this->validate_webhook($payload)) {
			self::log("Handling webhook request...it was valid!");

			$data       = json_decode($payload, true);
			$event_data = $data['event']['data'];

			self::log('Webhook received event: ' . print_r($data, true));

			if (!isset($event_data['metadata']['order_id'])) {
				exit;
			}

			$order_id = $event_data['metadata']['order_id'];

			$this->_update_order_status(wc_get_order($order_id), $event_data['timeline']);

			exit;
		}

		wp_die('Rettipay Webhook Request Failure', 'Rettipay Webhook', array('response' => 500));
	}

	/**
	 * Check Rettipay webhook request is valid.
	 * @param  string $payload
	 */
	public function validate_webhook($payload)
	{
		self::log('Checking Webhook response is valid');

		if (!isset($_SERVER['HTTP_X_RP_WEBHOOK_SIGNATURE'])) {
			self::log('There was no HTTP WEBHOOK SIG in the headers');
			return false;
		}

		$sig    = $_SERVER['HTTP_X_RP_WEBHOOK_SIGNATURE'];
		$secret = $this->get_option('webhook_secret');

		$sig2 = hash_hmac('sha256', $payload, $secret);

		if (hash_equals($sig, $sig2)) {
			return true;
		}

		return false;
	}

	public function validate_verification_hash($sig, $code)
	{
		self::log("Validating verification hash...");
		$secret = $this->get_option('webhook_secret');
		$sig2   = hash_hmac('sha256', $code, $secret);

		if (hash_equals($sig, $sig2)) {
			return true;
		}

		self::log("\nError: Hashes do not match");
		self::log("\nWas expecting " . $sig . " but hashed code to " . $sig2);
		self::log("\nSecret used: " . $secret);
		return false;
	}

	/**
	 * Init the API class and set the API key etc.
	 */
	protected function init_api()
	{
		include_once dirname(__FILE__) . '/includes/class-rettipay-api-handler.php';

		Rettipay_API_Handler::$log     		  = get_class($this) . '::log';
		Rettipay_API_Handler::$api_key 		  = $this->get_option('api_key');
	}

	/**
	 * Update the status of an order from a given timeline.
	 * @param  WC_Order $order
	 * @param  array    $timeline
	 */
	public function _update_order_status($order, $timeline)
	{
		$prev_status = $order->get_meta('_rettipay_status');

		$last_update = end($timeline);
		$status      = $last_update['status'];
		if ($status !== $prev_status) {
			$order->update_meta_data('_rettipay_status', $status);

			if ('EXPIRED' === $status && 'pending' == $order->get_status()) {
				$order->update_status('cancelled', __('Rettipay payment expired.', 'rettipay'));
			} elseif ('CANCELED' === $status) {
				$order->update_status('cancelled', __('Rettipay payment cancelled.', 'rettipay'));
			} elseif ('UNRESOLVED' === $status) {
				if ($last_update['context'] === 'OVERPAID') {
					$order->update_status('processing', __('Customer has overpaid for their order, but the payment will still be marked as complete.', 'rettipay'));
					// $order->payment_complete();
				} else {
					// translators: Rettipay error status for "unresolved" payment. Includes error status.
					$order->update_status('failed', sprintf(__('Rettipay payment unresolved, reason: %s.', 'rettipay'), $last_update['context']));
				}
			} elseif ('PENDING' === $status) {
				$order->update_status('blockchainpending', __('Rettipay payment detected, but awaiting blockchain confirmation.', 'rettipay'));
			} elseif ('RESOLVED' === $status) {
				// We don't know the resolution, so don't change order status.
				$order->add_order_note(__('Rettipay payment marked as resolved.', 'rettipay'));
			} elseif ('COMPLETED' === $status) {
				$order->add_order_note(__('Cryptocurrency funds have been successfully received. Waiting for order to be completed.', 'rettipay'));
				// $order->update_status('processing', __('Rettipay payment was successfully processed.', 'rettipay'));
				// $order->payment_complete();
			}
		}

		// Archive if in a resolved state and idle more than timeout.
		if (
			in_array($status, array('EXPIRED', 'COMPLETED', 'RESOLVED'), true) &&
			$order->get_date_modified() < $this->timeout
		) {
			self::log('Archiving order: ' . $order->get_order_number());
			$order->update_meta_data('_rettipay_archived', true);
		}
	}

	/**
	 * Handle a custom 'rettipay_archived' query var to get orders
	 * payed through Rettipay with the '_rettipay_archived' meta.
	 * @param array $query - Args for WP_Query.
	 * @param array $query_vars - Query vars from WC_Order_Query.
	 * @return array modified $query
	 */
	public function rettipay_custom_query_var($query, $query_vars)
	{
		if (array_key_exists('rettipay_archived', $query_vars)) {
			$query['meta_query'][] = array(
				'key'     => '_rettipay_archived',
				'compare' => $query_vars['rettipay_archived'] ? 'EXISTS' : 'NOT EXISTS',
			);
			// Limit only to orders payed through Rettipay.
			$query['meta_query'][] = array(
				'key'     => '_rettipay_charge_id',
				'compare' => 'EXISTS',
			);
		}

		return $query;
	}

	/**
	 * Calculates RettiPay discount totals from a list of Products
	 *
	 * @param   Object[]  $items  	An array of items
	 *
	 * @return  Array          		An array with data
	 */
	public function get_discount_and_total_user_will_pay($items)
	{
		$crypto_user_will_pay = 0;
		$discount_user_will_receive = 0;

		// Calculate how much the user needs to pay, and what they will
		// get as a discount

		// If the merchant has a site-wide discount set, then use that
		foreach ($items as $item) {
			if ($item['accept_rp']) {
				$percent_user_pays = 0;
				$percent_user_discount = 0;

				if ($this->get_option('site_wide_discount') == 'yes') {
					$percent_user_pays = $this->get_option('global_user_pays');
					$percent_user_discount = $this->get_option('global_user_discount');
				} else {
					$percent_user_pays = $item['percent_user_pays'];
					$percent_user_discount = $item['percent_user_discount'];
				}

				$rp_user_pays = $percent_user_pays / 100;
				$rp_user_discount = $percent_user_discount / 100;

				// Amount the user pays
				$product_fiat_total = $item['price'] * $item['quantity'];
				$crypto_user_will_pay = round($crypto_user_will_pay + ($product_fiat_total * $rp_user_pays), 2);

				// Amount the user will receive as a coupon
				$discount_user_will_receive = round($discount_user_will_receive + ($product_fiat_total * $rp_user_discount), 2);
			}
		}

		return array(
			'crypto_user_will_pay' => $crypto_user_will_pay,
			'discount_user_will_receive' => $discount_user_will_receive,
		);
	}

	/**
	 * Fetches user location data, or null if Geolocate is not enabled on
	 * the user's website
	 *
	 * @return  Object || Null  The user's geolocation data, or null
	 */
	public function get_user_location_metadata()
	{
		try {
			$geo      = new WC_Geolocation();
			$user_ip  = $geo->get_ip_address();
			$user_geo = $geo->geolocate_ip($user_ip);
			return array(
				'user_ip' => $user_ip,
				'user_geo' => $user_geo,
			);
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 * Creates a new coupon for the order once a user has paid with RettiPay
	 *
	 * @return string
	 */
	public function create_rp_coupon_for_order($charge_id, $amount)
	{
		$discount_type = 'fixed_cart';

		$coupon = array(
			'post_title' => $charge_id,
			'post_content' => '',
			'post_status' => 'publish',
			'post_author' => 1,
			'post_type'    => 'shop_coupon',
			'post_excerpt' => 'Coupon created by RettiPay plugin for charge with ID ' . $charge_id
		);

		$new_coupon_id = wp_insert_post($coupon);

		update_post_meta($new_coupon_id, 'discount_type', $discount_type);
		update_post_meta($new_coupon_id, 'coupon_amount', $amount);
		update_post_meta($new_coupon_id, 'individual_use', 'no');
		update_post_meta($new_coupon_id, 'usage_limit', '1');

		return $charge_id;
	}

	public function get_rp_site_wide_rates()
	{
		$rp_user_pays = $this->get_option('global_user_pays');
		$rp_user_discount = $this->get_option('global_user_discount');

		return array(
			'rp_user_pays' => $rp_user_pays,
			'rp_user_discount' => $rp_user_discount
		);
	}

	public function is_site_wide_discount_active()
	{
		$site_wide_discount_enabled = $this->get_option('site_wide_discount');

		if ($site_wide_discount_enabled == 'yes') {
			return true;
		} else {
			return false;
		}
	}

	// Checks to see if we need to sync the products to the server
	public function check_products_to_sync()
	{
		$this->init_api();

		if ($this->get_option('sync_with_rettipay') == 'yes' && strlen(trim(Rettipay_API_Handler::$api_key)) > 0) {
			// Fetch all products that are RettiPay enabled, then 
			// sync them to the website
			$products = wc_get_products(array());
			$products_to_sync = array();

			foreach ($products as $product) {
				$product_id = $product->get_id();
				$accept_rp = get_post_meta($product_id, '_accept_rp_payment', true) == 'yes';

				if ($accept_rp) {
					$product_data = wc_get_product($product_id);

					$vals = array(
						"name" => $product_data->get_name(),
						"type" => "external",
						"button_text" => "Go to shop",
						"external_url" => get_permalink($product_id),
						"description" => $product_data->get_description(),
						"short_description" => $product_data->get_short_description(),
						"sku" => $product_data->get_sku(),
						"regular_price" => $product_data->get_regular_price(),
						"sale_price" => $product_data->get_sale_price(),
						"virtual" => $product_data->get_virtual(),
						"date_on_sale_from" => $product_data->get_date_on_sale_from(),
						"date_on_sale_to" => $product_data->get_date_on_sale_to(),
						"downloadable" => $product_data->get_downloadable(),
						"downloads" => $product_data->get_downloads(),
						"download_limit" => $product_data->get_download_limit(),
						"download_expiry" => $product_data->get_download_expiry(),
						"tax_status" => $product_data->get_tax_status(),
						"tax_class" => $product_data->get_tax_class(),
						"manage_stock" => $product_data->get_manage_stock(),
						"stock_quantity" => $product_data->get_stock_quantity(),
						"stock_status" => $product_data->get_stock_status(),
						"backorders" => $product_data->get_backorders(),
						"sold_individually" => $product_data->get_sold_individually(),
						"weight" => $product_data->get_weight(),
						"dimensions" => $product_data->get_dimensions(),
						"shipping_class" => $product_data->get_shipping_class(),
						"reviews_allowed" => $product_data->get_reviews_allowed(),
						"upsell_ids" => $product_data->get_upsell_ids(),
						"cross_sell_ids" => $product_data->get_cross_sell_ids(),
						"purchase_note" => $product_data->get_purchase_note(),
						"attributes" => $product_data->get_attributes(),
						"default_attributes" => $product_data->get_default_attributes(),
						"menu_order" => $product_data->get_menu_order(),
						"categories" => $this->get_product_category_names($product),
						"meta_data" => [ 'merchant_product_id' => $product_id ]
					);

					// Now let's get the image urls - first the cover image
					$product_image_urls = array();
					array_push($product_image_urls, wp_get_attachment_url($product_data->get_image_id()));

					// Now the gallery
					foreach ($product_data->get_gallery_image_ids() as $id) {
						array_push($product_image_urls, wp_get_attachment_url($id));
					}

					$vals['images'] = $product_image_urls;

					array_push($products_to_sync, $vals);
				} else {
					// The product either has never been synced, or needs to be removed
					array_push($products_to_sync, array(
						"delete" => true,
						"meta_data" => [ 'merchant_product_id' => $product_id ]
					));
				}
			}

			Rettipay_API_Handler::send_dwc_request('products/add', $products_to_sync, 'POST');
		}
	}

	function get_product_category_names($product)
	{
		$categories = wp_get_post_terms($product->get_id(), 'product_cat');
		$categories = wp_list_pluck($categories, 'name', 'slug');
		return $categories;
	}
}
