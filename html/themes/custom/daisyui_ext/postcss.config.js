import stylelint from'stylelint';
import tailwindcss from '@tailwindcss/postcss';
import postcssImport from 'postcss-import'

// A post-processing tool for CSS to help enforce consistent styles
export default (ctx) => ({
  // Setting the 'map' option for source map support.
  // If 'ctx.options.map' is defined, it uses that; otherwise, it defaults to 'false'.
  map: ctx.options?.map || false,
  plugins: [
    stylelint({}),
    postcssImport({plugins: [stylelint()]}),
    tailwindcss(),
  ],
});
