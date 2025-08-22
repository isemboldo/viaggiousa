(function(){
  var track = document.getElementById('hubTrack');
  if (!track) return;

  var viewport = track.closest('.hub-viewport');
  var prev = document.querySelector('.hub-carousel .prev');
  var next = document.querySelector('.hub-carousel .next');
  var status = document.getElementById('carouselStatus');

  var slides = Array.from(track.children); // figure.hub-slide
  var count  = slides.length;
  if (count === 0) return;

  // A11y: aggiorna aria-hidden/tabindex e annuncia lo stato
  function setActive(i){
    idx = (i + count) % count;
    for (var k = 0; k < count; k++){
      var s = slides[k];
      if (k === idx){
        s.classList.add('active');
        s.setAttribute('aria-hidden', 'false');
        s.tabIndex = 0;
      } else {
        s.classList.remove('active');
        s.setAttribute('aria-hidden', 'true');
        s.tabIndex = -1;
      }
    }
    if (status){
      status.textContent = 'Immagine ' + (idx + 1) + ' di ' + count;
    }
  }

  // Avvio dopo la prima immagine (decode o fallback)
  var idx = 0;
  var firstImg = slides[0].querySelector('img');
  function start(){
    setActive(0);
    startAuto();
  }
  if (firstImg && 'decode' in firstImg){
    firstImg.decode().then(start).catch(start);
  } else if (firstImg && !firstImg.complete){
    var once = function(){ start(); };
    firstImg.addEventListener('load', once, {once:true});
    firstImg.addEventListener('error', once, {once:true});
    setTimeout(start, 800);
  } else {
    start();
  }

  // Navigazione
  function nextSlide(){ setActive(idx + 1); }
  function prevSlide(){ setActive(idx - 1); }
  if (prev) prev.addEventListener('click', function(){ stopAutoTemp(); prevSlide(); });
  if (next) next.addEventListener('click', function(){ stopAutoTemp(); nextSlide(); });

  // Tastiera
  track.addEventListener('keydown', function(e){
    if (e.key === 'ArrowLeft'){ e.preventDefault(); stopAutoTemp(); prevSlide(); }
    if (e.key === 'ArrowRight'){ e.preventDefault(); stopAutoTemp(); nextSlide(); }
  });

  // Swipe touch
  var startX = null, deltaX = 0, dragging = false;
  viewport.addEventListener('touchstart', function(e){
    startX = e.touches[0].clientX; deltaX = 0; dragging = true; stopAutoTemp();
  }, {passive:true});
  viewport.addEventListener('touchmove', function(e){
    if (!dragging) return;
    deltaX = e.touches[0].clientX - startX;
  }, {passive:true});
  viewport.addEventListener('touchend', function(){
    if (!dragging) return;
    dragging = false;
    if (Math.abs(deltaX) > 40){ if (deltaX < 0) nextSlide(); else prevSlide(); }
    deltaX = 0;
  });

  // Autoplay con rispetto di prefers-reduced-motion
  var timer = null, resumeTimer = null;
  var AUTO_DELAY = 4800, AUTO_PAUSE = 6000;
  var prefersReduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function startAuto(){
    if (prefersReduce || count <= 1) return;
    stopAuto();
    timer = setInterval(nextSlide, AUTO_DELAY);
  }
  function stopAuto(){
    if (timer){ clearInterval(timer); timer = null; }
  }
  function stopAutoTemp(){
    stopAuto();
    if (resumeTimer) clearTimeout(resumeTimer);
    if (!prefersReduce) resumeTimer = setTimeout(startAuto, AUTO_PAUSE);
  }

  // Pausa su hover/focus/tab nascosta
  viewport.addEventListener('mouseenter', stopAuto, {passive:true});
  viewport.addEventListener('mouseleave', startAuto, {passive:true});
  track.addEventListener('focusin', stopAuto);
  track.addEventListener('focusout', startAuto);
  document.addEventListener('visibilitychange', function(){
    if (document.hidden) stopAuto(); else startAuto();
  });

  // Precarica tutto (se per caso fosse lazy)
  slides.forEach(function(s){
    var img = s.querySelector('img');
    if (img && img.loading === 'lazy') img.loading = 'eager';
  });
})();
