<?php
/**
 * Smart Send shipping
 *
 * @package    Jigoshop
 * @category   Checkout
 * @author     sp4cecat
 * @copyright  Copyright (c) 2012 sp4cecat
 * @license    http://codecanyon.net
 */

function add_smart_send_method( $methods )
{
    $methods[] = 'smart_send';
    return $methods;
}

add_filter('jigoshop_shipping_methods', 'add_smart_send_method' );

class smart_send extends jigoshop_shipping_method {

    protected $smartSendUtils;

    public function __construct()
    {
        if( !$this->id ) $this->id = 'smart_send';
        if( !$this->title ) $this->title = get_option('jigoshop_smart_send_title');
        $this->enabled      = get_option('jigoshop_smart_send_enabled');
        $this->tax_status	= get_option('jigoshop_smart_send_tax_status');
        $this->fee 			= get_option('jigoshop_smart_send_handling_fee');

        add_action('jigoshop_update_options', array( &$this, 'process_admin_options') );

        add_option('jigoshop_smart_send_title', 'Smart Send');
        add_option('jigoshop_smart_send_tax_status', 'taxable');
        add_option('jigoshop_smart_send_calculation_method', 'combined');

        add_option('jigoshop_smart_send_vipusername', '');
        add_option('jigoshop_smart_send_vippassword', '');

        if ( isset( jigoshop_session::instance()->chosen_shipping_method_id )
            && jigoshop_session::instance()->chosen_shipping_method_id == $this->id ) {
            
            $this->chosen = true;
            
        }
    }

    // To be available, shipping city and postcode must be provided or set
    // If provided, they will be:
    //  shipping-city / billing-city
    //  shipping-postcode / billing-postcode
    public function is_available()
    {
        list( $shippingToPostcode, $shippingToTown ) = $this->smartSendGetShipTo();

        if( empty($shippingToTown) || empty($shippingToPostcode ) )
            {
                // ob_start();
                // print_r( $_POST );
                // $str .= ob_get_contents();
                // ob_end_clean();
                // jigoshop::add_error( "<pre>$str</pre> TO: $shippingToTown $shippingToPostcode" );
                // exit("<pre>$str</pre> TO: $shippingToTown $shippingToPostcode");
                // jigoshop::add_error( "Smart Send not available" );
                return false;
            }
        else return true;
    }

    public function calculate_shipping() {

        $_tax = &new jigoshop_tax();

        $this->shipping_total 	= 0;
        $this->shipping_tax 	= 0;

        list( $shippingToPostcode, $shippingToTown ) = $this->smartSendGetShipTo();

        $shippingOriginPostcode = get_option( 'jigoshop_smart_send_origin_postcode');
        $shippingOriginTown     = get_option( 'jigoshop_smart_send_origin_town');

        $vipUsername = get_option('jigoshop_smart_send_vipusername');
        $vipPassword = get_option('jigoshop_smart_send_vippassword');

        if( sizeof( jigoshop_cart::$cart_contents ) > 0 && !empty($shippingOriginTown) && !empty($shippingOriginPostcode) )
        {
            $itemList = array();

            foreach( jigoshop_cart::$cart_contents as $item_id => $values )
            {
                $_product = $values['data'];

                if ($_product->exists() && $values['quantity'] > 0 && $_product->product_type <> 'downloadable' )
                {
                    foreach( range( 1, $values['quantity'] ) as $blah ) { // Loop through quantity of each product
                        $shipping_error = false;
                        $weight = $_product->get_weight();
                        $length = $_product->get_length();
                        $height = $_product->get_height();
                        $width = $_product->get_width();

                        if( $length <= 0 )
                        {
                            jigoshop::add_error(
                                'Shipping Calculation Error: No <b>length</b> set for product <a href="' . get_permalink( $values['product_id'] ) . '">' . apply_filters( 'jigoshop_cart_product_title', $_product->get_title(), $_product).'</a>');
                            $shipping_error = true;
                        }
                        if( $height <= 0 )
                        {
                            jigoshop::add_error(
                                'Shipping Calculation Error: No <b>height</b> set for product <a href="' . get_permalink( $values['product_id'] ) . '">' . apply_filters( 'jigoshop_cart_product_title', $_product->get_title(), $_product).'</a>');
                            $shipping_error = true;
                        }
                        if( $width <= 0 )
                        {
                            jigoshop::add_error(
                                'Shipping Calculation Error: No <b>width</b> set for product <a href="' . get_permalink( $values['product_id'] ) . '">' . apply_filters( 'jigoshop_cart_product_title', $_product->get_title(), $_product).'</a>');
                            $shipping_error = true;
                        }

                        if( $shipping_error ) continue;

                        $itemList[] = array(
                            'Description' => 'Carton',
                            'Weight' => $weight,
                            'Depth' => $width,
                            'Length' => $length,
                            'Height' => $height
                        );
                    } // End loop through item count
                }
            } // End listing through cart items

            if( isset($itemList) && count($itemList) )
            {
                $smartSendQuote = new smartSendUtils( $vipUsername, $vipPassword );

                $shippingOriginState = $smartSendQuote->getState( $shippingOriginPostcode, $shippingOriginTown );
                $shippingToState = $smartSendQuote->getState( $shippingToPostcode, $shippingToTown );

                $smartSendQuote->setFrom(
                    array( $shippingOriginPostcode, $shippingOriginTown, $shippingOriginState )
                );

                $smartSendQuote->setTo(
                    array( $shippingToPostcode, $shippingToTown, $shippingToState )
                );

                foreach( $itemList as $item )  $smartSendQuote->addItem( $item );

                $quoteResult = $smartSendQuote->getQuote();

                $quotes = $quoteResult->ObtainQuoteResult->Quotes->Quote;

                if( is_array( $quotes ) ) $quotes = $quotes[0];

                $this->shipping_total += $quotes->TotalPrice;
                $this->title = $this->title .' '.$quotes->TransitDescription;
            }
        }

    }

