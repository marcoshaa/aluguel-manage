/* ── Arquivos: modal de upload, drag-drop, progress, delete ── */
(function () {
    var entityTypeSelect = document.getElementById('entity-type');
    var entityIdSelect   = document.getElementById('entity-id');
    var dropZone         = document.getElementById('drop-zone');
    var dropText         = document.getElementById('drop-text');
    var fileInput        = document.getElementById('file-input');
    var progress         = document.getElementById('upload-progress');
    var statusEl         = document.getElementById('upload-status');
    var btnEnviar        = document.getElementById('btn-enviar');
    var uploadForm       = document.getElementById('upload-form');
    var selectedFile     = null;

    /* ── Entity type change ──────────────────────────────── */
    entityTypeSelect.addEventListener('change', function () {
        var tipo = this.value;
        entityIdSelect.innerHTML = '';
        if (!tipo || !ENTITIES[tipo] || !ENTITIES[tipo].length) {
            entityIdSelect.innerHTML = '<option value="">Nenhuma opção disponível</option>';
            return;
        }
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Selecione...';
        entityIdSelect.appendChild(placeholder);
        ENTITIES[tipo].forEach(function (item) {
            var opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.label;
            entityIdSelect.appendChild(opt);
        });
    });

    /* ── Drag-and-drop ───────────────────────────────────── */
    dropZone.addEventListener('click', function () {
        fileInput.click();
    });

    dropZone.addEventListener('dragover', function (e) {
        e.preventDefault();
        dropZone.style.borderColor = '#4a90d9';
        dropZone.style.background = '#f0f6ff';
    });

    dropZone.addEventListener('dragleave', function () {
        dropZone.style.borderColor = '#ccc';
        dropZone.style.background = '';
    });

    dropZone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropZone.style.borderColor = '#ccc';
        dropZone.style.background = '';
        if (e.dataTransfer.files.length) {
            setFile(e.dataTransfer.files[0]);
        }
    });

    fileInput.addEventListener('change', function () {
        if (this.files.length) {
            setFile(this.files[0]);
        }
    });

    function setFile(file) {
        selectedFile = file;
        dropText.textContent = file.name + ' (' + formatSize(file.size) + ')';
        updateBtn();
    }

    function updateBtn() {
        btnEnviar.disabled = !(selectedFile && entityTypeSelect.value && entityIdSelect.value);
    }

    entityTypeSelect.addEventListener('change', updateBtn);
    entityIdSelect.addEventListener('change', updateBtn);

    /* ── Upload via XHR ──────────────────────────────────── */
    uploadForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!selectedFile || !entityTypeSelect.value || !entityIdSelect.value) return;

        var fd = new FormData();
        fd.append('entity_type', entityTypeSelect.value);
        fd.append('entity_id', entityIdSelect.value);
        fd.append('arquivo', selectedFile);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.uploadPath, true);

        progress.style.display = 'block';
        statusEl.style.display = 'block';
        statusEl.textContent = 'Enviando...';
        statusEl.style.color = '#555';
        btnEnviar.disabled = true;

        xhr.upload.addEventListener('progress', function (ev) {
            if (ev.lengthComputable) {
                var pct = Math.round((ev.loaded / ev.total) * 100);
                progress.value = pct;
                statusEl.textContent = 'Enviando... ' + pct + '%';
            }
        });

        xhr.addEventListener('load', function () {
            try {
                var res = JSON.parse(xhr.responseText);
            } catch (_) {
                statusEl.textContent = 'Erro inesperado no servidor.';
                statusEl.style.color = '#c0392b';
                btnEnviar.disabled = false;
                return;
            }
            if (res.ok) {
                statusEl.textContent = 'Arquivo enviado com sucesso!';
                statusEl.style.color = '#27ae60';
                setTimeout(function () {
                    document.getElementById('upload-modal').close();
                    location.reload();
                }, 800);
            } else {
                statusEl.textContent = res.erro || 'Erro no upload.';
                statusEl.style.color = '#c0392b';
                btnEnviar.disabled = false;
            }
        });

        xhr.addEventListener('error', function () {
            statusEl.textContent = 'Erro de conexão.';
            statusEl.style.color = '#c0392b';
            btnEnviar.disabled = false;
        });

        xhr.send(fd);
    });

    /* ── Reset modal on close ────────────────────────────── */
    document.getElementById('upload-modal').addEventListener('close', function () {
        selectedFile = null;
        fileInput.value = '';
        dropText.textContent = 'Arraste um arquivo aqui ou clique para selecionar';
        progress.style.display = 'none';
        progress.value = 0;
        statusEl.style.display = 'none';
        statusEl.textContent = '';
        entityTypeSelect.value = '';
        entityIdSelect.innerHTML = '<option value="">Selecione o tipo primeiro</option>';
        btnEnviar.disabled = true;
    });

    /* ── Helpers ──────────────────────────────────────────── */
    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }
})();

/* ── Delete file (global) ────────────────────────────────── */
function deleteFile(id, btn) {
    if (!confirm('Tem certeza que deseja excluir este arquivo?')) return;
    var fd = new FormData();
    fd.append('id', id);
    fetch(window.deletePath, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.ok) {
                var row = btn.closest('tr');
                if (row) row.remove();
            } else {
                alert(res.erro || 'Erro ao excluir.');
            }
        })
        .catch(function () { alert('Erro de conexão.'); });
}
