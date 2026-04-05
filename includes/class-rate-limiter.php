<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSL_Rate_Limiter {
    public function get_available_slots(WPSL_Shortener_Provider_Interface $provider) {
        $limit = max(1, (int) $provider->get_rate_limit_per_minute());
        $data = get_transient($this->get_transient_key($provider));

        if (!is_array($data) || empty($data['window_started']) || !isset($data['count'])) {
            return $limit;
        }

        $age = time() - (int) $data['window_started'];
        if ($age >= MINUTE_IN_SECONDS) {
            delete_transient($this->get_transient_key($provider));
            return $limit;
        }

        return max(0, $limit - (int) $data['count']);
    }

    public function register_hit(WPSL_Shortener_Provider_Interface $provider, $count = 1) {
        $limit = max(1, (int) $provider->get_rate_limit_per_minute());
        $key = $this->get_transient_key($provider);
        $data = get_transient($key);

        if (!is_array($data) || empty($data['window_started']) || (time() - (int) $data['window_started']) >= MINUTE_IN_SECONDS) {
            $data = array(
                'window_started' => time(),
                'count' => 0,
            );
        }

        $data['count'] = min($limit, (int) $data['count'] + max(1, (int) $count));
        set_transient($key, $data, MINUTE_IN_SECONDS);
    }

    private function get_transient_key(WPSL_Shortener_Provider_Interface $provider) {
        return 'wpsl_rate_' . sanitize_key($provider->get_id());
    }
}