    public function admin_options() {
    ?>

    <thead><tr><th scope="col" colspan="2"><h3 class="title"><?php _e('Smart Send', 'jigoshop'); ?></h3>
        <p><?php _e('Smart Send Australian online courier specialists.', 'jigoshop'); ?>&nbsp;<br/>
        <small><?php _e('Note: all products need a width/height(depth)/length and weight for calculations to work.', 'jigoshop'); ?></small></p></th></tr></thead>

    <tr>
        <td class="titledesc"></td>
        <td class="forminp">
            <input type="hidden" name="jigoshop_smart_send_enabled" value="no">
            <input type="checkbox" name="jigoshop_smart_send_enabled" id="jigoshop_smart_send_enabled" value="yes"<?php if (get_option('jigoshop_smart_send_enabled') == 'yes') echo ' checked="checked"'; ?>> <label for="jigoshop_smart_send_enabled"><?php _e('Enable Smart Send', 'jigoshop') ?></label>
        </td>
    </tr>

    <?php if( !get_option('jigoshop_smart_send_vipusername') || !get_option('jigoshop_smart_send_vippassword'))
    {
        ?>
    <tr>
        <td colspan="3" style="color: red">Note: You must have a Smart Send VIP account to use this plugin. Visit <a href="https://www.smartsend.com.au/vipClientEnquiry.cfm" target="_blank">Smart Send</a> to register.</td>
    </tr>
        <?php
    } ?>

    <tr>
        <td class="titledesc"><a href="#" tip="<?php _e('VIP username issued by Smart Send.','jigoshop') ?>" class="tips" tabindex="99"></a><?php _e('VIP Username', 'jigoshop') ?>:</td>
        <td class="forminp">
            <input type="text" name="jigoshop_smart_send_vipusername" id="jigoshop_smart_send_vipusername" style="min-width:70px;" value="<?php if ($value = get_option('jigoshop_smart_send_vipusername')) echo $value; ?>" />
        </td>
    </tr>

    <tr>
        <td class="titledesc"><a href="#" tip="<?php _e('VIP password issued by Smart Send.','jigoshop') ?>" class="tips" tabindex="99"></a><?php _e('VIP Password', 'jigoshop') ?>:</td>
        <td class="forminp">
            <input type="text" name="jigoshop_smart_send_vippassword" id="jigoshop_smart_send_vippassword" style="min-width:70px;" value="<?php if ($value = get_option('jigoshop_smart_send_vippassword')) echo $value; ?>" />
        </td>
    </tr>
    <tr>
        <td class="titledesc"><?php _e('Shipping Origin Postcode:', 'jigoshop') ?></td>
        <td class="forminp">
             <input type="text" name="jigoshop_smart_send_origin_postcode" id="jigoshop_smart_send_origin_postcode" style="width: 50px;" value="<?php echo get_option('jigoshop_smart_send_origin_postcode'); ?>" />
         </td>
    </tr>

    <tr>
        <td class="titledesc"><?php _e('Shipping Origin Town:', 'jigoshop') ?></td>
        <td class="forminp">
            <input type="text" name="jigoshop_smart_send_origin_town" id="jigoshop_smart_send_origin_town" style="min-width: 50px;" value="<?php echo get_option('jigoshop_smart_send_origin_town'); ?>" /></td>
        </td>
    </tr>
    <tr>
        <td class="titledesc"><a href="#" tip="<?php _e('The title that the user sees during checkout.','jigoshop') ?>" class="tips" tabindex="99"></a><?php _e('Method Title', 'jigoshop') ?>:</td>
        <td class="forminp">
            <input type="text" name="jigoshop_smart_send_title" id="jigoshop_smart_send_title" style="min-width:50px;" value="<?php if ($value = get_option('jigoshop_smart_send_title')) echo $value; else echo 'Smart Send'; ?>" />
        </td>
    </tr>
    <?php /* Individual v. combined - just do combined for now
    <tr>
        <td class="titledesc"><a href="#" tip="<?php _e('Individual - each item quoted individually<br>Combined - All items quoted as a shipment.','jigoshop') ?>" class="tips" tabindex="99"></a><?php _e('Calculation Method', 'jigoshop') ?>:</td>
        <td class="forminp">
            <select name="jigoshop_smart_send_calculation_method" id="jigoshop_smart_send_calculation_method" style="min-width:100px;">
                <option value="combined" <?php if (get_option('jigoshop_smart_send_calculation_method') == 'combined') echo 'selected="selected"'; ?>><?php _e('Combined', 'jigoshop'); ?></option>
                <option value="individual" <?php if (get_option('jigoshop_smart_send_calculation_method') == 'individual') echo 'selected="selected"'; ?>><?php _e('Individual', 'jigoshop'); ?></option>
            </select>
        </td>
    </tr>*/ ?>
    <?php $_tax = new jigoshop_tax(); ?>
    <tr>
        <td class="titledesc"><?php _e('Tax Status', 'jigoshop') ?>:</td>
        <td class="forminp">
            <select name="jigoshop_smart_send_tax_status">
                <option value="taxable" <?php if (get_option('jigoshop_smart_send_tax_status')=='taxable') echo 'selected="selected"'; ?>><?php _e('Taxable', 'jigoshop'); ?></option>
                <option value="none" <?php if (get_option('jigoshop_smart_send_tax_status')=='none') echo 'selected="selected"'; ?>><?php _e('None', 'jigoshop'); ?></option>
            </select>
        </td>
    </tr>
    <tr>
        <td class="titledesc"><a href="#" tip="<?php _e('Handling fee excluding tax. Enter an amount, e.g. 2.50, or a percentage, e.g. 5%. Leave blank to disable.', 'jigoshop') ?>" class="tips" tabindex="99"></a><?php _e('Handling Fee', 'jigoshop') ?>:</td>
        <td class="forminp">
            <input type="text" name="jigoshop_smart_send_handling_fee" id="jigoshop_smart_send_handling_fee" style="min-width:50px;" value="<?php if ($value = get_option('jigoshop_smart_send_handling_fee')) echo $value; ?>" />
        </td>
    </tr>
    <?php
    }

