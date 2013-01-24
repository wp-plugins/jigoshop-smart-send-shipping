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

class jigoshop_smart_send extends jigoshop_shipping_method {

    public static $seenAdmin = false;       // Show admin screen only the once
    public static $quoteStack = array();    // First shipping method makes the API call; subsequent grab a quote from that
    public static $extraHandling;           // Calculate handling fee first time round, available to other options

    protected $smartSendUtils;
    protected $allFeeTypes = array( 'none' => 'None', 'flat' => 'Flat Rate', 'percent' => 'Percentage' );
    protected $assuranceTypes = array( 'none' => 'No', 'forced' => 'Yes', 'optional' => 'Optional' );
    protected $receiptedTypes = array( 'none' => 'No', 'forced' => 'Yes', 'optional' => 'Optional' );
    protected $cloneId;

    public function __construct()
    {
        if( !$this->id ) $this->id = 'jigoshop_smart_send';
        if( !$this->cloneId ) $this->cloneId = 1;
        $this->title = get_option('jigoshop_smart_send_title');
        $this->enabled      = get_option('jigoshop_smart_send_enabled');
        $this->tax_status   = get_option('jigoshop_smart_send_tax_status');
        $this->fee          = 0;

        // Only for primary
        if( $this->cloneId == 1 )
        {
            add_action('jigoshop_update_options', array( &$this, 'process_admin_options') );

            add_option('jigoshop_smart_send_title', 'Courier');
            add_option('jigoshop_smart_send_package_type', 'Carton');
            add_option('jigoshop_smart_send_handling_fee_type', 'none');
            add_option('jigoshop_smart_send_handling_fee', 0);
            add_option('jigoshop_smart_send_assurance', 'none');
            add_option('jigoshop_smart_send_assurance_minimum', 0);
            add_option('jigoshop_smart_send_tax_status', 'none');
            add_option('jigoshop_smart_send_receipted', 'none');
            add_option('jigoshop_smart_send_lift_pickup', 0);
            add_option('jigoshop_smart_send_lift_delivery', 'yes' );

            add_option('jigoshop_smart_send_vipusername', '');
            add_option('jigoshop_smart_send_vippassword', '');

            if( $this->enabled == 'yes' )
            {
                add_action( 'before_checkout_form', array( $this, 'addSmartSendUserShippingOptions' ));
                // Called by AJAX when 'receipted' checkbox checked
                add_action( 'wp_ajax_smart_send_set_receipted', array( $this, 'ajaxSetReceipted' ));
                add_action( 'wp_ajax_nopriv_smart_send_set_receipted', array( $this, 'ajaxSetReceipted' ));

                add_action( 'wp_ajax_smart_send_set_tail_delivery', array( $this, 'ajaxSetTailDelivery' ));
                add_action( 'wp_ajax_nopriv_smart_send_set_tail_delivery', array( $this, 'ajaxSetTailDelivery' ));

                add_action( 'wp_ajax_smart_send_set_assurance', array( $this, 'ajaxSetAssurance' ));
                add_action( 'wp_ajax_nopriv_smart_send_set_assurance', array( $this, 'ajaxSetAssurance' ));
            }
        }

        if ( isset( jigoshop_session::instance()->chosen_shipping_method_id )
            && jigoshop_session::instance()->chosen_shipping_method_id == $this->id )
        {
            $this->chosen = true;
        }
    }

    // We set these options in the session, and access them during shipping calculations
    // TODO: De-duplicate shipping options javascript
    
