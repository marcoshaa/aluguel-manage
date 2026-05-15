<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

requireLogin();

$db   = getDB();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome          = trim($_POST['nome'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $gemini_key    = trim($_POST['gemini_api_key'] ?? '');
    $senha_nova    = $_POST['senha_nova'] ?? '';
    $senha_confirm = $_POST['senha_confirm'] ?? '';

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
            $st = $db->prepare("UPDATE usuarios SET nome=?, email=?, senha=?, gemini_api_key=? WHERE id=?");
            $st->execute([$nome, $email, $hash, $gemini_key ?: null, $user['id']]);
        } else {
            $st = $db->prepare("UPDATE usuarios SET nome=?, email=?, gemini_api_key=? WHERE id=?");
            $st->execute([$nome, $email, $gemini_key ?: null, $user['id']]);
        }
        $_SESSION['user_nome'] = $nome;
        flash('Perfil atualizado com sucesso.');
        redirect('perfil.php');
    }

    foreach ($erros as $e) flash($e, 'error');
    // Mantém dados no formulário
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

<?php layoutFoot(); ?>