    public function process_admin_options() {

        if(isset($_POST['jigoshop_smart_send_tax_status'])) update_option('jigoshop_smart_send_tax_status', jigowatt_clean($_POST['jigoshop_smart_send_tax_status'])); else @delete_option('jigoshop_smart_send_tax_status');

        if(isset($_POST['jigoshop_smart_send_enabled'])) update_option('jigoshop_smart_send_enabled', jigowatt_clean($_POST['jigoshop_smart_send_enabled'])); else @delete_option('jigoshop_smart_send_enabled');

        if(isset($_POST['jigoshop_smart_send_vipusername'])) update_option('jigoshop_smart_send_vipusername', jigowatt_clean($_POST['jigoshop_smart_send_vipusername'])); else @delete_option('jigoshop_smart_send_vipusername');

        if(isset($_POST['jigoshop_smart_send_vippassword'])) update_option('jigoshop_smart_send_vippassword', jigowatt_clean($_POST['jigoshop_smart_send_vippassword'])); else @delete_option('jigoshop_smart_send_vippasswordd');

        if(isset($_POST['jigoshop_smart_send_calculation_method'])) update_option('jigoshop_smart_send_calculation_method', jigowatt_clean($_POST['jigoshop_smart_send_calculation_method'])); else @delete_option('jigoshop_smart_send_calculation_method');

        if(isset($_POST['jigoshop_smart_send_title'])) update_option('jigoshop_smart_send_title', jigowatt_clean($_POST['jigoshop_smart_send_title'])); else @delete_option('jigoshop_smart_send_title');

        if(isset($_POST['jigoshop_smart_send_handling_fee'])) update_option('jigoshop_smart_send_handling_fee', jigowatt_clean($_POST['jigoshop_smart_send_handling_fee'])); else @delete_option('jigoshop_smart_send_handling_fee');

        if(isset($_POST['jigoshop_smart_send_origin_postcode'])) update_option('jigoshop_smart_send_origin_postcode', jigowatt_clean($_POST['jigoshop_smart_send_origin_postcode'])); else @delete_option('jigoshop_smart_send_origin_postcode');

        if(isset($_POST['jigoshop_smart_send_origin_town'])) update_option('jigoshop_smart_send_origin_town', jigowatt_clean($_POST['jigoshop_smart_send_origin_town'])); else @delete_option('jigoshop_smart_send_origin_town');
    }

