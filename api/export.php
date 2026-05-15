<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/visibilidade.php';

if (empty($_SESSION['user_id'])) { http_response_code(401); exit; }

$userId   = (int)$_SESSION['user_id'];
$tipo     = $_GET['tipo']   ?? '';
$filtro   = $_GET['filtro'] ?? '';
$mes      = $_GET['mes']    ?? '';

$db       = getDB();
$visiveis = imoveisVisiveis($userId);
$inClause = $visiveis ? implode(',', $visiveis) : '0';

$tipos_validos = ['pagamentos','imoveis','inquilinos','contratos'];
if (!in_array($tipo, $tipos_validos, true)) { http_response_code(400); echo 'Tipo inválido'; exit; }

$rows    = [];
$filename = 'export.xls';

switch ($tipo) {
    case 'pagamentos':
        $where = "c.imovel_id IN ({$inClause})";
        if ($filtro) $where .= " AND p.status = " . $db->quote($filtro);
        if ($mes)    $where .= " AND p.mes_referencia = " . $db->quote($mes);
        $rows = $db->query("
            SELECT q.nome AS Inquilino, i.endereco AS Imovel,
                   p.mes_referencia AS Mes, p.status AS Status,
                   p.valor_pago AS Valor_Pago, p.data_pagamento AS Data_Pagamento
            FROM pagamentos p
            JOIN contratos c ON c.id = p.contrato_id
            JOIN inquilinos q ON q.id = c.inquilino_id
            JOIN imoveis i ON i.id = c.imovel_id
            WHERE {$where}
            ORDER BY p.mes_referencia DESC, q.nome
        ")->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'pagamentos' . ($filtro ? "-{$filtro}" : '') . ($mes ? "-{$mes}" : '') . '.xls';
        break;

    case 'imoveis':
        $rows = $db->query("
            SELECT endereco AS Endereco, tipo AS Tipo,
                   valor_aluguel AS Valor_Aluguel, status AS Status
            FROM imoveis WHERE id IN ({$inClause}) ORDER BY endereco
        ")->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'imoveis.xls';
        break;

    case 'inquilinos':
        $rows = $db->query("
            SELECT nome AS Nome, cpf AS CPF, telefone AS Telefone, email AS Email
            FROM inquilinos ORDER BY nome
        ")->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'inquilinos.xls';
        break;

    case 'contratos':
        $rows = $db->query("
            SELECT i.endereco AS Imovel, q.nome AS Inquilino,
                   c.data_inicio AS Inicio, c.data_fim AS Fim,
                   c.valor_mensal AS Valor_Mensal, c.dia_vencimento AS Dia_Venc,
                   CASE c.ativo WHEN 1 THEN 'Ativo' ELSE 'Encerrado' END AS Status
            FROM contratos c
            JOIN imoveis i ON i.id = c.imovel_id
            JOIN inquilinos q ON q.id = c.inquilino_id
            WHERE c.imovel_id IN ({$inClause})
            ORDER BY c.ativo DESC, i.endereco
        ")->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'contratos.xls';
        break;
}

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store');

if (empty($rows)) { echo '<table><tr><td>Nenhum dado encontrado.</td></tr></table>'; exit; }

echo '<table border="1">';
echo '<tr>';
foreach (array_keys($rows[0]) as $col) {
    echo '<th>' . htmlspecialchars(str_replace('_', ' ', $col)) . '</th>';
}
echo '</tr>';
foreach ($rows as $row) {
    echo '<tr>';
    foreach ($row as $val) echo '<td>' . htmlspecialchars((string)($val ?? '')) . '</td>';
    echo '</tr>';
}
echo '</table>';
