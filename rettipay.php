<?php
/*
Plugin Name:  RettiPay
Plugin URI:   https://github.com/michaelfioretti/rettipay_woocommerce
Description:  A payment gateway that allows your customers to pay with cryptocurrency via Rettipay
Version:      1.1.1
Author:       RettiPay
Author URI:   https://rettipay.com
License:      GPLv3+
License URI:  https://www.gnu.org/licenses/gpl-3.0.html
Text Domain:  rettipay
Domain Path:  /languages

WC requires at least: 3.0.9
WC tested up to: 5.4

This software is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
any later version.

This software is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
*/

function rettipay_init_gateway()
{
    // If WooCommerce is available, initialize WC parts.
    // phpcs:ignore
    if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        require_once 'class-wc-gateway-rettipay.php';
        require_once 'includes/class-rettipay-api-handler.php';

        add_action('init', 'rettipay_wc_register_status_and_categories');

        // Filter to modify our cron schedules
        add_filter('cron_schedules', 'rettipay_add_custom_cron_schedules' );

        add_filter('woocommerce_valid_order_statuses_for_payment', 'rettipay_wc_status_valid_for_payment', 10, 2);
        add_action('rettipay_check_orders', 'rettipay_wc_check_orders');
        add_action('rettipay_check_products_to_sync', 'rettipay_wc_check_products_to_sync');
        add_filter('woocommerce_payment_gateways', 'rettipay_wc_add_rettipay_class');
        add_filter('wc_order_statuses', 'rettipay_wc_add_status');
        add_action('woocommerce_admin_order_data_after_order_details', 'rettipay_order_meta_general');
        add_action('woocommerce_order_details_after_order_table', 'rettipay_order_meta_general');
        add_filter('woocommerce_email_order_meta_fields', 'rettipay_custom_woocommerce_email_order_meta_fields', 10, 3);
        add_filter('woocommerce_email_actions', 'rettipay_register_email_action');
        add_action('woocommerce_email', 'rettipay_add_email_triggers');

        // Add the ability to allow/disallow discount via RettiPay, as well 
        // as the ability to set how much a user will pay for the product via a percentage, and
        // how much they will get off
        add_action('woocommerce_product_options_general_product_data', 'rettipay_add_additional_rettipay_info');
        add_action('woocommerce_process_product_meta', 'rettipay_products_custom_field_save');

        // Check to see if the user is on the checkout page, and if they are, look for
        // query parameters to see if they have just paid with RettiPay
        add_action('template_redirect', 'rettipay_check_for_completed_payment');

        // If the user has made a RP payment and needs to finish the checkout process, then
        // disable the payment gateway
        add_filter('woocommerce_available_payment_gateways', 'rettipay_check_for_previous_payment');

        // Clear previous session data once order is complete
        add_action('woocommerce_thankyou', 'rettipay_clear_session_data', 1);
        add_action('woocommerce_order_status_completed', 'rettipay_clear_session_data', 1);

        // Bulk edit functionality for products accepting cryptocurrency
        add_action('woocommerce_product_bulk_edit_start', 'rettipay_custom_rp_field_bulk_edit', 10, 0);
        add_action('woocommerce_product_bulk_edit_save', 'rettipay_save_rp_bulk_edit', 10, 1);
    }
}


add_action('plugins_loaded', 'rettipay_init_gateway');

// Schedule two crons:
// 1. The first is to check the status of RettiPay payments
// 2. The second is to add/update the merchant's products if they wish to sync them with Discounts With Crypto
function rettipay_activation()
{ 
    if (!wp_next_scheduled('rettipay_check_orders')) {
        wp_schedule_event(time(), 'hourly', 'rettipay_check_orders');
    }
}
register_activation_hook(__FILE__, 'rettipay_activation');

function rettipay_deactivation()
{
    wp_clear_scheduled_hook( 'rettipay_check_orders' );
    wp_clear_scheduled_hook( 'rettipay_check_products_to_sync' );
}
register_deactivation_hook(__FILE__, 'rettipay_deactivation');


// WooCommerce

function rettipay_wc_add_rettipay_class($methods)
{
    $methods[] = 'WC_Gateway_Rettipay';
    return $methods;
}

