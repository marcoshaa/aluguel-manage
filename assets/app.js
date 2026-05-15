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
