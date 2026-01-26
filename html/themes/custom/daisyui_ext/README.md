# daisyui_ext

This starterkit serves as a foundation for building your own theme based on DaisyUI. It utilizes Vite for CSS compilation and provides best practices for organizing your CSS files.

## Usage

See the [Starterkit documentation on Drupal.org](https://www.drupal.org/docs/core-modules-and-themes/core-themes/starterkit-theme).

Example command to generate your theme from your `web` folder

```bash
php core/scripts/drupal generate-theme my_daisyui_theme --starterkit daisyui_ext --path themes/custom
```

Then go to the new theme and run these commands to generate the CSS files:
- `npm install`
- `npm run build`

After that you can enable your theme under: `/admin/appearance` in the Drupal UI.

## CSS structure:

### File Types
* `.pcss files`: These are source files that are imported into other files. They are not directly processed by Vite to create standalone CSS files.
* `.pcss.css files under css`: These files are processed by Vite to generate the final CSS output. They are included in the build process and result in standalone CSS files.
* `.pcss.css files within the components directory` are automatically loaded by SDC, ensuring that the resulting CSS is available for use in your theme.
```
# Example Card component CSS file.
@reference '../../css/app.pcss';

.card-title {
  @apply text-2xl;
}
```
### Configure TailwindCSS and DaisyUI
Tailwind CSS can be configured under `tailwind.config.pcss` and DaisyUI can be configured under `css/plugins/daisyui.config.pcss`.
Additional Tailwind CSS plugins should included in the app.pcss as Tailwind Typography plugin that is already in the starterkit under `css/plugins/tailwindcss.typography.config.pcss`.

### DaisyUI themes
DaisyUI themes are placed under css/themes/theme.pcss.css and must included in `daisyui_ext.libraries.yml`.

For a detailed documentation on how to deal with DaisyUI themes, please refer to the _Working with DaisyUI themes_ section below.

### Using NPM

#### Setup NPM
- `npm install`

#### Build CSS (uses Vite)
- ``
```
❯ npm run build

> build
> NODE_ENV=production  vite build --emptyOutDir
```

#### Development (with watching, without mimyifing/compressing CSS)
- ```npm run dev``` to build both a CSS file and rebuild when a file is updated
```
❯ npm run dev
```

## Update DaisyUI and/or TailwindCSS

- ```npm update```
- ```npm run build```

### Clear cache
- ```drush cache:rebuild```

## Working with DaisyUI themes

### Updating an existing theme

Let's focus on the DaiysUI Dark theme override already there in the starterkit, provided as an example.

#### Updating new .pcss.css file

In the `css/themes/dark.pcss.css` file the primary color of the theme has been overriden as follows:

```
/**
 * Use this file to override DaisyUI dark theme,
 * Or add your own themes.
 */

@plugin "daisyui/theme" {
  name: "dark";
  --color-primary: oklch(55% 0.3 240);
}
```

#### Building the new DiasyUI package

`npm run build` has been already run for this change and has generated the `dist/css/themes/dark.css` file.

#### Updating the DaisyUI library

In order for the theme to be aware of this theme, the `my_daisyui_theme.libraries.yml` declares the library as follows:
```
daisyui:
  css:
    theme:
      ...
      "dist/css/themes/dark.css": { minified: true }
```

#### About UI Skins awarness

As the UI Skins definition of this theme is already present in the parent theme, nothing has to be updated on this part.

The Dark DaisyUI theme is available under `/admin/appearance/settings/my_daisyui_theme` in the theme section.

### Adding a new (random) theme

#### Adding new .pcss.css file

To add a new (random) theme, a new `css/themes/random.pcss.css` file must be created:
```
/**
 * Use this file to add DaisyUI random theme,
 * Or add your own themes.
 */
@plugin "daisyui/theme" {
  name: "random";
  default: false;
  prefersdark: false;
  color-scheme: "random";
  --color-base-100: oklch(98% 0.026 102.212);
  --color-base-200: oklch(97% 0.071 103.193);
  --color-base-300: oklch(94% 0.129 101.54);
  --color-base-content: oklch(42% 0.095 57.708);
  --color-primary: oklch(75% 0.183 55.934);
  --color-primary-content: oklch(26% 0.079 36.259);
  --color-secondary: oklch(70% 0.015 286.067);
  --color-secondary-content: oklch(14% 0.005 285.823);
  --color-accent: oklch(79% 0.209 151.711);
  --color-accent-content: oklch(26% 0.065 152.934);
  --color-neutral: oklch(47% 0.114 61.907);
  --color-neutral-content: oklch(98% 0.026 102.212);
  --color-info: oklch(74% 0.16 232.661);
  --color-info-content: oklch(29% 0.066 243.157);
  --color-success: oklch(84% 0.238 128.85);
  --color-success-content: oklch(27% 0.072 132.109);
  --color-warning: oklch(85% 0.199 91.936);
  --color-warning-content: oklch(28% 0.066 53.813);
  --color-error: oklch(71% 0.194 13.428);
  --color-error-content: oklch(27% 0.105 12.094);
  --radius-selector: 0rem;
  --radius-field: 0.5rem;
  --radius-box: 0rem;
  --size-selector: 0.25rem;
  --size-field: 0.25rem;
  --border: 1px;
  --depth: 1;
  --noise: 1;
}
```

#### Building the new DiasyUI package

Run the `npm run build` command to generate  the `dist/css/themes/random.css` file.

#### Updating the DaisyUI library

In order for the theme to be aware of this theme, the `my_daisyui_theme.libraries.yml` declares the library as follows:
```
daisyui:
  css:
    theme:
      ...
      "dist/css/themes/random.css": { minified: true }
```

#### Adding or updating the ui_skins.themes.yml file

If `my_daisyui_theme.ui_skins.themes.yml` file doesn't exist in your theme, just create it.

In order to use this new DaisyUI theme as configuration thanks to UI Skins modules, add a new definition to the `my_daisyui_theme.ui_skins.themes.yml` file as follows:

```
random:
  label: "Random"
  label_context: "color"
  key: "data-theme"
  target: html
```

Clear cache: ```drush cache:rebuild```

The new Random DaisyUI theme will be available under `/admin/appearance/settings/my_daisyui_theme` in the `theme` section.