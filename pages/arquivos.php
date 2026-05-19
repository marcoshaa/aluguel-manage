<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/visibilidade.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin();

$db       = getDB();
$userId   = (int)$_SESSION['user_id'];
$visiveis = imoveisVisiveis($userId);
$inClause = $visiveis ? implode(',', $visiveis) : '0';

// Filtro por tipo de entidade
$filtroTipo = $_GET['tipo'] ?? '';
$tiposValidos = ['imovel', 'inquilino', 'contrato'];

// Buscar arquivos visíveis
$whereExtra = '';
if ($filtroTipo && in_array($filtroTipo, $tiposValidos)) {
    $whereExtra = "AND a.entity_type = " . $db->quote($filtroTipo);
}

$sql = "SELECT a.*,
    CASE a.entity_type
        WHEN 'imovel'    THEN (SELECT endereco FROM imoveis WHERE id = a.entity_id)
        WHEN 'inquilino' THEN (SELECT nome FROM inquilinos WHERE id = a.entity_id)
        WHEN 'contrato'  THEN (SELECT CONCAT(i.endereco, ' — ', q.nome) FROM contratos c
                                JOIN imoveis i ON i.id = c.imovel_id
                                JOIN inquilinos q ON q.id = c.inquilino_id
                                WHERE c.id = a.entity_id)
    END AS entity_label
FROM arquivos a
WHERE (
    (a.entity_type = 'imovel'    AND a.entity_id IN ({$inClause})) OR
    (a.entity_type = 'contrato'  AND a.entity_id IN (SELECT id FROM contratos WHERE imovel_id IN ({$inClause}))) OR
    (a.entity_type = 'inquilino' AND a.entity_id IN (SELECT DISTINCT inquilino_id FROM contratos WHERE imovel_id IN ({$inClause})))
)
{$whereExtra}
ORDER BY a.created_at DESC";

$arquivos = $db->query($sql)->fetchAll();

// Dados para o modal de upload
$imoveisList = $db->query("SELECT id, endereco AS label FROM imoveis WHERE id IN ({$inClause}) ORDER BY endereco")->fetchAll();
$inquilinosList = $db->query("SELECT DISTINCT q.id, q.nome AS label FROM inquilinos q JOIN contratos c ON c.inquilino_id = q.id WHERE c.imovel_id IN ({$inClause}) ORDER BY q.nome")->fetchAll();
$contratosList = $db->query("SELECT c.id, CONCAT(i.endereco, ' — ', q.nome) AS label FROM contratos c JOIN imoveis i ON i.id = c.imovel_id JOIN inquilinos q ON q.id = c.inquilino_id WHERE c.imovel_id IN ({$inClause}) ORDER BY i.endereco")->fetchAll();

function formatBytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

function fileIcon(string $mime): string {
    if (str_contains($mime, 'pdf')) return "\xF0\x9F\x93\x84";
    if (str_contains($mime, 'word') || str_contains($mime, 'document')) return "\xF0\x9F\x93\x9D";
    if (str_starts_with($mime, 'image/')) return "\xF0\x9F\x96\xBC\xEF\xB8\x8F";
    return "\xF0\x9F\x93\x8E";
}

function entityTypeLabel(string $type): string {
    return match ($type) {
        'imovel'    => 'Imóvel',
        'inquilino' => 'Inquilino',
        'contrato'  => 'Contrato',
        default     => $type,
    };
}

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$base = preg_replace('|/pages$|', '', $scriptDir);

layoutHead('Arquivos');
renderFlash();
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem">
    <div style="display:flex; gap:.5rem">
        <a href="?tipo=" class="btn <?= $filtroTipo === '' ? 'btn-primary' : '' ?>" style="text-decoration:none">Todos</a>
        <a href="?tipo=imovel" class="btn <?= $filtroTipo === 'imovel' ? 'btn-primary' : '' ?>" style="text-decoration:none">Imóveis</a>
        <a href="?tipo=inquilino" class="btn <?= $filtroTipo === 'inquilino' ? 'btn-primary' : '' ?>" style="text-decoration:none">Inquilinos</a>
        <a href="?tipo=contrato" class="btn <?= $filtroTipo === 'contrato' ? 'btn-primary' : '' ?>" style="text-decoration:none">Contratos</a>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('upload-modal').showModal()">Enviar Arquivo</button>
