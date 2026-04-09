( function ( wp ) {
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var registerBlockType = wp.blocks.registerBlockType;
	var ServerSideRender = ( wp.serverSideRender && wp.serverSideRender.default ) || wp.serverSideRender;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;
	var ToggleControl = wp.components.ToggleControl;
	var CheckboxControl = wp.components.CheckboxControl;
	var Spinner = wp.components.Spinner;
	var __ = wp.i18n.__;

	var sourceUrl = ( window.vcpaPetCompanion && window.vcpaPetCompanion.sourceUrl ) || '';

	/**
	 * Fetch all terms for a taxonomy from the source site.
	 * Returns a promise resolving to [{ id, name }, ...].
	 */
	function fetchTerms( taxonomy ) {
		if ( ! sourceUrl ) {
			return Promise.resolve( [] );
		}
		return fetch( sourceUrl + '/wp-json/wp/v2/' + taxonomy + '?per_page=100&_fields=id,name,count&hide_empty=true' )
			.then( function ( r ) { return r.ok ? r.json() : []; } )
			.then( function ( terms ) {
				return Array.isArray( terms ) ? terms : [];
			} )
			.catch( function () { return []; } );
	}

	function TermCheckboxList( props ) {
		var terms = props.terms;
		var selected = props.selected || [];

		return el(
			'div',
			{ className: 'vcpa-term-list' },
			terms.map( function ( term ) {
				var checked = selected.indexOf( term.id ) !== -1;
				return el( CheckboxControl, {
					key: term.id,
					label: term.name,
					checked: checked,
					onChange: function ( isChecked ) {
						var next = isChecked
							? selected.concat( [ term.id ] )
							: selected.filter( function ( id ) { return id !== term.id; } );
						props.onChange( next );
					}
				} );
			} )
		);
	}

	registerBlockType( 'vcpa-pet-companion/featured-pets', {
		edit: function ( props ) {
			var attrs = props.attributes;
			var setAttrs = props.setAttributes;
			var blockProps = useBlockProps();

			var animalState = useState( null );
			var animals = animalState[0];
			var setAnimals = animalState[1];

			var attrState = useState( null );
			var petAttrs = attrState[0];
			var setPetAttrs = attrState[1];

			useEffect( function () {
				fetchTerms( 'pet_animal' ).then( setAnimals );
				fetchTerms( 'pet_attribute' ).then( setPetAttrs );
			}, [] );

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Display', 'vcpahumane-pet-companion' ) },
						el( RangeControl, {
							label: __( 'How many to show', 'vcpahumane-pet-companion' ),
							value: attrs.count,
							onChange: function ( v ) { setAttrs( { count: v } ); },
							min: 1,
							max: 12
						} ),
						el( RangeControl, {
							label: __( 'Columns', 'vcpahumane-pet-companion' ),
							value: attrs.columns,
							onChange: function ( v ) { setAttrs( { columns: v } ); },
							min: 1,
							max: 6
						} ),
						el( ToggleControl, {
							label: __( 'Randomize', 'vcpahumane-pet-companion' ),
							checked: !! attrs.random,
							onChange: function ( v ) { setAttrs( { random: v } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Animal type', 'vcpahumane-pet-companion' ), initialOpen: false },
						animals === null
							? el( Spinner, {} )
							: animals.length === 0
								? el( 'p', {}, __( 'No animal types found.', 'vcpahumane-pet-companion' ) )
								: el( Fragment, {},
									el( 'p', { className: 'description' }, __( 'Leave all unchecked to show every type.', 'vcpahumane-pet-companion' ) ),
									el( TermCheckboxList, {
										terms: animals,
										selected: attrs.animals || [],
										onChange: function ( v ) { setAttrs( { animals: v } ); }
									} )
								)
					),
					el(
						PanelBody,
						{ title: __( 'Attributes', 'vcpahumane-pet-companion' ), initialOpen: false },
						petAttrs === null
							? el( Spinner, {} )
							: petAttrs.length === 0
								? el( 'p', {}, __( 'No attributes found.', 'vcpahumane-pet-companion' ) )
								: el( Fragment, {},
									el( 'p', { className: 'description' }, __( 'Pets matching any selected attribute will be shown.', 'vcpahumane-pet-companion' ) ),
									el( TermCheckboxList, {
										terms: petAttrs,
										selected: attrs.petAttributes || [],
										onChange: function ( v ) { setAttrs( { petAttributes: v } ); }
									} )
								)
					)
				),
				el( ServerSideRender, {
					block: 'vcpa-pet-companion/featured-pets',
					attributes: attrs
				} )
			);
		},
		save: function () { return null; }
	} );
} )( window.wp );