    public function addSmartSendUserShippingOptions()
    {
        if( get_option('jigoshop_smart_send_receipted') == 'optional' )
        {
        ?>
        <div class="col2-set" id="smart_send_shipping_options_receipted">
            <input type="checkbox" id="smart_send_receipted"<?php if( isset(jigoshop_session::instance()->ssReceipted) && jigoshop_session::instance()->ssReceipted == 'yes' ) echo ' checked="checked"'; ?>>
            <label for="smart_send_receipted" style="vertical-align: middle; margin-left: 10px;">I require receipted delivery<br>
            <small>A signature will be required upon delivery</small></label>
        </div>
        <script type="text/javascript">
        (function($) {
            var ssParams = {
                    action:     'smart_send_set_receipted',
                    ssNonce:    '<?php echo wp_create_nonce('ssr_noncical'); ?>'
                },
                jqhxr;

            $('#smart_send_receipted').click( function()
            {
                $('#smart_send_shipping_options_receipted').block({
                    message: null,
                    overlayCSS: {
                    background: '#fff url(' + params.assets_url + '/assets/images/ajax-loader.gif) no-repeat 250px',
                    opacity: 0.6}});
                ssParams.ssReceipted = ( $(this).is(':checked') ) ? 'yes' : 'no';
                jqxhr = $.ajax({
                            type:       'POST',
                            url:        params.ajax_url,
                            data:       ssParams,
                            success:    function ( resp )
                            {
                                $('#smart_send_shipping_options_receipted').unblock();
                                clearTimeout(updateTimer);
                                update_checkout();
                            }
                        });
            });
        })(jQuery);
        </script>
        <?php
        }
        if( get_option('jigoshop_smart_send_lift_delivery') == 'yes' )
        {
        ?>
        <div class="col2-set" id="smart_send_shipping_options_tailift">
            <input type="checkbox" id="smart_send_taillift"<?php if( isset(jigoshop_session::instance()->ssTailDelivery) && jigoshop_session::instance()->ssTailDelivery == 'yes' ) echo ' checked="checked"'; ?>>
            <label for="smart_send_taillift" style="vertical-align: middle; margin-left: 10px;">I require tail-lift delivery<br>
            <small>If any items are 30kg or over, extra assistance will be provided</small></label>
        </div>
        <script type="text/javascript">
        (function($) {
            var ssParams = {
                    action:     'smart_send_set_tail_delivery',
                    ssNonce:    '<?php echo wp_create_nonce('sst_noncical'); ?>'
                },
                jqhxr;

            $('#smart_send_taillift').click( function()
            {
                $('#smart_send_shipping_options_tailift').block({
                    message: null,
                    overlayCSS: {
                    background: '#fff url(' + params.assets_url + '/assets/images/ajax-loader.gif) no-repeat 250px',
                    opacity: 0.6}});
                ssParams.ssTailLift = ( $(this).is(':checked') ) ? 'yes' : 'no';
                jqxhr = $.ajax({
                            type:       'POST',
                            url:        params.ajax_url,
                            data:       ssParams,
                            success:    function ( resp )
                            {
                                $('#smart_send_shipping_options_tailift').unblock();
                                clearTimeout(updateTimer);
                                update_checkout();
                            }
                        });
            });
        })(jQuery);
        </script>
        <?php
        }

        if( get_option('jigoshop_smart_send_assurance') == 'optional' )
        {
        ?>
        <div class="col2-set" id="smart_send_shipping_options_assurance">
            <input type="checkbox" id="smart_send_assurance"<?php if( isset(jigoshop_session::instance()->ssAssurance) && jigoshop_session::instance()->ssAssurance == 'yes' ) echo ' checked="checked"'; ?>>
            <label for="smart_send_assurance" style="vertical-align: middle; margin-left: 10px;">I require transport assurance<br>
            <small>Insure items against damage for a small additional fee</small></label>
        </div>
        <script type="text/javascript">
        (function($) {
            var ssParams = {
                    action:     'smart_send_set_assurance',
                    ssNonce:    '<?php echo wp_create_nonce('ssa_noncical'); ?>'
                },
                jqhxr;

            $('#smart_send_assurance').click( function()
            {
                $('#smart_send_shipping_options_assurance').block({
                    message: null,
                    overlayCSS: {
                    background: '#fff url(' + params.assets_url + '/assets/images/ajax-loader.gif) no-repeat 250px',
                    opacity: 0.6}});
                ssParams.ssAssurance = ( $(this).is(':checked') ) ? 'yes' : 'no';
                jqxhr = $.ajax({
                            type:       'POST',
                            url:        params.ajax_url,
                            data:       ssParams,
                            success:    function ( resp )
                            {
                                $('#smart_send_shipping_options_assurance').unblock();
                                clearTimeout(updateTimer);
                                update_checkout();
                            }
                        });
            });
        })(jQuery);
        </script>
        <?php
        }
    }

