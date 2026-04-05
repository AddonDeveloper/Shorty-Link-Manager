<?php

if (!defined('ABSPATH')) {
    exit;
}

interface WPSL_Shortener_Provider_Interface {
    public function get_id();
    public function get_name();
    public function get_registration_url();
    public function get_rate_limit_per_minute();
    public function get_recommended_batch_size();
    public function test_connection();
    public function shorten($url, $args = array());
}
