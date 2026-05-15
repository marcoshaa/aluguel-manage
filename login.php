<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

// Já logado → redireciona
if (!empty($_SESSION['user_id'])) {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    header('Location: ' . $base . '/index.php');
    exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if ($email && $senha) {
        $db = getDB();
        $st = $db->prepare("SELECT id, nome, senha FROM usuarios WHERE email = ?");
        $st->execute([$email]);
        $user = $st->fetch();

        if ($user && password_verify($senha, $user['senha'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_nome'] = $user['nome'];
            $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
            header('Location: ' . $base . '/index.php');
            exit;
        }
    }
    $erro = 'E-mail ou senha incorretos.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — Aluguel Manager</title>
    <link rel="stylesheet" href="<?= rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') ?>/assets/style.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: var(--bg); }
        .login-box { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); padding: 40px 36px; width: 100%; max-width: 380px; box-shadow: var(--shadow-md); }
        .login-logo { display: flex; align-items: center; gap: 10px; font-size: 1.1rem; font-weight: 700; color: var(--text); margin-bottom: 28px; }
        .login-logo svg { color: var(--primary); }
        .login-box h1 { font-size: 1.25rem; font-weight: 700; margin-bottom: 6px; }
        .login-box p  { font-size: .875rem; color: var(--text-2); margin-bottom: 24px; }
        .login-box .field { margin-bottom: 16px; }
        .login-box .btn-primary { width: 100%; justify-content: center; padding: 10px; margin-top: 8px; font-size: .95rem; }
    </style>
</head>
<body>
<div class="login-box">
    <div class="login-logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        Aluguel Manager
    </div>
    <h1>Entrar</h1>
    <p>Acesse sua conta para continuar</p>

    <?php if ($erro): ?>
        <div class="flash flash-error"><?= h($erro) ?></div>
    <?php endif ?>

    <form method="POST" autocomplete="on">
        <div class="field">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required autofocus autocomplete="email">
        </div>
        <div class="field">
            <label for="senha">Senha</label>
            <input type="password" id="senha" name="senha" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary">Entrar</button>
        <a href="pages/recuperar-senha.php" style="display:block;text-align:center;margin-top:14px;font-size:.875rem;">Esqueceu a senha?</a>
    </form>
</div>
</body>
</html>
