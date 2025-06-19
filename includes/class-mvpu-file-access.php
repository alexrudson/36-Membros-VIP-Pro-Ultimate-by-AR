<?php
if ( ! defined( 'WPINC' ) ) die;

/**
 * Gerencia o upload e download seguro de arquivos.
 */
class MVPU_File_Access {
    const SECURE_DIR = 'mvpu_secure_files';
    const DOWNLOAD_SLUG = 'download-vip';

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_file_metaboxes' ] );
        add_action( 'save_post_membro_vip_arquivo', [ $this, 'save_file_data' ] );
        add_filter( 'upload_dir', [ $this, 'change_upload_dir' ] );
        add_action( 'template_redirect', [ $this, 'handle_download' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            global $post;
            if ($post && $post->post_type === 'membro_vip_arquivo') {
                wp_enqueue_media();
            }
        }
    }

    public static function add_rewrite_rules() {
        add_rewrite_rule( self::DOWNLOAD_SLUG . '/([^/]+)/?$', 'index.php?mvpu_download_file=$matches[1]', 'top' );
    }

    public function add_file_metaboxes() {
        add_meta_box( 'mvpu_file_upload_mb', __( 'Arquivo Protegido', 'membros-vip-pro' ), [ $this, 'render_upload_metabox' ], 'membro_vip_arquivo', 'normal', 'high' );
        add_meta_box( 'mvpu_file_access_mb', __( 'Acesso por Grupo VIP', 'membros-vip-pro' ), [ $this, 'render_access_metabox' ], 'membro_vip_arquivo', 'side', 'default' );
        add_meta_box( 'mvpu_file_download_link_mb', __( 'Link de Download Seguro', 'membros-vip-pro' ), [ $this, 'render_download_link_metabox' ], 'membro_vip_arquivo', 'side', 'default' );
    }
    
