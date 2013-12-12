<?php
/*
Plugin Name: Group Buying Payment Processor - JuanPay
Version: .1
Plugin URI: http://sproutventure.com/wordpress/group-buying
Description: mPay24 Payments Add-on.
Author: Sprout Venture
Author URI: http://sproutventure.com/wordpress
Plugin Author: Dan Cameron
Contributors: Dan Cameron
Text Domain: group-buying
Domain Path: /lang
*/

add_action('gb_register_processors', 'gb_load_jp');
function gb_load_jp() {
	require_once('groupBuyingJuanPay.class.php');
}