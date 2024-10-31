<?php
/**
 * Plugin Name
 *
 * @package     PmproVoguepay
 * @author      kunlexzy
 *
 * @wordpress-plugin
 * Plugin Name: VoguePay plugin for Paid Memberships Pro
 * Plugin URI:  https://wordpress.org/plugins/pmpro-voguepay/
 * Description: Plugin to add Voguepay payment gateway into Paid Memberships Pro
 * Version:     1.0.0
 * Author:      kunlexzy
 * Author URI:  https://voguepay.com/3445-0056682
 * Text Domain: pmpro-voguepay
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

defined('ABSPATH') or die();

if (!function_exists('class_pmpro_voguepay')) {
    add_action('plugins_loaded', 'class_pmpro_voguepay', 20);

    function class_pmpro_voguepay()
    {
        // paid memberships pro required
        if (!class_exists('PMProGateway')) {
            return;
        }

        // load classes init method
        add_action('init', array('PMProGateway_Voguepay', 'init'));

        // plugin links
        add_filter('plugin_action_links', array('PMProGateway_Voguepay', 'plugin_action_links'), 10, 2);

        if (!class_exists('PMProGateway_Voguepay')) {

            class PMProGateway_Voguepay extends PMProGateway
            {

                function __construct($gateway = null)
                {

                    $this->url='https://voguepay.com/';
                    $this->gateway = $gateway;
                    $this->gateway_environment =  pmpro_getOption("gateway_environment");

                    return $this->gateway;
                }

                /**
                 * Run on WP init
                 */
                static function init()
                {

                    //Add voguepay to payment option
                    add_filter('pmpro_gateways', array('PMProGateway_Voguepay', 'pmpro_gateways'));

                    //add fields to payment settings
                    add_filter('pmpro_payment_options', array('PMProGateway_Voguepay', 'pmpro_payment_options'));
                    add_filter('pmpro_payment_option_fields', array('PMProGateway_Voguepay', 'pmpro_payment_option_fields'), 10, 2);
                    add_action('wp_ajax_pmpro_voguepay_ipn', array('PMProGateway_Voguepay', 'pmpro_voguepay_ipn'));
                    add_action('wp_ajax_nopriv_pmpro_voguepay_ipn', array('PMProGateway_Voguepay', 'pmpro_voguepay_ipn'));

                    //code to add at checkout
                    $gateway = pmpro_getGateway();


                    if ($gateway == "voguepay") {

                        add_filter('pmpro_include_billing_address_fields', '__return_false');
                        add_filter('pmpro_include_payment_information_fields', '__return_false');
                        add_filter('pmpro_required_billing_fields', array('PMProGateway_Voguepay', 'pmpro_required_billing_fields'));
                        add_filter('pmpro_checkout_default_submit_button', array('PMProGateway_Voguepay', 'pmpro_checkout_default_submit_button'));
                        add_filter('pmpro_checkout_before_change_membership_level', array('PMProGateway_Voguepay', 'pmpro_checkout_before_change_membership_level'), 10, 2);

                        add_filter('pmpro_pages_shortcode_checkout', array('PMProGateway_Voguepay', 'pmpro_pages_shortcode_checkout'), 20, 1);
                        add_filter('pmpro_pages_shortcode_confirmation', array('PMProGateway_Voguepay', 'pmpro_pages_shortcode_confirmation'), 20, 1);
                    }
                }

                /**
                 * Redirect Settings to PMPro settings
                 */
                static function plugin_action_links($links, $file)
                {
                    static $this_plugin;

                    if (false === isset($this_plugin) || true === empty($this_plugin)) {
                        $this_plugin = plugin_basename(__FILE__);
                    }

                    if ($file == $this_plugin) {
                        $settings_link = '<a href="'.admin_url('admin.php?page=pmpro-paymentsettings').'">'.__('Settings', 'paid-memberships-pro').'</a>';
                        array_unshift($links, $settings_link);
                    }

                    return $links;
                }

                static function pmpro_checkout_default_submit_button($show)
                {  ?>
                    <span id="pmpro_submit_span">
                    <input type="hidden" name="submit-checkout" value="1" />
                    <input type="submit" class="pmpro_btn pmpro_btn-submit-checkout" value="<?php  _e('Check Out with Voguepay', 'paid-memberships-pro'); ?> &raquo;" />
                    </span>
                    <?php

                    //don't show the default
                    return false;
                }
                /**
                 * add to gateways list
                 */
                static function pmpro_gateways($gateways)
                {
                    if (empty($gateways['voguepay'])) {
                        $gateways = array_slice($gateways, 0, 1) + array("voguepay" => __('Voguepay', 'paid-memberships-pro')) + array_slice($gateways, 1);
                    }
                    return $gateways;
                }

                function pmpro_voguepay_ipn()
                {
                    global $wpdb;
                    if ((strtoupper($_SERVER['REQUEST_METHOD']) != 'POST' )) {
                        exit('REJECTED');
                    }

                    if(isset($_POST['transaction_id'])){
                        $transaction_id=sanitize_text_field($_POST['transaction_id']);

                        if(empty(trim($transaction_id))) return;


                        $args = array( 'timeout' => 60 );


                        $mode = pmpro_getOption("gateway_environment");

                        if ($mode == 'sandbox') {
                            $json = wp_remote_get( 'https://voguepay.com/?v_transaction_id='.$transaction_id.'&type=json&demo=true', $args );
                        } else {
                            $json = wp_remote_get( 'https://voguepay.com/?v_transaction_id='.$transaction_id.'&type=json', $args );

                        }

                        $transaction 	= json_decode( $json['body'], true );

                        foreach($transaction as $key =>$val) $transaction[$key]=sanitize_text_field($val);

                        $ref_split = explode('##', $transaction['merchant_ref'] );


                        $morder =  new MemberOrder($ref_split[0]);
                        $morder->getMembershipLevel();
                        $morder->getUser();

                        $transaction_id = $transaction['transaction_id'];
                        $status = $transaction['status'];
                        $ref_split 		= explode('##', $transaction['merchant_ref'] );

                        $order_id 		= $ref_split[0];
                        $amount_paid_currency 	= $ref_split[1];
                        $amount_paid 	= $ref_split[2];


                        if (empty($pmpro_invoice)) {
                            $morder =  new MemberOrder($order_id);
                            if (!empty($morder) && $morder->gateway == "voguepay") $pmpro_invoice = $morder;
                        }

                        if (!empty($pmpro_invoice) && $pmpro_invoice->gateway == "voguepay" && isset($pmpro_invoice->total) && $pmpro_invoice->total > 0) {
                            $morder = $pmpro_invoice;

                            if ($morder->code == $order_id) {
                                $pmpro_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = '" . (int)$morder->membership_id . "' LIMIT 1");
                                $pmpro_level = apply_filters("pmpro_checkout_level", $pmpro_level);
                                $startdate = apply_filters("pmpro_checkout_start_date", "'" . current_time("mysql") . "'", $morder->user_id, $pmpro_level);

                                if (strlen($morder->subscription_transaction_id) > 3) {
                                    $enddate = "'" . date("Y-m-d", strtotime("+ " . $morder->subscription_transaction_id, current_time("timestamp"))) . "'";
                                } elseif (!empty($pmpro_level->expiration_number)) {
                                    $enddate = "'" . date("Y-m-d", strtotime("+ " . $pmpro_level->expiration_number . " " . $pmpro_level->expiration_period, current_time("timestamp"))) . "'";
                                } else {
                                    $enddate = "NULL";
                                }



                                if ('Approved' == $status && $pmpro_level->initial_payment ==  $amount_paid) {

                                    $custom_level = array(
                                        'user_id'           => $morder->user_id,
                                        'membership_id'     => $pmpro_level->id,
                                        'code_id'           => '',
                                        'initial_payment'   => $pmpro_level->initial_payment,
                                        'billing_amount'    => $pmpro_level->billing_amount,
                                        'cycle_number'      => $pmpro_level->cycle_number,
                                        'cycle_period'      => $pmpro_level->cycle_period,
                                        'billing_limit'     => $pmpro_level->billing_limit,
                                        'trial_amount'      => $pmpro_level->trial_amount,
                                        'trial_limit'       => $pmpro_level->trial_limit,
                                        'startdate'         => $startdate,
                                        'enddate'           => $enddate
                                    );

                                    if ($morder->status != 'Approved')
                                    {

                                        if (pmpro_changeMembershipLevel($custom_level, $morder->user_id, 'changed')) {
                                            $morder->membership_id = $pmpro_level->id;
                                            $morder->payment_transaction_id = $transaction_id;
                                            $morder->status = $status;
                                            $morder->saveOrder();

                                            $user=get_userdata($morder->user_id);
                                            $user->membership_level = pmpro_getMembershipLevelForUser($user->ID);

                                            //send email to member
                                            $pmproemail = new PMProEmail();
                                            $pmproemail->sendCheckoutEmail($user, $morder);

                                            //send email to admin
                                            $pmproemail = new PMProEmail();
                                            $pmproemail->sendCheckoutAdminEmail($user, $morder);

                                        }
                                        else  exit('Operation Failed');

                                    } else exit('Already Processed');


                                } else {
                                    $morder->membership_id = $pmpro_level->id;
                                    $morder->payment_transaction_id = $transaction_id;
                                    $morder->status = $status;
                                    $morder->saveOrder();
                                }

                            } else {
                                exit('Unable to Verify Transaction');

                            }

                        } else {
                            exit('Invalid Transaction Reference');
                        }


                    }

                    http_response_code(200);
                    exit('OK');
                }

                /**
                 * Get a list of payment options.
                 */
                static function getGatewayOptions()
                {
                    $options = array (
                        'voguepay_merchant_id',
                        'voguepay_store',
                        'currency',
                        'tax_state',
                        'tax_rate'
                    );

                    return $options;
                }

                /**
                 * Set payment options for payment settings page.
                 */
                static function pmpro_payment_options($options)
                {
                    //get Voguepay options
                    $voguepay_options = self::getGatewayOptions();

                    //merge with others.
                    $options = array_merge($voguepay_options, $options);

                    return $options;
                }

                /**
                 * Display fields options.
                 */
                static function pmpro_payment_option_fields($values, $gateway)
                {
                    ?>
                    <tr class="pmpro_settings_divider gateway gateway_voguepay" <?php if($gateway != "voguepay") { ?>style="display: none;"<?php } ?>>
                        <td colspan="2">
                            <?php _e('Voguepay Settings', 'paid-memberships-pro'); ?>
                        </td>
                    </tr>

                    <tr class="gateway gateway_voguepay" <?php if($gateway != "voguepay") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label for="voguepay_merchant_id"><?php _e('Merchant ID', 'paid-memberships-pro');?>:</label>
                        </th>
                        <td>
                            <input type="text" id="voguepay_merchant_id" name="voguepay_merchant_id" size="60" value="<?php echo esc_attr($values['voguepay_merchant_id'])?>" />
                        </td>
                    </tr>


                    <tr class="gateway gateway_voguepay" <?php if($gateway != "voguepay") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label for="voguepay_store"><?php _e('Store ID (Optional)', 'paid-memberships-pro');?>:</label>
                        </th>
                        <td>
                            <input type="text" id="voguepay_store" name="voguepay_store" size="60" value="<?php echo esc_attr($values['voguepay_store'])?>" />
                        </td>
                    </tr>


                    <?php
                }

                /**
                 * Remove required billing fields
                 */
                static function pmpro_required_billing_fields($fields)
                {
                    unset($fields['bfirstname']);
                    unset($fields['blastname']);
                    unset($fields['baddress1']);
                    unset($fields['bcity']);
                    unset($fields['bstate']);
                    unset($fields['bzipcode']);
                    unset($fields['bphone']);
                    unset($fields['bemail']);
                    unset($fields['bcountry']);
                    unset($fields['CardType']);
                    unset($fields['AccountNumber']);
                    unset($fields['ExpirationMonth']);
                    unset($fields['ExpirationYear']);
                    unset($fields['CVV']);

                    return $fields;
                }

                /**
                 * Send users to payment page.
                 */
                static function pmpro_checkout_before_change_membership_level($user_id, $morder)
                {

                    global $wpdb, $discount_code_id;

                    //if no order, no need to pay
                    if (empty($morder)) {
                        return;
                    }
                    if (empty($morder->code))
                        $morder->code = $morder->getRandomCode();

                    $morder->payment_type = "Voguepay";
                    $morder->status = "pending";
                    $morder->user_id = $user_id;
                    $morder->saveOrder();

                    //save discount code use
                    if (!empty($discount_code_id))
                        $wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('" . $discount_code_id . "', '" . $user_id . "', '" . $morder->id . "', now())");

                    $morder->Gateway->sendToVoguepay($morder);
                }

                function sendToVoguepay(&$order)
                {
                    global $wp;

                    $params = array();
                    $amount = $order->InitialPayment;
                    $amount_tax = $order->getTaxForPrice($amount);
                    $amount = round((float)$amount + (float)$amount_tax, 2);

                    $mode = pmpro_getOption("gateway_environment");
                    $merchant_id = ($mode!='live')? 'demo' : pmpro_getOption("voguepay_merchant_id");
                    $currency = pmpro_getOption("currency");
                    $store_id= pmpro_getOption("voguepay_store");

                    $params=[
                        'v_merchant_id'=>$merchant_id,
                        'merchant_ref'=>$order->code.'##'.$currency.'##'.$amount,
                        'cur'=>$currency,
                        'memo'=>'Order: '.$order->membership_level->name . " at " . get_bloginfo("name"),
                        'total'=>$amount,
                        'email'=>$order->Email,
                        'success_url'=>pmpro_url("confirmation", "?level=" . $order->membership_level->id.'&order='.$order->code),
                        'fail_url'=>pmpro_url("confirmation", "?level=" . $order->membership_level->id.'&order='.$order->code),
                        'notify_url'=>admin_url("admin-ajax.php") . "?action=pmpro_voguepay_ipn"
                    ];

                    if(!empty($store_id)) $params['store_id']=$store_id;
                    $params['developer_code']='5b6f5e46c65a4';


                    if ($merchant_id  == '') {
                        echo "Merchant ID not set";
                    }

                    $pay_url=$this->get_payment_link($params);

                    if($pay_url===0){
                        $order->Gateway->delete($order);
                        wp_redirect(pmpro_url("checkout", "?level=" . $order->membership_level->id . "&error=Failed"));
                        exit();
                    }

                    //Redirect to checout page
                    wp_redirect($pay_url);

                    exit;
                }


                /**
                 * Get Voguepay payment link
                 **/
                function get_payment_link( $voguepay_args ) {

                    $voguepay_redirect  = $this->url.'?p=linkToken&';
                    $voguepay_redirect .= http_build_query( $voguepay_args );

                    $args = array(
                        'timeout'   => 60
                    );

                    $request = wp_remote_get( $voguepay_redirect, $args );

                    if(isset($request['body'])) {
                        $valid_url = strpos($request['body'], $this->url . 'pay');
                    }
                    else $valid_url=false;

                    if ( ! is_wp_error( $request ) && 200 == wp_remote_retrieve_response_code( $request ) && $valid_url !== false ) return $request['body'];

                    return 0;
                }



                static function pmpro_pages_shortcode_checkout($content)
                {
                    $morder = new MemberOrder();
                    $found = $morder->getLastMemberOrder(get_current_user_id(), apply_filters("pmpro_confirmation_order_status", array("pending")));
                    if ($found) {
                        $morder->Gateway->delete($morder);
                    }

                    if (isset($_REQUEST['error'])) {
                        global $pmpro_msg, $pmpro_msgt;

                        $pmpro_msg = __("An error occurred while contacting the gateway, kindly try again", "pmpro");
                        $pmpro_msgt = "pmpro_error";

                        $content = "<div id='pmpro_message' class='pmpro_message ". $pmpro_msgt . "'>" . $pmpro_msg . "</div>" . $content;
                    }

                    return $content;
                }

                /**
                 * Custom confirmation page
                 */
                static function pmpro_pages_shortcode_confirmation()
                {

                    global $wpdb, $current_user, $pmpro_invoice, $pmpro_currency,$gateway;

                    if(isset($_GET['order']))
                    {

                        $order_id=sanitize_text_field($_GET['order']);

                        if(empty(trim($order_id))) return;

                        $pmpro_invoice = new MemberOrder($order_id);

                        if(empty($pmpro_invoice)) return;


                        if($pmpro_invoice->status=='Approved') {

                            $current_user = get_userdata($pmpro_invoice->user_id);
                            if(empty($current_user))
                                return false;

                            $pmpro_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = '" . (int)$pmpro_invoice->membership_id . "' LIMIT 1");
                            $pmpro_level = apply_filters("pmpro_checkout_level", $pmpro_level);


                            $current_user->membership_level =$pmpro_level;		//make sure they have the right level info


                        }

                        ob_start();


                        if($pmpro_invoice->status=='pending') {
                            echo __('Your order has not been confirmed', 'paid-memberships-pro');
                        }

                        if($pmpro_invoice->status!='Approved' && $pmpro_invoice->status!='pending') {
                            echo __('Your payment was not successful', 'paid-memberships-pro');
                        }


                        $content="<ul><li><strong>".__('Order', 'paid-memberships-pro').":</strong> ".$pmpro_invoice->code."</li>
                            <li><strong>".__('Amount', 'paid-memberships-pro').":</strong> ".$pmpro_invoice->total." ".$pmpro_currency."</li>
                             <li><strong>".__('Payment ID', 'paid-memberships-pro').":</strong> ".$pmpro_invoice->payment_transaction_id."</li>
                              <li><strong>".__('Payment Status', 'paid-memberships-pro').":</strong> ".$pmpro_invoice->status."</li>
                        </ul>";

                        if($pmpro_invoice->status=='Approved') {

                            if (file_exists(get_stylesheet_directory() . "/paid-memberships-pro/pages/confirmation.php")) {
                                include get_stylesheet_directory() . "/paid-memberships-pro/pages/confirmation.php";
                            } else {
                                include PMPRO_DIR . "/pages/confirmation.php";
                            }

                        }



                        $content= ob_get_contents().$content;

                        ob_end_clean();

                        echo $content;
                    }



                }

                function cancel(&$order)
                {
                    //require a subscription id
                    if(empty($order->subscription_transaction_id))
                        return false;

                    $order->updateStatus("cancelled");
                    return true;
                }

                function delete(&$order)
                {
                    $order->updateStatus("cancelled");
                    global $wpdb;
                    $wpdb->query("DELETE FROM $wpdb->pmpro_membership_orders WHERE id = '" . $order->id . "'");
                }
            }

        }
    }
}
?>