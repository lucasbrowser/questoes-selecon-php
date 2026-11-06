(function () {
  function post(data) {
    data.csrf = window.CSRF;
    return fetch('/api_responder.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF': window.CSRF },
      body: new URLSearchParams(data)
    }).then(function (r) { return r.json(); });
  }

  function marca(card, d, escolhida) {
    card.querySelectorAll('.alt').forEach(function (a) {
      a.classList.remove('certa', 'errada');
      if (d.gabarito && a.dataset.letra === d.gabarito) a.classList.add('certa');
      else if (escolhida && a.dataset.letra === escolhida) a.classList.add('errada');
    });
  }

  function comentario(card, d) {
    var box = card.querySelector('.comentario');
    var partes = [];
    if (d.anulada) partes.push('<b>Questão anulada pela banca.</b>');
    else if (!d.gabarito) partes.push('<b>Gabarito ainda não cadastrado para esta questão.</b>');
    else partes.push('<b>Gabarito oficial:</b> alternativa ' + d.gabarito + '.');
    if (d.pct_acerto !== null && d.total_resp > 0)
      partes.push('<br>' + d.pct_acerto + '% dos ' + d.total_resp + ' usuário(s) que responderam acertaram.');
    if (d.comentario) partes.push('<br>' + d.comentario.replace(/</g, '&lt;').replace(/\n/g, '<br>'));
    box.innerHTML = partes.join(' ');
    box.hidden = false;
  }

  document.querySelectorAll('.questao').forEach(function (card) {
    var id = card.dataset.id;
    var res = card.querySelector('.resultado');

    card.querySelectorAll('.tesoura').forEach(function (t) {
      t.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        t.closest('.alt').classList.toggle('riscada');
      });
    });

    card.querySelector('.btn-resolver').addEventListener('click', function () {
      var sel = card.querySelector('input[type=radio]:checked');
      if (!sel) { res.textContent = 'Selecione uma alternativa.'; res.className = 'resultado erro'; return; }
      post({ questao_id: id, letra: sel.value }).then(function (d) {
        if (d.erro) { res.textContent = d.erro; res.className = 'resultado erro'; return; }
        if (d.correta === true) { res.textContent = 'Você acertou!'; res.className = 'resultado ok'; }
        else if (d.correta === false) { res.textContent = 'Você errou! Gabarito: ' + d.gabarito; res.className = 'resultado erro'; }
        else { res.textContent = d.anulada ? 'Questão anulada.' : 'Sem gabarito cadastrado.'; res.className = 'resultado'; }
        if (!window.LOGADO && d.correta !== null) res.textContent += ' (entre na sua conta para salvar o desempenho)';
        marca(card, d, sel.value);
        comentario(card, d);
      });
    });

    card.querySelector('.btn-gab').addEventListener('click', function () {
      post({ questao_id: id, modo: 'gabarito' }).then(function (d) {
        marca(card, d, null);
        comentario(card, d);
      });
    });
  });
})();
