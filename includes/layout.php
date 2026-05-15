<?php
function layoutHead(string $title): void {
    // Sempre aponta para a raiz do projeto, independente do subdiretório atual
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $base = preg_replace('|/pages$|', '', $scriptDir);
    $page = basename($_SERVER['PHP_SELF']);
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> — Aluguel Manager</title>
    <link rel="stylesheet" href="<?= $base ?>/assets/style.css">
</head>
<body>
<div class="layout">

<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-name">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Aluguel Manager
        </div>
        <small>Gestão de Imóveis</small>
    </div>

    <nav>
        <a href="<?= $base ?>/index.php" class="<?= $page === 'index.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            Dashboard
        </a>
        <a href="<?= $base ?>/pages/imoveis.php" class="<?= $page === 'imoveis.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Imóveis
        </a>
        <a href="<?= $base ?>/pages/inquilinos.php" class="<?= $page === 'inquilinos.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Inquilinos
        </a>
        <a href="<?= $base ?>/pages/contratos.php" class="<?= $page === 'contratos.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            Contratos
        </a>
        <a href="<?= $base ?>/pages/pagamentos.php" class="<?= $page === 'pagamentos.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            Pagamentos
        </a>
        <a href="<?= $base ?>/pages/chat.php" class="<?= $page === 'chat.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            Chat IA
        </a>
        <?php if (!empty($_SESSION['user_id'])): ?>
        <?php
        $db = getDB();
        $adminCheck = $db->prepare("SELECT is_admin FROM usuarios WHERE id = ?");
        $adminCheck->execute([$_SESSION['user_id']]);
        $isAdminNav = (bool)($adminCheck->fetchColumn());
        ?>
        <?php if ($isAdminNav): ?>
        <a href="<?= $base ?>/pages/usuarios.php" class="<?= $page === 'usuarios.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            Usuários
        </a>
        <?php endif ?>
        <a href="<?= $base ?>/pages/grupos.php" class="<?= $page === 'grupos.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            Grupos
        </a>
        <a href="<?= $base ?>/pages/perfil.php" class="<?= $page === 'perfil.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            Meu Perfil
        </a>
        <a href="<?= $base ?>/logout.php" style="margin-top:4px">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sair
        </a>
        <?php endif ?>
    </nav>

    <div class="sidebar-footer">
        <?php
        $nome = $_SESSION['user_nome'] ?? 'Usuário';
        echo h($nome);
        ?>
    </div>
</aside>

<div class="main">
    <div class="topbar">
        <span class="topbar-title"><?= h($title) ?></span>
    </div>
    <div class="content">
    <?php
}

function layoutFoot(): void {
    ?>
    </div><!-- .content -->
</div><!-- .main -->

</div><!-- .layout -->
<script src="<?php echo preg_replace('|/pages$|', '', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/')); ?>/assets/app.js"></script>
</body>
</html>
    <?php
}

function renderFlash(): void {
    $f = getFlash();
    if (!$f) return;
    $class = $f['tipo'] === 'error' ? 'flash-error' : 'flash-success';
    echo "<div class=\"flash {$class}\">" . h($f['msg']) . "</div>";
}
