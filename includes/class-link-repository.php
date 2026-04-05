<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSL_Link_Repository {
    const OPTION_KEY = 'wpsl_link_map';
    const LINKS_OPTION_KEY = 'wpsl_discovered_links';

    public function get_short_url($provider_id, $original_url) {
        $map = get_option(self::OPTION_KEY, array());
        $key = $this->build_key($provider_id, $original_url);
        return isset($map[$key]) ? esc_url_raw($map[$key]) : '';
    }

    public function save_mapping($provider_id, $original_url, $short_url) {
        $map = get_option(self::OPTION_KEY, array());
        $key = $this->build_key($provider_id, $original_url);
        $map[$key] = esc_url_raw($short_url);
        update_option(self::OPTION_KEY, $map, false);
    }

    public function normalize_url($url) {
        $url = trim((string) $url);
        if (empty($url)) {
            return '';
        }

        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return esc_url_raw($url);
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $path = isset($parts['path']) ? $parts['path'] : '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return esc_url_raw($scheme . '://' . $host . $path . $query);
    }

    public function get_all_links() {
        $links = get_option(self::LINKS_OPTION_KEY, array());
        return is_array($links) ? $links : array();
    }

    public function get_link($normalized_url) {
        $links = $this->get_all_links();
        return isset($links[$normalized_url]) ? $links[$normalized_url] : null;
    }

    public function save_links($links) {
        update_option(self::LINKS_OPTION_KEY, $links, false);
    }

    public function reset_discovered_links() {
        update_option(self::LINKS_OPTION_KEY, array(), false);
    }

    public function upsert_discovered_link($original_url, $post_id, $post_type, $post_title, $anchor_text = '') {
        $normalized = $this->normalize_url($original_url);
        if (empty($normalized)) {
            return;
        }

        $links = $this->get_all_links();
        $is_new_link = !isset($links[$normalized]);
        if ($is_new_link) {
            $links[$normalized] = array(
                'original_url' => esc_url_raw($original_url),
                'normalized_url' => $normalized,
                'short_url' => '',
                'provider' => '',
                'status' => 'pending',
                'error_message' => '',
                'occurrences' => array(),
                'last_processed_at' => '',
                'updated_at' => current_time('mysql'),
            );
        }

        $occurrence_key = $post_id . '|' . md5((string) $anchor_text);
        $links[$normalized]['occurrences'][$occurrence_key] = array(
            'post_id' => (int) $post_id,
            'post_type' => sanitize_key($post_type),
            'post_title' => sanitize_text_field($post_title),
            'anchor_text' => sanitize_text_field($anchor_text),
        );
        $links[$normalized]['original_url'] = esc_url_raw($original_url);
        $links[$normalized]['updated_at'] = current_time('mysql');

        $provider_id = 'shurli';
        $cached_short = $this->get_short_url($provider_id, $original_url);
        if ($is_new_link && !empty($cached_short) && empty($links[$normalized]['short_url'])) {
            $links[$normalized]['short_url'] = $cached_short;
            $links[$normalized]['provider'] = $provider_id;
            $links[$normalized]['status'] = 'shortened';
            $links[$normalized]['last_processed_at'] = current_time('mysql');
        }

        $this->save_links($links);
    }

    public function update_link_status($normalized_url, $data) {
        $links = $this->get_all_links();
        if (!isset($links[$normalized_url])) {
            return;
        }

        $links[$normalized_url] = array_merge($links[$normalized_url], $data);
        $links[$normalized_url]['updated_at'] = current_time('mysql');
        $this->save_links($links);
    }

    public function get_links_by_status($status) {
        $links = $this->get_all_links();
        if (empty($status) || 'all' === $status) {
            return $links;
        }

        return array_filter($links, function ($link) use ($status) {
            return isset($link['status']) && $link['status'] === $status;
        });
    }

    public function count_by_status($status) {
        return count($this->get_links_by_status($status));
    }

    public function get_pending_links($limit = 0) {
        $pending = $this->get_links_by_status('pending');
        if ($limit > 0) {
            return array_slice($pending, 0, $limit, true);
        }
        return $pending;
    }

    public function get_status_totals() {
        $all = $this->get_all_links();
        return array(
            'all' => count($all),
            'pending' => count(array_filter($all, function ($link) {
                return isset($link['status']) && 'pending' === $link['status'];
            })),
            'shortened' => count(array_filter($all, function ($link) {
                return isset($link['status']) && 'shortened' === $link['status'];
            })),
            'error' => count(array_filter($all, function ($link) {
                return isset($link['status']) && 'error' === $link['status'];
            })),
        );
    }

    public function get_progress_percent() {
        $totals = $this->get_status_totals();
        if ($totals['all'] < 1) {
            return 0;
        }

        $done = $totals['shortened'] + $totals['error'];
        return min(100, max(0, (int) floor(($done / $totals['all']) * 100)));
    }

    private function build_key($provider_id, $original_url) {
        return md5($provider_id . '|' . untrailingslashit((string) $original_url));
    }
}
