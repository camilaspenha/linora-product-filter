<?php
if ( ! defined('ABSPATH') ) exit;

// Garante que temos os atributos
if ( ! isset($linora_pf_atts) || ! is_array($linora_pf_atts) ) {
    return;
}

// Recebe atributos do shortcode
$taxonomy = $linora_pf_atts['taxonomy'] ?? 'product_cat';
$title    = $linora_pf_atts['title'] ?? '';

// Verifica se a taxonomia existe
if ( ! function_exists('taxonomy_exists') || ! taxonomy_exists($taxonomy) ) {
    return;
}

$include_slugs = [];

if ( ! empty($linora_pf_atts['include']) ) {
    $include_slugs = array_map('sanitize_title', explode(',', $linora_pf_atts['include']));
}

$args = [
    'taxonomy'   => $taxonomy,
    'hide_empty' => true,
];

if ( ! empty($include_slugs) ) {
    $args['slug'] = $include_slugs;
}

$terms = get_terms($args);

// Garante a ordem definida no include
if ( ! empty($include_slugs) && ! is_wp_error($terms) ) {
    $ordered = [];
    foreach ($include_slugs as $slug) {
        foreach ($terms as $t) {
            if ($t->slug === $slug) {
                $ordered[] = $t;
                break;
            }
        }
    }
    $terms = $ordered;
}

if ( empty($terms) || is_wp_error($terms) ) {
    return;
}

// Pega filtros ativos
$active = function_exists('linora_pf_get_active_filters')
    ? linora_pf_get_active_filters()
    : [];

// Verifica se existe qualquer filtro ativo (para mostrar botão limpar)
// Verifica se existe qualquer filtro ativo (taxonomia OU preço)
$has_any_filter = false;

// 1) Taxonomias
if ( ! empty($active) ) {
    foreach ($active as $tax => $vals) {
        if (!empty($vals)) {
            $has_any_filter = true;
            break;
        }
    }
}

// 2) Preço
if ( ! $has_any_filter && function_exists('linora_pf_get_active_price_range') ) {
    if ( linora_pf_get_active_price_range() !== null ) {
        $has_any_filter = true;
    }
}


echo '<div class="linora-product-filter">';

// Botão limpar filtros (usa a função nova e segura)
if ( $has_any_filter && function_exists('linora_pf_get_clear_filters_url') ) {
    echo '<a class="linora-clear-filters" href="' . esc_url( linora_pf_get_clear_filters_url() ) . '">🧹 Limpar filtros</a>';
}

// ===============================
// FILTRO DE PREÇO
// ===============================
if ( function_exists('linora_pf_get_price_ranges') && function_exists('linora_pf_get_active_price_range') ) {

    $ranges = linora_pf_get_price_ranges();
    $active_price = linora_pf_get_active_price_range();

    echo '<div class="linora-price-filter">';
    echo '<h4>Filtrar por Preço</h4>';
    echo '<ul>';

    foreach ($ranges as $key => $range) {

        $is_active = ($active_price === $key);

        // Conta produtos nessa faixa respeitando outros filtros
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'     => '_price',
                    'value'   => [$range['min'], $range['max']],
                    'compare' => 'BETWEEN',
                    'type'    => 'NUMERIC',
                ]
            ],
        ];

        // Aplica filtros de taxonomia no contador
        if ( function_exists('linora_pf_get_active_filters') && function_exists('linora_pf_build_tax_query') ) {
            $filters = linora_pf_get_active_filters();
            if ( ! empty($filters) ) {
                $tax_query = linora_pf_build_tax_query($filters);
                if ( ! empty($tax_query) ) {
                    $args['tax_query'] = $tax_query;
                }
            }
        }

        // Mantém busca
        if ( get_query_var('s') ) {
            $args['s'] = get_query_var('s');
        }

        $q = new WP_Query($args);
        $count = (int) $q->found_posts;

        if ($count === 0) continue;

        // Monta URL
        $query = $_GET;
        if ( $is_active ) {
            unset($query['price_range']);
        } else {
            $query['price_range'] = $key;
        }

        // Base URL atual
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = $_SERVER['HTTP_HOST'];
        $uri    = $_SERVER['REQUEST_URI'];
        $base   = strtok($scheme . $host . $uri, '?');

        $url = $base;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        echo '<li>';
        echo '<label style="cursor:pointer;">';
        echo '<input type="radio" name="linora_price" ' . checked($is_active, true, false) . ' onclick="window.location.href=\'' . esc_url($url) . '\'" />';
        echo ' ' . esc_html($range['label']) . ' (' . $count . ')';
        echo '</label>';
        echo '</li>';
    }

    echo '</ul>';
    echo '</div>';
}

// Título
if ( ! empty($title) ) {
    echo '<h4>' . esc_html($title) . '</h4>';
}

echo '<ul>';

foreach ($terms as $term) {

    if ( ! isset($term->slug) ) continue;

    $active_slugs = $active[$taxonomy] ?? [];
    if ( ! is_array($active_slugs) ) {
        $active_slugs = [];
    }

    $is_active = in_array($term->slug, $active_slugs, true);

    // Conta produtos se aplicar esse termo
    if ( function_exists('linora_pf_count_for_term') ) {
        $count = linora_pf_count_for_term($taxonomy, $term->slug);
    } else {
        $count = 0;
    }

    if ($count === 0) {
        continue;
    }

    // Monta nova query preservando outros filtros
    $query = $_GET;
    if ( ! is_array($query) ) {
        $query = [];
    }

    if ($is_active) {
        // Remove
        $new = array_diff($active_slugs, [$term->slug]);
    } else {
        // Adiciona
        $new = array_unique(array_merge($active_slugs, [$term->slug]));
    }

    if (!empty($new)) {
        $query[$taxonomy] = implode(',', $new);
    } else {
        unset($query[$taxonomy]);
    }

    // Monta URL final baseado na URL atual real (sem quebrar busca nem subpasta)
    if ( isset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']) ) {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = $_SERVER['HTTP_HOST'];
        $uri    = $_SERVER['REQUEST_URI'];

        $current_url = $scheme . $host . $uri;
        $base = strtok($current_url, '?');
    } else {
        $base = home_url('/');
    }

    if (!empty($query)) {
        $url = $base . '?' . http_build_query($query);
    } else {
        $url = $base;
    }

    echo '<li>';
    echo '<label style="cursor:pointer;">';
    echo '<input type="checkbox" ' . checked($is_active, true, false) . ' onclick="window.location.href=\'' . esc_url($url) . '\'" />';
    echo ' ' . esc_html($term->name) . ' (' . intval($count) . ')';
    echo '</label>';
    echo '</li>';
}

echo '</ul>';
echo '</div>';
