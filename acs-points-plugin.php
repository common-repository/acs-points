<?php
/**
 * Plugin Name: ACS Points
 * Plugin URI: https://aftersalespro.gr
 * Description: ACS Points
 * Version: 1.1.4
 * Author: AfterSalesPro
 * Author URI:https://aftersalespro.gr
 * License: GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: acs-points-plugin
 */

if (!defined('WPINC')) {
    die;
}

define('ACS_POINTS_PLUGIN_VERSION', '1.1.4');
define('ACS_POINTS_PLUGIN_ID', 'acs-points-plugin');

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

if (!class_exists('AcsPointsPlugin')) {
    class AcsPointsPlugin
    {
        protected $settings;

        static $labels = [
            'checkout_option_title' => 'Παραλαβή από ACS Points',//'Collection from an ACS Point',
            'checkout_button_label' => 'Επιλέξτε ένα ACS Point',//'Select an ACS Point',
            'checkout_validation_error' => 'Please select a pickup point',
            'checkout_validation_error_weight' => 'We are sorry. %s is not possible for orders of %d kg and higher.',
            'checkout_selected_point_title' => "You've selected to pick up your order from",
            'email_selected_point_title' => 'Selected ACS SmartPoint',
        ];

        static $configuration = [
            'checkout_input_name' => 'acs_pp_point_id',
            'post_meta_field_name' => 'acs_pp_point',
        ];

        public function __construct()
        {
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($actions) {
                return array_merge($actions, [
                    '<a href="' . admin_url('admin.php?page=wc-settings&tab=shipping&section=acs-points-plugin') . '">Settings</a>',
                    '<a href="https://aftersalespro.gr" target="_blank">Support</a>',
                ]);
            });

            $this->settings = self::getSettings();
            if ($this->settings['enabled'] === 'yes') {
                $this->initWoo();
            }
        }

        function initWoo()
        {
            add_action('woocommerce_review_order_before_cart_contents', array($this, 'pre_checkout_validation'), 10);
            add_action('woocommerce_after_checkout_validation', array($this, 'checkout_validation'), 10, 2);

            add_action('woocommerce_before_checkout_form', array($this, 'add_map_in_checkout'), 10, 1);
            add_action('woocommerce_after_shipping_rate', array($this, 'add_map_trigger_to_shipping_option'), 10, 2);
            add_action('woocommerce_after_order_notes', array($this, 'add_checkout_point_input'));
            add_action('woocommerce_checkout_update_order_meta', array($this, 'save_checkout_point_input'));
            add_action('woocommerce_order_details_after_customer_details', array($this, 'show_point_details_in_customer'), 10);
            add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'show_point_details_in_admin'), 10, 1);
            add_action( ACS_POINTS_PLUGIN_ID . 'cron_hook', array($this, 'fetch_points_json') );
        }

        public static function getSettings()
        {
            $settings = get_option('woocommerce_acs-points-plugin_settings');
            if (empty($settings)) {
                $settings = array('enabled' => 'no');
            }
            return $settings;
        }

        public function get_order_weight()
        {
            $chosen_methods = WC()->session->get('chosen_shipping_methods');

            $packages = WC()->shipping->get_packages();

            $weight = 0;
            foreach ($packages as $i => $package) {

                if ($chosen_methods[$i] != ACS_POINTS_PLUGIN_ID) {
                    continue;
                }

                foreach ($package['contents'] as $item_id => $values) {
                    $_product = $values['data'];
                    if (is_numeric($_product->get_weight())) {
                        $weight += $_product->get_weight() * $values['quantity'];
                    }
                }
            }

            return (float) wc_get_weight($weight, 'kg');
        }

        public function pre_checkout_validation()
        {
            $chosen_methods = WC()->session->get('chosen_shipping_methods');

            if (!is_array($chosen_methods)) {
                return;
            }

            if (!in_array(ACS_POINTS_PLUGIN_ID, $chosen_methods)) {
                return;
            }

            $weightLimit = (int) $this->settings['weightUpperLimit'];
            if ($weightLimit == 0) {
                return;
            }

            $weight = $this->get_order_weight();

            if ($weight > $weightLimit) {
                $message = sprintf(
                    __(self::$labels['checkout_validation_error_weight'], ACS_POINTS_PLUGIN_ID),
                    __(self::$labels['checkout_option_title'], ACS_POINTS_PLUGIN_ID),
                    $weightLimit
                );
                $messageType = "error";

                if (!wc_has_notice($message, $messageType)) {
                    wc_add_notice($message, $messageType);
                }
            }

        }

        public function checkout_validation($data, $errors)
        {
            if (
                ACS_POINTS_PLUGIN_ID == $data['shipping_method'][0]
                && empty($_POST[self::$configuration['checkout_input_name']])
            ) {
                $errors->add('validation', __(self::$labels['checkout_validation_error'], ACS_POINTS_PLUGIN_ID));
            }

            $weightLimit = (int) $this->settings['weightUpperLimit'];
            if ($weightLimit != 0 && $this->get_order_weight() > $weightLimit) {
                $errors->add('validation', sprintf(
                    __(self::$labels['checkout_validation_error_weight'], ACS_POINTS_PLUGIN_ID),
                    __(self::$labels['checkout_option_title'], ACS_POINTS_PLUGIN_ID),
                    $weightLimit
                ));
            }
        }

        public function add_map_in_checkout()
        {
            $googleMapsKey = $this->settings['googleMapsKey'];
            $filesPath = '/wp-content/plugins/acs-points/';

            wp_enqueue_script(ACS_POINTS_PLUGIN_ID . '-js-googleapis', 'https://maps.googleapis.com/maps/api/js?key='.$googleMapsKey.'&libraries=geometry', array(), ACS_POINTS_PLUGIN_VERSION, 'all');
            wp_enqueue_script(ACS_POINTS_PLUGIN_ID . '-js-markerclusterer', plugins_url('js/markerclusterer.js', __FILE__), array(), ACS_POINTS_PLUGIN_VERSION, 'all');
            wp_enqueue_script(ACS_POINTS_PLUGIN_ID . '-js-woo-script', plugins_url('js/woo-script.js', __FILE__), array(), ACS_POINTS_PLUGIN_VERSION, 'all');
            wp_enqueue_script(ACS_POINTS_PLUGIN_ID . '-js-script', plugins_url('js/script.js', __FILE__), array(), ACS_POINTS_PLUGIN_VERSION, 'all');
            wp_enqueue_style(ACS_POINTS_PLUGIN_ID . '-css-styles', plugins_url('css/styles.css', __FILE__), array(), ACS_POINTS_PLUGIN_VERSION, 'all');

            require 'acs-points-map.php';
        }

        public function add_map_trigger_to_shipping_option($method)
        {
            if ($method->id == ACS_POINTS_PLUGIN_ID) {
                $buttonLabel = __(self::$labels['checkout_button_label'], ACS_POINTS_PLUGIN_ID);
                echo '<div class="locker-container">
                <span class="point-distance"></span>
                <button type="button" id="locker-trigger" onclick="openMap();" class="pick-locker-button" hidden="hidden">
                '.esc_attr($buttonLabel).'
                </button>
                </div>';
            }
        }

        public function add_checkout_point_input($checkout)
        {
            $field = self::$configuration['checkout_input_name'];
            echo '<div id="user_link_hidden_checkout_field">
                <input type="hidden" class="input-hidden" name="'.esc_attr($field).'" id="'.esc_attr($field).'" value="">
                </div>';
        }

        public function save_checkout_point_input($order_id)
        {
            $value = (int)$_POST[self::$configuration['checkout_input_name']];
            if (!empty($value) && $_POST['shipping_method'][0] == ACS_POINTS_PLUGIN_ID) {
                $pointsFile = file_get_contents(__DIR__ . '/data.json');
                $points = json_decode($pointsFile, true);
                $selectedPoint = [$value];
                foreach ($points['points'] as $point) {
                    if ($point['id'] == $value) {
                        $selectedPoint = $point;
                    }
                }
                update_post_meta($order_id, self::$configuration['post_meta_field_name'], json_encode($selectedPoint, JSON_UNESCAPED_UNICODE));
            }
        }

        public function show_point_details_in_customer($order)
        {
            $order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->id;
            $pointDetailsRaw = get_post_meta($order_id, self::$configuration['post_meta_field_name'], true);
            if (!$pointDetailsRaw) {
                return;
            }
            $pointDetails = json_decode($pointDetailsRaw, true);
            $title = __(self::$labels['email_selected_point_title'], ACS_POINTS_PLUGIN_ID);

            $stationName = esc_attr($pointDetails['name']);
            $stationCode = esc_attr($pointDetails['Acs_Station_Destination'] ?? '').esc_attr($pointDetails['Acs_Station_Branch_Destination'] ?? '');

            echo '<h3>'.esc_attr($title).'</h3>
                <p><a href="https://maps.google.com?q='.esc_attr($pointDetails['lat'] ?? '').','.esc_attr($pointDetails['lon'] ?? '').'" target="_blank" style="color: #999;">
                '.$stationName.' ('.$stationCode.')
                </a></p>';
        }

        public function show_point_details_in_admin($order)
        {
            return $this->show_point_details_in_customer($order);
        }

        public static function fetch_points_json($force = true)
        {
            $cached = get_transient(ACS_POINTS_PLUGIN_ID.'-points');
            if (!$force && $cached !== false) {
                return $cached;
            }

            $settings = self::getSettings();

            $data = [
                'ACSAlias' => 'ACS_Get_Stations_For_Plugin',
                'ACSInputParameters' => [
                    'locale' => null,
                    'Company_ID' => $settings['acsCompanyID'] ?? null,
                    'Company_Password' => $settings['acsCompanyPassword'] ?? null,
                    'User_ID' => $settings['acsUserID'] ?? null,
                    'User_Password' => $settings['acsUserPassword'] ?? null,
                ],
            ];

            $args = array(
                'timeout'     => 10,
                'body'        => json_encode($data),
                'headers'     => array(
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'ACSApiKey' => ($settings['acsApiKey'] ?? null),),
            );
            $responseRaw = wp_remote_post( 'https://webservices.acscourier.net/ACSRestServices/api/ACSAutoRest', $args );
            $httpCode = wp_remote_retrieve_response_code($responseRaw);
            $response = wp_remote_retrieve_body($responseRaw);
            $response = json_decode($response, true);

            $points = $response['ACSOutputResponce']['ACSTableOutput']['Table_Data1'] ?? [];

            $points = array_values(array_filter($points, function ($item) {
                return $item['type'] !== 'branch' || $item['Acs_Station_Branch_Destination'] == '1';
            }));

            $data = [
                'status_code' => $httpCode,
                'meta' => $response['ACSOutputResponce']['ACSTableOutput']['Table_Data'] ?? [],
                'points' => $points,
            ];
            set_transient(ACS_POINTS_PLUGIN_ID.'-points', $data, 60*30);

            file_put_contents(
                __DIR__  . '/data.json',
                json_encode([
                    'timestamp' => wp_date('Y-m-d H:i'),
                    'meta' => $response['ACSOutputResponce']['ACSTableOutput']['Table_Data'] ?? [],
                    'points' => $points,
                ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)
            );

            return $data;
        }
    }

    (new AcsPointsPlugin);
}


