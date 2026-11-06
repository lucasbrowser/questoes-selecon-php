<?php
// Página de diagnóstico: confira PHP, extensões, conexão e tabelas. APAGUE este arquivo depois de usar.
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');
$ok = fn($b) => $b ? '✅' : '❌';
echo '<h1>Diagnóstico</h1><pre>';
echo $ok(version_compare(PHP_VERSION, '8.0.0', '>=')) . ' PHP ' . PHP_VERSION . " (precisa de 8.0 ou superior — troque em cPanel → Select PHP Version)\n";
foreach (['pdo', 'pdo_mysql', 'mbstring', 'dom'] as $e) echo $ok(extension_loaded($e)) . " extensão $e\n";
$src = __DIR__ . '/src/bootstrap.php';
echo $ok(is_file($src)) . ' src/bootstrap.php encontrado em ' . realpath(dirname($src)) . "\n";
if (!is_file($src)) { echo "A pasta src/ precisa ficar ao lado da pasta public/ (um nível acima do diretório do site).\n</pre>"; exit; }
echo $ok(is_file(__DIR__ . '/../config.php')) . " config.php (ao lado de src/)\n";
try {
    require $src;
    $db = db();
    echo "✅ conexão com o MySQL ok (" . DB_HOST . '/' . DB_NAME . ', usuário ' . DB_USER . ")\n";
    foreach (['usuarios', 'disciplinas', 'concursos', 'provas', 'questoes', 'alternativas', 'respostas'] as $t) {
        try { $n = $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); echo "✅ tabela $t: $n registro(s)\n"; }
        catch (Throwable $e) { echo "❌ tabela $t: " . $e->getMessage() . "\n"; }
    }
} catch (Throwable $e) { echo '❌ ' . htmlspecialchars($e->getMessage()) . "\n"; }
echo $ok(is_writable(dirname(__DIR__) . '/storage/tmp')) . " storage/tmp gravável (necessário para importar PDFs)\n";
echo (function_exists('shell_exec') && trim((string)@shell_exec('command -v pdftotext')) !== '' ? '✅' : '⚠️') . " pdftotext disponível (sem ele, importe os PDFs no seu computador e envie o dump SQL)\n";
echo '</pre><p><b>Apague este arquivo (diagnostico.php) quando terminar.</b></p>';
