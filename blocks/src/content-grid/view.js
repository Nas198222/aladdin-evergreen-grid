/**
 * Aladdin Evergreen Grid — Frontend hydration (vanilla JS, no jQuery).
 *
 * Picks up every .aeg-grid wrapper rendered by the PHP render_callback and:
 *   - wires up filter buttons (debounced)
 *   - wires up the search box (debounced)
 *   - handles "Load more" pagination
 *   - shows skeleton loaders during fetch
 *   - shows error retry state on failure
 *
 * The PHP render_callback already produced the first page of items, so the JS
 * only refetches when the user changes a filter, types in search, or pages.
 */
import './style.scss';

// Localized from PHP via wp_localize_script (window.aegConfig).
const CONFIG = ( typeof window !== 'undefined' && window.aegConfig ) || {};
const REST_BASE = ( CONFIG.restUrl || '/wp-json/aladdin-evergreen/v1' ).replace( /\/+$/, '' );
const STRINGS = Object.assign(
	{
		tryAgain: 'Try again',
		couldNotLoad: 'Could not load items.',
		noItems: 'No items found.',
	},
	CONFIG.i18n || {}
);

// Whitelist of URL schemes safe to put in href/src.
const SAFE_SCHEMES = /^(https?:|mailto:|tel:|\/|#)/i;
const safeUrl = ( url ) => {
	if ( typeof url !== 'string' ) return '';
	const trimmed = url.trim();
	if ( ! trimmed ) return '';
	return SAFE_SCHEMES.test( trimmed ) ? trimmed : '';
};

const debounce = ( fn, ms ) => {
	let t;
	return ( ...args ) => {
		clearTimeout( t );
		t = setTimeout( () => fn( ...args ), ms );
	};
};

const escapeHtml = ( str ) =>
	String( str )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#39;' );

const renderSkeleton = ( count ) => {
	let html = '';
	for ( let i = 0; i < count; i++ ) {
		html += `<div class="aeg-card aeg-card--skeleton" aria-hidden="true">
			<div class="aeg-card__image-skeleton"></div>
			<div class="aeg-card__line-skeleton"></div>
			<div class="aeg-card__line-skeleton aeg-card__line-skeleton--short"></div>
		</div>`;
	}
	return html;
};

const renderRecipeMeta = ( meta ) => {
	const parts = [];
	if ( meta?.time ) parts.push( `<span class="aeg-card__time">${ escapeHtml( meta.time ) }</span>` );
	// 0-rating is rendered intentionally — visible "not yet rated" state.
	if ( meta?.rating != null ) parts.push( `<span class="aeg-card__rating">★ ${ Number( meta.rating ).toFixed( 1 ) }</span>` );
	if ( Array.isArray( meta?.diet ) && meta.diet.length ) {
		parts.push( `<span class="aeg-card__diet">${ escapeHtml( meta.diet[ 0 ] ) }</span>` );
	}
	return parts.join( '' );
};

const renderProductMeta = ( meta ) => {
	const parts = [];
	// PHP side already formats price via wc_price() with the right currency symbol.
	if ( meta?.price ) parts.push( `<span class="aeg-card__price">${ escapeHtml( meta.price ) }</span>` );
	return parts.join( '' );
};

const renderDefaultMeta = () => '';

const renderMeta = ( postType, meta ) => {
	if ( postType === 'wprm_recipe' ) return renderRecipeMeta( meta );
	if ( postType === 'product' ) return renderProductMeta( meta );
	return renderDefaultMeta( meta );
};

const renderCard = ( item, postType, eager = false ) => {
	const url = safeUrl( item.thumbnail?.url );
	const link = safeUrl( item.link );
	const w = Number( item.thumbnail?.w ) > 0 ? Number( item.thumbnail.w ) : 800;
	const h = Number( item.thumbnail?.h ) > 0 ? Number( item.thumbnail.h ) : 600;
	const loading = eager ? 'eager' : 'lazy';
	const priority = eager ? ' fetchpriority="high"' : '';

	// Lazy-loader exclusion classes match the SSR side (EWWW, Perfmatters).
	const img = url
		? `<div class="aeg-card__image"><img src="${ escapeHtml( url ) }" alt="${ escapeHtml( item.thumbnail?.alt || item.title || '' ) }" class="skip-lazy no-lazy aeg-card__img" data-no-lazy="1" loading="${ loading }" decoding="async"${ priority } width="${ w }" height="${ h }" /></div>`
		: '';
	const meta = renderMeta( postType, item.meta );

	return `<article class="aeg-card-wrap" role="listitem"><a class="aeg-card" href="${ escapeHtml( link ) }">
		${ img }
		<div class="aeg-card__body">
			<h3 class="aeg-card__title">${ escapeHtml( item.title ) }</h3>
			${ item.excerpt ? `<p class="aeg-card__excerpt">${ escapeHtml( item.excerpt ) }</p>` : '' }
			${ meta ? `<div class="aeg-card__meta-row">${ meta }</div>` : '' }
		</div>
	</a></article>`;
};

const renderError = ( msg ) =>
	`<div class="aeg-grid__error">
		<p>${ escapeHtml( msg ) }</p>
		<button class="aeg-grid__retry" type="button">${ escapeHtml( STRINGS.tryAgain ) }</button>
	</div>`;

class AEG_Grid {
	constructor( root ) {
		this.root = root;
		this.postType = root.dataset.postType || '';
		this.taxonomy = root.dataset.taxonomy || '';
		this.termIdsBase = ( root.dataset.termIds || '' ).split( ',' ).filter( Boolean ).map( Number ).filter( ( n ) => Number.isFinite( n ) );
		this.columns = parseInt( root.dataset.columns, 10 ) || 3;
		this.perPage = parseInt( root.dataset.perPage, 10 ) || 12;
		this.showLoadMore = root.dataset.showLoadMore === '1';

		this.itemsEl = root.querySelector( '.aeg-grid__items' );
		this.searchEl = root.querySelector( '.aeg-grid__search' );
		this.filterEls = Array.from( root.querySelectorAll( '.aeg-grid__filter' ) );
		this.loadMoreEl = root.querySelector( '.aeg-grid__load-more' );

		// Bail gracefully if the markup doesn't match what we expect (mis-pasted HTML, etc.).
		if ( ! this.itemsEl ) {
			return;
		}

		// Wire aria-controls so screen readers know which region the controls govern.
		if ( ! this.itemsEl.id ) {
			this.itemsEl.id = `aeg-items-${ Math.random().toString( 36 ).slice( 2, 9 ) }`;
		}
		if ( this.searchEl ) this.searchEl.setAttribute( 'aria-controls', this.itemsEl.id );
		this.filterEls.forEach( ( b ) => b.setAttribute( 'aria-controls', this.itemsEl.id ) );

		this.state = {
			search: '',
			termId: '',
			page: 1,
		};

		this.inflight = null; // AbortController for the active fetch

		// PHP already SSR'd the first page — don't refetch on mount.
		this.bind();
	}

	bind() {
		if ( this.searchEl ) {
			const onSearch = debounce( ( e ) => {
				this.state.search = e.target.value;
				this.state.page = 1;
				this.fetchInitial();
			}, 300 );
			this.searchEl.addEventListener( 'input', onSearch );
		}

		this.filterEls.forEach( ( btn ) => {
			// Wire initial aria-pressed state.
			btn.setAttribute( 'aria-pressed', btn.classList.contains( 'aeg-grid__filter--active' ) ? 'true' : 'false' );

			btn.addEventListener( 'click', () => {
				this.filterEls.forEach( ( b ) => {
					b.classList.remove( 'aeg-grid__filter--active' );
					b.setAttribute( 'aria-pressed', 'false' );
				} );
				btn.classList.add( 'aeg-grid__filter--active' );
				btn.setAttribute( 'aria-pressed', 'true' );
				this.state.termId = btn.dataset.termId || '';
				this.state.page = 1;
				this.fetchInitial();
			} );
		} );

		if ( this.loadMoreEl ) {
			this.loadMoreEl.addEventListener( 'click', () => {
				if ( this.loadMoreEl.disabled ) return; // prevent double-clicks
				this.loadMoreEl.disabled = true;
				this.state.page += 1;
				this.fetchPage( true ).finally( () => {
					this.loadMoreEl.disabled = false;
				} );
			} );
		}
	}

	buildParams() {
		const params = new URLSearchParams();
		params.set( 'post_type', this.postType );
		if ( this.taxonomy ) params.set( 'taxonomy', this.taxonomy );

		const termIds = this.state.termId
			? [ Number( this.state.termId ) ]
			: this.termIdsBase;
		if ( termIds.length ) params.set( 'term_ids', termIds.join( ',' ) );

		if ( this.state.search ) params.set( 'search', this.state.search );
		params.set( 'per_page', String( this.perPage ) );
		params.set( 'page', String( this.state.page ) );
		return params;
	}

	async fetchInitial() {
		this.itemsEl.innerHTML = renderSkeleton( Math.min( this.columns * 2, this.perPage ) );
		this.itemsEl.setAttribute( 'aria-busy', 'true' );
		await this.fetchPage( false );
	}

	async fetchPage( append ) {
		// Cancel any in-flight request before starting a new one.
		if ( this.inflight ) {
			this.inflight.abort();
		}
		const controller = new AbortController();
		this.inflight = controller;

		const params = this.buildParams();
		try {
			const res = await fetch( `${ REST_BASE }/grid-items?${ params.toString() }`, {
				headers: { Accept: 'application/json' },
				signal: controller.signal,
			} );
			if ( ! res.ok ) {
				throw new Error( `HTTP ${ res.status }` );
			}
			const data = await res.json();
			// If a newer request started while we were awaiting, drop this result.
			if ( this.inflight !== controller ) return;
			this.renderItems( data, append );
		} catch ( err ) {
			if ( err.name === 'AbortError' ) return; // Superseded — ignore.
			if ( append ) {
				// Failed load-more: roll back page counter, leave existing items intact.
				this.state.page = Math.max( 1, this.state.page - 1 );
				if ( this.loadMoreEl ) this.loadMoreEl.hidden = false;
			} else {
				// Initial/filter/search failure: replace the grid with retry UI.
				this.itemsEl.innerHTML = renderError( STRINGS.couldNotLoad );
				const retry = this.itemsEl.querySelector( '.aeg-grid__retry' );
				if ( retry ) {
					retry.addEventListener( 'click', () => {
						this.state.page = 1;
						this.fetchInitial();
					} );
				}
			}
		} finally {
			if ( this.inflight === controller ) {
				this.inflight = null;
				this.itemsEl.setAttribute( 'aria-busy', 'false' );
			}
		}
	}

	renderItems( data, append ) {
		const items = data.items || [];
		// Append fetches are below-fold by definition → never eager.
		const html = items.map( ( i, idx ) => renderCard( i, this.postType, ! append && idx < 2 ) ).join( '' );

		if ( append ) {
			this.itemsEl.insertAdjacentHTML( 'beforeend', html );
		} else {
			this.itemsEl.innerHTML = html || `<p class="aeg-grid__empty">${ escapeHtml( STRINGS.noItems ) }</p>`;
		}

		if ( this.loadMoreEl ) {
			const hasMore = data.pagination && data.pagination.current < data.pagination.total_pages;
			if ( hasMore ) {
				this.loadMoreEl.hidden = false;
			} else {
				this.loadMoreEl.hidden = true;
			}
		}
	}
}

const boot = () => {
	document.querySelectorAll( '.aeg-grid' ).forEach( ( el ) => {
		if ( ! el.__aegInit ) {
			el.__aegInit = true;
			new AEG_Grid( el );
		}
	} );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
