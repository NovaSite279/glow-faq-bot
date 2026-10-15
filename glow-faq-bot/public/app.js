// Chat page: sends messages to chat.php and shows replies like a WhatsApp thread.
(function () {
  'use strict';
  var chat = document.getElementById('chat');
  var form = document.getElementById('compose');
  var input = document.getElementById('msg');
  var send = document.getElementById('send');
  var status = document.getElementById('status');
  var tryBox = document.getElementById('try');
  var idle = status.textContent;
  var busy = false;

  function now() {
    var d = new Date();
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  }
  document.querySelectorAll('[data-now]').forEach(function (el) { el.textContent = now(); });

  // WhatsApp-style *bold* and _italic_, applied after escaping
  function format(text) {
    var safe = text.replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
    return safe
      .replace(/\*([^*\n]+)\*/g, '<strong>$1</strong>')
      .replace(/(^|\s)_([^_\n]+)_(?=\s|$|[.,!?])/g, '$1<em>$2</em>')
      .replace(/\n/g, '<br>');
  }

  function add(kind, text) {
    var div = document.createElement('div');
    div.className = 'msg ' + kind;
    div.innerHTML = '<p>' + format(text) + '</p><span class="time">' + now() + (kind === 'me' ? ' <span class="ticks">✓✓</span>' : '') + '</span>';
    chat.appendChild(div);
    chat.scrollTop = chat.scrollHeight;
    return div;
  }

  function note(text) {
    var p = document.createElement('p');
    p.className = 'note';
    p.textContent = text;
    chat.appendChild(p);
    chat.scrollTop = chat.scrollHeight;
  }

  function typing(on) {
    var t = document.getElementById('typing');
    if (on && !t) {
      t = document.createElement('div');
      t.id = 'typing';
      t.className = 'msg bot typing';
      t.setAttribute('aria-label', 'Typing');
      t.innerHTML = '<span></span><span></span><span></span>';
      chat.appendChild(t);
      chat.scrollTop = chat.scrollHeight;
    } else if (!on && t) {
      t.remove();
    }
    status.textContent = on ? 'typing…' : idle;
  }

  function post(body) {
    return fetch('chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  function sendMessage(text) {
    text = text.trim();
    if (!text || busy) return;
    busy = true;
    send.disabled = true;
    tryBox.hidden = true;
    add('me', text);
    input.value = '';
    grow();
    typing(true);
    post({ message: text })
      .then(function (res) {
        typing(false);
        add('bot', res.reply || 'Sorry, something went wrong. Please call us on 011 000 0000.');
        if (res.action === 'handoff') note('Passed to the Glow team. A person will get back to you shortly.');
      })
      .catch(function () {
        typing(false);
        add('bot', 'I can\'t connect right now. Please check your signal and try again, or call us on 011 000 0000.');
      })
      .then(function () {
        busy = false;
        send.disabled = false;
        input.focus();
      });
  }

  function grow() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); sendMessage(input.value); });
  input.addEventListener('input', grow);
  input.addEventListener('keydown', function (e) {
    // Enter sends on desktop; on phones the keyboard's send key submits instead
    if (e.key === 'Enter' && !e.shiftKey && window.matchMedia('(pointer: fine)').matches) {
      e.preventDefault();
      sendMessage(input.value);
    }
  });
  tryBox.querySelectorAll('button').forEach(function (b) {
    b.addEventListener('click', function () { sendMessage(b.textContent); });
  });
  document.getElementById('reset').addEventListener('click', function () {
    post({ reset: true }).finally(function () { location.reload(); });
  });
})();
