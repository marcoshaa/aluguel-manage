<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if (!empty($_SESSION['user_id'])) { header('Location: ' . $base . '/../index.php'); exit; }

$msg     = '';
$tipo    = 'success';
$devLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $db    = getDB();
    $st    = $db->prepare("SELECT id, nome FROM usuarios WHERE email = ? AND ativo = 1");
    $st->execute([$email]);
    $user  = $st->fetch();

    if ($user) {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600);
        $db->prepare("INSERT INTO recuperacao_tokens (usuario_id, token, expires_at) VALUES (?,?,?)")
           ->execute([$user['id'], $token, $expires]);

        $link    = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                 . $base . '/redefinir-senha.php?token=' . $token;
        $subject = 'Recuperação de senha — Aluguel Manager';
        $body    = "Olá, {$user['nome']}!\n\nClique no link abaixo para redefinir sua senha (válido por 1 hora):\n\n{$link}\n\nSe não solicitou, ignore este email.";
        $headers = 'From: noreply@aluguelmanager.local';

        if (@mail($email, $subject, $body, $headers)) {
            $msg = 'Email enviado! Verifique sua caixa de entrada.';
        } else {
            $msg     = '[DEV] mail() não disponível localmente. Use o link abaixo:';
            $tipo    = 'error';
            $devLink = $link;
        }
    } else {
        $msg = 'Se esse email estiver cadastrado, você receberá as instruções em breve.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recuperar Senha — Aluguel Manager</title>
    <link rel="stylesheet" href="<?= $base ?>/../assets/style.css">
    <style>
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--bg); }
        .login-box { background:var(--white); border:1px solid var(--border); border-radius:var(--radius); padding:40px 36px; width:100%; max-width:380px; box-shadow:var(--shadow-md); }
        .login-logo { display:flex; align-items:center; gap:10px; font-size:1.1rem; font-weight:700; color:var(--text); margin-bottom:28px; }
        .login-logo svg { color:var(--primary); }
        .login-box .btn-primary { width:100%; justify-content:center; padding:10px; margin-top:8px; }
        .dev-link { word-break:break-all; background:#f1f5f9; padding:10px; border-radius:6px; font-size:.8rem; margin-top:10px; }
        .back-link { display:block; text-align:center; margin-top:16px; font-size:.875rem; }
    </style>
</head>
<body>
<div class="login-box">
    <div class="login-logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        Aluguel Manager
    </div>
    <h1 style="font-size:1.2rem;font-weight:700;margin-bottom:6px">Recuperar Senha</h1>
    <p style="font-size:.875rem;color:var(--text-2);margin-bottom:20px">Informe seu email e enviaremos um link de recuperação.</p>

    <?php if ($msg): ?>
        <div class="flash <?= $tipo === 'error' ? 'flash-error' : 'flash-success' ?>"><?= h($msg) ?></div>
        <?php if ($devLink): ?>
            <div class="dev-link"><a href="<?= h($devLink) ?>"><?= h($devLink) ?></a></div>
        <?php endif ?>
    <?php endif ?>

    <?php if (!$msg): ?>
    <form method="POST">
        <div class="field" style="margin-bottom:16px">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required autofocus autocomplete="email">
        </div>
        <button type="submit" class="btn btn-primary">Enviar Link</button>
    </form>
    <?php endif ?>

    <a href="<?= $base ?>/../login.php" class="back-link">← Voltar ao login</a>
</div>
</body>
</html>
