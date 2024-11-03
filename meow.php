<?php
/**
 * Plugin Name: Stripe Checkout Plugin
 * Description: A plugin to integrate Stripe Checkout with custom checkout functionality.
 * Version: 1.0
 * Author: Your Name
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Enqueue necessary scripts and styles
function stripe_checkout_enqueue_scripts() {
    $version = filemtime(plugin_dir_path(__FILE__) . 'stripe-checkoutnew1.js');
    wp_enqueue_script('stripe-checkout-js', 'https://js.stripe.com/v3/', [], null, true);
    wp_enqueue_script('custom-checkout-js', plugin_dir_url(__FILE__) . 'stripe-checkoutnew1.js', ['jquery'], $version, true);
    //wp_enqueue_style('custom-style', plugin_dir_url(__FILE__) . 'style.css', array(), '1.0', 'all');
    
    wp_localize_script('custom-checkout-js', 'stripe_checkout_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
    ]);
}
add_action('wp_enqueue_scripts', 'stripe_checkout_enqueue_scripts');

// Shortcode to display the checkout page
function stripe_checkout_shortcode() {
    ob_start();
    ?>
    <section>
        <div class="product">
            <img src="https://i.imgur.com/EHyR2nP.png" alt="The cover of Stubborn Attachments" />
            <div class="description">
                <h3>Stubborn Attachments</h3>
                <h5>$20.00</h5>
            </div>
        </div>
        <form id="checkout-form" method="POST">
            <label for="customer_name">Full Name:</label>
            <input type="text" id="customer_name" name="customer_name" required placeholder="Full Name">

            <label for="contact_number">Contact Number:</label>
            <input type="tel" id="contact_number" name="contact_number" required placeholder="Contact Number">

            <label for="school">School:</label>
            <input type="text" id="school" name="school" required placeholder="School">

            <div id="age-fields">
                <div class="age-field">
                    <label for="age">Age:</label>
                    <input type="number" id="age" name="age[]" required placeholder="Age">
                    <span class="age-validation"></span>
                </div>
            </div>
            <button type="button" id="add-age">Add Age</button>
            <div><span id="total-ages"></span></div>
            <input type="hidden" id="quantity" name="quantity" value="0">
            <input type="hidden" name="action" value="stripe_checkout_submission">
            <button type="submit" id="checkout-button">Checkout</button>
        </form >
    </section>
    <?php
    return ob_get_clean();
}
add_shortcode('stripe_checkout', 'stripe_checkout_shortcode');

// ... (previous code remains unchanged)

// Shortcode to display the checkout page


function handle_stripe_checkout_submission() {
    try {
        require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
        require_once plugin_dir_path(__FILE__) . 'secrets.php';

        \Stripe\Stripe::setApiKey($stripeSecretKey);

        $customer_name = sanitize_text_field($_POST['customer_name'] ?? '');
        $contact_number = sanitize_text_field($_POST['contact_number'] ?? '');
        $school = sanitize_text_field($_POST['school'] ?? '');

        $YOUR_DOMAIN = home_url();

        // Prepare metadata
        $metadata = [
            'customer_name' => $customer_name,
            'contact_number' => $contact_number,
            'school' => $school,
        ];

        // Add ages for each product to metadata only if age data is provided
        if (isset($_POST['age']) && is_array($_POST['age'])) {
            foreach ($_POST['age'] as $product_id => $product_ages) {
                if (!empty($product_ages)) {
                    $metadata["ages_product_$product_id"] = json_encode($product_ages);
                }
            }
        }

        // Create line items
        $cart_items = get_car(); // Make sure this function is implemented
        if (empty($cart_items)) {
            wp_send_json_error(['error' => 'Cart is empty. Unable to proceed with checkout.']);
            return;
        }
        $total = 0;

        
        foreach ($cart_items as $product_id => $item) {
            $product = wc_get_product($product_id);
            if ($product) {
                $price = $product->get_price();
                $total += $price * $item['quantity']*100; // Convert to cents
            }
        }

        $checkout_session = \Stripe\Checkout\Session::create([
            'line_items' => [[
                        'price_data' => [
                            'currency' => 'sgd',  // Set your currency
                            'product_data' => [
                                'name' =>$product->get_name(),
                            ],
                            'unit_amount' => $total,  // Stripe expects the price in cents
                        ],
                        'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $YOUR_DOMAIN . '/success',
            'cancel_url' => $YOUR_DOMAIN . '/cancel',
            'metadata' => $metadata, // Include the metadata here
        ]);

        wp_send_json_success(['session_url' => $checkout_session->url]);
    } catch (Exception $e) {
        wp_send_json_error(['error' => $e->getMessage()]);
    }
}
add_action('wp_ajax_stripe_checkout_submission', 'handle_stripe_checkout_submission');
add_action('wp_ajax_nopriv_stripe_checkout_submission', 'handle_stripe_checkout_submission');


function handle_stripe_webhook() {
    require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
    require_once plugin_dir_path(__FILE__) . 'secrets.php';

    \Stripe\Stripe::setApiKey($stripeSecretKey);

    $payload = @file_get_contents('php://input');
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
    error_log('Stripe Signature: ' . $sig_header);
   
    if (!$sig_header) {
        $sig_header = $_SERVER['STRIPE_SIGNATURE'] ?? '';
        error_log('Alternate Stripe Signature: ' . $sig_header);
    }
   
    if (empty($sig_header)) {
        error_log('Stripe signature header is missing');
        http_response_code(400);
        exit();
    }
    $event = null;

    try {
        $event = \Stripe\Webhook::constructEvent(
            $payload, $sig_header, $stripeWebhookSecret
        );
    } catch(\UnexpectedValueException $e) {
        http_response_code(404);
        exit();
    } catch(\Stripe\Exception\SignatureVerificationException $e) {
        http_response_code(403);
        exit();
    }

    if ($event->type == 'checkout.session.completed') {
        $session = $event->data->object;
        $ps = $session->payment_status;
        $meta = $session->metadata;
        global $wpdb;
        $table_name = $wpdb->prefix . 'stripe_checkout_data';

        $customer_name = $meta->customer_name ?? '';
        $contact_number = $meta->contact_number ?? '';
        $school = $meta->school ?? '';

        error_log('Extracted metadata: ' . json_encode($meta));

        // Check if there are any age-related fields
        $has_age_fields = false;
        foreach ($meta as $key => $value) {
            if (strpos($key, 'ages_product_') === 0) {
                $has_age_fields = true;
                break;
            }
        }

        if ($has_age_fields) {
            // Process ages for each product
            foreach ($meta as $key => $value) {
                if (strpos($key, 'ages_product_') === 0) {
                    $product_id = str_replace('ages_product_', '', $key);
                    
                    // First, decode the JSON string
                    $decoded_value = json_decode($value, true);
                    
                    // If the result is a string, it means it was double-encoded, so decode again
                    if (is_string($decoded_value)) {
                        $ages = json_decode($decoded_value, true);
                    } else {
                        $ages = $decoded_value;
                    }
            
                    // Ensure $ages is an array
                    $ages = is_array($ages) ? $ages : [$ages];
            
                 

                    foreach ($ages as $age) {
                        $data = array(
                            'session_id' => $session->id,
                            'customer_name' => $customer_name,
                            'contact_number' => $contact_number,
                            'school' => $school,
                            'product_id' => $product_id,
                            'age' => $age,
                            'payment_status' => $ps,
                            'created_at' => current_time('mysql')
                        );

                        $insert_result = $wpdb->insert($table_name, $data);

                        if ($insert_result === false) {
                            error_log('Error saving the form data: ' . $wpdb->last_error);
                        } else {
                            error_log('Form data saved successfully for product ' . $product_id . ', age ' . $age);
                        }
                    }
                }
            }
        } else {
            // Process the form without age-related fields
            $data = array(
                'session_id' => $session->id,
                'customer_name' => $customer_name,
                'contact_number' => $contact_number,
                'school' => $school,
               // 'product_id' => null,  // or you could use a default value if needed
               // 'age' => null,  // or you could use a default value if needed
                'payment_status' => $ps,
                'created_at' => current_time('mysql')
            );

            $insert_result = $wpdb->insert($table_name, $data);

            if ($insert_result === false) {
                error_log('Error saving the form data: ' . $wpdb->last_error);
            } else {
                error_log('Form data saved successfully without age-related fields');
            }
        }
    }
    http_response_code(200);
}
add_action('wp_ajax_nopriv_stripe_webhook', 'handle_stripe_webhook');
add_action('wp_ajax_stripe_webhook', 'handle_stripe_webhook');



