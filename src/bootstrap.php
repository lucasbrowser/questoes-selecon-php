<?php
declare(strict_types=1);

// ---------- Configuração: config.php (hospedagem compartilhada) ou variáveis de ambiente ----------
define('ROOT', dirname(__DIR__));
if (is_file(ROOT . '/config.php')) {
    require ROOT . '/config.php';
}
defined('DB_HOST') || define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
defined('DB_PORT') || define('DB_PORT', getenv('DB_PORT') ?: '3306');
defined('DB_NAME') || define('DB_NAME', getenv('DB_NAME') ?: 'bancoselecon');
defined('DB_USER') || define('DB_USER', getenv('DB_USER') ?: 'root');
defined('DB_PASS') || define('DB_PASS', getenv('DB_PASS') ?: '');
defined('PDFTOTEXT') || define('PDFTOTEXT', getenv('PDFTOTEXT') ?: 'pdftotext');
defined('DEBUG') || define('DEBUG', false);   // true mostra detalhes do erro na tela
define('TMP_DIR', ROOT . '/storage/tmp');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true, // permite repetir :parametro na mesma consulta
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Banco de questões: ' . $e->getMessage());
            exit('<h1>Falha ao conectar ao banco de dados</h1><p>Confira host, nome, usuário e senha em <code>config.php</code> '
                . '(veja <code>/diagnostico.php</code>).</p>' . (DEBUG ? '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>' : ''));
        }
    }
    return $pdo;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Texto com quebras de linha preservadas. */
function ht(?string $s): string
{
    return nl2br(h($s));
}

// ---------- CSRF ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$t)) {
        http_response_code(400);
        exit('Token CSRF inválido. Recarregue a página.');
    }
}

// ---------- Autenticação ----------
function usuario(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT id, nome, email, is_admin FROM usuarios WHERE id = ?');
            $st->execute([$_SESSION['uid']]);
            $u = $st->fetch() ?: null;
        }
    }
    return $u;
}

function exige_login(): array
{
    $u = usuario();
    if (!$u) {
        header('Location: /login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    return $u;
}

function exige_admin(): array
{
    $u = exige_login();
    if (!$u['is_admin']) {
        http_response_code(403);
        exit('Acesso restrito a administradores.');
    }
    return $u;
}

// ---------- Layout ----------
function layout_inicio(string $titulo, string $ativo = ''): void
{
    $u = usuario();
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titulo) ?> · Questões Selecon</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topo">
  <div class="wrap topo-in">
    <a class="logo" href="/"><span class="logo-mark">Q</span> Questões <b>Selecon</b></a>
    <nav class="menu">
      <a href="/" class="<?= $ativo === 'questoes' ? 'on' : '' ?>">Questões</a>
      <a href="/desempenho.php" class="<?= $ativo === 'desempenho' ? 'on' : '' ?>">Meu desempenho</a>
      <?php if ($u && $u['is_admin']): ?>
        <a href="/admin/" class="<?= $ativo === 'admin' ? 'on' : '' ?>">Administração</a>
      <?php endif; ?>
    </nav>
    <div class="conta">
      <?php if ($u): ?>
        <span class="ola">Olá, <?= h(explode(' ', $u['nome'])[0]) ?></span>
        <a class="btn btn-sm btn-ghost" href="/sair.php">Sair</a>
      <?php else: ?>
        <a class="btn btn-sm btn-ghost" href="/login.php">Entrar</a>
        <a class="btn btn-sm" href="/registro.php">Cadastre-se</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<main class="wrap">
<?php
}

function layout_fim(): void
{
    ?></main>
<footer class="rodape"><div class="wrap">Banco de questões de concursos · Banca Selecon · uso para estudo</div></footer>
</body>
</html>
<?php
}
