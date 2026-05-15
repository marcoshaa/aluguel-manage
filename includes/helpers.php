<?php
function moeda(float $valor): string {
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function dataBR(?string $data): string {
    if (!$data) return '—';
    $d = DateTime::createFromFormat('Y-m-d', $data);
    return $d ? $d->format('d/m/Y') : $data;
}

function mesBR(string $mes): string {
    // mes no formato YYYY-MM
    [$ano, $m] = explode('-', $mes);
    $nomes = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
    return ($nomes[(int)$m] ?? $m) . '/' . $ano;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function tipoLabel(string $tipo): string {
    $map = ['casa' => 'Casa', 'apto' => 'Apartamento', 'sala' => 'Sala Comercial'];
    return $map[$tipo] ?? $tipo;
}

function statusImovelBadge(string $status): string {
    $class = $status === 'alugado' ? 'badge-alugado' : 'badge-disponivel';
    $label = $status === 'alugado' ? 'Alugado' : 'Disponível';
    return "<span class=\"badge {$class}\">{$label}</span>";
}

function statusPagBadge(string $status): string {
    $map = ['pago' => 'badge-pago', 'pendente' => 'badge-pendente', 'atrasado' => 'badge-atrasado'];
    $labels = ['pago' => 'Pago', 'pendente' => 'Pendente', 'atrasado' => 'Atrasado'];
    $class = $map[$status] ?? 'badge-pendente';
    $label = $labels[$status] ?? $status;
    return "<span class=\"badge {$class}\">{$label}</span>";
}

function calcularStatusPagamento(string $mesReferencia, int $diaVencimento): string {
    $hoje = new DateTime();
    [$ano, $mes] = explode('-', $mesReferencia);
    $vencimento = new DateTime("{$ano}-{$mes}-" . str_pad($diaVencimento, 2, '0', STR_PAD_LEFT));
    $diff = $hoje->diff($vencimento);
    if ($hoje <= $vencimento) return 'pendente';
    return $diff->days > 5 ? 'atrasado' : 'pendente';
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function flash(string $msg, string $tipo = 'success'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'tipo' => $tipo];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}
