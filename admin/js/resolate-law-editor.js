/* global wp */
(function() {
    if (!window.wp) return;

    const { registerPlugin } = wp.plugins || {};
    const { createElement: el } = wp.element || {};
    const { BlockControls } = (wp.blockEditor || wp.editor || {});
    const { ToolbarDropdownMenu, ToolbarGroup } = wp.components || {};
    const { useSelect, useDispatch } = wp.data || {};
    const { addFilter } = (wp.hooks || {});

    if (!registerPlugin || !BlockControls || !ToolbarDropdownMenu || !useSelect || !useDispatch) return;

    const JustifyControl = function() {
        const selected = useSelect( (select) => {
            const ed = select('core/block-editor');
            const clientId = ed.getSelectedBlockClientId && ed.getSelectedBlockClientId();
            if (!clientId) return null;
            const block = ed.getBlock(clientId);
            return block || null;
        }, []);

        const { updateBlockAttributes } = useDispatch('core/block-editor');

        if (!selected || selected.name !== 'core/paragraph') return null;

        const cls = selected.attributes.className || '';
        const current = /has-text-align-justify/.test(cls) ? 'justify'
            : /has-text-align-center/.test(cls) ? 'center'
            : /has-text-align-right/.test(cls) ? 'right'
            : 'left';

        const setAlign = (align) => {
            let next = (selected.attributes.className || '').split(/\s+/).filter(Boolean)
                .filter((c) => !/^has-text-align-/.test(c));
            if (align && align !== 'left') {
                next.push('has-text-align-' + align);
            }
            updateBlockAttributes(selected.clientId, { className: next.join(' ') || undefined });
        };

        const items = [
            { title: 'Izquierda', onClick: () => setAlign('left'), isActive: current === 'left', icon: 'editor-alignleft' },
            { title: 'Centrado', onClick: () => setAlign('center'), isActive: current === 'center', icon: 'editor-aligncenter' },
            { title: 'Derecha', onClick: () => setAlign('right'), isActive: current === 'right', icon: 'editor-alignright' },
            { title: 'Justificado', onClick: () => setAlign('justify'), isActive: current === 'justify', icon: function(){
                return el('svg', { width: 24, height: 24, viewBox: '0 0 24 24' },
                    el('path', { d: 'M20 6H4V4h16v2Zm0 6H4v-2h16v2Zm0 6H4v-2h16v2Z' })
                );
            } },
        ];

        return el( BlockControls, { group: 'block' },
            el( ToolbarGroup, {},
                el( ToolbarDropdownMenu, {
                    icon: 'editor-alignleft',
                    label: 'Align text',
                    controls: items,
                } )
            )
        );
    };

    registerPlugin('resolate-law-justify', {
        render: JustifyControl,
        icon: null,
    });

    // Remove Drop cap and Orientation from paragraph supports.
    if (addFilter) {
        addFilter(
            'blocks.registerBlockType',
            'resolate/law-paragraph-supports',
            function(settings, name) {
                if (name !== 'core/paragraph') return settings;
                settings.supports = settings.supports || {};
                const typ = Object.assign({}, settings.supports.typography);
                typ.dropCap = false;
                // Orientation UI in Typography corresponds to writingMode.
                typ.writingMode = false;
                settings.supports.typography = typ;
                return settings;
            }
        );
    }

    // Also update editor settings to disable Drop cap and Orientation (writingMode) in Typography.
    try {
        const sel = wp.data.select('core/block-editor');
        const disp = wp.data.dispatch('core/block-editor');
        const settings = sel && sel.getSettings ? sel.getSettings() : {};
        const feat = Object.assign({}, settings.__experimentalFeatures || {});
        const typo = Object.assign({}, feat.typography || {});
        typo.dropCap = false;
        typo.writingMode = false;
        feat.typography = typo;
        disp && disp.updateSettings && disp.updateSettings({ __experimentalFeatures: feat });
    } catch (e) {
        // no-op
    }
})();
