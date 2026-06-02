(function ($) {
    'use strict';

    var chave;
    var baseUrl = (typeof buscaCepAdmin !== 'undefined' && buscaCepAdmin.apiUrl)
        ? buscaCepAdmin.apiUrl
        : (document.location.origin + '/wp-json/resales/v1/json');

    $('input[name="cep"]').mask('00000-000');

    $('input[name="whatsapp"]').mask('(00) S0000-0000', {
        translation: { 'S': { pattern: /\d/, optional: true } }
    });
    
    $('input[name="telefone"]').mask('(00) 0000-0000');

    // Utilitários
    var listState = {
        page: 1,
        totalPages: 1,
    };

    var util = {
        escHtml: function (value) {
            return $('<div>').text(value == null ? '' : String(value)).html();
        },

        renderResaleRows: function (rows) {
            if (!Array.isArray(rows) || rows.length === 0) {
                return '<tr><td colspan="15">Nenhum cadastro nesta página.</td></tr>';
            }

            return rows.map(function (row) {
                var id = row.id || '';
                return '<tr id="filter" class="active-row">' +
                    '<td>' + util.escHtml(row.nome) + '</td>' +
                    '<td>' + util.escHtml(row.plano) + '</td>' +
                    '<td>' + util.escHtml(row.especialidade) + '</td>' +
                    '<td>' + util.escHtml(row.cnpj_crm) + '</td>' +
                    '<td>' + util.escHtml(row.whatsapp) + '</td>' +
                    '<td>' + util.escHtml(row.telefone) + '</td>' +
                    '<td>' + util.escHtml(row.horario) + '</td>' +
                    '<td>' + util.escHtml(row.cep) + '</td>' +
                    '<td>' + util.escHtml(row.rua) + '</td>' +
                    '<td>' + util.escHtml(row.numero) + '</td>' +
                    '<td>' + util.escHtml(row.bairro) + '</td>' +
                    '<td>' + util.escHtml(row.municipio) + '</td>' +
                    '<td>' + util.escHtml(row.estado) + '</td>' +
                    '<td>' + util.escHtml(row.status) + '</td>' +
                    '<td class="th-display">' +
                    '<button type="button" id="' + id + '" class="btn-primary btn-revenda btn-edit-resale">Editar</button> ' +
                    '<button type="button" id="' + id + '" class="btn-primary btn-revenda btn-delete-resale">Excluir</button>' +
                    '</td>' +
                    '</tr>';
            }).join('');
        },

        listAll: function (page) {
            if (typeof page === 'number' && page > 0) {
                listState.page = page;
            }

            $('#body-table').html(
                '<tr><td colspan="15">Carregando...</td></tr>'
            );

            $.ajax({
                url: baseUrl + '/getall',
                type: 'GET',
                dataType: 'json',
                cache: false,
                data: {
                    list_page: listState.page,
                },
                beforeSend: function (xhr) {
                    if (typeof buscaCepAdmin !== 'undefined' && buscaCepAdmin.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', buscaCepAdmin.nonce);
                    }
                },
                success: function (data) {
                    if (data && Array.isArray(data.rows)) {
                        $('#body-table').html(util.renderResaleRows(data.rows));
                        util.updateRecordCount(data.count);
                        util.updatePagination(data);
                        return;
                    }

                    // Compatibilidade com resposta antiga em HTML
                    if (data && typeof data === 'object' && data.html !== undefined) {
                        $('#body-table').html(data.html);
                        util.updateRecordCount(data.count);
                        util.updatePagination(data);
                        return;
                    }

                    $('#body-table').empty();
                    util.refreshRecordCount();
                },
                error: function () {
                    $('#body-table').html(
                        '<tr><td colspan="15">Não foi possível carregar a listagem.</td></tr>'
                    );
                    util.refreshRecordCount();
                },
            });
        },

        refreshRecordCount: function () {
            $.ajax({
                url: baseUrl + '/count',
                type: 'GET',
                dataType: 'json',
                success: function (data) {
                    if (data && typeof data.count === 'number') {
                        util.updateRecordCount(data.count);
                    }
                },
            });
        },

        updateRecordCount: function (count) {
            var display = (typeof count === 'number' && !isNaN(count))
                ? count.toLocaleString('pt-BR')
                : '—';
            $('#record-count').text(display);
        },

        updatePagination: function (data) {
            if (!data || typeof data !== 'object') {
                return;
            }

            listState.page = data.page || 1;
            listState.totalPages = data.total_pages || 1;

            var from = data.from || 0;
            var to = data.to || 0;
            var total = data.count || 0;

            if (total > 0) {
                $('#record-range').text(
                    ' (exibindo ' + from.toLocaleString('pt-BR') +
                    '–' + to.toLocaleString('pt-BR') + ')'
                );
                $('#buscacep-page-info').text(
                    'Página ' + listState.page.toLocaleString('pt-BR') +
                    ' de ' + listState.totalPages.toLocaleString('pt-BR')
                );
            } else {
                $('#record-range').text('');
                $('#buscacep-page-info').text('Nenhum cadastro');
            }

            $('#buscacep-prev-page').prop('disabled', listState.page <= 1);
            $('#buscacep-next-page').prop('disabled', listState.page >= listState.totalPages);
        },

        showPersistentNotice: function (message, type) {
            type = type || 'success';
            var safeMessage = $('<div>').text(message).html();
            var $notice = $(
                '<div class="alert alert-' + type + ' buscacep-notice">' +
                '<button type="button" class="buscacep-notice-close" aria-label="Fechar">&times;</button>' +
                '<span class="buscacep-notice-text">' + safeMessage + '</span>' +
                '</div>'
            );

            $('#buscacep-notices').append($notice);
        },

        getToken: function () {
            $.ajax({
                url: baseUrl + '/getToken',
                type: 'GET',
                dataType: 'json',
                success: function (data) {
                    $('#geo_token').val(data);
                },
            });
        },

        token: function () {
            var text = '';
            var possible = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            for (var i = 0; i < 4; i++) {
                text += possible.charAt(Math.floor(Math.random() * possible.length));
            }
            return text;
        },

        verify: function () {
            var isValid = true;
            $('.resale').each(function () {
                if ($(this).val() === '' || $(this).val() === null) {
                    $(this).css('border', 'solid 1px #e93b3b');
                    isValid = false;
                } else {
                    $(this).css('border', 'solid 1px #8c8f94');
                }
            });
            var $plano = $('#plano');
            var $especialidade = $('#especialidade');
            if (!$plano.val() || $plano.val() === '') {
                $plano.css('border', 'solid 1px #e93b3b');
                isValid = false;
            } else {
                $plano.css('border', 'solid 1px #8c8f94');
            }
            if (!$especialidade.val() || $especialidade.val().trim() === '') {
                $especialidade.css('border', 'solid 1px #e93b3b');
                isValid = false;
            } else {
                $especialidade.css('border', 'solid 1px #8c8f94');
            }
            return isValid;
        },

        searchParams: new URLSearchParams(window.location.search),

        showMessage: function (message, type) {
            type = type || 'success';
            $('#modal-message').modal('show');
            $('.modal_message').css('background-color', type === 'success' ? '#dff0d8' : '#f2dede');
            $('.modal-message').html('<div class="alert alert-' + type + '">' + $('<div>').text(message).html() + '</div>');
        },
    };

    $(document).on('click', '.buscacep-notice-close', function () {
        $(this).closest('.buscacep-notice').remove();
    });

    // Inicializar conforme a página
    switch (util.searchParams.get('page')) {
        case 'buscacep-config':
            util.getToken();
            break;
        case 'revendas':
            util.listAll(1);
            break;
    }

    $('#buscacep-prev-page').on('click', function () {
        if (listState.page > 1) {
            util.listAll(listState.page - 1);
        }
    });

    $('#buscacep-next-page').on('click', function () {
        if (listState.page < listState.totalPages) {
            util.listAll(listState.page + 1);
        }
    });

    // Abrir modal de cadastro (evita conflito com Bootstrap do tema)
    $('#btn-cadastrar').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $('#universal-resales-form')[0].reset();
        $('#btn-submit').removeAttr('id update');
        $('#btn-submit').attr('id', 'btn-submit');
        $('#modal-resale .modal-title').text('Cadastrar Rede Credenciada');
        $('#modal-resale').modal('show');
    });

    // Validação em tempo real
    $('.resale').on('change', function () {
        util.verify();
    });

    // Cadastro / Edição de revenda
    $('#btn-submit').click(function () {
        var isUpdate = $(this).attr('update') === 'true';

        if (isUpdate) {
            $.ajax({
                url: baseUrl + '/update',
                type: 'POST',
                dataType: 'json',
                data: $('#universal-resales-form').serialize() + '&id=' + $(this).attr('id') + '&token=' + util.token(),
                success: function (data) {
                    if (data.status === 'ok') {
                        util.showMessage('Rede credenciada atualizada com sucesso.');
                        util.listAll();
                    }
                },
                error: function (err) {
                    console.error('Erro ao atualizar:', err);
                },
            });
        } else {
            if (!util.verify()) {
                util.showMessage('Por favor preencha os campos destacados em vermelho!', 'danger');
                return false;
            }

            $.ajax({
                url: baseUrl + '/create',
                type: 'POST',
                dataType: 'json',
                data: $('#universal-resales-form').serialize() + '&token=' + util.token(),
                success: function () {
                    util.showMessage('Rede credenciada cadastrada com sucesso.');
                    util.listAll();
                },
                error: function (err) {
                    console.error('Erro ao cadastrar:', err);
                },
            });
        }
    });

    // Editar revenda
    $(document).on('click', '.btn-edit-resale', function () {
        var id = $(this).attr('id');

        $.ajax({
            url: baseUrl + '/getDetails/' + id + '/edit',
            type: 'GET',
            dataType: 'json',
            success: function (data) {
                $('#universal-resales-form :input').map(function () {
                    $(this).val(data[$(this).attr('name')]);
                });
                $('.btn-submit').attr('id', id).attr('update', true);
                $('#modal-resale').modal('show');
            },
        });
    });

    // Excluir revenda
    $(document).on('click', '.btn-delete-resale', function () {
        var id = $(this).attr('id');

        $.ajax({
            url: baseUrl + '/getDetails/' + id + '/delete',
            type: 'GET',
            dataType: 'json',
            success: function (data) {
                $('#modal-confirm-delete').find('.modal-body').html(data);
                $('.btn-delete-submit').attr('id', id);
                $('#modal-confirm-delete').modal('show');

                $('.btn-delete-submit')
                    .off('click')
                    .on('click', function () {
                        $.ajax({
                            url: baseUrl + '/delete',
                            type: 'POST',
                            dataType: 'json',
                            data: '&id=' + $(this).attr('id'),
                            success: function (data) {
                                if (data.status === 'ok') {
                                    util.showMessage('Rede credenciada excluída com sucesso.');
                                    $('#modal-confirm-delete').modal('hide');
                                    util.listAll();
                                }
                            },
                            error: function (err) {
                                console.error('Erro ao excluir:', err);
                            },
                        });
                    });
            },
        });
    });

    // Salvar configurações
    $('#salve-config').click(function () {
        $.ajax({
            url: baseUrl + '/config',
            type: 'POST',
            dataType: 'json',
            data: $('.Cadastro').serialize(),
            success: function (data) {
                if (data.status === 'ok' && data.body_response) {
                    util.showMessage(data.body_response);
                } else {
                    util.showMessage(data.body_response || 'Erro ao salvar', 'danger');
                }
            },
        });
    });

    // Importar arquivo (em lotes para evitar timeout do servidor)
    $('#import-csv').on('click', function () {
        $('#import').click();
    });

    function updateImportProgress(processed, total) {
        $('#process_data').text(processed);
        $('#total_data').text(total);
        var pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
        $('.progress-bar').css('width', pct + '%').attr('aria-valuenow', pct);
    }

    function finishImportUi(success, message) {
        $('#import-csv').prop('disabled', false);
        $('.import-form')[0].reset();
        $('.progress-bar-messages').empty();
        $('#process').css('display', 'none');
        util.showPersistentNotice(message, success ? 'success' : 'danger');
        if (success) {
            util.listAll();
        }
    }

    function runImportBatch(importId, total) {
        $.ajax({
            url: baseUrl + '/upload_file/process',
            type: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ import_id: importId }),
            beforeSend: function (xhr) {
                if (typeof buscaCepAdmin !== 'undefined' && buscaCepAdmin.nonce) {
                    xhr.setRequestHeader('X-WP-Nonce', buscaCepAdmin.nonce);
                }
            },
            success: function (data) {
                if (!data || data.success !== true) {
                    finishImportUi(false, (data && (data.error || data.message)) || 'Erro ao processar lote da importação.');
                    return;
                }

                updateImportProgress(data.processed || 0, total);
                var parts = [];
                if (data.total_saved > 0) {
                    parts.push(data.total_saved + ' gravado(s)');
                }
                if (data.unchanged > 0) {
                    parts.push(data.unchanged + ' sem alteração');
                }
                if (data.geo_reused > 0) {
                    parts.push(data.geo_reused + ' coords reutilizadas');
                }
                if (data.geo_api_calls > 0) {
                    parts.push(data.geo_api_calls + ' API Google');
                }
                if (parts.length > 0) {
                    $('.progress-bar-messages').html(
                        '<div class="alert alert-info">Importando ' + data.processed + ' de ' + total +
                        ' — ' + parts.join(', ') + '...</div>'
                    );
                }

                if (!data.finished) {
                    runImportBatch(importId, total);
                    return;
                }

                if (data.import_success) {
                    var msg = data.msg || ('Importação concluída. ' + (data.total_saved || 0) + ' registro(s) processado(s).');
                    if (typeof data.record_count === 'number') {
                        util.updateRecordCount(data.record_count);
                    }
                    finishImportUi(true, msg);
                } else {
                    finishImportUi(false, data.error || 'Nenhum registro foi importado.');
                }
            },
            error: function (xhr) {
                var msg = 'Erro na importação.';
                if (xhr.responseJSON) {
                    msg = xhr.responseJSON.error || xhr.responseJSON.message || msg;
                } else if (xhr.status === 403) {
                    msg = 'Acesso negado. Verifique se está logado como administrador.';
                } else if (xhr.status === 504 || xhr.status === 502 || xhr.status === 524) {
                    msg = 'Lote da importação excedeu o tempo limite do servidor. Tente novamente.';
                } else if (xhr.status === 0) {
                    msg = 'Conexão interrompida durante a importação. Tente novamente.';
                } else if (xhr.status) {
                    msg = 'Erro HTTP ' + xhr.status + ' durante a importação.';
                }
                finishImportUi(false, msg);
            },
        });
    }

    $('#import').change(function () {
        chave = util.token();

        if (!this.files || !this.files[0]) return;

        if ($('#sync_mode').is(':checked')) {
            if (!window.confirm('Modo sincronização: cadastros que não estiverem na planilha serão EXCLUÍDOS ao final da importação. Deseja continuar?')) {
                $(this).val('');
                return;
            }
        }

        var extension = this.files[0].name.split('.').pop().toLowerCase();
        if (extension !== 'csv') {
            alert('Selecione um arquivo CSV.');
            return;
        }

        var formData = new FormData($('.import-form')[0]);

        $('#process').css('display', 'block');
        updateImportProgress(0, 0);
        $('.progress-bar-messages').html('<div class="alert alert-info">Preparando arquivo...</div>');
        $('#import-csv').prop('disabled', true);

        $.ajax({
            url: baseUrl + '/upload_file/init',
            type: 'POST',
            data: formData,
            contentType: false,
            dataType: 'json',
            cache: false,
            processData: false,
            beforeSend: function (xhr) {
                if (typeof buscaCepAdmin !== 'undefined' && buscaCepAdmin.nonce) {
                    xhr.setRequestHeader('X-WP-Nonce', buscaCepAdmin.nonce);
                }
            },
            success: function (data) {
                if (!data || data.success !== true) {
                    finishImportUi(false, (data && (data.error || data.message)) || 'Erro ao preparar a importação.');
                    return;
                }

                if (!data.total || data.total < 1) {
                    finishImportUi(false, 'Arquivo sem linhas de dados para importar.');
                    return;
                }

                var delimiterMsg = data.delimiter === ';' ? ' (detectado separador ponto e vírgula)' : '';
                var syncMsg = data.sync_mode ? ' — modo sincronização ativo' : '';
                $('.progress-bar-messages').html(
                    '<div class="alert alert-info">Importando ' + data.total + ' registro(s)' + delimiterMsg + syncMsg + '...</div>'
                );
                updateImportProgress(0, data.total);
                runImportBatch(data.import_id, data.total);
            },
            error: function (xhr) {
                var msg = 'Erro ao enviar o arquivo.';
                if (xhr.responseJSON) {
                    msg = xhr.responseJSON.error || xhr.responseJSON.message || msg;
                } else if (xhr.status) {
                    msg = 'Erro HTTP ' + xhr.status + ' ao enviar o arquivo.';
                }
                finishImportUi(false, msg);
            },
        });
    });

    // Exportar - redireciona para o endpoint que dispara o download
    $('.export-csv').click(function () {
        window.location.href = baseUrl + '/export';
    });
})(jQuery);

// Função de pesquisa global na tabela
function search() {
    var input = document.getElementById('bar');
    var filter = input.value.toUpperCase();
    var tbody = document.getElementById('body-table');
    var tr = tbody.getElementsByTagName('tr');

    for (var i = 0; i < tr.length; i++) {
        var td = tr[i];
        if (td) {
            var txtValue = td.textContent || td.innerText;
            tr[i].style.display = txtValue.toUpperCase().indexOf(filter) > -1 ? '' : 'none';
        }
    }
}
