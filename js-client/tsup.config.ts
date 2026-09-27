import { defineConfig } from 'tsup';

export default defineConfig({
  entry: { index: 'src/index.ts' },
  outDir: 'dist',
  format: ['esm', 'cjs'],
  target: 'es2022',
  platform: 'browser',
  dts: true,
  sourcemap: true,
  minify: true,
  // Preserve class/function names in the minified output so `error.name`,
  // `constructor.name` and stack traces stay meaningful for consumers.
  keepNames: true,
  treeshake: true,
  splitting: false,
  clean: true,
  shims: false,
  outExtension({ format }) {
    return { js: format === 'cjs' ? '.cjs' : '.js' };
  },
});