    public function smartSendGetShipTo()
    {
        if( isset( $_POST['post_data'] ) )
        {
            foreach( explode( '&', $_POST['post_data']) as $var )
            {
                list( $k, $v ) = explode( '=', $var );
                $postData[$k] = $v;
            }
            
            if($postData['shipping-postcode'] ) $shippingToPostcode = $postData['shipping-postcode'];
            else if(isset($postData['billing-postcode']) ) $shippingToPostcode = $postData['billing-postcode'];
            
            if($postData['shipping-city'] ) $shippingToTown = $postData['shipping-city'];
            else if(isset($postData['billing-city']) ) $shippingToTown = $postData['billing-city'];
        }
        else
        {
            if( isset($_POST['shipping-postcode']) && $_POST['shipping-postcode'] != '')
            {
                $shippingToPostcode = $_POST['shipping-postcode'];
                update_user_meta( get_current_user_id(), 'shipping-postcode', $shippingToPostcode );
                jigoshop_customer::set_shipping_postcode($shippingToPostcode);
            }
            else if( isset($_POST['billing-postcode']) && $_POST['billing-postcode'] != '')
            {
                $shippingToPostcode = $_POST['billing-postcode'];
                update_user_meta( get_current_user_id(), 'billing-postcode', $shippingToPostcode );
                update_user_meta( get_current_user_id(), 'shipping-postcode', $shippingToPostcode );
                jigoshop_customer::set_shipping_postcode($shippingToPostcode);
            }
            if( isset($_POST['shipping-city']) && $_POST['shipping-city'] != '' )
            {
                $shippingToTown = $_POST['shipping-city'];
                update_user_meta( get_current_user_id(), 'shipping-city', $shippingToTown );
            }
            else if( isset($_POST['billing-city']) && $_POST['billing-city'] != '' )
            {
                $shippingToTown = $_POST['billing-city'];
                update_user_meta( get_current_user_id(), 'billing-city', $shippingToTown );
                update_user_meta( get_current_user_id(), 'shipping-city', $shippingToTown );
            }
        }

        $shippingToPostcode = jigoshop_customer::get_shipping_postcode();
        if( !isset($shippingToTown) ) $shippingToTown = get_user_meta( get_current_user_id(), 'shipping-city' );

        return array( $shippingToPostcode, $shippingToTown );
    }

}


/**
 * Jigoshop Shipping Calculator
 **/
