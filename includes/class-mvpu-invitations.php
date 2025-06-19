<?php
// Arquivo: /includes/class-mvpu-invitations.php
if ( ! defined( 'WPINC' ) ) die;

/**
 * Gerencia o fluxo de convites, registro, aprovação e expiração.
 */
class MVPU_Invitations {
    
    public function __construct() {
        add_action( 'init', [ __CLASS__, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'handle_registration_page' ] );
        add_action( 'mvpu_daily_expiration_check', [ $this, 'run_expiration_check' ] );
    }

    public static function add_rewrite_rules() {
        add_rewrite_rule( '^registro-vip/?$', 'index.php?mvpu_registration=true', 'top' );
    }

    public static function add_query_vars( $vars ) {
        $vars[] = 'mvpu_registration';
        return $vars;
    }

    /**
     * Intercepta a URL de registro e exibe o formulário ou processa os dados.
     */
    public function handle_registration_page() {
        if ( get_query_var( 'mvpu_registration' ) ) {
            $this->process_registration_submission();
            $this->display_registration_form();
            exit;
        }
    }
    
    /**
     * Processa os dados do formulário de registro.
     */
    private function process_registration_submission() {
        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['mvpu_register_nonce'] ) || ! wp_verify_nonce( $_POST['mvpu_register_nonce'], 'mvpu_register' ) ) {
            return;
        }

        $username = isset( $_POST['username'] ) ? sanitize_user( $_POST['username'] ) : '';
        $email    = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
        $group_id = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
        
        // Validações
        $errors = [];
        if ( empty( $username ) || empty( $email ) || empty( $group_id ) ) $errors[] = 'empty_fields';
        if ( username_exists( $username ) ) $errors[] = 'username_exists';
        if ( email_exists( $email ) ) $errors[] = 'email_exists';
        if ( ! is_email( $email ) ) $errors[] = 'invalid_email';
        if ( get_post_type( $group_id ) !== 'membro_vip_grupo' ) $errors[] = 'invalid_group';

        if ( ! empty( $errors ) ) {
            $this->redirect_with_error( $errors[0] );
        }

        $password = wp_generate_password();
        $user_id = wp_create_user( $username, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            $this->redirect_with_error( $user_id->get_error_code() );
        }

        update_user_meta( $user_id, '_mvpu_status', 'pending' );
        update_user_meta( $user_id, '_mvpu_pending_group', $group_id );

        wp_new_user_notification( $user_id, null, 'both' );

        $login_url = wp_login_url();
        wp_redirect( add_query_arg( 'registration_success', 'true', $login_url ) );
        exit;
    }
    
    /**
     * Exibe o formulário de registro na página virtual.
     */
    public function display_registration_form() {
        $group_id = isset( $_GET['grupo_id'] ) ? absint( $_GET['grupo_id'] ) : 0;
        if ( ! $group_id || get_post_type( $group_id ) !== 'membro_vip_grupo' ) {
            wp_die( __( 'Link de convite inválido ou expirado.', 'membros-vip-pro' ) );
        }
        $group_name = get_the_title( $group_id );

        get_header();
        ?>
        <div id="primary" class="content-area" style="padding: 2em;">
            <main id="main" class="site-main">
                <article class="page type-page status-publish hentry">
                    <header class="entry-header">
                        <h1 class="entry-title"><?php printf( __( 'Registro para o Grupo: %s', 'membros-vip-pro' ), esc_html( $group_name ) ); ?></h1>
                    </header>
                    <div class="entry-content">
                        <?php if ( isset( $_GET['reg_error'] ) ) : ?>
                            <div style="color: #d8000c; background-color: #ffbaba; border: 1px solid; padding: 15px; margin-bottom: 20px;">
                                <?php echo esc_html( self::get_error_message( $_GET['reg_error'] ) ); ?>
                            </div>
                        <?php endif; ?>
                        
                        <p><?php _e( 'Crie sua conta para solicitar acesso. Sua inscrição será revisada por um administrador.', 'membros-vip-pro' ); ?></p>
                        <form method="POST" action="">
                            <p>
                                <label for="username"><?php _e( 'Nome de usuário', 'membros-vip-pro' ); ?></label><br>
                                <input type="text" name="username" id="username" required>
                            </p>
                            <p>
                                <label for="email"><?php _e( 'E-mail', 'membros-vip-pro' ); ?></label><br>
                                <input type="email" name="email" id="email" required>
                            </p>
                            <input type="hidden" name="group_id" value="<?php echo esc_attr( $group_id ); ?>">
                            <?php wp_nonce_field( 'mvpu_register', 'mvpu_register_nonce' ); ?>
                            <p>
                                <input type="submit" value="<?php _e( 'Registrar', 'membros-vip-pro' ); ?>">
                            </p>
                        </form>
                    </div>
                </article>
            </main>
        </div>
        <?php
        get_footer();
    }
    
    /**
     * Tarefa diária do Cron para verificar e expirar membros.
     */
    public function run_expiration_check() {
        $users = get_users([
            'meta_key'   => '_mvpu_status',
            'meta_value' => 'confirmed',
            'fields'     => 'ID',
        ]);
        
        if ( empty( $users ) ) return;

        $today = new DateTime();
        $today->setTime( 0, 0, 0 );

        foreach ( $users as $user_id ) {
            $expiration_date_str = get_user_meta( $user_id, '_mvpu_expiration_date', true );
            if ( empty( $expiration_date_str ) ) continue;

            $expiration_date = new DateTime( $expiration_date_str );
            $expiration_date->setTime( 0, 0, 0 );

            if ( $today > $expiration_date ) {
                update_user_meta( $user_id, '_mvpu_status', 'expired' );
                delete_user_meta( $user_id, '_membros_vip_pro_grupos' );
            }
        }
    }

    private function redirect_with_error( $code ) {
        $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $url = add_query_arg( ['reg_error' => $code], $current_url );
        wp_redirect( $url );
        exit;
    }

    private static function get_error_message( $code ) {
        $messages = [
            'empty_fields'    => __( 'Todos os campos são obrigatórios.', 'membros-vip-pro' ),
            'username_exists' => __( 'Este nome de usuário já está em uso.', 'membros-vip-pro' ),
            'email_exists'    => __( 'Este e-mail já está cadastrado.', 'membros-vip-pro' ),
            'invalid_email'   => __( 'Por favor, insira um e-mail válido.', 'membros-vip-pro' ),
            'invalid_group'   => __( 'O grupo de convite é inválido.', 'membros-vip-pro' ),
        ];
        return $messages[ $code ] ?? __( 'Ocorreu um erro desconhecido.', 'membros-vip-pro' );
    }
}