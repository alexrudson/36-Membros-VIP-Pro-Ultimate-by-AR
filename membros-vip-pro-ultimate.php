<?php
/**
 * Plugin Name:       Membros VIP Pro Ultimate by AR
 * Plugin URI:        https://github.com/alexrudson/membros-vip-pro-ultimate
 * Description:       O "canivete suíço" para gerenciamento de membros, grupos, conteúdo restrito, convites, arquivos e widgets. Uma solução completa e integrada.
 * Version:           2.0.0
 * Author:            Alex Rudson
 * Author URI:        https://alexrudson.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       membros-vip-pro
 * Domain Path:       /languages
 */

// Se este arquivo for chamado diretamente, aborte.
if ( ! defined( 'WPINC' ) ) {
    die( 'Acesso negado.' );
}

define( 'MVPU_VERSION', '2.0.0' );
define( 'MVPU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * A classe principal e orquestradora do plugin.
 * Padrão Singleton para garantir uma única instância.
 */
final class Membros_VIP_Pro_Ultimate {
    
    private static $instance;

    public static function instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
            self::$instance->setup_hooks();
        }
        return self::$instance;
    }

    private function setup_hooks() {
        // Hooks de ciclo de vida do plugin (ativação/desativação)
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
        
        // Hook principal para carregar os módulos
        add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
    }

    /**
     * Carrega as dependências e inicializa todos os módulos do plugin.
     */
    public function init_plugin() {
        // Carrega e inicializa os módulos em ordem de dependência
        require_once MVPU_PLUGIN_DIR . 'includes/class-mvpu-cpt.php';
        require_once MVPU_PLUGIN_DIR . 'includes/class-mvpu-settings.php';
        require_once MVPU_PLUGIN_DIR . 'includes/class-mvpu-user-profile.php';
        require_once MVPU_PLUGIN_DIR . 'includes/class-mvpu-invitations.php';
        require_once MVPU_PLUGIN_DIR . 'includes/class-mvpu-content-restriction.php';
        require_once MVPU_PLUGIN_DIR . 'includes/class-mvpu-file-access.php';
        require_once MVPU_PLUGIN_DIR . 'includes/class-mvpu-widget-control.php';

        new MVPU_CPT();
        new MVPU_Settings();
        new MVPU_User_Profile();
        new MVPU_Invitations();
        // A instância de restrição é global para ser acessível pelo módulo de arquivos
        $GLOBALS['mvpu_content_restriction'] = new MVPU_Content_Restriction();
        new MVPU_File_Access();
        new MVPU_Widget_Control();
    }

    /**
     * Executa na ativação do plugin.
     */
    public function activate() {
        // Garante que as dependências estejam carregadas para a ativação
        require_once MVPU_PLUGIN_DIR . 'includes/class-mvpu-cpt.php';
        require_once MVPU_PLUGIN_DIR . 'includes/class-mvpu-invitations.php';
        require_once MVPU_PLUGIN_DIR . 'includes/class-mvpu-file-access.php';

        // Registra os CPTs para que as regras de reescrita os reconheçam
        $cpt_manager = new MVPU_CPT();
        $cpt_manager->register_all_cpts();

        // Adiciona as regras de reescrita para as páginas virtuais
        MVPU_Invitations::add_rewrite_rules();
        MVPU_File_Access::add_rewrite_rules();
        flush_rewrite_rules(); // Limpa e recria as regras de reescrita do WP

        // Cria o diretório seguro para arquivos
        MVPU_File_Access::create_secure_directory();

        // Agenda o cron job para verificação diária de expiração de membros
        if ( ! wp_next_scheduled( 'mvpu_daily_expiration_check' ) ) {
            wp_schedule_event( time(), 'daily', 'mvpu_daily_expiration_check' );
        }
    }

    /**
     * Executa na desativação do plugin.
     */
    public function deactivate() {
        flush_rewrite_rules(); // Limpa as regras para evitar links quebrados
        wp_clear_scheduled_hook( 'mvpu_daily_expiration_check' ); // Remove o agendamento do cron
    }
    
    private function __construct() { /* Construtor privado para Singleton */ }
}

/**
 * Função de inicialização global.
 * @return Membros_VIP_Pro_Ultimate A instância principal do plugin.
 */
function MVPU_run() {
    return Membros_VIP_Pro_Ultimate::instance();
}

// Inicia o plugin.
MVPU_run();