if (!function_exists('jigoshop_shipping_calculator') && get_option('jigoshop_smart_send_enabled') == 'yes') {
    function jigoshop_shipping_calculator() {
        if (jigoshop_shipping::is_enabled() && get_option('jigoshop_enable_shipping_calc')=='yes' && jigoshop_cart::needs_shipping()) :
            ?>

        <style type="text/css">
            .cart-collaterals .cart_totals,
            .cart-collaterals .shipping_calculator{
                width:337px;
            }
            .ui-corner-all {
                font-size: 12px;
                text-align: center;
            }
            .calc_shipping_button {
                font-size: 14px;
            }
            .calc_shipping_button:hover {
                color: orange;
            }
        </style>

        <form class="shipping_calculator" action="<?php echo jigoshop_cart::get_cart_url(); ?>" method="post" style="text-align: center;">
            <h2><a href="#" class="shipping-calculator-button"><?php _e('Calculate Shipping', 'jigoshop'); ?> <span>&darr;</span></a></h2>
            <script type="text/javascript">
                jQuery(function(){
                    /* states */
                    var states_json = params.countries.replace(/&quot;/g, '"');
                    var states = jQuery.parseJSON( states_json );

                    jQuery('select.smart_send_country_to_state').change(function(){

                        var country = jQuery(this).val();
                        var state_box = jQuery('#' + jQuery(this).attr('rel'));

                        var input_name = jQuery(state_box).attr('name');
                        var input_id = jQuery(state_box).attr('id');

                        if (states[country]) {
                            var options = '';
                            var state = states[country];
                            for(var index in state) {
                                options = options + '<option value="' + index + '">' + state[index] + '</option>';
                            }
                            if (jQuery(state_box).is('input')) {
                                // Change for select
                                jQuery(state_box).replaceWith('<select name="' + input_name + '" id="' + input_id + '"><option value="">' + params.select_state_text + '</option></select>');
                                state_box = jQuery('#' + jQuery(this).attr('rel'));
                            }
                            jQuery(state_box).append(options);
                        } else {
                            if (jQuery(state_box).is('select')) {
                                jQuery(state_box).replaceWith('<input type="text" placeholder="' + params.state_text + '" name="' + input_name + '" id="' + input_id + '" />');
                                state_box = jQuery('#' + jQuery(this).attr('rel'));
                            }
                        }

                    }).change();
                });
            </script>
            <section class="shipping-calculator-form">
                <div class="col2-set" id="smart_send_state_select"<?php echo jigoshop_customer::get_shipping_country() != 'AU' ? ' style="display:none;"' : '';?>>
                    <input type="hidden" name="shipping-postcode" id="csp">
                    <input type="hidden" name="shipping-city" id="cst">
                    <p class="form-row-old">
                        <label for="calc_shipping_postcode">Please enter your postcode,<br>then select your town from the list:</label><br>
                        <input type="text" class="input-text" placeholder="<?php _e('Postcode/Zip', 'jigoshop'); ?>" title="<?php _e('Postcode', 'jigoshop'); ?>" name="postcode_placer" id="postcode_placer" style="width: 200px; text-align: center" />
                    </p>
                </div><button disabled="disabled" type="submit" name="calc_shipping" class="calc_shipping_button" id="calc_shipping_button" value="1" class="button" style="margin:0 auto; display: none"><?php _e('Calculate Shipping', 'jigoshop'); ?></button>
                <?php jigoshop::nonce_field('cart') ?>
            </section>
            <script type="text/javascript">
        <?php      
        $username = get_option( 'jigoshop_smart_send_vipusername');
        $password = get_option( 'jigoshop_smart_send_vippassword');

        $smartSendUtils = new smartSendUtils( $username, $password );
        $locations = $smartSendUtils->getLocations();
        foreach( $locations as $postcode => $townlist )
        {
            foreach( $townlist as $towndata )  $locs[] = $towndata[0] . ', ' . $postcode;
        }
        ?>
            jQuery(function(){
                var towns =  [
                "<?php echo implode( "\",\n\"", $locs ); ?>"
                ];
                jQuery('#postcode_placer').autocomplete( {
                    source: towns,
                    minLength: 4,
                    autoFocus: true,
                    select: function( ev, ui ) {
                        if( jQuery.type(ui.item) != 'null' )
                        {
                            jQuery(this).val(ui.item.value);
                            var addr = ui.item.value.split( ", ");
                            jQuery('#cst').val(addr[0]);
                            jQuery('#csp').val(addr[1]);
                            jQuery('#calc_shipping_button').removeAttr( 'disabled' );
                            jQuery('#calc_shipping_button').show('fast');
                        }
                    }
                });
            });
            </script>
        </form>
        <?php
        endif;

    }
}