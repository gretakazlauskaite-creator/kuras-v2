(function (blocks, element, components) {
    'use strict';
    blocks.registerBlockType('kuras-pricer/finder', {
        apiVersion: 3,
        title: 'Kuro kainų palyginimas',
        icon: 'location-alt',
        category: 'widgets',
        description: 'Pricer kuro kainų paieška, TOP ir žemėlapis.',
        edit: function () {
            return element.createElement(components.Placeholder, {icon: 'location-alt', label: 'Kuro kainų palyginimas'}, 'Tikras vaizdas ir duomenys bus rodomi paskelbtame puslapyje.');
        },
        save: function () { return null; }
    });
}(window.wp.blocks, window.wp.element, window.wp.components));
