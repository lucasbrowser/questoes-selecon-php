<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['erro' => 'método']));
}
csrf_check();

$qid = (int)($_POST['questao_id'] ?? 0);
$letra = strtoupper(substr((string)($_POST['letra'] ?? ''), 0, 1));
$apenasGab = ($_POST['modo'] ?? '') === 'gabarito';

$db = db();
$st = $db->prepare('SELECT id, gabarito, anulada, comentario FROM questoes WHERE id = ?');
$st->execute([$qid]);
$q = $st->fetch();
if (!$q) {
    http_response_code(404);
    exit(json_encode(['erro' => 'questão não encontrada']));
}

$out = ['gabarito' => $q['gabarito'], 'anulada' => (bool)$q['anulada'], 'comentario' => $q['comentario']];

if (!$apenasGab) {
    if (!preg_match('/^[A-E]$/', $letra)) {
        http_response_code(422);
        exit(json_encode(['erro' => 'alternativa inválida']));
    }
    $out['correta'] = $q['gabarito'] ? ($q['gabarito'] === $letra) : null;
    $u = usuario();
    if ($u && $out['correta'] !== null) {
        $db->prepare('INSERT INTO respostas (usuario_id, questao_id, letra, correta) VALUES (?,?,?,?)')
           ->execute([$u['id'], $qid, $letra, $out['correta'] ? 1 : 0]);
    }
    $out['salvo'] = (bool)$u;
}

$st = $db->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(correta), 0) AS acertos FROM respostas WHERE questao_id = ?');
$st->execute([$qid]);
$s = $st->fetch();
$out['pct_acerto'] = (int)$s['total'] > 0 ? (int)round(100 * $s['acertos'] / $s['total']) : null;
$out['total_resp'] = (int)$s['total'];

echo json_encode($out, JSON_UNESCAPED_UNICODE);
