/**
 * Block „Nur mit Lernzugang“ (bootspruefung/zugang): innere Blöcke werden nur
 * mit passendem Lernzugang ausgegeben, sonst ein Hinweis mit Produktlink.
 * Ohne Build-Schritt, nur WordPress-Globals.
 */
(function (wp, cfg) {
	'use strict';
	if (!wp || !wp.blocks) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var c = wp.components;
	cfg = cfg || { courses: {}, features: {} };

	function options(map, empty) {
		var out = empty ? [{ value: '', label: empty }] : [];
		Object.keys(map).forEach(function (k) { out.push({ value: k, label: map[k] }); });
		return out;
	}

	wp.blocks.registerBlockType('bootspruefung/zugang', {
		apiVersion: 3,
		title: __('Nur mit Lernzugang', 'bootspruefung-learning'),
		description: __('Inhalt nur für Nutzer mit Lernzugang zum gewählten Kurs.', 'bootspruefung-learning'),
		category: 'design',
		icon: 'lock',
		attributes: {
			course: { type: 'string', default: '' },
			feature: { type: 'string', default: 'any' },
			message: { type: 'string', default: '' }
		},
		edit: function (props) {
			var a = props.attributes;
			var blockProps = be.useBlockProps({ className: 'bp-restricted-editor', style: { border: '1px dashed #8c8f94', padding: '12px' } });
			return el('div', blockProps, [
				el(be.InspectorControls, { key: 'inspector' }, el(c.PanelBody, { title: __('Lernzugang', 'bootspruefung-learning') }, [
					el(c.SelectControl, { key: 'course', label: __('Kurs', 'bootspruefung-learning'), value: a.course, options: options(cfg.courses, __('– bitte wählen –', 'bootspruefung-learning')), onChange: function (v) { props.setAttributes({ course: v }); } }),
					el(c.SelectControl, { key: 'feature', label: __('Funktion', 'bootspruefung-learning'), value: a.feature, options: options(cfg.features), onChange: function (v) { props.setAttributes({ feature: v }); } }),
					el(c.TextareaControl, { key: 'message', label: __('Hinweis ohne Zugang', 'bootspruefung-learning'), value: a.message, onChange: function (v) { props.setAttributes({ message: v }); } })
				])),
				el('p', { key: 'label', style: { margin: '0 0 8px', fontSize: '12px', opacity: 0.8 } }, __('Nur mit Lernzugang:', 'bootspruefung-learning') + ' ' + (a.course ? (cfg.courses[a.course] || a.course) + ' · ' + (cfg.features[a.feature] || a.feature) : __('Kurs in der Seitenleiste wählen', 'bootspruefung-learning'))),
				el(be.InnerBlocks, { key: 'inner' })
			]);
		},
		save: function () {
			return el(be.InnerBlocks.Content);
		}
	});
}(window.wp, window.bpRestricted));
