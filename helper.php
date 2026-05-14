<?php

use dokuwiki\Extension\Plugin;

/**
 * Shared logic for the Fontello plugin.
 */
class helper_plugin_fontello extends Plugin
{
    protected const ACTIVE_DIR = DOKU_PLUGIN . 'fontello/assets/active';
    protected const ACTIVE_CSS = self::ACTIVE_DIR . '/css/fontello.css';
    protected const ACTIVE_CONFIG = self::ACTIVE_DIR . '/config.json';
    protected const ACTIVE_MANIFEST = self::ACTIVE_DIR . '/manifest.json';
    protected const ACTIVE_ENABLED = self::ACTIVE_DIR . '/enabled.json';
    protected const ACTIVE_FONT_DIR = self::ACTIVE_DIR . '/font';

    /**
     * Returns true when an active package is available.
     *
     * @return bool
     */
    public function hasActivePackage()
    {
        return file_exists(self::ACTIVE_CONFIG) && file_exists(self::ACTIVE_CSS);
    }

    /**
     * Returns the public URL to the generated stylesheet.
     *
     * @return string
     */
    public function getCssUrl()
    {
        $mtime = @filemtime(self::ACTIVE_CSS) ?: time();
        return DOKU_BASE . 'lib/plugins/fontello/assets/active/css/fontello.css?v=' . $mtime;
    }

    /**
     * Load the currently active package information.
     *
     * @return array|null
     */
    public function getPackageInfo()
    {
        if (!$this->hasActivePackage()) return null;

        $config = $this->loadJsonFile(self::ACTIVE_CONFIG);
        if ($config === null) return null;

        $manifest = $this->loadJsonFile(self::ACTIVE_MANIFEST) ?: [];
        $prefix = (string) ($config['css_prefix_text'] ?? 'icon-');
        $icons = $this->extractIcons($config);
        $enabledNames = $this->loadEnabledIconNames($icons);
        $enabledMap = array_fill_keys($enabledNames, true);

        foreach ($icons as $index => $icon) {
            $icons[$index]['enabled'] = isset($enabledMap[$icon['name']]);
        }

        return [
            'prefix' => $prefix,
            'icons' => $icons,
            'icon_count' => count($icons),
            'enabled_count' => count($enabledNames),
            'font_files' => $manifest['font_files'] ?? [],
            'imported_at' => $manifest['imported_at'] ?? null,
            'zip_name' => $manifest['zip_name'] ?? '',
        ];
    }

    /**
     * Return all active icons for toolbar or picker integrations.
     *
     * @return array
     */
    public function getActiveIcons()
    {
        $package = $this->getPackageInfo();
        if ($package === null) return [];

        return array_values(array_filter($package['icons'], static function ($icon) {
            return !empty($icon['enabled']);
        }));
    }

    /**
     * Check if the given icon exists in the active package.
     *
     * @param string $iconName
     * @return bool
     */
    public function hasIcon($iconName)
    {
        return $this->getIconClass($iconName) !== null;
    }

    /**
     * Return the CSS class for an icon name.
     *
     * @param string $iconName
     * @return string|null
     */
    public function getIconClass($iconName)
    {
        $package = $this->getPackageInfo();
        if ($package === null) return null;

        foreach ($package['icons'] as $icon) {
            if ($icon['name'] === $iconName) return $icon['class'];
        }

        return null;
    }

    /**
     * Parse a Fontello icon token.
     *
     * @param string $token
     * @return array|null
     */
    public function parseIconToken($token)
    {
        if (!preg_match('/^<icon:([A-Za-z0-9_-]+)((?:\|[A-Za-z0-9_-]+)*)>$/', $token, $match)) {
            return null;
        }

        $flags = [];
        if ($match[2] !== '') {
            foreach (explode('|', ltrim($match[2], '|')) as $flag) {
                if ($flag === '') continue;
                if (!in_array($flag, ['toc', 'notoc'], true)) return null;
                $flags[$flag] = true;
            }
        }

        return [
            'raw' => $token,
            'name' => $match[1],
            'flags' => $flags,
            'toc' => isset($flags['toc']),
            'notoc' => isset($flags['notoc']),
        ];
    }

    /**
     * Return the XHTML markup for a known icon.
     *
     * @param string $iconName
     * @return string|null
     */
    public function renderIconXhtml($iconName)
    {
        $class = $this->getIconClass($iconName);
        if ($class === null) return null;

        return '<span class="fontello-icon ' . hsc($class) . '" aria-hidden="true"></span>';
    }

