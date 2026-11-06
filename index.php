<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$db = db();
$u = usuario();
$por = 10;

// ---------- filtros ----------
$f = [
    'disciplina' => (int)($_GET['disciplina'] ?? 0),
    'concurso'   => (int)($_GET['concurso'] ?? 0),
    'cargo'      => trim((string)($_GET['cargo'] ?? '')),
    'ano'        => (int)($_GET['ano'] ?? 0),
    'q'          => trim((string)($_GET['q'] ?? '')),
    'sit'        => (string)($_GET['sit'] ?? ''),
];
$pag = max(1, (int)($_GET['pagina'] ?? 1));

$where = ['1=1'];
$par = [];
if ($f['disciplina']) { $where[] = 'q.disciplina_id = :disc'; $par[':disc'] = $f['disciplina']; }
if ($f['concurso'])   { $where[] = 'c.id = :conc';            $par[':conc'] = $f['concurso']; }
if ($f['cargo'] !== '') { $where[] = 'p.cargo = :cargo';      $par[':cargo'] = $f['cargo']; }
if ($f['ano'])        { $where[] = 'c.ano = :ano';            $par[':ano'] = $f['ano']; }
if ($f['q'] !== '')   { $where[] = "q.enunciado LIKE :qlike"; // collation utf8mb4_unicode_ci ignora maiúsculas e acentos
                        $par[':qlike'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%'; }
if ($u && in_array($f['sit'], ['nao', 'resolvidas', 'acertei', 'errei'], true)) {
    $ult = "(SELECT r.correta FROM respostas r WHERE r.questao_id = q.id AND r.usuario_id = :uid ORDER BY r.respondida_em DESC LIMIT 1)";
    $par[':uid'] = $u['id'];
    $where[] = match ($f['sit']) {
        'nao' => "NOT EXISTS (SELECT 1 FROM respostas r WHERE r.questao_id = q.id AND r.usuario_id = :uid)",
        'resolvidas' => "EXISTS (SELECT 1 FROM respostas r WHERE r.questao_id = q.id AND r.usuario_id = :uid)",
        'acertei' => "$ult = 1",
        'errei' => "$ult = 0",
    };
}
$W = implode(' AND ', $where);
$from = "FROM questoes q JOIN provas p ON p.id = q.prova_id JOIN concursos c ON c.id = p.concurso_id
         LEFT JOIN disciplinas d ON d.id = q.disciplina_id WHERE $W";

$st = $db->prepare("SELECT count(*) $from");
$st->execute($par);
$total = (int)$st->fetchColumn();
$paginas = max(1, (int)ceil($total / $por));
$pag = min($pag, $paginas);

$st = $db->prepare("SELECT q.*, d.nome AS disciplina, p.cargo, p.versao, c.orgao, c.ano, c.banca, c.edital $from
                    ORDER BY (c.ano IS NULL), c.ano DESC, c.id, p.cargo, q.numero LIMIT $por OFFSET " . (($pag - 1) * $por));
$st->execute($par);
$questoes = $st->fetchAll();

$alts = [];
$minhas = [];
if ($questoes) {
    $ids = array_column($questoes, 'id');
    $in = implode(',', array_map('intval', $ids));
    foreach ($db->query("SELECT questao_id, letra, texto FROM alternativas WHERE questao_id IN ($in) ORDER BY letra") as $a) {
        $alts[$a['questao_id']][] = $a;
    }
    if ($u) {
        $st2 = $db->prepare("SELECT r.questao_id, r.letra, r.correta FROM respostas r
                             JOIN (SELECT questao_id, MAX(id) AS mid FROM respostas WHERE usuario_id = ? AND questao_id IN ($in) GROUP BY questao_id) m
                               ON m.mid = r.id");
        $st2->execute([$u['id']]);
        foreach ($st2 as $r) { $minhas[$r['questao_id']] = $r; }
    }
}

// listas dos filtros
$optDisc = $db->query("SELECT d.id, d.nome, count(*) n FROM disciplinas d JOIN questoes q ON q.disciplina_id = d.id GROUP BY d.id, d.nome ORDER BY d.nome")->fetchAll();
$optConc = $db->query("SELECT id, orgao, edital, ano FROM concursos ORDER BY (ano IS NULL), ano DESC, orgao")->fetchAll();
$optCargo = $db->query("SELECT DISTINCT cargo FROM provas ORDER BY cargo")->fetchAll(PDO::FETCH_COLUMN);
$optAno = $db->query("SELECT DISTINCT ano FROM concursos WHERE ano IS NOT NULL ORDER BY ano DESC")->fetchAll(PDO::FETCH_COLUMN);

function url_pag(array $f, int $p): string
{
    $q = array_filter($f, fn($v) => $v !== '' && $v !== 0);
    $q['pagina'] = $p;
    return '/?' . http_build_query($q);
}

layout_inicio('Questões de concursos', 'questoes');
?>
<div class="grade">
  <aside class="filtros">
    <form method="get" action="/">
      <h2>Filtros</h2>
      <label>Palavra-chave
        <input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Buscar no enunciado">
      </label>
      <label>Disciplina
        <select name="disciplina">
          <option value="0">Todas</option>
          <?php foreach ($optDisc as $o): ?>
            <option value="<?= $o['id'] ?>" <?= $f['disciplina'] === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['nome']) ?> (<?= $o['n'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Concurso / Órgão
        <select name="concurso">
          <option value="0">Todos</option>
          <?php foreach ($optConc as $o): ?>
            <option value="<?= $o['id'] ?>" <?= $f['concurso'] === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['orgao']) ?> <?= $o['ano'] ? '(' . $o['ano'] . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Cargo
        <select name="cargo">
          <option value="">Todos</option>
          <?php foreach ($optCargo as $o): ?>
            <option <?= $f['cargo'] === $o ? 'selected' : '' ?>><?= h($o) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Ano
        <select name="ano">
          <option value="0">Todos</option>
          <?php foreach ($optAno as $o): ?>
            <option <?= $f['ano'] === (int)$o ? 'selected' : '' ?>><?= (int)$o ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($u): ?>
      <fieldset>
        <legend>Situação</legend>
        <?php foreach (['' => 'Todas', 'nao' => 'Não resolvidas', 'resolvidas' => 'Resolvidas', 'acertei' => 'Que acertei', 'errei' => 'Que errei'] as $k => $v): ?>
          <label class="radio"><input type="radio" name="sit" value="<?= $k ?>" <?= $f['sit'] === $k ? 'checked' : '' ?>> <?= $v ?></label>
        <?php endforeach; ?>
      </fieldset>
      <?php endif; ?>
      <button class="btn btn-block" type="submit">Filtrar questões</button>
      <a class="limpar" href="/">Limpar filtros</a>
    </form>
  </aside>

  <section class="lista">
    <div class="resumo">
      <span><strong><?= number_format($total, 0, ',', '.') ?></strong> questões encontradas</span>
      <span class="banca-tag">Banca: Selecon</span>
    </div>
    <?php if (!$questoes): ?>
      <div class="vazio">Nenhuma questão encontrada. <?php if (!$total && !array_filter($f)): ?>Importe provas em <a href="/admin/">Administração</a>.<?php endif; ?></div>
    <?php endif; ?>

    <?php foreach ($questoes as $q):
        $mine = $minhas[$q['id']] ?? null; ?>
    <article class="questao" data-id="<?= $q['id'] ?>">
      <header class="q-topo">
        <span class="q-id">Q<?= $q['id'] ?></span>
        <?php if ($q['disciplina']): ?><a class="chip chip-disc" href="/?disciplina=<?= (int)$q['disciplina_id'] ?>"><?= h($q['disciplina']) ?></a><?php endif; ?>
        <span class="q-meta">
          Ano: <b><?= $q['ano'] ?: '—' ?></b> ·
          Banca: <b><?= h($q['banca']) ?></b> ·
          Órgão: <b><?= h($q['orgao']) ?></b> ·
          Cargo: <b><?= h($q['cargo']) ?></b> ·
          Questão nº <b><?= $q['numero'] ?></b>
        </span>
        <?php if ($q['revisar'] && $u && $u['is_admin']): ?><span class="chip chip-aviso">revisar</span><?php endif; ?>
        <?php if ($q['anulada']): ?><span class="chip chip-anulada">Anulada</span><?php endif; ?>
      </header>

      <?php if ($q['texto_apoio']): ?>
        <details class="apoio" open>
          <summary>Texto de apoio</summary>
          <div><?= ht($q['texto_apoio']) ?></div>
        </details>
      <?php endif; ?>

      <div class="enunciado"><?= ht($q['enunciado']) ?></div>

      <form class="alts" onsubmit="return false">
        <?php foreach ($alts[$q['id']] ?? [] as $a): ?>
          <label class="alt" data-letra="<?= h($a['letra']) ?>">
            <input type="radio" name="alt<?= $q['id'] ?>" value="<?= h($a['letra']) ?>" <?= ($mine && $mine['letra'] === $a['letra']) ? 'checked' : '' ?>>
            <span class="bola"><?= h($a['letra']) ?></span>
            <span class="txt"><?= ht($a['texto']) ?></span>
            <button type="button" class="tesoura" title="Riscar alternativa" aria-label="Riscar alternativa">✂</button>
          </label>
        <?php endforeach; ?>
      </form>

      <footer class="q-rodape">
        <button class="btn btn-resolver" type="button" <?= $q['anulada'] ? 'disabled' : '' ?>>Resolver questão</button>
        <button class="btn btn-ghost btn-gab" type="button">Ver gabarito</button>
        <span class="resultado" aria-live="polite"></span>
      </footer>
      <div class="comentario" hidden></div>
    </article>
    <?php endforeach; ?>

    <?php if ($paginas > 1): ?>
    <nav class="paginacao" aria-label="Paginação">
      <?php if ($pag > 1): ?><a href="<?= h(url_pag($f, $pag - 1)) ?>">‹ Anterior</a><?php endif; ?>
      <?php for ($i = max(1, $pag - 3); $i <= min($paginas, $pag + 3); $i++): ?>
        <a class="<?= $i === $pag ? 'atual' : '' ?>" href="<?= h(url_pag($f, $i)) ?>"><?= $i ?></a>
      <?php endfor; ?>
      <?php if ($pag < $paginas): ?><a href="<?= h(url_pag($f, $pag + 1)) ?>">Próxima ›</a><?php endif; ?>
    </nav>
    <?php endif; ?>
  </section>
</div>
<script>window.CSRF = <?= json_encode(csrf_token()) ?>; window.LOGADO = <?= $u ? 'true' : 'false' ?>;</script>
<script src="/assets/app.js"></script>
<?php layout_fim();