    // Set a session variable for whether the user wants receipted delivery
    public function ajaxSetReceipted()
    {
        if( !wp_verify_nonce( $_POST['ssNonce'], 'ssr_noncical') ) exit( 'Illegal Ajax action' );
        if( !in_array( $_POST['ssReceipted'], array( 'yes', 'no' ) ) ) exit( 'Invalid value' );
        if( get_option('jigoshop_smart_send_receipted') != 'optional' ) exit( 'You cannot set receipted delivery' );
        jigoshop_session::instance()->ssReceipted = $_POST['ssReceipted'];
        exit(jigoshop_session::instance()->ssReceipted);
    }

    // Set a session variable for whether the user wants receipted delivery
    public function ajaxSetTailDelivery()
    {
        if( !wp_verify_nonce( $_POST['ssNonce'], 'sst_noncical') ) exit( 'Illegal Ajax action' );
        if( !in_array( $_POST['ssTailLift'], array( 'yes', 'no' ) ) ) exit( 'Invalid value' );
        if( get_option('jigoshop_smart_send_lift_delivery') != 'yes' ) exit( 'Tail lift option not available' );
        jigoshop_session::instance()->ssTailDelivery = $_POST['ssTailLift'];
        exit(jigoshop_session::instance()->ssTailDelivery);
    }

    // Set a session variable for whether the user wants receipted delivery
    public function ajaxSetAssurance()
    {
        if( !wp_verify_nonce( $_POST['ssNonce'], 'ssa_noncical') ) exit( 'Illegal Ajax action' );
        if( !in_array( $_POST['ssAssurance'], array( 'yes', 'no' ) ) ) exit( 'Invalid value' );
        if( get_option('jigoshop_smart_send_assurance') != 'optional' ) exit( 'Assurance option not available' );
        jigoshop_session::instance()->ssAssurance = $_POST['ssAssurance'];
        exit(jigoshop_session::instance()->ssAssurance);
    }


    // To be available, shipping city and postcode must be provided or set
    // If provided, they will be:
    //  shipping-city / billing-city
    //  shipping-postcode / billing-postcode
    public function is_available()
    {
        if( $this->enabled == 'no' ) return false;
        list( $shippingToPostcode, $shippingToTown ) = $this->smartSendGetShipTo();
        if( empty($shippingToTown) || empty($shippingToPostcode ) ) return false;
        return true;
    }

