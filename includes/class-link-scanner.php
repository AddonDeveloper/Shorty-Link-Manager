<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSL_Link_Scanner {
    private $repository;

    public function __construct(WPSL_Link_Repository $repository) {
        $this->repository = $repository;
    }

    public function scan_posts() {
        $posts = get_posts(array(
            'post_type' => array('post', 'page'),
            'post_status' => array('publish', 'future', 'draft', 'pending', 'private'),
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => false,
        ));

        $found = 0;

        foreach ($posts as $post) {
            $found += $this->scan_single_post($post);
        }

        update_option('wpsl_last_scan_at', current_time('mysql'), false);

        return array(
            'posts_scanned' => count($posts),
            'occurrences_found' => $found,
            'unique_links' => count($this->repository->get_all_links()),
        );
    }

    public function scan_single_post($post) {
        if (empty($post) || empty($post->post_content) || stripos($post->post_content, 'href=') === false) {
            return 0;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $post->post_content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        if (!$loaded) {
            return 0;
        }

        $links = $dom->getElementsByTagName('a');
        $count = 0;

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if (!$this->should_track_url($href)) {
                continue;
            }

            $this->repository->upsert_discovered_link(
                $href,
                $post->ID,
                $post->post_type,
                get_the_title($post),
                $link->textContent
            );
            $count++;
        }

        return $count;
    }

    public function should_track_url($url) {
        $url = trim((string) $url);
        if (empty($url)) {
            return false;
        }

        if (stripos($url, 'mailto:') === 0 || stripos($url, 'tel:') === 0 || stripos($url, 'javascript:') === 0 || strpos($url, '#') === 0) {
            return false;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $link_host = wp_parse_url($url, PHP_URL_HOST);
        if (!$link_host) {
            return false;
        }

        if ($home_host && strtolower($home_host) === strtolower($link_host)) {
            return false;
        }

        $excluded_domains = apply_filters('wpsl_excluded_domains', array('shurli.at'));
        foreach ($excluded_domains as $domain) {
            if (stripos($link_host, $domain) !== false) {
                return false;
            }
        }

        return true;
    }
}
