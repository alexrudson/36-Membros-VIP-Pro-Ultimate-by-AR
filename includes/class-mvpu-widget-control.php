<?php
if ( ! defined( 'WPINC' ) ) die;

/**
 * Gerencia a visibilidade condicional de widgets.
 */
class MVPU_Widget_Control {

    public function __construct() {
        add_action( 'in_widget_form', [ $this, 'add_visibility_options' ], 10, 3 );
        add_filter( 'widget_update_callback', [ $this, 'save_visibility_options' ], 10, 4 );
        add_filter( 'widget_display_callback', [ $this, 'filter_widget_display' ], 10, 3 );
    }

    /**
     * Adiciona o campo de seleção de visibilidade no formulário do widget.
     */
    public function add_visibility_options( $widget, $return, $instance ) {
        $visibility = isset( $instance['mvpu_visibility'] ) ? $instance['mvpu_visibility'] : 'all';
        $all_groups = new WP_Query( ['post_type' => 'membro_vip_grupo', 'posts_per_page' => -1, 'post_status' => 'publish'] );
        ?>
        <hr>
        <p>
            <label for="<?php echo $widget->get_field_id( 'mvpu_visibility' ); ?>"><strong><?php _e( 'Visibilidade por Grupo VIP:', 'membros-vip-pro' ); ?></strong></label>
            <select class="widefat" id="<?php echo $widget->get_field_id( 'mvpu_visibility' ); ?>" name="<?php echo $widget->get_field_name( 'mvpu_visibility' ); ?>">
                <option value="all" <?php selected( $visibility, 'all' ); ?>><?php _e( 'Todos', 'membros-vip-pro' ); ?></option>
                <option value="logged_out" <?php selected( $visibility, 'logged_out' ); ?>><?php _e( 'Apenas Visitantes (Não Logados)', 'membros-vip-pro' ); ?></option>
                <option value="logged_in" <?php selected( $visibility, 'logged_in' ); ?>><?php _e( 'Todos os Usuários Logados', 'membros-vip-pro' ); ?></option>
                <optgroup label="<?php _e( 'Grupos Específicos', 'membros-vip-pro' ); ?>">
                    <?php
                    if ( $all_groups->have_posts() ) {
                        while ( $all_groups->have_posts() ) {
                            $all_groups->the_post();
                            $group_id = get_the_ID();
                            echo '<option value="' . esc_attr( $group_id ) . '" ' . selected( $visibility, $group_id, false ) . '>' . esc_html( get_the_title() ) . '</option>';
                        }
                        wp_reset_postdata();
                    }
                    ?>
                </optgroup>
            </select>
        </p>
        <?php
        return $instance;
    }

    /**
     * Salva a opção de visibilidade do widget.
     */
    public function save_visibility_options( $instance, $new_instance, $old_instance, $widget ) {
        $instance['mvpu_visibility'] = isset( $new_instance['mvpu_visibility'] ) ? sanitize_text_field( $new_instance['mvpu_visibility'] ) : 'all';
        return $instance;
    }

    /**
     * Filtra a exibição do widget no front-end com base na regra de visibilidade.
     */
    public function filter_widget_display( $instance, $widget, $args ) {
        $visibility = $instance['mvpu_visibility'] ?? 'all';

        if ( $visibility === 'all' ) return $instance;

        $is_logged_in = is_user_logged_in();
        
        if ( $visibility === 'logged_out' ) return $is_logged_in ? false : $instance;
        if ( $visibility === 'logged_in' ) return $is_logged_in ? $instance : false;

        // Regra de grupo específico, usuário deve estar logado
        if ( ! $is_logged_in ) return false;

        $required_group_id = absint( $visibility );
        $user_groups = get_user_meta( get_current_user_id(), '_membros_vip_pro_grupos', true ) ?: [];
        
        if ( in_array( $required_group_id, $user_groups ) ) {
            return $instance; // Permissão concedida
        }

        return false; // Permissão negada
    }
}