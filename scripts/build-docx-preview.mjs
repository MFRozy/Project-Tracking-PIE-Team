import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const outputDir = join(root, 'public', 'lib');
const require = createRequire(import.meta.url);
const docxPreviewRoot = dirname(dirname(require.resolve('docx-preview')));
const jszipRoot = dirname(dirname(require.resolve('jszip')));
const pdfjsRoot = dirname(require.resolve('pdfjs-dist/package.json'));

mkdirSync(outputDir, { recursive: true });

await build({
  entryPoints: [join(docxPreviewRoot, 'dist', 'docx-preview.mjs')],
  bundle: true,
  format: 'iife',
  globalName: 'DocxPreview',
  outfile: join(outputDir, 'docx-preview.js'),
  minify: true,
  legalComments: 'none',
});

await build({
  entryPoints: [join(pdfjsRoot, 'legacy', 'build', 'pdf.mjs')],
  bundle: true,
  format: 'iife',
  globalName: 'PdfPreview',
  outfile: join(outputDir, 'pdf-preview.js'),
  minify: true,
  legalComments: 'none',
});

for (const bundle of ['docx-preview.js', 'pdf-preview.js']) {
  const path = join(outputDir, bundle);
  writeFileSync(path, readFileSync(path, 'utf8').replace(/[ \t]+$/gm, ''));
}

copyFileSync(join(docxPreviewRoot, 'LICENSE'), join(outputDir, 'LICENSE-docx-preview.txt'));
copyFileSync(join(jszipRoot, 'LICENSE.markdown'), join(outputDir, 'LICENSE-jszip.txt'));
copyFileSync(join(pdfjsRoot, 'LICENSE'), join(outputDir, 'LICENSE-pdfjs.txt'));
copyFileSync(join(pdfjsRoot, 'legacy', 'build', 'pdf.worker.min.mjs'), join(outputDir, 'pdf.worker.min.mjs'));