    /**
     * Decide whether a parsed icon token should remain visible in the TOC.
     *
     * @param array $token
     * @return bool
     */
    public function iconTokenShowsInToc(array $token)
    {
        if (!empty($token['notoc'])) return false;
        if (!empty($token['toc'])) return true;

        return (bool) $this->getConf('showInToc');
    }

    /**
     * Persist which icons should be offered in toolbar or picker integrations.
     *
     * This does not affect inline rendering of imported icons.
     *
     * @param array $iconNames
     * @return int
     */
    public function saveEnabledIconNames(array $iconNames)
    {
        $package = $this->getPackageInfo();
        if ($package === null) {
            throw new RuntimeException($this->getLang('err_no_package'));
        }

        $requested = array_fill_keys(array_map('strval', $iconNames), true);
        $enabled = [];

        foreach ($package['icons'] as $icon) {
            if (isset($requested[$icon['name']])) {
                $enabled[] = $icon['name'];
            }
        }

        io_makeFileDir(self::ACTIVE_ENABLED);
        file_put_contents(self::ACTIVE_ENABLED, json_encode($enabled, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return count($enabled);
    }

    /**
     * Import a Fontello ZIP package.
     *
     * @param array $upload
     * @return array
     */
    public function importPackage(array $upload)
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        $tmpName = (string) ($upload['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName) && !file_exists($tmpName)) {
            throw new RuntimeException($this->getLang('err_upload_missing'));
        }

        $archive = $this->openArchive($tmpName);
        $map = $archive['map'];
        $configEntry = $this->findRequiredEntry($map, 'config.json', $this->getLang('err_missing_config'));
        $this->findRequiredEntry($map, 'css/fontello.css', $this->getLang('err_missing_css'));
        $fontEntries = $this->findFontEntries($map);
        if ($fontEntries === []) {
            $this->closeArchive($archive);
            throw new RuntimeException($this->getLang('err_missing_fonts'));
        }

        $configJson = $this->readArchiveEntry($archive, $configEntry);
        $config = json_decode($configJson, true);
        if (!is_array($config) || !isset($config['glyphs']) || !is_array($config['glyphs'])) {
            $this->closeArchive($archive);
            throw new RuntimeException($this->getLang('err_invalid_config'));
        }

        $icons = $this->extractIcons($config);
        if ($icons === []) {
            $this->closeArchive($archive);
            throw new RuntimeException($this->getLang('err_no_icons'));
        }

        $fontFiles = [];
        $fontContents = [];
        foreach ($fontEntries as $relative => $original) {
            $basename = basename($relative);
            $fontFiles[] = $basename;
            $fontContents[$basename] = $this->readArchiveEntry($archive, $original);
        }

        $manifest = [
            'zip_name' => (string) ($upload['name'] ?? ''),
            'imported_at' => date('c'),
            'prefix' => (string) ($config['css_prefix_text'] ?? 'icon-'),
            'icon_count' => count($icons),
            'font_files' => array_values($fontFiles),
        ];

        $css = $this->buildCss($config, $fontFiles);

        $this->closeArchive($archive);
        $this->resetActiveDirectory();

        foreach ($fontContents as $basename => $content) {
            $target = self::ACTIVE_FONT_DIR . '/' . $basename;
            io_makeFileDir($target);
            file_put_contents($target, $content);
        }

        io_makeFileDir(self::ACTIVE_CONFIG);
        file_put_contents(self::ACTIVE_CONFIG, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        io_makeFileDir(self::ACTIVE_MANIFEST);
        file_put_contents(self::ACTIVE_MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        io_makeFileDir(self::ACTIVE_ENABLED);
        $enabledJson = json_encode(array_column($icons, 'name'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents(self::ACTIVE_ENABLED, $enabledJson);
        io_makeFileDir(self::ACTIVE_CSS);
        file_put_contents(self::ACTIVE_CSS, $css);
        $this->purgeDokuWikiCaches();

        return $this->getPackageInfo() ?: $manifest;
    }

    /**
     * Extract icon metadata from the package config.
     *
     * @param array $config
     * @return array
     */
    protected function extractIcons(array $config)
    {
        $prefix = (string) ($config['css_prefix_text'] ?? 'icon-');
        $icons = [];

        foreach ($config['glyphs'] ?? [] as $glyph) {
            $name = trim((string) ($glyph['css'] ?? ''));
            $code = $glyph['code'] ?? null;
            if ($name === '' || !is_numeric($code)) continue;

            $icon = [
                'name' => $name,
                'class' => $prefix . $name,
                'code' => strtolower(dechex((int) $code)),
            ];

            // Fontello packages may contain duplicate css names; keep the last one.
            $icons[$icon['class']] = $icon;
        }

        $icons = array_values($icons);

        usort($icons, static function ($left, $right) {
            return strcmp($left['name'], $right['name']);
        });

        return $icons;
    }

    /**
     * Load enabled icon names. Missing or invalid state means all icons are enabled.
     *
     * @param array $icons
     * @return array
     */
    protected function loadEnabledIconNames(array $icons)
    {
        $allNames = array_column($icons, 'name');
        $enabled = $this->loadJsonFile(self::ACTIVE_ENABLED);
        if ($enabled === null || array_values($enabled) !== $enabled) return $allNames;

        $known = array_fill_keys($allNames, true);
        $names = [];

        foreach ($enabled as $name) {
            $name = (string) $name;
            if (isset($known[$name])) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Build a normalized entry map for the archive.
     *
     * @param ZipArchive $zip
     * @return array
     */
    protected function buildArchiveMap(array $originalNames)
    {
        $roots = [];
        $hasTopLevelFiles = false;

        foreach ($originalNames as $name) {
            $name = str_replace('\\', '/', $name);
            if (substr($name, -1) === '/') continue;
            $name = trim($name, '/');
            if ($name === '') continue;
            $parts = explode('/', $name, 2);
            $roots[$parts[0]] = true;
            if (count($parts) === 1) $hasTopLevelFiles = true;
        }

        $stripRoot = count($roots) === 1 && !$hasTopLevelFiles;
        $map = [];

        foreach ($originalNames as $name) {
            $name = str_replace('\\', '/', $name);
            if (substr($name, -1) === '/') continue;
            $name = trim($name, '/');
            if ($name === '') continue;
            $relative = $name;
            if ($stripRoot) {
                $relative = explode('/', $name, 2)[1] ?? '';
            }
            if ($relative === '' || substr($relative, -1) === '/') continue;
            $map[$relative] = $name;
        }

        return $map;
    }

    /**
     * Find a required archive entry.
     *
     * @param array $map
     * @param string $relativePath
     * @param string $errorMessage
     * @return string
     */
    protected function findRequiredEntry(array $map, $relativePath, $errorMessage)
    {
        if (!isset($map[$relativePath])) {
            throw new RuntimeException($errorMessage);
        }

        return $map[$relativePath];
    }

    /**
     * Return all supported font entries.
     *
     * @param array $map
     * @return array
     */
    protected function findFontEntries(array $map)
    {
        $fonts = [];
        foreach ($map as $relative => $original) {
            if (!str_starts_with($relative, 'font/')) continue;
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if (!in_array($extension, ['eot', 'svg', 'ttf', 'woff', 'woff2'], true)) continue;
            $fonts[$relative] = $original;
        }

        return $fonts;
    }

    /**
     * Read a single entry from the archive.
     *
     * @param ZipArchive $zip
     * @param string $entryName
     * @return string
     */
    protected function openArchive($tmpName)
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($tmpName) === true) {
                $names = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $names[] = $zip->getNameIndex($i);
                }
                return [
                    'type' => 'ziparchive',
                    'handle' => $zip,
                    'map' => $this->buildArchiveMap($names),
                ];
            }
        }

        if ($this->canUseSystemZipTools()) {
            return [
                'type' => 'system',
                'path' => $tmpName,
                'map' => $this->buildArchiveMap($this->listArchiveEntries($tmpName)),
            ];
        }

        throw new RuntimeException($this->getLang('err_zip_support'));
    }

    /**
     * Close an open archive handle when needed.
     *
     * @param array $archive
     * @return void
     */
    protected function closeArchive(array $archive)
    {
        if (($archive['type'] ?? '') === 'ziparchive' && isset($archive['handle'])) {
            $archive['handle']->close();
        }
    }

    /**
     * Read a single entry from the archive.
     *
     * @param array $archive
     * @param string $entryName
     * @return string
     */
    protected function readArchiveEntry(array $archive, $entryName)
    {
        if (($archive['type'] ?? '') === 'ziparchive') {
            $content = $archive['handle']->getFromName($entryName);
            if ($content === false) {
                throw new RuntimeException(sprintf($this->getLang('err_archive_read'), $entryName));
            }
            return $content;
        }

        $command = 'unzip -p ' . escapeshellarg($archive['path']) . ' ' . escapeshellarg($entryName);
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException($this->getLang('err_zip_open'));
        }

        $content = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $message = trim($error) !== '' ? trim($error) : sprintf($this->getLang('err_archive_read'), $entryName);
            throw new RuntimeException($message);
        }

        return $content;
    }

    /**
     * List archive entries using zipinfo.
     *
     * @param string $tmpName
     * @return array
     */
    protected function listArchiveEntries($tmpName)
    {
        $output = [];
        $exitCode = 0;
        exec('zipinfo -1 ' . escapeshellarg($tmpName), $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException($this->getLang('err_zip_open'));
        }

        return $output;
    }

    /**
     * Check whether system ZIP tools can be used as a fallback.
     *
     * @return bool
     */
    protected function canUseSystemZipTools()
    {
        if (!function_exists('exec') || !function_exists('proc_open')) return false;

        return $this->commandExists('unzip') && $this->commandExists('zipinfo');
    }

    /**
     * Check whether a shell command exists.
     *
     * @param string $command
     * @return bool
     */
    protected function commandExists($command)
    {
        $output = [];
        $exitCode = 0;
        exec('command -v ' . escapeshellarg($command), $output, $exitCode);
        return $exitCode === 0 && !empty($output);
    }

    /**
     * Remove the current active package and recreate the base directory.
     *
     * @return void
     */
    protected function resetActiveDirectory()
    {
        if (file_exists(self::ACTIVE_DIR)) {
            io_rmdir(self::ACTIVE_DIR, true);
        }

        io_mkdir_p(self::ACTIVE_DIR);
    }

    /**
     * Expire DokuWiki render and asset caches after package changes.
     *
     * DokuWiki's extension manager uses the same local.php touch pattern.
     *
     * @return void
     */
    protected function purgeDokuWikiCaches()
    {
        global $config_cascade;

        $localConfig = reset($config_cascade['main']['local']);
        if ($localConfig) {
            @touch($localConfig);
        }
    }

    /**
     * Generate the public stylesheet from config data.
     *
     * @param array $config
     * @param array $fontFiles
     * @return string
     */
    protected function buildCss(array $config, array $fontFiles)
    {
        $family = 'fontello';
        $icons = $this->extractIcons($config);
        $sources = [];

        $formatMap = [
            'eot' => 'embedded-opentype',
            'woff2' => 'woff2',
            'woff' => 'woff',
            'ttf' => 'truetype',
            'svg' => 'svg',
        ];
        $priority = ['eot', 'woff2', 'woff', 'ttf', 'svg'];

        foreach ($priority as $extension) {
            foreach ($fontFiles as $fontFile) {
                if (strtolower(pathinfo($fontFile, PATHINFO_EXTENSION)) !== $extension) continue;
                $url = "../font/$fontFile";
                if ($extension === 'svg') {
                    $url .= '#' . $family;
                }
                $sources[] = "url('$url') format('" . $formatMap[$extension] . "')";
            }
        }

        $css = "@font-face {\n";
        $css .= "  font-family: '$family';\n";
        $css .= '  src: ' . implode(",\n       ", $sources) . ";\n";
        $css .= "  font-weight: normal;\n";
        $css .= "  font-style: normal;\n";
        $css .= "}\n\n";
        $css .= ".fontello-icon {\n";
        $css .= "  display: inline-block;\n";
        $css .= "}\n\n";
        $css .= ".fontello-icon:before {\n";
        $css .= "  font-family: '$family';\n";
        $css .= "  font-style: normal;\n";
        $css .= "  font-weight: normal;\n";
        $css .= "  speak: never;\n";
        $css .= "  display: inline-block;\n";
        $css .= "  text-decoration: inherit;\n";
        $css .= "  width: 1em;\n";
        $css .= "  margin-right: .2em;\n";
        $css .= "  text-align: center;\n";
        $css .= "  font-variant: normal;\n";
        $css .= "  text-transform: none;\n";
        $css .= "  line-height: 1em;\n";
        $css .= "  margin-left: .2em;\n";
        $css .= "  -webkit-font-smoothing: antialiased;\n";
        $css .= "  -moz-osx-font-smoothing: grayscale;\n";
        $css .= "}\n\n";

        foreach ($icons as $icon) {
            $css .= '.fontello-icon.' . $icon['class'] . ':before { content: "\\' . $icon['code'] . "\"; }\n";
        }

        return $css;
    }

    /**
     * Load a JSON file from disk.
     *
     * @param string $file
     * @return array|null
     */
    protected function loadJsonFile($file)
    {
        if (!file_exists($file)) return null;

        $json = file_get_contents($file);
        if ($json === false) return null;

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Translate PHP upload error codes.
     *
     * @param int $error
     * @return string
     */
    protected function uploadErrorMessage($error)
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $this->getLang('err_upload_too_large'),
            UPLOAD_ERR_PARTIAL => $this->getLang('err_upload_partial'),
            UPLOAD_ERR_NO_FILE => $this->getLang('err_upload_missing'),
            default => $this->getLang('err_upload_generic'),
        };
    }
}
