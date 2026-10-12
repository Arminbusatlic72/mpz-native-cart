<?php
/**
 * Plugin Name: Pametna korpa za WooCommerce
 * Description: Dodaje bočnu WooCommerce korpu, prikaz napretka do besplatne dostave i pametne preporuke proizvoda.
 * Version: 1.5.5
 * Author: Moj Prvi Zalogaj
 * Requires Plugins: woocommerce
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.9
 * Text Domain: mpz-native-cart
 */

defined( 'ABSPATH' ) || exit;

final class MPZ_Native_Cart {
	const VERSION         = '1.5.5';
	const OPTION          = 'mpz_native_cart_settings';
	const PAIR_CACHE_KEY  = 'mpz_native_cart_purchase_pairs_v1';
	const PAIR_CACHE_TIME = WEEK_IN_SECONDS;

	public static function init() {
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'boot' ) );
	}

	public static function declare_compatibility() {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}

	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_drawer' ), 30 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'cart_fragments' ) );
		add_action( 'wp_ajax_mpz_native_cart_update', array( __CLASS__, 'ajax_update_cart' ) );
		add_action( 'wp_ajax_nopriv_mpz_native_cart_update', array( __CLASS__, 'ajax_update_cart' ) );
	}

	private static function defaults() {
		return array(
			'enabled'                  => 'yes',
			'activation_threshold'     => '5000',
			'recommendation_count'     => '4',
			'manual_product_ids'       => '',
			'goal_message'             => 'Dodajte još %s za besplatnu dostavu',
			'complete_message'         => 'Otključali ste besplatnu dostavu!',
			'recommendations_heading'  => 'Možda će vam se dopasti',
		);
	}

	private static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function admin_menu() {
		add_submenu_page(
			'woocommerce',
			'Pametna korpa za WooCommerce',
			'Pametna korpa',
			'manage_woocommerce',
			'mpz-native-cart',
			array( __CLASS__, 'settings_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'mpz_native_cart_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize_settings( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();

		return array(
			'enabled'                 => ! empty( $input['enabled'] ) ? 'yes' : 'no',
			'activation_threshold'    => isset( $input['activation_threshold'] ) ? (string) max( 0, (float) wc_format_decimal( $input['activation_threshold'] ) ) : $defaults['activation_threshold'],
			'recommendation_count'    => isset( $input['recommendation_count'] ) ? (string) min( 8, max( 1, absint( $input['recommendation_count'] ) ) ) : $defaults['recommendation_count'],
			'manual_product_ids'      => isset( $input['manual_product_ids'] ) ? implode( ',', array_filter( array_map( 'absint', explode( ',', $input['manual_product_ids'] ) ) ) ) : '',
			'goal_message'            => isset( $input['goal_message'] ) ? sanitize_text_field( $input['goal_message'] ) : $defaults['goal_message'],
			'complete_message'        => isset( $input['complete_message'] ) ? sanitize_text_field( $input['complete_message'] ) : $defaults['complete_message'],
			'recommendations_heading' => isset( $input['recommendations_heading'] ) ? sanitize_text_field( $input['recommendations_heading'] ) : $defaults['recommendations_heading'],
		);
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$s = self::settings();
		?>
		<div class="wrap">
			<h1>Pametna korpa za WooCommerce</h1>
			<p>Podesite bočnu korpu, cilj za besplatnu dostavu i preporučene proizvode.</p>
			<p><strong>Redosled preporuka:</strong> WooCommerce cross-sells, ručno odabrani proizvodi za kampanju, proizvodi koji se često kupuju zajedno, povezani proizvodi i najprodavaniji proizvodi.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'mpz_native_cart_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Uključi bočnu korpu</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="1" <?php checked( $s['enabled'], 'yes' ); ?>> Aktivno</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="mpz-activation-threshold">Ciljni iznos za besplatnu dostavu</label></th>
						<td><input id="mpz-activation-threshold" class="regular-text" type="number" min="0" step="0.01" name="<?php echo esc_attr( self::OPTION ); ?>[activation_threshold]" value="<?php echo esc_attr( $s['activation_threshold'] ); ?>"> <span class="description"><?php echo esc_html( get_woocommerce_currency() ); ?></span><p class="description">Korpa uvijek prikazuje slider preporuka. Ispod ovog iznosa prikazuje koliko još nedostaje do besplatne dostave, a nakon dostizanja cilja prikazuje poruku o uspjehu.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="mpz-count">Broj preporuka</label></th>
						<td><input id="mpz-count" type="number" min="1" max="8" name="<?php echo esc_attr( self::OPTION ); ?>[recommendation_count]" value="<?php echo esc_attr( $s['recommendation_count'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="mpz-products">Ručno odabrani proizvodi</label></th>
						<td><input id="mpz-products" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION ); ?>[manual_product_ids]" value="<?php echo esc_attr( $s['manual_product_ids'] ); ?>"><p class="description">ID brojevi proizvoda za kampanju, odvojeni zarezima. Koriste se kada statistika i cross-sells ne popune sva mesta.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="mpz-goal">Poruka pre cilja</label></th>
						<td><input id="mpz-goal" class="large-text" type="text" name="<?php echo esc_attr( self::OPTION ); ?>[goal_message]" value="<?php echo esc_attr( $s['goal_message'] ); ?>"><p class="description">Koristite %s na mestu preostalog iznosa.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="mpz-complete">Poruka kada je cilj dostignut</label></th>
						<td><input id="mpz-complete" class="large-text" type="text" name="<?php echo esc_attr( self::OPTION ); ?>[complete_message]" value="<?php echo esc_attr( $s['complete_message'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="mpz-heading">Naslov preporuka</label></th>
						<td><input id="mpz-heading" class="large-text" type="text" name="<?php echo esc_attr( self::OPTION ); ?>[recommendations_heading]" value="<?php echo esc_attr( $s['recommendations_heading'] ); ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function enqueue_assets() {
		$s = self::settings();
		if ( is_admin() || 'yes' !== $s['enabled'] ) {
			return;
		}

		wp_enqueue_style( 'mpz-native-cart', plugins_url( 'assets/cart.css', __FILE__ ), array(), self::VERSION );
		wp_enqueue_script( 'mpz-native-cart', plugins_url( 'assets/cart.js', __FILE__ ), array( 'jquery', 'wc-add-to-cart', 'wc-cart-fragments' ), self::VERSION, true );
		wp_localize_script(
			'mpz-native-cart',
			'mpzNativeCart',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mpz-native-cart' ),
				'version' => self::VERSION,
			)
		);
	}

	private static function cart_subtotal() {
		if ( ! WC()->cart ) {
			return 0.0;
		}

		// Match WooCommerce's free-shipping calculation: use the subtotal as
		// displayed to the customer and apply coupon discounts including tax.
		$subtotal = (float) WC()->cart->get_displayed_subtotal();
		$subtotal -= (float) WC()->cart->get_discount_total();

		if ( WC()->cart->display_prices_including_tax() ) {
			$subtotal -= (float) WC()->cart->get_discount_tax();
		}

		return max( 0, round( $subtotal, wc_get_price_decimals() ) );
	}

	private static function cart_is_eligible() {
		$s         = self::settings();
		$threshold = max( 0, (float) $s['activation_threshold'] );

		return $threshold <= 0 || self::cart_subtotal() >= $threshold;
	}

	private static function progress_data() {
		$s         = self::settings();
		$threshold = max( 0, (float) $s['activation_threshold'] );
		$subtotal  = self::cart_subtotal();
		$remaining = max( 0, $threshold - $subtotal );
		$percent   = $threshold > 0 ? min( 100, ( $subtotal / $threshold ) * 100 ) : 100;

		if ( $remaining > 0 ) {
			$remaining_price = wc_price(
				$remaining,
				array(
					'currency'     => 'RSD',
					'price_format' => '%2$s RSD',
				)
			);
			$message = sprintf( $s['goal_message'], wp_strip_all_tags( $remaining_price ) );
		} else {
			$message = $s['complete_message'];
		}

		return array(
			'message' => $message,
			'percent' => $percent,
		);
	}

	private static function valid_recommendation_ids( $ids, $cart_ids ) {
		$ids = array_values( array_unique( array_diff( array_filter( array_map( 'absint', $ids ) ), $cart_ids ) ) );

		return array_values(
			array_filter(
				$ids,
				function ( $product_id ) {
					$product = wc_get_product( $product_id );
					return $product && $product->is_visible() && $product->is_purchasable() && $product->is_in_stock();
				}
			)
		);
	}

	/**
	 * Build an anonymous product-pair frequency map from recent paid orders.
	 *
	 * Customer details are never read or stored. The result is cached for a week
	 * so recommendation rendering remains inexpensive on normal page requests.
	 */
	private static function purchase_pair_map() {
		$cached = get_transient( self::PAIR_CACHE_KEY );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$pairs     = array();
		$order_ids = wc_get_orders(
			array(
				'status'       => array( 'wc-processing', 'wc-completed' ),
				'limit'        => 300,
				'return'       => 'ids',
				'orderby'      => 'date',
				'order'        => 'DESC',
				'date_created' => '>' . ( time() - YEAR_IN_SECONDS ),
			)
		);

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$product_ids = array();
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$product_id = absint( $item->get_product_id() );
				if ( $product_id ) {
					$product_ids[] = $product_id;
				}
			}

			// Unique IDs avoid quantity inflating affinity; the cap prevents pathological orders.
			$product_ids = array_slice( array_values( array_unique( $product_ids ) ), 0, 30 );
			$count       = count( $product_ids );
			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$left  = $product_ids[ $i ];
					$right = $product_ids[ $j ];
					$pairs[ $left ][ $right ] = isset( $pairs[ $left ][ $right ] ) ? $pairs[ $left ][ $right ] + 1 : 1;
					$pairs[ $right ][ $left ] = isset( $pairs[ $right ][ $left ] ) ? $pairs[ $right ][ $left ] + 1 : 1;
				}
			}
		}

		set_transient( self::PAIR_CACHE_KEY, $pairs, self::PAIR_CACHE_TIME );
		return $pairs;
	}

	private static function frequently_bought_together_ids( $cart_ids, $limit ) {
		$pairs  = self::purchase_pair_map();
		$scores = array();

		foreach ( $cart_ids as $cart_product_id ) {
			if ( empty( $pairs[ $cart_product_id ] ) ) {
				continue;
			}
			foreach ( $pairs[ $cart_product_id ] as $candidate_id => $pair_count ) {
				$candidate_id = absint( $candidate_id );
				if ( $pair_count < 2 || in_array( $candidate_id, $cart_ids, true ) ) {
					continue;
				}
				$scores[ $candidate_id ] = isset( $scores[ $candidate_id ] ) ? $scores[ $candidate_id ] + $pair_count : $pair_count;
			}
		}

		arsort( $scores, SORT_NUMERIC );
		return array_slice( array_keys( $scores ), 0, $limit );
	}

	private static function recommendation_ids() {
		$s        = self::settings();
		$limit    = max( 1, absint( $s['recommendation_count'] ) );
		$cart_ids = array();
		$ids      = array();

		if ( ! WC()->cart ) {
			return $ids;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			$cart_ids[] = (int) $item['product_id'];
		}

		// Explicit product-level cross-sells are the merchant's highest-priority choice.
		$ids = array_map( 'absint', WC()->cart->get_cross_sells() );
		$ids = self::valid_recommendation_ids( $ids, $cart_ids );

		// Global manual products are the merchant's second-priority campaign choices.
		if ( count( $ids ) < $limit && ! empty( $s['manual_product_ids'] ) ) {
			$ids = array_merge( $ids, array_map( 'absint', explode( ',', $s['manual_product_ids'] ) ) );
			$ids = self::valid_recommendation_ids( $ids, $cart_ids );
		}

		// Purchase-history affinity fills positions not occupied by explicit choices.
		if ( count( $ids ) < $limit && ! empty( $cart_ids ) ) {
			$ids = array_merge( $ids, self::frequently_bought_together_ids( $cart_ids, $limit ) );
			$ids = self::valid_recommendation_ids( $ids, $cart_ids );
		}

		// Then use WooCommerce related products from shared categories/tags.
		if ( count( $ids ) < $limit ) {
			foreach ( $cart_ids as $product_id ) {
				if ( count( $ids ) >= $limit ) {
					break;
				}
				$ids = array_merge( $ids, wc_get_related_products( $product_id, $limit * 2, array_merge( $cart_ids, $ids ) ) );
				$ids = self::valid_recommendation_ids( $ids, $cart_ids );
			}
		}

		// Popular products are the final fallback, so the slider remains full.
		if ( count( $ids ) < $limit ) {
			$popular_ids = get_posts(
				array(
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'posts_per_page'         => $limit * 3,
					'fields'                 => 'ids',
					'post__not_in'           => array_merge( $cart_ids, $ids ),
					'meta_key'               => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'orderby'                => 'meta_value_num',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			$ids = array_merge( $ids, array_map( 'absint', $popular_ids ) );
		}

		return array_slice( self::valid_recommendation_ids( $ids, $cart_ids ), 0, $limit );
	}

	private static function progress_markup() {
		$p = self::progress_data();
		ob_start();
		?>
		<div class="mpz-native-cart__goal" aria-live="polite">
			<strong><?php echo esc_html( $p['message'] ); ?></strong>
			<div class="mpz-native-cart__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( round( $p['percent'] ) ); ?>">
				<span style="width: <?php echo esc_attr( $p['percent'] ); ?>%"></span>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function recommendations_markup( $context = 'drawer' ) {
		$ids = self::recommendation_ids();
		$s   = self::settings();
		if ( empty( $ids ) ) {
			return '';
		}

		ob_start();
		?>
		<section class="mpz-native-cart__recommendations mpz-native-cart__recommendations--<?php echo esc_attr( $context ); ?>">
			<div class="mpz-native-cart__recommendations-header">
				<h3><?php echo esc_html( $s['recommendations_heading'] ); ?></h3>
				<?php if ( count( $ids ) > 1 ) : ?>
					<div class="mpz-native-cart__slider-controls" aria-label="Navigacija preporučenih proizvoda">
						<button type="button" data-mpz-slide="prev" aria-label="Prethodni proizvodi">←</button>
						<button type="button" data-mpz-slide="next" aria-label="Sledeći proizvodi">→</button>
					</div>
				<?php endif; ?>
			</div>
			<div class="mpz-native-cart__recommendation-list" data-mpz-slider tabindex="0">
				<?php foreach ( $ids as $product_id ) :
					$product = wc_get_product( $product_id );
					if ( ! $product ) {
						continue;
					}
					?>
					<article class="mpz-native-cart__recommendation">
						<a class="mpz-native-cart__recommendation-image" href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?></a>
						<div class="mpz-native-cart__recommendation-copy">
							<a href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
							<span><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
						</div>
						<?php if ( $product->is_type( 'simple' ) ) : ?>
							<a href="<?php echo esc_url( $product->add_to_cart_url() ); ?>" data-quantity="1" data-product_id="<?php echo esc_attr( $product_id ); ?>" class="button add_to_cart_button ajax_add_to_cart mpz-native-cart__add" aria-label="<?php echo esc_attr( sprintf( 'Dodaj %s u korpu', $product->get_name() ) ); ?>">+</a>
						<?php else : ?>
							<a href="<?php echo esc_url( $product->get_permalink() ); ?>" class="button mpz-native-cart__add" aria-label="<?php echo esc_attr( sprintf( 'Izaberi opcije za %s', $product->get_name() ) ); ?>">→</a>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	private static function drawer_content() {
		ob_start();
		?>
		<div class="mpz-native-cart__content">
			<?php echo self::progress_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="mpz-native-cart__items">
				<?php if ( WC()->cart && ! WC()->cart->is_empty() ) : ?>
					<?php foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :
						$product = $cart_item['data'];
						if ( ! $product || ! $product->exists() ) {
							continue;
						}
						?>
						<article class="mpz-native-cart__item">
							<a class="mpz-native-cart__item-image" href="<?php echo esc_url( $product->get_permalink( $cart_item ) ); ?>"><?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?></a>
							<div class="mpz-native-cart__item-copy">
								<a class="mpz-native-cart__item-name" href="<?php echo esc_url( $product->get_permalink( $cart_item ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
								<?php echo wp_kses_post( wc_get_formatted_cart_item_data( $cart_item ) ); ?>
								<span class="mpz-native-cart__item-price"><?php echo wp_kses_post( WC()->cart->get_product_price( $product ) ); ?></span>
								<div class="mpz-native-cart__quantity">
									<button type="button" data-mpz-quantity="<?php echo esc_attr( max( 0, $cart_item['quantity'] - 1 ) ); ?>" data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>" aria-label="Smanji količinu">−</button>
									<span><?php echo esc_html( $cart_item['quantity'] ); ?></span>
									<button type="button" data-mpz-quantity="<?php echo esc_attr( $cart_item['quantity'] + 1 ); ?>" data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>" aria-label="Povećaj količinu">+</button>
								</div>
							</div>
							<button type="button" class="mpz-native-cart__remove" data-mpz-quantity="0" data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>" aria-label="Ukloni <?php echo esc_attr( $product->get_name() ); ?>">×</button>
						</article>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="mpz-native-cart__empty"><p>Vaša korpa je prazna.</p><a class="button" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">Pogledajte proizvode</a></div>
				<?php endif; ?>
			</div>
			<?php echo self::recommendations_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( WC()->cart && ! WC()->cart->is_empty() ) : ?>
				<footer class="mpz-native-cart__footer">
					<div><strong>Ukupno</strong><strong><?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?></strong></div>
					<a class="button alt" href="<?php echo esc_url( wc_get_checkout_url() ); ?>">Nastavite na plaćanje</a>
					<small>Dostava se obračunava na stranici za plaćanje.</small>
				</footer>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function drawer_markup() {
		$s = self::settings();
		if ( 'yes' !== $s['enabled'] ) {
			return '';
		}

		$is_eligible = self::cart_is_eligible();
		ob_start();
		?>
		<div id="mpz-native-cart" class="mpz-native-cart" data-goal-reached="<?php echo $is_eligible ? '1' : '0'; ?>" aria-hidden="true">
			<button class="mpz-native-cart__backdrop" type="button" data-mpz-close aria-label="Zatvori korpu"></button>
			<aside class="mpz-native-cart__drawer" role="dialog" aria-modal="true" aria-labelledby="mpz-native-cart-title">
				<header class="mpz-native-cart__header">
					<h2 id="mpz-native-cart-title">Vaša korpa</h2>
					<button type="button" data-mpz-close aria-label="Zatvori korpu">×</button>
				</header>
				<div id="mpz-native-cart-panel"><?php echo self::drawer_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</aside>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function render_drawer() {
		echo self::drawer_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function cart_fragments( $fragments ) {
		$fragments['#mpz-native-cart'] = self::drawer_markup();
		return $fragments;
	}

	public static function ajax_update_cart() {
		check_ajax_referer( 'mpz-native-cart', 'nonce' );
		$cart_item_key = isset( $_POST['cart_item_key'] ) ? wc_clean( wp_unslash( $_POST['cart_item_key'] ) ) : '';
		$quantity      = isset( $_POST['quantity'] ) ? max( 0, absint( $_POST['quantity'] ) ) : 0;

		if ( ! WC()->cart || ! $cart_item_key || ! isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
			wp_send_json_error( array( 'message' => 'Stavka nije pronađena.' ), 404 );
		}

		WC()->cart->set_quantity( $cart_item_key, $quantity, true );
		WC()->cart->calculate_totals();
		WC_AJAX::get_refreshed_fragments();
	}
}

MPZ_Native_Cart::init();
