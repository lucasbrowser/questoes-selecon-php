<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Importer.php';
exige_admin();
set_time_limit(600);
ini_set('memory_limit', '1024M');

$erro = '';
$ok = '';
$prev = null;
$token = '';

function salvaUploads(string $campo, string $dir): array
{
    $out = [];
    if (!isset($_FILES[$campo])) {
        return $out;
    }
    foreach ((array)$_FILES[$campo]['name'] as $i => $nome) {
        if ($_FILES[$campo]['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($_FILES[$campo]['error'][$i] !== UPLOAD_ERR_OK) {
            throw new RuntimeException("Falha no upload de $nome (código {$_FILES[$campo]['error'][$i]}). Verifique upload_max_filesize/post_max_size.");
        }
        if (strtolower(pathinfo($nome, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new RuntimeException("$nome não é PDF.");
        }
        $dest = $dir . '/' . $campo . '_' . $i . '.pdf';
        move_uploaded_file($_FILES[$campo]['tmp_name'][$i], $dest);
        $out[] = $dest;
    }
    return $out;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (($_POST['passo'] ?? '') === 'analisar') {
            $token = bin2hex(random_bytes(8));
            $dir = TMP_DIR . '/' . $token;
            mkdir($dir, 0775, true);
            $provas = salvaUploads('provas', $dir);
            $gabs = salvaUploads('gabaritos', $dir);
            if (!$provas) {
                throw new RuntimeException('Envie ao menos um PDF de prova.');
            }
            $prev = Importer::analisa($provas, $gabs);
            file_put_contents(TMP_DIR . "/$token.json", json_encode($prev, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            foreach (array_merge($provas, $gabs) as $f) { @unlink($f); }
            @rmdir($dir);
        } elseif (($_POST['passo'] ?? '') === 'gravar') {
            $token = preg_replace('/[^a-f0-9]/', '', (string)$_POST['token']);
            $cads = json_decode((string)@file_get_contents(TMP_DIR . "/$token.json"), true);
            if (!$cads) {
                throw new RuntimeException('Análise expirada. Envie os arquivos novamente.');
            }
            $sel = [];
            foreach ($cads as $i => $c) {
                if (empty($_POST['usar'][$i])) {
                    continue;
                }
                foreach (['orgao', 'edital', 'cargo', 'codigo', 'nivel', 'versao'] as $k) {
                    $c['meta'][$k] = trim((string)($_POST['meta'][$i][$k] ?? ''));
                }
                $c['meta']['ano'] = (int)($_POST['meta'][$i]['ano'] ?? 0) ?: null;
                // faixas de disciplina: "1-4:Língua Portuguesa; 5-8:Raciocínio Lógico"
                $faixas = trim((string)($_POST['faixas'][$i] ?? ''));
                if ($faixas !== '') {
                    foreach (preg_split('/\s*;\s*/', $faixas) as $fx) {
                        if (preg_match('/^(\d+)\s*-\s*(\d+)\s*:\s*(.+)$/u', $fx, $m)) {
                            foreach ($c['questoes'] as &$q) {
                                if ($q['numero'] >= (int)$m[1] && $q['numero'] <= (int)$m[2]) { $q['disciplina'] = trim($m[3]); }
                            }
                            unset($q);
                        }
                    }
                }
                $sel[] = $c;
            }
            [$np, $nq] = Importer::grava(db(), $sel);
            @unlink(TMP_DIR . "/$token.json");
            $ok = "Importação concluída: $np prova(s), $nq questão(ões).";
            $prev = null;
        }
    }
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

layout_inicio('Importar PDFs', 'admin');
?>
<div class="cartao">
  <h1>Importar provas e gabaritos</h1>
  <?php if ($erro): ?><div class="alerta erro"><?= h($erro) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alerta ok"><?= h($ok) ?> <a href="/">Ver questões</a> · <a href="/admin/revisar.php">Revisar</a></div><?php endif; ?>

  <form class="form" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="passo" value="analisar">
    <label>PDF(s) de prova <input type="file" name="provas[]" accept="application/pdf" multiple required></label>
    <label>PDF(s) de gabarito <input type="file" name="gabaritos[]" accept="application/pdf" multiple></label>
    <p><small>PDFs com vários cargos (ex.: caderno único com todos os cargos) são separados automaticamente. O gabarito é associado pelo código
    do cargo (ex.: “401 – ADVOGADO”) ou pelo nome. PDFs escaneados (sem texto) precisam de OCR antes.</small></p>
    <button class="btn">Analisar PDFs</button>
  </form>
</div>

<?php if ($prev !== null): ?>
<form method="post" class="form">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="passo" value="gravar">
  <input type="hidden" name="token" value="<?= h($token) ?>">
  <div class="cartao">
    <h2>Pré-visualização (<?= count($prev) ?> caderno(s))</h2>
    <p>Confira os dados detectados. Desmarque o que não deseja importar. Use “faixas” para corrigir disciplinas, ex.:
       <code>1-10:Língua Portuguesa; 11-20:Direito Administrativo</code>.</p>
    <?php foreach ($prev as $i => $c): $m = $c['meta'];
        $rev = count(array_filter($c['questoes'], fn($q) => $q['revisar'])); ?>
      <fieldset class="cartao" style="padding:12px 16px">
        <label style="display:inline"><input type="checkbox" name="usar[<?= $i ?>]" value="1" checked style="width:auto">
          <b><?= h(($m['codigo'] ? $m['codigo'] . ' – ' : '') . $m['cargo']) ?></b></label>
        — <?= count($c['questoes']) ?> questões ·
        gabarito: <b><?= $c['gabarito_ok'] ? count($c['gabarito']) . ' respostas' : 'NÃO encontrado' ?></b> ·
        a revisar: <?= $rev ?>
        <?php if ($c['faltando']): ?> · <span style="color:var(--erro)">não detectadas: <?= h(implode(', ', $c['faltando'])) ?></span><?php endif; ?>
        <details><summary>Editar dados</summary>
          <label>Órgão <input name="meta[<?= $i ?>][orgao]" value="<?= h($m['orgao']) ?>"></label>
          <label>Edital <input name="meta[<?= $i ?>][edital]" value="<?= h($m['edital']) ?>"></label>
          <label>Cargo <input name="meta[<?= $i ?>][cargo]" value="<?= h($m['cargo']) ?>"></label>
          <label>Código <input name="meta[<?= $i ?>][codigo]" value="<?= h($m['codigo']) ?>"></label>
          <label>Nível <input name="meta[<?= $i ?>][nivel]" value="<?= h($m['nivel']) ?>"></label>
          <label>Versão da prova (A, B…) <input name="meta[<?= $i ?>][versao]" value="<?= h($m['versao']) ?>"></label>
          <label>Ano <input type="number" name="meta[<?= $i ?>][ano]" value="<?= h((string)$m['ano']) ?>"></label>
          <label>Faixas de disciplina <input name="faixas[<?= $i ?>]" placeholder="1-10:Língua Portuguesa; 11-20:Informática"></label>
        </details>
        <details><summary>Amostra: questão <?= $c['questoes'][0]['numero'] ?? '' ?></summary>
          <p><?= ht($c['questoes'][0]['enunciado'] ?? '') ?></p>
          <ul><?php foreach ($c['questoes'][0]['alternativas'] ?? [] as $L => $t): ?><li><b><?= h($L) ?>)</b> <?= h($t) ?></li><?php endforeach; ?></ul>
        </details>
      </fieldset>
    <?php endforeach; ?>
    <button class="btn">Gravar no banco</button>
  </div>
</form>
<?php endif; ?>
<?php layout_fim();
