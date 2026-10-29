// Демо-збірка для клієнта: відкривається подвійним кліком з диска (file://), працює на моку.
// Браузер не вантажить JS-модулі з file://, тому JS і CSS вшиваються прямо в HTML,
// а шляхи робляться відносними. Обидві сторінки лежать в одній папці (index.html, admin.html),
// щоб мок-дані в localStorage були спільні.
import { resolve } from 'node:path';
import { build } from 'vite';

const root = resolve(import.meta.dirname, '..');
const outDir = resolve(root, 'dist-demo');

const pages = [
  { input: 'index.html', output: 'index.html' },
  { input: 'admin/index.html', output: 'admin.html' },
];

const escapeRegExp = (str) => str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

function inlineIntoHtml(output) {
  return {
    name: 'demo-inline',
    enforce: 'post',
    generateBundle(_, bundle) {
      const htmlKey = Object.keys(bundle).find((key) => key.endsWith('.html'));
      let html = bundle[htmlKey].source;

      for (const [key, file] of Object.entries(bundle)) {
        const name = escapeRegExp(key);

        if (file.type === 'chunk') {
          const code = file.code.replaceAll('</script', '<\\/script');
          html = html.replace(
            new RegExp(`<script[^>]*src="[^"]*${name}"[^>]*></script>`),
            () => `<script type="module">${code}</script>`,
          );
        } else if (key.endsWith('.css')) {
          html = html.replace(
            new RegExp(`<link[^>]*href="[^"]*${name}"[^>]*>`),
            () => `<style>${file.source}</style>`,
          );
        } else {
          continue;
        }

        delete bundle[key];
      }

      delete bundle[htmlKey];
      // admin/index.html у демо лежить поруч з index.html, тому ../images/ → ./images/
      this.emitFile({ type: 'asset', fileName: output, source: html.replaceAll('"../images/', '"./images/') });
    },
  };
}

for (const [index, page] of pages.entries()) {
  await build({
    root,
    configFile: false,
    mode: 'demo',
    base: './',
    logLevel: 'warn',
    plugins: [inlineIntoHtml(page.output)],
    build: {
      outDir,
      emptyOutDir: index === 0,
      copyPublicDir: index === 0,
      rollupOptions: {
        input: resolve(root, page.input),
        output: { codeSplitting: false },
      },
    },
  });
}

console.log(`Демо зібрано: ${outDir}`);
