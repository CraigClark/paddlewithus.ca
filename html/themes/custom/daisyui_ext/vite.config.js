import { defineConfig } from 'vite';
import { resolve, join, relative } from 'path';
import { globSync } from 'glob';
import { viteStaticCopy } from 'vite-plugin-static-copy';
import svgSpritePlugin from '@pivanov/vite-plugin-svg-sprite';

/**
 * Defines glob patterns for CSS source files.
 */
const cssGlob = {
  '': 'css/**/*.pcss.css',
  'css': 'components/**/*.pcss.css',
};

/**
 * Defines icon lib folder.
 * You can define one icon lib for each spritemap.
 */
const iconLibs = {
  'default': ['icons/default'],
};


export default defineConfig(() => {
  const projectRoot = __dirname;

  // Generate entry points for Rollup
  const entries = Object.entries(cssGlob).reduce((acc, [folder, pattern]) => {
    const files = globSync(join(projectRoot, pattern));
    files.forEach((file) => {
      const outputKey = join(folder, relative(projectRoot, file)).replace('.pcss.css', '');
      acc[outputKey] = resolve(projectRoot, file);
    });
    return acc;
  }, {});


  // Create plugins array with svgSpritePlugin instances
  const plugins = Object.entries(iconLibs).map(([lib, folders]) =>
    svgSpritePlugin({
      iconDirs: folders,
      symbolId: '[dir]-[name]',
      fileName: `${lib}-icons.svg`,
      svgDomId: 'svg-sprite',
    })
  );

  return {
    plugins: [
      ...plugins,
      viteStaticCopy({
        targets: [
          {
            src: 'dist/css/components', // Copy compiled CSS directory
            dest: '../', // to component and folder.
          },
        ],
      }),
    ],
    css: {
      postcss: true,
    },
    build: {
      minify: process.env.NODE_ENV === 'production',
      rollupOptions: {
        input: entries,
        output: {
          assetFileNames: '[name].[ext]',
        },
      },
    },
  };
});
