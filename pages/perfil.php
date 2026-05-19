<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin();

$db   = getDB();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome          = trim($_POST['nome'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $gemini_key    = trim($_POST['gemini_api_key'] ?? '');
    $senha_nova    = $_POST['senha_nova'] ?? '';
    $senha_confirm = $_POST['senha_confirm'] ?? '';

    $storage_driver = $_POST['storage_driver'] ?? 'b2';
    $b2_key_id      = trim($_POST['b2_key_id'] ?? '');
    $b2_app_key     = trim($_POST['b2_app_key'] ?? '');
    $b2_bucket_id   = trim($_POST['b2_bucket_id'] ?? '');
    $b2_bucket_name = trim($_POST['b2_bucket_name'] ?? '');
    $gdrive_json    = trim($_POST['gdrive_service_account_json'] ?? '');
    $gdrive_folder  = trim($_POST['gdrive_folder_id'] ?? '');

    // Se campo sensível vier vazio, mantém o valor atual do banco
    $b2_key_id      = $b2_key_id      ?: ($user['b2_key_id'] ?? null);
    $b2_app_key     = $b2_app_key     ?: ($user['b2_app_key'] ?? null);
    $b2_bucket_id   = $b2_bucket_id   ?: ($user['b2_bucket_id'] ?? null);
    $b2_bucket_name = $b2_bucket_name ?: ($user['b2_bucket_name'] ?? null);
    $gdrive_json    = $gdrive_json    ?: ($user['gdrive_service_account_json'] ?? null);
    $gdrive_folder  = $gdrive_folder  ?: ($user['gdrive_folder_id'] ?? null);

    $erros = [];
    if (!$nome)  $erros[] = 'Nome é obrigatório.';
    if (!$email) $erros[] = 'E-mail é obrigatório.';

    if ($senha_nova && $senha_nova !== $senha_confirm) {
        $erros[] = 'As senhas não coincidem.';
    }
    if ($senha_nova && strlen($senha_nova) < 6) {
        $erros[] = 'A nova senha deve ter pelo menos 6 caracteres.';
    }

    if (!$erros) {
        if ($senha_nova) {
            $hash = password_hash($senha_nova, PASSWORD_DEFAULT);
            $st = $db->prepare("UPDATE usuarios SET nome=?, email=?, senha=?, gemini_api_key=?,
                storage_driver=?, b2_key_id=?, b2_app_key=?, b2_bucket_id=?, b2_bucket_name=?,
                gdrive_service_account_json=?, gdrive_folder_id=? WHERE id=?");
            $st->execute([$nome, $email, $hash, $gemini_key ?: null,
                $storage_driver, $b2_key_id, $b2_app_key, $b2_bucket_id, $b2_bucket_name,
                $gdrive_json, $gdrive_folder, $user['id']]);
        } else {
            $st = $db->prepare("UPDATE usuarios SET nome=?, email=?, gemini_api_key=?,
                storage_driver=?, b2_key_id=?, b2_app_key=?, b2_bucket_id=?, b2_bucket_name=?,
                gdrive_service_account_json=?, gdrive_folder_id=? WHERE id=?");
            $st->execute([$nome, $email, $gemini_key ?: null,
                $storage_driver, $b2_key_id, $b2_app_key, $b2_bucket_id, $b2_bucket_name,
                $gdrive_json, $gdrive_folder, $user['id']]);
        }
        $_SESSION['user_nome'] = $nome;
        flash('Perfil atualizado com sucesso.');
        redirect('perfil.php');
    }

    foreach ($erros as $e) flash($e, 'error');
    $user['nome']           = $nome;
    $user['email']          = $email;
    $user['gemini_api_key'] = $gemini_key;
}

layoutHead('Meu Perfil');
renderFlash();
?>

<div class="page-title">
    <h1>Meu Perfil</h1>
</div>

<div class="form-card">
    <form method="POST" autocomplete="off">
        <div class="form-grid">
            <div class="field">
                <label for="nome">Nome</label>
                <input type="text" id="nome" name="nome" value="<?= h($user['nome']) ?>" required>
            </div>
            <div class="field">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" value="<?= h($user['email']) ?>" required>
            </div>
            <div class="field span2">
                <label for="gemini_api_key">Chave API Gemini</label>
                <input type="text" id="gemini_api_key" name="gemini_api_key"
                       value="<?= h($user['gemini_api_key'] ?? '') ?>"
                       placeholder="Cole sua chave do Google AI Studio aqui">
            </div>

            <!-- Separador visual -->
            <div class="field span2" style="border-top:1px solid var(--border);padding-top:16px;margin-top:4px">
                <label style="font-size:.9rem;font-weight:700;color:var(--text)">Armazenamento de Arquivos</label>
                <p style="font-size:.8rem;color:var(--muted);margin-top:4px">Configuração para upload de contratos, fotos e documentos.</p>
            </div>

            <div class="field span2">
                <label>Provedor</label>
                <div style="display:flex;gap:20px;margin-top:4px">
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:.9rem;cursor:pointer">
                        <input type="radio" name="storage_driver" value="b2" <?= ($user['storage_driver']??'b2')==='b2' ? 'checked' : '' ?> onchange="toggleStorage(this.value)">
                        Backblaze B2
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:.9rem;cursor:pointer">
                        <input type="radio" name="storage_driver" value="gdrive" <?= ($user['storage_driver']??'b2')==='gdrive' ? 'checked' : '' ?> onchange="toggleStorage(this.value)">
                        Google Drive
                    </label>
                </div>
            </div>

            <!-- Campos B2 -->
            <div id="b2-fields" class="field span2">
                <div class="form-grid">
                    <div class="field">
                        <label>B2 Key ID</label>
                        <input type="text" name="b2_key_id" value="<?= h($user['b2_key_id'] ?? '') ?>" placeholder="<?= !empty($user['b2_key_id']) ? 'já configurado' : 'Ex: 005abc...' ?>">
                    </div>
                    <div class="field">
                        <label>B2 Application Key</label>
                        <input type="password" name="b2_app_key" value="" placeholder="<?= !empty($user['b2_app_key']) ? 'já configurado (deixe em branco para manter)' : 'K005...' ?>">
                    </div>
                    <div class="field">
                        <label>B2 Bucket ID</label>
                        <input type="text" name="b2_bucket_id" value="<?= h($user['b2_bucket_id'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label>B2 Bucket Name</label>
                        <input type="text" name="b2_bucket_name" value="<?= h($user['b2_bucket_name'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- Campos Google Drive -->
            <div id="gdrive-fields" class="field span2" style="display:none">
                <div class="form-grid">
                    <div class="field span2">
                        <label>Service Account JSON</label>
                        <textarea name="gdrive_service_account_json" rows="5" placeholder="<?= !empty($user['gdrive_service_account_json']) ? 'já configurado (cole novamente para atualizar)' : 'Cole o conteúdo do arquivo JSON da Service Account aqui' ?>"><?= h($user['gdrive_service_account_json'] ?? '') ?></textarea>
                    </div>
                    <div class="field span2">
                        <label>Google Drive Folder ID</label>
                        <input type="text" name="gdrive_folder_id" value="<?= h($user['gdrive_folder_id'] ?? '') ?>" placeholder="ID da pasta do Drive onde os arquivos serão salvos">
                    </div>
                </div>
            </div>

            <div class="field">
                <label for="senha_nova">Nova Senha <span style="font-weight:400;color:var(--muted)">(deixe em branco para manter)</span></label>
                <input type="password" id="senha_nova" name="senha_nova" autocomplete="new-password" minlength="6">
            </div>
            <div class="field">
                <label for="senha_confirm">Confirmar Nova Senha</label>
                <input type="password" id="senha_confirm" name="senha_confirm" autocomplete="new-password">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salvar</button>
        </div>
    </form>
</div>

<script>
function toggleStorage(v) {
    document.getElementById('b2-fields').style.display = v === 'b2' ? '' : 'none';
    document.getElementById('gdrive-fields').style.display = v === 'gdrive' ? '' : 'none';
}
// Inicializa baseado no valor atual
toggleStorage(document.querySelector('input[name=storage_driver]:checked')?.value || 'b2');
</script>

<?php layoutFoot(); ?>
