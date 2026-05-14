/**
 * Evergreen Content Grid — Edit (block editor UI).
 *
 * Pickers: postType → taxonomy → terms. Plus columns / filters / search / perPage.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import {
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	RangeControl,
	ToggleControl,
	TextControl,
	CheckboxControl,
	Spinner,
	Placeholder,
	Button,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

const POST_TYPES_EXCLUDE = [ 'attachment', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' ];

export default function Edit( { attributes, setAttributes } ) {
	const {
		postType,
		taxonomy,
		termIds,
		columns,
		showFilters,
		showSearch,
		perPage,
		heading,
		showLoadMore,
	} = attributes;

	const [ postTypes, setPostTypes ] = useState( [] );
	const [ taxonomies, setTaxonomies ] = useState( [] );
	const [ terms, setTerms ] = useState( [] );
	const [ previewItems, setPreviewItems ] = useState( [] );
	const [ loading, setLoading ] = useState( { postTypes: true, taxonomies: false, terms: false, preview: false } );

	// Load post types once on mount.
	useEffect( () => {
		apiFetch( { path: '/wp/v2/types' } )
			.then( ( res ) => {
				const opts = Object.values( res )
					.filter( ( pt ) => pt.viewable && ! POST_TYPES_EXCLUDE.includes( pt.slug ) )
					.map( ( pt ) => ( { label: pt.name, value: pt.slug } ) );
				setPostTypes( opts );
				setLoading( ( s ) => ( { ...s, postTypes: false } ) );
			} )
			.catch( () => setLoading( ( s ) => ( { ...s, postTypes: false } ) ) );
	}, [] );

	// Load taxonomies when postType changes.
	useEffect( () => {
		if ( ! postType ) {
			setTaxonomies( [] );
			return;
		}
		setLoading( ( s ) => ( { ...s, taxonomies: true } ) );
		apiFetch( { path: `/aladdin-evergreen/v1/taxonomies?post_type=${ encodeURIComponent( postType ) }` } )
			.then( ( res ) => {
				setTaxonomies( res || [] );
				setLoading( ( s ) => ( { ...s, taxonomies: false } ) );
			} )
			.catch( () => setLoading( ( s ) => ( { ...s, taxonomies: false } ) ) );
	}, [ postType ] );

	// Load terms when taxonomy changes.
	useEffect( () => {
		if ( ! taxonomy ) {
			setTerms( [] );
			return;
		}
		setLoading( ( s ) => ( { ...s, terms: true } ) );
		apiFetch( { path: `/aladdin-evergreen/v1/terms?taxonomy=${ encodeURIComponent( taxonomy ) }` } )
			.then( ( res ) => {
				setTerms( res || [] );
				setLoading( ( s ) => ( { ...s, terms: false } ) );
			} )
			.catch( () => setLoading( ( s ) => ( { ...s, terms: false } ) ) );
	}, [ taxonomy ] );

	// Load preview items.
	useEffect( () => {
		if ( ! postType ) return;
		setLoading( ( s ) => ( { ...s, preview: true } ) );
		const params = new URLSearchParams( {
			post_type: postType,
			per_page: Math.min( columns * 2, 6 ),
			taxonomy: taxonomy || '',
			term_ids: ( termIds || [] ).join( ',' ),
		} );
		apiFetch( { path: `/aladdin-evergreen/v1/grid-items?${ params.toString() }` } )
			.then( ( res ) => {
				setPreviewItems( res.items || [] );
				setLoading( ( s ) => ( { ...s, preview: false } ) );
			} )
			.catch( () => setLoading( ( s ) => ( { ...s, preview: false } ) ) );
	}, [ postType, taxonomy, JSON.stringify( termIds ), columns ] );

	const toggleTermId = ( id, checked ) => {
		const next = checked ? [ ...termIds, id ] : termIds.filter( ( t ) => t !== id );
		setAttributes( { termIds: next } );
	};

	const blockProps = useBlockProps( {
		className: `aeg-grid aeg-grid--cols-${ columns }`,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Content Source', 'aladdin-evergreen-grid' ) } initialOpen={ true }>
					<SelectControl
						label={ __( 'Post Type', 'aladdin-evergreen-grid' ) }
						value={ postType }
						options={ loading.postTypes ? [ { label: __( 'Loading…', 'aladdin-evergreen-grid' ), value: '' } ] : postTypes }
						onChange={ ( value ) => setAttributes( { postType: value, taxonomy: '', termIds: [] } ) }
					/>
					<SelectControl
						label={ __( 'Taxonomy (optional)', 'aladdin-evergreen-grid' ) }
						help={ __( 'Pick a taxonomy to enable filter buttons.', 'aladdin-evergreen-grid' ) }
						value={ taxonomy }
						options={ [
							{ label: __( '— None —', 'aladdin-evergreen-grid' ), value: '' },
							...taxonomies.map( ( t ) => ( { label: t.label, value: t.slug } ) ),
						] }
						disabled={ ! postType || loading.taxonomies }
						onChange={ ( value ) => setAttributes( { taxonomy: value, termIds: [] } ) }
					/>
					{ taxonomy && (
						<div style={ { marginTop: 12 } }>
							<strong>{ __( 'Limit to terms (optional)', 'aladdin-evergreen-grid' ) }</strong>
							{ loading.terms && <Spinner /> }
							{ ! loading.terms && terms.length === 0 && (
								<p style={ { fontStyle: 'italic', fontSize: 12, marginTop: 8 } }>
									{ __( 'No terms found for this taxonomy.', 'aladdin-evergreen-grid' ) }
								</p>
							) }
							{ ! loading.terms && terms.map( ( term ) => (
								<CheckboxControl
									key={ term.id }
									label={ `${ term.name } (${ term.count })` }
									checked={ termIds.includes( term.id ) }
									onChange={ ( checked ) => toggleTermId( term.id, checked ) }
								/>
							) ) }
						</div>
					) }
				</PanelBody>

				<PanelBody title={ __( 'Layout', 'aladdin-evergreen-grid' ) } initialOpen={ false }>
					<TextControl
						label={ __( 'Heading (optional)', 'aladdin-evergreen-grid' ) }
						value={ heading }
						onChange={ ( value ) => setAttributes( { heading: value } ) }
					/>
					<RangeControl
						label={ __( 'Columns', 'aladdin-evergreen-grid' ) }
						value={ columns }
						min={ 1 }
						max={ 4 }
						onChange={ ( value ) => setAttributes( { columns: value } ) }
					/>
					<RangeControl
						label={ __( 'Items per page', 'aladdin-evergreen-grid' ) }
						value={ perPage }
						min={ 1 }
						max={ 50 }
						onChange={ ( value ) => setAttributes( { perPage: value } ) }
					/>
				</PanelBody>

				<PanelBody title={ __( 'Controls', 'aladdin-evergreen-grid' ) } initialOpen={ false }>
					<ToggleControl
						label={ __( 'Show search box', 'aladdin-evergreen-grid' ) }
						checked={ showSearch }
						onChange={ ( v ) => setAttributes( { showSearch: v } ) }
					/>
					<ToggleControl
						label={ __( 'Show filter buttons', 'aladdin-evergreen-grid' ) }
						checked={ showFilters }
						onChange={ ( v ) => setAttributes( { showFilters: v } ) }
					/>
					<ToggleControl
						label={ __( 'Show "Load more" button', 'aladdin-evergreen-grid' ) }
						checked={ showLoadMore }
						onChange={ ( v ) => setAttributes( { showLoadMore: v } ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ heading && <h2 className="aeg-grid__heading">{ heading }</h2> }
				{ loading.preview ? (
					<Placeholder
						icon="grid-view"
						label={ __( 'Loading preview…', 'aladdin-evergreen-grid' ) }
					>
						<Spinner />
					</Placeholder>
				) : previewItems.length === 0 ? (
					<Placeholder
						icon="grid-view"
						label={ __( 'No items to preview', 'aladdin-evergreen-grid' ) }
						instructions={ __( 'Pick a post type with published items, or relax your taxonomy filter.', 'aladdin-evergreen-grid' ) }
					/>
				) : (
					<div className="aeg-grid__items" style={ { display: 'grid', gridTemplateColumns: `repeat(${ columns }, 1fr)`, gap: 24 } }>
						{ previewItems.map( ( item ) => (
							<div className="aeg-card" key={ item.id }>
								{ item.thumbnail?.url && (
									<div className="aeg-card__image">
										<img src={ item.thumbnail.url } alt={ item.thumbnail.alt } loading="lazy" />
									</div>
								) }
								<div className="aeg-card__body">
									<h3 className="aeg-card__title">{ item.title }</h3>
									{ item.excerpt && <p className="aeg-card__excerpt">{ item.excerpt }</p> }
									{ item.meta?.time && <span className="aeg-card__meta">{ item.meta.time }</span> }
								</div>
							</div>
						) ) }
					</div>
				) }
			</div>
		</>
	);
}
