(function () {
    'use strict';

    var iconTokenPattern = /<icon:([A-Za-z0-9_-]+)(?:\|(toc|notoc))?>/g;

    function getFontelloInfo() {
        if (!window.JSINFO || !window.JSINFO.plugin_fontello) return null;
        return window.JSINFO.plugin_fontello;
    }

    function tokenShowsInToc(flag, info) {
        if (flag === 'notoc') return false;
        if (flag === 'toc') return true;

        return !!info.showInToc;
    }

    function createIconSpan(cssClass) {
        var span = document.createElement('span');
        span.className = 'fontello-icon ' + cssClass;
        span.setAttribute('aria-hidden', 'true');
        return span;
    }

    function replaceTextNodeTokens(textNode, info) {
        var text = textNode.nodeValue;
        var fragment = document.createDocumentFragment();
        var lastIndex = 0;
        var changed = false;
        var match;

        iconTokenPattern.lastIndex = 0;
        while ((match = iconTokenPattern.exec(text)) !== null) {
            var raw = match[0];
            var name = match[1];
            var flag = match[2] || '';
            var cssClass = info.icons && info.icons[name];
            var visible = tokenShowsInToc(flag, info);

            if (match.index > lastIndex) {
                fragment.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
            }

            if (cssClass && visible) {
                fragment.appendChild(createIconSpan(cssClass));
                changed = true;
            } else if (cssClass) {
                changed = true;
            } else {
                fragment.appendChild(document.createTextNode(raw));
            }

            lastIndex = match.index + raw.length;
        }

        if (!changed) return;
        if (lastIndex < text.length) {
            fragment.appendChild(document.createTextNode(text.slice(lastIndex)));
        }

        textNode.parentNode.replaceChild(fragment, textNode);
    }

    function replaceTocLinkTokens(link, info) {
        var walker = document.createTreeWalker(link, NodeFilter.SHOW_TEXT, null, false);
        var nodes = [];
        var node;

        while ((node = walker.nextNode()) !== null) {
            if (node.nodeValue.indexOf('<icon:') !== -1) {
                nodes.push(node);
            }
        }

        nodes.forEach(function (textNode) {
            replaceTextNodeTokens(textNode, info);
        });
    }

    function renderTocIcons() {
        var info = getFontelloInfo();
        if (!info || !info.icons) return;

        Array.prototype.forEach.call(document.querySelectorAll('#dw__toc a'), function (link) {
            replaceTocLinkTokens(link, info);
        });
    }

    function scheduleTocIconRendering() {
        window.setTimeout(renderTocIcons, 0);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleTocIconRendering);
    } else {
        scheduleTocIconRendering();
    }

    window.addBtnActionFontello = function ($btn, props, edid) {
        if (!props.list || !props.list.length) return '';

        var pickerid = 'picker' + (pickercounter++);
        var $picker = jQuery(document.createElement('div'))
            .addClass('picker a11y pk_fontello')
            .attr('id', pickerid)
            .attr('aria-hidden', 'true')
            .css('position', 'absolute')
            .removeAttr('hidden');

        jQuery.each(props.list, function (_, icon) {
            var name = icon.name || '';
            var cssClass = icon['class'] || '';
            var insert = icon.insert || '<icon:' + name + '>';
            if (!name || !cssClass) return;

            jQuery(document.createElement('button'))
                .addClass('pickerbutton fontello-picker-button')
                .attr('type', 'button')
                .attr('title', name)
                .attr('aria-label', name)
                .attr('aria-controls', edid)
                .on('click', function (event) {
                    insertAtCarret(edid, insert);
                    pickerClose();
                    event.preventDefault();
                })
                .append(
                    jQuery(document.createElement('span'))
                        .addClass('fontello-icon ' + cssClass)
                        .attr('aria-hidden', 'true')
                )
                .appendTo($picker);
        });

        jQuery('body').append($picker);

        $btn.on('click', function (event) {
            pickerToggle(pickerid, $btn);
            $picker.removeAttr('hidden').prop('hidden', false);
            event.preventDefault();
        });

        return pickerid;
    };
}());
