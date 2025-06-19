<?php
if ( ! defined( 'WPINC' ) ) die;

/**
 * Gerencia o registro de todos os Custom Post Types (CPTs) e suas meta boxes relacionadas.
 */
class MVPU_CPT {

    public function __construct() {
        add_action( 'init', [ $this, 'register_all_cpts' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_group_metaboxes' ] );
        add_action( 'save_post_membro_vip_grupo', [ $this, 'save_group_metaboxes' ] );
    }

    /**
     * Ponto de entrada para registrar todos os CPTs do plugin.
     */
    public function register_all_cpts() {
        $this->register_grupos_cpt();
        $this->register_arquivos_cpt();
    }

    /**
     * Registra o CPT 'membro_vip_grupo'.
     */
    private function register_grupos_cpt() {
        $labels = [
            'name'          => _x( 'Grupos VIP', 'Post Type General Name', 'membros-vip-pro' ),
            'singular_name' => _x( 'Grupo VIP', 'Post Type Singular Name', 'membros-vip-pro' ),
            'menu_name'     => __( 'Grupos VIP', 'membros-vip-pro' ),
            'all_items'     => __( 'Todos os Grupos', 'membros-vip-pro' ),
            'add_new_item'  => __( 'Adicionar Novo Grupo', 'membros-vip-pro' ),
            'add_new'       => __( 'Adicionar Novo', 'membros-vip-pro' ),
            'edit_item'     => __( 'Editar Grupo', 'membros-vip-pro' ),
            'update_item'   => __( 'Atualizar Grupo', 'membros-vip-pro' ),
        ];
        $args = [
            'label'               => __( 'Grupo VIP', 'membros-vip-pro' ),
            'labels'              => $labels,
            'supports'            => [ 'title', 'editor' ],
            'hierarchical'        => false,
            'public'              => false, // Não precisa ser público no front-end
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_position'       => 25,
            'menu_icon'           => 'dashicons-groups',
            'show_in_admin_bar'   => true,
            'show_in_nav_menus'   => false,
            'can_export'          => true,
            'has_archive'         => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'capability_type'     => 'post',
            'rewrite'             => false,
        ];
        register_post_type( 'membro_vip_grupo', $args );
    }

    /**
     * Registra o CPT 'membro_vip_arquivo'.
     */
    private function register_arquivos_cpt() {
        $labels = [
            'name'          => _x( 'Arquivos VIP', 'Post Type General Name', 'membros-vip-pro' ),
            'singular_name' => _x( 'Arquivo VIP', 'Post Type Singular Name', 'membros-vip-pro' ),
            'menu_name'     => __( 'Arquivos VIP', 'membros-vip-pro' ),
            'all_items'     => __( 'Todos os Arquivos', 'membros-vip-pro' ),
            'add_new_item'  => __( 'Adicionar Novo Arquivo', 'membros-vip-pro' ),
            'add_new'       => __( 'Adicionar Novo', 'membros-vip-pro' ),
            'edit_item'     => __( 'Editar Arquivo', 'membros-vip-pro' ),
            'update_item'   => __( 'Atualizar Arquivo', 'membros-vip-pro' ),
        ];
        $args = [
            'label'               => __( 'Arquivo VIP', 'membros-vip-pro' ),
            'description'         => __( 'Arquivos com acesso restrito por grupo.', 'membros-vip-pro' ),
            'labels'              => $labels,
            'supports'            => [ 'title', 'editor' ],
            'hierarchical'        => false,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'edit.php?post_type=membro_vip_grupo', // Submenu de "Grupos VIP"
            'show_in_admin_bar'   => true,
            'can_export'          => true,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'capability_type'     => 'post',
        ];
        register_post_type( 'membro_vip_arquivo', $args );
    }

    /**
     * Adiciona as meta boxes à tela de edição de Grupo.
     */
    public function add_group_metaboxes() {
        add_meta_box(
            'mvpu_invitation_settings',
            __( 'Configurações de Convite', 'membros-vip-pro' ),
            [ $this, 'render_invitation_metabox' ],
            'membro_vip_grupo',
            'side',
            'high'
        );
    }

    /**
     * Renderiza o conteúdo da meta box de Convite.
     */
    public function render_invitation_metabox( $post ) {
        wp_nonce_field( 'mvpu_save_invitation_data', 'mvpu_invitation_nonce' );
        
        $validity = get_post_meta( $post->ID, '_mvpu_access_validity', true );
        $validity = $validity ? (int) $validity : 365;
        $invitation_link = home_url( '/registro-vip/?grupo_id=' . $post->ID );
        ?>
        <p>
            <label for="mvpu_access_validity"><strong><?php _e( 'Validade do Acesso (dias):', 'membros-vip-pro' ); ?></strong></label><br>
            <input type="number" id="mvpu_access_validity" name="mvpu_access_validity" value="<?php echo esc_attr( $validity ); ?>" min="1" step="1" style="width:100%;">
        </p>
        <p>
            <strong><?php _e( 'Link de Convite:', 'membros-vip-pro' ); ?></strong><br>
            <input type="text" value="<?php echo esc_url( $invitation_link ); ?>" readonly style="width:100%;" onfocus="this.select();">
            <small><?php _e( 'Use este link para o cadastro de novos membros neste grupo.', 'membros-vip-pro' ); ?></small>
        </p>
        <?php
    }

    /**
     * Salva os dados da meta box de Convite.
     */
    public function save_group_metaboxes( $post_id ) {
        if ( ! isset( $_POST['mvpu_invitation_nonce'] ) || ! wp_verify_nonce( $_POST['mvpu_invitation_nonce'], 'mvpu_save_invitation_data' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        if ( isset( $_POST['mvpu_access_validity'] ) ) {
            update_post_meta( $post_id, '_mvpu_access_validity', absint( $_POST['mvpu_access_validity'] ) );
        }
    }
}