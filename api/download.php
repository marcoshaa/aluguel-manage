<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/visibilidade.php';
require_once __DIR__ . '/../includes/storage.php';

if (empty($_SESSION['user_id'])) { http_response_code(403); exit('Não autenticado'); }

$userId = (int)$_SESSION['user_id'];
$arqId  = (int)($_GET['id'] ?? 0);
if (!$arqId) { http_response_code(400); exit('ID inválido'); }

$db = getDB();
$arq = $db->prepare("SELECT * FROM arquivos WHERE id=?");
$arq->execute([$arqId]);
$arq = $arq->fetch();
if (!$arq) { http_response_code(404); exit('Arquivo não encontrado'); }

// Verificar permissão de acesso
$visiveis = imoveisVisiveis($userId);
$ok = false;
if ($arq['entity_type'] === 'imovel') {
    $ok = in_array($arq['entity_id'], $visiveis);
} elseif ($arq['entity_type'] === 'contrato') {
    $c = $db->prepare("SELECT imovel_id FROM contratos WHERE id=?"); $c->execute([$arq['entity_id']]);
    $row = $c->fetch();
    $ok = $row && in_array($row['imovel_id'], $visiveis);
} elseif ($arq['entity_type'] === 'inquilino') {
    if ($visiveis) {
        $in = implode(',', $visiveis);
        $q = $db->query("SELECT COUNT(*) FROM contratos WHERE inquilino_id={$arq['entity_id']} AND imovel_id IN ($in)");
        $ok = (int)$q->fetchColumn() > 0;
    }
}
if (!$ok) { http_response_code(403); exit('Acesso negado'); }

try {
    storageStream($arq['driver'], $arq['file_key'], $arq['usuario_id'], $arq['filename'], $arq['mime_type']);
} catch (Exception $e) {
    http_response_code(500); exit('Erro ao baixar arquivo');
}
