/* Editor UI for the PlanSync block. Rendering happens on the server. */
(function (wp) {
    'use strict';
    if (!wp || !wp.blocks) {
        return;
    }

    var el = wp.element.createElement;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var c = wp.components;
    var ServerSideRender = wp.serverSideRender;
    var data = window.whmcsPlansEditor || { groups: [], settingsUrl: '', iconUrl: '' };

    wp.blocks.registerBlockType('arphost/whmcs-plans', {
        apiVersion: 3,
        title: 'PlanSync',
        description: 'Plans, prices and stock from a WHMCS product group. By ARPHost.',
        icon: data.iconUrl ? el('img', { src: data.iconUrl, alt: '', width: 20, height: 20 }) : 'cart',
        category: 'arphost',
        keywords: ['whmcs', 'pricing', 'plans', 'vps'],
        edit: function (props) {
            var a = props.attributes;
            var set = function (key) {
                return function (value) {
                    var next = {};
                    next[key] = value;
                    props.setAttributes(next);
                };
            };
            var groupOptions = [{ label: 'Choose a product group', value: '' }].concat(data.groups);

            var controls = el(InspectorControls, null,
                el(c.PanelBody, { title: 'Plans', initialOpen: true },
                    el(c.SelectControl, { label: 'Product group', value: a.group, options: groupOptions, onChange: set('group') }),
                    data.settingsUrl ? el('p', null, el('a', { href: data.settingsUrl, target: '_blank', rel: 'noopener' }, 'Manage groups, exclusions and SEO fields')) : null,
                    el(c.SelectControl, {
                        label: 'Layout', value: a.layout, onChange: set('layout'),
                        options: [{ label: 'Tabs by category', value: 'tabs' }, { label: 'Sections by category', value: 'grid' }]
                    }),
                    el(c.SelectControl, {
                        label: 'Out of stock plans', value: a.outOfStock, onChange: set('outOfStock'),
                        options: [{ label: 'Show in place', value: 'show' }, { label: 'Show last', value: 'last' }, { label: 'Hide', value: 'hide' }]
                    }),
                    el(c.TextControl, { label: 'Only these categories (comma separated)', value: a.category, onChange: set('category') }),
                    el(c.TextControl, { label: 'Button label', value: a.buttonLabel, onChange: set('buttonLabel') })
                ),
                el(c.PanelBody, { title: 'SEO', initialOpen: false },
                    el(c.RangeControl, { label: 'Category heading level', min: 2, max: 6, value: a.categoryLevel, onChange: set('categoryLevel') }),
                    el(c.RangeControl, { label: 'Plan heading level', min: 2, max: 6, value: a.planLevel, onChange: set('planLevel') }),
                    el(c.ToggleControl, { label: 'Add Product structured data', checked: !!a.showSchema, onChange: set('showSchema') })
                )
            );

            return el('div', useBlockProps(),
                controls,
                a.group
                    ? el(ServerSideRender, { block: 'arphost/whmcs-plans', attributes: a })
                    : el(c.Placeholder, { icon: 'cart', label: 'PlanSync', instructions: 'Choose a product group in the block settings.' })
            );
        },
        save: function () {
            return null;
        }
    });
})(window.wp);
