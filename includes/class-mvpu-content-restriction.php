<?php
if ( ! defined( 'WPINC' ) ) die;

/**
 * Gerencia a restrição de conteúdo, seja por categoria ou por gotejamento (Drip Content).
 */
class MVPU_Content_Restriction {

    private $all_restricted_categories_cache;

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
        add_action( 'save_post_membro_vip_grupo', [ $this, 'save_category_restriction_data' ] );
        add_action( 'save_post', [ $this, 'save_drip_content_data' ] );
        add_action( 'template_redirect', [ $this, 'check_content_access' ] );
    }

    /**
     * Adiciona todas as meta boxes relacionadas a este módulo.
     */
    public function add_metaboxes() {
        add_meta_box( 'mvpu_category_restriction_mb', __( 'Restringir Acesso a Categorias', 'membros-vip-pro' ), [ $this, 'render_category_restriction_metabox' ], 'membro_vip_grupo', 'advanced', 'high' );
        add_meta_box( 'mvpu_drip_content_mb', __( 'Conteúdo Programado (Membros VIP Pro)', 'membros-vip-pro' ), [ $this, 'render_drip_content_metabox' ], 'post', 'side', 'default' );
    }

    public function render_category_restriction_metabox( $post ) {
        wp_nonce_field( 'mvpu_save_category_restriction', 'mvpu_category_restriction_nonce' );
        $saved_categories = get_post_meta( $post->ID, '_mvpu_restricted_categories', true ) ?: [];
        $all_categories = get_categories( [ 'hide_empty' => false ] );

        echo '<p>' . __( 'Marque as categorias de posts que serão exclusivas para membros deste grupo.', 'membros-vip-pro' ) . '</p>';
        echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">';
        foreach ( $all_categories as $category ) {
            $checked = in_array( $category->term_id, $saved_categories ) ? 'checked' : '';
            echo '<label><input type="checkbox" name="mvpu_restricted_categories[]" value="' . esc_attr( $category->term_id ) . '" ' . $checked . '> ' . esc_html( $category->name ) . '</label><br>';
        }
        echo '</div>';
    }

    public function save_category_restriction_data( $post_id ) {
        if ( ! isset( $_POST['mvpu_category_restriction_nonce'] ) || ! wp_verify_nonce( $_POST['mvpu_category_restriction_nonce'], 'mvpu_save_category_restriction' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        
        $sanitized_cats = isset( $_POST['mvpu_restricted_categories'] ) ? array_map( 'intval', (array) $_POST['mvpu_restricted_categories'] ) : [];
        update_post_meta( $post_id, '_mvpu_restricted_categories', $sanitized_cats );
    }

    public function render_drip_content_metabox( $post ) {
        wp_nonce_field( 'mvpu_save_drip_content', 'mvpu_drip_content_nonce' );
        
        $drip_group = get_post_meta( $post->ID, '_mvpu_drip_group_id', true );
        $drip_days = get_post_meta( $post->ID, '_mvpu_drip_days', true );
        $all_groups = new WP_Query( ['post_type' => 'membro_vip_grupo', 'posts_per_page' => -1, 'post_status' => 'publish'] );
        
        echo '<p>' . __( 'Libere este conteúdo para um grupo específico após um certo número de dias.', 'membros-vip-pro' ) . '</p>';
        echo '<label for="mvpu_drip_group_id"><strong>' . __( 'Liberar para o grupo:', 'membros-vip-pro' ) . '</strong></label><br>';
        echo '<select name="mvpu_drip_group_id" id="mvpu_drip_group_id" style="width:100%;">';
        echo '<option value="">— ' . __( 'Nenhum', 'membros-vip-pro' ) . ' —</option>';
        if ( $all_groups->have_posts() ) {
            while( $all_groups->have_posts() ) {
                $all_groups->the_post();
                echo '<option value="' . get_the_ID() . '" ' . selected( $drip_group, get_the_ID(), false ) . '>' . get_the_title() . '</option>';
            }
            wp_reset_postdata();
        }
        echo '</select><br><br>';
        echo '<label for="mvpu_drip_days"><strong>' . __( 'Liberar após (dias):', 'membros-vip-pro' ) . '</strong></label><br>';
        echo '<input type="number" name="mvpu_drip_days" id="mvpu_drip_days" value="' . esc_attr( $drip_days ) . '" min="0" step="1" style="width:100%;">';
        echo '<p class="description">' . __( 'Dias após a aprovação do membro. Use 0 para acesso imediato.', 'membros-vip-pro' ) . '</p>';
    }

    public function save_drip_content_data( $post_id ) {
        if ( get_post_type($post_id) !== 'post' ) return; // Garante que só salve para posts
        if ( ! isset( $_POST['mvpu_drip_content_nonce'] ) || ! wp_verify_nonce( $_POST['mvpu_drip_content_nonce'], 'mvpu_save_drip_content' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        
        update_post_meta( $post_id, '_mvpu_drip_group_id', isset( $_POST['mvpu_drip_group_id'] ) ? absint( $_POST['mvpu_drip_group_id'] ) : '' );
        update_post_meta( $post_id, '_mvpu_drip_days', isset( $_POST['mvpu_drip_days'] ) ? absint( $_POST['mvpu_drip_days'] ) : '' );
    }

    /**
     * Verificação principal que acontece em cada carregamento de página.
     */
    public function check_content_access() {
        if ( ! is_singular( 'post' ) || is_admin() ) return;
        
        $post_id = get_queried_object_id();
        $user_id = get_current_user_id();

        // Prioridade 1: Regra de Drip Content
        $drip_group_id = get_post_meta( $post_id, '_mvpu_drip_group_id', true );
        if ( $drip_group_id ) {
            if ( ! $user_id || ! $this->user_has_drip_access( $user_id, $post_id, $drip_group_id ) ) {
                $this->redirect_user();
            }
            return; // Acesso permitido por Drip, fim da verificação.
        }

        // Prioridade 2: Restrição por Categoria
        $post_categories = wp_get_post_categories( $post_id );
        if ( empty( $post_categories ) ) return;
        
        $all_restricted = $this->get_all_restricted_categories();
        if ( empty( array_intersect( $post_categories, $all_restricted ) ) ) return; // Post não está em categoria restrita

        if ( ! $user_id || ! $this->user_has_category_access( $user_id, $post_categories ) ) {
            $this->redirect_user();
        }
    }
    
    private function user_has_drip_access( $user_id, $post_id, $required_group_id ) {
        $user_groups = get_user_meta( $user_id, '_membros_vip_pro_grupos', true ) ?: [];
        if ( ! in_array( $required_group_id, $user_groups ) ) return false;

        $drip_days = (int) get_post_meta( $post_id, '_mvpu_drip_days', true );
        $join_date_str = get_user_meta( $user_id, '_mvpu_join_date_' . $required_group_id, true );

        if ( empty( $join_date_str ) ) return false;

        $join_date = new DateTime( $join_date_str );
        $unlock_date = $join_date->modify( "+{$drip_days} days" );
        
        return new DateTime() >= $unlock_date;
    }

    private function user_has_category_access( $user_id, $post_categories ) {
        $user_groups = get_user_meta( $user_id, '_membros_vip_pro_grupos', true ) ?: [];
        if ( empty( $user_groups ) ) return false;

        foreach ( $user_groups as $group_id ) {
            $group_allowed_cats = get_post_meta( $group_id, '_mvpu_restricted_categories', true ) ?: [];
            if ( ! empty( array_intersect( $post_categories, $group_allowed_cats ) ) ) {
                return true; // Acesso permitido
            }
        }
        return false;
    }

    private function get_all_restricted_categories() {
        if ( isset( $this->all_restricted_categories_cache ) ) {
            return $this->all_restricted_categories_cache;
        }

        $cats = [];
        $query = new WP_Query( ['post_type' => 'membro_vip_grupo', 'posts_per_page' => -1, 'fields' => 'ids'] );
        if ( ! empty( $query->posts ) ) {
            foreach ( $query->posts as $id ) {
                $meta = get_post_meta( $id, '_mvpu_restricted_categories', true );
                if ( is_array( $meta ) ) {
                    $cats = array_merge( $cats, $meta );
                }
            }
        }
        $this->all_restricted_categories_cache = array_unique( $cats );
        return $this->all_restricted_categories_cache;
    }

    public function redirect_user() {
        $settings = get_option( 'mvpu_settings' );
        $redirect_page_id = $settings['access_denied_page_id'] ?? 0;
        $redirect_url = $redirect_page_id ? get_permalink( $redirect_page_id ) : home_url();
        
        wp_redirect( add_query_arg( 'restricted_access', 'true', $redirect_url ) );
        exit();
    }
}