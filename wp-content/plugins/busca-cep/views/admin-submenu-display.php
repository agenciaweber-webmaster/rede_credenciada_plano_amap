<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
    <main class="main">
        <h2 class="title-plugin">
            <?php _e('Configurações', 'busca-cep'); ?>
        </h2>
        <div class="body-configuration">
            <div class="Cadastro-revenda">
                <h4><?php _e('Obs.: Você precisa inserir os tokens necessários para o plugin funcionar.', 'busca-cep'); ?></h4>
                <form class="Cadastro" method="POST" action="return false();">
                    <label for="geo_token"><?php _e('Token API Geocode Google:*', 'busca-cep'); ?></label>
                    <div class="config-input-row">
                        <input type="text" name="geo_token" id="geo_token" class="form-control resale">
                        <button class="btn btn-primary" id="salve-config" type="button">
                            <?php _e('Salvar', 'busca-cep'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal de mensagem -->
        <div class="modal fade" id="modal-message" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content modal_message">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="<?php _e('Fechar', 'busca-cep'); ?>">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <span class="modal-message" id="message"></span>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
