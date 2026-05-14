<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;

/**
 * Action component for globally loading the active Fontello stylesheet.
 */
class action_plugin_fontello extends ActionPlugin
{
    /**
     * @param EventHandler $controller
     * @return void
     */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'handleJsInfo');
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'handleHeader');
        $controller->register_hook('RENDERER_CONTENT_POSTPROCESS', 'BEFORE', $this, 'handleRendererPostprocess');
        $controller->register_hook('TPL_TOC_RENDER', 'BEFORE', $this, 'handleToc');
        $controller->register_hook('TPL_CONTENT_DISPLAY', 'BEFORE', $this, 'handleContentDisplay');
        $controller->register_hook('TOOLBAR_DEFINE', 'AFTER', $this, 'handleToolbar');
        $controller->register_hook('JS_CACHE_USE', 'BEFORE', $this, 'handleJsCache');
    }

    /**
     * Provide active icon metadata to plugin JavaScript before JSINFO is emitted.
     *
     * @return void
     */
    public function handleJsInfo()
    {
        global $JSINFO;

        /** @var helper_plugin_fontello $helper */
        $helper = $this->loadHelper('fontello', false);
        if ($helper === null || !$helper->hasActivePackage()) return;

        $package = $helper->getPackageInfo();
        if ($package === null) return;

        $icons = [];
        foreach ($package['icons'] as $icon) {
            if (!isset($icon['name'], $icon['class'])) continue;
            $icons[$icon['name']] = $icon['class'];
        }

        $JSINFO['plugin_fontello'] = [
            'icons' => $icons,
            'showInToc' => (bool) $helper->getConf('showInToc'),
        ];
    }

    /**
     * Inject the active stylesheet when a package is available.
     *
     * @param Event $event
     * @param mixed $param
     * @return void
     */
    public function handleHeader(Event &$event, $param)
    {
        /** @var helper_plugin_fontello $helper */
        $helper = $this->loadHelper('fontello', false);
        if ($helper === null || !$helper->hasActivePackage()) return;

        foreach ($event->data['link'] as $link) {
            if (($link['href'] ?? '') === $helper->getCssUrl()) return;
        }

        $event->data['link'][] = [
            'rel' => 'stylesheet',
            'type' => 'text/css',
            'href' => $helper->getCssUrl(),
        ];
    }

    /**
     * Remove TOC-hidden icon tokens before DokuWiki builds the TOC HTML.
     *
     * @param Event $event
     * @param mixed $param
     * @return void
     */
    public function handleToc(Event &$event, $param)
    {
        /** @var helper_plugin_fontello $helper */
        $helper = $this->loadHelper('fontello', false);
        if ($helper === null || !is_array($event->data)) return;

        foreach ($event->data as $index => $item) {
            if (!isset($item['title'])) continue;

            $event->data[$index]['title'] = $this->filterTocTitle((string) $item['title'], $helper);
        }
    }

    /**
     * Render Fontello tokens in rendered XHTML headings.
     *
     * @param Event $event
     * @param mixed $param
     * @return void
     */
    public function handleRendererPostprocess(Event &$event, $param)
    {
        /** @var helper_plugin_fontello $helper */
        $helper = $this->loadHelper('fontello', false);
        if (
            $helper === null ||
            !$helper->hasActivePackage() ||
            !is_array($event->data) ||
            ($event->data[0] ?? '') !== 'xhtml' ||
            !isset($event->data[1]) ||
            !is_string($event->data[1])
        ) {
            return;
        }

        $event->data[1] = $this->replaceHeadingIconTokens($event->data[1], $helper);
        $event->data[1] = $this->replaceLinkIconTokens($event->data[1], $helper);
        $event->data[1] = $this->replaceCatlistIconTokens($event->data[1], $helper);
    }

    /**
     * Render Fontello tokens in visible TOC links.
     *
     * @param Event $event
     * @param mixed $param
     * @return void
     */
    public function handleContentDisplay(Event &$event, $param)
    {
        /** @var helper_plugin_fontello $helper */
        $helper = $this->loadHelper('fontello', false);
        if ($helper === null || !$helper->hasActivePackage() || !is_string($event->data)) return;

        $event->data = preg_replace_callback(
            '/(<!-- TOC START -->.*?<!-- TOC END -->)/s',
            function ($match) use ($helper) {
                return $this->replaceEscapedIconTokens($match[1], $helper, false);
            },
            $event->data
        );
    }

    /**
     * Add a Fontello picker button to the editor toolbar.
     *
     * @param Event $event
     * @param mixed $param
     * @return void
     */
    public function handleToolbar(Event &$event, $param)
    {
        /** @var helper_plugin_fontello $helper */
        $helper = $this->loadHelper('fontello', false);
        if ($helper === null || !$helper->hasActivePackage()) return;

        $icons = $helper->getActiveIcons();
        if ($icons === []) return;

        $button = [
            'type' => 'fontello',
            'title' => $this->getLang('toolbar_icons'),
            'icon' => DOKU_BASE . 'lib/plugins/fontello/images/toolbar/fontello.svg',
            'class' => 'pk_fontello',
            'list' => array_map(static function ($icon) {
                return [
                    'name' => $icon['name'],
                    'class' => $icon['class'],
                    'insert' => '<icon:' . $icon['name'] . '>',
                ];
            }, $icons),
            'block' => false,
        ];

        $insertAt = count($event->data);
        foreach ($event->data as $index => $item) {
            if (($item['type'] ?? '') === 'picker' && ($item['icobase'] ?? '') === 'smileys') {
                $insertAt = $index + 1;
                break;
            }
        }

        array_splice($event->data, $insertAt, 0, [$button]);
    }

    /**
     * Make dynamic toolbar data sensitive to active package changes.
     *
     * DokuWiki caches the generated toolbar JavaScript. The toolbar button list
     * depends on runtime JSON files, so they need to be cache dependencies.
     *
     * @param Event $event
     * @param mixed $param
     * @return void
     */
    public function handleJsCache(Event &$event, $param)
    {
        if (!isset($event->data->depends['files']) || !is_array($event->data->depends['files'])) {
            $event->data->depends['files'] = [];
        }

        foreach ([
            DOKU_PLUGIN . 'fontello/assets/active/config.json',
            DOKU_PLUGIN . 'fontello/assets/active/enabled.json',
        ] as $file) {
            if (file_exists($file)) {
                $event->data->depends['files'][] = $file;
            }
        }
    }

    /**
     * Replace escaped icon tokens in XHTML headings.
     *
     * @param string $html
     * @param helper_plugin_fontello $helper
     * @return string
     */
    protected function replaceHeadingIconTokens($html, helper_plugin_fontello $helper)
    {
        return preg_replace_callback(
            '/<h([1-6])\b([^>]*)>(.*?)<\/h\1>/s',
            function ($match) use ($helper) {
                return '<h' . $match[1] . $match[2] . '>' .
                    $this->replaceEscapedIconTokens($match[3], $helper, true) .
                    '</h' . $match[1] . '>';
            },
            $html
        );
    }

    /**
     * Replace escaped icon tokens in rendered link labels.
     *
     * This covers plugins such as catlist that render page titles via the
     * XHTML renderer's internallink() method instead of reparsing title text.
     *
     * @param string $html
     * @param helper_plugin_fontello $helper
     * @return string
     */
    protected function replaceLinkIconTokens($html, helper_plugin_fontello $helper)
    {
        return preg_replace_callback(
            '/<a\b([^>]*)>(.*?)<\/a>/s',
            function ($match) use ($helper) {
                return '<a' . $match[1] . '>' .
                    $this->replaceEscapedIconTokens($match[2], $helper, true) .
                    '</a>';
            },
            $html
        );
    }

    /**
     * Replace escaped icon tokens in catlist labels that are not links.
     *
     * @param string $html
     * @param helper_plugin_fontello $helper
     * @return string
     */
    protected function replaceCatlistIconTokens($html, helper_plugin_fontello $helper)
    {
        return preg_replace_callback(
            '/<(?P<tag>h[1-5]|strong|span|li)\b(?P<attrs>[^>]*\bclass="[^"]*\bcatlist-(?:head|nshead|page)\b[^"]*"[^>]*)>(?P<body>.*?)<\/(?P=tag)>/s',
            function ($match) use ($helper) {
                return '<' . $match['tag'] . $match['attrs'] . '>' .
                    $this->replaceEscapedIconTokens($match['body'], $helper, true) .
                    '</' . $match['tag'] . '>';
            },
            $html
        );
    }

    /**
     * Keep only TOC-visible tokens in a title.
     *
     * @param string $title
     * @param helper_plugin_fontello $helper
     * @return string
     */
    protected function filterTocTitle($title, helper_plugin_fontello $helper)
    {
        $title = preg_replace_callback(
            '/<icon:[A-Za-z0-9_-]+(?:\|(?:toc|notoc))?>/',
            function ($match) use ($helper) {
                $token = $helper->parseIconToken($match[0]);
                if ($token === null || !$helper->iconTokenShowsInToc($token)) return '';
                if ($helper->renderIconXhtml($token['name']) === null) return $match[0];
                return $match[0];
            },
            $title
        );

        return trim(preg_replace('/[ \t]{2,}/', ' ', $title));
    }

    /**
     * Replace escaped icon tokens with local icon HTML.
     *
     * @param string $html
     * @param helper_plugin_fontello $helper
     * @param bool $ignoreTocFlag
     * @return string
     */
    protected function replaceEscapedIconTokens($html, helper_plugin_fontello $helper, $ignoreTocFlag)
    {
        return preg_replace_callback(
            '/&lt;icon:([A-Za-z0-9_-]+)(?:\|(toc|notoc))?&gt;/',
            function ($match) use ($helper, $ignoreTocFlag) {
                $raw = '<icon:' . $match[1] . (isset($match[2]) && $match[2] !== '' ? '|' . $match[2] : '') . '>';
                $token = $helper->parseIconToken($raw);
                if ($token === null) return $match[0];
                if (!$ignoreTocFlag && !$helper->iconTokenShowsInToc($token)) return '';

                return $helper->renderIconXhtml($token['name']) ?: $match[0];
            },
            $html
        );
    }
}
