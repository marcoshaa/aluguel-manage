<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gemini.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['resposta' => 'Não autenticado.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido']);
    exit;
}

$pergunta = trim($_POST['pergunta'] ?? '');
if (!$pergunta) {
    echo json_encode(['resposta' => 'Por favor, faça uma pergunta.']);
    exit;
}

try {
    $resposta = geminiChat($pergunta);

    if (preg_match('/\[EXPORTAR:(\w+):([^\]]*)\]/', $resposta, $m)) {
        $tipo   = $m[1];
        $filtro = trim($m[2]);
        $url    = 'api/export.php?tipo=' . urlencode($tipo);
        if (preg_match('/^\d{4}-\d{2}$/', $filtro)) {
            $url .= '&mes=' . urlencode($filtro);
        } elseif ($filtro) {
            $url .= '&filtro=' . urlencode($filtro);
        }
        $labels = ['pagamentos'=>'Pagamentos','imoveis'=>'Imóveis','inquilinos'=>'Inquilinos','contratos'=>'Contratos'];
        $label  = ($labels[$tipo] ?? $tipo) . ($filtro ? " ({$filtro})" : '');
        echo json_encode([
            'resposta' => 'Planilha pronta!',
            'download' => ['url' => $url, 'label' => "Baixar Excel — {$label}"]
        ]);
        exit;
    }

    echo json_encode(['resposta' => $resposta]);
} catch (Throwable $e) {
    echo json_encode(['resposta' => 'Erro interno: ' . $e->getMessage()]);
}
