<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
$u = exige_login();
$db = db();

// considera a última resposta de cada questão
$base = "SELECT r.questao_id, r.correta, r.respondida_em, q.disciplina_id
         FROM respostas r
         JOIN (SELECT questao_id, MAX(id) AS mid FROM respostas WHERE usuario_id = :u GROUP BY questao_id) m ON m.mid = r.id
         JOIN questoes q ON q.id = r.questao_id";
$st = $db->prepare("SELECT COUNT(*) AS resolvidas, COALESCE(SUM(correta), 0) AS acertos FROM ($base) x");
$st->execute([':u' => $u['id']]);
$t = $st->fetch();
$st = $db->prepare("SELECT count(*) FROM respostas WHERE usuario_id = ?");
$st->execute([$u['id']]);
$tentativas = (int)$st->fetchColumn();

$st = $db->prepare("SELECT COALESCE(d.nome,'Sem disciplina') AS nome, COUNT(*) AS n, COALESCE(SUM(x.correta), 0) AS ok
                    FROM ($base) x LEFT JOIN disciplinas d ON d.id = x.disciplina_id GROUP BY COALESCE(d.nome,'Sem disciplina') ORDER BY n DESC");
$st->execute([':u' => $u['id']]);
$porDisc = $st->fetchAll();

$st = $db->prepare("SELECT DATE(respondida_em) AS dia, COUNT(*) AS n, COALESCE(SUM(correta), 0) AS ok FROM respostas
                    WHERE usuario_id = ? AND respondida_em > NOW() - INTERVAL 14 DAY GROUP BY DATE(respondida_em) ORDER BY DATE(respondida_em) DESC");
$st->execute([$u['id']]);
$dias = $st->fetchAll();

$pct = fn($ok, $n) => $n ? round(100 * $ok / $n) : 0;
layout_inicio('Meu desempenho', 'desempenho');
?>
<h1>Meu desempenho</h1>
<div class="cards3">
  <div class="num"><b><?= (int)$t['resolvidas'] ?></b><span>questões resolvidas</span></div>
  <div class="num"><b><?= (int)$t['acertos'] ?></b><span>acertos</span></div>
  <div class="num"><b><?= $pct($t['acertos'], $t['resolvidas']) ?>%</b><span>aproveitamento</span></div>
  <div class="num"><b><?= $tentativas ?></b><span>respostas enviadas</span></div>
</div>
<div class="cartao">
  <h2>Por disciplina</h2>
  <?php if (!$porDisc): ?><p>Você ainda não respondeu questões. <a href="/">Comece agora</a>.</p><?php else: ?>
  <table class="tab"><tr><th>Disciplina</th><th>Resolvidas</th><th>Acertos</th><th>Aproveitamento</th></tr>
  <?php foreach ($porDisc as $d): $p = $pct($d['ok'], $d['n']); ?>
    <tr><td><?= h($d['nome']) ?></td><td><?= $d['n'] ?></td><td><?= $d['ok'] ?></td>
        <td><div class="barra"><i style="width:<?= $p ?>%"></i></div> <?= $p ?>%</td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>
<div class="cartao">
  <h2>Últimos 14 dias</h2>
  <?php if (!$dias): ?><p>Sem atividade recente.</p><?php else: ?>
  <table class="tab"><tr><th>Dia</th><th>Respostas</th><th>Acertos</th></tr>
  <?php foreach ($dias as $d): ?>
    <tr><td><?= date('d/m/Y', strtotime($d['dia'])) ?></td><td><?= $d['n'] ?></td><td><?= $d['ok'] ?></td></tr>
  <?php endforeach; ?></table><?php endif; ?>
</div>
<?php layout_fim();
