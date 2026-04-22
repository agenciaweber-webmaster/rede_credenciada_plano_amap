<?php if (!defined('ABSPATH')) exit; ?>
<div class="busca-lojas-container">
    <h2 class="busca-lojas-titulo"><?php _e('Busque os planos mais próximos', 'busca-cep'); ?></h2>
    <p class="busca-lojas-subtitulo"><?php _e('Encontre atendimento agora: digite o seu CEP no campo abaixo para localizar clínicas, hospitais e laboratórios mais próximos de você.', 'busca-cep'); ?></p>

    <div class="busca-lojas-body">
        <div class="busca-lojas-coluna-lista">
            <div class="busca-filtros-busca-linha">
                <form class="busca-mapa" id="form-busca-cep">
                    <input type="text" class="form-control input-cep" id="buscacep-input" placeholder="<?php echo esc_attr__('Digite o seu CEP', 'busca-cep'); ?>">
                    <button type="submit" class="btn-buscar">
                        <i class="fa fa-search btn-icon-search" aria-hidden="true"></i>
                        <i class="fa fa-spinner fa-spin btn-icon-loading" aria-hidden="true"></i>
                    </button>
                </form>
            </div>
            <div class="busca-filtros-extra-linha">
                <div class="busca-filtros-selects">
                    <div class="filtro-plano-wrap filtro-select-wrap" style="display:none;">
                        <select id="filtro-plano" class="form-control" aria-label="<?php esc_attr_e('Filtrar por plano', 'busca-cep'); ?>">
                            <option value=""><?php _e('Filtrar por plano', 'busca-cep'); ?></option>
                        </select>
                    </div>
                    <div class="filtro-especialidade-wrap filtro-select-wrap" style="display:none;">
                        <select id="filtro-especialidade" class="form-control" aria-label="<?php esc_attr_e('Filtrar por especialidade', 'busca-cep'); ?>">
                            <option value=""><?php _e('Filtrar por especialidade', 'busca-cep'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="busca-imprimir-inline-wrap" aria-hidden="true">
                    <button type="button" class="btn-busca-imprimir" id="btn-busca-imprimir" disabled aria-label="<?php esc_attr_e('Imprimir informações das redes listadas', 'busca-cep'); ?>"><?php _e('Imprimir informações', 'busca-cep'); ?></button>
                </div>
            </div>
            <div class="scroll-lojas" id="lista-lojas"></div>
        </div>
        <div class="busca-lojas-coluna-detalhes">
            <div class="perfil-rede-inline" id="perfil-rede-content"></div>
            <div class="busca-lojas-mapa" id="revenderores_maps"></div>
        </div>
    </div>

    <div class="busca-imprimir-modal" id="busca-imprimir-modal" aria-hidden="true">
        <div class="busca-imprimir-backdrop" id="busca-imprimir-fechar-backdrop" tabindex="-1"></div>
        <div class="busca-imprimir-dialog" role="dialog" aria-labelledby="busca-imprimir-titulo" aria-modal="true">
            <h3 class="busca-imprimir-titulo" id="busca-imprimir-titulo"><?php _e('Imprimir informações das redes', 'busca-cep'); ?></h3>
            <p class="busca-imprimir-ajuda"><?php _e('Selecione uma ou mais redes da lista atual (após busca e filtros) e confirme para abrir a visualização de impressão.', 'busca-cep'); ?></p>
            <form class="busca-imprimir-form" id="busca-imprimir-form">
                <div class="busca-imprimir-toolbar">
                    <label class="busca-imprimir-todas-label">
                        <input type="checkbox" id="busca-imprimir-todas">
                        <?php _e('Selecionar todas', 'busca-cep'); ?>
                    </label>
                </div>
                <div class="busca-imprimir-checkboxes" id="busca-imprimir-checkboxes"></div>
                <div class="busca-imprimir-actions">
                    <button type="button" class="btn-imprimir-cancelar" id="busca-imprimir-cancelar"><?php _e('Cancelar', 'busca-cep'); ?></button>
                    <button type="submit" class="btn-imprimir-confirmar" id="busca-imprimir-confirmar"><?php _e('Imprimir', 'busca-cep'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