    public function calculate_shipping() {

        $_tax = &new jigoshop_tax();

        $this->shipping_total 	= 0;
        $this->shipping_tax 	= 0;

        if( $this->cloneId > 1 )
        {
            if( getStack(true) )
            {
                $quotes = array_shift( jigoshop_smart_send::$quoteStack );
                if( $quotes->TotalPrice > 0 )
                {
                    $this->shipping_total += $quotes->TotalPrice + jigoshop_smart_send::$extraHandling;
                    $this->title = $this->title .': '.$quotes->TransitDescription;
                }
                else $this->enabled = 'no';
                return;
            }
            else
            {
                $this->enabled = 'no';
                return;
            }
        }
        
        list( $shippingToPostcode, $shippingToTown ) = $this->smartSendGetShipTo();

        $shippingOriginPostcode = get_option( 'jigoshop_smart_send_origin_postcode');
        $shippingOriginTown     = get_option( 'jigoshop_smart_send_origin_town');

        $vipUsername = get_option('jigoshop_smart_send_vipusername');
        $vipPassword = get_option('jigoshop_smart_send_vippassword');

        $description = get_option('jigoshop_smart_send_package_type');

        if( sizeof( jigoshop_cart::$cart_contents ) > 0 && !empty($shippingOriginTown) && !empty($shippingOriginPostcode) )
        {
            $itemList = array();
            $pickupFlag = $deliveryFlag = 0;

            foreach( jigoshop_cart::$cart_contents as $item_id => $values )
            {
                $_product = $values['data'];

                if ($_product->exists() && $values['quantity'] > 0 && $_product->product_type != 'downloadable' )
                {
                    $totalCart = 0;
                    foreach( range( 1, $values['quantity'] ) as $blah ) { // Loop through quantity of each product
                        $shipping_error = false;
                        $weight = $_product->get_weight();
                        $length = $_product->get_length();
                        $height = $_product->get_height();
                        $width = $_product->get_width();
                        $totalCart += $_product->get_price();

                        // Tail-lift options
                        $tailPickup = get_option('jigoshop_smart_send_lift_pickup');
                        $tailDelivery = get_option('jigoshop_smart_send_lift_delivery');
                        $sessionDelivery = jigoshop_session::instance()->ssTailDelivery;

                        // Flags for calculating tail lift options
                        if( $tailDelivery == 'yes' && $weight >= 30 && $sessionDelivery == 'yes' ) $deliveryFlag = 1;
                        if( $tailPickup > 0 && $weight > $tailPickup ) $pickupFlag = 1;

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
                            'Description'   => $description,
                            'Weight'        => $weight,
                            'Depth'         => $width,
                            'Length'        => $length,
                            'Height'        => $height
                        );
                    } // End loop through item count
                }
            } // End listing through cart items

            if( isset($itemList) && count($itemList) )
            {
                $smartSendQuote = new smartSendUtils( $vipUsername, $vipPassword );

                $shippingOriginState = $smartSendQuote->getState( $shippingOriginPostcode );
                $shippingToState = $smartSendQuote->getState( $shippingToPostcode );

                $smartSendQuote->setFrom(
                    array( $shippingOriginPostcode, $shippingOriginTown, $shippingOriginState )
                );

                $smartSendQuote->setTo(
                    array( $shippingToPostcode, $shippingToTown, $shippingToState )
                );

                // Handling Fees

                $feeType = get_option('jigoshop_smart_send_handling_fee_type');
                if( $feeType == 'flat' ) $this->fee = get_option('jigoshop_smart_send_handling_fee');
                else if( $feeType == 'percent' ) $this->fee = $totalCart * get_option('jigoshop_smart_send_handling_fee') / 100;
                self::$extraHandling = $this->fee;

                // Transport Assurance
                
                $assuranceOpt = get_option('jigoshop_smart_send_assurance' );

                if( $assuranceOpt == 'forced' || ( $assuranceOpt == 'optional' && !empty( jigoshop_session::instance()->ssAssurance ) && jigoshop_session::instance()->ssAssurance == 'yes' ))
                {
                    if( $totalCart >= get_option('jigoshop_smart_send_assurance_minimum') )
                        $smartSendQuote->setOptional( 'transportAssurance', $totalCart );
                }

                // Receipted Delivery
                
                $receiptedOpt = get_option('jigoshop_smart_send_receipted');

                if( $receiptedOpt == 'forced' || ( $receiptedOpt == 'optional' && !empty(jigoshop_session::instance()->ssReceipted) && jigoshop_session::instance()->ssReceipted == 'yes' ) )
                {
                    $smartSendQuote->setOptional( 'receiptedDelivery', 'true' );
                }

                // Tail-lift
                $tailLift = 'NONE';
                if( $pickupFlag ) $tailLift = 'PICKUP';
                if( $deliveryFlag )
                {
                    if( $pickupFlag ) $tailLift = 'BOTH';
                    else $tailLift = 'DELIVERY';
                }
                $smartSendQuote->setOptional( 'tailLift', $tailLift );


                foreach( $itemList as $item )  $smartSendQuote->addItem( $item );
                $quoteResult = $smartSendQuote->getQuote();
                $quotes = $quoteResult->ObtainQuoteResult->Quotes->Quote;

                // Put all quotes in to the stack, ready for clones to feed from
                if( is_array( $quotes ) )
                {
                    self::$quoteStack = $quotes;
                    $quotes = array_shift( self::$quoteStack );
                }

                if( $quotes->TotalPrice )
                {
                    $this->shipping_total = $quotes->TotalPrice + $this->fee;
                    $this->title = $this->title .': '.$quotes->TransitDescription;
                }
            }
        }

    }

    public function admin_options() {
        if( $this->cloneId > 1 ) return;
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
    <tr>
        <td class="titledesc"><a href="#" tabindex="99"></a><?php _e('Type of Package', 'jigoshop') ?>:</td>
        <td class="forminp">
            <select name="jigoshop_smart_send_package_type" id="jigoshop_smart_send_package_type">
            <?php
            $feeType = get_option('jigoshop_smart_send_package_type');
            foreach( smartSendUtils::$ssPackageTypes as $v )
            {
                echo '<option value="'.$v.'"';
                if( $v == $feeType ) echo ' selected="selected"';
                echo ">$v</option>\n";
            }
            ?>
            </select>
        </td>
    </tr>
    <tr>
        <td class="titledesc"><a href="#" tip="<?php _e('Flat rate or a percentage of shipping cost.', 'jigoshop') ?>" class="tips" tabindex="99"></a><?php _e('Handling Fee Type', 'jigoshop') ?>:</td>
        <td class="forminp">
            <select name="jigoshop_smart_send_handling_fee_type" id="jigoshop_smart_send_handling_fee_type">
            <?php
            $feeType = get_option('jigoshop_smart_send_handling_fee_type');
            foreach( $this->allFeeTypes as $k => $v )
            {
                echo '<option value="'.$k.'"';
                if( $k == $feeType ) echo ' selected="selected"';
                echo ">$v</option>\n";
            }
            ?>
            </select>
        </td>
    </tr>
    <tr>
        <td class="titledesc"><a href="#" tip="<?php _e('Handling fee amount or percentage.', 'jigoshop') ?>" class="tips" tabindex="99"></a><?php _e('Handling Fee', 'jigoshop') ?>:</td>
        <td class="forminp">
            <input type="text" name="jigoshop_smart_send_handling_fee" id="jigoshop_smart_send_handling_fee" value="<?php if ($value = get_option('jigoshop_smart_send_handling_fee')) echo $value; ?>" style="width: 50px" />
        </td>
    </tr>
    <tr>
        <td class="titledesc"><a href="#" tip="<?php _e('\'Forced\' will add assurance automatically, \'Optional\' will give the user the option of adding it to the shipping cost at checkout.', 'jigoshop') ?>" class="tips" tabindex="99"></a><?php _e('Transport Assurance', 'jigoshop') ?>:</td>
        <td class="forminp">
            <select name="jigoshop_smart_send_assurance" id="jigoshop_smart_send_assurance">
            <?php
            $assurance = get_option('jigoshop_smart_send_assurance');
            foreach( $this->assuranceTypes as $k => $v )
            {
                echo '<option value="'.$k.'"';
                if( $k == $assurance ) echo ' selected="selected"';
                echo ">$v</option>\n";
            }
            ?>
            </select>
        </td>
    </tr>
    <tr>
        <td class="titledesc"><a href="#" tip="<?php _e('If assurance required, apply to cart totals over this figure.', 'jigoshop') ?>" class="tips" tabindex="99"></a><?php _e('Assurance Min.', 'jigoshop') ?>:</td>
        <td class="forminp">
            <input type="text" name="jigoshop_smart_send_assurance_minimum" id="jigoshop_smart_send_assurance_minimum" value="<?php echo get_option('jigoshop_smart_send_assurance_minimum'); ?>" style="width: 50px" />
        </td>
    </tr>
    <tr>
        <td class="titledesc"><a href="#" tip="<?php _e('\'Forced\' will always request it, \'Optional\' will give the user the option of requesting it at checkout.', 'jigoshop') ?>" class="tips" tabindex="99"></a><?php _e('Receipted Delivery', 'jigoshop') ?>:</td>
        <td class="forminp">
            <select name="jigoshop_smart_send_receipted" id="jigoshop_smart_send_receipted">
            <?php
            $receipted = get_option('jigoshop_smart_send_receipted');
            foreach( $this->receiptedTypes as $k => $v )
            {
                echo '<option value="'.$k.'"';
                if( $k == $receipted ) echo ' selected="selected"';
                echo ">$v</option>\n";
            }
            ?>
            </select>
        </td>
    </tr>
    <tr>
        <td class="titledesc"><a href="#" tip="<?php _e('If an item is heavier than this, request tail-lift truck pickup.', 'jigoshop') ?>" class="tips" tabindex="99"></a><?php _e('Tail-Lift - Pickup', 'jigoshop') ?>:</td>
        <td class="forminp">
            <input type="text" name="jigoshop_smart_send_lift_pickup" id="jigoshop_smart_send_lift_pickup" value="<?php echo get_option('jigoshop_smart_send_lift_pickup'); ?>" style="width: 50px"/> KG - set to zero for none.
        </td>
    </tr>
    <tr>
        <td class="titledesc"><a href="#" tip="<?php _e('Offer tail-lift delivery assist if any item over 30kg.', 'jigoshop') ?>" class="tips" tabindex="99"></a><?php _e('Tail-Lift - Delivery', 'jigoshop') ?>:</td>
        <td class="forminp">
            <select name="jigoshop_smart_send_lift_delivery" id="jigoshop_smart_send_lift_delivery">
            <?php
            $deliveryHelp = get_option('jigoshop_smart_send_lift_delivery');
            foreach( array( 'no', 'yes' ) as $v )
            {
                echo '<option value="'.$v.'"';
                if( $v == $deliveryHelp ) echo ' selected="selected"';
                echo '>'.ucfirst($v)."</option>\n";
            }
            ?>
            </select>
        </td>
    </tr>
    <?php
    }

    public function process_admin_options() {

        $postData = $_POST;

        if(isset($_POST['jigoshop_smart_send_enabled'])) update_option('jigoshop_smart_send_enabled', jigowatt_clean($_POST['jigoshop_smart_send_enabled'])); else @delete_option('jigoshop_smart_send_enabled');

        if(isset($_POST['jigoshop_smart_send_vipusername'])) update_option('jigoshop_smart_send_vipusername', jigowatt_clean($_POST['jigoshop_smart_send_vipusername'])); else @delete_option('jigoshop_smart_send_vipusername');

        if(isset($_POST['jigoshop_smart_send_vippassword'])) update_option('jigoshop_smart_send_vippassword', jigowatt_clean($_POST['jigoshop_smart_send_vippassword'])); else @delete_option('jigoshop_smart_send_vippasswordd');

        if(isset($_POST['jigoshop_smart_send_package_type'])) update_option('jigoshop_smart_send_package_type', jigowatt_clean($_POST['jigoshop_smart_send_package_type'])); else @delete_option('jigoshop_smart_send_package_type');

        if(isset($_POST['jigoshop_smart_send_title'])) update_option('jigoshop_smart_send_title', jigowatt_clean($_POST['jigoshop_smart_send_title'])); else @delete_option('jigoshop_smart_send_title');

        if(isset($_POST['jigoshop_smart_send_origin_postcode'])) update_option('jigoshop_smart_send_origin_postcode', jigowatt_clean($_POST['jigoshop_smart_send_origin_postcode'])); else @delete_option('jigoshop_smart_send_origin_postcode');

        if(isset($_POST['jigoshop_smart_send_origin_town'])) update_option('jigoshop_smart_send_origin_town', jigowatt_clean($_POST['jigoshop_smart_send_origin_town'])); else @delete_option('jigoshop_smart_send_origin_town');

        if(isset($_POST['jigoshop_smart_send_handling_fee_type'])) update_option('jigoshop_smart_send_handling_fee_type', jigowatt_clean($_POST['jigoshop_smart_send_handling_fee_type'])); else @delete_option('jigoshop_smart_send_handling_fee_type');

        if(isset($_POST['jigoshop_smart_send_handling_fee'])) update_option('jigoshop_smart_send_handling_fee', jigowatt_clean($_POST['jigoshop_smart_send_handling_fee'])); else @delete_option('jigoshop_smart_send_handling_fee');

        if(isset($_POST['jigoshop_smart_send_assurance'])) update_option('jigoshop_smart_send_assurance', jigowatt_clean($_POST['jigoshop_smart_send_assurance'])); else @delete_option('jigoshop_smart_send_assurance');

        if(isset($_POST['jigoshop_smart_send_assurance_minimum'])) update_option('jigoshop_smart_send_assurance_minimum', jigowatt_clean($_POST['jigoshop_smart_send_assurance_minimum'])); else @delete_option('jigoshop_smart_send_assurance_minimum');

        if(isset($_POST['jigoshop_smart_send_receipted'])) update_option('jigoshop_smart_send_receipted', jigowatt_clean($_POST['jigoshop_smart_send_receipted'])); else @delete_option('jigoshop_smart_send_receipted');

        if(isset($_POST['jigoshop_smart_send_lift_pickup'])) update_option('jigoshop_smart_send_lift_pickup', jigowatt_clean($_POST['jigoshop_smart_send_lift_pickup'])); else @delete_option('jigoshop_smart_send_lift_pickup');

        if(isset($_POST['jigoshop_smart_send_lift_delivery'])) update_option('jigoshop_smart_send_lift_delivery', jigowatt_clean($_POST['jigoshop_smart_send_lift_delivery'])); else @delete_option('jigoshop_smart_send_lift_delivery');
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
            if( isset( $shippingToPostcode ) ) jigoshop_customer::set_shipping_postcode($shippingToPostcode);
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

        if( !jigoshop_customer::get_shipping_country() ) jigoshop_customer::set_shipping_country('AU');
        return array( $shippingToPostcode, $shippingToTown );
    }

}