function rettipay_wc_check_orders()
{
    $gateway = WC()->payment_gateways()->payment_gateways()['rettipay'];
    return $gateway->check_orders();
}

function rettipay_wc_check_products_to_sync()
{
    $gateway = WC()->payment_gateways()->payment_gateways()['rettipay'];
    return $gateway->check_products_to_sync();
}

/**
 * Register new status with ID "wc-pendingconfirmation" and label "Pending Confirmation"
 */
function rettipay_wc_register_status_and_categories()
{
    register_post_status('wc-pendingconfirmation', array(
        'label'                     => __('Pending Confirmation', 'rettipay'),
        'public'                    => true,
        'show_in_admin_status_list' => true,
        /* translators: WooCommerce order count in blockchain pending. */
        'label_count'               => _n_noop('Pending confirmation <span class="count">(%s)</span>', 'Pending confirmation <span class="count">(%s)</span>'),
    ));

    // Now that the 'cron_schedules' filter has ran, we will add our second cron job using a custom time
    if (!wp_next_scheduled('rettipay_check_products_to_sync')) {
        wp_schedule_event(time(), 'every_thirty_minutes', 'rettipay_check_products_to_sync');
    }
}

/**
 * Register wc-pendingconfirmation status as valid for payment.
 */
function rettipay_wc_status_valid_for_payment($statuses, $order)
{
    $statuses[] = 'wc-pendingconfirmation';
    return $statuses;
}

/**
 * Add registered status to list of WC Order statuses
 * @param array $wc_statuses_arr Array of all order statuses on the website.
 */
function rettipay_wc_add_status($wc_statuses_arr)
{
    $new_statuses_arr = array();

    // Add new order status after payment pending.
    foreach ($wc_statuses_arr as $id => $label) {
        $new_statuses_arr[$id] = $label;

        if ('wc-pending' === $id) {  // after "Payment Pending" status.
            $new_statuses_arr['wc-pendingconfirmation'] = __('Blockchain Pending', 'rettipay');
        }
    }

    return $new_statuses_arr;
}


/**
 * Add order RettPay meta after General and before Billing
 *
 * @param WC_Order $order WC order instance
 */
function rettipay_order_meta_general($order)
{
    if ($order->get_payment_method() == 'rettipay') {
    ?>
        <br class="clear" />
        <h3>RettiPay Data</h3>
        <div class="">
            <p>RettiPay Reference # <?php echo esc_html($order->get_meta('_rettipay_charge_id')); ?></p>
        </div>
    <?php
    }
}


/**
 * Add RettiPay meta to WC emails
 *
 * @see https://docs.woocommerce.com/document/add-a-custom-field-in-an-order-to-the-emails/
 *
 * @param array    $fields indexed list of existing additional fields.
 * @param bool     $sent_to_admin If should sent to admin.
 * @param WC_Order $order WC order instance
 *
 */
function rettipay_custom_woocommerce_email_order_meta_fields($fields, $sent_to_admin, $order)
{
    if ($order->get_payment_method() == 'rettipay') {
        $fields['rettipay_reference'] = array(
            'label' => __('RettiPay Reference #'),
            'value' => $order->get_meta('_rettipay_charge_id'),
        );
    }

    return $fields;
}

/**
 * Adds new cron schedules
 *
 * @param array $schedules
 *
 * @return array
 */
function rettipay_add_custom_cron_schedules($schedules)
{
    $schedules['every_thirty_minutes'] = array(
        'interval' => 1800,
        'display' => __( 'Once every 30 minutes' )
    );

    return $schedules;
}

/**
 * Registers "woocommerce_order_status_blockchainpending_to_processing" as a WooCommerce email action.
 *
 * @param array $email_actions
 *
 * @return array
 */
function rettipay_register_email_action($email_actions)
{
    $email_actions[] = 'woocommerce_order_status_blockchainpending_to_processing';

    return $email_actions;
}


/**
 * Adds new triggers for emails sent when the order status transitions to Processing.
 *
 * @param WC_Emails $wc_emails
 */
