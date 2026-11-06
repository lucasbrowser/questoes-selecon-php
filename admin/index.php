<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
exige_admin();
$db = db();
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_prova') {
    csrf_check();
    $db->prepare('DELETE FROM provas WHERE id = ?')->execute([(int)$_POST['id']]);
    $db->exec('DELETE FROM concursos WHERE NOT EXISTS (SELECT 1 FROM provas p WHERE p.concurso_id = concursos.id)');
    $msg = 'Prova excluída.';
}
$provas = $db->query("SELECT p.id, p.cargo, p.codigo, p.versao, c.orgao, c.edital, c.ano,
        COUNT(q.id) AS n, COALESCE(SUM(q.revisar), 0) AS rev, COALESCE(SUM(q.gabarito IS NULL AND q.anulada = 0), 0) AS semgab
    FROM provas p JOIN concursos c ON c.id = p.concurso_id LEFT JOIN questoes q ON q.prova_id = p.id
    GROUP BY p.id, p.cargo, p.codigo, p.versao, c.orgao, c.edital, c.ano
    ORDER BY (c.ano IS NULL), c.ano DESC, c.orgao, p.cargo")->fetchAll();
layout_inicio('Administração', 'admin');
?>
<div class="cartao">
  <h1>Administração</h1>
  <?php if ($msg): ?><div class="alerta ok"><?= h($msg) ?></div><?php endif; ?>
  <p><a class="btn" href="/admin/importar.php">Importar PDFs de prova e gabarito</a>
     <a class="btn btn-ghost" href="/admin/revisar.php">Questões para revisar</a></p>
</div>
<div class="cartao">
  <h2>Provas cadastradas (<?= count($provas) ?>)</h2>
  <table class="tab">
    <tr><th>Órgão / Edital</th><th>Cargo</th><th>Ano</th><th>Questões</th><th>A revisar</th><th>Sem gabarito</th><th></th></tr>
    <?php foreach ($provas as $p): ?>
    <tr>
      <td><?= h($p['orgao']) ?><br><small><?= h($p['edital']) ?></small></td>
      <td><?= h(trim(($p['codigo'] ? $p['codigo'] . ' – ' : '') . $p['cargo'] . ($p['versao'] ? ' (Prova ' . $p['versao'] . ')' : ''))) ?></td>
      <td><?= $p['ano'] ?></td><td><?= $p['n'] ?></td>
      <td><a href="/admin/revisar.php?prova=<?= $p['id'] ?>"><?= $p['rev'] ?></a></td><td><?= $p['semgab'] ?></td>
      <td><form method="post" onsubmit="return confirm('Excluir esta prova e suas questões?')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="excluir_prova">
        <input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-sm btn-perigo">Excluir</button></form></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php layout_fim();
