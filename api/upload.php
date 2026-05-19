<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/visibilidade.php';
require_once __DIR__ . '/../includes/storage.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) { echo json_encode(['erro' => 'Não autenticado']); exit; }

$userId     = (int)$_SESSION['user_id'];
$entityType = $_POST['entity_type'] ?? '';
$entityId   = (int)($_POST['entity_id'] ?? 0);

$allowedTypes = ['imovel','inquilino','contrato'];
if (!in_array($entityType, $allowedTypes) || !$entityId) {
    echo json_encode(['erro' => 'Entidade inválida']); exit;
}

if (empty($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
    $errMsg = match ($_FILES['arquivo']['error'] ?? -1) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande',
        default => 'Erro no upload',
    };
    echo json_encode(['erro' => $errMsg]); exit;
}

$maxBytes = 20 * 1024 * 1024; // 20MB
if ($_FILES['arquivo']['size'] > $maxBytes) {
    echo json_encode(['erro' => 'Arquivo excede 20MB']); exit;
}

$allowedMimes = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
];
$mime = mime_content_type($_FILES['arquivo']['tmp_name']);
if (!in_array($mime, $allowedMimes)) {
    echo json_encode(['erro' => 'Tipo de arquivo não permitido']); exit;
}

// Verifica permissão de acesso
$visiveis = imoveisVisiveis($userId);
$db = getDB();

if ($entityType === 'imovel') {
    if (!in_array($entityId, $visiveis)) { echo json_encode(['erro' => 'Acesso negado']); exit; }
    $perm = permissoesImovel($userId, $entityId);
    if (!$perm['pode_escrever']) { echo json_encode(['erro' => 'Sem permissão de escrita']); exit; }
} elseif ($entityType === 'contrato') {
    $c = $db->prepare("SELECT imovel_id FROM contratos WHERE id=?"); $c->execute([$entityId]);
    $row = $c->fetch();
    if (!$row || !in_array($row['imovel_id'], $visiveis)) { echo json_encode(['erro' => 'Acesso negado']); exit; }
    $perm = permissoesImovel($userId, $row['imovel_id']);
    if (!$perm['pode_escrever']) { echo json_encode(['erro' => 'Sem permissão de escrita']); exit; }
} elseif ($entityType === 'inquilino') {
    // Inquilinos visíveis = ligados a contratos de imóveis visíveis
    if ($visiveis) {
        $in = implode(',', $visiveis);
        $q = $db->query("SELECT DISTINCT inquilino_id FROM contratos WHERE imovel_id IN ($in)");
        $inquilinosVisiveis = $q->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($entityId, $inquilinosVisiveis)) { echo json_encode(['erro' => 'Acesso negado']); exit; }
    } else { echo json_encode(['erro' => 'Acesso negado']); exit; }
}

try {
    $result = storageUpload($userId, $_FILES['arquivo']['tmp_name'], $_FILES['arquivo']['name'], $mime);
    $db->prepare("INSERT INTO arquivos (entity_type, entity_id, driver, file_key, filename, mime_type, tamanho, usuario_id)
        VALUES (?,?,?,?,?,?,?,?)")
       ->execute([
           $entityType, $entityId,
           $result['driver'], $result['file_key'],
           $result['filename'], $mime,
           $_FILES['arquivo']['size'], $userId
       ]);
    $arqId = $db->lastInsertId();
    echo json_encode(['ok' => true, 'id' => $arqId, 'filename' => $result['filename']]);
} catch (Exception $e) {
    echo json_encode(['erro' => 'Falha no upload: ' . $e->getMessage()]);
}
