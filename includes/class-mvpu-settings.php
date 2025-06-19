<?php
if ( ! defined( 'WPINC' ) ) die;

/**
 * Gerencia a página de configurações do plugin.
 */
class MVPU_Settings {
    
    private $options;

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_plugin_page' ] );
        add_action( 'admin_init', [ $this, 'page_init' ] );
    }

    public function add_plugin_page() {
        add_submenu_page(
            'edit.php?post_type=membro_vip_grupo',
            __( 'Configurações Membros VIP Pro', 'membros-vip-pro' ),
            __( 'Configurações', 'membros-vip-pro' ),
            'manage_options',
            'mvpu-settings',
            [ $this, 'create_admin_page' ]
        );
    }

    public function create_admin_page() {
        $this->options = get_option( 'mvpu_settings' );
        ?>
        <div class="wrap">
            <h1><?php _e( 'Configurações do Membros VIP Pro', 'membros-vip-pro' ); ?></h1>
            <form method="post" action="options.php">
            <?php
                settings_fields( 'mvpu_option_group' );
                do_settings_sections( 'mvpu-setting-admin' );
                submit_button();
            ?>
            </form>
        </div>
        <?php
    }

    public function page_init() {
        register_setting(
            'mvpu_option_group',
            'mvpu_settings',
            [ $this, 'sanitize' ]
        );

        add_settings_section(
            'mvpu_setting_section_id',
            __( 'Configurações de Restrição', 'membros-vip-pro' ),
            null,
            'mvpu-setting-admin'
        );

        add_settings_field(
            'access_denied_page_id',
            __( 'Página de Acesso Negado', 'membros-vip-pro' ),
            [ $this, 'access_denied_page_id_callback' ],
            'mvpu-setting-admin',
            'mvpu_setting_section_id'
        );
    }

    public function sanitize( $input ) {
        $new_input = [];
        if ( isset( $input['access_denied_page_id'] ) ) {
            $new_input['access_denied_page_id'] = absint( $input['access_denied_page_id'] );
        }
        return $new_input;
    }

    public function access_denied_page_id_callback() {
        $page_id = isset( $this->options['access_denied_page_id'] ) ? $this->options['access_denied_page_id'] : '';
        wp_dropdown_pages([
            'name'              => 'mvpu_settings[access_denied_page_id]',
            'selected'          => $page_id,
            'show_option_none'  => '— ' . __( 'Selecione uma página', 'membros-vip-pro' ) . ' —',
            'option_none_value' => '0',
        ]);
        echo '<p class="description">' . __( 'Usuários sem permissão serão redirecionados para esta página.', 'membros-vip-pro' ) . '</p>';
    }
}