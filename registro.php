<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nome = trim((string)$_POST['nome']);
    $email = trim((string)$_POST['email']);
    $senha = (string)$_POST['senha'];
    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Informe nome e um e-mail válido.';
    } elseif (strlen($senha) < 8) {
        $erro = 'A senha deve ter pelo menos 8 caracteres.';
    } else {
        $db = db();
        $st = $db->prepare('SELECT 1 FROM usuarios WHERE lower(email) = lower(?)');
        $st->execute([$email]);
        if ($st->fetch()) {
            $erro = 'Este e-mail já está cadastrado.';
        } else {
            $primeiro = (int)$db->query('SELECT count(*) FROM usuarios')->fetchColumn() === 0; // 1º usuário = administrador
            $st = $db->prepare('INSERT INTO usuarios (nome, email, senha_hash, is_admin) VALUES (?,?,?,?)');
            $st->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $primeiro ? 1 : 0]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$db->lastInsertId();
            header('Location: /');
            exit;
        }
    }
}
layout_inicio('Cadastre-se');
?>
<form class="cartao form estreito" method="post">
  <h1>Criar conta</h1>
  <?php if ($erro): ?><div class="alerta erro"><?= h($erro) ?></div><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <label>Nome <input name="nome" required autofocus value="<?= h($_POST['nome'] ?? '') ?>"></label>
  <label>E-mail <input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>"></label>
  <label>Senha (mín. 8 caracteres) <input type="password" name="senha" required minlength="8"></label>
  <button class="btn btn-block">Cadastrar</button>
  <p>Já tem conta? <a href="/login.php">Entrar</a></p>
</form>
<?php layout_fim();
