<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/storage.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['erro' => 'Método inválido']); exit; }
if (empty($_SESSION['user_id'])) { echo json_encode(['erro' => 'Não autenticado']); exit; }

$userId = (int)$_SESSION['user_id'];
$arqId  = (int)($_POST['id'] ?? 0);
if (!$arqId) { echo json_encode(['erro' => 'ID inválido']); exit; }

$db = getDB();
$arq = $db->prepare("SELECT * FROM arquivos WHERE id=?");
$arq->execute([$arqId]);
$arq = $arq->fetch();
if (!$arq) { echo json_encode(['erro' => 'Arquivo não encontrado']); exit; }

// Só o uploader ou admin pode deletar
$isAdminUser = (bool)$db->query("SELECT is_admin FROM usuarios WHERE id=$userId")->fetchColumn();
if ($arq['usuario_id'] !== $userId && !$isAdminUser) {
    echo json_encode(['erro' => 'Sem permissão']); exit;
}

try {
    storageDelete($arq['driver'], $arq['file_key'], $arq['usuario_id']);
    $db->prepare("DELETE FROM arquivos WHERE id=?")->execute([$arqId]);
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['erro' => 'Falha ao deletar: ' . $e->getMessage()]);
}
