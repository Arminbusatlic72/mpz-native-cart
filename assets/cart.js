( function ( $, window, document ) {
	'use strict';

	const rootSelector = '#mpz-native-cart';
	let lastEligibility = false;
	let openAfterCartRefresh = false;

	function root() {
		return document.querySelector( rootSelector );
	}

	function isGoalReached() {
		const cart = root();
		return !! cart && cart.dataset.goalReached === '1';
	}

	function closeThemeCart() {
		const astraDrawer = document.getElementById( 'astra-mobile-cart-drawer' );
		if ( astraDrawer ) {
			astraDrawer.classList.remove( 'active', 'woocommerce-active', 'edd-active' );
		}
		document.documentElement.classList.remove( 'ast-mobile-cart-active' );
	}

	function openCart() {
		const cart = root();
		if ( ! cart ) return false;
		closeThemeCart();
		cart.classList.add( 'is-open' );
		cart.setAttribute( 'aria-hidden', 'false' );
		document.documentElement.classList.add( 'mpz-native-cart-open' );
		const close = cart.querySelector( '.mpz-native-cart__header [data-mpz-close]' );
		if ( close ) close.focus();
		// Astra can open its own drawer later in the same add-to-cart event.
		window.setTimeout( closeThemeCart, 0 );
		return true;
	}

	function closeCart() {
		const cart = root();
		if ( ! cart ) return;
		cart.classList.remove( 'is-open' );
		cart.setAttribute( 'aria-hidden', 'true' );
		document.documentElement.classList.remove( 'mpz-native-cart-open' );
	}

	function refreshAfterCartPageUpdate() {
		if ( openAfterCartRefresh ) return;
		openAfterCartRefresh = true;
		$( document.body ).trigger( 'wc_fragment_refresh' );
	}

	$( document.body ).on( 'added_to_cart', function () {
		if ( ! openCart() ) {
			refreshAfterCartPageUpdate();
		}
	} );

	document.body.addEventListener( 'wc-blocks_added_to_cart', function () {
		refreshAfterCartPageUpdate();
	} );

	// The classic WooCommerce cart uses these events for quantity/cart updates.
	$( document.body ).on( 'updated_wc_div updated_cart_totals', function () {
		if ( document.body.classList.contains( 'woocommerce-cart' ) ) {
			refreshAfterCartPageUpdate();
		}
	} );

	$( document.body ).on( 'wc_fragments_refreshed', function () {
		const nowEligible = isGoalReached();
		if ( openAfterCartRefresh && nowEligible && ! lastEligibility ) {
			openCart();
		}
		lastEligibility = nowEligible;
		openAfterCartRefresh = false;
	} );

	// Astra binds the mobile cart handler to a parent wrapper. Capture the
	// trigger first so mobile visitors always see this plugin's full drawer.
	document.addEventListener( 'click', function ( event ) {
		if ( document.body.classList.contains( 'woocommerce-cart' ) || document.body.classList.contains( 'woocommerce-checkout' ) ) {
			return;
		}

		const themeCartTrigger = event.target.closest( '.ast-mobile-header-wrap .ast-header-woo-cart, .ast-desktop-cart-flyout, .ast-slidein-cart' );
		if ( ! themeCartTrigger || event.target.closest( '#mpz-native-cart' ) ) return;

		event.preventDefault();
		event.stopPropagation();
		openCart();
	}, true );

	document.addEventListener( 'astra_on_slide_In_cart_open', function () {
		if ( root() && ! document.body.classList.contains( 'woocommerce-cart' ) && ! document.body.classList.contains( 'woocommerce-checkout' ) ) {
			openCart();
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		const close = event.target.closest( '[data-mpz-close]' );
		if ( close ) {
			event.preventDefault();
			closeCart();
			return;
		}

		const cartLink = event.target.closest( '.site-header-cart a, a.cart-contents, .ast-site-header-cart a' );
		if ( cartLink && ! document.body.classList.contains( 'woocommerce-cart' ) && ! document.body.classList.contains( 'woocommerce-checkout' ) ) {
			event.preventDefault();
			openCart();
			return;
		}

		const sliderButton = event.target.closest( '[data-mpz-slide]' );
		if ( sliderButton ) {
			event.preventDefault();
			const recommendations = sliderButton.closest( '.mpz-native-cart__recommendations' );
			const slider = recommendations ? recommendations.querySelector( '[data-mpz-slider]' ) : null;
			if ( slider ) {
				const direction = sliderButton.dataset.mpzSlide === 'prev' ? -1 : 1;
				const card = slider.querySelector( '.mpz-native-cart__recommendation' );
				const distance = card ? card.getBoundingClientRect().width + 12 : slider.clientWidth * 0.8;
				slider.scrollBy( { left: direction * distance, behavior: 'smooth' } );
			}
			return;
		}

		const quantityButton = event.target.closest( '[data-mpz-quantity]' );
		if ( ! quantityButton ) return;
		event.preventDefault();
		quantityButton.disabled = true;
		const panel = document.querySelector( '#mpz-native-cart-panel' );
		if ( panel ) panel.classList.add( 'is-loading' );

		$.post( mpzNativeCart.ajaxUrl, {
			action: 'mpz_native_cart_update',
			nonce: mpzNativeCart.nonce,
			cart_item_key: quantityButton.dataset.cartKey,
			quantity: quantityButton.dataset.mpzQuantity
		} ).done( function ( response ) {
			if ( response && response.fragments ) {
				$.each( response.fragments, function ( selector, html ) {
					$( selector ).replaceWith( html );
				} );
				$( document.body ).trigger( 'wc_fragments_refreshed' );
				openCart();
			}
		} ).always( function () {
			const currentPanel = document.querySelector( '#mpz-native-cart-panel' );
			if ( currentPanel ) currentPanel.classList.remove( 'is-loading' );
		} );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' ) closeCart();
	} );

	$( function () {
		lastEligibility = isGoalReached();
		try {
			const versionKey = 'mpz_native_cart_version';
			if ( window.localStorage.getItem( versionKey ) !== mpzNativeCart.version ) {
				window.localStorage.setItem( versionKey, mpzNativeCart.version );
				$( document.body ).trigger( 'wc_fragment_refresh' );
			}
		} catch ( error ) {
			// Storage may be unavailable in private mode; cart updates still refresh fragments.
		}
	} );

} )( jQuery, window, document );
