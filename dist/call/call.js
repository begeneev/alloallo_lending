/* Страница звонка агенту Аллоалло из браузера.
 *
 * 1. Код заявки — в адресе (/call/#<код>), его выдаёт /api/lead.php после формы на лендинге.
 * 2. /api/call.php по коду отдаёт адрес виджета платформы и контекст звонка (телефон из формы, имя, сайт,
 *    номер сделки). Контекст уходит в виджет атрибутом data-alloallo-context.
 * 3. Звоним своей кнопкой: плавающую кнопку виджета прячем, звонок запускаем AlloalloWidget.start() / stop(),
 *    состояние читаем из AlloalloWidget.getState().connectionStatus.
 * 4. Нажали кнопку — {event:'start'}: сделка переходит на «Начал звонок» (один раз).
 *    Соединились — /api/call-event.php {event:'connected'}; положили трубку или закрыли страницу во время
 *    разговора — {event:'ended'}: сервер пишет итог в сделку Битрикс24 и меняет её стадию.
 * Кто говорит, виджет не сообщает — определяем по громкости голоса агента и микрофона.
 */
(() => {
  const stage = document.getElementById('stage');
  const btn = document.getElementById('call');
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const BOT_T = 0.012, USER_T = 0.025, CONNECT_TIMEOUT = 25000, LOAD_TIMEOUT = 12000;

  const TEXT = {
    loading: ['Готовим звонок…', 'Пара секунд'],
    ready: ['Нажмите, чтобы позвонить', 'Звонок идёт через браузер, нужен только микрофон'],
    connecting: ['Соединяем…', 'Если браузер спросит про микрофон, нажмите «Разрешить»'],
    talking: ['Идёт разговор', 'Чтобы закончить, нажмите красную кнопку'],
    botSpeaks: 'Говорит агент', userSpeaks: 'Агент слушает вас',
    ended: ['Спасибо за разговор', 'Длительность {time}. Если вы записались на демонстрацию — до встречи на ней.'],
    endedAgain: ['Спасибо за разговор', 'Длительность {time}. Можно позвонить ещё раз.'],
    failed: ['Не удалось соединиться', 'Попробуйте позвонить ещё раз через минуту'],
    mic: ['Нет доступа к микрофону', 'Разрешите микрофон: значок слева от адреса сайта. Потом позвоните снова'],
    unavailable: ['Звонок сейчас недоступен', 'Обновите страницу через минуту. Заявку мы уже получили'],
    not_configured: ['Звонок скоро заработает', 'Агента ещё настраиваем. Заявку мы получили и свяжемся с вами'],
    expired: ['Ссылка устарела', 'Ссылка на звонок действует сутки. Оставьте заявку на сайте ещё раз'],
    used: ['Повторный разговор — завтра', 'С одного номера — один разговор с агентом в сутки. Если записались на демонстрацию — до встречи на ней'],
    network: ['Не удалось открыть звонок', 'Проверьте интернет и обновите страницу']
  };
  // Повторная заявка с того же номера: новую не создаём, говорим об этом вверху страницы.
  const AGAIN = {
    lead: 'Вы уже оставляли заявку сегодня, новую мы не создавали. Поговорить с агентом можно по ней.',
    usedTitle: 'уже оставляли заявку и поговорили с агентом',
    usedLead: 'Заявка и итог разговора уже у нас, новую заявку мы не создавали.'
  };
  const ENDS = ['ended', 'expired', 'used', 'not_configured'];

  const code = (location.hash.slice(1).match(/^[a-f0-9]{32}$/) || [])[0] || '';
  let W = null, state = 'loading', speaker = 'none', prevCs = 'idle', lastError = null, repeat = false;
  let startedAt = 0, duration = 0, actx = null, meters = null, lvl = 0, tick = 0, connectTimer = 0, loadTimer = 0, inCall = false;

  const fmt = (s) => { s = Math.max(0, Math.floor(s)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); };
  const set = (key, val) => stage.querySelectorAll('[data-t="' + key + '"]').forEach((el) => { if (el.textContent !== val) el.textContent = val; });

  function render() {
    let pair;
    if (state === 'connected') pair = [speaker === 'bot' ? TEXT.botSpeaks : speaker === 'user' ? TEXT.userSpeaks : TEXT.talking[0], TEXT.talking[1]];
    else if (state === 'ended') { const t = repeat ? TEXT.endedAgain : TEXT.ended; pair = [t[0], t[1].replace('{time}', fmt(duration))]; }
    else if (state === 'mic') pair = [TEXT.mic[0], lastError && lastError.userMessage ? lastError.userMessage : TEXT.mic[1]];
    else pair = TEXT[state] || TEXT.ready;
    stage.dataset.state = state;
    stage.dataset.speaker = state === 'connected' ? speaker : 'none';
    set('status', pair[0]); set('sub', pair[1]);
    set('timer', state === 'connected' ? fmt((Date.now() - startedAt) / 1000) : '');
    const final = ENDS.includes(state) && !(state === 'ended' && repeat);
    btn.hidden = final;
    // Звонка на этой странице не было (уже поговорили, ссылка устарела) — убираем и место под кнопку.
    btn.parentElement.hidden = final && state !== 'ended';
    btn.disabled = ['loading', 'unavailable', 'connecting', 'network'].includes(state);
    btn.setAttribute('aria-label', state === 'connected' ? 'Завершить разговор' : state === 'connecting' ? 'Соединяем' : 'Позвонить агенту');
    const after = document.getElementById('cp-after');
    after.hidden = !final;
    const link = after.querySelector('a');
    link.href = state === 'expired' ? '/#zayavka' : '/';
    link.textContent = state === 'expired' ? 'Оставить заявку' : 'Вернуться на сайт';
    document.getElementById('cp-tips').hidden = final || state === 'connected';
  }

  function setState(s) {
    state = s;
    clearInterval(tick); tick = 0;
    if (s === 'connected') tick = setInterval(render, 1000);
    render();
  }

  function post(path, data, keepalive) {
    return fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data), keepalive: !!keepalive, credentials: 'same-origin' });
  }
  const sendEvent = (event, extra, keepalive) => post('/api/call-event.php', Object.assign({ code, event }, extra || {}), keepalive).catch(() => {});

  function loadWidget(url, context) {
    const s = document.createElement('script');
    s.src = url; s.async = true; s.id = 'alloallo-widget-script';
    // Контекст звонка — атрибутом скрипта: агент получает телефон из формы и данные заявки.
    s.setAttribute('data-alloallo-context', JSON.stringify(context));
    s.onload = () => {
      const w = window.AlloalloWidget;
      if (!w) { clearTimeout(loadTimer); setState('unavailable'); return; }
      const ready = () => { if (W) return; clearTimeout(loadTimer); W = w; setState('ready'); };
      w.onError((e) => { lastError = e; });
      w.onReady(ready);
      if (w.getState().isInitialized) ready();
    };
    s.onerror = () => { clearTimeout(loadTimer); setState('unavailable'); };
    loadTimer = setTimeout(() => { if (!W) setState('unavailable'); }, LOAD_TIMEOUT);
    document.body.appendChild(s);
  }

  function callEnded(reason) {
    if (!inCall) return;
    inCall = false;
    duration = (Date.now() - startedAt) / 1000;
    sendEvent('ended', { duration: Math.round(duration), reason }, reason === 'page_closed');
  }

  // Состояние звонка из виджета. Конец разговора агентом и обрыв связи он сообщает одинаково.
  setInterval(() => {
    if (!W) return;
    const cs = W.getState().connectionStatus;
    if (cs === prevCs) return;
    prevCs = cs;
    if (cs === 'connecting') {
      if (state !== 'connecting') setState('connecting');
    } else if (cs === 'connected') {
      clearTimeout(connectTimer);
      if (state !== 'connected') {
        startedAt = Date.now(); inCall = true;
        sendEvent('connected');
        setState('connected'); startMeters();
      }
    } else if (state === 'connected') {
      callEnded('hangup'); stopMeters(); setState('ended');
    } else if (state === 'connecting') {
      clearTimeout(connectTimer); stopMeters();
      setState(cs === 'failed' ? (lastError && lastError.userMessage ? 'mic' : 'failed') : 'ready');
    }
  }, 150);

  let startSent = false;
  btn.addEventListener('click', () => {
    if (!W) return;
    if (state === 'connected') { W.stop(); return; }
    if (state === 'connecting') return;
    lastError = null;
    try {
      if (!actx) actx = new (window.AudioContext || window.webkitAudioContext)();
      if (actx.state === 'suspended') actx.resume();
    } catch (e) { actx = null; }
    setState('connecting');
    if (!startSent) { startSent = true; sendEvent('start'); }
    W.start();
    clearTimeout(connectTimer);
    connectTimer = setTimeout(() => { if (state === 'connecting') { try { W.stop(); } catch (e) {} setState('failed'); } }, CONNECT_TIMEOUT);
  });

  // Закрыли вкладку во время разговора — всё равно фиксируем конец звонка; перед этим спрашиваем.
  window.addEventListener('beforeunload', (e) => { if (inCall) { e.preventDefault(); e.returnValue = ''; } });
  window.addEventListener('pagehide', () => callEnded('page_closed'));
  // Другой код в адресе — другая заявка: страницу загружаем заново.
  window.addEventListener('hashchange', () => { if (!inCall) location.reload(); });

  // Кто говорит: громкость голоса агента и микрофона.
  function meter(stream) {
    const src = actx.createMediaStreamSource(stream);
    const an = actx.createAnalyser(); an.fftSize = 1024;
    const mute = actx.createGain(); mute.gain.value = 0;
    src.connect(an); an.connect(mute); mute.connect(actx.destination);
    const buf = new Float32Array(an.fftSize);
    return {
      read() { an.getFloatTimeDomainData(buf); let s = 0; for (let k = 0; k < buf.length; k++) s += buf[k] * buf[k]; return Math.sqrt(s / buf.length); },
      close() { try { src.disconnect(); an.disconnect(); mute.disconnect(); } catch (e) {} }
    };
  }
  function startMeters() {
    stopMeters();
    if (!actx) return;
    const m = meters = { bot: null, user: null, raf: 0, cand: 'none', since: 0 };
    const frame = (now) => {
      if (meters !== m) return;
      const st = W && W.getState();
      try {
        if (!m.bot && st && st.audioElement && st.audioElement.srcObject) m.bot = meter(st.audioElement.srcObject);
        if (!m.user && st && st.stream) m.user = meter(st.stream);
      } catch (e) {}
      const b = m.bot ? m.bot.read() : 0, u = m.user ? m.user.read() : 0;
      const cand = b > BOT_T ? 'bot' : u > USER_T ? 'user' : 'none';
      if (cand !== m.cand) { m.cand = cand; m.since = now; }
      if (cand !== speaker && now - m.since >= (cand === 'none' ? 450 : 120)) { speaker = cand; render(); }
      const raw = speaker === 'bot' ? b * 7 : speaker === 'user' ? u * 6 : 0;
      lvl = reduce ? (speaker === 'none' ? 0 : 0.5) : lvl + (Math.min(1, raw) - lvl) * 0.3;
      stage.style.setProperty('--lvl', lvl.toFixed(3));
      m.raf = requestAnimationFrame(frame);
    };
    m.raf = requestAnimationFrame(frame);
  }
  function stopMeters() {
    if (meters) { cancelAnimationFrame(meters.raf); if (meters.bot) meters.bot.close(); if (meters.user) meters.user.close(); }
    meters = null; speaker = 'none'; lvl = 0; stage.style.setProperty('--lvl', '0');
  }

  async function init() {
    if (!code) { setState('expired'); return; }
    try {
      const r = await post('/api/call.php', { code });
      const j = await r.json().catch(() => ({}));
      const title = document.getElementById('cp-title'), lead = document.querySelector('.cp-lead');
      if (!j.ok) {
        if (j.error === 'used') { title.textContent = (j.name ? j.name + ', вы ' : 'Вы ') + AGAIN.usedTitle; lead.textContent = AGAIN.usedLead; }
        setState(TEXT[j.error] ? j.error : 'network');
        return;
      }
      repeat = !!j.repeat;
      if (j.name) title.textContent = j.name + ', поговорите с агентом Аллоалло';
      if (j.again) lead.textContent = AGAIN.lead;
      loadWidget(j.widget, j.context || {});
    } catch (e) {
      setState('network');
    }
  }
  render();
  init();
})();
