(() => {
  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

  // Smoothstep gives gentle starts and endings instead of abrupt linear ramps.
  function smoothstep(t) {
    const x = clamp(t, 0, 1);
    return x * x * (3 - 2 * x);
  }

  window.createProgressionCurve = function createProgressionCurve(options = {}) {
    const start = Number.isFinite(options.start) ? options.start : 1;
    const end = Number.isFinite(options.end) ? options.end : 2.35;
    const warmup = Number.isFinite(options.warmup) ? options.warmup : 0;
    const duration = Math.max(1, Number(options.duration) || 2400);

    return {
      at(progressValue) {
        const raw = Number(progressValue) - warmup;
        const normalized = smoothstep(raw / duration);
        return start + (end - start) * normalized;
      }
    };
  };
})();
