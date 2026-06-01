(function ($) {
    'use strict';

    var chave;
    var clear_timer;
    var baseUrl = (typeof buscaCepAdmin !== 'undefined' && buscaCepAdmin.apiUrl)
        ? buscaCepAdmin.apiUrl
        : (document.location.origin + '/wp-json/resales/v1/json');

    $('input[name="cep"]').mask('00000-000');

    $('input[name="whatsapp"]').mask('(00) S0000-0000', {
        translation: { 'S': { pattern: /\d/, optional: true } }
    });
    
    $('input[name="telefone"]').mask('(00) 0000-0000');

    // Utilitários
    var util = {
        listAll: function () {
            $.ajax({
                url: baseUrl + '/getall',
                type: 'GET',
                dataType: 'json',
                success: function (data) {
                    $('#body-table').html(data);
                },
            });
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
            $('.modal-message').html('<div class="alert alert-' + type + '">' + message + '</div>');

            clear_timer = setTimeout(function () {
                $('#modal-message').modal('hide');
                clearInterval(clear_timer);
            }, 1500);
        },
    };

    // Inicializar conforme a página
    switch (util.searchParams.get('page')) {
        case 'buscacep-config':
            util.getToken();
            break;
        case 'revendas':
            util.listAll();
            break;
    }

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
                        setTimeout(function () {
                            util.listAll();
                        }, 1600);
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
                    setTimeout(function () {
                        util.listAll();
                    }, 1600);
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
                                    setTimeout(function () {
                                        $('#modal-confirm-delete').modal('hide');
                                        util.listAll();
                                    }, 1600);
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
        var alertClass = success ? 'alert-success' : 'alert-danger';
        $('.progress-bar-messages').html('<div class="alert ' + alertClass + '">' + message + '</div>');
        $('.import-form')[0].reset();
        if (success) {
            util.listAll();
        }
        setTimeout(function () { $('#process').css('display', 'none'); }, success ? 3000 : 8000);
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
                    if (data.erros > 0) {
                        msg += ' ' + data.erros + ' linha(s) com erro.';
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
                $('.progress-bar-messages').html(
                    '<div class="alert alert-info">Importando ' + data.total + ' registro(s)' + delimiterMsg + '...</div>'
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
