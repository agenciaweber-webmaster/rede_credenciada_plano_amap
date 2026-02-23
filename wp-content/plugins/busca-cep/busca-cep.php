<?php

/**
 * @package BuscaCep
 */

/**
 * Plugin Name: Rede Credenciada
 * Author: Agencia Weber
 * Plugin URI:
 * Author URI:
 * Version: 1.0.0
 * License: Coming soon
 * Description: Cadastro e consulta à rede credenciada por CEP
 * Text Domain: busca-cep
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BUSCACEP_VERSION', '6.1.3');
define('BUSCACEP_MINIMUM_WP_VERSION', '6.5');
define('BUSCACEP_PLUGIN_DIR', __DIR__);
define('BUSCACEP_VIEW_DIR', BUSCACEP_PLUGIN_DIR . '/views');
define('BUSCACEP_COMPONENTS_DIR', BUSCACEP_PLUGIN_DIR . '/components');
define('BUSCACEP_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('BUSCACEP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoload vendor
if (!defined('BUSCACEP_VENDOR_DIR')) {
    define('BUSCACEP_VENDOR_DIR', BUSCACEP_PLUGIN_DIR . '/vendor');
}

foreach (glob(BUSCACEP_VENDOR_DIR . "/*/src/*.php") as $file) {
    require_once $file;
}

foreach (glob(BUSCACEP_VENDOR_DIR . "/*/helpers/*.php") as $file) {
    require_once $file;
}

// Autoload includes
$autoload_dirs = [
    BUSCACEP_PLUGIN_DIR . '/includes/Helpers',
    BUSCACEP_PLUGIN_DIR . '/includes/Models',
    BUSCACEP_PLUGIN_DIR . '/includes/Controllers',
];

foreach ($autoload_dirs as $dir) {
    foreach (glob($dir . "/*.php") as $file) {
        require_once $file;
    }
}

// Initialize plugin
$buscaCepPlugin = new BuscaCep\Controllers\AdminConfig();

// Hooks
register_activation_hook(__FILE__, [$buscaCepPlugin, 'activate']);
register_deactivation_hook(__FILE__, [$buscaCepPlugin, 'deactivate']);
