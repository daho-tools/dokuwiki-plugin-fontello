<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * Syntax component for inline icon rendering.
 */
class syntax_plugin_fontello extends SyntaxPlugin
{
    /** @var helper_plugin_fontello */
    protected $helper;

    public function __construct()
    {
        $this->helper = $this->loadHelper('fontello');
    }

    /**
     * @return string
     */
    public function getType()
    {
        return 'substition';
    }

    /**
     * @return string
     */
    public function getPType()
    {
        return 'normal';
    }

    /**
     * @return int
     */
    public function getSort()
    {
        return 190;
    }

    /**
     * @param string $mode
     * @return void
     */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('<icon:[A-Za-z0-9_-]+(?:\|(?:toc|notoc))?>', $mode, 'plugin_fontello');
    }

    /**
     * @param string $match
     * @param int $state
     * @param int $pos
     * @param Doku_Handler $handler
     * @return array
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $token = $this->helper->parseIconToken($match);
        if ($token === null) {
            return [
                'raw' => $match,
                'name' => '',
                'class' => null,
                'missing_package' => false,
            ];
        }

        $name = $token['name'];
        $hasPackage = $this->helper->hasActivePackage();
        $class = $hasPackage ? $this->helper->getIconClass($name) : null;

        return [
            'raw' => $match,
            'name' => $name,
            'class' => $class,
            'missing_package' => !$hasPackage,
        ];
    }

    /**
     * @param string $format
     * @param Doku_Renderer $renderer
     * @param array $data
     * @return bool
     */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format !== 'xhtml') return false;

        /** @var Doku_Renderer_xhtml $renderer */
        if (!empty($data['class'])) {
            $renderer->doc .= '<span class="fontello-icon ' . hsc($data['class']) . '" aria-hidden="true"></span>';
        } elseif (!empty($data['missing_package'])) {
            $renderer->doc .= hsc(sprintf($this->getLang('icon_missing_package'), $data['name']));
        } else {
            $renderer->doc .= hsc($data['raw']);
        }

        return true;
    }
}
