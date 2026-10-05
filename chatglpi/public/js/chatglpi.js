/**
 * Plugin Chat GLPI - ícone na barra, painel lateral e página do chat
 * Uma única sincronização por intervalo (mais rápida com o chat aberto); notificações do navegador opcionais.
 */
(function () {
    'use strict';

    if (window.ChatGlpi) {
        return;
    }

    var raiz = (window.CFG_GLPI && window.CFG_GLPI.root_doc) ? window.CFG_GLPI.root_doc : '';
    var urlAjax = raiz + '/plugins/chatglpi/front/ajax.php';

    var estado = {
        config: null,
        eu: null,
        conversas: [],
        total: 0,
        agora: '',
        aba: 'conversas',
        busca: '',
        pessoas: [],
        aberta: null,          // cabeçalho da conversa aberta
        ultimo: 0,             // id da última mensagem mostrada
        primeiro: 0,           // id da mensagem mais antiga mostrada
        mais: false,
        lidaAte: 0,
        anexo: null,           // arquivo aguardando envio
        painelAberto: false,
        ultimaInteracao: Date.now(),
        interagiu: true,
        digitandoAte: 0,
        conhecidas: null,      // última mensagem conhecida por conversa (para avisar só do que é novo)
        timer: null,
        sincronizando: false,
        enviando: false
    };

    var ui = { botao: null, painel: null, pagina: null, raiz: null };

    // ------------------------------------------------------------------ utilitários

    function esc(t) {
        var d = document.createElement('div');
        d.textContent = t == null ? '' : String(t);
        return d.innerHTML;
    }

    function token() {
        var m = document.querySelector('meta[property="glpi:csrf_token"]');
        return m ? m.getAttribute('content') : '';
    }

    function guardarToken(t) {
        var m = document.querySelector('meta[property="glpi:csrf_token"]');
        if (t && m) {
            m.setAttribute('content', t);
        }
    }

    function lerJson(texto) {
        try {
            return JSON.parse(texto);
        } catch (e) {
            var m = texto.match(/\{[\s\S]*\}\s*$/);
            if (m) {
                try { return JSON.parse(m[0]); } catch (e2) { /* segue */ }
            }
        }
        return { success: false, message: 'Resposta inválida do servidor.' };
    }

    function pedir(acao, dados, metodo) {
        dados = dados || {};
        var opcoes = { method: metodo || 'GET', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        var url = urlAjax + '?action=' + encodeURIComponent(acao);
        if (opcoes.method === 'GET') {
            Object.keys(dados).forEach(function (k) {
                if (dados[k] !== undefined && dados[k] !== null && dados[k] !== '') {
                    url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(dados[k]);
                }
            });
        } else {
            var fd = dados instanceof FormData ? dados : new FormData();
            if (!(dados instanceof FormData)) {
                Object.keys(dados).forEach(function (k) { fd.append(k, dados[k]); });
            }
            fd.append('action', acao);
            var t = token();
            if (t) {
                opcoes.headers['X-Glpi-Csrf-Token'] = t;
                fd.append('_glpi_csrf_token', t);
            }
            opcoes.body = fd;
        }
        return fetch(url, opcoes).then(function (r) { return r.text(); }).then(function (txt) {
            var j = lerJson(txt);
            guardarToken(j.new_token);
            return j;
        });
    }

    function aviso(texto, erro) {
        if (erro && typeof window.glpi_toast_error === 'function') {
            window.glpi_toast_error(texto);
        } else if (!erro && typeof window.glpi_toast_info === 'function') {
            window.glpi_toast_info(texto);
        }
    }

    var CORES = ['rgba(13,110,253,.12)', 'rgba(25,135,84,.12)', 'rgba(255,193,7,.18)', 'rgba(220,53,69,.10)', 'rgba(111,66,193,.12)', 'rgba(13,202,240,.14)', 'rgba(108,117,125,.14)'];

    function avatar(foto, ini, id, status, classe) {
        var html = '<span class="chatglpi-avatar ' + (classe || '') + '"' + (foto ? '' : ' style="background:' + CORES[(id || 0) % CORES.length] + '"') + '>';
        html += foto ? '<img src="' + esc(foto) + '" alt="">' : '<span>' + esc(ini || '?') + '</span>';
        if (status) {
            html += '<i class="chatglpi-ponto chatglpi-st-' + esc(status) + '" title="' + esc(rotuloStatus(status)) + '"></i>';
        }
        return html + '</span>';
    }

    function rotuloStatus(s) {
        return { online: 'Online', ausente: 'Ausente', ocupado: 'Ocupado', invisivel: 'Invisível', offline: 'Offline' }[s] || s;
    }

    function rotuloDia(dia) {
        var hoje = new Date();
        var d = new Date(dia + 'T12:00:00');
        var ontem = new Date(hoje.getTime() - 86400000);
        var iso = function (x) { return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0'); };
        if (dia === iso(hoje)) { return 'Hoje'; }
        if (dia === iso(ontem)) { return 'Ontem'; }
        return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear();
    }

    // ------------------------------------------------------------------ estrutura

    function montarBotao() {
        if (document.querySelector('[data-chatglpi-botao]')) {
            ui.botao = document.querySelector('[data-chatglpi-botao]');
            return true;
        }
        var cont = document.querySelector('header .header-container') || document.querySelector('header.navbar .container-fluid');
        if (!cont) {
            return false;
        }
        ui.botao = document.createElement('div');
        ui.botao.className = 'chatglpi-barra';
        ui.botao.setAttribute('data-chatglpi-botao', '');
        ui.botao.innerHTML = '<button type="button" class="chatglpi-barra-botao" aria-label="Chat" title="Chat"><i class="ti ti-messages"></i><span class="chatglpi-badge" hidden></span></button>';
        var alvo = Array.from(cont.children).filter(function (el) {
            return el.classList.contains('ms-md-4') || el.querySelector('.user-menu, [data-testid="user-menu"]');
        }).pop();
        var sino = cont.querySelector('[data-notificacoes-sino]');
        if (sino) {
            cont.insertBefore(ui.botao, sino);
        } else if (alvo) {
            cont.insertBefore(ui.botao, alvo);
        } else {
            cont.appendChild(ui.botao);
        }
        ui.botao.querySelector('button').addEventListener('click', function () {
            if (ui.pagina) {
                ui.pagina.scrollIntoView({ behavior: 'smooth' });
                return;
            }
            alternarPainel();
        });
        return true;
    }

    function estrutura() {
        return '<div class="chatglpi-app">' +
            '<section class="chatglpi-lado-lista">' +
                '<div class="chatglpi-topo">' +
                    '<span class="chatglpi-topo-titulo" data-titulo>Chat</span>' +
                    '<span class="chatglpi-topo-botoes">' +
                        '<span class="chatglpi-menu-status"><button type="button" class="btn btn-sm btn-ghost-secondary" data-acao="menu-status" title="Meu status"><i class="chatglpi-ponto chatglpi-st-online" data-meu-status></i></button>' +
                            '<div class="chatglpi-suspenso" data-suspenso="status" hidden>' +
                                '<button type="button" data-status="online"><i class="chatglpi-ponto chatglpi-st-online"></i> Online</button>' +
                                '<button type="button" data-status="ocupado"><i class="chatglpi-ponto chatglpi-st-ocupado"></i> Ocupado</button>' +
                                '<button type="button" data-status="invisivel"><i class="chatglpi-ponto chatglpi-st-invisivel"></i> Invisível</button>' +
                                '<hr><label><input type="checkbox" class="chatglpi-check" data-pref="notificar_navegador"> Avisos do navegador</label>' +
                                '<label><input type="checkbox" class="chatglpi-check" data-pref="tocar_som"> Som ao receber</label>' +
                            '</div></span>' +
                        '<a class="btn btn-sm btn-ghost-secondary" data-link="pagina" title="Abrir em tela cheia"><i class="ti ti-arrows-maximize"></i></a>' +
                        '<button type="button" class="btn btn-sm btn-ghost-secondary" data-acao="fechar" title="Fechar"><i class="ti ti-x"></i></button>' +
                    '</span>' +
                '</div>' +
                '<div class="chatglpi-abas">' +
                    '<button type="button" class="chatglpi-aba ativo" data-aba="conversas">Conversas <span class="chatglpi-contador" data-contador hidden></span></button>' +
                    '<button type="button" class="chatglpi-aba" data-aba="pessoas">Pessoas</button>' +
                    '<button type="button" class="chatglpi-aba" data-aba="salas">Salas</button>' +
                '</div>' +
                '<div class="chatglpi-busca"><i class="ti ti-search"></i><input type="search" class="form-control form-control-sm" placeholder="Pesquisar…" data-busca></div>' +
                '<ul class="chatglpi-lista" data-lista></ul>' +
                '<div class="chatglpi-lista-rodape" data-rodape-salas hidden><a data-link="salas"><i class="ti ti-settings"></i> Gerenciar salas</a></div>' +
            '</section>' +
            '<section class="chatglpi-lado-conversa" data-conversa hidden>' +
                '<div class="chatglpi-topo chatglpi-topo-conversa">' +
                    '<button type="button" class="btn btn-sm btn-ghost-secondary chatglpi-voltar" data-acao="voltar" title="Voltar"><i class="ti ti-arrow-left"></i></button>' +
                    '<span data-conversa-avatar></span>' +
                    '<span class="chatglpi-conversa-info"><span class="chatglpi-conversa-titulo" data-conversa-titulo></span><span class="chatglpi-conversa-sub" data-conversa-sub></span></span>' +
                    '<span class="chatglpi-topo-botoes chatglpi-menu-conversa"><button type="button" class="btn btn-sm btn-ghost-secondary" data-acao="menu-conversa" title="Mais ações"><i class="ti ti-dots-vertical"></i></button>' +
                        '<div class="chatglpi-suspenso" data-suspenso="conversa" hidden>' +
                            '<button type="button" data-acao="form-salvar"><i class="ti ti-device-floppy"></i> Salvar no chamado</button>' +
                            '<button type="button" data-acao="form-abrir"><i class="ti ti-ticket"></i> Abrir chamado</button>' +
                            '<button type="button" data-acao="membros" data-so-sala><i class="ti ti-users"></i> Membros</button>' +
                            '<button type="button" data-acao="silenciar"><i class="ti ti-bell-off"></i> <span data-rotulo-silenciar>Silenciar</span></button>' +
                            '<button type="button" data-acao="ocultar" data-so-direta><i class="ti ti-eye-off"></i> Ocultar da lista</button>' +
                        '</div></span>' +
                '</div>' +
                '<div class="chatglpi-mensagens" data-mensagens></div>' +
                '<div class="chatglpi-digitando" data-digitando hidden></div>' +
                '<div class="chatglpi-anexo-pendente" data-anexo-pendente hidden></div>' +
                '<form class="chatglpi-compor" data-compor>' +
                    '<button type="button" class="btn btn-sm btn-ghost-secondary" data-acao="anexar" title="Anexar arquivo"><i class="ti ti-paperclip"></i></button>' +
                    '<input type="file" data-arquivo hidden>' +
                    '<textarea rows="1" class="form-control" placeholder="Escreva uma mensagem…" data-texto maxlength="10000"></textarea>' +
                    '<button type="submit" class="btn btn-sm chatglpi-btn-enviar" title="Enviar (Enter)"><i class="ti ti-send"></i></button>' +
                '</form>' +
                '<div class="chatglpi-sobre" data-sobre hidden></div>' +
            '</section>' +
            '<section class="chatglpi-vazio-conversa" data-sem-conversa><i class="ti ti-messages"></i><span>Escolha uma conversa ou uma pessoa para começar.</span></section>' +
        '</div>';
    }

    function montarPainel() {
        if (ui.painel) {
            return;
        }
        ui.painel = document.createElement('div');
        ui.painel.className = 'chatglpi chatglpi-painel';
        ui.painel.setAttribute('role', 'dialog');
        ui.painel.setAttribute('aria-label', 'Chat');
        ui.painel.hidden = true;
        ui.painel.innerHTML = estrutura();
        document.body.appendChild(ui.painel);
        ligar(ui.painel);
    }

    function alternarPainel(abrir) {
        montarPainel();
        estado.painelAberto = abrir === undefined ? ui.painel.hidden : !!abrir;
        ui.painel.hidden = !estado.painelAberto;
        ui.raiz = ui.painel;
        if (estado.painelAberto) {
            desenharLista();
            if (estado.aberta) {
                pedir('lida', { conversa: estado.aberta.id }, 'POST');
            }
            var b = ui.raiz.querySelector('[data-busca]');
            if (!estado.aberta && b) {
                b.focus();
            }
        }
        reagendar(300);
    }

    // ------------------------------------------------------------------ lista

    function desenharLista() {
        if (!ui.raiz) {
            return;
        }
        var r = ui.raiz;
        r.querySelector('[data-titulo]').textContent = estado.config ? estado.config.nome : 'Chat';
        r.querySelectorAll('[data-aba]').forEach(function (b) { b.classList.toggle('ativo', b.getAttribute('data-aba') === estado.aba); });
        var cont = r.querySelector('[data-contador]');
        cont.hidden = estado.total === 0;
        cont.textContent = estado.total > 99 ? '99+' : String(estado.total);
        r.querySelector('[data-rodape-salas]').hidden = !(estado.aba === 'salas' && estado.config && estado.config.gerenciaSalas);
        var termo = estado.busca.toLowerCase();
        var lista = r.querySelector('[data-lista]');
        var html = '';
        if (estado.aba === 'pessoas') {
            if (!estado.pessoas.length) {
                html = '<li class="chatglpi-lista-vazia">' + (estado.busca ? 'Ninguém encontrado.' : 'Carregando…') + '</li>';
            }
            estado.pessoas.forEach(function (p) {
                html += '<li><button type="button" class="chatglpi-item" data-usuario="' + p.id + '">' + avatar(p.foto, p.ini, p.id, p.status) +
                    '<span class="chatglpi-item-texto"><span class="chatglpi-item-nome">' + esc(p.nome) + '</span>' +
                    '<span class="chatglpi-item-previa">' + esc(p.status === 'offline' ? (p.visto ? 'Visto em ' + p.visto : 'Offline') : rotuloStatus(p.status)) + '</span></span></button></li>';
            });
        } else {
            var itens = estado.conversas.filter(function (c) {
                return (estado.aba !== 'salas' || c.tipo === 'sala') && (termo === '' || c.titulo.toLowerCase().indexOf(termo) !== -1);
            });
            if (!itens.length) {
                html = '<li class="chatglpi-lista-vazia">' + (estado.aba === 'salas' ? 'Nenhuma sala disponível.' : (termo ? 'Nada encontrado.' : 'Nenhuma conversa ainda. Veja em "Pessoas" quem está online.')) + '</li>';
            }
            itens.forEach(function (c) {
                var icone = c.tipo === 'sala' ? '<span class="chatglpi-avatar chatglpi-avatar-sala"><i class="ti ti-users-group"></i></span>' : avatar(c.foto, c.ini, c.outro, c.status);
                html += '<li><button type="button" class="chatglpi-item' + (estado.aberta && estado.aberta.id === c.id ? ' atual' : '') + (c.nao_lidas ? ' nao-lida' : '') + '" data-conversa-id="' + c.id + '">' + icone +
                    '<span class="chatglpi-item-texto"><span class="chatglpi-item-nome">' + esc(c.titulo) + (c.silenciada ? ' <i class="ti ti-bell-off" title="Silenciada"></i>' : '') + '</span>' +
                    '<span class="chatglpi-item-previa">' + esc(c.previa || (c.tipo === 'sala' ? 'Sem mensagens ainda' : '')) + '</span></span>' +
                    '<span class="chatglpi-item-lado"><span class="chatglpi-item-data">' + esc(c.data) + '</span>' + (c.nao_lidas ? '<span class="chatglpi-contador' + (c.silenciada ? ' mudo' : '') + '">' + (c.nao_lidas > 99 ? '99+' : c.nao_lidas) + '</span>' : '') + '</span></button></li>';
            });
        }
        lista.innerHTML = html;
    }

    function desenharMeuStatus() {
        if (!estado.eu) {
            return;
        }
        document.querySelectorAll('[data-meu-status]').forEach(function (p) {
            p.className = 'chatglpi-ponto chatglpi-st-' + estado.eu.status;
            p.parentNode.title = 'Meu status: ' + rotuloStatus(estado.eu.status);
        });
        document.querySelectorAll('[data-pref="notificar_navegador"]').forEach(function (c) { c.checked = !!estado.eu.notificar; });
        document.querySelectorAll('[data-pref="tocar_som"]').forEach(function (c) { c.checked = !!estado.eu.som; });
    }

    function badge() {
        if (!ui.botao) {
            return;
        }
        var b = ui.botao.querySelector('.chatglpi-badge');
        b.hidden = estado.total === 0;
        b.textContent = estado.total > 99 ? '99+' : String(estado.total);
        ui.botao.querySelector('button').title = estado.total ? estado.total + ' mensagem(ns) não lida(s)' : (estado.config ? estado.config.nome : 'Chat');
    }

    // ------------------------------------------------------------------ conversa

    function abrirConversa(params) {
        if (!ui.raiz) {
            return;
        }
        var r = ui.raiz;
        fecharSobre();
        r.querySelector('[data-conversa]').hidden = false;
        r.querySelector('[data-sem-conversa]').hidden = true;
        r.classList.add('chatglpi-com-conversa');
        var caixa = r.querySelector('[data-mensagens]');
        caixa.innerHTML = '<div class="chatglpi-carregando"><i class="ti ti-loader"></i> Carregando…</div>';
        params.visivel = visivel() ? 1 : '';
        return pedir('abrir', params).then(function (j) {
            if (!j.success) {
                caixa.innerHTML = '<div class="chatglpi-alerta">' + esc(j.message) + '</div>';
                return;
            }
            estado.aberta = j.conversa;
            estado.ultimo = 0;
            estado.primeiro = 0;
            estado.mais = j.mais;
            estado.lidaAte = j.lida_ate || 0;
            estado.agora = j.agora || estado.agora;
            descartarAnexo();
            desenharCabecalho();
            caixa.innerHTML = '';
            adicionarMensagens(j.mensagens, 'fim');
            rolarFim(true);
            var c = estado.conversas.find(function (x) { return x.id === j.conversa.id; });
            if (c && visivel()) {
                estado.total = Math.max(0, estado.total - (c.silenciada ? 0 : c.nao_lidas));
                c.nao_lidas = 0;
                badge();
            }
            desenharLista();
            var txt = r.querySelector('[data-texto]');
            txt.value = rascunho(j.conversa.id);
            ajustarAltura(txt);
            txt.focus();
            if (ui.pagina && window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname + '?conversa=' + j.conversa.id);
            }
        });
    }

    function desenharCabecalho() {
        var r = ui.raiz;
        var a = estado.aberta;
        if (!a) {
            return;
        }
        r.querySelector('[data-conversa-avatar]').innerHTML = a.tipo === 'sala' ? '<span class="chatglpi-avatar chatglpi-avatar-sala"><i class="ti ti-users-group"></i></span>' : avatar(a.foto, a.ini, a.outro, a.status);
        r.querySelector('[data-conversa-titulo]').textContent = a.titulo;
        r.querySelector('[data-conversa-sub]').textContent = a.tipo === 'sala' ? (a.membros + ' membro(s)' + (a.descricao ? ' · ' + a.descricao : '')) : rotuloStatus(a.status);
        r.querySelectorAll('[data-so-sala]').forEach(function (b) { b.hidden = a.tipo !== 'sala'; });
        r.querySelectorAll('[data-so-direta]').forEach(function (b) { b.hidden = a.tipo !== 'direta'; });
        r.querySelector('[data-rotulo-silenciar]').textContent = a.silenciada ? 'Reativar avisos' : 'Silenciar';
        r.querySelector('[data-acao="anexar"]').hidden = !(estado.config && estado.config.anexos);
    }

    function fecharConversa() {
        var r = ui.raiz;
        guardarRascunho();
        estado.aberta = null;
        estado.ultimo = 0;
        r.querySelector('[data-conversa]').hidden = true;
        r.querySelector('[data-sem-conversa]').hidden = false;
        r.classList.remove('chatglpi-com-conversa');
        desenharLista();
        if (ui.pagina && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', window.location.pathname);
        }
    }

    function htmlMensagem(m) {
        if (m.tipo === 'evento') {
            return '<div class="chatglpi-evento" data-msg="' + m.id + '"><span><b>' + esc(m.nome) + '</b> ' + m.html + ' · ' + esc(m.hora) + '</span></div>';
        }
        var sala = estado.aberta && estado.aberta.tipo === 'sala';
        var corpo = m.apagada ? '<span class="chatglpi-apagada"><i class="ti ti-ban"></i> Mensagem apagada</span>' : m.html;
        if (m.anexo) {
            var a = m.anexo;
            corpo += a.imagem
                ? '<a class="chatglpi-imagem" href="' + esc(a.ver) + '" target="_blank" rel="noopener"><img src="' + esc(a.ver) + '" alt="' + esc(a.nome) + '" loading="lazy"></a>'
                : '<a class="chatglpi-arquivo" href="' + esc(a.url) + '"><i class="ti ti-file"></i><span>' + esc(a.nome) + '</span><small>' + esc(a.tamanho) + '</small></a>';
        }
        var acoes = m.alterar ? '<span class="chatglpi-msg-acoes"><button type="button" data-acao="editar" title="Editar"><i class="ti ti-edit"></i></button><button type="button" data-acao="apagar" title="Apagar"><i class="ti ti-trash"></i></button></span>' : '';
        var lida = m.minha && estado.aberta && estado.aberta.tipo === 'direta' ? '<i class="ti ' + (m.id <= estado.lidaAte ? 'ti-checks lida' : 'ti-check') + '" data-lida title="' + (m.id <= estado.lidaAte ? 'Lida' : 'Enviada') + '"></i>' : '';
        return '<div class="chatglpi-msg' + (m.minha ? ' minha' : '') + '" data-msg="' + m.id + '" data-autor="' + m.autor + '" data-dia="' + esc(m.dia) + '">' +
            (!m.minha && sala ? avatar(m.foto, m.ini, m.autor, '', 'chatglpi-avatar-p') : '') +
            '<div class="chatglpi-balao">' + (!m.minha && sala ? '<span class="chatglpi-msg-autor">' + esc(m.nome) + '</span>' : '') +
            '<div class="chatglpi-msg-corpo">' + corpo + '</div>' +
            '<span class="chatglpi-msg-hora" title="' + esc(m.data) + '">' + (m.editada && !m.apagada ? 'editada · ' : '') + esc(m.hora) + lida + '</span>' + acoes +
            '</div></div>';
    }

    /** Insere mensagens no fim (novas) ou no início (histórico), com separadores de dia */
    function adicionarMensagens(lista, onde) {
        var caixa = ui.raiz.querySelector('[data-mensagens]');
        if (!lista || !lista.length) {
            if (onde === 'fim' && !caixa.children.length) {
                caixa.innerHTML = '<div class="chatglpi-sem-msg" data-sem-msg>Nenhuma mensagem ainda. Diga olá!</div>';
            }
            return;
        }
        var semMsg = caixa.querySelector('[data-sem-msg]');
        if (semMsg) {
            semMsg.remove();
        }
        lista = lista.filter(function (m) { return !caixa.querySelector('[data-msg="' + m.id + '"]'); });
        if (!lista.length) {
            return;
        }
        if (onde === 'inicio') {
            var alturaAntes = caixa.scrollHeight;
            var primeiroDia = (caixa.querySelector('[data-dia]') || {}).getAttribute ? caixa.querySelector('[data-dia]').getAttribute('data-dia') : '';
            var sep = caixa.querySelector('.chatglpi-dia');
            if (sep && lista[lista.length - 1].dia === primeiroDia) {
                sep.remove();
            }
            var html = '';
            var dia = '';
            lista.forEach(function (m) {
                if (m.dia !== dia) {
                    html += '<div class="chatglpi-dia"><span>' + esc(rotuloDia(m.dia)) + '</span></div>';
                    dia = m.dia;
                }
                html += htmlMensagem(m);
            });
            var mais = caixa.querySelector('[data-mais]');
            if (mais) {
                mais.remove();
            }
            caixa.insertAdjacentHTML('afterbegin', html);
            caixa.scrollTop = caixa.scrollHeight - alturaAntes;
            estado.primeiro = lista[0].id;
        } else {
            var ultimoDia = '';
            var todos = caixa.querySelectorAll('[data-dia]');
            if (todos.length) {
                ultimoDia = todos[todos.length - 1].getAttribute('data-dia');
            }
            var h = '';
            lista.forEach(function (m) {
                if (m.tipo !== 'evento' && m.dia !== ultimoDia) {
                    h += '<div class="chatglpi-dia"><span>' + esc(rotuloDia(m.dia)) + '</span></div>';
                    ultimoDia = m.dia;
                }
                h += htmlMensagem(m);
            });
            caixa.insertAdjacentHTML('beforeend', h);
            estado.ultimo = Math.max(estado.ultimo, lista[lista.length - 1].id);
            if (!estado.primeiro) {
                estado.primeiro = lista[0].id;
            }
        }
        if (estado.mais && !caixa.querySelector('[data-mais]')) {
            caixa.insertAdjacentHTML('afterbegin', '<div class="chatglpi-mais" data-mais><button type="button" class="btn btn-sm btn-ghost-secondary" data-acao="mais">Mensagens anteriores</button></div>');
        }
    }

    function substituirMensagem(m) {
        var el = ui.raiz.querySelector('[data-msg="' + m.id + '"]');
        if (el) {
            el.outerHTML = htmlMensagem(m);
        }
    }

    function atualizarLidas() {
        if (!ui.raiz || !estado.aberta || estado.aberta.tipo !== 'direta') {
            return;
        }
        ui.raiz.querySelectorAll('.chatglpi-msg.minha [data-lida]').forEach(function (i) {
            var id = parseInt(i.closest('[data-msg]').getAttribute('data-msg'), 10);
            var lida = id <= estado.lidaAte;
            i.className = 'ti ' + (lida ? 'ti-checks lida' : 'ti-check');
            i.title = lida ? 'Lida' : 'Enviada';
        });
    }

    function pertoDoFim() {
        var c = ui.raiz.querySelector('[data-mensagens]');
        return c.scrollHeight - c.scrollTop - c.clientHeight < 120;
    }

    function rolarFim(forcar) {
        var c = ui.raiz.querySelector('[data-mensagens]');
        if (forcar || pertoDoFim()) {
            c.scrollTop = c.scrollHeight;
        }
    }

    // ------------------------------------------------------------------ rascunhos e anexo

    var rascunhos = {};

    function rascunho(id) {
        return rascunhos[id] || '';
    }

    function guardarRascunho() {
        if (estado.aberta && ui.raiz) {
            rascunhos[estado.aberta.id] = ui.raiz.querySelector('[data-texto]').value;
        }
    }

    function ajustarAltura(t) {
        t.style.height = 'auto';
        t.style.height = Math.min(140, t.scrollHeight + 2) + 'px';
    }

    function escolherAnexo(arquivo) {
        if (!arquivo || !estado.config || !estado.config.anexos) {
            return;
        }
        var ext = (arquivo.name.split('.').pop() || '').toLowerCase();
        if (estado.config.extensoes.indexOf(ext) === -1) {
            aviso('Tipo de arquivo não permitido (.' + ext + ').', true);
            return;
        }
        if (arquivo.size > estado.config.anexoMaxMb * 1048576) {
            aviso('O arquivo passa de ' + String(estado.config.anexoMaxMb).replace('.', ',') + ' MB.', true);
            return;
        }
        estado.anexo = arquivo;
        var p = ui.raiz.querySelector('[data-anexo-pendente]');
        p.hidden = false;
        p.innerHTML = '<i class="ti ti-paperclip"></i><span>' + esc(arquivo.name) + '</span><small>' + Math.max(1, Math.round(arquivo.size / 1024)) + ' KB</small><button type="button" data-acao="tirar-anexo" title="Remover"><i class="ti ti-x"></i></button>';
    }

    function descartarAnexo() {
        estado.anexo = null;
        if (ui.raiz) {
            var p = ui.raiz.querySelector('[data-anexo-pendente]');
            p.hidden = true;
            p.innerHTML = '';
            var f = ui.raiz.querySelector('[data-arquivo]');
            if (f) {
                f.value = '';
            }
        }
    }

    function enviar() {
        if (!estado.aberta || estado.enviando) {
            return;
        }
        var txt = ui.raiz.querySelector('[data-texto]');
        var texto = txt.value.trim();
        if (texto === '' && !estado.anexo) {
            return;
        }
        var fd = new FormData();
        fd.append('conversa', estado.aberta.id);
        fd.append('texto', texto);
        if (estado.anexo) {
            fd.append('arquivo', estado.anexo, estado.anexo.name || 'imagem.png');
        }
        estado.enviando = true;
        var botao = ui.raiz.querySelector('.chatglpi-btn-enviar');
        botao.disabled = true;
        pedir('enviar', fd, 'POST').then(function (j) {
            if (!j.success) {
                aviso(j.message, true);
                return;
            }
            txt.value = '';
            rascunhos[estado.aberta.id] = '';
            ajustarAltura(txt);
            descartarAnexo();
            adicionarMensagens([j.mensagem], 'fim');
            rolarFim(true);
            estado.digitandoAte = 0;
            reagendar(800);
        }).catch(function () {
            aviso('Falha de comunicação. A mensagem não foi enviada.', true);
        }).finally(function () {
            estado.enviando = false;
            botao.disabled = false;
            txt.focus();
        });
    }

    // ------------------------------------------------------------------ edição

    function editarMensagem(el) {
        var id = el.getAttribute('data-msg');
        var corpo = el.querySelector('.chatglpi-msg-corpo');
        if (!corpo || el.querySelector('[data-edicao]')) {
            return;
        }
        pedir('historico', { conversa: estado.aberta.id, antes: parseInt(id, 10) + 1 }).then(function (j) {
            var m = (j.mensagens || []).find(function (x) { return String(x.id) === String(id); });
            if (!m) {
                return;
            }
            corpo.innerHTML = '<div class="chatglpi-edicao" data-edicao><textarea class="form-control form-control-sm" rows="2">' + esc(m.texto) + '</textarea>' +
                '<span><button type="button" class="btn btn-sm chatglpi-btn-enviar" data-acao="salvar-edicao">Salvar</button><button type="button" class="btn btn-sm btn-ghost-secondary" data-acao="cancelar-edicao">Cancelar</button></span></div>';
            var t = corpo.querySelector('textarea');
            t.focus();
            t.setSelectionRange(t.value.length, t.value.length);
        });
    }

    // ------------------------------------------------------------------ sobreposições (chamados, membros)

    function abrirSobre(html) {
        var s = ui.raiz.querySelector('[data-sobre]');
        s.innerHTML = '<div class="chatglpi-sobre-caixa">' + html + '</div>';
        s.hidden = false;
        var primeiro = s.querySelector('input, select, textarea');
        if (primeiro) {
            primeiro.focus();
        }
    }

    function fecharSobre() {
        if (!ui.raiz) {
            return;
        }
        var s = ui.raiz.querySelector('[data-sobre]');
        s.hidden = true;
        s.innerHTML = '';
    }

    function formSalvar() {
        var privado = !estado.config || estado.config.privadoPadrao !== false;
        abrirSobre('<form data-form="salvar"><h6><i class="ti ti-device-floppy"></i> Salvar no chamado</h6>' +
            '<label>Número do chamado<input type="text" class="form-control form-control-sm" name="chamado" placeholder="Ex.: 1234" inputmode="numeric" required></label>' +
            '<label>Mensagens<select class="form-select form-select-sm" name="quantidade"><option value="10">Últimas 10</option><option value="20">Últimas 20</option><option value="50" selected>Últimas 50</option><option value="200">Últimas 200</option></select></label>' +
            '<label class="chatglpi-linha-check"><input type="checkbox" class="chatglpi-check" name="privado" value="1"' + (privado ? ' checked' : '') + '> Acompanhamento privado</label>' +
            '<div class="chatglpi-sobre-botoes"><button type="submit" class="btn btn-sm chatglpi-btn-enviar"><i class="ti ti-device-floppy"></i> Salvar</button><button type="button" class="btn btn-sm btn-secondary" data-acao="fechar-sobre">Cancelar</button></div></form>');
    }

    function formAbrir() {
        abrirSobre('<div class="chatglpi-carregando"><i class="ti ti-loader"></i> Carregando…</div>');
        var membros = estado.aberta.tipo === 'sala' ? pedir('membros', { conversa: estado.aberta.id }) : Promise.resolve({ membros: [] });
        Promise.all([pedir('entidades'), membros]).then(function (r) {
            var ent = r[0];
            var opcoes = (ent.entidades || []).map(function (e) {
                return '<option value="' + e.id + '"' + (e.id === ent.atual ? ' selected' : '') + '>' + esc(e.nome) + '</option>';
            }).join('');
            var req = '';
            if (estado.aberta.tipo === 'sala') {
                req = '<label>Requerente<select class="form-select form-select-sm" name="requerente">' + (r[1].membros || []).map(function (m) {
                    return '<option value="' + m.id + '"' + (estado.eu && m.id === estado.eu.id ? ' selected' : '') + '>' + esc(m.nome) + '</option>';
                }).join('') + '</select></label>';
            }
            abrirSobre('<form data-form="abrir"><h6><i class="ti ti-ticket"></i> Abrir chamado</h6>' +
                '<label>Título<input type="text" class="form-control form-control-sm" name="titulo" maxlength="250" required></label>' +
                '<label>Entidade<select class="form-select form-select-sm" name="entidade" required>' + opcoes + '</select></label>' + req +
                '<label>Descrição<textarea class="form-control form-control-sm" name="descricao" rows="3"></textarea></label>' +
                '<label>Incluir a conversa<select class="form-select form-select-sm" name="incluir"><option value="0">Não incluir</option><option value="10" selected>Últimas 10 mensagens</option><option value="30">Últimas 30 mensagens</option><option value="100">Últimas 100 mensagens</option></select></label>' +
                (estado.aberta.tipo === 'direta' ? '<p class="chatglpi-ajuda"><i class="ti ti-info-circle"></i> ' + esc(estado.aberta.titulo) + ' será o requerente.</p>' : '') +
                '<div class="chatglpi-sobre-botoes"><button type="submit" class="btn btn-sm chatglpi-btn-enviar"><i class="ti ti-ticket"></i> Abrir chamado</button><button type="button" class="btn btn-sm btn-secondary" data-acao="fechar-sobre">Cancelar</button></div></form>');
        });
    }

    function verMembros() {
        abrirSobre('<div class="chatglpi-carregando"><i class="ti ti-loader"></i> Carregando…</div>');
        pedir('membros', { conversa: estado.aberta.id }).then(function (j) {
            var html = '<h6><i class="ti ti-users"></i> Membros (' + (j.membros || []).length + ')</h6><ul class="chatglpi-lista chatglpi-lista-membros">';
            (j.membros || []).forEach(function (m) {
                html += '<li><button type="button" class="chatglpi-item" data-usuario="' + m.id + '">' + avatar(m.foto, m.ini, m.id, m.status) + '<span class="chatglpi-item-texto"><span class="chatglpi-item-nome">' + esc(m.nome) + '</span><span class="chatglpi-item-previa">' + esc(rotuloStatus(m.status)) + '</span></span></button></li>';
            });
            abrirSobre(html + '</ul><div class="chatglpi-sobre-botoes"><button type="button" class="btn btn-sm btn-secondary" data-acao="fechar-sobre">Fechar</button></div>');
        });
    }

    function enviarFormulario(form) {
        var tipo = form.getAttribute('data-form');
        var fd = new FormData(form);
        fd.append('conversa', estado.aberta.id);
        if (tipo === 'salvar' && !fd.get('privado')) {
            fd.append('privado', '0');
        }
        var botao = form.querySelector('[type="submit"]');
        botao.disabled = true;
        pedir(tipo === 'salvar' ? 'salvar_chamado' : 'abrir_chamado', fd, 'POST').then(function (j) {
            botao.disabled = false;
            if (!j.success) {
                var erro = form.querySelector('.chatglpi-alerta') || document.createElement('div');
                erro.className = 'chatglpi-alerta';
                erro.textContent = j.message;
                form.insertBefore(erro, form.querySelector('.chatglpi-sobre-botoes'));
                return;
            }
            abrirSobre('<div class="chatglpi-sucesso"><i class="ti ti-circle-check"></i><span>' + esc(j.message) + '</span></div>' +
                '<div class="chatglpi-sobre-botoes"><a class="btn btn-sm chatglpi-btn-enviar" href="' + esc(j.link) + '" target="_blank"><i class="ti ti-external-link"></i> Ver chamado</a><button type="button" class="btn btn-sm btn-secondary" data-acao="fechar-sobre">Fechar</button></div>');
            reagendar(300);
        }).catch(function () {
            botao.disabled = false;
            aviso('Falha de comunicação com o servidor.', true);
        });
    }

    // ------------------------------------------------------------------ eventos

    function fecharSuspensos(exceto) {
        document.querySelectorAll('.chatglpi [data-suspenso]').forEach(function (s) {
            if (s !== exceto) {
                s.hidden = true;
            }
        });
    }

    function ligar(r) {
        r.addEventListener('click', function (ev) {
            var alvo = ev.target.closest('[data-acao], [data-aba], [data-conversa-id], [data-usuario], [data-status], [data-link]');
            if (!alvo || !r.contains(alvo)) {
                if (!ev.target.closest('[data-suspenso]')) {
                    fecharSuspensos();
                }
                return;
            }
            if (alvo.hasAttribute('data-link')) {
                if (!estado.config) {
                    ev.preventDefault();
                    return;
                }
                var destino = alvo.getAttribute('data-link') === 'salas' ? estado.config.paginaSalas : estado.config.paginaChat + (estado.aberta ? '?conversa=' + estado.aberta.id : '');
                alvo.setAttribute('href', destino);
                return;
            }
            if (alvo.hasAttribute('data-aba')) {
                estado.aba = alvo.getAttribute('data-aba');
                if (estado.aba === 'pessoas') {
                    estado.pessoas = [];
                    reagendar(10);
                }
                desenharLista();
                return;
            }
            if (alvo.hasAttribute('data-conversa-id')) {
                abrirConversa({ conversa: alvo.getAttribute('data-conversa-id') });
                return;
            }
            if (alvo.hasAttribute('data-usuario')) {
                abrirConversa({ usuario: alvo.getAttribute('data-usuario') });
                return;
            }
            if (alvo.hasAttribute('data-status')) {
                var st = alvo.getAttribute('data-status');
                pedir('status', { status: st }, 'POST').then(function () {
                    estado.eu.status = st === 'online' ? 'online' : st;
                    desenharMeuStatus();
                });
                fecharSuspensos();
                return;
            }
            var acao = alvo.getAttribute('data-acao');
            var msg = alvo.closest('[data-msg]');
            switch (acao) {
                case 'fechar':
                    alternarPainel(false);
                    break;
                case 'voltar':
                    fecharConversa();
                    break;
                case 'menu-status':
                case 'menu-conversa':
                    var s = alvo.parentNode.querySelector('[data-suspenso]');
                    fecharSuspensos(s);
                    s.hidden = !s.hidden;
                    break;
                case 'anexar':
                    r.querySelector('[data-arquivo]').click();
                    break;
                case 'tirar-anexo':
                    descartarAnexo();
                    break;
                case 'mais':
                    alvo.disabled = true;
                    pedir('historico', { conversa: estado.aberta.id, antes: estado.primeiro }).then(function (j) {
                        estado.mais = !!j.mais;
                        var caixa = r.querySelector('[data-mensagens]');
                        var bloco = caixa.querySelector('[data-mais]');
                        if (bloco) {
                            bloco.remove();
                        }
                        adicionarMensagens(j.mensagens || [], 'inicio');
                        if (estado.mais && !caixa.querySelector('[data-mais]')) {
                            caixa.insertAdjacentHTML('afterbegin', '<div class="chatglpi-mais" data-mais><button type="button" class="btn btn-sm btn-ghost-secondary" data-acao="mais">Mensagens anteriores</button></div>');
                        }
                    });
                    break;
                case 'editar':
                    editarMensagem(msg);
                    break;
                case 'cancelar-edicao':
                    pedir('historico', { conversa: estado.aberta.id, antes: parseInt(msg.getAttribute('data-msg'), 10) + 1 }).then(function (j) {
                        var m = (j.mensagens || []).find(function (x) { return String(x.id) === msg.getAttribute('data-msg'); });
                        if (m) {
                            substituirMensagem(m);
                        }
                    });
                    break;
                case 'salvar-edicao':
                    var texto = msg.querySelector('[data-edicao] textarea').value;
                    pedir('editar', { id: msg.getAttribute('data-msg'), texto: texto }, 'POST').then(function (j) {
                        if (!j.success) {
                            aviso(j.message, true);
                            return;
                        }
                        substituirMensagem(j.mensagem);
                    });
                    break;
                case 'apagar':
                    if (!alvo.classList.contains('confirmar')) {
                        alvo.classList.add('confirmar');
                        alvo.innerHTML = '<i class="ti ti-alert-triangle"></i> Apagar?';
                        setTimeout(function () {
                            if (alvo.isConnected) {
                                alvo.classList.remove('confirmar');
                                alvo.innerHTML = '<i class="ti ti-trash"></i>';
                            }
                        }, 4000);
                        break;
                    }
                    pedir('apagar', { id: msg.getAttribute('data-msg') }, 'POST').then(function (j) {
                        if (!j.success) {
                            aviso(j.message, true);
                            return;
                        }
                        substituirMensagem(j.mensagem);
                    });
                    break;
                case 'form-salvar':
                    fecharSuspensos();
                    formSalvar();
                    break;
                case 'form-abrir':
                    fecharSuspensos();
                    formAbrir();
                    break;
                case 'membros':
                    fecharSuspensos();
                    verMembros();
                    break;
                case 'silenciar':
                case 'ocultar':
                    fecharSuspensos();
                    pedir(acao, { conversa: estado.aberta.id }, 'POST').then(function (j) {
                        if (!j.success) {
                            return;
                        }
                        if (acao === 'silenciar') {
                            estado.aberta.silenciada = !!j.valor;
                            desenharCabecalho();
                            aviso(j.valor ? 'Conversa silenciada.' : 'Avisos reativados.', false);
                        } else {
                            fecharConversa();
                        }
                        reagendar(200);
                    });
                    break;
                case 'fechar-sobre':
                    fecharSobre();
                    break;
            }
        });

        r.addEventListener('change', function (ev) {
            var t = ev.target;
            if (t.matches('[data-arquivo]') && t.files && t.files[0]) {
                escolherAnexo(t.files[0]);
            }
            if (t.matches('[data-pref]')) {
                var nome = t.getAttribute('data-pref');
                var valor = t.checked ? 1 : 0;
                var gravar = function () {
                    var dados = {};
                    dados[nome] = valor;
                    pedir('preferencias', dados, 'POST');
                    if (nome === 'notificar_navegador') {
                        estado.eu.notificar = !!valor;
                    } else {
                        estado.eu.som = !!valor;
                    }
                };
                if (nome === 'notificar_navegador' && valor && 'Notification' in window && Notification.permission === 'default') {
                    Notification.requestPermission().then(gravar);
                } else {
                    gravar();
                }
            }
        });

        r.addEventListener('submit', function (ev) {
            ev.preventDefault();
            if (ev.target.matches('[data-compor]')) {
                enviar();
            } else if (ev.target.matches('[data-form]')) {
                enviarFormulario(ev.target);
            }
        });

        r.addEventListener('input', function (ev) {
            var t = ev.target;
            if (t.matches('[data-busca]')) {
                estado.busca = t.value.trim();
                desenharLista();
                if (estado.aba === 'pessoas') {
                    clearTimeout(estado.timerBusca);
                    estado.timerBusca = setTimeout(function () { reagendar(10); }, 300);
                }
            }
            if (t.matches('[data-texto]')) {
                ajustarAltura(t);
                estado.digitandoAte = Date.now() + 4000;
            }
        });

        r.addEventListener('keydown', function (ev) {
            if (ev.target.matches('[data-texto]') && ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) {
                ev.preventDefault();
                enviar();
            }
            if (ev.target.matches('[data-edicao] textarea') && ev.key === 'Enter' && !ev.shiftKey) {
                ev.preventDefault();
                ev.target.closest('[data-msg]').querySelector('[data-acao="salvar-edicao"]').click();
            }
            if (ev.key === 'Escape') {
                if (!r.querySelector('[data-sobre]').hidden) {
                    fecharSobre();
                } else if (ui.painel === r && !ui.painel.hidden) {
                    alternarPainel(false);
                }
            }
        });

        r.addEventListener('paste', function (ev) {
            if (!ev.target.matches('[data-texto]') || !ev.clipboardData) {
                return;
            }
            var itens = Array.from(ev.clipboardData.items || []).filter(function (i) { return i.kind === 'file'; });
            if (itens.length) {
                ev.preventDefault();
                var f = itens[0].getAsFile();
                if (f && !f.name) {
                    f = new File([f], 'imagem-' + Date.now() + '.png', { type: f.type });
                } else if (f && /^image\./.test(f.name)) {
                    f = new File([f], 'imagem-' + Date.now() + '.' + (f.type.split('/')[1] || 'png'), { type: f.type });
                }
                escolherAnexo(f);
            }
        });

        var caixa = r.querySelector('[data-mensagens]');
        caixa.addEventListener('dragover', function (ev) {
            if (estado.config && estado.config.anexos) {
                ev.preventDefault();
                caixa.classList.add('arrastando');
            }
        });
        caixa.addEventListener('dragleave', function () { caixa.classList.remove('arrastando'); });
        caixa.addEventListener('drop', function (ev) {
            caixa.classList.remove('arrastando');
            if (ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files[0]) {
                ev.preventDefault();
                escolherAnexo(ev.dataTransfer.files[0]);
            }
        });
    }

    // ------------------------------------------------------------------ sincronização

    function visivel() {
        return document.visibilityState === 'visible';
    }

    function chatVisivel() {
        return visivel() && (!!ui.pagina || estado.painelAberto);
    }

    function intervalo() {
        var c = estado.config || { intervaloAberto: 4, intervaloFechado: 20 };
        return (chatVisivel() ? c.intervaloAberto : c.intervaloFechado) * 1000;
    }

    function reagendar(ms) {
        clearTimeout(estado.timer);
        estado.timer = setTimeout(sincronizar, ms === undefined ? intervalo() : ms);
    }

    function sincronizar() {
        if (estado.sincronizando) {
            reagendar(1000);
            return;
        }
        estado.sincronizando = true;
        var aberta = estado.aberta && chatVisivel() ? estado.aberta.id : (estado.aberta ? estado.aberta.id : 0);
        var params = {
            primeira: estado.config ? '' : 1,
            aberta: aberta || '',
            ultimo: aberta ? estado.ultimo : '',
            desde: aberta ? estado.agora : '',
            ativo: estado.interagiu ? 1 : '',
            digitando: (aberta && estado.digitandoAte > Date.now()) ? aberta : '',
            visivel: chatVisivel() ? 1 : '',
            pessoas: (estado.aba === 'pessoas' && chatVisivel()) ? 1 : '',
            busca: estado.aba === 'pessoas' ? estado.busca : ''
        };
        estado.interagiu = false;
        pedir('sincronizar', params).then(function (j) {
            if (!j.success) {
                if (j.sem_acesso) {
                    if (ui.botao) {
                        ui.botao.remove();
                    }
                    return 'parar';
                }
                return;
            }
            if (j.config) {
                estado.config = j.config;
                if (ui.raiz) {
                    desenharLista();
                }
            }
            estado.eu = j.eu;
            estado.agora = j.agora;
            avisarNovas(j.conversas);
            estado.conversas = j.conversas;
            estado.total = j.total;
            if (j.pessoas) {
                estado.pessoas = j.pessoas;
            }
            badge();
            desenharMeuStatus();
            if (ui.raiz && (estado.painelAberto || ui.pagina)) {
                var foco = document.activeElement;
                var buscando = foco && foco.matches && foco.matches('[data-busca]');
                desenharLista();
                if (buscando) {
                    foco.focus();
                }
            }
            if (j.aberta && estado.aberta && j.aberta.id === estado.aberta.id && ui.raiz) {
                var fim = pertoDoFim();
                adicionarMensagens(j.aberta.novas, 'fim');
                (j.aberta.alteradas || []).forEach(substituirMensagem);
                if (fim) {
                    rolarFim(true);
                }
                if (j.aberta.lida_ate !== estado.lidaAte) {
                    estado.lidaAte = j.aberta.lida_ate;
                    atualizarLidas();
                }
                var dig = ui.raiz.querySelector('[data-digitando]');
                dig.hidden = !j.aberta.digitando.length;
                dig.textContent = j.aberta.digitando.length ? j.aberta.digitando.join(', ') + (j.aberta.digitando.length > 1 ? ' estão digitando…' : ' está digitando…') : '';
                if (j.aberta.status && estado.aberta.tipo === 'direta' && j.aberta.status !== estado.aberta.status) {
                    estado.aberta.status = j.aberta.status;
                    desenharCabecalho();
                }
            }
            var titulo = document.title.replace(/^\(\d+\+?\)\s*/, '');
            document.title = estado.total ? '(' + estado.total + ') ' + titulo : titulo;
        }).catch(function () { /* tenta de novo no próximo ciclo */ }).then(function (r) {
            estado.sincronizando = false;
            if (r !== 'parar') {
                reagendar();
            }
        });
    }

    /** Aviso do navegador e som para mensagens novas fora da conversa que está na tela */
    function avisarNovas(conversas) {
        var primeira = estado.conhecidas === null;
        var antes = estado.conhecidas || {};
        estado.conhecidas = {};
        conversas.forEach(function (c) { estado.conhecidas[c.id] = c.ultima_id; });
        if (primeira || !estado.eu) {
            return;
        }
        var novas = conversas.filter(function (c) {
            var naTela = estado.aberta && estado.aberta.id === c.id && chatVisivel();
            return c.nao_lidas > 0 && !c.silenciada && c.ultima_id > (antes[c.id] || 0) && !naTela;
        });
        if (!novas.length) {
            return;
        }
        if (estado.eu.som) {
            tocar();
        }
        if (estado.eu.notificar && !visivel() && 'Notification' in window && Notification.permission === 'granted') {
            novas.slice(0, 3).forEach(function (c) {
                try {
                    var n = new Notification(c.titulo, { body: c.previa || 'Nova mensagem', tag: 'chatglpi-' + c.id, icon: raiz + '/pics/favicon.ico' });
                    n.onclick = function () {
                        window.focus();
                        if (!ui.pagina) {
                            alternarPainel(true);
                        }
                        abrirConversa({ conversa: c.id });
                        n.close();
                    };
                } catch (e) { /* navegador sem suporte */ }
            });
        }
    }

    var audio = null;

    function tocar() {
        try {
            audio = audio || new (window.AudioContext || window.webkitAudioContext)();
            var o = audio.createOscillator();
            var g = audio.createGain();
            o.type = 'sine';
            o.frequency.value = 880;
            g.gain.setValueAtTime(0.0001, audio.currentTime);
            g.gain.exponentialRampToValueAtTime(0.08, audio.currentTime + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + 0.25);
            o.connect(g);
            g.connect(audio.destination);
            o.start();
            o.stop(audio.currentTime + 0.3);
        } catch (e) { /* sem áudio */ }
    }

    // ------------------------------------------------------------------ início

    function marcarInteracao() {
        estado.interagiu = true;
        estado.ultimaInteracao = Date.now();
    }

    function iniciar() {
        ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function (ev) {
            document.addEventListener(ev, marcarInteracao, { passive: true, capture: true });
        });
        document.addEventListener('visibilitychange', function () {
            if (visivel()) {
                marcarInteracao();
                reagendar(200);
            }
        });
        document.addEventListener('click', function (ev) {
            if (!ev.target.closest('.chatglpi [data-suspenso], .chatglpi [data-acao="menu-status"], .chatglpi [data-acao="menu-conversa"]')) {
                fecharSuspensos();
            }
            if (estado.painelAberto && ui.painel && !ui.painel.contains(ev.target) && ui.botao && !ui.botao.contains(ev.target) && !ev.target.closest('.modal, .select2-container')) {
                if (window.innerWidth < 768) {
                    alternarPainel(false);
                }
            }
        });

        var pagina = document.querySelector('[data-chatglpi-pagina]');
        if (pagina) {
            ui.pagina = pagina;
            pagina.classList.add('chatglpi', 'chatglpi-modo-pagina');
            pagina.innerHTML = estrutura();
            ui.raiz = pagina;
            ligar(pagina);
            pagina.querySelector('[data-acao="fechar"]').hidden = true;
            pagina.querySelector('[data-link="pagina"]').hidden = true;
        }
        var tentativas = 0;
        var esperarBarra = function () {
            if (!montarBotao() && tentativas++ < 40) {
                setTimeout(esperarBarra, 150);
            }
        };
        esperarBarra();

        // Primeira sincronização traz a configuração; na página, abre a conversa pedida
        estado.sincronizando = true;
        pedir('sincronizar', { primeira: 1, ativo: 1 }).then(function (j) {
            estado.sincronizando = false;
            if (!j.success) {
                if (j.sem_acesso && ui.botao) {
                    ui.botao.remove();
                }
                return;
            }
            estado.config = j.config;
            estado.eu = j.eu;
            estado.agora = j.agora;
            estado.conversas = j.conversas;
            estado.total = j.total;
            avisarNovas(j.conversas);
            badge();
            if (ui.pagina) {
                desenharLista();
                desenharMeuStatus();
                var c = parseInt(ui.pagina.getAttribute('data-abrir-conversa'), 10) || 0;
                var u = parseInt(ui.pagina.getAttribute('data-abrir-usuario'), 10) || 0;
                if (c || u) {
                    abrirConversa(c ? { conversa: c } : { usuario: u });
                }
            }
            reagendar();
        }).catch(function () {
            estado.sincronizando = false;
            reagendar(30000);
        });
    }

    window.ChatGlpi = {
        abrir: function (usuario) {
            if (!ui.pagina) {
                alternarPainel(true);
            }
            return abrirConversa({ usuario: usuario });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
