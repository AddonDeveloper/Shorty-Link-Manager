<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSL_Links_Page {
    private $repository;
    private $scanner;
    private $processor;

    public function __construct(WPSL_Link_Repository $repository, WPSL_Link_Scanner $scanner, WPSL_Link_Processor $processor) {
        $this->repository = $repository;
        $this->scanner = $scanner;
        $this->processor = $processor;
    }

    public function init() {
        add_action('admin_menu', array($this, 'add_submenu'));
        add_action('admin_post_wpsl_scan_links', array($this, 'handle_scan_links'));
        add_action('admin_post_wpsl_shorten_single_link', array($this, 'handle_shorten_single_link'));
        add_action('admin_post_wpsl_process_pending_links', array($this, 'handle_process_pending_links'));
        add_action('admin_post_wpsl_restore_single_link', array($this, 'handle_restore_single_link'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_wpsl_process_next_batch', array($this, 'ajax_process_next_batch'));
        add_action('wp_ajax_wpsl_get_progress', array($this, 'ajax_get_progress'));
    }

    public function add_submenu() {
        add_submenu_page(
            'shorty-link-manager',
            __('Links', 'shorty-link-manager'),
            __('Links', 'shorty-link-manager'),
            'manage_options',
            'shorty-link-manager-links',
            array($this, 'render_page')
        );
    }

    public function enqueue_assets($hook) {
        if ('shorty-link-manager_page_shorty-link-manager-links' !== $hook) {
            return;
        }

        wp_enqueue_script(
            'wpsl-links-admin',
            WPSL_PLUGIN_URL . 'assets/js/links-admin.js',
            array(),
            WPSL_VERSION,
            true
        );

        $provider = wpsl_get_active_provider();

        wp_localize_script('wpsl-links-admin', 'wpslLinksAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpsl_links_ajax'),
            'i18n' => array(
                'working' => __('Automatic processing is running…', 'shorty-link-manager'),
                'waiting' => __('Rate limit reached. Try again after the next minute window.', 'shorty-link-manager'),
                'done' => __('Finished. All pending links have been processed.', 'shorty-link-manager'),
                'reloading' => __('Finished. Reloading the page…', 'shorty-link-manager'),
                'waitingReload' => __('Rate limit reached. Reloading the page with the latest results…', 'shorty-link-manager'),
                'buttonIdle' => __('Start automatic batch processing', 'shorty-link-manager'),
                /* translators: 1: provider name, 2: remaining API calls in the current minute. */
                'rateInfo' => __('Current provider: %1$s | Remaining calls in this minute: %2$d', 'shorty-link-manager'),
                'providerName' => $provider ? $provider->get_name() : '',
                'error' => __('An unexpected error occurred during processing.', 'shorty-link-manager'),
            ),
        ));
    }

    public function handle_scan_links() {
        $this->assert_access();
        check_admin_referer('wpsl_scan_links');

        $result = $this->scanner->scan_posts();
        $message = sprintf(
            /* translators: 1: number of posts/pages scanned, 2: number of external link occurrences found, 3: number of unique links stored. */
            __('Scan completed. %1$d posts/pages scanned, %2$d external link occurrences found, %3$d unique links stored.', 'shorty-link-manager'),
            (int) $result['posts_scanned'],
            (int) $result['occurrences_found'],
            (int) $result['unique_links']
        );

        wp_safe_redirect(add_query_arg(array(
            'page' => 'shorty-link-manager-links',
            'wpsl_notice' => 'success',
            'message' => rawurlencode($message),
        ), admin_url('admin.php')));
        exit;
    }

    public function handle_shorten_single_link() {
        $this->assert_access();
        $normalized_url = isset($_GET['link']) ? sanitize_text_field(wp_unslash($_GET['link'])) : '';
        check_admin_referer('wpsl_shorten_link_' . $normalized_url);

        $provider = wpsl_get_active_provider();
        if (!$provider) {
            $this->redirect_notice('error', __('No provider configured.', 'shorty-link-manager'));
        }

        $result = $this->processor->process_single_link($normalized_url, $provider);
        if (is_wp_error($result)) {
            $this->redirect_notice('error', $result->get_error_message());
        }

        $this->redirect_notice('success', __('Link shortened successfully and applied to its posts.', 'shorty-link-manager'));
    }

    public function handle_process_pending_links() {
        $this->assert_access();
        check_admin_referer('wpsl_process_pending_links');

        $provider = wpsl_get_active_provider();
        if (!$provider) {
            $this->redirect_notice('error', __('No provider configured.', 'shorty-link-manager'));
        }

        $result = $this->processor->process_pending_batch($provider);
        $type = $result['errors'] > 0 && $result['success'] === 0 ? 'error' : 'success';
        $message = sprintf(
            /* translators: 1: processed links count, 2: successful links count, 3: error count, 4: remaining pending links count, 5: batch result message. */
            __('Processed: %1$d, successful: %2$d, errors: %3$d, remaining pending: %4$d. %5$s', 'shorty-link-manager'),
            (int) $result['processed'],
            (int) $result['success'],
            (int) $result['errors'],
            (int) $result['remaining'],
            $result['message']
        );

        $this->redirect_notice($type, $message);
    }

    public function handle_restore_single_link() {
        $this->assert_access();
        $normalized_url = isset($_GET['link']) ? sanitize_text_field(wp_unslash($_GET['link'])) : '';
        check_admin_referer('wpsl_restore_link_' . $normalized_url);

        $result = $this->processor->restore_single_link($normalized_url);
        if (is_wp_error($result)) {
            $this->redirect_notice('error', $result->get_error_message());
        }

        $this->redirect_notice('success', __('Original URL restored in its posts.', 'shorty-link-manager'));
    }

    public function ajax_process_next_batch() {
        $this->assert_ajax_access();

        $provider = wpsl_get_active_provider();
        if (!$provider) {
            wp_send_json_error(array('message' => __('No provider configured.', 'shorty-link-manager')), 400);
        }

        $result = $this->processor->process_pending_batch($provider);
        $snapshot = $this->processor->get_progress_snapshot($provider);
        wp_send_json_success(array_merge($result, array('snapshot' => $snapshot)));
    }

    public function ajax_get_progress() {
        $this->assert_ajax_access();

        $provider = wpsl_get_active_provider();
        $snapshot = $this->processor->get_progress_snapshot($provider ?: null);
        wp_send_json_success($snapshot);
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading sanitized list filter from the admin URL.
        $status_filter = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'all';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading sanitized admin notice query args for display only.
        $wpsl_notice = isset($_GET['wpsl_notice']) ? sanitize_key(wp_unslash($_GET['wpsl_notice'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading sanitized admin notice query args for display only.
        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';

        if ('errors' === $status_filter) {
            $status_filter = 'error';
        }

        $links = $this->repository->get_links_by_status($status_filter);
        uasort($links, function ($a, $b) {
            return strcmp($b['updated_at'], $a['updated_at']);
        });

        $provider = wpsl_get_active_provider();
        $counts = $this->repository->get_status_totals();
        $progress = $this->processor->get_progress_snapshot($provider ?: null);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Shorty Link Manager → Links', 'shorty-link-manager'); ?></h1>
            <p><?php esc_html_e('Scan older posts and pages for outgoing links, shorten them in safe batches and write the short URLs back into the matching posts.', 'shorty-link-manager'); ?></p>

            <?php if ($wpsl_notice) : ?>
                <div class="notice notice-<?php echo esc_attr('success' === $wpsl_notice ? 'success' : 'error'); ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #ccd0d4;padding:16px;margin:16px 0;max-width:980px;">
                <h2 style="margin-top:0;"><?php esc_html_e('Bulk processing', 'shorty-link-manager'); ?></h2>
                <p><?php esc_html_e('The automatic runner continues batch by batch until all pending links are processed or the provider rate limit is reached.', 'shorty-link-manager'); ?></p>
                <div style="position:relative;height:24px;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:4px;overflow:hidden;">
                    <div id="wpsl-progress-bar" style="height:24px;width:<?php echo esc_attr($progress['progress_percent']); ?>%;background:#2271b1;"></div>
                    <div id="wpsl-progress-label" style="position:absolute;left:10px;top:3px;color:#fff;font-weight:600;"><?php echo esc_html($progress['progress_percent']); ?>%</div>
                </div>
                <p id="wpsl-progress-summary" style="margin:12px 0 6px;">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: 1: total links, 2: shortened links, 3: pending links, 4: error links. */
                            __('Total: %1$d | Shortened: %2$d | Pending: %3$d | Errors: %4$d', 'shorty-link-manager'),
                            (int) $progress['total'],
                            (int) $progress['shortened'],
                            (int) $progress['pending'],
                            (int) $progress['error']
                        )
                    );
                    ?>
                </p>
                <p id="wpsl-progress-message" style="margin:6px 0 14px; color:#50575e;">
                    <?php
                    echo esc_html(
                        $progress['last_scan_at']
                            ? sprintf(
                                /* translators: %s: last scan timestamp. */
                                __('Last scan: %s', 'shorty-link-manager'),
                                $progress['last_scan_at']
                            )
                            : __('No scan has been run yet.', 'shorty-link-manager')
                    );
                    ?>
                </p>
                <p>
                    <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpsl_scan_links'), 'wpsl_scan_links')); ?>"><?php esc_html_e('Scan links', 'shorty-link-manager'); ?></a>
                    <button type="button" class="button button-primary" id="wpsl-auto-run-button" <?php disabled(empty($provider) || $progress['pending'] < 1); ?>><?php esc_html_e('Start automatic batch processing', 'shorty-link-manager'); ?></button>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpsl_process_pending_links'), 'wpsl_process_pending_links')); ?>"><?php esc_html_e('Process one safe batch', 'shorty-link-manager'); ?></a>
                </p>
                <?php if ($provider) : ?>
                    <p><small id="wpsl-rate-limit-info">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: 1: provider name, 2: remaining API calls in the current minute. */
                                __('Current provider: %1$s | Remaining calls in this minute: %2$d', 'shorty-link-manager'),
                                $provider->get_name(),
                                (int) $progress['rate_limit_remaining']
                            )
                        );
                        ?>
                    </small></p>
                <?php else : ?>
                    <p><small><?php esc_html_e('Configure a provider and API key in Settings before shortening links.', 'shorty-link-manager'); ?></small></p>
                <?php endif; ?>
            </div>

            <p>
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'shorty-link-manager-links', 'status' => 'all'), admin_url('admin.php'))); ?>">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d: total number of links. */
                            __('All (%d)', 'shorty-link-manager'),
                            $counts['all']
                        )
                    );
                    ?>
                </a> |
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'shorty-link-manager-links', 'status' => 'pending'), admin_url('admin.php'))); ?>">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d: number of pending links. */
                            __('Pending (%d)', 'shorty-link-manager'),
                            $counts['pending']
                        )
                    );
                    ?>
                </a> |
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'shorty-link-manager-links', 'status' => 'shortened'), admin_url('admin.php'))); ?>">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d: number of shortened links. */
                            __('Shortened (%d)', 'shorty-link-manager'),
                            $counts['shortened']
                        )
                    );
                    ?>
                </a> |
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'shorty-link-manager-links', 'status' => 'error'), admin_url('admin.php'))); ?>">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d: number of links with errors. */
                            __('Errors (%d)', 'shorty-link-manager'),
                            $counts['error']
                        )
                    );
                    ?>
                </a>
            </p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Original URL', 'shorty-link-manager'); ?></th>
                        <th><?php esc_html_e('Short URL', 'shorty-link-manager'); ?></th>
                        <th><?php esc_html_e('Status', 'shorty-link-manager'); ?></th>
                        <th><?php esc_html_e('Occurrences', 'shorty-link-manager'); ?></th>
                        <th><?php esc_html_e('Source', 'shorty-link-manager'); ?></th>
                        <th><?php esc_html_e('Provider', 'shorty-link-manager'); ?></th>
                        <th><?php esc_html_e('Last processed', 'shorty-link-manager'); ?></th>
                        <th><?php esc_html_e('Action', 'shorty-link-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($links)) : ?>
                        <tr><td colspan="8"><?php esc_html_e('No links found yet. Start with “Scan links”.', 'shorty-link-manager'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($links as $normalized_url => $link) : ?>
                            <?php $occurrences = isset($link['occurrences']) && is_array($link['occurrences']) ? array_values($link['occurrences']) : array(); ?>
                            <tr>
                                <td style="max-width:320px; word-break:break-word;"><a href="<?php echo esc_url($link['original_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($link['original_url']); ?></a></td>
                                <td style="max-width:240px; word-break:break-word;">
                                    <?php if (!empty($link['short_url'])) : ?>
                                        <a href="<?php echo esc_url($link['short_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($link['short_url']); ?></a>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html(ucfirst($link['status'])); ?></strong>
                                    <?php if (!empty($link['error_message'])) : ?>
                                        <br><span style="color:#b32d2e"><?php echo esc_html($link['error_message']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(count($occurrences)); ?></td>
                                <td>
                                    <?php if (!empty($occurrences[0])) : ?>
                                        <a href="<?php echo esc_url(get_edit_post_link($occurrences[0]['post_id'], 'url')); ?>"><?php echo esc_html($occurrences[0]['post_title']); ?></a>
                                        <?php if (count($occurrences) > 1) : ?>
                                            <br><small>
                                                <?php
                                                echo esc_html(
                                                    sprintf(
                                                        /* translators: %d: number of additional link occurrences. */
                                                        __('+ %d more', 'shorty-link-manager'),
                                                        count($occurrences) - 1
                                                    )
                                                );
                                                ?>
                                            </small>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?php echo !empty($link['provider']) ? esc_html($link['provider']) : '—'; ?></td>
                                <td><?php echo !empty($link['last_processed_at']) ? esc_html($link['last_processed_at']) : '—'; ?></td>
                                <td>
                                    <?php if ('pending' === $link['status'] || 'error' === $link['status']) : ?>
                                        <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpsl_shorten_single_link&link=' . rawurlencode($normalized_url)), 'wpsl_shorten_link_' . $normalized_url)); ?>"><?php esc_html_e('Shorten now', 'shorty-link-manager'); ?></a>
                                    <?php elseif ('shortened' === $link['status']) : ?>
                                        <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpsl_restore_single_link&link=' . rawurlencode($normalized_url)), 'wpsl_restore_link_' . $normalized_url)); ?>"><?php esc_html_e('Restore original', 'shorty-link-manager'); ?></a>
                                    <?php else : ?>
                                        <span>—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function assert_access() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'shorty-link-manager'));
        }
    }

    private function assert_ajax_access() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'shorty-link-manager')), 403);
        }

        check_ajax_referer('wpsl_links_ajax', 'nonce');
    }

    private function redirect_notice($type, $message) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'shorty-link-manager-links',
            'wpsl_notice' => $type,
            'message' => rawurlencode($message),
        ), admin_url('admin.php')));
        exit;
    }
}
