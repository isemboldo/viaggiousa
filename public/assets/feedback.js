(function () {
  const API_BASE = (window.__BASE_URL__ || '').replace(/\/+$/,''); // dal layout se serve
  const SEL = '[data-feedback]'; // bottoni: data-feedback="like|dislike|more"
  const DISABLED_MS = 1200;      // debounce client

  // piccolo toast in-page (senza CSS esterno)
  function toast(msg) {
    let t = document.getElementById('fb-toast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'fb-toast';
      t.style.position = 'fixed';
      t.style.left = '50%';
      t.style.bottom = '24px';
      t.style.transform = 'translateX(-50%)';
      t.style.background = 'rgba(17,24,39,.95)';
      t.style.color = '#fff';
      t.style.padding = '10px 14px';
      t.style.borderRadius = '10px';
      t.style.fontSize = '14px';
      t.style.boxShadow = '0 6px 20px rgba(0,0,0,.25)';
      t.style.zIndex = '9999';
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(t._hide);
    t._hide = setTimeout(() => { t.style.opacity = '0'; }, 1600);
  }

  async function send(sectionId, action) {
    const url = API_BASE + '/api/section/' + sectionId + '/feedback';
    const params = new URLSearchParams();
    params.set('action', action);

    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type':'application/x-www-form-urlencoded' },
      body: params.toString(),
      credentials: 'same-origin'
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return await res.json();
  }

  function updateCounts(root, counts) {
    // root: container della sezione (data-section="ID")
    const map = { like: 'likes', dislike: 'dislikes', more: 'more_info' };
    for (const k in map) {
      const span = root.querySelector('[data-count="'+map[k]+'"]');
      if (span) span.textContent = String(counts[map[k]] ?? 0);
    }
  }

  function onClick(e) {
    const btn = e.currentTarget;
    if (btn._busy) return;
    btn._busy = true;
    setTimeout(()=>{ btn._busy = false; }, DISABLED_MS);

    const action = btn.getAttribute('data-feedback');
    const root = btn.closest('[data-section]');
    const sectionId = root && root.getAttribute('data-section');
    if (!action || !sectionId) return;

    // aggiorna ottimistico (se presente)
    const field = action === 'like' ? 'likes' : (action === 'dislike' ? 'dislikes' : 'more_info');
    const span = root.querySelector('[data-count="'+field+'"]');
    const before = span ? parseInt(span.textContent||'0', 10) : null;
    if (span && !Number.isNaN(before)) span.textContent = String(before + 1);

    send(sectionId, action)
      .then(json => {
        if (json.counts) updateCounts(root, json.counts);
        toast(json.message || 'OK');
      })
      .catch(() => {
        if (span && before !== null) span.textContent = String(before); // rollback
        toast('Errore, riprova');
      });
  }

  function bind() {
    document.querySelectorAll(SEL).forEach(btn => {
      btn.removeEventListener('click', onClick);
      btn.addEventListener('click', onClick);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
