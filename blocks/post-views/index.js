( function ( wp ) {
	'use strict';

	var el                = wp.element.createElement;
	var __                = wp.i18n.__;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody         = wp.components.PanelBody;
	var SelectControl     = wp.components.SelectControl;
	var Disabled          = wp.components.Disabled;
	var ServerSideRender  = wp.serverSideRender;

	wp.blocks.registerBlockType( 'vireo/post-views', {
		edit: function ( props ) {
			var a       = props.attributes;
			var postId  = a.postId || ( props.context && props.context.postId ) || 0;

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Settings', 'vireo-analytics' ) },
						el( SelectControl, {
							label: __( 'Count views from', 'vireo-analytics' ),
							value: a.period,
							options: [
								{ value: 'all', label: __( 'All time', 'vireo-analytics' ) },
								{ value: '7', label: __( 'Last 7 days', 'vireo-analytics' ) },
								{ value: '30', label: __( 'Last 30 days', 'vireo-analytics' ) },
								{ value: '90', label: __( 'Last 90 days', 'vireo-analytics' ) },
							],
							onChange: function ( v ) {
								props.setAttributes( { period: v } );
							},
						} )
					)
				),
				postId
					? el(
						Disabled,
						null,
						el( ServerSideRender, {
							block: 'vireo/post-views',
							attributes: { period: a.period, postId: postId },
						} )
					)
					: el( 'p', null, __( 'Shows this post\'s view count on the site.', 'vireo-analytics' ) )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
