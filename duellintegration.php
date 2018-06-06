<?php
defined('ABSPATH') or die('No script kiddies please!');
/*
  Plugin Name: Duell Integration
  Plugin URI: https://kasseservice.no/
  Description: Duell integration used to sync orders, products, customer with Duell.
  Author: kasseservice
  Version: 1.0
  Author URI: https://kasseservice.no/
 */
include( plugin_dir_path(__FILE__) . 'includes/duell.php');

class Duellintegration {

    public $duellLimit = 20;

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'setup_install'));
        register_deactivation_hook(__FILE__, array($this, 'setup_uninstall'));
        //==pre intialize values
        add_action('plugins_loaded', array($this, 'plugin_init_setup'));
        // Hook into the admin menu
        add_action('admin_menu', array($this, 'create_plugin_settings_page'));
        // Add Settings and Fields
        add_action('admin_init', array($this, 'setup_sections'));
        add_action('admin_init', array($this, 'setup_fields'));
        // Admin error/success messages
        add_action('admin_notices', array($this, 'update_notice'));
        add_action('admin_notices', array($this, 'error_notice'));
        // Cron job event register
        add_filter('cron_schedules', array($this, 'cron_intervals_schedule'));
        add_action('duell_cron_sync_products', array($this, 'sync_products'));
        add_action('duell_cron_sync_prices', array($this, 'sync_prices'));
        add_action('duell_cron_sync_stocks', array($this, 'sync_stocks'));
        add_action('duell_cron_sync_orders', array($this, 'sync_orders'));
        // Manual cron event register
        add_filter('duell_cron_sync_products', array($this, 'sync_products'));
        add_filter('duell_cron_sync_prices', array($this, 'sync_prices'));
        add_filter('duell_cron_sync_stocks', array($this, 'sync_stocks'));
        add_filter('duell_cron_sync_orders', array($this, 'sync_orders'));
        add_action('wp_ajax_manual_run_cron_action', array($this, 'manual_run_custom_cron'));
        // Admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts_and_styles'));
        add_action('admin_footer', array($this, 'setup_action_javascript'));
        // After order placed hook
        add_action('woocommerce_thankyou', array($this, 'wc_subtract_stock_after_order_placed'), 111, 1);
        add_action('woocommerce_process_shop_order_meta', array($this, 'wc_subtract_stock_after_order_placed'), 10, 2);
        // Display Duell order number in woocommerce order list and make it searchable
        add_filter('manage_edit-shop_order_columns', array($this, 'duell_shop_order_column'), 12, 1);
        add_action('manage_shop_order_posts_custom_column', array($this, 'duell_order_list_column_content'), 10, 2);
        add_filter('woocommerce_shop_order_search_fields', array($this, 'duell_search_fields'), 10, 1);
        // For simple products add cost price:
        // Add Field
        //add_action('woocommerce_product_options_general_product_data', array($this, 'wc_add_product_cost_price_field'));
        //Save field cost price
        //add_action('woocommerce_process_product_meta', array($this, 'wc_save_product_cost_price_field'), 10, 2);
        // For variations add cost price:
        // Add Field variant product cost price
        //add_action('woocommerce_product_after_variable_attributes', array($this, 'wc_add_variable_product_cost_price_field'), 10, 3);
        // Save field variant cost price
        //add_action('woocommerce_save_product_variation', array($this, 'wc_save_variable_product_cost_price_field'), 10, 2);
    }

    /*
     * Actions perform on activation of plugin
     */

    function setup_install() {
        global $wpdb;
        if (!wp_next_scheduled('duell_cron_sync_products')) {
            wp_schedule_event(time(), 'every3hours', 'duell_cron_sync_products');
        }
        if (!wp_next_scheduled('duell_cron_sync_prices')) {
            wp_schedule_event(time(), 'every30minutes', 'duell_cron_sync_prices');
        }
        if (!wp_next_scheduled('duell_cron_sync_stocks')) {
            wp_schedule_event(time(), 'every30minutes', 'duell_cron_sync_stocks');
        }
        if (!wp_next_scheduled('duell_cron_sync_orders')) {
            //$next3am = ( date('Hi') >= '0300' ) ? strtotime('+1day 3am') : strtotime('3am');
            //wp_schedule_single_event($next3am, 'duell_cron_sync_orders');
            wp_schedule_event(strtotime('03:00:00'), 'daily3am', 'duell_cron_sync_orders');
        }
    }

    /*
     * Actions perform on de-activation of plugin
     */

    function setup_uninstall() {
        global $wpdb;
        // Deactivate cron jobs
        wp_clear_scheduled_hook('duell_cron_sync_products');
        wp_clear_scheduled_hook('duell_cron_sync_prices');
        wp_clear_scheduled_hook('duell_cron_sync_stocks');
        wp_clear_scheduled_hook('duell_cron_sync_orders');
        // Remove site wise option
        delete_option('duellintegration_client_number');
        delete_option('duellintegration_client_token');
        delete_option('duellintegration_stock_department_token');
        delete_option('duellintegration_order_department_token');
        delete_option('duellintegration_api_access_token');
        delete_option('duellintegration_log_status');
        delete_option('duellintegration_integration_status');
        delete_option('duellintegration_update_existing_product');
        delete_option('duellintegration_product_lastsync');
        delete_option('duellintegration_order_lastsync');
        delete_option('duellintegration_prices_lastsync');
        delete_option('duellintegration_shipping_product_id');
        delete_option('duellintegration_shipping_category_id');
    }

    /*
     * Intialize plugin intial value and check dependencies
     */

    function plugin_init_setup() {
        $this->check_plugin_dependencies();
        defined('DUELL_API_ENDPOINT') OR define('DUELL_API_ENDPOINT', 'https://duell.kasseservice.no/api/v1/');
        defined('DUELL_LOGIN_ACTION') OR define('DUELL_LOGIN_ACTION', 'getaccesstokens');
        defined('DUELL_KEY_NAME') OR define('DUELL_KEY_NAME', 'duell_integration');
        defined('DUELL_TOTAL_LOGIN_ATTEMPT') OR define('DUELL_TOTAL_LOGIN_ATTEMPT', 3);
        defined('DUELL_CNT') OR define('DUELL_CNT', 0);
    }

    /**
     * Check dependencies.
     *
     * @throws Exception
     */
    function check_plugin_dependencies() {
        if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            add_action('admin_notices', array($this, 'wc_dependency_warning_notice'));
            return false;
        }
        if (!function_exists('curl_init')) {
            add_action('admin_notices', array($this, 'curl_dependency_warning_notice'));
            return false;
        }
        return true;
    }

    /*
     * Display error notice in admin if woocommerce is not active
     */

    function wc_dependency_warning_notice() {
        echo '<div class="error"><p><strong>' . sprintf(esc_html__('Duell integration requires WooCommerce to be installed and active. You can download %s here.', 'duellintegration'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
    }

    /*
     * Display error notice in admin if cURL module is not installed
     */

    function curl_dependency_warning_notice() {
        echo '<div class="error"><p><strong>' . sprintf(esc_html__('Duell integration requires cURL to be installed on your server', 'duellintegration')) . '</strong></p></div>';
    }

    /*
     * Setup plugin menu
     */

    function create_plugin_settings_page() {
        $capability = 'manage_options';
        $page_title = 'Duell Integration Settings';
        $menu_title = 'Duell Integration';
        $slug = 'duell-settings';
        $callback = array($this, 'plugin_settings_page_content');
        $icon = plugins_url() . '/duellintegration/assets/images/duell-icon.png';
        $position = 100;
        add_menu_page($page_title, $menu_title, $capability, $slug, $callback, $icon, $position);
        /* add_submenu_page($slug, 'Settings', 'Settings', 'administrator', $slug, $callback);
          $log_page_title = 'Logs';
          $log_menu_title = 'Logs';
          $log_slug = 'duell-integration-logs';
          $log_callback = array($this, 'plugin_settings_page_content');
          add_submenu_page($slug, $log_page_title, $log_menu_title, $capability, $log_slug, $log_callback); */
    }

    /*
     * Display success message after plugin setting update
     */

    public function update_notice() {
        if (isset($_GET['settings-updated'])) {
            add_settings_error('duellintegration_messages', 'duellintegration_message', __('Settings Saved', 'duellintegration'), 'updated');
        }
    }

    /*
     * Display error message if any validation throw
     */

    public function error_notice() {
        settings_errors('duellintegration_messages');
    }

    /*
     * Display plugin setup page
     */

    public function plugin_settings_page_content() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div id="blocker" style="display: none;"><div><?php echo __('Processing', 'duellintegration') ?>...</div></div>
        <div class="wrap">
          <h1><?php echo __('Duell Integration', 'duellintegration') ?></h1>
          <div class="col-left">
            <form method="post" action="options.php">
              <?php wp_nonce_field('duellintegration_nonce', 'duellintegration_nonce_field'); ?>
              <?php settings_fields('duellintegration'); ?>
              <?php do_settings_sections('duellintegration'); ?>
              <?php submit_button(); ?>
            </form>
          </div>
          <div class="col-right borderL">
            <div id="manual-cron-output" style="margin: 8px 0px;"></div>
            <div class="col-right-hf">
              <div class="infodiv">
                <h3><?php echo __('Sync Products', 'duellintegration') ?></h3>
                <p><?php echo __('Manual sync products with Duell', 'duellintegration') ?></p>
                <a href="javascript:void(0)" data-type="sync_products"  class="syncbutton manual-cron"><?php echo __('Run now', 'duellintegration') ?></a>
              </div>
              <div class="infodiv">
                <h3><?php echo __('Sync Stocks', 'duellintegration') ?></h3>
                <p><?php echo __('Manual sync stocks with Duell', 'duellintegration') ?></p>
                <a href="javascript:void(0)" data-type="sync_stocks" class="syncbutton manual-cron"><?php echo __('Run now', 'duellintegration') ?></a>
              </div>
            </div>
            <div class="col-right-hf borderL">
              <div class="infodiv">
                <h3><?php echo __('Sync Price', 'duellintegration') ?></h3>
                <p><?php echo __('Manual sync price with Duell', 'duellintegration') ?></p>
                <a href="javascript:void(0)" data-type="sync_prices" class="syncbutton manual-cron"><?php echo __('Run now', 'duellintegration') ?></a>
              </div>
              <div class="infodiv">
                <h3><?php echo __('Sync Orders', 'duellintegration') ?></h3>
                <p><?php echo __('Manual sync orders with Duell', 'duellintegration') ?></p>
                <a href="javascript:void(0)" data-type="sync_orders" class="syncbutton manual-cron"><?php echo __('Run now', 'duellintegration') ?></a>
              </div>
            </div>
            <div class="infodiv txtL">
              <h3>Setup Cronjobs</h3>
              <div><b>Product Sync every 3 hours:</b>  0 */3 * * * curl  <?php echo get_site_url(); ?>/wp-cron.php?doing_wp_cron >/dev/null 2>&1</div>
              <div><b>Price Sync every 30 minutes: </b> */30 * * * * curl  <?php echo get_site_url(); ?>/wp-cron.php?doing_wp_cron >/dev/null 2>&1</div>
              <div><b>Stocks Sync every 30 minutes: </b> */30 * * * * curl  <?php echo get_site_url(); ?>/wp-cron.php?doing_wp_cron >/dev/null 2>&1</div>
              <div><b>Orders Sync every night 3am: </b> 0 3 * * * curl  <?php echo get_site_url(); ?>/wp-cron.php?doing_wp_cron >/dev/null 2>&1</div>
            </div>
          </div>
        </div>
        <?php
    }

    /*
     * Plugin javascript
     */

    public function setup_action_javascript() {
        ?><script>
                    (function ($) {
                      function blockUI()
                      {
                        jQuery("#blocker").css('display', "");
                      }
                      function unblockUI()
                      {
                        jQuery("#blocker").css('display', "none");
                      }
                      var inProcess = false;
                      var $output = $('#manual-cron-output');
                      $('.manual-cron').click(function () {
                        if (inProcess == false) {
                          inProcess = true;
                          console.log($(this).attr('data-type'))
                          jQuery.ajax({
                            type: "POST",
                            url: ajaxurl,
                            data: {action: 'manual_run_cron_action', param: $(this).attr('data-type')},
                            cache: false,
                            beforeSend: function () {
                              // jQuery('#button-syncmanually').button('loading');
                              blockUI();
                            },
                            complete: function () {
                              //jQuery('#button-syncmanually').button('reset');
                              unblockUI();
                              inProcess = false;
                            },
                            success: function (data) {
                              $output.html(data.response);
                            },
                            error: function (jqXHR, textStatus, errorThrown) {
                              $output.html('<code>ERROR</code> ' + textStatus + ' ' + errorThrown);
                            }
                          }).done(function (msg) {
                            // alert("Data Saved: " + msg.response);
                            $output.html('<code>OK</code>' + msg.response);
                          });
                        }
                      });
                    }(jQuery));
        </script>
        <?php
    }

    /*
     * Plugin section setup
     */

    public function setup_sections() {
        add_settings_section('duell_configuration_section', 'Configure', array($this, 'section_callback'), 'duellintegration');
    }

    /*
     * Plugin section callback
     */

    public function section_callback($arguments) {
        switch ($arguments['id']) {
            case 'duell_configuration_section':
                echo '<b>Note:</b> Make sure you have API access in Duell manager section.';
                break;
        }
    }

    /*
     * Plugin fields setup
     */

    public function setup_fields() {
        $fields = array(
            array(
                'uid' => 'duellintegration_client_number',
                'label' => __('Client Number', 'duellintegration'),
                'section' => 'duell_configuration_section',
                'type' => 'number',
                'placeholder' => __('Client Number', 'duellintegration'),
                'class' => "",
                'default' => '',
                'helper' => '',
                'supplimental' => '',
                'validation' => true
            ),
            array(
                'uid' => 'duellintegration_client_token',
                'label' => __('Client Token', 'duellintegration'),
                'section' => 'duell_configuration_section',
                'type' => 'text',
                'placeholder' => __('Client Token', 'duellintegration'),
                'class' => "regular-text ltr",
                'default' => '',
                'helper' => '',
                'supplimental' => '',
                'validation' => true
            ),
            array(
                'uid' => 'duellintegration_stock_department_token',
                'label' => __('Stock Department', 'duellintegration'),
                'section' => 'duell_configuration_section',
                'type' => 'text',
                'placeholder' => __('Stock Department Token', 'duellintegration'),
                'supplimental' => 'Enter the department token from which stock will fetch',
                'class' => "regular-text ltr",
                'default' => '',
                'helper' => '',
                'validation' => true
            ),
            array(
                'uid' => 'duellintegration_order_department_token',
                'label' => __('Order Department', 'duellintegration'),
                'section' => 'duell_configuration_section',
                'type' => 'text',
                'placeholder' => __('Order Department Token', 'duellintegration'),
                'supplimental' => 'Enter the department token in which order will save',
                'class' => "regular-text ltr",
                'default' => '',
                'helper' => '',
                'validation' => true
            ),
            array(
                'uid' => 'duellintegration_update_existing_product',
                'label' => __('Update Existing Products', 'duellintegration'),
                'section' => 'duell_configuration_section',
                'type' => 'select',
                'options' => array(
                    '1' => 'Yes',
                    '0' => 'No'
                ),
                'default' => 0,
                'class' => "",
                'helper' => '',
                'supplimental' => '',
                'validation' => false
            ), array(
                'uid' => 'duellintegration_log_status',
                'label' => __('Enable Log', 'duellintegration'),
                'section' => 'duell_configuration_section',
                'type' => 'select',
                'options' => array(
                    '1' => 'Yes',
                    '0' => 'No'
                ),
                'default' => 0,
                'class' => "",
                'helper' => '',
                'supplimental' => '',
                'validation' => false
            ),
            array(
                'uid' => 'duellintegration_integration_status',
                'label' => __('Enable Sync', 'duellintegration'),
                'section' => 'duell_configuration_section',
                'type' => 'select',
                'options' => array(
                    '1' => 'Yes',
                    '0' => 'No'
                ),
                'default' => 1,
                'class' => "",
                'helper' => '',
                'supplimental' => '',
                'validation' => false
            )
        );
        foreach ($fields as $field) {
            add_settings_field($field['uid'], $field['label'], array($this, 'field_callback'), 'duellintegration', $field['section'], $field);
            if ($field['validation']) {
                register_setting('duellintegration', $field['uid'], array($this, 'plugin_validate_' . $field['uid'] . '_option'));
            } else {
                register_setting('duellintegration', $field['uid']);
            }
        }
    }

    /*
     * Plugin fields register, callback and render
     */

    public function field_callback($arguments) {
        $value = get_option($arguments['uid']);
        if (!$value) {
            $value = $arguments['default'];
        }
        switch ($arguments['type']) {
            case 'text':
            case 'password':
            case 'number':
                printf('<input name="%1$s" id="%1$s" type="%2$s" placeholder="%3$s" value="%4$s" class="%5$s" />', $arguments['uid'], $arguments['type'], $arguments['placeholder'], $value, $arguments['class']);
                break;
            case 'textarea':
                printf('<textarea name="%1$s" id="%1$s" placeholder="%2$s" rows="5" cols="50" class="%4$s">%3$s</textarea>', $arguments['uid'], $arguments['placeholder'], $value, $arguments['class']);
                break;
            case 'select':
            case 'multiselect':
                if (!empty($arguments['options']) && is_array($arguments['options'])) {
                    $attributes = '';
                    $options_markup = '';
                    foreach ($arguments['options'] as $key => $label) {
                        if ($arguments['type'] === 'multiselect') {
                            $options_markup .= sprintf('<option value="%s" %s>%s</option>', $key, selected($value[array_search($key, $value, true)], $key, false), $label);
                        } else {
                            $options_markup .= sprintf('<option value="%s" %s>%s</option>', $key, selected($value, $key, false), $label);
                        }
                    }
                    if ($arguments['type'] === 'multiselect') {
                        $attributes = ' multiple="multiple" ';
                        printf('<select name="%1$s[]" id="%1$s" %2$s class="%4$s">%3$s</select>', $arguments['uid'], $attributes, $options_markup, $arguments['class']);
                    } else {
                        printf('<select name="%1$s" id="%1$s" %2$s class="%4$s">%3$s</select>', $arguments['uid'], $attributes, $options_markup, $arguments['class']);
                    }
                }
                break;
            case 'radio':
            case 'checkbox':
                if (!empty($arguments['options']) && is_array($arguments['options'])) {
                    $options_markup = '';
                    $iterator = 0;
                    foreach ($arguments['options'] as $key => $label) {
                        $iterator++;
                        $options_markup .= sprintf('<label for="%1$s_%6$s"><input id="%1$s_%6$s" name="%1$s[]" type="%2$s" value="%3$s" %4$s /> %5$s</label><br/>', $arguments['uid'], $arguments['type'], $key, checked($value[array_search($key, $value, true)], $key, false), $label, $iterator);
                    }
                    printf('<fieldset>%s</fieldset>', $options_markup);
                }
                break;
        }
        if ($helper = $arguments['helper']) {
            printf('<span class="helper"> %s</span>', $helper);
        }
        if ($supplimental = $arguments['supplimental']) {
            printf('<p class="description">%s</p>', $supplimental);
        }
    }

    function plugin_validate_duellintegration_client_number_option($input) {
        if (is_null($input) || $input == '' || !is_numeric($input) || strlen($input) != 6) {
            $input = get_option('duellintegration_client_number');
            add_settings_error('duellintegration_messages', 'duellintegration_messages', 'Incorrect value entered in client number!', 'error');
        }
        return sanitize_text_field($input);
    }

    function plugin_validate_duellintegration_client_token_option($input) {
        if (is_null($input) || $input == '') {
            $input = get_option('duellintegration_client_token');
            add_settings_error('duellintegration_messages', 'duellintegration_messages', 'Incorrect value entered in client token!', 'error');
        }
        return sanitize_text_field($input);
    }

    function plugin_validate_duellintegration_stock_department_token_option($input) {
        if (is_null($input) || $input == '') {
            $input = get_option('duellintegration_stock_department_token');
            add_settings_error('duellintegration_messages', 'duellintegration_messages', 'Incorrect value entered in stock department token!', 'error');
        }
        return sanitize_text_field($input);
    }

    function plugin_validate_duellintegration_order_department_token_option($input) {
        if (is_null($input) || $input == '') {
            $input = get_option('duellintegration_order_department_token');
            add_settings_error('duellintegration_messages', 'duellintegration_messages', 'Incorrect value entered in order department token!', 'error');
        }
        return sanitize_text_field($input);
    }

    public function enqueue_admin_scripts_and_styles() {
        wp_enqueue_style('duellintegration_admin', plugin_dir_url(__FILE__) . '/assets/css/duellintegration.css');
    }

    // Add new column after total in woocommerce order list
    function duell_shop_order_column($columns) {
        $new_columns = array();
        foreach ($columns as $column_name => $column_info) {
            $new_columns[$column_name] = $column_info;
            if ('order_total' === $column_name) {
                $new_columns['order_duell_order_number'] = __('Duell #', 'duellintegration');
            }
        }
        return $new_columns;
    }

    // Adding data for duell order column
    function duell_order_list_column_content($column, $post_id) {
        $duell_order_number = get_post_meta($post_id, '_duell_order_number', true);
        if (empty($duell_order_number)) {
            $duell_order_number = '';
        }
        switch ($column) {
            case 'order_duell_order_number' :
                echo '<span>' . $duell_order_number . '</span>'; // display the data
                break;
        }
    }

    // Add duell order number searchable in woocommerce list
    function duell_search_fields($meta_keys) {
        $meta_keys[] = '_duell_order_number';
        return $meta_keys;
    }

    function cron_intervals_schedule($schedules) {
        $schedules['every3hours'] = array(
            'interval' => 10800,
            'display' => __('Every 3 hours')
        );
        $schedules['every30minutes'] = array(
            'interval' => 1800,
            'display' => __('Every 30 Minutes')
        );
        $schedules['daily3am'] = array(
            'interval' => 86400,
            'display' => __('Every day 3am')
        );
        return $schedules;
    }

    function manual_run_custom_cron() {
        $reponse = array();
        if (!empty($_POST['param'])) {
            $cronName = strtolower($_POST['param']);
            $actionRes = apply_filters('duell_cron_' . $cronName, 'manual');
            $response['response'] = $actionRes['message'];
        } else {
            $response['response'] = "You didn't send the param";
        }
        header("Content-Type: application/json");
        echo json_encode($response);
        exit();
    }

    public function sync_orders($type = "manual") {
        global $wpdb;
        $type = strtolower($type);
        $response = array();
        $response['status'] = FALSE;
        $response['message'] = 'All orders are synced.';
        try {
            $duellIntegrationStatus = get_option('duellintegration_integration_status');
            $duellClientNumber = (int) get_option('duellintegration_client_number');
            $duellClientToken = get_option('duellintegration_client_token');
            $duellOrderDepartmentToken = get_option('duellintegration_order_department_token');
            if ($duellIntegrationStatus == 1 || $duellIntegrationStatus == '1') {
                if ($duellClientNumber <= 0) {
                    $text_error = 'Client number is not setup';
                    write_log('OrderSync() - ' . $text_error);
                    $response['message'] = $text_error;
                    if ($type != 'manual') {
                        //duellMailAlert($text_error, 422);
                    }
                    return $response;
                }
                if (strlen($duellClientToken) <= 0) {
                    $text_error = 'Client token is not setup';
                    write_log('OrderSync() - ' . $text_error);
                    $response['message'] = $text_error;
                    if ($type != 'manual') {
                        //duellMailAlert($text_error, 422);
                    }
                    return $response;
                }
                ini_set('memory_limit', '-1');
                ini_set('max_execution_time', 0);
                ini_set('default_socket_timeout', 500000);
                $orderLastSyncDate = get_option('duellintegration_order_lastsync');
                $lastSyncDate = '';
                if (!is_null($orderLastSyncDate) && validateDateTime($orderLastSyncDate, 'Y-m-d H:i:s')) {
                    $lastSyncDate = date('Y-m-d H:i:s', strtotime($orderLastSyncDate));
                }
                //get all post with post_status=wc-completed,  post_type=shop_order
                $sql = "SELECT wp_posts.ID,wp_posts.post_date FROM wp_posts ";
                $sql .= " LEFT JOIN wp_postmeta ON (wp_posts.ID = wp_postmeta.post_id AND wp_postmeta.meta_key = '_duell_order_id' ) ";
                $sql .= " LEFT JOIN wp_postmeta AS mt1 ON ( wp_posts.ID = mt1.post_id ) ";
                if ($lastSyncDate != '') {
                    $sql .= " LEFT JOIN wp_postmeta as mt2 ON (wp_posts.ID = mt2.post_id AND mt2.meta_key = '_completed_date' ) ";
                }
                $sql .= " WHERE 1=1 ";
                $sql .= " AND wp_posts.post_type = 'shop_order' AND wp_posts.post_status = 'wc-completed' ";
                $sql .= " AND ( ";
                $sql .= " wp_postmeta.post_id IS NULL OR ( mt1.meta_key = '_duell_order_id' AND mt1.meta_value IS NULL )  OR  ( mt1.meta_key = '_duell_order_id' AND mt1.meta_value = '' ) ";
                $sql .= " ) ";
                if ($lastSyncDate != '') {
                    $sql .= " AND ( STR_TO_DATE(mt2.meta_value, '%Y-%m-%d %H:%i') >= '" . date('Y-m-d H:i', strtotime($lastSyncDate)) . "' ) ";
                }
                $sql .= " GROUP BY wp_posts.ID ORDER BY wp_posts.ID ASC";
                $fetchNonSyncedOrders = $wpdb->get_results($sql, ARRAY_A);
                $prepareOrderData = array();
                $notSyncCategoryData = array();
                $notSyncCategoryOrderData = array();
                $notSyncCategoryProductData = array();
                $notSyncProductData = array();
                $notSyncProductOrderData = array();
                $notSyncCustomerData = array();
                $notSyncCustomerOrderData = array();
                $woocommerce_prices_include_tax = get_option('woocommerce_prices_include_tax'); //=yes (inc tax) or no (excl. tax)
                $woocommerce_calc_taxes = get_option('woocommerce_calc_taxes');
                if (!empty($fetchNonSyncedOrders)) {
                    foreach ($fetchNonSyncedOrders as $postId) {
                        $orderDetails = getWooCommerceOrderDetailById($postId['ID']);
                        $orderData = array();
                        $orderProductData = array();
                        if (is_array($orderDetails) && !empty($orderDetails) && !is_null($orderDetails) && isset($orderDetails['order']) && !empty($orderDetails['order']['line_items']) && !empty($orderDetails['order']['billing_address']['first_name']) && (!empty($orderDetails['order']['billing_address']['email']) || !empty($orderDetails['order']['billing_address']['phone']) )) {
                            $orderDetailData = $orderDetails['order'];
                            $orderId = $orderDetailData['id'];
                            if (!is_null($duellOrderDepartmentToken) && $duellOrderDepartmentToken != '') {
                                $orderData['department_id'] = $duellOrderDepartmentToken;
                            }
                            $orderData['comments'] = $orderDetailData['note'];
                            $orderData['reference_comment'] = '';
                            $orderData['reference_order_number'] = $orderId; //$orderDetailData['order_number'];
                            $orderData['round_off_amount'] = 0;
                            $orderBillingInfo = $orderDetailData['billing_address'];
                            $orderData['customer_id'] = $orderBillingInfo['duell_customer_id'];
                            if ($orderData['customer_id'] == 0 || is_null($orderData['customer_id']) || $orderData['customer_id'] == '') {
                                $customerKey = $orderBillingInfo['email'];
                                if (!empty($orderBillingInfo['email'])) {
                                    $customerKey = $orderBillingInfo['email'];
                                } elseif (!empty($orderBillingInfo['phone'])) {
                                    $customerKey = (int) str_replace(array(' ', '+', '#', '*'), '', $orderBillingInfo['phone']);
                                }
                                $notSyncCustomerData[$customerKey] = array(
                                    'customer_name' => $orderBillingInfo['first_name'] . ' ' . $orderBillingInfo['last_name'],
                                    'phone' => !empty($orderBillingInfo['phone']) ? (int) str_replace(array(' ', '+', '#', '*'), '', $orderBillingInfo['phone']) : '',
                                    'email' => $orderBillingInfo['email'],
                                    'primary_address' => $orderBillingInfo['address_1'],
                                    'primary_zip' => $orderBillingInfo['postcode'],
                                    'city' => $orderBillingInfo['city']);
                                $notSyncCustomerOrderData[$orderBillingInfo['email']][] = array('order_id' => $orderId);
                            }
                            foreach ($orderDetailData['line_items'] as $orderLine) {
                                $orderProduct = array();
                                $price_ex_vat = 0.00;
                                $price_inc_vat = 0.00;
                                $vatrate_percent = 0.00;
                                $discount_percentage = 0.00;
                                $orderlineId = $orderLine['id'];
                                $quantity = $orderLine['quantity'];
                                $tax_class = $orderLine['tax_class'];
                                $singleQtyPriceAfterDiscount = $orderLine['price'];
                                if ($woocommerce_calc_taxes == 'yes' && $woocommerce_prices_include_tax == 'yes') {
                                    $vatrate_percent = $orderLine['item_tax_rate'];
                                    $vatrateMultiplier = 1 + ($vatrate_percent / 100);
                                    //==original cost
                                    $subtotalWithQty = $orderLine['subtotal'];
                                    $subtotalTaxWithQty = $orderLine['subtotal_tax'];
                                    $singleQtyPrice = $subtotalWithQty / $quantity;
                                    $singleQtyTax = $subtotalTaxWithQty / $quantity;
                                    //==total cost after discount
                                    $totalWithQty = $orderLine['total'];
                                    $totalTaxWithQty = $orderLine['total_tax'];
                                    $singleTotalQtyPrice = $totalWithQty / $quantity;
                                    $singleTotalQtyTax = $totalTaxWithQty / $quantity;
                                    //==calculate discount
                                    $singleProductDiscountAmount = $singleQtyPrice - $singleQtyPriceAfterDiscount;
                                    if ($singleProductDiscountAmount > 0) {
                                        $discount_percentage = round((($singleProductDiscountAmount * 100) / $singleQtyPrice), 2);
                                    }
                                    $price_ex_vat = number_format($singleQtyPrice / $vatrateMultiplier, 2);
                                    $price_inc_vat = $singleQtyPrice;
                                } else {
                                    //==original cost
                                    $subtotalWithQty = $orderLine['subtotal'];
                                    $subtotalTaxWithQty = $orderLine['subtotal_tax'];
                                    $singleQtyPrice = $subtotalWithQty / $quantity;
                                    $singleQtyTax = $subtotalTaxWithQty / $quantity;
                                    //==total cost after discount
                                    $totalWithQty = $orderLine['total'];
                                    $totalTaxWithQty = $orderLine['total_tax'];
                                    $singleTotalQtyPrice = $totalWithQty / $quantity;
                                    $singleTotalQtyTax = $totalTaxWithQty / $quantity;
                                    //==calculate discount
                                    $singleProductDiscountAmount = $singleQtyPrice - $singleQtyPriceAfterDiscount;
                                    if ($singleProductDiscountAmount > 0) {
                                        $discount_percentage = round((($singleProductDiscountAmount * 100) / $singleQtyPrice), 2);
                                    }
                                    //==calculate vatrate percentage
                                    if ($singleQtyTax > 0) {
                                        $vatrate_percent = round(number_format((($singleQtyTax * 100) / $singleQtyPrice), 2));
                                    }
                                    $price_ex_vat = $singleQtyPrice;
                                    $price_inc_vat = $singleQtyPrice + $singleQtyTax;
                                }
                                $orderProduct['entity_type'] = 'product';
                                $orderProduct['product_id'] = $orderLine['duell_product_id'];
                                $orderProduct['price_ex_vat'] = $price_ex_vat;
                                $orderProduct['price_inc_vat'] = $price_inc_vat;
                                $orderProduct['quantity'] = $quantity;
                                $orderProduct['vatrate_percent'] = $vatrate_percent;
                                $orderProduct['discount_percentage'] = $discount_percentage;
                                $orderProduct['comments'] = '';
                                if ($orderLine['duell_category_id'] <= 0 || $orderLine['duell_category_id'] == '' || is_null($orderLine['duell_category_id'])) {
                                    $notSyncCategoryData[$orderLine['category_id']] = array('category_name' => $orderLine['category_name']);
                                    $notSyncCategoryOrderData[$orderLine['category_id']][] = array('order_id' => $orderId, 'orderline_id' => $orderlineId);
                                    $notSyncCategoryProductData[$orderLine['category_id']][] = $orderLine['product_id'];
                                }
                                if ($orderLine['duell_product_id'] <= 0 || $orderLine['duell_product_id'] == '' || is_null($orderLine['duell_product_id'])) {
                                    $notSyncProductData[$orderLine['product_id']] = array('product_name' => $orderLine['name'], 'product_number' => $orderLine['sku'], 'price_inc_vat' => $price_inc_vat, 'vatrate_percent' => $vatrate_percent, 'category_id' => $orderLine['duell_category_id']);
                                    $notSyncProductOrderData[$orderLine['product_id']][] = array('order_id' => $orderId, 'orderline_id' => $orderlineId);
                                }
                                $orderProductData[$orderlineId] = $orderProduct;
                            }
                            if (!empty($orderProductData)) {
                                if ($orderDetailData['total_shipping'] > 0) {
                                    $orderlineId = -9999999;
                                    $shippingProductId = get_option('duellintegration_shipping_product_id');
                                    $shippingProductCategoryId = get_option('duellintegration_shipping_category_id');
                                    $shipping_vatrate_percent = 0.00;
                                    $shipping_price_ex_vat = $orderDetailData['total_shipping'];
                                    $shipping_price_inc_vat = $orderDetailData['total_shipping'] + $orderDetailData['shipping_tax'];
                                    if ($orderDetailData['shipping_tax'] > 0) {
                                        $shipping_vatrate_percent = round(number_format((($orderDetailData['shipping_tax'] * 100) / $shipping_price_ex_vat), 2));
                                    }
                                    if ($shippingProductCategoryId <= 0 || $shippingProductCategoryId == '' || is_null($shippingProductCategoryId)) {
                                        $notSyncCategoryData[$orderlineId] = array('category_name' => 'SHIPPING');
                                        $notSyncCategoryOrderData[$orderlineId][] = array('order_id' => $orderId, 'orderline_id' => $orderlineId);
                                        $notSyncCategoryProductData[$orderlineId][] = $orderlineId;
                                    }
                                    if (is_null($shippingProductId) || $shippingProductId == '' || $shippingProductId <= 0) {
                                        $notSyncProductData[$orderlineId] = array('product_name' => 'SHIPPING', 'product_number' => 'SHIPPING', 'price_inc_vat' => $shipping_price_inc_vat, 'vatrate_percent' => $shipping_vatrate_percent, 'category_id' => $shippingProductCategoryId);
                                        $notSyncProductOrderData[$orderlineId][] = array('order_id' => $orderId, 'orderline_id' => $orderlineId);
                                    }
                                    $orderProduct['entity_type'] = 'product';
                                    $orderProduct['product_id'] = $shippingProductId;
                                    $orderProduct['price_ex_vat'] = $shipping_price_ex_vat;
                                    $orderProduct['price_inc_vat'] = $shipping_price_inc_vat;
                                    $orderProduct['quantity'] = 1;
                                    $orderProduct['vatrate_percent'] = $shipping_vatrate_percent;
                                    $orderProduct['discount_percentage'] = 0.00;
                                    $orderProduct['comments'] = '';
                                    $orderProductData[$orderlineId] = $orderProduct;
                                }
                                $prepareOrderData[$orderId] = array('order_data' => $orderData, 'product_data' => $orderProductData);
                            }
                        }
                        unset($orderData);
                        unset($orderProductData);
                    }
                    /* write_log($notSyncCategoryData);
                      write_log($notSyncCategoryOrderData);
                      write_log($notSyncCategoryProductData);
                      write_log($notSyncCustomerData);
                      write_log($notSyncCustomerOrderData);
                      write_log($notSyncProductData);
                      write_log($notSyncProductOrderData);
                      write_log($prepareOrderData); */
                    $isCustomerSync = true;
                    $newCustomersDuellId = array();
                    try {
                        if (!empty($notSyncCustomerData)) {
                            $isCustomerSync = false;
                            foreach ($notSyncCustomerData as $custEmail => $customerRowData) {
                                ///
                                $duellCustomerId = 0;
                                $customerApiData = array('client_number' => $duellClientNumber, 'client_token' => $duellClientToken, 'length' => 1, 'start' => 0);
                                if (filter_var($custEmail, FILTER_VALIDATE_EMAIL)) {
                                    $customerApiData['filter[customer_email]'] = $custEmail;
                                } else {
                                    $customerApiData['filter[customer_phone]'] = $custEmail;
                                }
                                $wsdata = callDuell('customer/list', 'get', $customerApiData, 'json', $type);
                                if (isset($wsdata['status']) && $wsdata['status'] === true) {
                                    $totalRecord = $wsdata['total_count'];
                                    if ($totalRecord > 0) {
                                        if (isset($wsdata['customers']) && !empty($wsdata['customers'])) {
                                            $allData = $wsdata['customers'];
                                            if (isset($allData[0]['customer_id']) && (int) $allData[0]['customer_id'] > 0) {
                                                $duellCustomerId = $allData[0]['customer_id'];
                                            }
                                        }
                                    }
                                }
                                if ($duellCustomerId == 0) {
                                    $customerSaveData = array(
                                        'customer_name' => $customerRowData['customer_name'],
                                        'phone' => $customerRowData['phone'],
                                        'email' => $customerRowData['email'],
                                        'primary_address' => $customerRowData['primary_address'],
                                        'primary_zip' => $customerRowData['primary_zip'],
                                        'city' => $customerRowData['city']
                                    );
                                    $wsdata = callDuell('customer', 'put', $customerSaveData, 'json', $type);
                                    if (isset($wsdata['status']) && $wsdata['status'] === true) {
                                        if (isset($wsdata['customers']) && !empty($wsdata['customers'])) {
                                            $duellCustomerId = $wsdata['customers'];
                                        }
                                    }
                                }
                                if ($duellCustomerId > 0) {
                                    $newCustomersDuellId[$custEmail] = $duellCustomerId;
                                }
                            }
                            if (!empty($newCustomersDuellId)) {
                                foreach ($newCustomersDuellId as $custEmail => $duellCustomerId) {
                                    if (isset($notSyncCustomerData[$custEmail]) && isset($notSyncCustomerOrderData[$custEmail])) {
                                        $custOrderIds = $notSyncCustomerOrderData[$custEmail];
                                        foreach ($custOrderIds as $custOrderData) {
                                            $prepareOrderData[$custOrderData['order_id']]['order_data']['customer_id'] = $duellCustomerId;
                                            update_post_meta($custOrderData['order_id'], '_duell_customer_id', $duellCustomerId);
                                        }
                                        unset($notSyncCustomerData[$custEmail]);
                                        unset($notSyncCustomerOrderData[$custEmail]);
                                    }
                                }
                            }
                            if (empty($notSyncCustomerData) && empty($notSyncCustomerOrderData)) {
                                $isCustomerSync = true;
                            } else {
                                write_log("notSyncCustomerData: " . json_encode($notSyncCustomerData));
                                write_log("notSyncCustomerOrderData: " . json_encode($notSyncCustomerOrderData));
                            }
                        }
                    } catch (Exception $ex) {
                        write_log("customerSync(Exception): " . json_encode($ex));
                    }
                    write_log("isCustomerSync(getOrders): " . $isCustomerSync);
                    //==category sync
                    $isProductCategorySync = true;
                    $newCategoriesDuellId = array();
                    try {
                        if (!empty($notSyncCategoryData)) {
                            $isProductCategorySync = false;
                            foreach ($notSyncCategoryData as $catId => $categoryRowData) {
                                $duellCategoryId = 0;
                                $categoryApiData = array('client_number' => $duellClientNumber, 'client_token' => $duellClientToken, 'length' => 1, 'start' => 0);
                                $categoryApiData['filter[category_name]'] = $categoryRowData['category_name'];
                                $wsdata = callDuell('product/category/list/product', 'get', $categoryApiData, 'json', $type);
                                if (isset($wsdata['status']) && $wsdata['status'] === true) {
                                    $totalRecord = $wsdata['total_count'];
                                    if ($totalRecord > 0) {
                                        if (isset($wsdata['categories']) && !empty($wsdata['categories'])) {
                                            $allData = $wsdata['categories'];
                                            if (isset($allData[0]['category_id']) && (int) $allData[0]['category_id'] > 0) {
                                                $duellCategoryId = $allData[0]['category_id'];
                                            }
                                        }
                                    }
                                }
                                if ($duellCategoryId == 0) {
                                    $categoryNewData = array();
                                    $categoryNewData[] = array('category_name' => $categoryRowData['category_name'],
                                        'category_type' => 'product'
                                    );
                                    $categorySaveData = array('client_number' => $duellClientNumber, 'client_token' => $duellClientToken, 'category_data' => $categoryNewData);
                                    $wsdata = callDuell('product/category/save', 'post', $categorySaveData, 'json', $type);
                                    if (isset($wsdata['status']) && $wsdata['status'] === true) {
                                        if (isset($wsdata['data']) && !empty($wsdata['data'])) {
                                            $allData = $wsdata['data'];
                                            if (isset($allData[0]['category_id']) && (int) $allData[0]['category_id'] > 0) {
                                                $duellCategoryId = $allData[0]['category_id'];
                                            }
                                        }
                                    }
                                }
                                if ($duellCategoryId > 0) {
                                    $newCategoriesDuellId[$catId] = $duellCategoryId;
                                    if ($categoryRowData['category_name'] == 'SHIPPING') {
                                        update_option('duellintegration_shipping_category_id', $duellCategoryId);
                                    } else {
                                        update_term_meta($catId, '_duell_category_id', $duellCategoryId);
                                    }
                                }
                            }
                            if (!empty($newCategoriesDuellId)) {
                                foreach ($newCategoriesDuellId as $catId => $duellCategoryId) {
                                    if (isset($notSyncCategoryData[$catId]) && isset($notSyncCategoryOrderData[$catId]) && isset($notSyncCategoryProductData[$catId])) {
                                        $catProductIds = $notSyncCategoryProductData[$catId];
                                        foreach ($catProductIds as $productId) {
                                            $notSyncProductData[$productId]['category_id'] = $duellCategoryId;
                                        }
                                        unset($notSyncCategoryData[$catId]);
                                        unset($notSyncCategoryOrderData[$catId]);
                                        unset($notSyncCategoryProductData[$catId]);
                                    }
                                }
                            }
                            if (empty($notSyncCategoryData) && empty($notSyncCategoryOrderData) && empty($notSyncCategoryProductData)) {
                                $isProductCategorySync = true;
                            } else {
                                write_log("notSyncCategoryData(getOrders): " . json_encode($notSyncCategoryData));
                                write_log("notSyncCategoryOrderData(getOrders): " . json_encode($notSyncCategoryOrderData));
                            }
                        }
                    } catch (\Exception $ex) {
                        write_log("ProductCategorySyncException(getOrders): " . json_encode($ex));
                    }
                    ///== end order product category sync
                    write_log("isProductCatSync(getOrders): " . $isProductCategorySync);
                    //==product sync
                    $isProductSync = true;
                    $newProductsDuellId = array();
                    try {
                        if (!empty($notSyncProductData)) {
                            $isProductSync = false;
                            foreach ($notSyncProductData as $productId => $productRowData) {
                                $duellProductId = 0;
                                $productApiData = array('client_number' => $duellClientNumber, 'client_token' => $duellClientToken, 'length' => 1, 'start' => 0);
                                $productApiData['filter[product_number]'] = $productRowData['product_number'];
                                $wsdata = callDuell('product/list', 'get', $productApiData, 'json', $type);
                                if (isset($wsdata['status']) && $wsdata['status'] === true) {
                                    $totalRecord = $wsdata['total_count'];
                                    if ($totalRecord > 0) {
                                        if (isset($wsdata['products']) && !empty($wsdata['products'])) {
                                            $allData = $wsdata['products'];
                                            if (isset($allData[0]['product_id']) && (int) $allData[0]['product_id'] > 0) {
                                                $duellProductId = $allData[0]['product_id'];
                                            }
                                        }
                                    }
                                }
                                if ($duellProductId == 0) {
                                    $productNewData = array();
                                    $productNewData[] = $productRowData;
                                    $productSaveData = array('client_number' => $duellClientNumber, 'client_token' => $duellClientToken, 'product_data' => $productNewData);
                                    $wsdata = callDuell('product/save', 'post', $productSaveData, 'json', $type);
                                    if (isset($wsdata['status']) && $wsdata['status'] === true) {
                                        if (isset($wsdata['data']) && !empty($wsdata['data'])) {
                                            $allData = $wsdata['data'];
                                            if (isset($allData[0]['product_id']) && (int) $allData[0]['product_id'] > 0) {
                                                $duellProductId = $allData[0]['product_id'];
                                            }
                                        }
                                    }
                                }
                                if ($duellProductId > 0) {
                                    $newProductsDuellId[$productId] = $duellProductId;
                                    if ($productRowData['product_number'] == 'SHIPPING') {
                                        update_option('duellintegration_shipping_product_id', $duellProductId);
                                    } else {
                                        update_post_meta($productId, '_duell_product_id', $duellProductId);
                                    }
                                }
                            }
                            if (!empty($newProductsDuellId)) {
                                foreach ($newProductsDuellId as $productId => $duellProductId) {
                                    if (isset($notSyncProductData[$productId]) && isset($notSyncProductOrderData[$productId])) {
                                        $productOrderIds = $notSyncProductOrderData[$productId];
                                        foreach ($productOrderIds as $productOrderData) {
                                            $prepareOrderData[$productOrderData['order_id']]['product_data'][$productOrderData['orderline_id']]['product_id'] = $duellProductId;
                                        }
                                        unset($notSyncProductData[$productId]);
                                        unset($notSyncProductOrderData[$productId]);
                                    }
                                }
                            }
                            if (empty($notSyncProductData) && empty($notSyncProductOrderData)) {
                                $isProductSync = true;
                            } else {
                                write_log("notSyncProductData(getOrders): " . json_encode($notSyncProductData));
                                write_log("notSyncProductOrderData(getOrders): " . json_encode($notSyncProductOrderData));
                            }
                        }
                    } catch (\Exception $ex) {
                        write_log("ProductSyncException(getOrders): " . json_encode($ex));
                    }
                    ///== end order product sync
                    write_log("isProductSync(getOrders): " . $isProductSync);
                    try {
                        if (!empty($prepareOrderData)) {
                            if ($isCustomerSync == true && $isProductCategorySync == true && $isProductSync == true) {
                                $orderApiData = array('client_number' => $duellClientNumber, 'client_token' => $duellClientToken, 'orders' => $prepareOrderData);
                                $wsdata = callDuell('sale/orders/save', 'post', $orderApiData, 'json', $type);
                                if (isset($wsdata['validation_message']) && !empty($wsdata['validation_message'])) {
                                    write_log($wsdata['validation_message']);
                                }
                                if (isset($wsdata['status']) && $wsdata['status'] === true) {
                                    $totalRecord = $wsdata['total_affected'];
                                    if ($totalRecord > 0) {
                                        if (isset($wsdata['data']) && !empty($wsdata['data'])) {
                                            $allData = $wsdata['data'];
                                            foreach ($allData as $orderRow) {
                                                update_post_meta($orderRow['reference_order_number'], '_duell_order_id', $orderRow['order_id']);
                                                update_post_meta($orderRow['reference_order_number'], '_duell_order_number', $orderRow['order_number']);
                                            }
                                        }
                                    }
                                    $response['status'] = TRUE;
                                    $response['message'] = 'success';
                                    update_option('duellintegration_order_lastsync', date('Y-m-d H:i:s'));
                                    return $response;
                                } else {
                                    $text_error = $wsdata['message'];
                                    write_log('OrderSync() - Error:: ' . $text_error);
                                    $response['message'] = $text_error;
                                }
                            }
                        }
                    } catch (\Exception $ex) {
                        write_log("orderSyncException(getOrders): " . json_encode($ex));
                    }
                }
            } else {
                $text_error = 'Integration status is not active.';
                write_log('OrderSync() - ' . $text_error);
                $response['message'] = $text_error;
                return $response;
            }
        } catch (Exception $e) {
            $text_error = 'Catch exception throw:: ' . $e->getMessage();
            write_log('OrderSync() - ' . $text_error);
            if ($type != 'manual') {
                duellMailAlert($text_error, 422);
            }
        }
        return $response;
    }

    function wc_subtract_stock_after_order_placed($order_id) {
        if (!$order_id) {
            return;
        }
        try {
            $duellIntegrationStatus = get_option('duellintegration_integration_status');
            $duellClientNumber = (int) get_option('duellintegration_client_number');
            $duellClientToken = get_option('duellintegration_client_token');
            $duellStockDepartmentToken = get_option('duellintegration_stock_department_token');
            if ($duellIntegrationStatus == 1 || $duellIntegrationStatus == '1') {
                if ($duellClientNumber <= 0) {
                    $text_error = 'AdjustStockSync() - Client number is not setup';
                    write_log($text_error);
                    return;
                }
                if (strlen($duellClientToken) <= 0) {
                    $text_error = 'AdjustStockSync() - Client token is not setup';
                    write_log($text_error);
                    return;
                }
                if (strlen($duellStockDepartmentToken) <= 0) {
                    $text_error = 'AdjustStockSync() - Stock department token is not setup';
                    write_log($text_error);
                    return;
                }
                $orderDetail = getWooCommerceOrderProductsById($order_id, array('sku', 'quantity'));
                $duellProductData = array();
                $productStockLogStr = PHP_EOL . PHP_EOL;
                if (isset($orderDetail['order']['line_items']) && !empty($orderDetail['order']['line_items'])) {
                    $orderLineItems = $orderDetail['order']['line_items'];
                    foreach ($orderLineItems as $lineItem) {
                        if (isset($lineItem['id']) && $lineItem['id'] > 0 && isset($lineItem['sku']) && $lineItem['sku'] != '' && isset($lineItem['quantity']) && $lineItem['quantity'] > 0) {
                            $duellProductData[] = array('product_number' => $lineItem['sku'], 'quantity' => $lineItem['quantity']);
                            $productStockLogStr .= 'Product Id: ' . $lineItem['id'] . ' SKU: ' . $lineItem['sku'] . ' Qty: ' . $lineItem['quantity'] . PHP_EOL;
                        }
                    }
                }
                ini_set('memory_limit', '-1');
                ini_set('max_execution_time', 0);
                ini_set('default_socket_timeout', 500000);
                $apiData = array('client_number' => $duellClientNumber, 'client_token' => $duellClientToken);
                $apiData['department_token'] = $duellStockDepartmentToken;
                $apiData['product_data'] = $duellProductData;
                $wsdata = callDuell('product/adjust-stock', 'post', $apiData);
                if (isset($wsdata['status']) && $wsdata['status'] === true) {
                    write_log('duellStockUpdateSuccess():: Order Id: ' . $orderDetail['order']['id'] . $productStockLogStr, true);
                } else {
                    $text_error = 'AdjustStockSync() - Error:: ' . $wsdata['message'];
                    write_log($text_error);
                    duellMailAlert($text_error, 422);
                    return;
                }
            } else {
                $text_error = 'Integration status is not active.';
                return;
            }
        } catch (Exception $e) {
            $text_error = 'AdjustStockSync() - Catch exception throw:: ' . $e->getMessage();
            write_log($text_error);
            duellMailAlert($text_error, 422);
            return;
        }
    }

    public function sync_stocks($type = "manual") {
        $type = strtolower($type);
        $response = array();
        $response['status'] = FALSE;
        $response['message'] = 'Webservice is temporary unavailable. Please try again.';
        try {
            $duellIntegrationStatus = get_option('duellintegration_integration_status');
            $duellClientNumber = (int) get_option('duellintegration_client_number');
            $duellClientToken = get_option('duellintegration_client_token');
            $duellStockDepartmentToken = get_option('duellintegration_stock_department_token');
            if ($duellIntegrationStatus == 1 || $duellIntegrationStatus == '1') {
                if ($duellClientNumber <= 0) {
                    $text_error = 'Client number is not setup';
                    write_log('StockSync() - ' . $text_error);
                    $response['message'] = $text_error;
                    if ($type != 'manual') {
                        //duellMailAlert($text_error, 422);
                    }
                    return $response;
                }
                if (strlen($duellClientToken) <= 0) {
                    $text_error = 'Client token is not setup';
                    write_log('StockSync() - ' . $text_error);
                    $response['message'] = $text_error;
                    if ($type != 'manual') {
                        //duellMailAlert($text_error, 422);
                    }
                    return $response;
                }
                if (strlen($duellStockDepartmentToken) <= 0) {
                    $text_error = 'Stock department token is not setup';
                    write_log('StockSync() - ' . $text_error);
                    $response['message'] = $text_error;
                    if ($type != 'manual') {
                        //duellMailAlert($text_error, 422);
                    }
                    return $response;
                }
                ini_set('memory_limit', '-1');
                ini_set('max_execution_time', 0);
                ini_set('default_socket_timeout', 500000);
                $start = 0;
                $limit = $this->duellLimit;
                $apiData = array('client_number' => $duellClientNumber, 'client_token' => $duellClientToken, 'length' => $limit, 'start' => $start);
                $apiData['department'] = $duellStockDepartmentToken;
                $wsdata = callDuell('all/product/stock', 'get', $apiData, 'json', $type);
                if (isset($wsdata['status']) && $wsdata['status'] === true) {
                    $totalRecord = $wsdata['total_count'];
                    if ($totalRecord > 0) {
                        if (isset($wsdata['data']) && !empty($wsdata['data'])) {
                            $allData = $wsdata['data'];
                            $this->processProductStockData($allData);
                            usleep(500000);
                            $nextCounter = $start + $limit;
                            while ($totalRecord > $limit && $totalRecord > $nextCounter) {
                                $apiData = array('client_number' => $duellClientNumber, 'client_token' => $duellClientToken, 'length' => $limit, 'start' => $nextCounter);
                                $apiData['department'] = $duellStockDepartmentToken;
                                $wsdata = callDuell('all/product/stock', 'get', $apiData, 'json', $type);
                                if (isset($wsdata['status']) && $wsdata['status'] === true) {
                                    $totalNRecord = $wsdata['total_count'];
                                    if ($totalNRecord > 0) {
                                        if (isset($wsdata['data']) && !empty($wsdata['data'])) {
                                            $allData = $wsdata['data'];
                                            $this->processProductStockData($allData);
                                        }
                                    }
                                    $nextCounter = $nextCounter + $limit;
                                }
                                usleep(500000);
                            }
                        }
                    }
                    $response['status'] = TRUE;
                    $response['message'] = 'success';
                    return $response;
                } else {
                    $text_error = $wsdata['message'];
                    write_log('StockSync() - Error:: ' . $text_error);
                    $response['message'] = $text_error;
                }
            } else {
                $text_error = 'Integration status is not active.';
                write_log('StockSync() - ' . $text_error);
                $response['message'] = $text_error;
                return $response;
            }
        } catch (Exception $e) {
            $text_error = 'Catch exception throw:: ' . $e->getMessage();
            write_log('StockSync() - ' . $text_error);
            if ($type != 'manual') {
                duellMailAlert($text_error, 422);
            }
        }
        return $response;
    }

    function processProductStockData($data = array()) {
        $woocommerce_prices_include_tax = get_option('woocommerce_prices_include_tax'); //=yes (inc tax) or no (excl. tax)
        if (!empty($data)) {
            foreach ($data as $product) {
                $productNumber = isset($product['product_number']) ? $product['product_number'] : '';
                $stock = isset($product['department'][0]['stock']) ? $product['department'][0]['stock'] : 0;
                $productExists = getWooCommerceProductBySku($productNumber);
                if (!is_null($productExists)) {
                    $post_id = $productExists;
                    $manageStock = get_post_meta($post_id, '_manage_stock', true);
                    if ($manageStock == 'yes') {
                        $stockStatus = get_post_meta($post_id, '_stock_status', true);
                        $currentStock = get_post_meta($post_id, '_stock', true);
                        write_log('processStockUpdation() Before updating stock - Product Id: ' . $post_id . ' Current Status: ' . $stockStatus . ' Current Qty: ' . $currentStock . ' New Qty: ' . $stock, true);
                        $stockStatusMsg = 'outofstock';
                        if ($stock > 0) {
                            $stockStatusMsg = 'instock';
                        }
                        update_post_meta($post_id, '_stock_status', $stockStatusMsg);
                        update_post_meta($post_id, '_stock', $stock);
                    }
                }
            }
        }
    }

    public function sync_prices($type = "manual") {
        $type = strtolower($type);
        $response = array();
        $response['status'] = FALSE;
        $response['message'] = 'Webservice is temporary unavailable. Please try again.';
        try {
            $duellIntegrationStatus = get_option('duellintegration_integration_status');
            $duellClientNumber = (int) get_option('duellintegration_client_number');
            $duellClientToken = get_option('duellintegration_client_token');
            if ($duellIntegrationStatus == 1 || $duellIntegrationStatus == '1') {
                if ($duellClientNumber <= 0) {
                    $text_error = 'Client number is not setup';
                    write_log('PricesSync() - ' . $text_error);
                    $response['message'] = $text_error;
                    if ($type != 'manual') {
                        //duellMailAlert($text_error, 422);
                    }
                    return $response;
                }
                if (strlen($duellClientToken) <= 0) {
                    $text_error = 'Client token is not setup';
                    write_log('PricesSync() - ' . $text_error);
                    $response['message'] = $text_error;
                    if ($type != 'manual') {
                        //duellMailAlert($text_error, 422);
                    }
                    return $response;
                }
                ini_set('memory_limit', '-1');
                ini_set('max_execution_time', 0);
                ini_set('default_socket_timeout', 500000);
                $lastSyncDate = get_option('duellintegration_prices_lastsync');
                $start = 0;
                $limit = $this->duellLimit;
                $apiData = array('client_number' => $duellClientNumber, 'client_token' => $duellClientToken, 'length' => $limit, 'start' => $start);
                if (!is_null($lastSyncDate) && validateDateTime($lastSyncDate, 'Y-m-d H:i:s')) {
                    $apiData['filter[last_update_date]'] = date('Y-m-d H:i:s', strtotime($lastSyncDate));
                }
                $wsdata = callDuell('product/list', 'get', $apiData, 'json', $type);
                if (isset($wsdata['status']) && $wsdata['status'] === true) {
                    $totalRecord = $wsdata['total_count'];
                    if ($totalRecord > 0) {
                        if (isset($wsdata['products']) && !empty($wsdata['products'])) {
                            $allData = $wsdata['products'];
                            $this->processProductPriceData($allData);
                            usleep(500000);
                            $nextCounter = $start + $limit;
                            while ($totalRecord > $limit && $totalRecord > $nextCounter) {
                                $apiData = array('client_number' => $duellClientNumber, 'client_token' => $duellClientToken, 'length' => $limit, 'start' => $nextCounter);
                                if (!is_null($lastSyncDate) && validateDateTime($lastSyncDate, 'Y-m-d H:i:s')) {
                                    $apiData['filter[last_update_date]'] = date('Y-m-d H:i:s', strtotime($lastSyncDate));
                                }
                                $wsdata = callDuell('product/list', 'get', $apiData, 'json', $type);
                                if (isset($wsdata['status']) && $wsdata['status'] === true) {
                                    $totalNRecord = $wsdata['total_count'];
                                    if ($totalNRecord > 0) {
                                        if (isset($wsdata['products']) && !empty($wsdata['products'])) {
                                            $allData = $wsdata['products'];
                                            $this->processProductPriceData($allData);
                                        }
                                    }
                                    $nextCounter = $nextCounter + $limit;
                                }
                                usleep(500000);
                            }
                        }
                        update_option('duellintegration_prices_lastsync', date('Y-m-d H:i:s'));
                    }
                    $response['status'] = TRUE;
                    $response['message'] = 'success';
                    return $response;
                } else {
                    $text_error = $wsdata['message'];
                    write_log('PricesSync() - Error:: ' . $text_error);
                    $response['message'] = $text_error;
                }
            } else {
                $text_error = 'Integration status is not active.';
                write_log('PricesSync() - ' . $text_error);
                $response['message'] = $text_error;
                return $response;
            }
        } catch (Exception $e) {
            $text_error = 'Catch exception throw:: ' . $e->getMessage();
            write_log('PricesSync() - ' . $text_error);
            if ($type != 'manual') {
                duellMailAlert($text_error, 422);
            }
        }
        return $response;
    }

    function processProductPriceData($data = array()) {
        $woocommerce_prices_include_tax = get_option('woocommerce_prices_include_tax'); //=yes (inc tax) or no (excl. tax)
        if (!empty($data)) {
            foreach ($data as $product) {
                $productNumber = $product['product_number'];
                $vatratePercentage = $product['vatrate_percent'];
                $costPrice = $product['cost_price'];
                $priceIncTax = $product['price_inc_vat'];
                $finalPrice = 0;
                if ($woocommerce_prices_include_tax == 'yes') {
                    $finalPrice = $priceIncTax;
                } else {
                    $vatrateMultiplier = 1 + ( $vatratePercentage / 100);
                    $priceExTax = $priceIncTax / $vatrateMultiplier;
                    $finalPrice = number_format($priceExTax, 2, '.', '');
                }
                $productExists = getWooCommerceProductBySku($productNumber);
                if (!is_null($productExists)) {
                    $post_id = $productExists;
                    update_post_meta($post_id, '_regular_price', $finalPrice);
                    update_post_meta($post_id, '_sale_price', $finalPrice);
                    update_post_meta($post_id, '_price', $finalPrice);
                }
            }
        }
    }

    public function sync_products($type = "manual") {
        $type = strtolower($type);
        $response = array();
        $response['status'] = FALSE;
        $response['message'] = 'Webservice is temporary unavailable. Please try again.';
        try {
            $duellIntegrationStatus = get_option('duellintegration_integration_status');
            $duellClientNumber = (int) get_option('duellintegration_client_number');
            $duellClientToken = get_option('duellintegration_client_token');
            if ($duellIntegrationStatus == 1 || $duellIntegrationStatus == '1') {
                if ($duellClientNumber <= 0) {
                    $text_error = 'Client number is not setup';
                    write_log('ProductSync() - ' . $text_error);
                    $response['message'] = $text_error;
                    if ($type != 'manual') {
                        //duellMailAlert($text_error, 422);
                    }
                    return $response;
                }
                if (strlen($duellClientToken) <= 0) {
                    $text_error = 'Client token is not setup';
                    write_log('ProductSync() - ' . $text_error);
                    $response['message'] = $text_error;
                    if ($type != 'manual') {
                        //duellMailAlert($text_error, 422);
                    }
                    return $response;
                }
                ini_set('memory_limit', '-1');
                ini_set('max_execution_time', 0);
                ini_set('default_socket_timeout', 500000);
                $lastSyncDate = get_option('duellintegration_product_lastsync');
                $start = 0;
                $limit = $this->duellLimit;
                $apiData = array('client_number' => $duellClientNumber, 'client_token' => $duellClientToken, 'length' => $limit, 'start' => $start);
                if (!is_null($lastSyncDate) && validateDateTime($lastSyncDate, 'Y-m-d H:i:s')) {
                    $apiData['filter[last_update_date]'] = date('Y-m-d H:i:s', strtotime($lastSyncDate));
                }
                $wsdata = callDuell('product/list', 'get', $apiData, 'json', $type);
                if (isset($wsdata['status']) && $wsdata['status'] === true) {
                    $totalRecord = $wsdata['total_count'];
                    if ($totalRecord > 0) {
                        if (isset($wsdata['products']) && !empty($wsdata['products'])) {
                            $allData = $wsdata['products'];
                            $this->processProductData($allData);
                            usleep(500000);
                            $nextCounter = $start + $limit;
                            while ($totalRecord > $limit && $totalRecord > $nextCounter) {
                                $apiData = array('client_number' => $duellClientNumber, 'client_token' => $duellClientToken, 'length' => $limit, 'start' => $nextCounter);
                                if (!is_null($lastSyncDate) && validateDateTime($lastSyncDate, 'Y-m-d H:i:s')) {
                                    $apiData['filter[last_update_date]'] = date('Y-m-d H:i:s', strtotime($lastSyncDate));
                                }
                                $wsdata = callDuell('product/list', 'get', $apiData, 'json', $type);
                                if (isset($wsdata['status']) && $wsdata['status'] === true) {
                                    $totalNRecord = $wsdata['total_count'];
                                    if ($totalNRecord > 0) {
                                        if (isset($wsdata['products']) && !empty($wsdata['products'])) {
                                            $allData = $wsdata['products'];
                                            $this->processProductData($allData);
                                        }
                                    }
                                    $nextCounter = $nextCounter + $limit;
                                }
                                usleep(500000);
                            }
                        }
                        update_option('duellintegration_product_lastsync', date('Y-m-d H:i:s'));
                    }
                    $response['status'] = TRUE;
                    $response['message'] = 'success';
                    return $response;
                } else {
                    $text_error = $wsdata['message'];
                    write_log('ProductSync() - Error:: ' . $text_error);
                    $response['message'] = $text_error;
                }
            } else {
                $text_error = 'Integration status is not active.';
                write_log('ProductSync() - ' . $text_error);
                $response['message'] = $text_error;
                return $response;
            }
        } catch (Exception $e) {
            $text_error = 'Catch exception throw:: ' . $e->getMessage();
            write_log('ProductSync() - ' . $text_error);
            if ($type != 'manual') {
                duellMailAlert($text_error, 422);
            }
        }
        return $response;
    }

    function processProductData($data = array()) {
        $woocommerce_prices_include_tax = get_option('woocommerce_prices_include_tax'); //=yes (inc tax) or no (excl. tax)
        $updateExistingProduct = get_option('duellintegration_update_existing_product');
        if (!empty($data)) {
            wp_defer_term_counting(true);
            wp_defer_comment_counting(true);
            foreach ($data as $product) {
                try {
                    $duellProductId = $product['product_id'];
                    $productNumber = $product['product_number'];
                    $relatedProductId = $product['related_product_id'];
                    $productName = $product['product_name'];
                    $description = $product['description'];
                    $barcode = $product['barcode'];
                    $categoryId = $product['category_id'];
                    $categoryName = $product['category_name'];
                    $vatratePercentage = $product['vatrate_percent'];
                    $costPrice = $product['cost_price'];
                    $priceIncTax = $product['price_inc_vat'];
                    $isDeleted = $product['is_deleted'];
                    $finalPrice = 0;
                    if ($woocommerce_prices_include_tax == 'yes') {
                        $finalPrice = $priceIncTax;
                    } else {
                        $vatrateMultiplier = 1 + ( $vatratePercentage / 100);
                        $priceExTax = $priceIncTax / $vatrateMultiplier;
                        $finalPrice = number_format($priceExTax, 2, '.', '');
                    }
                    $productExists = getWooCommerceProductBySku($productNumber);
                    if (is_null($productExists) || $productExists == '' || $productExists <= 0) {
                        if ($description == '' || is_null($description)) {
                            $description = $productName;
                        }
                        //Create post
                        $post = array(
                            'comment_status' => 'closed',
                            'ping_status' => 'closed',
                            'post_excerpt' => '',
                            'post_author' => 1,
                            'post_content' => $description,
                            'post_status' => "pending",
                            'post_title' => $productName,
                            'post_name' => $productName,
                            'post_parent' => 0,
                            'post_password' => '',
                            'post_type' => "product",
                            'menu_order' => 0
                        );
                        $post_id = wp_insert_post($post, $wp_error = true);
                        write_log("The error is: " . $wp_error);
                    } else {
                        //Update post
                        if ($updateExistingProduct == '1' || $updateExistingProduct == 1) {
                            $post_id = $productExists;
                            $post = array(
                                'ID' => $post_id,
                                'post_title' => $productName,
                                'post_content' => $description
                            );
                            wp_update_post($post);
                        }
                    }
                    if ($post_id) {
                        //$attach_id = get_post_meta($product->parent_id, "_thumbnail_id", true);
                        //add_post_meta($post_id, '_thumbnail_id', $attach_id);
                        // For new product only
                        if (is_null($productExists)) {
                            wp_set_object_terms($post_id, 'simple', 'product_type');
                            update_post_meta($post_id, '_wc_review_count', "0");
                            update_post_meta($post_id, '_wc_rating_count', array());
                            update_post_meta($post_id, '_wc_average_rating', "0");
                            update_post_meta($post_id, '_sku', $productNumber);
                            update_post_meta($post_id, '_sale_price_dates_from', "");
                            update_post_meta($post_id, '_sale_price_dates_to', "");
                            update_post_meta($post_id, 'total_sales', '0');
                            update_post_meta($post_id, '_tax_status', 'taxable');
                            update_post_meta($post_id, '_tax_class', 'standard');
                            update_post_meta($post_id, '_manage_stock', "yes");
                            update_post_meta($post_id, '_stock_status', 'outofstock');
                            update_post_meta($post_id, '_stock', "0");
                            update_post_meta($post_id, '_backorders', "no");
                            update_post_meta($post_id, '_sold_individually', "");
                            update_post_meta($post_id, '_weight', "");
                            update_post_meta($post_id, '_length', "");
                            update_post_meta($post_id, '_width', "");
                            update_post_meta($post_id, '_height', "");
                            update_post_meta($post_id, '_upsell_ids', array());
                            update_post_meta($post_id, '_crosssell_ids', array());
                            update_post_meta($post_id, '_purchase_note', "");
                            update_post_meta($post_id, '_default_attributes', array());
                            update_post_meta($post_id, '_product_attributes', array());
                            update_post_meta($post_id, '_virtual', 'no');
                            update_post_meta($post_id, '_downloadable', 'no');
                            update_post_meta($post_id, '_visibility', 'visible');
                            update_post_meta($post_id, '_featured', "no");
                            update_post_meta($post_id, '_duell_product_id', $duellProductId);
                        }
                        if (is_null($productExists) || ($updateExistingProduct == '1' || $updateExistingProduct == 1)) {
                            wp_set_object_terms($post_id, $categoryName, 'product_cat');
                            update_post_meta($post_id, '_barcode', $barcode);
                            update_post_meta($post_id, '_regular_price', $finalPrice);
                            update_post_meta($post_id, '_sale_price', $finalPrice);
                            update_post_meta($post_id, '_price', $finalPrice);
                        }
                    }
                } catch (Exception $e) {
                    $text_error = 'Product Catch exception throw:: ' . $e->getMessage();
                    write_log('ProductSync() - ' . $text_error);
                }
            }
            wp_defer_term_counting(false);
            wp_defer_comment_counting(false);
        }
    }

    /**
     * Create cost/purchase price field for simple product
     */
    function wc_add_product_cost_price_field() {
        $currency = get_woocommerce_currency_symbol();
        woocommerce_wp_text_input(
            array(
                'id' => '_cost_price',
                'class' => '',
                'wrapper_class' => 'pricing show_if_simple show_if_external',
                'label' => __("Cost price", 'products-cost-price-for-woocommerce') . " ($currency)",
                'data_type' => 'price',
                'desc_tip' => true,
                'description' => __('This is the buying-in price of the product.', 'products-cost-price-for-woocommerce'),
            )
        );
    }

    function wc_save_product_cost_price_field($post_id, $post) {
        if (isset($_POST['_cost_price'])) {
            $cost_price = ($_POST['_cost_price'] === '' ) ? '' : wc_format_decimal($_POST['_cost_price']);
            update_post_meta($post_id, '_cost_price', $cost_price);
        }
    }

    /**
     * Create cost/purchase price field for variation products
     */
    function wc_add_variable_product_cost_price_field($loop, $variation_data, $variation) {
        $currency = get_woocommerce_currency_symbol();
        woocommerce_wp_text_input(array(
            'id' => 'variable_cost_price[' . $loop . ']',
            'wrapper_class' => 'form-row form-row-first',
            'label' => __("Cost price", 'products-cost-price-for-woocommerce') . " ($currency)",
            'placeholder' => '',
            'data_type' => 'price',
            'desc_tip' => false,
            'value' => get_post_meta($variation->ID, '_cost_price', true)
        ));
    }

    function wc_save_variable_product_cost_price_field($variation_id, $i) {
        if (isset($_POST['variable_cost_price'][$i])) {
            $cost_price = ($_POST['variable_cost_price'][$i] === '' ) ? '' : wc_format_decimal($_POST['variable_cost_price'][$i]);
            update_post_meta($variation_id, '_cost_price', $cost_price);
        }
    }

}

new Duellintegration();