<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
$erro = '';
$next = (string)($_GET['next'] ?? $_POST['next'] ?? '/');
if (!str_starts_with($next, '/') || str_starts_with($next, '//')) { $next = '/'; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT id, senha_hash FROM usuarios WHERE lower(email) = lower(?)');
    $st->execute([trim((string)$_POST['email'])]);
    $u = $st->fetch();
    if ($u && password_verify((string)$_POST['senha'], $u['senha_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        header('Location: ' . $next);
        exit;
    }
    $erro = 'E-mail ou senha incorretos.';
}
layout_inicio('Entrar');
?>
<form class="cartao form estreito" method="post">
  <h1>Entrar</h1>
  <?php if ($erro): ?><div class="alerta erro"><?= h($erro) ?></div><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="next" value="<?= h($next) ?>">
  <label>E-mail <input type="email" name="email" required autofocus></label>
  <label>Senha <input type="password" name="senha" required></label>
  <button class="btn btn-block">Entrar</button>
  <p>Não tem conta? <a href="/registro.php">Cadastre-se</a></p>
</form>
<?php layout_fim();
