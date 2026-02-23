(function ($) {
    'use strict';

    var baseUrl = (typeof buscaCepConfig !== 'undefined' && buscaCepConfig.apiUrl)
        ? buscaCepConfig.apiUrl
        : (document.location.origin + '/wp-json/resales/v1/json');

    var mapaGlobal = null;
    var marcadoresGlobal = [];
    var resultadoCompleto = null;
    var resultadoExibido = [];

    $('.input-cep').mask('00000-000');

    function setMarkers(map, locations) {
        clearMarkers();
        for (var i = 0; i < locations.length; i++) {
            var marker = new google.maps.Marker({
                position: new google.maps.LatLng(locations[i][1], locations[i][2]),
                map: map,
                title: locations[i][0],
                zIndex: locations[i][3] || i,
            });
            marcadoresGlobal.push(marker);
        }
    }

    function clearMarkers() {
        for (var i = 0; i < marcadoresGlobal.length; i++) {
            marcadoresGlobal[i].setMap(null);
        }
        marcadoresGlobal = [];
    }

    function renderizarPerfil(rede) {
        if (!rede) {
            $('#perfil-rede-content').html('');
            return;
        }

        var whats = rede.whatsapp;
        if (whats) {
            var num = whats.replace(/\D/g, '');
            if (num.length >= 10) {
                num = num.replace(/^0/, '');
                var waIcon = '<svg class="whatsapp-icon-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';
                whats = '<a href="https://wa.me/55' + num + '" target="_blank" rel="noopener" class="perfil-whatsapp-link">' +
                    waIcon + rede.whatsapp + '</a>';
            }
        } else {
            whats = '-';
        }

        var endereco = rede.endereco || '-';
        var horario = rede.horario || '-';
        var cnpjCrm = rede.cnpj || '';
        var cnpjCrmLabel = cnpjCrm && cnpjCrm.replace(/\D/g, '').length === 14 ? 'CNPJ' : 'CRM';
        var phoneIcon = '<svg class="phone-icon-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>';
        var telParts = [];
        if (rede.telefone) telParts.push(phoneIcon + ' ' + rede.telefone);
        if (whats !== '-') telParts.push(whats); else if (rede.whatsapp) telParts.push(phoneIcon + ' ' + rede.whatsapp);
        var linhaTelefones = telParts.length > 0 ? telParts.join(' / ') : '-';
        var nomeRede = rede.nome || '-';
        var html = '<div class="perfil-nome">' + nomeRede + '</div>' +
            (cnpjCrm ? '<div class="perfil-item">' + cnpjCrmLabel + ': ' + cnpjCrm + '</div>' : '') +
            '<div class="perfil-item">' + endereco + '</div>' +
            '<div class="perfil-item">' + horario + '</div>' +
            '<div class="perfil-item">' + linhaTelefones + '</div>';

        $('#perfil-rede-content').html(html);
    }

    function renderizarLista(resales, selecionarPrimeiro) {
        resultadoExibido = resales || [];
        var html = '';
        if (resales && resales.length > 0) {
            $(resales).each(function (i, b) {
                var plano = b.plano || '';
                var esp = b.especialidade || '';
                html += '<div class="lojas-proximas js-item-lista' + (i === 0 && selecionarPrimeiro ? ' ativo' : '') + '" data-index="' + i + '">' +
                    '<div class="info-lojas">' +
                    (plano ? '<span class="info-plano">' + plano + '</span>' : '') +
                    '<span class="info-nome-wrap">' +
                    '<strong class="info-nome">' + (b.nome || '-') + '</strong>' +
                    (esp ? '<span class="info-especialidade">' + esp + '</span>' : '') +
                    '</span>';
                if (b.distancia) {
                    html += '<span class="distancia">' + b.distancia + '</span>';
                }
                html += '<p class="info-endereco">' + (b.endereco || '-') + '</p>' +
                    '</div></div>';
            });
            $('.scroll-lojas').html(html);

            if (selecionarPrimeiro && resales[0]) {
                renderizarPerfil(resales[0]);
            }
        } else {
            var msgEmpty = '<div class="busca-sem-resultado">Nenhum plano encontrado próximo a este CEP.</div>';
            $('.scroll-lojas').html(msgEmpty);
            renderizarPerfil(null);
        }
    }

    function aplicarFiltro() {
        if (!resultadoCompleto) return;

        var especialidade = $('#filtro-especialidade').val();
        var resales = resultadoCompleto.resales;
        var makers = resultadoCompleto.makers;

        if (especialidade) {
            var filtrados = [];
            var makersFiltrados = [];
            $(resales).each(function (i, r) {
                var esp = (r.especialidade || '').toString();
                if (esp === especialidade || esp.indexOf(especialidade) !== -1) {
                    filtrados.push(r);
                    makersFiltrados.push(makers[i]);
                }
            });
            resales = filtrados;
            makers = makersFiltrados;
        }

        renderizarLista(resales, true);

        if (mapaGlobal && makers.length > 0) {
            setMarkers(mapaGlobal, makers);
            mapaGlobal.setCenter(new google.maps.LatLng(makers[0][1], makers[0][2]));
        } else if (mapaGlobal && makers.length === 0) {
            clearMarkers();
        }
    }

    function processarResposta(obj) {
        if (obj.status === 'ok') {
            renderizarLista([], false);
            $('.filtro-especialidade-wrap').hide();
            return;
        }

        resultadoCompleto = obj;

        var $select = $('#filtro-especialidade');
        $select.empty().append('<option value="">Filtrar por especialidade</option>');
        if (obj.especialidades && obj.especialidades.length > 0) {
            $(obj.especialidades).each(function (i, esp) {
                $select.append('<option value="' + esp + '">' + esp + '</option>');
            });
            $('.filtro-especialidade-wrap').show();
        } else {
            $('.filtro-especialidade-wrap').hide();
        }

        renderizarLista(obj.resales, true);

        if (obj.makers && obj.makers.length > 0) {
            setMarkers(mapaGlobal, obj.makers);
            mapaGlobal.setCenter(new google.maps.LatLng(obj.makers[0][1], obj.makers[0][2]));
            mapaGlobal.setZoom(12);
        }
    }

    google.maps.event.addDomListener(window, 'load', function () {
        mapaGlobal = new google.maps.Map(document.getElementById('revenderores_maps'), {
            zoom: 12,
            center: new google.maps.LatLng(-23.4958167, -46.6396306),
            mapTypeId: google.maps.MapTypeId.ROADMAP,
        });
    });

    $('#form-busca-cep').on('submit', function (e) {
        e.preventDefault();
        var cep = $('.input-cep').val().replace(/\D/g, '');
        if (cep.length < 8) {
            alert('Digite um CEP válido');
            return false;
        }
        var cepFormatado = cep.length === 8 ? cep.replace(/(\d{5})(\d{3})/, '$1-$2') : cep;
        $('.btn-buscar').addClass('loading').prop('disabled', true);

        $.ajax({
            url: baseUrl + '/consult/' + cepFormatado,
            type: 'GET',
            success: function (obj) {
                $('.btn-buscar').removeClass('loading').prop('disabled', false);
                processarResposta(obj);
            },
            error: function () {
                $('.btn-buscar').removeClass('loading').prop('disabled', false);
                renderizarLista([], false);
            }
        });
    });

    $(document).on('change', '#filtro-especialidade', function () {
        if (resultadoCompleto) aplicarFiltro();
    });

    $(document).on('click', '.js-item-lista', function () {
        var idx = $(this).data('index');
        $('.lojas-proximas').removeClass('ativo');
        $(this).addClass('ativo');
        if (resultadoExibido && resultadoExibido[idx]) {
            renderizarPerfil(resultadoExibido[idx]);
        }
    });
})(jQuery);
