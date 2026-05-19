// Confirmação antes de deletar
document.addEventListener('click', function(e) {
    const btn = e.target.closest('[data-confirm]');
    if (!btn) return;
    if (!confirm(btn.dataset.confirm || 'Confirmar?')) e.preventDefault();
});

// Chat IA
(function() {
    const form = document.getElementById('chat-form');
    if (!form) return;

    const input    = form.querySelector('#chat-input');
    const messages = document.getElementById('chat-messages');

    function appendMsg(text, type) {
        const div = document.createElement('div');
        div.className = 'msg msg-' + type;
        div.textContent = text;
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
        return div;
    }

    function appendDownload(texto, download) {
        const div = document.createElement('div');
        div.className = 'msg msg-ai';
        div.innerHTML = texto + '<br><a class="btn-download" href="' + download.url + '" download>' + download.label + '</a>';
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
    }

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const q = input.value.trim();
        if (!q) return;
        input.value = '';
        appendMsg(q, 'user');
        const typing = appendMsg('Digitando...', 'ai msg-typing');

        const apiPath = window.chatApiPath || 'api/chat.php';
        try {
            const res = await fetch(apiPath, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'pergunta=' + encodeURIComponent(q)
            });
            const data = await res.json();
            typing.remove();
            if (data.download) {
                appendDownload(data.resposta || 'Pronto!', data.download);
            } else {
                appendMsg(data.resposta || 'Sem resposta.', 'ai');
            }
        } catch (err) {
            typing.remove();
            appendMsg('Erro ao conectar com a IA.', 'ai');
        }
    });
})();

// Hamburger / sidebar mobile
(function() {
    const btn     = document.getElementById('hamburger-btn');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    if (!btn || !sidebar || !overlay) return;

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('active');
        btn.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        btn.classList.remove('open');
        document.body.style.overflow = '';
    }

    btn.addEventListener('click', function() {
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    });

    overlay.addEventListener('click', closeSidebar);

    // Fechar sidebar ao navegar (click em link da sidebar)
    sidebar.querySelectorAll('a').forEach(function(a) {
        a.addEventListener('click', closeSidebar);
    });

    // Fechar ao redimensionar para desktop
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) closeSidebar();
    });
})();
