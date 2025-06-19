<?php
if ( ! defined( 'WPINC' ) ) die;

/**
 * Adiciona e gerencia os campos e ações no perfil do usuário.
 */
class MVPU_User_Profile {

    public function __construct() {
        add_action( 'show_user_profile', [ $this, 'render_user_metaboxes' ] );
        add_action( 'edit_user_profile', [ $this, 'render_user_metaboxes' ] );

        add_action( 'personal_options_update', [ $this, 'save_user_groups_metabox' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_user_groups_metabox' ] );
        
        add_action( 'admin_init', [ $this, 'handle_approval_action' ] );

        add_filter( 'manage_users_columns', [ $this, 'add_status_column' ] );
        add_filter( 'manage_users_custom_column', [ $this, 'render_status_column' ], 10, 3 );
        
        add_action( 'admin_notices', [ $this, 'show_approval_notice' ] );
    }

    /**
     * Renderiza todas as caixas de meta do plugin na página de perfil.
     */
    public function render_user_metaboxes( $user ) {
        if ( ! current_user_can( 'edit_users' ) ) return;
        $this->render_access_management_metabox( $user );
        $this->render_user_groups_metabox( $user );
    }

    /**
     * Renderiza a caixa de Gerenciamento de Acesso.
     */
    private function render_access_management_metabox( $user ) {
        $status = get_user_meta( $user->ID, '_mvpu_status', true );
        $status_text = self::get_status_text( $status );
        $expiration_date = get_user_meta( $user->ID, '_mvpu_expiration_date', true );
        ?>
        <h2><?php _e( 'Membros VIP Pro - Gerenciamento', 'membros-vip-pro' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label><?php _e( 'Status do Acesso', 'membros-vip-pro' ); ?></label></th>
                <td>
                    <p><strong><?php echo esc_html( $status_text ); ?></strong></p>
                    <?php if ( $status === 'confirmed' && $expiration_date ) : ?>
                        <p><?php _e( 'Expira em:', 'membros-vip-pro' ); ?> <?php echo date_i18n( get_option( 'date_format' ), strtotime( $expiration_date ) ); ?></p>
                    <?php endif; ?>
                    <?php if ( $status === 'pending' ) : 
                        $approval_link = wp_nonce_url(
                            add_query_arg( [ 'user_id' => $user->ID, 'mvpu_action' => 'approve' ], admin_url( 'user-edit.php' ) ),
                            'mvpu_approve_user_' . $user->ID
                        );
                    ?>
                        <a href="<?php echo esc_url( $approval_link ); ?>" class="button button-primary"><?php _e( 'Aprovar Acesso', 'membros-vip-pro' ); ?></a>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Renderiza a caixa de seleção de grupos do usuário.
     */
    private function render_user_groups_metabox( $user ) {
        $all_groups_query = new WP_Query(['post_type' => 'membro_vip_grupo', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
        $user_groups = get_user_meta( $user->ID, '_membros_vip_pro_grupos', true );
        $user_groups = is_array( $user_groups ) ? $user_groups : [];
        ?>
        <h3><?php _e( 'Grupos VIP do Usuário', 'membros-vip-pro' ); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="mvpu_user_groups"><?php _e( 'Associações', 'membros-vip-pro' ); ?></label></th>
                <td>
                    <?php
                    wp_nonce_field( 'mvpu_save_user_groups', 'mvpu_user_groups_nonce' );
                    if ( $all_groups_query->have_posts() ) {
                        while ( $all_groups_query->have_posts() ) {
                            $all_groups_query->the_post();
                            $group_id = get_the_ID();
                            $checked = in_array( $group_id, $user_groups ) ? 'checked="checked"' : '';
                            echo '<label><input type="checkbox" name="mvpu_user_groups[]" value="' . esc_attr( $group_id ) . '" ' . $checked . '> ' . esc_html( get_the_title() ) . '</label><br>';
                        }
                        wp_reset_postdata();
                    } else {
                        _e( 'Nenhum grupo VIP foi criado ainda.', 'membros-vip-pro' );
                    }
                    ?>
                    <p class="description"><?php _e( 'Gerencie manualmente os grupos do usuário. A aprovação automática já associa ao grupo correto.', 'membros-vip-pro' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Salva a associação de grupos do usuário.
     */
    public function save_user_groups_metabox( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) return;
        if ( ! isset( $_POST['mvpu_user_groups_nonce'] ) || ! wp_verify_nonce( $_POST['mvpu_user_groups_nonce'], 'mvpu_save_user_groups' ) ) return;
        
        $selected_groups = isset( $_POST['mvpu_user_groups'] ) ? (array) $_POST['mvpu_user_groups'] : [];
        update_user_meta( $user_id, '_membros_vip_pro_grupos', array_map( 'intval', $selected_groups ) );
    }

    /**
     * Processa a ação de aprovação do administrador.
     */
    public function handle_approval_action() {
        if ( ! current_user_can( 'edit_users' ) ) return;
        if ( ! isset( $_GET['mvpu_action'] ) || $_GET['mvpu_action'] !== 'approve' ) return;
        if ( ! isset( $_GET['user_id'] ) || ! isset( $_GET['_wpnonce'] ) ) return;

        $user_id = absint( $_GET['user_id'] );
        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'mvpu_approve_user_' . $user_id ) ) {
            wp_die( __( 'Falha na verificação de segurança (nonce).', 'membros-vip-pro' ) );
        }

        $pending_group = get_user_meta( $user_id, '_mvpu_pending_group', true );
        if ( ! $pending_group ) return;

        // 1. Adiciona o usuário ao grupo
        $user_groups = get_user_meta( $user_id, '_membros_vip_pro_grupos', true );
        $user_groups = is_array( $user_groups ) ? $user_groups : [];
        if ( ! in_array( $pending_group, $user_groups ) ) {
            $user_groups[] = $pending_group;
            update_user_meta( $user_id, '_membros_vip_pro_grupos', $user_groups );
        }

        // 2. Muda o status para 'confirmado'
        update_user_meta( $user_id, '_mvpu_status', 'confirmed' );

        // 3. Calcula e salva a data de expiração
        $validity = get_post_meta( $pending_group, '_mvpu_access_validity', true );
        $validity_days = $validity ? absint( $validity ) : 365;
        $expiration_date = date( 'Y-m-d H:i:s', strtotime( "+{$validity_days} days" ) );
        update_user_meta( $user_id, '_mvpu_expiration_date', $expiration_date );
        
        // 4. Salva a data de ingresso no grupo (para Drip Content)
        update_user_meta( $user_id, '_mvpu_join_date_' . $pending_group, date( 'Y-m-d H:i:s' ) );

        // 5. Limpa o meta de pendência
        delete_user_meta( $user_id, '_mvpu_pending_group' );
        
        // 6. Redireciona de volta com mensagem de sucesso
        wp_redirect( add_query_arg( 'mvpu_approved', 'true', get_edit_user_link( $user_id ) ) );
        exit;
    }

    /**
     * Adiciona a coluna 'Status VIP' na lista de usuários.
     */
    public function add_status_column( $columns ) {
        $columns['mvpu_status'] = __( 'Status VIP', 'membros-vip-pro' );
        return $columns;
    }

    /**
     * Renderiza o conteúdo da coluna 'Status VIP'.
     */
    public function render_status_column( $value, $column_name, $user_id ) {
        if ( 'mvpu_status' === $column_name ) {
            $status = get_user_meta( $user_id, '_mvpu_status', true );
            return esc_html( self::get_status_text( $status ) );
        }
        return $value;
    }

    /**
     * Mostra uma notificação de sucesso após aprovar um usuário.
     */
    public function show_approval_notice() {
        if ( isset( $_GET['mvpu_approved'] ) && $_GET['mvpu_approved'] === 'true' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . __( 'Usuário aprovado com sucesso! Acesso concedido.', 'membros-vip-pro' ) . '</p></div>';
        }
    }
    
    /**
     * Converte a chave de status em um texto legível.
     */
    public static function get_status_text( $status_key ) {
        switch ( $status_key ) {
            case 'pending': return __( 'Pendente', 'membros-vip-pro' );
            case 'confirmed': return __( 'Confirmado', 'membros-vip-pro' );
            case 'expired': return __( 'Expirado', 'membros-vip-pro' );
            default: return '—';
        }
    }
}