    public function render_upload_metabox( $post ) {
        wp_nonce_field( 'mvpu_save_file', 'mvpu_file_nonce' );
        $file_id = get_post_meta( $post->ID, '_mvpu_file_attachment_id', true );
        echo '<p>' . __( 'Faça o upload do arquivo que deseja proteger.', 'membros-vip-pro' ) . '</p>';
        echo '<input type="hidden" name="mvpu_file_attachment_id" id="mvpu_file_attachment_id" value="' . esc_attr( $file_id ) . '">';
        echo '<button type="button" class="button" id="mvpu_upload_file_button">' . __( 'Selecionar/Enviar Arquivo', 'membros-vip-pro' ) . '</button>';
        echo '<div id="mvpu_file_preview" style="margin-top: 15px;">';
        if ( $file_id ) echo '<strong>' . __( 'Arquivo selecionado:', 'membros-vip-pro' ) . '</strong> ' . esc_html( basename( get_attached_file( $file_id ) ) );
        echo '</div>';
        ?>
        <script>
        jQuery(document).ready(function($){
            var frame;
            $('#mvpu_upload_file_button').on('click', function(e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({ 
                    title: '<?php _e( "Selecione um Arquivo", "membros-vip-pro" ); ?>', 
                    button: { text: '<?php _e( "Usar este arquivo", "membros-vip-pro" ); ?>' }, 
                    multiple: false 
                });
                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $('#mvpu_file_attachment_id').val(attachment.id);
                    $('#mvpu_file_preview').html('<strong><?php _e( "Arquivo selecionado:", "membros-vip-pro" ); ?></strong> ' + attachment.filename);
                });
                frame.open();
            });
        });
        </script>
        <?php
    }

    public function render_access_metabox( $post ) {
        wp_nonce_field( 'mvpu_save_file_access', 'mvpu_file_access_nonce' );
        $allowed_groups = get_post_meta( $post->ID, '_mvpu_allowed_groups', true ) ?: [];
        $all_groups = new WP_Query( ['post_type' => 'membro_vip_grupo', 'posts_per_page' => -1, 'post_status' => 'publish'] );
        if ( $all_groups->have_posts() ) {
            while( $all_groups->have_posts() ) {
                $all_groups->the_post();
                $checked = in_array( get_the_ID(), $allowed_groups ) ? 'checked' : '';
                echo '<label><input type="checkbox" name="mvpu_allowed_groups[]" value="' . get_the_ID() . '" ' . $checked . '> ' . get_the_title() . '</label><br>';
            }
            wp_reset_postdata();
        } else {
            echo __( 'Nenhum grupo VIP encontrado.', 'membros-vip-pro' );
        }
    }
    
    public function render_download_link_metabox( $post ) {
        if ( $post->post_status !== 'publish' ) {
            echo __( 'Publique o arquivo para gerar o link.', 'membros-vip-pro' );
            return;
        }
        $link = home_url( self::DOWNLOAD_SLUG . '/' . $post->post_name );
        echo '<input type="text" value="' . esc_url( $link ) . '" readonly style="width:100%;" onfocus="this.select();">';
    }

    public function save_file_data( $post_id ) {
        if ( ! isset( $_POST['mvpu_file_nonce'], $_POST['mvpu_file_access_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['mvpu_file_nonce'], 'mvpu_save_file' ) ) return;
        if ( ! wp_verify_nonce( $_POST['mvpu_file_access_nonce'], 'mvpu_save_file_access' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        
        if ( isset( $_POST['mvpu_file_attachment_id'] ) ) {
            update_post_meta( $post_id, '_mvpu_file_attachment_id', absint( $_POST['mvpu_file_attachment_id'] ) );
        }
        $groups = isset( $_POST['mvpu_allowed_groups'] ) ? array_map( 'intval', (array) $_POST['mvpu_allowed_groups'] ) : [];
        update_post_meta( $post_id, '_mvpu_allowed_groups', $groups );
    }
    
    public function change_upload_dir( $dirs ) {
        if ( isset( $_REQUEST['post_id'] ) && get_post_type( $_REQUEST['post_id'] ) === 'membro_vip_arquivo' ) {
            $secure_dir = self::SECURE_DIR;
            $dirs['path'] = $dirs['basedir'] . '/' . $secure_dir;
            $dirs['url'] = $dirs['baseurl'] . '/' . $secure_dir; // URL não será usada, mas é bom manter a consistência
            $dirs['subdir'] = '/' . $secure_dir;
        }
        return $dirs;
    }

    public function handle_download() {
        if ( ! $file_slug = get_query_var( 'mvpu_download_file' ) ) return;

        $files = get_posts( [ 'name' => $file_slug, 'post_type' => 'membro_vip_arquivo', 'post_status' => 'publish', 'posts_per_page' => 1 ] );

        // Se o arquivo não existe ou o usuário não está logado, redireciona.
        if ( ! $files || ! is_user_logged_in() ) {
            $GLOBALS['mvpu_content_restriction']->redirect_user();
        }

        $file_post = $files[0];
        $user_groups = get_user_meta( get_current_user_id(), '_membros_vip_pro_grupos', true ) ?: [];
        $allowed_groups = get_post_meta( $file_post->ID, '_mvpu_allowed_groups', true ) ?: [];

        // Se o usuário não pertence a nenhum grupo autorizado, redireciona.
        if ( empty( array_intersect( $user_groups, $allowed_groups ) ) ) {
            $GLOBALS['mvpu_content_restriction']->redirect_user();
        }

        $attachment_id = get_post_meta( $file_post->ID, '_mvpu_file_attachment_id', true );
        $file_path = get_attached_file( $attachment_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            wp_die( __( 'Arquivo não encontrado no servidor.', 'membros-vip-pro' ), __( 'Erro de Download', 'membros-vip-pro' ), 404 );
        }
        
        // Limpa qualquer output que possa ter sido iniciado
        if (ob_get_level()) ob_end_clean();

        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
        header( 'Expires: 0' );
        header( 'Cache-Control: must-revalidate' );
        header( 'Pragma: public' );
        header( 'Content-Length: ' . filesize( $file_path ) );
        
        readfile( $file_path );
        exit;
    }

    public static function create_secure_directory() {
        $upload_dir = wp_upload_dir();
        $secure_path = $upload_dir['basedir'] . '/' . self::SECURE_DIR;
        if ( ! file_exists( $secure_path ) ) {
            wp_mkdir_p( $secure_path );
        }
        $htaccess_path = $secure_path . '/.htaccess';
        if ( ! file_exists( $htaccess_path ) ) {
            $htaccess_content = "Options -Indexes\ndeny from all";
            @file_put_contents( $htaccess_path, $htaccess_content );
        }
    }
}