( function ( wp ) {
	'use strict';

	var el                = wp.element.createElement;
	var __                = wp.i18n.__;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody         = wp.components.PanelBody;
	var RangeControl      = wp.components.RangeControl;
	var SelectControl     = wp.components.SelectControl;
	var ToggleControl     = wp.components.ToggleControl;
	var CheckboxControl   = wp.components.CheckboxControl;
	var Disabled          = wp.components.Disabled;
	var BaseControl       = wp.components.BaseControl;
	var ServerSideRender  = wp.serverSideRender;
	var useSelect         = wp.data.useSelect;

	function PostTypeChecks( props ) {
		var types = useSelect( function ( select ) {
			return select( 'core' ).getPostTypes( { per_page: -1 } );
		}, [] );

		if ( ! types ) {
			return null;
		}

		return el(
			'div',
			{ style: { marginBottom: '24px' } },
			el( BaseControl.VisualLabel, null, __( 'Post types', 'vireo-analytics' ) ),
			types
				.filter( function ( t ) {
					return t.viewable && 'attachment' !== t.slug;
				} )
				.map( function ( t ) {
					var on = props.value.indexOf( t.slug ) !== -1;
					// The last ticked type can't be unticked. It used to snap
					// back to Posts without a word, switching what the block shows.
					var last = on && props.value.length === 1;
					return el( CheckboxControl, {
						key: t.slug,
						label: t.name,
						checked: on,
						disabled: last,
						help: last ? __( 'At least one type stays selected.', 'vireo-analytics' ) : undefined,
						onChange: function ( checked ) {
							var next = props.value.filter( function ( s ) {
								return s !== t.slug;
							} );
							if ( checked ) {
								next.push( t.slug );
							}
							props.onChange( next.length ? next : [ 'post' ] );
						},
					} );
				} )
		);
	}

	wp.blocks.registerBlockType( 'vireo/popular-posts', {
		edit: function ( props ) {
			var a   = props.attributes;
			var set = props.setAttributes;

			// The preview runs over REST, where there is no "current post"
			// unless we say which one is being edited.
			var postId = useSelect( function ( select ) {
				var editor = select( 'core/editor' );
				return editor ? editor.getCurrentPostId() : 0;
			}, [] );

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Settings', 'vireo-analytics' ) },
						el( RangeControl, {
							label: __( 'Number of posts', 'vireo-analytics' ),
							value: a.number,
							min: 1,
							max: 20,
							onChange: function ( v ) {
								set( { number: v || 5 } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Period', 'vireo-analytics' ),
							value: a.period,
							options: [
								{ value: '7', label: __( 'Last 7 days', 'vireo-analytics' ) },
								{ value: '30', label: __( 'Last 30 days', 'vireo-analytics' ) },
								{ value: '90', label: __( 'Last 90 days', 'vireo-analytics' ) },
								{ value: 'all', label: __( 'All time', 'vireo-analytics' ) },
							],
							onChange: function ( v ) {
								set( { period: v } );
							},
						} ),
						el( PostTypeChecks, {
							value: a.postTypes,
							onChange: function ( v ) {
								set( { postTypes: v } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Display', 'vireo-analytics' ),
							value: a.display,
							options: [
								{ value: 'list', label: __( 'List', 'vireo-analytics' ) },
								{ value: 'thumbnails', label: __( 'List with featured images', 'vireo-analytics' ) },
							],
							help: 'thumbnails' === a.display
								? __( 'Posts without a featured image are listed by title only.', 'vireo-analytics' )
								: undefined,
							onChange: function ( v ) {
								set( { display: v } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Show view counts', 'vireo-analytics' ),
							checked: a.showViews,
							onChange: function ( v ) {
								set( { showViews: v } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Leave out the post being viewed', 'vireo-analytics' ),
							checked: a.excludeCurrent,
							onChange: function ( v ) {
								set( { excludeCurrent: v } );
							},
						} )
					)
				),
				el(
					Disabled,
					null,
					el( ServerSideRender, {
						block: 'vireo/popular-posts',
						attributes: a,
						// The block prints nothing on the site when no post qualifies;
						// in the editor that left a blank gap with no reason given.
						EmptyResponsePlaceholder: function () {
							return el(
								'p',
								{ className: 'vireo-popular-posts__empty' },
								__( 'No posts of the selected types have views in this period yet. Visitors see nothing here until they do.', 'vireo-analytics' )
							);
						},
						urlQueryArgs: 'number' === typeof postId ? { post_id: postId } : {},
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
