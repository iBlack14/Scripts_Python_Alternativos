<?php
/** Buscador, resultados y archivo de productos para Elementor. */
if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_Elementor_Product_Explorer_Widget extends \Elementor\Widget_Base {
    public function get_name() { return 'owc_product_explorer'; }
    public function get_title() { return __( 'Buscador y catálogo Odoo', 'odoo-woo-connector' ); }
    public function get_icon() { return 'eicon-products'; }
    public function get_categories() { return [ 'owc-widgets' ]; }
    public function get_style_depends() { return [ 'owc-elementor-widgets' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content', [ 'label' => __( 'Contenido y filtros', 'odoo-woo-connector' ) ] );
        $this->add_control( 'placeholder', [ 'label' => __( 'Texto del buscador', 'odoo-woo-connector' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => '¿Qué producto estás buscando?' ] );
        $this->add_control( 'all_title', [ 'label' => __( 'Título del catálogo', 'odoo-woo-connector' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'Todos los productos' ] );
        $this->add_control( 'per_page', [ 'label' => __( 'Productos por página', 'odoo-woo-connector' ), 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 24, 'min' => 4, 'max' => 96 ] );
        $this->add_responsive_control( 'columns', [ 'label' => __( 'Columnas', 'odoo-woo-connector' ), 'type' => \Elementor\Controls_Manager::SELECT, 'default' => '4', 'tablet_default' => '3', 'mobile_default' => '2', 'options' => [ '2'=>'2', '3'=>'3', '4'=>'4', '5'=>'5', '6'=>'6' ], 'selectors' => [ '{{WRAPPER}} .owc-explorer' => '--owc-columns: {{VALUE}};' ] ] );
        foreach ( [
            'show_search' => 'Mostrar buscador', 'show_categories' => 'Filtro de categorías',
            'show_price' => 'Filtro de precio', 'show_stock' => 'Filtro de stock',
            'show_order' => 'Ordenamiento', 'show_count' => 'Cantidad de resultados',
        ] as $id => $label ) {
            $this->add_control( $id, [ 'label' => __( $label, 'odoo-woo-connector' ), 'type' => \Elementor\Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ] );
        }
        $this->end_controls_section();

        $this->start_controls_section( 'style_colors', [ 'label' => __( 'Colores', 'odoo-woo-connector' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ] );
        $colors = [
            'accent' => [ 'Color principal', '#0a5ca8', '--owc-ex-accent' ],
            'accent_hover' => [ 'Principal al pasar', '#08467f', '--owc-ex-accent-hover' ],
            'title_color' => [ 'Títulos', '#172033', '--owc-ex-title' ],
            'text_color' => [ 'Texto', '#344054', '--owc-ex-text' ],
            'muted_color' => [ 'Texto secundario', '#667085', '--owc-ex-muted' ],
            'background' => [ 'Fondo general', '#ffffff', '--owc-ex-bg' ],
            'sidebar_bg' => [ 'Fondo de filtros', '#f7f8fa', '--owc-ex-side-bg' ],
            'card_bg' => [ 'Fondo de tarjetas', '#ffffff', '--owc-ex-card-bg' ],
            'border_color' => [ 'Bordes', '#e4e7ec', '--owc-ex-border' ],
            'price_color' => [ 'Precio', '#1f2a37', '--owc-ex-price' ],
            'button_text' => [ 'Texto del botón', '#ffffff', '--owc-ex-button-text' ],
        ];
        foreach ( $colors as $id => $data ) {
            $this->add_control( $id, [ 'label' => __( $data[0], 'odoo-woo-connector' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => $data[1], 'selectors' => [ '{{WRAPPER}} .owc-explorer' => $data[2] . ': {{VALUE}};' ] ] );
        }
        $this->end_controls_section();
    }

    private function value( $key, $default = '' ) {
        return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default;
    }

    /**
     * Resuelve tanto el slug real de WooCommerce como el alias legible que usa
     * el explorador (generado desde el nombre de la categoría).
     *
     * WooCommerce puede añadir el slug de los padres para evitar duplicados,
     * por ejemplo `alambres-gfperu-materiales-de-construccion-...`. En ese
     * caso la URL pública `?categoria=alambres-gfperu` debe seguir funcionando.
     */
    private function category_id_from_value( $value ) {
        if ( ! $value ) return 0;
        if ( is_numeric( $value ) ) return absint( $value );

        $slug = sanitize_title( $value );
        $term = get_term_by( 'slug', $slug, 'product_cat' );
        if ( $term && ! is_wp_error( $term ) ) return (int) $term->term_id;

        $terms = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ] );
        if ( is_wp_error( $terms ) ) return 0;

        foreach ( $terms as $candidate ) {
            if ( sanitize_title( $candidate->name ) === $slug ) {
                return (int) $candidate->term_id;
            }
        }
        return 0;
    }

    protected function render() {
        if ( ! class_exists( 'WooCommerce' ) ) return;
        $s = $this->get_settings_for_display();
        $term = is_tax( 'product_cat' ) ? get_queried_object() : null;
        $keyword = $this->value( 's', get_search_query() );
        // Una búsqueda escrita siempre comienza en todo el catálogo; la categoría
        // solo se aplica cuando el usuario la selecciona explícitamente.
        $default_category = $keyword ? 0 : ( $term && ! is_wp_error( $term ) ? $term->term_id : 0 );
        $category_value = $this->value( 'categoria', $this->value( 'owc_cat', $default_category ) );
        $category = $this->category_id_from_value( $category_value );
        $min = max( 0, (float) $this->value( 'owc_min', 0 ) );
        $max = max( 0, (float) $this->value( 'owc_max', 0 ) );
        $stock = $this->value( 'owc_stock' ) === '1';
        $order = $this->value( 'owc_order', 'relevance' );
        $paged = max( 1, absint( $this->value( 'product-page', 1 ) ) );

        $args = [ 'post_type'=>'product', 'post_status'=>'publish', 'posts_per_page'=>absint( $s['per_page'] ), 'paged'=>$paged, 's'=>$keyword, 'tax_query'=>[], 'meta_query'=>WC()->query->get_meta_query() ];
        if ( $category ) $args['tax_query'][] = [ 'taxonomy'=>'product_cat', 'field'=>'term_id', 'terms'=>$category, 'include_children'=>true ];
        if ( $min || $max ) $args['meta_query'][] = [ 'key'=>'_price', 'value'=>[ $min, $max ?: PHP_INT_MAX ], 'compare'=>'BETWEEN', 'type'=>'NUMERIC' ];
        if ( $stock ) $args['meta_query'][] = [ 'key'=>'_stock_status', 'value'=>'instock' ];
        if ( $order === 'price' || $order === 'price-desc' ) { $args['meta_key'] = '_price'; $args['orderby'] = 'meta_value_num'; $args['order'] = $order === 'price' ? 'ASC' : 'DESC'; }
        elseif ( $order === 'newest' ) { $args['orderby']='date'; $args['order']='DESC'; }
        elseif ( ! $keyword ) { $args['orderby'] = 'menu_order title'; $args['order'] = 'ASC'; }
        $query = new \WP_Query( $args );

        // En búsquedas, calcular categorías relacionadas con los resultados.
        $search_facet_ids = [];
        $search_facet_counts = [];
        if ( $keyword ) {
            $facet_args = $args;
            $facet_args['posts_per_page'] = -1;
            $facet_args['paged'] = 1;
            $facet_args['fields'] = 'ids';
            unset( $facet_args['tax_query'] );
            foreach ( get_posts( $facet_args ) as $facet_product_id ) {
                $product_terms = wp_get_post_terms( $facet_product_id, 'product_cat', [ 'fields'=>'ids' ] );
                if ( is_wp_error( $product_terms ) ) continue;
                $seen_for_product = [];
                foreach ( $product_terms as $facet_term_id ) {
                    $lineage = array_merge( [ (int) $facet_term_id ], array_map( 'intval', get_ancestors( $facet_term_id, 'product_cat', 'taxonomy' ) ) );
                    foreach ( $lineage as $lineage_id ) $seen_for_product[ $lineage_id ] = true;
                }
                foreach ( array_keys( $seen_for_product ) as $lineage_id ) {
                    $search_facet_ids[ $lineage_id ] = true;
                    $search_facet_counts[ $lineage_id ] = ( $search_facet_counts[ $lineage_id ] ?? 0 ) + 1;
                }
            }
        }

        $selected_term = $category ? get_term( $category, 'product_cat' ) : null;
        $title = $keyword ? sprintf( 'Resultados para “%s”', $keyword ) : ( $selected_term && ! is_wp_error( $selected_term ) ? $selected_term->name : ( $term && ! is_wp_error( $term ) ? $term->name : $s['all_title'] ) );
        // En una plantilla Search de Elementor conservamos su URL y diseño.
        // En otros contextos, el formulario usa la tienda como destino.
        $shop = is_search() ? home_url( '/' ) : ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) );
        $default_cat = (int) get_option( 'default_product_cat', 0 );
        $top_categories = get_terms( [ 'taxonomy'=>'product_cat', 'hide_empty'=>false, 'parent'=>0, 'exclude'=>$default_cat ? [ $default_cat ] : [] ] );
        if ( is_wp_error( $top_categories ) ) $top_categories = [];
        if ( $keyword ) $top_categories = array_values( array_filter( $top_categories, function( $top ) use ( $search_facet_ids ) { return isset( $search_facet_ids[ (int) $top->term_id ] ); } ) );
        $category_letters = [];
        foreach ( $top_categories as $letter_term ) {
            $first = mb_strtoupper( mb_substr( remove_accents( $letter_term->name ), 0, 1 ) );
            if ( preg_match( '/[A-Z0-9]/', $first ) ) $category_letters[] = $first;
        }
        $category_letters = array_values( array_unique( $category_letters ) );
        sort( $category_letters );
        $branch_root = $selected_term && ! is_wp_error( $selected_term ) ? $selected_term : null;
        while ( $branch_root && $branch_root->parent ) {
            $parent_term = get_term( $branch_root->parent, 'product_cat' );
            if ( ! $parent_term || is_wp_error( $parent_term ) ) break;
            $branch_root = $parent_term;
        }
        $branch_root_id = $branch_root ? (int) $branch_root->term_id : 0;
        $nav_items = $branch_root_id ? get_terms( [ 'taxonomy'=>'product_cat', 'hide_empty'=>false, 'parent'=>$branch_root_id ] ) : [];
        if ( is_wp_error( $nav_items ) ) $nav_items = [];
        if ( $keyword && $branch_root_id ) $nav_items = array_values( array_filter( $nav_items, function( $item ) use ( $search_facet_ids ) { return isset( $search_facet_ids[ (int) $item->term_id ] ); } ) );
        if ( $keyword && ! $branch_root_id && $search_facet_ids ) {
            $nav_items = get_terms( [ 'taxonomy'=>'product_cat', 'hide_empty'=>false, 'include'=>array_map( 'intval', array_keys( $search_facet_ids ) ) ] );
            if ( is_wp_error( $nav_items ) ) $nav_items = [];
            $nav_items = array_values( array_filter( $nav_items, function( $item ) { return (int) $item->parent > 0; } ) );
        }
        $count_terms = get_terms( [ 'taxonomy'=>'product_cat', 'hide_empty'=>false ] );
        $count_map = [];
        $child_map = [];
        $term_map = [];
        if ( ! is_wp_error( $count_terms ) ) foreach ( $count_terms as $count_term ) {
            $count_map[ (int) $count_term->term_id ] = (int) $count_term->count;
            $child_map[ (int) $count_term->parent ][] = (int) $count_term->term_id;
            $term_map[ (int) $count_term->term_id ] = $count_term;
        }
        $tree_count = function( $term_id ) use ( &$tree_count, $count_map, $child_map, $keyword, $search_facet_counts ) {
            if ( $keyword ) return $search_facet_counts[ (int) $term_id ] ?? 0;
            $total = $count_map[ (int) $term_id ] ?? 0;
            foreach ( $child_map[ (int) $term_id ] ?? [] as $child_id ) $total += $tree_count( $child_id );
            return $total;
        };
        // Mostrar toda la rama de subcategorías, no únicamente el primer nivel.
        $nav_rows = [];
        $append_nav_branch = function( $term_id, $depth = 0 ) use ( &$append_nav_branch, &$nav_rows, $term_map, $child_map, $keyword, $search_facet_ids ) {
            $term_id = (int) $term_id;
            if ( ! isset( $term_map[ $term_id ] ) ) return;
            if ( $keyword && ! isset( $search_facet_ids[ $term_id ] ) ) return;
            $nav_rows[] = [ 'term' => $term_map[ $term_id ], 'depth' => $depth ];
            foreach ( $child_map[ $term_id ] ?? [] as $descendant_id ) $append_nav_branch( $descendant_id, $depth + 1 );
        };
        foreach ( $nav_items as $nav_root_item ) $append_nav_branch( $nav_root_item->term_id );
        ?>
        <section class="owc-explorer owc-explorer--store" data-owc-explorer="<?php echo esc_attr( $this->get_id() ); ?>" aria-live="polite">
            <nav class="owc-explorer__crumbs" aria-label="Ruta"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Inicio</a><span>›</span><?php echo esc_html( $title ); ?></nav>
            <?php if ( $s['show_search'] === 'yes' ) : ?>
            <form class="owc-explorer__search" method="get" action="<?php echo esc_url( $shop ); ?>">
                <input name="s" type="search" value="<?php echo esc_attr( $keyword ); ?>" placeholder="<?php echo esc_attr( $s['placeholder'] ); ?>">
                <button type="submit" aria-label="Buscar">⌕ <span>Buscar</span></button>
            </form>
            <?php endif; ?>
            <header class="owc-explorer__header"><div><h1><?php echo esc_html( $title ); ?></h1><?php if ( $s['show_count'] === 'yes' ) : ?><p><?php echo esc_html( sprintf( _n( '%s producto', '%s productos', $query->found_posts, 'odoo-woo-connector' ), number_format_i18n( $query->found_posts ) ) ); ?></p><?php endif; ?></div></header>
            <button type="button" class="owc-explorer__mobile-filter" aria-expanded="false">☰ Mostrar filtros</button>
            <div class="owc-explorer__layout">
                <aside class="owc-explorer__filters">
                    <div class="owc-explorer__filters-title"><span>Filtrar por</span><small>Encuentra tu producto</small></div>
                    <form method="get" action="">
                        <?php if ( $keyword ) : ?><input type="hidden" name="s" value="<?php echo esc_attr( $keyword ); ?>"><?php endif; ?>
                        <?php if ( $s['show_categories'] === 'yes' ) : ?><input type="hidden" name="owc_cat" value="<?php echo esc_attr( $category ); ?>"><details class="owc-explorer__category-picker"><summary><small>Categoría</small><b><?php echo esc_html( $branch_root ? $branch_root->name : 'Todas las categorías' ); ?></b></summary><div class="owc-explorer__category-tools"><input type="search" class="owc-explorer__category-search" placeholder="Buscar categoría…" aria-label="Buscar categoría"><div class="owc-explorer__alphabet"><button type="button" class="is-active" data-owc-letter="">Todas</button><?php foreach ( $category_letters as $letter ) : ?><button type="button" data-owc-letter="<?php echo esc_attr( $letter ); ?>"><?php echo esc_html( $letter ); ?></button><?php endforeach; ?></div></div><nav class="owc-explorer__category-tree"><a data-category-name="" class="owc-explorer__category-parent<?php echo $category ? '' : ' is-active'; ?>" href="<?php echo esc_url( add_query_arg( [ 'owc_cat'=>false, 's'=>$keyword, 'post_type'=>'product', 'product-page'=>false ] ) ); ?>">Todas las categorías</a><?php foreach ( $top_categories as $top ) : $normalized_name = mb_strtolower( remove_accents( $top->name ) ); ?><a data-category-name="<?php echo esc_attr( $normalized_name ); ?>" data-owc-category-slug="<?php echo esc_attr( sanitize_title( $top->name ) ); ?>" class="owc-explorer__category-parent<?php echo (int) $top->term_id === $branch_root_id ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'owc_cat'=>$top->term_id, 's'=>$keyword, 'post_type'=>'product', 'product-page'=>false ] ) ); ?>"><?php echo esc_html( $top->name ); ?><span><?php echo esc_html( $tree_count( $top->term_id ) ); ?></span></a><?php endforeach; ?><p class="owc-explorer__category-empty" hidden>No encontramos esa categoría.</p></nav></details><?php if ( $nav_rows ) : ?><details class="owc-explorer__subcategory-box" open><summary>Subcategorías</summary><nav class="owc-explorer__subcats"><?php foreach ( $nav_rows as $nav_row ) : $child = $nav_row['term']; $depth = min( 4, (int) $nav_row['depth'] ); ?><a data-owc-category-slug="<?php echo esc_attr( sanitize_title( $child->name ) ); ?>" data-owc-depth="<?php echo esc_attr( $depth ); ?>" class="<?php echo (int) $child->term_id === $category ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'owc_cat'=>$child->term_id, 's'=>$keyword, 'post_type'=>'product', 'product-page'=>false ] ) ); ?>"><?php echo esc_html( $child->name ); ?><span><?php echo esc_html( $tree_count( $child->term_id ) ); ?></span></a><?php endforeach; ?></nav></details><?php endif; ?><?php endif; ?>
                        <?php if ( $s['show_price'] === 'yes' ) : ?><details><summary>Precio</summary><div class="owc-explorer__prices"><input name="owc_min" type="number" min="0" step="0.01" value="<?php echo esc_attr( $min ?: '' ); ?>" placeholder="Mín."><input name="owc_max" type="number" min="0" step="0.01" value="<?php echo esc_attr( $max ?: '' ); ?>" placeholder="Máx."></div></details><?php endif; ?>
                        <?php if ( $s['show_stock'] === 'yes' ) : ?><details open><summary>Disponibilidad</summary><label class="owc-explorer__check"><input type="checkbox" name="owc_stock" value="1" <?php checked( $stock ); ?>> Productos con stock</label></details><?php endif; ?>
                        <button class="owc-explorer__apply" type="submit">Aplicar filtros</button>
                    </form>
                </aside>
                <main class="owc-explorer__results">
                    <?php if ( $s['show_order'] === 'yes' ) : ?><div class="owc-explorer__toolbar"><span><?php echo esc_html( number_format_i18n( $query->found_posts ) ); ?> resultados</span><form method="get"><input type="hidden" name="s" value="<?php echo esc_attr( $keyword ); ?>"><input type="hidden" name="owc_cat" value="<?php echo esc_attr( $category ); ?>"><input type="hidden" name="owc_min" value="<?php echo esc_attr( $min ?: '' ); ?>"><input type="hidden" name="owc_max" value="<?php echo esc_attr( $max ?: '' ); ?>"><?php if ( $stock ) : ?><input type="hidden" name="owc_stock" value="1"><?php endif; ?><label>Ordenar por:<select name="owc_order"><option value="relevance" <?php selected( $order, 'relevance' ); ?>>Recomendados</option><option value="price" <?php selected( $order, 'price' ); ?>>Menor precio</option><option value="price-desc" <?php selected( $order, 'price-desc' ); ?>>Mayor precio</option><option value="newest" <?php selected( $order, 'newest' ); ?>>Más recientes</option></select></label></form></div><?php endif; ?>
                    <?php if ( $category || $min || $max || $stock ) : ?><div class="owc-explorer__chips"><?php if ( $selected_term && ! is_wp_error( $selected_term ) ) : ?><a href="<?php echo esc_url( remove_query_arg( [ 'owc_cat', 'product-page' ] ) ); ?>"><?php echo esc_html( $selected_term->name ); ?> <b>×</b></a><?php endif; ?><?php if ( $min ) : ?><a href="<?php echo esc_url( remove_query_arg( [ 'owc_min', 'product-page' ] ) ); ?>">Desde <?php echo wp_kses_post( wc_price( $min ) ); ?> <b>×</b></a><?php endif; ?><?php if ( $max ) : ?><a href="<?php echo esc_url( remove_query_arg( [ 'owc_max', 'product-page' ] ) ); ?>">Hasta <?php echo wp_kses_post( wc_price( $max ) ); ?> <b>×</b></a><?php endif; ?><?php if ( $stock ) : ?><a href="<?php echo esc_url( remove_query_arg( [ 'owc_stock', 'product-page' ] ) ); ?>">Con stock <b>×</b></a><?php endif; ?></div><?php endif; ?>
                    <?php if ( $query->have_posts() ) : ?><div class="owc-explorer__grid">
                    <?php while ( $query->have_posts() ) : $query->the_post(); $product = wc_get_product( get_the_ID() ); if ( ! $product ) continue; ?>
                        <article class="owc-product-card"><a class="owc-product-card__image" href="<?php the_permalink(); ?>"><?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?><?php if ( $product->is_on_sale() ) : ?><span>Oferta</span><?php endif; ?></a><div class="owc-product-card__body"><?php $cats = wc_get_product_category_list( $product->get_id(), ', ' ); if ( $cats ) : ?><div class="owc-product-card__category"><?php echo wp_kses_post( $cats ); ?></div><?php endif; ?><a class="owc-product-card__name" href="<?php the_permalink(); ?>"><?php the_title(); ?></a><div class="owc-product-card__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div><div class="owc-product-card__stock <?php echo $product->is_in_stock() ? 'is-in' : 'is-out'; ?>"><?php echo esc_html( $product->is_in_stock() ? '● Disponible' : 'Sin stock' ); ?></div><a class="owc-product-card__button" href="<?php the_permalink(); ?>">Ver producto</a></div></article>
                    <?php endwhile; ?></div><?php else : ?><div class="owc-explorer__empty"><strong>No encontramos productos</strong><p>Prueba con otra palabra o elimina algunos filtros.</p></div><?php endif; ?>
                    <?php if ( $query->max_num_pages > 1 ) echo '<nav class="owc-explorer__pagination">' . wp_kses_post( paginate_links( [ 'base'=>add_query_arg( 'product-page', '%#%' ), 'format'=>'', 'current'=>$paged, 'total'=>$query->max_num_pages, 'prev_text'=>'←', 'next_text'=>'→' ] ) ) . '</nav>'; ?>
                </main>
            </div>
        </section>
        <?php wp_reset_postdata();
    }
}
