<?php
function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
        header('Location: ' . $base . '/login.php');
        exit;
    }
}

function currentUser(): array {
    if (empty($_SESSION['user_id'])) return [];
    static $user = null;
    if ($user === null) {
        $db = getDB();
        $st = $db->prepare("SELECT id, nome, email, gemini_api_key FROM usuarios WHERE id = ?");
        $st->execute([$_SESSION['user_id']]);
        $user = $st->fetch() ?: [];
    }
    return $user;
}

function getGeminiKey(): string {
    $user = currentUser();
    if (!empty($user['gemini_api_key'])) return $user['gemini_api_key'];
    return defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
}

function isAdmin(): bool {
    if (empty($_SESSION['user_id'])) return false;
    $db = getDB();
    $st = $db->prepare("SELECT is_admin FROM usuarios WHERE id = ?");
    $st->execute([$_SESSION['user_id']]);
    return (bool)$st->fetchColumn();
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        flash('Acesso restrito a administradores.', 'error');
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
        header('Location: ' . $base . '/../index.php');
        exit;
    }
}
