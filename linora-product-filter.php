<?php
/*
Plugin Name: Linora Product Filter
Description: A simple product filter plugin for Linora WooCommerce store.
Version: 1.0
Author: Linora
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Retorna a URL atual removendo APENAS filtros de taxonomia
 * Mantém busca (?s=), orderby, etc
 */
function linora_pf_get_clear_filters_url() {

    // Garante que temos essas vars
    if ( ! isset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']) ) {
        return home_url('/');
    }

    $scheme = is_ssl() ? 'https://' : 'http://';
    $host   = $_SERVER['HTTP_HOST'];
    $uri    = $_SERVER['REQUEST_URI'];

    $url = $scheme . $host . $uri;

    // Separa path e query
    $parts = explode('?', $url, 2);

    $base  = $parts[0];
    $query = [];

    if ( isset($parts[1]) ) {
        parse_str($parts[1], $query);
    }

    foreach ($query as $key => $value) {
        if ( (function_exists('taxonomy_exists') && taxonomy_exists($key)) || $key === 'price_range' ) {
            unset($query[$key]);
        }
    }

    // Reconstrói a URL
    if ( ! empty($query) ) {
        return $base . '?' . http_build_query($query);
    }

    return $base;
}

/**
 * Lê filtros ativos da URL:
 * ?product_cat=slug1,slug2&pa_material=slug3
 */
function linora_pf_get_active_filters() {
    $filters = [];

    if ( empty($_GET) ) {
        return $filters;
    }

    foreach ($_GET as $key => $value) {
        if ( ! empty($value) && function_exists('taxonomy_exists') && taxonomy_exists($key) ) {
            $slugs = array_filter(array_map('sanitize_title', explode(',', (string) $value)));
            if (!empty($slugs)) {
                $filters[$key] = $slugs;
            }
        }
    }

    return $filters;
}

/**
 * Monta tax_query a partir dos filtros ativos
 */
function linora_pf_build_tax_query($filters) {
    $tax_query = [];

    if ( empty($filters) || ! is_array($filters) ) {
        return $tax_query;
    }

    foreach ($filters as $tax => $terms) {
        if ( empty($terms) ) continue;

        $tax_query[] = [
            'taxonomy' => $tax,
            'field'    => 'slug',
            'terms'    => $terms,
            'operator' => 'AND',
        ];
    }

    if ( count($tax_query) > 1 ) {
        $tax_query['relation'] = 'AND';
    }

    return $tax_query;
}

/**
 * Conta quantos produtos existem se um termo for aplicado
 */
function linora_pf_count_for_term($taxonomy, $term_slug) {

    // Se WooCommerce não estiver ativo, não tenta nada
    if ( ! class_exists('WP_Query') ) {
        return 0;
    }

    $active = linora_pf_get_active_filters();

    // Simula clique nesse termo
    $current = $active[$taxonomy] ?? [];

    if (!in_array($term_slug, $current, true)) {
        $current[] = $term_slug;
    }

    $active[$taxonomy] = $current;

    $tax_query = linora_pf_build_tax_query($active);

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 1, // só precisamos do total
    ];

    // Aplica filtro de preço ativo no contador
    $range_key = linora_pf_get_active_price_range();
    if ( $range_key ) {
        $ranges = linora_pf_get_price_ranges();
        $range  = $ranges[$range_key];

        $args['meta_query'][] = [
            'key'     => '_price',
            'value'   => [$range['min'], $range['max']],
            'compare' => 'BETWEEN',
            'type'    => 'NUMERIC',
        ];
    }

    if ( ! empty($tax_query) ) {
        $args['tax_query'] = $tax_query;
    }

    // Mantém busca
    if ( get_query_var('s') ) {
        $args['s'] = get_query_var('s');
    }

    $q = new WP_Query($args);

    if ( is_wp_error($q) ) {
        return 0;
    }

    return (int) $q->found_posts;
}

/**
 * Shortcode principal
 * Uso:
 * [linora_product_filter taxonomy="product_cat"]
 * [linora_product_filter taxonomy="pa_material"]
 */
function linora_product_filter_shortcode( $atts ) {

    $atts = shortcode_atts( array(
        'taxonomy' => 'product_cat',
        'title'    => '',
    ), $atts, 'linora_product_filter' );

    // Disponibiliza $atts para o template
    $linora_pf_atts = $atts;

    ob_start();

    $template = plugin_dir_path( __FILE__ ) . 'templates/filter-template.php';

    if ( file_exists($template) ) {
        include $template;
    } else {
        echo '<!-- Linora Product Filter: template não encontrado -->';
    }

    return ob_get_clean();
}
add_shortcode( 'linora_product_filter', 'linora_product_filter_shortcode' );

/**
 * Enfileira CSS
 */
function linora_product_filter_enqueue_scripts() {
    wp_enqueue_style(
        'linora-product-filter-style',
        plugin_dir_url( __FILE__ ) . 'css/linora-product-filter.css',
        [],
        '1.0'
    );
}
add_action( 'wp_enqueue_scripts', 'linora_product_filter_enqueue_scripts' );

/**
 * Aplica os filtros da URL na query principal do WooCommerce
 * Força comportamento AND entre os filtros
 */
/**
 * Aplica os filtros da URL (taxonomias + preço) na query principal do WooCommerce
 */
add_action('pre_get_posts', function($q) {

    if ( is_admin() || ! $q->is_main_query() ) {
        return;
    }

    if ( ! ( is_shop() || is_product_category() || is_search() || is_post_type_archive('product') ) ) {
        return;
    }

    // --- FILTROS DE TAXONOMIA ---
    if ( function_exists('linora_pf_get_active_filters') && function_exists('linora_pf_build_tax_query') ) {
        $filters = linora_pf_get_active_filters();

        if ( ! empty($filters) ) {
            $tax_query = linora_pf_build_tax_query($filters);
            if ( ! empty($tax_query) ) {
                $q->set('tax_query', $tax_query);
            }
        }
    }

    // --- FILTRO DE PREÇO ---
    if ( function_exists('linora_pf_get_active_price_range') ) {

        $range_key = linora_pf_get_active_price_range();

        if ( $range_key ) {
            $ranges = linora_pf_get_price_ranges();
            $range  = $ranges[$range_key];

            $meta_query = (array) $q->get('meta_query');

            $meta_query[] = [
                'key'     => '_price',
                'value'   => [$range['min'], $range['max']],
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            ];

            $q->set('meta_query', $meta_query);
        }
    }
});


/**
 * Retorna as faixas de preço configuradas
 */
function linora_pf_get_price_ranges() {
    return [
        '0-100'   => ['min' => 0,   'max' => 100, 'label' => 'Até R$ 100'],
        '101-200' => ['min' => 101, 'max' => 200, 'label' => 'R$ 101 – R$ 200'],
        '201-300' => ['min' => 201, 'max' => 300, 'label' => 'R$ 201 – R$ 300'],
        '301-400' => ['min' => 301, 'max' => 400, 'label' => 'R$ 301 – R$ 400'],
    ];
}

/**
 * Retorna a faixa de preço ativa ou null
 */
function linora_pf_get_active_price_range() {
    if ( empty($_GET['price_range']) ) {
        return null;
    }

    $ranges = linora_pf_get_price_ranges();
    $key = sanitize_text_field($_GET['price_range']);

    if ( isset($ranges[$key]) ) {
        return $key;
    }

    return null;
}
