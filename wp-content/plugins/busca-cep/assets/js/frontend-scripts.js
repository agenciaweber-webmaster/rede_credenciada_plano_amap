(function ($) {
    'use strict';

    var baseUrl = (typeof buscaCepConfig !== 'undefined' && buscaCepConfig.apiUrl)
        ? buscaCepConfig.apiUrl
        : (document.location.origin + '/wp-json/resales/v1/json');

    var mapaGlobal = null;
    var marcadoresGlobal = [];
    var resultadoCompleto = null;
    var resultadoExibido = [];
    /** Snapshot dos grupos no momento em que o modal foi aberto (índices dos checkboxes batem com este array). */
    var gruposImpressaoAtual = null;

    /** Centro inicial: Salvador/BA (faixa 40xxx). O centróide do Brasil (-14.23, -51.92) deixava o mapa genérico demais antes da busca. */
    var MAPA_DEFAULT_CENTER = { lat: -12.9714, lng: -38.5014 };

    $('.input-cep').mask('00000-000');

    var PHONE_ICON_SVG_LISTA = '<svg class="phone-icon-svg phone-icon-svg--lista" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="13" height="13" aria-hidden="true"><path fill="currentColor" d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>';

    function escHtml(s) {
        if (s === null || s === undefined) {
            return '';
        }
        return $('<div/>').text(String(s)).html();
    }

    function parseCoord(v) {
        if (typeof v === 'number' && isFinite(v)) {
            return v;
        }
        if (v === null || v === undefined || v === '') {
            return NaN;
        }
        return parseFloat(String(v).replace(',', '.'));
    }

    function criarMapaGlobal() {
        if (mapaGlobal) {
            return mapaGlobal;
        }
        if (typeof google === 'undefined' || !google.maps) {
            return null;
        }
        var el = document.getElementById('revenderores_maps');
        if (!el) {
            return null;
        }
        mapaGlobal = new google.maps.Map(el, {
            zoom: 6,
            center: new google.maps.LatLng(MAPA_DEFAULT_CENTER.lat, MAPA_DEFAULT_CENTER.lng),
            mapTypeId: google.maps.MapTypeId.ROADMAP,
            maxZoom: 18,
            gestureHandling: 'greedy',
        });
        return mapaGlobal;
    }

    /** Força o Google Maps a recalcular o tamanho do div (evita mapa “travado” após flex/layout ou muitos marcadores). */
    function redesenharMapa(map) {
        if (!map || typeof google === 'undefined' || !google.maps || !google.maps.event) {
            return;
        }
        var c = map.getCenter();
        google.maps.event.trigger(map, 'resize');
        if (c) {
            map.setCenter(c);
        }
    }

    /**
     * Garante API carregada + mapa instanciado (corrige busca antes do window.load ou mapa null).
     */
    function comMapaPronto(fn) {
        var map = criarMapaGlobal();
        if (map) {
            fn(map);
            return;
        }
        var tentativas = 0;
        var maxT = 120;
        var id = setInterval(function () {
            tentativas++;
            map = criarMapaGlobal();
            if (map) {
                clearInterval(id);
                fn(map);
                return;
            }
            if (tentativas >= maxT) {
                clearInterval(id);
            }
        }, 50);
    }

    function setMarkers(map, locations) {
        if (!map || !locations) {
            return;
        }
        clearMarkers();
        for (var i = 0; i < locations.length; i++) {
            var la = parseCoord(locations[i][1]);
            var ln = parseCoord(locations[i][2]);
            if (!isFinite(la) || !isFinite(ln)) {
                continue;
            }
            var marker = new google.maps.Marker({
                position: new google.maps.LatLng(la, ln),
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

    /**
     * Posiciona o mapa só com o que a API devolveu: search_lat/search_lng + todos os marcadores em makers.
     */
    function posicionarMapaResultado(map, makers, searchLat, searchLng) {
        if (!map) {
            return;
        }
        var sl = parseCoord(searchLat);
        var sg = parseCoord(searchLng);
        var temCentro = isFinite(sl) && isFinite(sg);
        var bounds = new google.maps.LatLngBounds();
        var i;
        var temPonto = false;
        if (makers && makers.length) {
            for (i = 0; i < makers.length; i++) {
                var la = parseCoord(makers[i][1]);
                var ln = parseCoord(makers[i][2]);
                if (isFinite(la) && isFinite(ln)) {
                    bounds.extend(new google.maps.LatLng(la, ln));
                    temPonto = true;
                }
            }
        }
        if (temCentro) {
            bounds.extend(new google.maps.LatLng(sl, sg));
            temPonto = true;
        }
        if (temPonto) {
            map.fitBounds(bounds);
            google.maps.event.addListenerOnce(map, 'idle', function () {
                var z = map.getZoom();
                if (z > 15) {
                    map.setZoom(15);
                }
                if (z < 4) {
                    map.setZoom(4);
                }
            });
            return;
        }
        if (temCentro) {
            map.setCenter(new google.maps.LatLng(sl, sg));
            map.setZoom(14);
        }
    }

    function atualizarBotaoImprimir() {
        var habilitar = resultadoExibido && resultadoExibido.length > 0;
        var $wrap = $('.busca-imprimir-inline-wrap');
        var $btn = $('#btn-busca-imprimir');
        if (habilitar) {
            $wrap.addClass('is-visible').attr('aria-hidden', 'false');
            $btn.prop('disabled', false);
        } else {
            $wrap.removeClass('is-visible').attr('aria-hidden', 'true');
            $btn.prop('disabled', true);
        }
    }

    /**
     * Mostra o wrapper dos selects só quando há pelo menos um filtro na resposta.
     * Não usar :visible nos filhos: com o pai em display:none o jQuery sempre os trata como invisíveis.
     */
    function atualizarVisibilidadeFiltrosSelects(mostrar) {
        var $box = $('.busca-filtros-selects');
        if (mostrar) {
            $box.addClass('is-visible');
        } else {
            $box.removeClass('is-visible');
        }
    }

    function invalidarGruposImpressao() {
        gruposImpressaoAtual = null;
    }

    function fecharModalImprimir() {
        var $m = $('#busca-imprimir-modal');
        $m.removeClass('is-visible').attr('aria-hidden', 'true');
        invalidarGruposImpressao();
        var $p = $m.data('buscaCepModalParent');
        if ($p && $p.length && document.body && $.contains(document.body, $p[0])) {
            $m.appendTo($p);
        }
    }

    function normalizarTextoChave(s) {
        return (s || '')
            .trim()
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .replace(/[.,;:|]/g, '')
            .trim();
    }

    function sufixoLocalizacao(r) {
        return normalizarTextoChave(r.endereco || '') + '|' +
            normalizarTextoChave(r.municipio || '') + '|' +
            normalizarTextoChave(r.estado || '');
    }

    /**
     * Funde só registros da mesma unidade (mesmo CNPJ/CRM + mesmo endereço/cidade).
     * Não usar geo primeiro: várias empresas diferentes compartilham o mesmo ponto geocodificado.
     */
    function chaveAgrupamentoRede(r, index) {
        var loc = sufixoLocalizacao(r);
        var docRaw = String(r.cnpj || '').trim();
        var digits = docRaw.replace(/\D/g, '');
        if (digits.length === 14) {
            return 'cnpj:' + digits + '|' + loc;
        }
        if (docRaw.length > 0) {
            return 'crm:' + normalizarTextoChave(docRaw) + '|' + loc;
        }
        var nome = normalizarTextoChave(r.nome || '');
        if (nome || loc.replace(/\|/g, '').length > 0) {
            return 'nomeloc:' + nome + '|' + loc;
        }
        var la = Number(r.lat);
        var ln = Number(r.lng);
        if (!isNaN(la) && !isNaN(ln)) {
            return 'geo:' + la.toFixed(5) + '|' + ln.toFixed(5);
        }
        if (r.id != null && String(r.id).trim() !== '') {
            return 'id:' + String(r.id);
        }
        return 'idx:' + index;
    }

    /**
     * Uma entrada por rede, com planos e especialidades reunidos (ordem preservada).
     */
    function agruparRedesParaImpressao(lista) {
        var map = {};
        var ordem = [];
        var i;
        for (i = 0; i < lista.length; i++) {
            var r = lista[i];
            var k = chaveAgrupamentoRede(r, i);
            if (!map[k]) {
                map[k] = {
                    id: r.id,
                    nome: r.nome,
                    cnpj: r.cnpj,
                    endereco: r.endereco,
                    telefone: r.telefone || '',
                    whatsapp: r.whatsapp || '',
                    horario: r.horario || '',
                    distancia: r.distancia || '',
                    _planos: [],
                    _esp: [],
                    _planoSet: {},
                    _espSet: {}
                };
                ordem.push(k);
            }
            var g = map[k];
            var p = (r.plano || '').toString().trim();
            if (p && !g._planoSet[p]) {
                g._planoSet[p] = true;
                g._planos.push(p);
            }
            var e = (r.especialidade || '').toString().trim();
            if (e && !g._espSet[e]) {
                g._espSet[e] = true;
                g._esp.push(e);
            }
            if (!g.telefone && r.telefone) g.telefone = r.telefone;
            if (!g.whatsapp && r.whatsapp) g.whatsapp = r.whatsapp;
            if (!g.horario && r.horario) g.horario = r.horario;
            if (!g.distancia && r.distancia) g.distancia = r.distancia;
        }
        var saida = [];
        for (i = 0; i < ordem.length; i++) {
            var raw = map[ordem[i]];
            saida.push({
                id: raw.id,
                nome: raw.nome,
                cnpj: raw.cnpj,
                endereco: raw.endereco,
                telefone: raw.telefone,
                whatsapp: raw.whatsapp,
                horario: raw.horario,
                distancia: raw.distancia,
                planos: raw._planos.slice(),
                especialidades: raw._esp.slice(),
                plano: raw._planos.join(', '),
                especialidade: raw._esp.join(', ')
            });
        }
        return saida;
    }

    function abrirModalImprimir() {
        if (!resultadoExibido || resultadoExibido.length === 0) {
            return;
        }
        gruposImpressaoAtual = agruparRedesParaImpressao(resultadoExibido.slice());
        var grupos = gruposImpressaoAtual;
        var $box = $('#busca-imprimir-checkboxes').empty();
        $('#busca-imprimir-todas').prop('checked', false);
        for (var i = 0; i < grupos.length; i++) {
            var g = grupos[i];
            var label = g.nome || '-';
            $box.append(
                '<label class="busca-imprimir-item"><input type="checkbox" name="rede_print" value="' + i + '"> ' +
                escHtml(label) + '</label>'
            );
        }
        var $m = $('#busca-imprimir-modal');
        if ($m.length) {
            var $p = $m.data('buscaCepModalParent');
            if ($p && $p.length && $m.parent()[0] !== document.body) {
                $m.appendTo('body');
            }
            $m.addClass('is-visible').attr('aria-hidden', 'false');
        }
    }

    function montarHtmlImpressao(redes) {
        var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Rede credenciada</title>';
        html += '<style>';
        html += 'html,body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#222;}';
        html += 'body{padding:20px;font-size:11pt;line-height:1.45;-webkit-print-color-adjust:exact;print-color-adjust:exact;}';
        html += 'h1{font-size:14pt;font-weight:600;margin:0 0 14px;}';
        html += '.card{border:1px solid #ccc;border-radius:6px;padding:12px 14px;margin-bottom:12px;}';
        html += '.card h2{font-size:12pt;margin:0 0 8px;}';
        html += '.row{margin:5px 0;font-size:11pt;}';
        html += '@page{size:A4;margin:14mm;}';
        html += '@media print{';
        html += 'body{padding:0;}';
        html += 'h1{font-size:13pt;}';
        html += '.card{break-inside:avoid;page-break-inside:avoid;}';
        html += '}';
        html += '</style></head><body>';
        html += '<h1>Rede credenciada</h1>';
        $(redes).each(function (_, r) {
            var cnpj = r.cnpj || '';
            var cnpjLabel = cnpj && String(cnpj).replace(/\D/g, '').length === 14 ? 'CNPJ' : 'CRM';
            html += '<div class="card"><h2>' + escHtml(r.nome || '-') + '</h2>';
            var planosLista = (r.planos && r.planos.length) ? r.planos.join(', ') : (r.plano || '');
            if (planosLista) {
                var lblPlano = (r.planos && r.planos.length > 1) ? 'Planos:' : 'Plano:';
                html += '<div class="row"><strong>' + lblPlano + '</strong> ' + escHtml(planosLista) + '</div>';
            }
            var espsLista = (r.especialidades && r.especialidades.length) ? r.especialidades.join(', ') : (r.especialidade || '');
            if (espsLista) {
                var lblEsp = (r.especialidades && r.especialidades.length > 1) ? 'Especialidades:' : 'Especialidade:';
                html += '<div class="row"><strong>' + lblEsp + '</strong> ' + escHtml(espsLista) + '</div>';
            }
            if (cnpj) {
                html += '<div class="row"><strong>' + escHtml(cnpjLabel) + ':</strong> ' + escHtml(cnpj) + '</div>';
            }
            html += '<div class="row"><strong>Endereço:</strong> ' + escHtml(r.endereco || '-') + '</div>';
            if (r.telefone) {
                html += '<div class="row"><strong>Telefone:</strong> ' + escHtml(r.telefone) + '</div>';
            }
            if (r.whatsapp) {
                html += '<div class="row"><strong>WhatsApp:</strong> ' + escHtml(r.whatsapp) + '</div>';
            }
            if (r.horario) {
                html += '<div class="row"><strong>Horário:</strong> ' + escHtml(r.horario) + '</div>';
            }
            if (r.distancia) {
                html += '<div class="row"><strong>Distância:</strong> ' + escHtml(r.distancia) + '</div>';
            }
            html += '</div>';
        });
        html += '</body></html>';
        return html;
    }

    function executarImpressao() {
        var grupos = gruposImpressaoAtual;
        if (!grupos || grupos.length === 0) {
            grupos = agruparRedesParaImpressao((resultadoExibido || []).slice());
        }
        var selecionadas = [];
        $('#busca-imprimir-checkboxes input[name="rede_print"]:checked').each(function () {
            var idx = parseInt($(this).val(), 10);
            if (!isNaN(idx) && grupos[idx]) {
                selecionadas.push(grupos[idx]);
            }
        });
        if (selecionadas.length === 0) {
            alert('Selecione ao menos uma rede para imprimir.');
            return;
        }
        var docHtml = montarHtmlImpressao(selecionadas);
        var janela = window.open('', '_blank');
        if (!janela) {
            alert('Não foi possível abrir a janela de impressão. Permita pop-ups para este site.');
            return;
        }
        janela.document.write(docHtml);
        janela.document.close();
        janela.focus();
        setTimeout(function () {
            try {
                janela.print();
            } catch (e) { /* noop */ }
        }, 300);
        fecharModalImprimir();
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
        var html = '<div class="perfil-nome">' + escHtml(nomeRede) + '</div>' +
            (cnpjCrm ? '<div class="perfil-item">' + escHtml(cnpjCrmLabel) + ': ' + escHtml(cnpjCrm) + '</div>' : '') +
            '<div class="perfil-item">' + escHtml(endereco) + '</div>' +
            '<div class="perfil-item">' + escHtml(horario) + '</div>' +
            '<div class="perfil-item">' + linhaTelefones + '</div>';

        $('#perfil-rede-content').html(html);
    }

    function renderizarLista(resales, selecionarPrimeiro) {
        resultadoExibido = resales || [];
        atualizarBotaoImprimir();
        var html = '';
        if (resales && resales.length > 0) {
            $(resales).each(function (i, b) {
                var plano = b.plano || '';
                var esp = b.especialidade || '';
                var tel = (b.telefone || '').toString().trim();
                html += '<div class="lojas-proximas js-item-lista' + (i === 0 && selecionarPrimeiro ? ' ativo' : '') + '" data-index="' + i + '">' +
                    '<div class="info-lojas">' +
                    (plano ? '<span class="info-plano">' + escHtml(plano) + '</span>' : '') +
                    '<span class="info-nome-wrap">' +
                    '<strong class="info-nome">' + escHtml(b.nome || '-') + '</strong>' +
                    (esp ? '<span class="info-especialidade">' + escHtml(esp) + '</span>' : '') +
                    '</span>';
                if (b.distancia) {
                    html += '<span class="distancia">' + escHtml(b.distancia) + '</span>';
                }
                html += '<p class="info-endereco">' + escHtml(b.endereco || '-') + '</p>';
                if (tel) {
                    html += '<p class="info-telefone">' + PHONE_ICON_SVG_LISTA + '<span class="info-telefone-numero">' + escHtml(tel) + '</span></p>';
                }
                html += '</div></div>';
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

    function combinaFiltroTexto(valorCampo, valorItem) {
        if (!valorCampo) {
            return true;
        }
        var esp = (valorItem || '').toString();
        return esp === valorCampo || esp.indexOf(valorCampo) !== -1;
    }

    function aplicarFiltro() {
        if ($('#busca-imprimir-modal').hasClass('is-visible')) {
            fecharModalImprimir();
        } else {
            invalidarGruposImpressao();
        }
        if (!resultadoCompleto) return;

        var especialidade = $('#filtro-especialidade').val();
        var plano = $('#filtro-plano').val();
        var resales = resultadoCompleto.resales;
        var makers = resultadoCompleto.makers;

        if (especialidade || plano) {
            var filtrados = [];
            var makersFiltrados = [];
            $(resales).each(function (i, r) {
                var okEsp = combinaFiltroTexto(especialidade, r.especialidade);
                var okPlano = combinaFiltroTexto(plano, r.plano);
                if (okEsp && okPlano) {
                    filtrados.push(r);
                    makersFiltrados.push(makers[i]);
                }
            });
            resales = filtrados;
            makers = makersFiltrados;
        }

        renderizarLista(resales, true);

        comMapaPronto(function (map) {
            if (makers.length > 0) {
                setMarkers(map, makers);
                posicionarMapaResultado(map, makers, resultadoCompleto.search_lat, resultadoCompleto.search_lng);
            } else {
                clearMarkers();
                posicionarMapaResultado(map, [], resultadoCompleto.search_lat, resultadoCompleto.search_lng);
            }
            setTimeout(function () {
                redesenharMapa(map);
            }, 80);
        });
    }

    function processarResposta(obj) {
        if ($('#busca-imprimir-modal').hasClass('is-visible')) {
            fecharModalImprimir();
        } else {
            invalidarGruposImpressao();
        }

        if (obj.status === 'ok') {
            renderizarLista([], false);
            $('.filtro-especialidade-wrap').hide();
            $('.filtro-plano-wrap').hide();
            $('#filtro-especialidade').empty().append('<option value="">Filtrar por especialidade</option>');
            $('#filtro-plano').empty().append('<option value="">Filtrar por plano</option>');
            atualizarVisibilidadeFiltrosSelects(false);
            return;
        }

        resultadoCompleto = obj;

        var $selEsp = $('#filtro-especialidade');
        $selEsp.empty().append('<option value="">Filtrar por especialidade</option>');
        if (obj.especialidades && obj.especialidades.length > 0) {
            $(obj.especialidades).each(function (i, esp) {
                $selEsp.append($('<option></option>').val(esp).text(esp));
            });
            $('.filtro-especialidade-wrap').show();
        } else {
            $('.filtro-especialidade-wrap').hide();
        }

        var $selPlano = $('#filtro-plano');
        $selPlano.empty().append('<option value="">Filtrar por plano</option>');
        if (obj.planos && obj.planos.length > 0) {
            $(obj.planos).each(function (i, p) {
                $selPlano.append($('<option></option>').val(p).text(p));
            });
            $('.filtro-plano-wrap').show();
        } else {
            $('.filtro-plano-wrap').hide();
        }

        var temFiltros = (obj.especialidades && obj.especialidades.length > 0) ||
            (obj.planos && obj.planos.length > 0);
        atualizarVisibilidadeFiltrosSelects(temFiltros);

        renderizarLista(obj.resales, true);

        if (obj.makers && obj.makers.length > 0) {
            comMapaPronto(function (map) {
                setMarkers(map, obj.makers);
                posicionarMapaResultado(map, obj.makers, obj.search_lat, obj.search_lng);
                setTimeout(function () {
                    redesenharMapa(map);
                }, 80);
            });
        }
    }

    $(function () {
        criarMapaGlobal();
    });
    $(window).on('load', function () {
        criarMapaGlobal();
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
                if (typeof buscaCepConfig !== 'undefined' && buscaCepConfig.mapDebug) {
                    try {
                        console.log('[busca-cep] resposta /consult/', JSON.parse(JSON.stringify(obj)));
                    } catch (e) {
                        console.log('[busca-cep] resposta /consult/', obj);
                    }
                }
                processarResposta(obj);
            },
            error: function () {
                $('.btn-buscar').removeClass('loading').prop('disabled', false);
                if ($('#busca-imprimir-modal').hasClass('is-visible')) {
                    fecharModalImprimir();
                } else {
                    invalidarGruposImpressao();
                }
                resultadoCompleto = null;
                $('.filtro-especialidade-wrap').hide();
                $('.filtro-plano-wrap').hide();
                $('#filtro-especialidade').empty().append('<option value="">Filtrar por especialidade</option>');
                $('#filtro-plano').empty().append('<option value="">Filtrar por plano</option>');
                atualizarVisibilidadeFiltrosSelects(false);
                renderizarLista([], false);
            }
        });
    });

    $(document).on('change', '#filtro-especialidade, #filtro-plano', function () {
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

    $('#btn-busca-imprimir').on('click', function () {
        abrirModalImprimir();
    });

    $('#busca-imprimir-cancelar, #busca-imprimir-fechar-backdrop').on('click', function () {
        fecharModalImprimir();
    });

    $('#busca-imprimir-todas').on('change', function () {
        var marcar = $(this).prop('checked');
        $('#busca-imprimir-checkboxes input[name="rede_print"]').prop('checked', marcar);
    });

    $(document).on('change', '#busca-imprimir-checkboxes input[name="rede_print"]', function () {
        var $boxes = $('#busca-imprimir-checkboxes input[name="rede_print"]');
        var n = $boxes.length;
        var c = $boxes.filter(':checked').length;
        $('#busca-imprimir-todas').prop('checked', n > 0 && c === n);
    });

    $('#busca-imprimir-form').on('submit', function (e) {
        e.preventDefault();
        executarImpressao();
    });

    $(function () {
        var $modal = $('#busca-imprimir-modal');
        if ($modal.length) {
            $modal.data('buscaCepModalParent', $modal.parent());
        }
        atualizarVisibilidadeFiltrosSelects(false);
    });
})(jQuery);