add_action('woocommerce_shipping_init', function()
{
    if (!class_exists('ACSPointsPlugin_ShippingMethod')) {
        class ACSPointsPlugin_ShippingMethod extends WC_Shipping_Method
        {
            public function __construct()
            {
                $this->id = ACS_POINTS_PLUGIN_ID;
                $this->title = __(AcsPointsPlugin::$labels['checkout_option_title'], ACS_POINTS_PLUGIN_ID);
                $this->method_title = __(AcsPointsPlugin::$labels['checkout_option_title'], ACS_POINTS_PLUGIN_ID);
                $this->method_description = '';

                $this->availability = 'including';
                $this->countries = array(
                    'GR', 'CY',
                );

                $settings = AcsPointsPlugin::getSettings();
                $this->enabled = $settings['enabled'];
                $this->init();
            }

            function init()
            {
                $this->init_form_fields();
                add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
            }

            function admin_options() {
                $httpResponse = AcsPointsPlugin::fetch_points_json();
                ?>
                <h2><?php echo esc_attr($this->title); ?></h2>
                <?php if ($httpResponse['status_code'] == 200): ?>
                    <p style="font-weight: bold; color: #00870d;"><?php echo __('Valid ACS Credentials.', ACS_POINTS_PLUGIN_ID); ?></p>
                <?php else: ?>
                    <p style="font-weight: bold; color: #F00;"><?php echo __('Invalid ACS Credentials.', ACS_POINTS_PLUGIN_ID); ?></p>
                <?php endif; ?>
                <table class="form-table">
                    <?php $this->generate_settings_html(); ?>
                </table>
                <?php
            }

            function init_form_fields()
            {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __('Activation', ACS_POINTS_PLUGIN_ID),
                        'type' => 'checkbox',
                        'description' => __('Activation/Deactivation of shipping method', ACS_POINTS_PLUGIN_ID),
                        'default' => 'no'
                    ),
                    'googleMapsKey' => array(
                        'title' => __('Google Api Key', ACS_POINTS_PLUGIN_ID),
                        'type' => 'text',
                        'description' => __('Google Maps API Key required for map functionalities.', ACS_POINTS_PLUGIN_ID),
                        'default' => ''
                    ),
                    'baseCost' => array(
                        'title' => __('Base shipping cost per order', ACS_POINTS_PLUGIN_ID) . ' (&euro;)',
                        'type' => 'number',
                        'description' => __('Shipping cost for pickup from ACS Point', ACS_POINTS_PLUGIN_ID),
                        'default' => 0,
                        'custom_attributes' => array(
                            'step' => 0.01
                        )
                    ),
                    'baseCostKgLimit' => array(
                        'title' => __('Base shipping cost kg limit', ACS_POINTS_PLUGIN_ID) . ' (kg)',
                        'type' => 'number',
                        'description' => __('Base shipping cost is valid up to X kg', ACS_POINTS_PLUGIN_ID),
                        'default' => 0,
                    ),
                    'costPerKg' => array(
                        'title' => __('Cost per extra kg', ACS_POINTS_PLUGIN_ID) . ' (&euro;)',
                        'type' => 'number',
                        'description' => __('Extra cost per kilo', ACS_POINTS_PLUGIN_ID),
                        'default' => 0,
                        'custom_attributes' => array(
                            'step' => 0.01
                        )
                    ),
                    'weightUpperLimit' => array(
                        'title' => __('Weight limit', ACS_POINTS_PLUGIN_ID) . ' (kg)',
                        'type' => 'number',
                        'description' => __('Upper limit for package weight (kg) in order to pickup from ACS Point', ACS_POINTS_PLUGIN_ID),
                        'default' => 30
                    ),
                    'freeShippingUpperLimit' => array(
                        'title' => __('Free delivery', ACS_POINTS_PLUGIN_ID) . ' (&euro;)',
                        'type' => 'number',
                        'description' => __('for order value higher than', ACS_POINTS_PLUGIN_ID),
                        'default' => '',
                        'custom_attributes' => array(
                            'step' => 0.01
                        )
                    ),
                    'acsCompanyID' => array(
                        'title' => __('Company ID', ACS_POINTS_PLUGIN_ID),
                        'type' => 'text',
                        'description' => __('Provided by ACS courier', ACS_POINTS_PLUGIN_ID),
                        'default' => ''
                    ),
                    'acsCompanyPassword' => array(
                        'title' => __('Company Password', ACS_POINTS_PLUGIN_ID),
                        'type' => 'text',
                        'description' => __('Provided by ACS courier', ACS_POINTS_PLUGIN_ID),
                        'default' => ''
                    ),
                    'acsUserID' => array(
                        'title' => __('User ID', ACS_POINTS_PLUGIN_ID),
                        'type' => 'text',
                        'description' => __('Provided by ACS courier', ACS_POINTS_PLUGIN_ID),
                        'default' => ''
                    ),
                    'acsUserPassword' => array(
                        'title' => __('User Password', ACS_POINTS_PLUGIN_ID),
                        'type' => 'text',
                        'description' => __('Provided by ACS courier', ACS_POINTS_PLUGIN_ID),
                        'default' => ''
                    ),
                    'acsApiKey' => array(
                        'title' => __('Api Key', ACS_POINTS_PLUGIN_ID),
                        'type' => 'text',
                        'description' => __('Provided by ACS courier', ACS_POINTS_PLUGIN_ID),
                        'default' => ''
                    ),
                );
            }

            public function calculate_shipping($packages = array())
            {
                $settings = AcsPointsPlugin::getSettings();
                $baseCost = $settings['baseCost'];
                $baseCostKgLimit = $settings['baseCostKgLimit'] ?? 0;
                $costPerKg = $settings['costPerKg'] ?? 0;

                $freeShippingLimit = $settings['freeShippingUpperLimit'];
                $optionCost = 0;

                $orderTotalCost = 0;
                $weightTotal = 0;
                foreach ($packages['contents'] as $item_id => $values) {
                    $_product = $values['data'];
                    $qty = $values['quantity'] ?? 1;
                    $tempWeight = (float) $_product->get_weight();
                    $weightTotal += wc_get_weight($qty * $tempWeight, 'kg');
                    $orderTotalCost += $qty * $_product->get_price();
                }

                if ($freeShippingLimit != '' && $orderTotalCost < $freeShippingLimit) {
                    $optionCost = $baseCost;
                    if ($weightTotal > $baseCostKgLimit) {
                        $optionCost += $costPerKg * ($weightTotal - $baseCostKgLimit);
                    }
                }

                $rate = array(
                    'id' => $this->id,
                    'label' => $this->title,
                    'cost' => $optionCost
                );
                $this->add_rate($rate);
            }
        }
    }
});

function acs_pp_add_shipping_method($methods)
{
    $methods[] = 'ACSPointsPlugin_ShippingMethod';
    return $methods;
}
add_filter('woocommerce_shipping_methods', 'acs_pp_add_shipping_method');

register_deactivation_hook( __FILE__, 'acs_pp_deactivate' );
function acs_pp_deactivate() {
    $timestamp = wp_next_scheduled( ACS_POINTS_PLUGIN_ID . 'cron_hook' );
    wp_unschedule_event( $timestamp, ACS_POINTS_PLUGIN_ID . 'cron_hook' );
}

register_activation_hook( __FILE__, 'acs_pp_activate' );
function acs_pp_activate() {
    if( !wp_next_scheduled( ACS_POINTS_PLUGIN_ID . 'cron_hook' ) ) {
        wp_schedule_event(time(), 'hourly', ACS_POINTS_PLUGIN_ID . 'cron_hook');
    }
}