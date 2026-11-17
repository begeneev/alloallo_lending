/* Имитация скрипта кнопки платформы (alloallo-widget.js) для локального стенда.
 * Тот же интерфейс: onReady, onError, getState().connectionStatus, start(), stop().
 * Контекст звонка из data-alloallo-context кладёт в window.__mockContext, чтобы его можно было проверить.
 * Голоса агента и собеседника — неслышные тоны с паузами: на странице видно «Говорит агент», «Слушает вас» и паузы. */
(() => {
  const script = document.currentScript;
  let ctx = {};
  try { ctx = JSON.parse(script.getAttribute('data-alloallo-context') || '{}'); } catch (e) {}
  window.__mockContext = ctx;
  const state = { isInitialized: false, connectionStatus: 'idle', audioElement: null, stream: null };
  const ready = [], errors = [];
  let audio = null;

  // Разговор по ролям: агент говорит фразу (слоги — скачки громкости), пауза, отвечает «собеседник», пауза.
  // Звук не слышен: голос агента — в audioElement (muted), «микрофон» — в stream, как у настоящего виджета.
  const SCRIPT = [['bot', 2600], ['none', 1500], ['user', 1800], ['none', 1200], ['bot', 1800], ['none', 2600]];
  function voice(ac, freq) {
    const osc = ac.createOscillator(), gain = ac.createGain(), dest = ac.createMediaStreamDestination();
    osc.frequency.value = freq; gain.gain.value = 0;
    osc.connect(gain); gain.connect(dest); osc.start();
    return { gain, stream: dest.stream };
  }
  function botVoice() {
    try {
      const ac = new (window.AudioContext || window.webkitAudioContext)();
      const bot = voice(ac, 220), user = voice(ac, 160);
      const el = new Audio(); el.srcObject = bot.stream; el.muted = true;
      let step = 0, until = performance.now() + SCRIPT[0][1];
      const timer = setInterval(() => {
        if (performance.now() > until) { step = (step + 1) % SCRIPT.length; until = performance.now() + SCRIPT[step][1]; }
        const who = SCRIPT[step][0];
        const syllable = 0.04 + Math.random() * 0.3;
        bot.gain.gain.setTargetAtTime(who === 'bot' ? syllable : 0, ac.currentTime, 0.03);
        user.gain.gain.setTargetAtTime(who === 'user' ? syllable : 0, ac.currentTime, 0.03);
      }, 110);
      audio = { ac, el, timer };
      state.audioElement = el;
      state.stream = user.stream;
    } catch (e) {}
  }

  window.AlloalloWidget = {
    onReady(cb) { ready.push(cb); },
    onError(cb) { errors.push(cb); },
    getState() { return state; },
    start() {
      state.connectionStatus = 'connecting';
      // ~2,5 с, как у настоящего виджета с вопросом про микрофон и подъёмом звонка
      setTimeout(() => { if (state.connectionStatus === 'connecting') { botVoice(); state.connectionStatus = 'connected'; } }, 2500);
    },
    stop() {
      state.connectionStatus = 'idle';
      if (audio) { clearInterval(audio.timer); try { audio.ac.close(); } catch (e) {} audio = null; state.audioElement = null; state.stream = null; }
    }
  };
  setTimeout(() => { state.isInitialized = true; ready.forEach((cb) => cb()); }, 300);
})();
