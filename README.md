# Fontello Plugin for DokuWiki

Import a local Fontello ZIP package and use its icons in DokuWiki pages with simple `<icon:name>` syntax.

The plugin stores the imported Fontello CSS and font files locally inside the DokuWiki installation. It does not load fonts from a CDN or any other remote source.

## Features

- Import a ZIP package downloaded from https://fontello.com
- Render icons in regular DokuWiki page content with `<icon:name>`
- Render icons in headings
- Show icons in the table of contents, with optional per-icon control
- Enable or disable icons for the editor toolbar picker in the admin panel
- Works with the Read the Dokus template
- Includes compatibility handling for the catlist plugin

## Usage

Upload a Fontello ZIP package in the DokuWiki admin area.

After the import, use icons in page content like this:

```text
<icon:ok>
<icon:download>
<icon:mail>
```

For headings and table-of-contents behavior:

```text
<icon:ok|toc>
<icon:ok|notoc>
```

Unknown icons are left visible as their original syntax so missing or mistyped icon names can be spotted easily.

## Administration

The admin page shows the currently imported Fontello package, its CSS prefix, the number of detected icons, and a preview of each icon.

Icons can be enabled or disabled for the toolbar picker. This only controls which icons are offered in the editor picker; imported icons can still be rendered with `<icon:name>` syntax.

## Compatibility

Tested with:

- regular DokuWiki page content
- headings
- the Read the Dokus template
- the catlist plugin
