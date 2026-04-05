<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSL_Link_Processor {
    private $repository;
    private $scanner;
    private $rate_limiter;
    private $is_processing = false;

    public function __construct(WPSL_Link_Repository $repository, WPSL_Link_Scanner $scanner, WPSL_Rate_Limiter $rate_limiter) {
        $this->repository = $repository;
        $this->scanner = $scanner;
        $this->rate_limiter = $rate_limiter;
    }

    public function maybe_process_post($post_id, $post, $update) {
        if ($this->is_processing) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (!in_array($post->post_type, array('post', 'page'), true)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $options = get_option('wpsl_settings', array());
        $mode = isset($options['mode']) ? $options['mode'] : 'manual';

        $this->scanner->scan_single_post($post);

        if ('automatic' !== $mode) {
            return;
        }

        $provider = wpsl_get_active_provider();
        if (!$provider) {
            return;
        }

        $new_content = $this->shorten_content_links($post->post_content, $provider);
        if (is_wp_error($new_content) || $new_content === $post->post_content) {
            return;
        }

        $this->is_processing = true;
        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $new_content,
        ));
        $this->is_processing = false;
    }

    public function shorten_post_links_now($post_id) {
        $post = get_post($post_id);
        if (!$post || !current_user_can('edit_post', $post_id)) {
            return new WP_Error('wpsl_forbidden', __('You are not allowed to edit this post.', 'shorty-link-manager'));
        }

        $provider = wpsl_get_active_provider();
        if (!$provider) {
            return new WP_Error('wpsl_no_provider', __('No active short URL provider configured.', 'shorty-link-manager'));
        }

        $new_content = $this->shorten_content_links($post->post_content, $provider);
        if (is_wp_error($new_content)) {
            return $new_content;
        }

        if ($new_content !== $post->post_content) {
            $this->is_processing = true;
            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $new_content,
            ));
            $this->is_processing = false;
        }

        $updated_post = get_post($post_id);
        if ($updated_post) {
            $this->scanner->scan_single_post($updated_post);
        }

        return true;
    }

    public function process_single_link($normalized_url, WPSL_Shortener_Provider_Interface $provider) {
        $link = $this->repository->get_link($normalized_url);
        if (empty($link) || empty($link['original_url'])) {
            return new WP_Error('wpsl_link_missing', __('Link not found.', 'shorty-link-manager'));
        }

        $cached = $this->repository->get_short_url($provider->get_id(), $link['original_url']);
        if (!empty($cached)) {
            $this->repository->update_link_status($normalized_url, array(
                'short_url' => $cached,
                'provider' => $provider->get_id(),
                'status' => 'shortened',
                'error_message' => '',
                'last_processed_at' => current_time('mysql'),
            ));
            $this->apply_short_url_to_occurrences($normalized_url, $cached);
            return $cached;
        }

        if ($this->rate_limiter->get_available_slots($provider) < 1) {
            return new WP_Error('wpsl_rate_limit', __('Rate limit reached. Please continue in about a minute.', 'shorty-link-manager'));
        }

        $short_url = $provider->shorten($link['original_url'], array(
            'description' => wp_strip_all_tags(get_bloginfo('name')),
        ));

        $this->rate_limiter->register_hit($provider, 1);

        if (is_wp_error($short_url)) {
            $this->repository->update_link_status($normalized_url, array(
                'status' => 'error',
                'error_message' => $short_url->get_error_message(),
                'provider' => $provider->get_id(),
                'last_processed_at' => current_time('mysql'),
            ));
            return $short_url;
        }

        $this->repository->save_mapping($provider->get_id(), $link['original_url'], $short_url);
        $this->repository->update_link_status($normalized_url, array(
            'short_url' => $short_url,
            'provider' => $provider->get_id(),
            'status' => 'shortened',
            'error_message' => '',
            'last_processed_at' => current_time('mysql'),
        ));
        $this->apply_short_url_to_occurrences($normalized_url, $short_url);

        return $short_url;
    }


    public function restore_single_link($normalized_url) {
        $link = $this->repository->get_link($normalized_url);
        if (empty($link) || empty($link['original_url']) || empty($link['short_url'])) {
            return new WP_Error('wpsl_link_missing', __('No shortened link data was found.', 'shorty-link-manager'));
        }

        if (!empty($link['occurrences']) && is_array($link['occurrences'])) {
            foreach ($link['occurrences'] as $occurrence) {
                if (empty($occurrence['post_id'])) {
                    continue;
                }
                $this->replace_url_in_post_exact((int) $occurrence['post_id'], $link['short_url'], $link['original_url']);
            }
        }

        $this->repository->update_link_status($normalized_url, array(
            'short_url' => '',
            'provider' => '',
            'status' => 'pending',
            'error_message' => '',
            'last_processed_at' => current_time('mysql'),
        ));

        return true;
    }

    public function process_pending_batch(WPSL_Shortener_Provider_Interface $provider) {
        $available = $this->rate_limiter->get_available_slots($provider);
        if ($available < 1) {
            return array(
                'processed' => 0,
                'success' => 0,
                'errors' => 0,
                'remaining' => count($this->repository->get_pending_links()),
                'progress_percent' => $this->repository->get_progress_percent(),
                'message' => __('Rate limit reached. Please continue in about a minute.', 'shorty-link-manager'),
                'rate_limited' => true,
                'done' => false,
            );
        }

        $limit = min($available, max(1, (int) $provider->get_recommended_batch_size()));
        $pending = $this->repository->get_pending_links($limit);

        $result = array(
            'processed' => 0,
            'success' => 0,
            'errors' => 0,
            'remaining' => 0,
            'progress_percent' => 0,
            'message' => '',
            'rate_limited' => false,
            'done' => false,
        );

        foreach ($pending as $normalized_url => $link) {
            $processed = $this->process_single_link($normalized_url, $provider);
            $result['processed']++;
            if (is_wp_error($processed)) {
                $result['errors']++;
                if ('wpsl_rate_limit' === $processed->get_error_code()) {
                    $result['rate_limited'] = true;
                    break;
                }
            } else {
                $result['success']++;
            }
        }

        $result['remaining'] = count($this->repository->get_pending_links());
        $result['progress_percent'] = $this->repository->get_progress_percent();
        $result['done'] = ($result['remaining'] <= 0);

        if ($result['done']) {
            $result['message'] = __('All pending links have been processed.', 'shorty-link-manager');
        } elseif ($result['rate_limited'] || $this->rate_limiter->get_available_slots($provider) < 1) {
            $result['rate_limited'] = true;
            $result['message'] = __('Current batch finished. Waiting for the next rate-limit window.', 'shorty-link-manager');
        } else {
            $result['message'] = __('Batch processed. Starting the next batch automatically.', 'shorty-link-manager');
        }

        return $result;
    }

    public function get_progress_snapshot(WPSL_Shortener_Provider_Interface $provider = null) {
        $totals = $this->repository->get_status_totals();
        $snapshot = array(
            'total' => $totals['all'],
            'pending' => $totals['pending'],
            'shortened' => $totals['shortened'],
            'error' => $totals['error'],
            'progress_percent' => $this->repository->get_progress_percent(),
            'last_scan_at' => get_option('wpsl_last_scan_at', ''),
            'can_continue' => false,
            'rate_limit_remaining' => 0,
        );

        if ($provider) {
            $snapshot['rate_limit_remaining'] = $this->rate_limiter->get_available_slots($provider);
            $snapshot['can_continue'] = $snapshot['pending'] > 0 && $snapshot['rate_limit_remaining'] > 0;
        }

        return $snapshot;
    }

    public function shorten_content_links($content, WPSL_Shortener_Provider_Interface $provider) {
        if (empty($content) || stripos($content, 'href=') === false) {
            return $content;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $wrapped = '<?xml encoding="utf-8" ?><div>' . $content . '</div>';
        $loaded = $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        if (!$loaded) {
            return new WP_Error('wpsl_dom_error', __('Post content could not be parsed.', 'shorty-link-manager'));
        }

        $links = $dom->getElementsByTagName('a');
        $changed = false;

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if (!$this->scanner->should_track_url($href)) {
                continue;
            }

            $cached = $this->repository->get_short_url($provider->get_id(), $href);
            if (!empty($cached)) {
                $link->setAttribute('href', $cached);
                $changed = true;
                continue;
            }

            if ($this->rate_limiter->get_available_slots($provider) < 1) {
                continue;
            }

            $short_url = $provider->shorten($href, array(
                'description' => wp_strip_all_tags(get_bloginfo('name')),
            ));

            $this->rate_limiter->register_hit($provider, 1);

            $normalized_url = $this->repository->normalize_url($href);
            if (is_wp_error($short_url)) {
                if ($normalized_url) {
                    $this->repository->update_link_status($normalized_url, array(
                        'status' => 'error',
                        'provider' => $provider->get_id(),
                        'error_message' => $short_url->get_error_message(),
                        'last_processed_at' => current_time('mysql'),
                    ));
                }
                continue;
            }

            $this->repository->save_mapping($provider->get_id(), $href, $short_url);
            if ($normalized_url) {
                $this->repository->update_link_status($normalized_url, array(
                    'short_url' => $short_url,
                    'provider' => $provider->get_id(),
                    'status' => 'shortened',
                    'error_message' => '',
                    'last_processed_at' => current_time('mysql'),
                ));
            }
            $link->setAttribute('href', $short_url);
            $changed = true;
        }

        if (!$changed) {
            return $content;
        }

        $html = $dom->saveHTML($dom->documentElement);
        $html = preg_replace('/^<div>|<\/div>$/', '', $html);
        return $html;
    }

    private function apply_short_url_to_occurrences($normalized_url, $short_url) {
        $link = $this->repository->get_link($normalized_url);
        if (empty($link['occurrences']) || !is_array($link['occurrences'])) {
            return;
        }

        foreach ($link['occurrences'] as $occurrence) {
            if (empty($occurrence['post_id'])) {
                continue;
            }
            $this->replace_url_in_post((int) $occurrence['post_id'], $normalized_url, $short_url);
        }
    }

    private function replace_url_in_post($post_id, $normalized_original_url, $short_url) {
        $post = get_post($post_id);
        if (!$post || empty($post->post_content) || stripos($post->post_content, 'href=') === false) {
            return;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $post->post_content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        if (!$loaded) {
            return;
        }

        $links = $dom->getElementsByTagName('a');
        $changed = false;

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if ($this->repository->normalize_url($href) !== $normalized_original_url) {
                continue;
            }
            $link->setAttribute('href', $short_url);
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        $html = $dom->saveHTML($dom->documentElement);
        $html = preg_replace('/^<div>|<\/div>$/', '', $html);

        $this->is_processing = true;
        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $html,
        ));
        $this->is_processing = false;
    }

    private function replace_url_in_post_exact($post_id, $from_url, $to_url) {
        $post = get_post($post_id);
        if (!$post || empty($post->post_content) || stripos($post->post_content, 'href=') === false) {
            return;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $post->post_content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        if (!$loaded) {
            return;
        }

        $links = $dom->getElementsByTagName('a');
        $changed = false;
        $normalized_from = $this->repository->normalize_url($from_url);

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if ($this->repository->normalize_url($href) !== $normalized_from) {
                continue;
            }
            $link->setAttribute('href', $to_url);
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        $html = $dom->saveHTML($dom->documentElement);
        $html = preg_replace('/^<div>|<\/div>$/', '', $html);

        $this->is_processing = true;
        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $html,
        ));
        $this->is_processing = false;
    }
}
