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

    // Importar arquivo
    $('#import-csv').on('click', function () {
        $('#import').click();
    });

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
        $('.progress-bar-messages').html('<div class="alert alert-info">Aguarde, processando importação...</div>');
        $('#import-csv').prop('disabled', true);

        $.ajax({
            url: baseUrl + '/upload_file',
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
                $('#import-csv').prop('disabled', false);
                if (data.success) {
                    var msg = data.msg || ('Arquivo importado. ' + (data.total || 0) + ' registro(s) processado(s).');
                    if (data.erros > 0) msg += ' ' + data.erros + ' linha(s) com erro.';
                    $('.progress-bar-messages').html('<div class="alert alert-success">' + msg + '</div>');
                    util.listAll();
                    setTimeout(function () { $('#process').css('display', 'none'); }, 3000);
                } else {
                    var err = data.error || data.message || 'Erro na importação.';
                    $('.progress-bar-messages').html('<div class="alert alert-danger">' + err + '</div>');
                    setTimeout(function () { $('#process').css('display', 'none'); }, 5000);
                }
                $('.import-form')[0].reset();
            },
            error: function (xhr) {
                $('#import-csv').prop('disabled', false);
                var msg = 'Erro na importação.';
                if (xhr.responseJSON) {
                    msg = xhr.responseJSON.error || xhr.responseJSON.message || msg;
                } else if (xhr.status === 403) {
                    msg = 'Acesso negado. Verifique se está logado como administrador.';
                } else if (xhr.status === 0) {
                    msg = 'Erro de conexão. Verifique sua rede.';
                }
                $('.progress-bar-messages').html('<div class="alert alert-danger">' + msg + '</div>');
                $('.import-form')[0].reset();
                setTimeout(function () { $('#process').css('display', 'none'); }, 5000);
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
