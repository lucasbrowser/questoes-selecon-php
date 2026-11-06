<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
exige_admin();
$db = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)$_POST['id'];
    $g = strtoupper(trim((string)($_POST['gabarito'] ?? '')));
    $anulada = isset($_POST['anulada']);
    $db->beginTransaction();
    $db->prepare('UPDATE questoes SET enunciado=?, texto_apoio=?, gabarito=?, anulada=?, comentario=?, revisar=? WHERE id=?')
       ->execute([trim((string)$_POST['enunciado']), trim((string)$_POST['texto_apoio']) ?: null,
                  preg_match('/^[A-E]$/', $g) && !$anulada ? $g : null, $anulada ? 1 : 0,
                  trim((string)$_POST['comentario']) ?: null, isset($_POST['revisar']) ? 1 : 0, $id]);
    foreach ((array)($_POST['alt'] ?? []) as $L => $txt) {
        if (preg_match('/^[A-E]$/', (string)$L)) {
            $db->prepare('INSERT INTO alternativas (questao_id, letra, texto) VALUES (?,?,?)
                          ON DUPLICATE KEY UPDATE texto = VALUES(texto)')->execute([$id, $L, trim((string)$txt)]);
        }
    }
    $db->commit();
    $msg = "Questão Q$id salva.";
}

$prova = (int)($_GET['prova'] ?? 0);
$base = "FROM questoes q JOIN provas p ON p.id = q.prova_id JOIN concursos c ON c.id = p.concurso_id
        WHERE (q.revisar = 1 OR (q.gabarito IS NULL AND q.anulada = 0))" . ($prova ? ' AND p.id = ' . $prova : '');
$qs = $db->query("SELECT q.*, p.cargo, c.orgao, c.ano $base ORDER BY q.prova_id, q.numero LIMIT 20")->fetchAll();
$total = (int)$db->query("SELECT COUNT(*) $base")->fetchColumn();
layout_inicio('Revisar questões', 'admin');
?>
<div class="cartao">
  <h1>Questões para revisar</h1>
  <p><?= $total ?> questão(ões) com importação incerta ou sem gabarito (mostrando até 20). Ao salvar, desmarque “revisar”.</p>
  <?php if ($msg): ?><div class="alerta ok"><?= h($msg) ?></div><?php endif; ?>
</div>
<?php foreach ($qs as $q):
    $alts = $db->prepare('SELECT letra, texto FROM alternativas WHERE questao_id = ? ORDER BY letra'); $alts->execute([$q['id']]); ?>
<form class="cartao form" method="post">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="id" value="<?= $q['id'] ?>">
  <h2>Q<?= $q['id'] ?> · <?= h($q['cargo']) ?> · nº <?= $q['numero'] ?> <small>(<?= h($q['orgao']) ?> <?= $q['ano'] ?>)</small></h2>
  <label>Texto de apoio <textarea name="texto_apoio" rows="3"><?= h($q['texto_apoio']) ?></textarea></label>
  <label>Enunciado <textarea name="enunciado" rows="4"><?= h($q['enunciado']) ?></textarea></label>
  <?php foreach ($alts as $a): ?>
    <label>Alternativa <?= h($a['letra']) ?> <textarea name="alt[<?= h($a['letra']) ?>]" rows="2"><?= h($a['texto']) ?></textarea></label>
  <?php endforeach; ?>
  <label>Gabarito (A–E) <input name="gabarito" maxlength="1" value="<?= h(trim((string)$q['gabarito'])) ?>" style="width:80px"></label>
  <label><input type="checkbox" name="anulada" style="width:auto" <?= $q['anulada'] ? 'checked' : '' ?>> Questão anulada</label>
  <label>Comentário / explicação <textarea name="comentario" rows="3"><?= h($q['comentario']) ?></textarea></label>
  <label><input type="checkbox" name="revisar" style="width:auto" <?= $q['revisar'] ? 'checked' : '' ?>> Ainda precisa de revisão</label>
  <button class="btn">Salvar</button>
</form>
<?php endforeach; ?>
<?php if (!$qs): ?><div class="cartao">Nada para revisar. 🎉</div><?php endif; ?>
<?php layout_fim();
