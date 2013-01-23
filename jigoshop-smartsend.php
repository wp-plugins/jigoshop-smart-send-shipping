<?php
/*
	Plugin Name: Jigoshop - Smart Send Shipping Plugin
	Plugin URI: http://codexmedia.com.au/jigoshop-smart-send-shipping-plugin/
	Description: Add Smart Send shipping calculations to Jigoshop
	Version: 2.0
	Author:  Paul Appleyard	
	Author URI: http://codexmedia.com.au/
	License: GPL v2 - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

function jigoshop_smartsend_shipping_init()
{
    include_once('smartSendUtils.php');
    include_once('smartsend-plugin.php');
}

add_action('jigoshop_shipping_init', 'jigoshop_smartsend_shipping_init');