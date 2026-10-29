// Фонове зоряне небо з сузір'ями (мотив з брендбука)

const GOLD = '232, 194, 127';
const CREAM = '255, 231, 190';

export function startSky(canvas) {
  const ctx = canvas.getContext('2d');
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;

  let width = 0;
  let height = 0;
  let stars = [];
  let constellations = [];

  function makeConstellation() {
    const points = [];
    let x = Math.random() * width;
    let y = Math.random() * height;
    const count = 4 + Math.floor(Math.random() * 3);
    for (let i = 0; i < count; i++) {
      points.push({ x, y, phase: Math.random() * Math.PI * 2 });
      const angle = Math.random() * Math.PI * 2;
      const distance = 30 + Math.random() * 55;
      x += Math.cos(angle) * distance;
      y += Math.sin(angle) * distance;
    }
    return points;
  }

  function build() {
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    width = window.innerWidth;
    // Беремо висоту екрана, а не вікна: на мобільних панель адреси змінює innerHeight при скролі
    height = Math.max(window.innerHeight, window.screen.height);
    canvas.width = width * dpr;
    canvas.height = height * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    stars = Array.from({ length: Math.round((width * height) / 2800) }, () => ({
      x: Math.random() * width,
      y: Math.random() * height,
      r: 0.3 + Math.random() * 1.1,
      phase: Math.random() * Math.PI * 2,
      speed: 0.4 + Math.random() * 1.4,
      color: Math.random() < 0.4 ? GOLD : CREAM,
    }));
    constellations = Array.from({ length: Math.max(2, Math.round((width * height) / 160000)) }, makeConstellation);
  }

  function draw(time) {
    const t = time / 1000;
    ctx.clearRect(0, 0, width, height);

    for (const star of stars) {
      const alpha = 0.25 + 0.6 * (0.5 + 0.5 * Math.sin(t * star.speed + star.phase));
      ctx.fillStyle = `rgba(${star.color}, ${alpha})`;
      ctx.beginPath();
      ctx.arc(star.x, star.y, star.r, 0, Math.PI * 2);
      ctx.fill();
    }

    ctx.lineWidth = 0.6;
    ctx.strokeStyle = `rgba(${GOLD}, 0.2)`;
    for (const points of constellations) {
      ctx.beginPath();
      points.forEach((p, i) => (i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y)));
      ctx.stroke();

      for (const p of points) {
        const glow = 0.6 + 0.4 * Math.sin(t * 0.8 + p.phase);
        const gradient = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, 7);
        gradient.addColorStop(0, `rgba(${CREAM}, ${glow})`);
        gradient.addColorStop(0.3, `rgba(${GOLD}, ${glow * 0.5})`);
        gradient.addColorStop(1, `rgba(${GOLD}, 0)`);
        ctx.fillStyle = gradient;
        ctx.beginPath();
        ctx.arc(p.x, p.y, 7, 0, Math.PI * 2);
        ctx.fill();
      }
    }
  }

  function loop(time) {
    draw(time);
    requestAnimationFrame(loop);
  }

  build();
  if (reducedMotion) draw(0);
  else requestAnimationFrame(loop);

  // Перебудовуємо лише при зміні ширини (поворот екрана), щоб зорі не «стрибали» при скролі
  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      if (window.innerWidth === width) return;
      build();
      if (reducedMotion) draw(0);
    }, 200);
  });
}
