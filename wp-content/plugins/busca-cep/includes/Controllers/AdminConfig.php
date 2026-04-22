<?php

namespace BuscaCep\Controllers;

/**
 * Gerencia menus administrativos, enqueue de scripts/styles e hooks do plugin.
 */
class AdminConfig
{
    public function __construct()
    {
        add_action('init', [$this, 'init']);
    }

    /**
     * Versão para cache-bust de asset (evita browser com JS/CSS antigo após deploy).
     */
    private function assetVersion(string $relativePath): string
    {
        $path = BUSCACEP_PLUGIN_DIR . $relativePath;
        if (is_readable($path)) {
            $mtime = filemtime($path);
            if ($mtime !== false) {
                return (string) $mtime;
            }
        }

        return BUSCACEP_VERSION;
    }

    public function init()
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'adminEnqueue']);
        add_action('wp_enqueue_scripts', [$this, 'frontendEnqueue']);
        add_action('rest_api_init', [new RestApi(), 'register']);
        add_shortcode('resales-map', [$this, 'resalesMapShortcode']);
    }

    /**
     * Verifica se a página atual exibe o mapa de revendas (shortcode ou template).
     */
    private function pageHasResalesMap()
    {
        if (!is_singular()) {
            return false;
        }
        $post = get_queried_object();
        if ($post && has_shortcode($post->post_content, 'resales-map')) {
            return true;
        }
        return is_page_template('rede_credenciada/rede-template.php');
    }

    /**
     * Shortcode [resales-map] para exibir o mapa de revendas no frontend.
     */
    public function resalesMapShortcode()
    {
        $verCss = $this->assetVersion('/assets/css/frontend-styles.css');
        $verJs = $this->assetVersion('/assets/js/frontend-scripts.js');
        wp_enqueue_style('buscacep-frontend', BUSCACEP_PLUGIN_URL . 'assets/css/frontend-styles.css', [], $verCss);
        wp_enqueue_script('buscacep-mask', 'https://cdn.jsdelivr.net/npm/jquery-mask-plugin@1.14.13/dist/jquery.mask.min.js', ['jquery'], BUSCACEP_VERSION, true);
        wp_enqueue_script('buscacep-google-maps', 'https://maps.googleapis.com/maps/api/js?key=AIzaSyCnAtv-xji754g4WdkO-n01sIW3R64Ch5o', [], BUSCACEP_VERSION, true);
        wp_enqueue_script('buscacep-frontend', BUSCACEP_PLUGIN_URL . 'assets/js/frontend-scripts.js', ['jquery', 'buscacep-mask', 'buscacep-google-maps'], $verJs, true);
        wp_localize_script('buscacep-frontend', 'buscaCepConfig', [
            'apiUrl'   => esc_url(rest_url('resales/v1/json')),
            'mapDebug' => defined('WP_DEBUG') && WP_DEBUG,
        ]);

        ob_start();
        include BUSCACEP_VIEW_DIR . '/map-display.php';
        return ob_get_clean();
    }

    /**
     * Registra e enfileira scripts e styles do frontend na página Rede Credenciada.
     */
    public function frontendEnqueue()
    {
        $verCss = $this->assetVersion('/assets/css/frontend-styles.css');
        $verJs = $this->assetVersion('/assets/js/frontend-scripts.js');

        wp_register_style(
            'buscacep-frontend',
            BUSCACEP_PLUGIN_URL . 'assets/css/frontend-styles.css',
            [],
            $verCss
        );

        wp_register_script(
            'buscacep-mask',
            'https://cdn.jsdelivr.net/npm/jquery-mask-plugin@1.14.13/dist/jquery.mask.min.js',
            ['jquery'],
            BUSCACEP_VERSION,
            true
        );

        wp_register_script(
            'buscacep-bootstrap-js',
            'https://stackpath.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js',
            ['jquery'],
            BUSCACEP_VERSION,
            true
        );

        wp_register_script(
            'buscacep-google-maps',
            'https://maps.googleapis.com/maps/api/js?key=AIzaSyCnAtv-xji754g4WdkO-n01sIW3R64Ch5o',
            [],
            BUSCACEP_VERSION,
            true
        );

        wp_register_script(
            'buscacep-frontend',
            BUSCACEP_PLUGIN_URL . 'assets/js/frontend-scripts.js',
            ['jquery', 'buscacep-mask', 'buscacep-google-maps'],
            $verJs,
            true
        );

        wp_localize_script('buscacep-frontend', 'buscaCepConfig', [
            'apiUrl'   => esc_url(rest_url('resales/v1/json')),
            'mapDebug' => defined('WP_DEBUG') && WP_DEBUG,
        ]);

        if ($this->pageHasResalesMap()) {
            wp_enqueue_style('buscacep-frontend');
            wp_enqueue_script('buscacep-mask');
            wp_enqueue_script('buscacep-google-maps');
            wp_enqueue_script('buscacep-frontend');
        }
    }

    /**
     * Enqueue de scripts e styles no admin.
     */
    public function adminEnqueue($hook)
    {
        if (strpos($hook, 'revendas') === false && strpos($hook, 'buscacep') === false) {
            return;
        }

        wp_enqueue_style(
            'buscacep-bootstrap',
            'https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css',
            [],
            BUSCACEP_VERSION
        );

        wp_enqueue_script(
            'buscacep-mask',
            'https://cdn.jsdelivr.net/npm/jquery-mask-plugin@1.14.13/dist/jquery.mask.min.js',
            ['jquery'],
            BUSCACEP_VERSION,
            true
        );

        wp_enqueue_script(
            'buscacep-bootstrap-js',
            'https://stackpath.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js',
            ['jquery'],
            BUSCACEP_VERSION,
            true
        );

        wp_enqueue_script(
            'buscacep-admin',
            BUSCACEP_PLUGIN_URL . 'assets/js/admin-scripts.js',
            ['jquery', 'buscacep-mask', 'buscacep-bootstrap-js'],
            BUSCACEP_VERSION,
            true
        );

        wp_localize_script('buscacep-admin', 'buscaCepAdmin', [
            'apiUrl'  => esc_url(rest_url('resales/v1/json')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);

        wp_enqueue_style(
            'buscacep-admin',
            BUSCACEP_PLUGIN_URL . 'assets/css/admin-styles.css',
            [],
            BUSCACEP_VERSION
        );
    }

    /**
     * Ativação do plugin: cria tabelas e verifica versão do WP.
     */
    public function activate()
    {
        if (version_compare(get_bloginfo('version'), BUSCACEP_MINIMUM_WP_VERSION, '<')) {
            wp_die(__(
                'Não é possível utilizar o plugin, atualize seu blog para a versão mais atual do WordPress',
                'busca-cep'
            ));
        }

        $this->createTables();
        flush_rewrite_rules();
    }

    /**
     * Desativação do plugin.
     */
    public function deactivate()
    {
        flush_rewrite_rules();
    }

    /**
     * Registra menus no admin do WordPress.
     */
    public function addMenu()
    {
        add_menu_page(
            __('Rede Credenciada', 'busca-cep'),
            __('Rede Credenciada', 'busca-cep'),
            'administrator',
            'revendas',
            [$this, 'displayDashboard'],
            'dashicons-location',
            26
        );

        add_submenu_page(
            'revendas',
            __('Configurações', 'busca-cep'),
            __('Configurações', 'busca-cep'),
            'administrator',
            'buscacep-config',
            [$this, 'displayConfig']
        );
    }

    public function displayDashboard()
    {
        require_once BUSCACEP_VIEW_DIR . '/admin-display.php';
    }

    public function displayConfig()
    {
        require_once BUSCACEP_VIEW_DIR . '/admin-submenu-display.php';
    }

    /**
     * Cria tabela de revendas e tabela de configurações no banco.
     */
    private function createTables()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'revenda';
        $config_table = $wpdb->prefix . 'revenda_config';
        $charset_collate = $wpdb->get_charset_collate();

        $query = "CREATE TABLE IF NOT EXISTS $table (
            revenda_id int(11) NOT NULL AUTO_INCREMENT,
            razao varchar(250) NOT NULL,
            fantasia varchar(250) NULL,
            segmento varchar(250) NULL,
            telefone varchar(20) NULL,
            email varchar(250) NULL,
            site varchar(250) NULL,
            cep varchar(9) NOT NULL,
            rua varchar(250) NOT NULL,
            numero int(8) NOT NULL,
            bairro varchar(250) NOT NULL,
            municipio varchar(250) NOT NULL,
            estado varchar(250) NOT NULL,
            pais varchar(250) NOT NULL,
            lat float(10,6) NOT NULL,
            lng float(10,6) NOT NULL,
            status varchar(250) NOT NULL DEFAULT 'ativo',
            date varchar(250) NOT NULL,
            token varchar(250) NOT NULL,
            PRIMARY KEY(revenda_id)
        ) $charset_collate;";

        $wpdb->query($wpdb->prepare($query));

        $config_query = "CREATE TABLE IF NOT EXISTS $config_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            token varchar(250) NULL,
            PRIMARY KEY(id)
        ) $charset_collate;";

        $wpdb->query($wpdb->prepare($config_query));
    }
}
