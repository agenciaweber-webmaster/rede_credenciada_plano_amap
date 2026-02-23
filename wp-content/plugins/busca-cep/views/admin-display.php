<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
    <main class="main">
        <h2 class="title-plugin">
            <?php _e('Rede Credenciada', 'busca-cep'); ?>
        </h2>
        <header class="up-header">
            <button class="btn btn-primary" id="btn-cadastrar" type="button">
                <?php _e('Cadastrar', 'busca-cep'); ?>
            </button>
            <button type="button" id="import-csv" class="btn btn-primary import-csv">
                <?php _e('Importar CSV', 'busca-cep'); ?>
            </button>
            <button type="button" id="export-csv" class="btn btn-primary export-csv">
                <?php _e('Exportar', 'busca-cep'); ?>
            </button>
            <form class="import-form" id="import-form" enctype="multipart/form-data" action="return false();">
                <input type="file" name="import" id="import" value="import" class="import" accept=".csv">
            </form>
            <input type="text" onkeyup="search()" id="bar" class="search-form" placeholder="<?php _e('Pesquisar', 'busca-cep'); ?>" title="<?php _e('Pesquisa', 'busca-cep'); ?>">
        </header>
        <br>
        <div class="form-group" id="process" style="display:none;">
            <div class="progress-bar-messages"></div>
            <div class="progress">
                <div class="progress-bar-lines">
                    <span id="process_data">0</span> - <span id="total_data">0</span>
                </div>
                <div class="progress-bar progress-bar-striped active" role="progressbar" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
        </div>
        <p class="buscacep-info-message">
            <?php _e('Para cada especialidade atendida, deve existir um cadastro próprio. Não agrupe múltiplas especialidades em um único registro.', 'busca-cep'); ?>
        </p>
        <p class="buscacep-info-message">
            <?php _e('O arquivo CSV deve estar em UTF-8, utilizar vírgula como separador e aspas nos campos quando necessário.', 'busca-cep'); ?>
        </p>
        <table class="table-revendas">
                    <thead>
                <tr>
                    <th scope="col"><?php _e('Nome', 'busca-cep'); ?></th>
                    <th scope="col"><?php _e('Plano', 'busca-cep'); ?></th>
                    <th scope="col"><?php _e('Especialidade', 'busca-cep'); ?></th>
                    <th scope="col"><?php _e('CNPJ/CRM', 'busca-cep'); ?></th>
                    <th scope="col"><?php _e('WhatsApp', 'busca-cep'); ?></th>
                    <th scope="col"><?php _e('Telefone', 'busca-cep'); ?></th>
                    <th scope="col"><?php _e('Horário', 'busca-cep'); ?></th>
                    <th scope="col"><?php _e('CEP', 'busca-cep'); ?></th>
                    <th scope="col"><?php _e('Rua', 'busca-cep'); ?></th>
                    <th scope="col"><?php _e('Número', 'busca-cep'); ?></th>
                    <th scope="col"><?php _e('Bairro', 'busca-cep'); ?></th>
                    <th scope="col"><?php _e('Cidade', 'busca-cep'); ?></th>
                    <th scope="col"><?php _e('Estado', 'busca-cep'); ?></th>
                    <th scope="col"><?php _e('Status', 'busca-cep'); ?></th>
                    <th colspan="2"><?php _e('Ações', 'busca-cep'); ?></th>
                </tr>
            </thead>
            <tbody id="body-table"></tbody>
        </table>

        <!-- Modal de cadastro/edição -->
        <div class="modal fade revenda" id="modal-resale" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static" data-keyboard="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="<?php _e('Fechar', 'busca-cep'); ?>">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <h5 class="modal-title"><?php _e('Cadastrar Rede Credenciada', 'busca-cep'); ?></h5>
                    </div>
                    <div class="modal-body revenda">
                        <div class="Cadastro-revenda">
                            <h4><?php _e('Obs.: Informações como rua, bairro e etc. são preenchidas automaticamente utilizando o CEP.', 'busca-cep'); ?></h4>
                            <form id="universal-resales-form" class="Cadastro" method="POST" action="return false();">
                                <label for="nome"><?php _e('Nome:*', 'busca-cep'); ?></label>
                                <input type="text" name="nome" id="nome" class="form-control resale">
                                <br>
                                <label for="plano"><?php _e('Plano:*', 'busca-cep'); ?></label>
                                <select name="plano" id="plano" class="form-control">
                                    <option value=""><?php _e('-- Selecione --', 'busca-cep'); ?></option>
                                    <option value="AMO Médico">AMO Médico</option>
                                    <option value="AMO Odonto">AMO Odonto</option>
                                    <option value="AMAP Médico">AMAP Médico</option>
                                </select>
                                <br>
                                <label for="especialidade"><?php _e('Especialidade:*', 'busca-cep'); ?></label>
                                <input type="text" name="especialidade" id="especialidade" class="form-control" placeholder="<?php _e('Ex: Cardiologia, Odontologia', 'busca-cep'); ?>">
                                <br>
                                <label for="cnpj"><?php _e('CNPJ/CRM', 'busca-cep'); ?></label>
                                <input type="text" name="cnpj" id="cnpj" class="form-control" placeholder="<?php _e('CNPJ (14 dígitos) ou CRM', 'busca-cep'); ?>">
                                <br>
                                <label for="whatsapp"><?php _e('WhatsApp', 'busca-cep'); ?></label>
                                <input type="text" name="whatsapp" id="whatsapp" class="form-control" placeholder="(00) 00000-0000">
                                <br>
                                <label for="telefone"><?php _e('Telefone:*', 'busca-cep'); ?></label>
                                <input type="text" name="telefone" id="telefone" class="form-control resale">
                                <br>
                                <label for="horario"><?php _e('Horário de funcionamento', 'busca-cep'); ?></label>
                                <input type="text" name="horario" id="horario" class="form-control" placeholder="<?php _e('Ex: Seg a Sex 8h às 18h', 'busca-cep'); ?>">
                                <br>
                                <label for="cep"><?php _e('CEP:*', 'busca-cep'); ?></label>
                                <input type="text" name="cep" id="cep" class="form-control resale">
                                <br>
                                <label for="numero"><?php _e('Número:*', 'busca-cep'); ?></label>
                                <input type="number" name="numero" id="numero" class="form-control resale">
                                <br>
                                <label for="status"><?php _e('Status:*', 'busca-cep'); ?></label>
                                <select name="status" class="status form-control" id="status">
                                    <option value="ativo"><?php _e('Ativo', 'busca-cep'); ?></option>
                                    <option value="inativo"><?php _e('Inativo', 'busca-cep'); ?></option>
                                </select>
                            </form>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" id="btn-submit" class="btn btn-primary btn-submit">
                            <?php _e('Salvar', 'busca-cep'); ?>
                        </button>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">
                            <?php _e('Cancelar', 'busca-cep'); ?>
                        </button>
                    </div>
                </div>
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

        <!-- Modal de confirmação de exclusão -->
        <div class="modal fade delete" id="modal-confirm-delete" role="dialog">
            <div class="modal-dialog modal-confirm" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                        <h4 class="modal-title w-100"><?php _e('Você tem certeza?', 'busca-cep'); ?></h4>
                    </div>
                    <div class="modal-body"></div>
                    <div class="modal-footer justify-content-center">
                        <button type="button" class="btn btn-danger btn-revenda btn-delete-submit"><?php _e('Excluir', 'busca-cep'); ?></button>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php _e('Cancelar', 'busca-cep'); ?></button>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
