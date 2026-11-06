<?php
declare(strict_types=1);
/**
 * Importação em linha de comando.
 *   php tools/import.php --prova=prova.pdf [--prova=outra.pdf] --gabarito=gab.pdf [--gabarito=outro.pdf] [--dry]
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Importer.php';

$o = getopt('', ['prova:', 'gabarito:', 'dry', 'orgao:', 'edital:', 'cargo:', 'ano:']);
$provas = (array)($o['prova'] ?? []);
$gabs = (array)($o['gabarito'] ?? []);
if (!$provas) {
    fwrite(STDERR, "Uso: php tools/import.php --prova=prova.pdf --gabarito=gabarito.pdf [--dry]\n");
    exit(1);
}
$cads = Importer::analisa($provas, $gabs);
foreach ($cads as &$c) { // sobrescritas manuais (úteis quando o OCR não lê a capa)
    foreach (['orgao', 'edital', 'cargo'] as $k) {
        if (!empty($o[$k])) { $c['meta'][$k] = $o[$k]; }
    }
    if (!empty($o['ano'])) { $c['meta']['ano'] = (int)$o['ano']; }
}
unset($c);
foreach ($cads as $c) {
    $m = $c['meta'];
    $rev = count(array_filter($c['questoes'], fn($q) => $q['revisar']));
    printf("%-45s %3d questões | gabarito: %s | revisar: %d | faltando: %s\n",
        mb_substr(($m['codigo'] ? $m['codigo'] . ' ' : '') . $m['cargo'], 0, 45), count($c['questoes']),
        $c['gabarito_ok'] ? 'ok' : 'NÃO ENCONTRADO', $rev, $c['faltando'] ? implode(',', $c['faltando']) : '-');
    if (empty($c['meta']['cargo']) || empty($c['meta']['orgao'])) {
        echo "  ! órgão/cargo não detectados (" . json_encode($c['meta'], JSON_UNESCAPED_UNICODE) . ") — use --orgao= --cargo= --edital= --ano=\n";
    }
}
if (isset($o['dry'])) {
    exit(0);
}
[$p, $q] = Importer::grava(db(), $cads);
echo "Gravado: $p prova(s), $q questão(ões).\n";
