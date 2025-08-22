(function(){
  var h1   = document.querySelector('.home-hero.centered .hero-content h1');
  var wrap = document.querySelector('.home-hero.centered .hero-content');
  if (!h1 || !wrap) return;

  function px(v){ return parseFloat(v) || 0; }

  function fit(){
    // stato di misura
    h1.style.whiteSpace = 'nowrap';
    h1.style.overflow   = 'hidden';  // temporaneo per misure precise

    // larghezza disponibile = width reale del contenitore - piccolo buffer
    var avail = wrap.clientWidth - 24; // buffer anti-taglio (nessun padding sul wrap)

    // limiti di font-size in px
    var MIN = 22, MAX = 200;

    // stima iniziale basata sulla width del contenitore, non sulla viewport
    var guess = Math.min(MAX, Math.max(MIN, wrap.clientWidth * 0.10));
    h1.style.fontSize = guess + 'px';

    // binary search per combaciare al pixel
    var low = MIN, high = MAX, mid, i = 0;
    while (i++ < 16) {
      mid = (low + high) / 2;
      h1.style.fontSize = mid + 'px';
      if (h1.scrollWidth > avail) { high = mid; } else { low = mid; }
    }
    // margine finale anti-taglio
    h1.style.fontSize = Math.floor(low - 2) + 'px';
    h1.style.overflow = 'visible';
  }

  var raf;
  function schedule(){ cancelAnimationFrame(raf); raf = requestAnimationFrame(fit); }

  // ricalcola su resize / orientation e DOPO il caricamento dei font/immagini
  window.addEventListener('resize', schedule, {passive:true});
  window.addEventListener('orientationchange', schedule);
  window.addEventListener('load', schedule);
  document.addEventListener('DOMContentLoaded', fit);

  fit();
})();