</div>

<table class="table">
    <thead>
        <tr>
            <th>Arquivo</th>
            <th>Tipo</th>
            <th>Entidade</th>
            <th>Tamanho</th>
            <th>Data</th>
            <th>Ações</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($arquivos)): ?>
        <tr><td colspan="6" style="text-align:center;color:#888">Nenhum arquivo encontrado.</td></tr>
    <?php else: ?>
        <?php foreach ($arquivos as $arq): ?>
        <tr data-id="<?= $arq['id'] ?>" data-tipo="<?= h($arq['entity_type']) ?>">
            <td>
                <span style="margin-right:4px"><?= fileIcon($arq['mime_type']) ?></span>
                <a href="<?= $base ?>/api/download.php?id=<?= $arq['id'] ?>" target="_blank"><?= h($arq['filename']) ?></a>
            </td>
            <td><?= entityTypeLabel($arq['entity_type']) ?></td>
            <td><?= h($arq['entity_label'] ?? '—') ?></td>
            <td><?= formatBytes((int)$arq['tamanho']) ?></td>
            <td><?= date('d/m/Y H:i', strtotime($arq['created_at'])) ?></td>
            <td>
                <a href="<?= $base ?>/api/download.php?id=<?= $arq['id'] ?>" class="btn btn-sm" title="Download">Download</a>
                <button class="btn btn-sm btn-danger" onclick="deleteFile(<?= $arq['id'] ?>, this)" title="Excluir">Excluir</button>
            </td>
        </tr>
        <?php endforeach ?>
    <?php endif ?>
    </tbody>
</table>

<dialog id="upload-modal" style="border:1px solid #ccc; border-radius:8px; padding:2rem; max-width:500px; width:90%">
    <form id="upload-form" method="dialog">
        <h3 style="margin-top:0">Enviar Arquivo</h3>

        <label for="entity-type" style="display:block; margin-bottom:.25rem; font-weight:600">Tipo de Entidade</label>
        <select id="entity-type" name="entity_type" required style="width:100%; padding:.5rem; margin-bottom:1rem; border:1px solid #ccc; border-radius:4px">
            <option value="">Selecione...</option>
            <option value="imovel">Imóvel</option>
            <option value="inquilino">Inquilino</option>
            <option value="contrato">Contrato</option>
        </select>

        <label for="entity-id" style="display:block; margin-bottom:.25rem; font-weight:600">Entidade</label>
        <select id="entity-id" name="entity_id" required style="width:100%; padding:.5rem; margin-bottom:1rem; border:1px solid #ccc; border-radius:4px">
            <option value="">Selecione o tipo primeiro</option>
        </select>

        <label style="display:block; margin-bottom:.25rem; font-weight:600">Arquivo</label>
        <div id="drop-zone" style="border:2px dashed #ccc; border-radius:8px; padding:2rem; text-align:center; cursor:pointer; margin-bottom:1rem; transition: border-color .2s, background .2s">
            <p id="drop-text" style="margin:0; color:#888">Arraste um arquivo aqui ou clique para selecionar</p>
            <input type="file" id="file-input" style="display:none" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.bmp">
        </div>

        <progress id="upload-progress" value="0" max="100" style="width:100%; display:none; margin-bottom:1rem"></progress>
        <p id="upload-status" style="display:none; margin-bottom:1rem; font-size:.9rem"></p>

        <div style="display:flex; justify-content:flex-end; gap:.5rem">
            <button type="button" class="btn" onclick="document.getElementById('upload-modal').close()">Cancelar</button>
            <button type="submit" class="btn btn-primary" id="btn-enviar" disabled>Enviar</button>
        </div>
    </form>
</dialog>

<script>
const ENTITIES = {
    imovel:    <?= json_encode(array_values($imoveisList)) ?>,
    inquilino: <?= json_encode(array_values($inquilinosList)) ?>,
    contrato:  <?= json_encode(array_values($contratosList)) ?>
};
window.uploadPath = '<?= $base ?>/api/upload.php';
window.deletePath = '<?= $base ?>/api/delete-file.php';
</script>
<script src="<?= $base ?>/assets/arquivos.js"></script>

<?php layoutFoot(); ?>
