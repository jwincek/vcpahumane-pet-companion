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
	var TextControl = wp.components.TextControl;
	var Button = wp.components.Button;
	var Spinner = wp.components.Spinner;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var __ = wp.i18n.__;

	var sourceUrl = ( window.vcpaPetCompanion && window.vcpaPetCompanion.sourceUrl ) || '';

	/**
	 * Search the source for pets matching `query`. Returns up to 10 results
	 * with the fields we need to display the picker and to cache name/image.
	 */
	function searchPets( query ) {
		if ( ! sourceUrl || ! query ) {
			return Promise.resolve( [] );
		}
		var url = sourceUrl
			+ '/wp-json/wp/v2/pet?per_page=10&_embed=wp:featuredmedia&search='
			+ encodeURIComponent( query );

		return fetch( url )
			.then( function ( r ) { return r.ok ? r.json() : []; } )
			.then( function ( items ) {
				return ( Array.isArray( items ) ? items : [] ).map( function ( raw ) {
					var media = raw._embedded
						&& raw._embedded['wp:featuredmedia']
						&& raw._embedded['wp:featuredmedia'][0];
					var sizes = ( media && media.media_details && media.media_details.sizes ) || {};
					var img = ( sizes.thumbnail && sizes.thumbnail.source_url )
						|| ( sizes.medium && sizes.medium.source_url )
						|| ( media && media.source_url )
						|| '';
					var fullImg = ( sizes.medium_large && sizes.medium_large.source_url )
						|| ( sizes.medium && sizes.medium.source_url )
						|| ( media && media.source_url )
						|| '';
					return {
						id: raw.id,
						slug: raw.slug,
						name: ( raw.title && raw.title.rendered ) ? raw.title.rendered.replace( /<[^>]*>/g, '' ) : '',
						thumb: img,
						image: fullImg,
						alt: ( media && media.alt_text ) || ''
					};
				} );
			} )
			.catch( function () { return []; } );
	}

	registerBlockType( 'vcpa-pet-companion/pet-card', {
		edit: function ( props ) {
			var attrs = props.attributes;
			var setAttrs = props.setAttributes;
			var blockProps = useBlockProps();

			var queryState = useState( '' );
			var query = queryState[0];
			var setQuery = queryState[1];

			var resultsState = useState( null );
			var results = resultsState[0];
			var setResults = resultsState[1];

			var loadingState = useState( false );
			var loading = loadingState[0];
			var setLoading = loadingState[1];

			// Debounced search.
			useEffect( function () {
				if ( ! query || query.length < 2 ) {
					setResults( null );
					return;
				}
				setLoading( true );
				var timer = setTimeout( function () {
					searchPets( query ).then( function ( items ) {
						setResults( items );
						setLoading( false );
					} );
				}, 300 );
				return function () { clearTimeout( timer ); };
			}, [ query ] );

			function pickPet( pet ) {
				setAttrs( {
					petId: pet.id,
					petSlug: pet.slug,
					petName: pet.name,
					petImage: pet.image,
					petAlt: pet.alt
				} );
				setQuery( '' );
				setResults( null );
			}

			function clearPet() {
				setAttrs( { petId: 0, petSlug: '', petName: '', petImage: '', petAlt: '' } );
			}

			var hasPet = !! ( attrs.petId || attrs.petSlug );

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Layout', 'vcpahumane-pet-companion' ) },
						el( SelectControl, {
							label: __( 'Image position', 'vcpahumane-pet-companion' ),
							value: attrs.imagePosition || 'left',
							options: [
								{ label: __( 'Left of text', 'vcpahumane-pet-companion' ), value: 'left' },
								{ label: __( 'Above text', 'vcpahumane-pet-companion' ), value: 'top' }
							],
							onChange: function ( v ) { setAttrs( { imagePosition: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show full story', 'vcpahumane-pet-companion' ),
							help: __( 'Use the shelter\u2019s full writeup. Turn off for a shorter excerpt.', 'vcpahumane-pet-companion' ),
							checked: !! attrs.showFullStory,
							onChange: function ( v ) { setAttrs( { showFullStory: v } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Pet to display', 'vcpahumane-pet-companion' ) },
						hasPet && el(
							'div',
							{ className: 'vcpa-pet-picker__current' },
							attrs.petImage && el( 'img', {
								src: attrs.petImage,
								alt: attrs.petAlt || attrs.petName,
								style: { maxWidth: '100%', height: 'auto', borderRadius: '6px', marginBottom: '0.5rem' }
							} ),
							el( 'p', { style: { margin: '0 0 0.5rem' } },
								el( 'strong', {}, attrs.petName || ( '#' + attrs.petId ) )
							),
							el( Button, { variant: 'secondary', onClick: clearPet, isSmall: true },
								__( 'Pick a different pet', 'vcpahumane-pet-companion' )
							)
						),
						! hasPet && el( Fragment, {},
							el( TextControl, {
								label: __( 'Search by name', 'vcpahumane-pet-companion' ),
								value: query,
								onChange: setQuery,
								placeholder: __( 'Type a pet\u2019s name\u2026', 'vcpahumane-pet-companion' )
							} ),
							loading && el( Spinner, {} ),
							results !== null && results.length === 0 && ! loading
								&& el( 'p', { className: 'description' }, __( 'No pets matched.', 'vcpahumane-pet-companion' ) ),
							results && results.length > 0 && el(
								'ul',
								{ className: 'vcpa-pet-picker__results', style: { listStyle: 'none', margin: 0, padding: 0 } },
								results.map( function ( pet ) {
									return el(
										'li',
										{
											key: pet.id,
											style: {
												display: 'flex',
												alignItems: 'center',
												gap: '0.6rem',
												padding: '0.4rem',
												cursor: 'pointer',
												borderRadius: '4px'
											},
											onClick: function () { pickPet( pet ); }
										},
										pet.thumb && el( 'img', {
											src: pet.thumb,
											alt: '',
											style: { width: '40px', height: '40px', objectFit: 'cover', borderRadius: '4px' }
										} ),
										el( 'span', {}, pet.name )
									);
								} )
							)
						)
					)
				),
				hasPet
					? el( ServerSideRender, {
						block: 'vcpa-pet-companion/pet-card',
						attributes: attrs
					} )
					: el(
						'div',
						{ className: 'vcpa-pet-empty', style: { padding: '1.5rem', textAlign: 'center', border: '1px dashed #ccc', borderRadius: '8px' } },
						__( 'Search for a pet in the block sidebar.', 'vcpahumane-pet-companion' )
					)
			);
		},
		save: function () { return null; }
	} );
} )( window.wp );
