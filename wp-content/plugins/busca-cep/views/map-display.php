<?php if (!defined('ABSPATH')) exit; ?>
<div class="busca-lojas-container">
    <h2 class="busca-lojas-titulo"><?php _e('Busque os planos mais próximos', 'busca-cep'); ?></h2>

    <div class="busca-lojas-body">
        <div class="busca-lojas-coluna-lista">
            <div class="busca-filtros-row">
                <form class="busca-mapa" id="form-busca-cep">
                    <input type="text" class="form-control input-cep" id="buscacep-input" placeholder="<?php echo esc_attr__('Digite o seu CEP', 'busca-cep'); ?>">
                    <button type="submit" class="btn-buscar">
                        <i class="fa fa-search btn-icon-search" aria-hidden="true"></i>
                        <i class="fa fa-spinner fa-spin btn-icon-loading" aria-hidden="true"></i>
                    </button>
                </form>
                <div class="filtro-especialidade-wrap" style="display:none;">
                    <select id="filtro-especialidade" class="form-control" aria-label="<?php esc_attr_e('Filtrar por especialidade', 'busca-cep'); ?>">
                        <option value=""><?php _e('Filtrar por especialidade', 'busca-cep'); ?></option>
                    </select>
                </div>
            </div>
            <div class="scroll-lojas" id="lista-lojas"></div>
        </div>
        <div class="busca-lojas-coluna-detalhes">
            <div class="perfil-rede-inline" id="perfil-rede-content"></div>
            <div class="busca-lojas-mapa" id="revenderores_maps"></div>
        </div>
    </div>
</div>
