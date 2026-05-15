<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$base  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$token = trim($_GET['token'] ?? '');
$erro  = '';
$ok    = false;

$db = getDB();
$st = $db->prepare("SELECT rt.id, rt.usuario_id FROM recuperacao_tokens rt
    WHERE rt.token = ? AND rt.usado = 0 AND rt.expires_at > NOW()");
$st->execute([$token]);
$tokenRow = $st->fetch();

if (!$token || !$tokenRow) {
    $erro = 'Link inválido ou expirado. Solicite um novo.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenRow) {
    $nova    = $_POST['senha_nova']    ?? '';
    $confirm = $_POST['senha_confirm'] ?? '';
    if (strlen($nova) < 6) {
        $erro = 'A senha deve ter pelo menos 6 caracteres.';
    } elseif ($nova !== $confirm) {
        $erro = 'As senhas não coincidem.';
    } else {
        $hash = password_hash($nova, PASSWORD_DEFAULT);
        $db->prepare("UPDATE usuarios SET senha=? WHERE id=?")->execute([$hash, $tokenRow['usuario_id']]);
        $db->prepare("UPDATE recuperacao_tokens SET usado=1 WHERE id=?")->execute([$tokenRow['id']]);
        $ok = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Redefinir Senha — Aluguel Manager</title>
    <link rel="stylesheet" href="<?= $base ?>/../assets/style.css">
    <style>
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--bg); }
        .login-box { background:var(--white); border:1px solid var(--border); border-radius:var(--radius); padding:40px 36px; width:100%; max-width:380px; box-shadow:var(--shadow-md); }
        .login-logo { display:flex; align-items:center; gap:10px; font-size:1.1rem; font-weight:700; color:var(--text); margin-bottom:28px; }
        .login-logo svg { color:var(--primary); }
        .login-box .btn-primary { width:100%; justify-content:center; padding:10px; margin-top:8px; }
        .back-link { display:block; text-align:center; margin-top:16px; font-size:.875rem; }
    </style>
</head>
<body>
<div class="login-box">
    <div class="login-logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        Aluguel Manager
    </div>

    <?php if ($ok): ?>
        <div class="flash flash-success">Senha redefinida com sucesso!</div>
        <a href="<?= $base ?>/../login.php" class="btn btn-primary" style="display:flex;justify-content:center;margin-top:16px">Ir para o Login</a>
    <?php elseif ($erro && !$tokenRow): ?>
        <div class="flash flash-error"><?= h($erro) ?></div>
        <a href="<?= $base ?>/recuperar-senha.php" class="back-link">Solicitar novo link</a>
    <?php else: ?>
        <h1 style="font-size:1.2rem;font-weight:700;margin-bottom:20px">Nova Senha</h1>
        <?php if ($erro): ?><div class="flash flash-error"><?= h($erro) ?></div><?php endif ?>
        <form method="POST">
            <div class="field" style="margin-bottom:16px">
                <label for="senha_nova">Nova Senha</label>
                <input type="password" id="senha_nova" name="senha_nova" required minlength="6" autofocus>
            </div>
            <div class="field" style="margin-bottom:16px">
                <label for="senha_confirm">Confirmar Senha</label>
                <input type="password" id="senha_confirm" name="senha_confirm" required minlength="6">
            </div>
            <button type="submit" class="btn btn-primary">Salvar Nova Senha</button>
        </form>
    <?php endif ?>
</div>
</body>
</html>