function rettipay_add_email_triggers($wc_emails)
{
    $emails = $wc_emails->get_emails();

    /**
     * A list of WooCommerce emails sent when the order status transitions to Processing.
     *
     * Developers can use the `rp_processing_order_emails` filter to add in their own emails.
     *
     * @param array $emails List of email class names.
     *
     * @return array
     */
    $processing_order_emails = apply_filters('rp_processing_order_emails', [
        'WC_Email_New_Order',
        'WC_Email_Customer_Processing_Order',
    ]);

    foreach ($processing_order_emails as $email_class) {
        if (isset($emails[$email_class])) {
            $email = $emails[$email_class];

            add_action(
                'woocommerce_order_status_blockchainpending_to_processing_notification',
                array($email, 'trigger')
            );
        }
    }
}

/**
 * Adds the following fields;
 * 
 * 1. Checkbox allowing this product to be available for RettiPay
 * 2. Set how much the user will pay for a specific discount
 *
 * @return  Void  This function does not return anything
 */
function rettipay_add_additional_rettipay_info()
{
    // Allow RettiPay payments for this product
    $rp_checkbox_args = array(
        'id'       => '_accept_rp_payment',
        'title'    => __('Enable/Disable', 'rettipay'),
        'type'     => 'checkbox',
        'label'    => __('Allow RettiPay payments for this product', 'rettipay'),
        'desc_tip' => true,
        'description' => __('
            Allow a user to pay for this product with cryptocurrency via RettiPay. Once this
            is enabled, fill in the fields below to modify how much the user will be able to pay
            for, as well as their discount.
        '),
        'default'  => 'no'
    );

    // Set how much the user will pay in cryptocurrency, as a percentage
    $rettipay_user_pays_percentage = array(
        'id'                => '_rp_user_pays',
        'label'             => __('User pays', 'rettipay'),
        'desc_tip'          => true,
        'type'              => 'number',
        'custom_attributes' => array(
            'step'          => 'any',
            'min'           => '1',
            'max'           => '100'
        ),
        'description' => __('User will pay this much in cryptocurrency, as a percentage. For example,
        a value of "5" will let the user pay 5% of the product total in cryptocurrency.', 'rettipay'),
    );

    $rettipay_user_receives_discount_percentage = array(
        'id'          => '_rp_user_discount',
        'label'       => __('User discount', 'rettipay'),
        'desc_tip'    => true,
        'type'        => 'number',
        'custom_attributes' => array(
            'step'          => 'any',
            'min'           => '1',
            'max'           => '100'
        ),
        'description' => __('User will receive this much off of the product total, as a percentage. For example,
        a value of "5" means that the user will receive 5% off of the product total.', 'rettipay'),
    );

    echo '<div class="options_group" style="width: 80%">';
    woocommerce_wp_checkbox($rp_checkbox_args);
    woocommerce_wp_text_input($rettipay_user_pays_percentage);
    woocommerce_wp_text_input($rettipay_user_receives_discount_percentage);
    echo '</div>';
}

/**
 * Saves the values of our custom fields to the database
 *
 * @param   String  $post_id  The product ID
 *
 * @return  Void              This function does not return anything
 */
function rettipay_products_custom_field_save($post_id)
{
    // _accept_rp_payment
    $sanitized_accept_rp_field = sanitize_text_field( $_POST['_accept_rp_payment'] );
    $accept_rp = isset($sanitized_accept_rp_field) ? 'yes' : 'no';
    update_post_meta($post_id, '_accept_rp_payment', $accept_rp);

    // _rp_user_pays field
    $rp_user_pays = sanitize_text_field($_POST['_rp_user_pays']);
    if (!empty($rp_user_pays))
        update_post_meta($post_id, '_rp_user_pays', esc_attr($rp_user_pays));

    // _rp_user_discount field
    $rp_user_discount = sanitize_text_field($_POST['_rp_user_discount']);
    if (!empty($rp_user_discount))
        update_post_meta($post_id, '_rp_user_discount', esc_attr($rp_user_discount));
}

function rettipay_check_for_completed_payment()
{
    global $woocommerce;
    $rp_gateway = WC()->payment_gateways()->payment_gateways()['rettipay'];

    // If the user has not been to the RettiPay website
    if (is_checkout() || is_wc_endpoint_url('order-pay')) {
        $sanitized_rp_code = sanitize_text_field($_GET['rp_code']);
        $sanitized_verification = sanitize_text_field($_GET['verification']);

        if ( isset($sanitized_rp_code) && isset($sanitized_verification) ) {
            $rp_discount = WC()->session->get('rp_user_receives');

            // Validate the verification hash before continuing
            if ($rp_gateway->validate_verification_hash($sanitized_verification, $sanitized_rp_code)) {
                if ( !in_array(strtolower($sanitized_rp_code), $woocommerce->cart->get_applied_coupons()) ) {
                    $coupon_code = $rp_gateway->create_rp_coupon_for_order($sanitized_rp_code, $rp_discount);
                    if (!$woocommerce->cart->add_discount( sanitize_text_field($coupon_code) )) {
                        WC()->session->set("rp_coupon_code", $coupon_code);
                        WC()->session->set('verification_hash', $_GET['verification']);
                        $woocommerce->show_messages();
                    }
                }
            }
        }
    }
}

/**
 * Checks to see if the user has already made a RettiPay payment when
 * coming back to the site. If they have, then we will disable RettiPay
 *
 * @param   Array  $gateways    The current payment gateways
 *
 * @return  Void                This function does not return anything
 */
function rettipay_check_for_previous_payment($gateways)
{
    global $woocommerce;

    $cart_items = $woocommerce->cart->get_cart();

    $rp_gateway = WC()->payment_gateways()->payment_gateways()['rettipay'];
    $products = array();

    foreach ($cart_items as $item => $values) {
        $quantity = $values['quantity'];
        $price = get_post_meta($values['product_id'], '_price', true);
        $accept_rp = get_post_meta($values['product_id'], '_accept_rp_payment', true) == 'yes';
        $percent_user_pays = get_post_meta($values['product_id'], '_rp_user_pays', true);
        $percent_user_discount = get_post_meta($values['product_id'], '_rp_user_discount', true);

        $productVals = array(
            "quantity" => $quantity,
            "price" => $price,
            "accept_rp" => $accept_rp,
            "percent_user_pays" => $percent_user_pays,
            "percent_user_discount" => $percent_user_discount
        );

        array_push($products, $productVals);
    }

    $rp_discount_data = $rp_gateway->get_discount_and_total_user_will_pay($products);

    // Store this data in the user's session for validation

    WC()->session->set('rp_user_pays', $rp_discount_data['crypto_user_will_pay']);
    WC()->session->set('rp_user_receives', $rp_discount_data['discount_user_will_receive']);

    // Get the percentage that the user can save based on the difference that they spend,
    // receive as a discount, and their cart subtotal (no shipping currently at the moment)
    // $total_off_in_fiat = ($rp_discount_data['discount_user_will_receive'] - $rp_discount_data['crypto_user_will_pay']);
    // $cart_subtotal = WC()->cart->subtotal;
    // $savings_as_decimal = ($total_off_in_fiat / $cart_subtotal);
    // $percent_user_saves = round($savings_as_decimal * 100);
    // $coupon_discount_percentage = round(($rp_discount_data['discount_user_will_receive'] / $cart_subtotal) * 100);

    // Change the title and description for the user
    // $rp_title = 'Cryptocurrency - Save ' . $percent_user_saves . '%';

    $rp_title = 'Cryptocurrency';
    $rp_desc = 'Pay ' .
        wc_price($rp_discount_data['crypto_user_will_pay']) .
        ' in CJS or other cryptocurrencies and get ' .
        wc_price($rp_discount_data['discount_user_will_receive']) .
        ' off your order. Once your cryptocurrency payment has been processed, you will ' .
        'be redirected back here to finish your order.';

    $gateways['rettipay']->title = $rp_title;
    $gateways['rettipay']->description = $rp_desc;

    // If the user has already made a payment
    if (WC()->session->get("rp_coupon_code") && WC()->session->get('verification_hash')) {
        unset($gateways['rettipay']);
    }

    // Check the query parameters as well

    if (isset($_GET['rp_code']) && isset($_GET['verification'])) {
        $sanitized_rp_code = sanitize_text_field( $_GET['rp_code'] );
        $sanitized_verification = sanitize_text_field( $_GET['verification'] );

        unset($gateways['rettipay']);

        if (!WC()->session->get("rp_coupon_code")) {
            WC()->session->set("rp_coupon_code", $sanitized_rp_code);
        }

        if (!WC()->session->get("verification_hash")) {
            WC()->session->set("verification_hash", $sanitized_verification);
        }
    }

    // If there is no discount to give - i.e: if there are no products
    // in the cart that allow RettiPay
    if (empty($rp_discount_data['crypto_user_will_pay'])) {
        unset($gateways['rettipay']);
    }

    return $gateways;
}

/**
 * Clear the session data once the user has completed their order
 *
 * @return  Void  This function does not return anything
 */
function rettipay_clear_session_data()
{
    WC()->session->set('rp_coupon_code', null);
    WC()->session->set('verification_hash', null);
    WC()->session->set('rp_user_pays', null);
    WC()->session->set('rp_user_receives', null);
}

function rettipay_save_rp_bulk_edit($product)
{
    if ($product->is_type('simple') || $product->is_type('external')) {
        // $product_id = method_exists($product, 'get_id') ? $product->get_id() : $product->id;
        $product_id = $product->get_id();

        $sanitized_accept_rp_payment = sanitize_text_field( $_REQUEST['_accept_rp_payment'] );
        $is_accept_rp_set = isset( $sanitized_accept_rp_payment ) ? 'yes' : 'no';
        update_post_meta($product_id, '_accept_rp_payment', $is_accept_rp_set);

        // _rp_user_pays field
        $sanitized_rp_user_pays = sanitize_text_field( $_REQUEST['_rp_user_pays'] );
        if (!empty($sanitized_rp_user_pays))
            update_post_meta($product_id, '_rp_user_pays', esc_attr($sanitized_rp_user_pays));

        // _rp_user_discount field
        $sanitized_rp_user_discount = sanitize_text_field( $_REQUEST['_rp_user_discount'] );
        if (!empty($sanitized_rp_user_discount))
            update_post_meta($product_id, '_rp_user_discount', esc_attr($sanitized_rp_user_discount));
    }
}

function rettipay_custom_rp_field_bulk_edit()
{
    ?>
    <div>
        <label>
            <span>
                <?php
                $rp_checkbox_args = array(
                    'id'       => '_accept_rp_payment',
                    'title'    => __('Enable/Disable', 'rettipay'),
                    'type'     => 'checkbox',
                    'label'    => __('Allow RettiPay payments for this product', 'rettipay'),
                    'desc_tip' => true,
                    'description' => __('
                            Allow a user to pay for this product with cryptocurrency via RettiPay. Once this
                            is enabled, fill in the fields below to modify how much the user will be able to pay
                            for, as well as their discount.
                        '),
                    'default'  => 'no'
                );

                $rettipay_user_pays_percentage = array(
                    'id'                => '_rp_user_pays',
                    'label'             => __('User pays', 'rettipay'),
                    'desc_tip'          => true,
                    'type'              => 'number',
                    'custom_attributes' => array(
                        'step'          => 'any',
                        'min'           => '1',
                        'max'           => '100'
                    ),
                    'description' => __('User will pay this much in cryptocurrency, as a percentage. For example,
                    a value of "5" will let the user pay 5% of the product total in cryptocurrency.', 'rettipay'),
                );

                $rettipay_user_receives_discount_percentage = array(
                    'id'          => '_rp_user_discount',
                    'label'       => __('User discount', 'rettipay'),
                    'desc_tip'    => true,
                    'type'        => 'number',
                    'custom_attributes' => array(
                        'step'          => 'any',
                        'min'           => '1',
                        'max'           => '100'
                    ),
                    'description' => __('User will receive this much off of the product total, as a percentage. For example,
                    a value of "5" means that the user will receive 5% off of the product total.', 'rettipay'),
                );


                echo '<div>';
                woocommerce_wp_checkbox($rp_checkbox_args);
                woocommerce_wp_text_input($rettipay_user_pays_percentage);
                woocommerce_wp_text_input($rettipay_user_receives_discount_percentage);
                echo '</div>';
                ?>
            </span>
        </label>
        <!-- <label class="change-input">
            <input type="text" name="_t_dostawy" class="text t_dostawy" placeholder="<?php _e('Enter Termin dostawy', 'woocommerce'); ?>" value="" />
        </label> -->
    </div>
<?php
}