function add_smart_send_method( $methods )
{
    $methods[] = 'jigoshop_smart_send';

    // Let's make 3 extra methods all up to handle possible options
    foreach( range( 2, 4 ) as $ss )
    {
        eval(
        'class jigoshop_smart_send'.$ss.' extends jigoshop_smart_send
        {
            public function __construct()
            {
                $this->id = "jigoshop_smart_send'.$ss.'";
                $this->cloneId = '.$ss.';
                parent::__construct();
            }
        }'
        );
        $methods[] = 'jigoshop_smart_send' . $ss;
    }
    return $methods;
}

add_filter('jigoshop_shipping_methods', 'add_smart_send_method' );

function getStack($count = false )
{
    if( isset( jigoshop_smart_send::$quoteStack ) )
    {
        if( $count ) return count( jigoshop_smart_send::$quoteStack );
        else return array_shift( jigoshop_smart_send::$quoteStack );
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
            #postcode-finder-box {
                position: relative;
                width: 200px;
                margin-left: 65px;
            }
            #postcode-finder-box .ui-menu-item {
                width: 200px;
            }
            #postcode-finder-box .ui-autocomplete {
                position: relative;
                width: 200px;
            }
        </style>

        <form class="shipping_calculator" action="<?php echo jigoshop_cart::get_cart_url(); ?>" method="post" style="text-align: center;">
            <h2><a href="#" class="shipping-calculator-button"><?php _e('Calculate Shipping', 'jigoshop'); ?> <span>&darr;</span></a></h2>
            <section class="shipping-calculator-form">
                <?php
                $toCountry = jigoshop_customer::get_shipping_country();
                if( $toCountry && $toCountry != 'AU' )
                {
                    echo "We do not ship to your country.";
                }
                else
                {
                    ?>
                <div class="col2-set" id="smart_send_state_select">
                <input type="hidden" name="shipping-postcode" id="csp">
                <input type="hidden" name="shipping-city" id="cst">
                <p class="form-row-old" style="margin-bottom: 0px">
                    <label for="calc_shipping_postcode">Please enter your postcode,<br>then select your town from the list:</label><br>
                    <input type="text" class="input-text" placeholder="<?php _e('Postcode/Zip', 'jigoshop'); ?>" title="<?php _e('Postcode', 'jigoshop'); ?>" name="postcode-placer" id="postcode-placer" style="width: 200px; text-align: center;" />
                    <div id="postcode-finder-box"></div>
                </p>
                </div>
                <button disabled="disabled" type="submit" name="calc_shipping" class="calc_shipping_button" id="calc_shipping_button" value="1" class="button" style="margin:0 auto; display: none"><?php _e('Calculate Shipping', 'jigoshop'); ?></button>
                <?php jigoshop::nonce_field('cart');
                }
                ?>
                <div class="col2-set" id="smart_send_state_select"<?php echo jigoshop_customer::get_shipping_country() != 'AU' ? ' style="display:none;"' : '';?>>

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
                jQuery('#postcode-placer').autocomplete( {
                    source: towns,
                    minLength: 4,
                    autoFocus: true,
                    appendTo: "#postcode-finder-box",
                    create: function( ev, ui ) {
                        jQuery("#postcode-finder-box").css( { height: '200px'});
                    },
                    select: function( ev, ui ) {
                        if( jQuery.type(ui.item) != 'null' )
                        {
                            jQuery(this).val(ui.item.value);
                            var addr = ui.item.value.split( ", ");
                            jQuery('#cst').val(addr[0]);
                            jQuery('#csp').val(addr[1]);
                            jQuery('#calc_shipping_button').removeAttr( 'disabled' );
                            jQuery('#calc_shipping_button').show('fast');
                            jQuery("#postcode-finder-box").css( { height: '0px'});
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