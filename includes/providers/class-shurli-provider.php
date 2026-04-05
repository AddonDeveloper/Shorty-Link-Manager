<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSL_Shurli_Provider implements WPSL_Shortener_Provider_Interface {
    const API_URL = 'https://shurli.at/api/url/add';

    private $api_key;

    public function __construct($api_key = '') {
        $this->api_key = trim((string) $api_key);
    }

    public function get_id() {
        return 'shurli';
    }

    public function get_name() {
        return 'Shurli.at';
    }

    public function get_registration_url() {
        return 'https://shurli.at/developers';
    }

    public function get_rate_limit_per_minute() {
        return 30;
    }

    public function get_recommended_batch_size() {
        return 5;
    }

    public function test_connection() {
        if (empty($this->api_key)) {
            return new WP_Error('wpsl_missing_api_key', __('API key is missing.', 'shorty-link-manager'));
        }

        $result = $this->shorten('https://example.com/?wpsl_test=' . time(), array(
            'description' => 'Shorty Link Manager API Test',
        ));

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    public function shorten($url, $args = array()) {
        if (empty($this->api_key)) {
            return new WP_Error('wpsl_missing_api_key', __('API key is missing.', 'shorty-link-manager'));
        }

        $payload = array(
            'url' => esc_url_raw($url),
        );

        if (!empty($args['custom'])) {
            $payload['custom'] = sanitize_title($args['custom']);
        }

        if (!empty($args['description'])) {
            $payload['description'] = sanitize_text_field($args['description']);
        }

        if (!empty($args['domain'])) {
            $payload['domain'] = sanitize_text_field($args['domain']);
        }

        $response = wp_remote_post(self::API_URL, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body' => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            $message = __('Short URL service returned an error.', 'shorty-link-manager');
            if (is_array($data)) {
                if (!empty($data['message'])) {
                    $message = sanitize_text_field($data['message']);
                } elseif (!empty($data['error'])) {
                    $message = sanitize_text_field($data['error']);
                }
            }
            return new WP_Error('wpsl_api_error', $message, array('status' => $code, 'response' => $data));
        }

        $short_url = '';

        if (is_array($data)) {
            foreach (array('shorturl', 'shortUrl', 'short_url', 'result', 'link', 'url') as $key) {
                if (!empty($data[$key]) && filter_var($data[$key], FILTER_VALIDATE_URL)) {
                    $short_url = $data[$key];
                    break;
                }
            }

            if (empty($short_url) && !empty($data['details']) && is_array($data['details'])) {
                foreach (array('shorturl', 'shortUrl', 'short_url', 'link', 'url') as $nested_key) {
                    if (!empty($data['details'][$nested_key]) && filter_var($data['details'][$nested_key], FILTER_VALIDATE_URL)) {
                        $short_url = $data['details'][$nested_key];
                        break;
                    }
                }
            }

            if (empty($short_url) && !empty($data['data']) && is_array($data['data'])) {
                foreach (array('shorturl', 'shortUrl', 'short_url', 'link', 'url') as $nested_key) {
                    if (!empty($data['data'][$nested_key]) && filter_var($data['data'][$nested_key], FILTER_VALIDATE_URL)) {
                        $short_url = $data['data'][$nested_key];
                        break;
                    }
                }
            }
        }

        if (empty($short_url)) {
            return new WP_Error('wpsl_invalid_response', __('No short URL was returned by the service.', 'shorty-link-manager'), $data);
        }

        return esc_url_raw($short_url);
    }
}
