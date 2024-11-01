<?php
/*
Plugin Name: WPC Update Variations In Cart for WooCommerce
Plugin URI: https://wpclever.net/
Description: WPC Update Variations In Cart gives your customer power to change variation in cart.
Version: 1.1.3
Author: WPClever
Author URI: https://wpclever.net
Text Domain: wpc-update-variations-in-cart
Domain Path: /languages/
Requires Plugins: woocommerce
Requires at least: 4.0
Tested up to: 6.6
WC requires at least: 3.0
WC tested up to: 9.1
*/

defined( 'ABSPATH' ) || exit;

! defined( 'WPCUV_VERSION' ) && define( 'WPCUV_VERSION', '1.1.3' );
! defined( 'WPCUV_LITE' ) && define( 'WPCUV_LITE', __FILE__ );
! defined( 'WPCUV_FILE' ) && define( 'WPCUV_FILE', __FILE__ );
! defined( 'WPCUV_URI' ) && define( 'WPCUV_URI', plugin_dir_url( __FILE__ ) );
! defined( 'WPCUV_REVIEWS' ) && define( 'WPCUV_REVIEWS', 'https://wordpress.org/support/plugin/wpc-update-variations-in-cart/reviews/?filter=5' );
! defined( 'WPCUV_CHANGELOG' ) && define( 'WPCUV_CHANGELOG', 'https://wordpress.org/plugins/wpc-update-variations-in-cart/#developers' );
! defined( 'WPCUV_DISCUSSION' ) && define( 'WPCUV_DISCUSSION', 'https://wordpress.org/support/plugin/wpc-update-variations-in-cart' );
! defined( 'WPC_URI' ) && define( 'WPC_URI', WPCUV_URI );

include 'includes/dashboard/wpc-dashboard.php';
include 'includes/kit/wpc-kit.php';
include 'includes/hpos.php';

if ( ! function_exists( 'wpcuv_init' ) ) {
	add_action( 'plugins_loaded', 'wpcuv_init', 11 );

	function wpcuv_init() {
		// load text-domain
		load_plugin_textdomain( 'wpc-update-variations-in-cart', false, basename( __DIR__ ) . '/languages/' );

		if ( ! function_exists( 'WC' ) || ! version_compare( WC()->version, '3.0', '>=' ) ) {
			add_action( 'admin_notices', 'wpcuv_notice_wc' );

			return null;
		}

		if ( ! class_exists( 'WPCleverWpcuv' ) && class_exists( 'WC_Product' ) ) {
			class WPCleverWpcuv {
				protected static $instance = null;

				public static function instance() {
					if ( is_null( self::$instance ) ) {
						self::$instance = new self();
					}

					return self::$instance;
				}

				function __construct() {
					// Link
					add_filter( 'plugin_row_meta', [ $this, 'row_meta' ], 10, 2 );

					// Enqueue scripts
					add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

					// Add edit link on cart page
					add_filter( 'woocommerce_cart_item_name', [ $this, 'cart_item_name' ], 20, 3 );

					// Load variation
					add_action( 'wp_ajax_load_variation', [ $this, 'ajax_load_variation' ] );
					add_action( 'wp_ajax_nopriv_load_variation', [ $this, 'ajax_load_variation' ] );

					// Update variation
					add_action( 'wp_ajax_update_variation', [ $this, 'ajax_update_variation' ] );
					add_action( 'wp_ajax_nopriv_update_variation', [ $this, 'ajax_update_variation' ] );
				}

				function row_meta( $links, $file ) {
					static $plugin;

					if ( ! isset( $plugin ) ) {
						$plugin = plugin_basename( __FILE__ );
					}

					if ( $plugin === $file ) {
						$row_meta = [
							'support' => '<a href="' . esc_url( WPCUV_DISCUSSION ) . '" target="_blank">' . esc_html__( 'Community support', 'wpc-update-variations-in-cart' ) . '</a>',
						];

						return array_merge( $links, $row_meta );
					}

					return (array) $links;
				}

				function enqueue_scripts() {
					if ( is_cart() || apply_filters( 'wpcuv_is_cart', false ) ) {
						wp_enqueue_script( 'wc-add-to-cart-variation' );
						wp_enqueue_style( 'wpcuv-frontend', WPCUV_URI . 'assets/css/frontend.css' );
						wp_enqueue_script( 'wpcuv-frontend', WPCUV_URI . 'assets/js/frontend.js', [ 'jquery' ], WPCUV_VERSION, true );
						wp_localize_script( 'wpcuv-frontend', 'wpcuv_vars', [
								'ajax_url'     => admin_url( 'admin-ajax.php' ),
								'nonce'        => wp_create_nonce( 'wpcuv-security' ),
								'update_text'  => esc_html__( 'Update', 'wpc-update-variations-in-cart' ),
								'updated_text' => esc_html__( 'Cart updated.', 'wpc-update-variations-in-cart' ),
							]
						);
					}
				}

				public function cart_item_name( $title, $cart_item, $cart_item_key ) {
					if ( isset( $cart_item['woosb_parent_id'] ) || isset( $cart_item['wooco_parent_id'] ) || isset( $cart_item['woofs_parent_id'] ) || isset( $cart_item['woobt_parent_id'] ) ) {
						// doesn't support special product
						return $title;
					}

					if ( apply_filters( 'wpcuv_ignore', false, $cart_item, $cart_item_key ) ) {
						return $title;
					}

					if ( ! empty( $cart_item['variation'] ) && is_cart() ) {
						return sprintf(
							'%s<br /><span class="wpcuv-edit" id="%s">%s</span>',
							$title,
							$cart_item_key,
							esc_html__( 'Edit', 'wpc-update-variations-in-cart' )
						);
					}

					return $title;
				}

				public function ajax_load_variation() {
					if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'wpcuv-security' ) ) {
						die( 'Permissions check failed!' );
					}

					$current_key          = sanitize_key( $_POST['current_key'] );
					$cart_item            = WC()->cart->get_cart_item( $current_key );
					$variable_product     = wc_get_product( $cart_item['product_id'] );
					$selected_variation   = $cart_item['variation'];
					$selected_qty         = (float) $cart_item['quantity'];
					$available_variations = $variable_product->get_available_variations();
					$attributes           = $variable_product->get_variation_attributes();
					$attribute_keys       = array_keys( $attributes );
					$update_button_text   = esc_html__( 'Update', 'wpc-update-variations-in-cart' );
					$cancel_button_text   = esc_html__( 'Cancel', 'wpc-update-variations-in-cart' );
					?>
                    <tr class="wpcuv-new-item wpcuv-new-item-<?php echo esc_attr( $current_key ); ?>">
                        <td colspan="100%">
                            <table class="wpcuv-editor">
                                <tr>
                                    <td class="wpcuv-thumbnail">
                                        <div class="wpcuv-thumbnail-ori"><?php echo $variable_product->get_image( 'full' ); ?></div>
                                        <div class="wpcuv-thumbnail-new"></div>
                                    </td>
                                    <td class="wpcuv-info">
                                        <h4 class="wpcuv-title">
											<?php echo $variable_product->get_name(); ?>
                                        </h4>
                                        <form class="variations_form cart" method="post" enctype='multipart/form-data' data-product_id="<?php echo absint( $variable_product->get_id() ); ?>" data-product_variations="<?php echo htmlspecialchars( json_encode( $available_variations ) ); ?>">
                                            <table class="variations">
                                                <tbody>
												<?php foreach ( $attributes as $attribute_name => $options ) { ?>
                                                    <tr>
                                                        <td class="label">
                                                            <label for="<?php echo esc_attr( sanitize_title( $attribute_name ) ); ?>">
																<?php echo wc_attribute_label( $attribute_name ); ?>
                                                            </label>
                                                        </td>
                                                        <td class="value">
															<?php
															$selected = $selected_variation[ 'attribute_' . sanitize_title( $attribute_name ) ];

															wc_dropdown_variation_attribute_options( [
																'options'   => $options,
																'attribute' => $attribute_name,
																'product'   => $variable_product,
																'selected'  => $selected,
															] );

															echo end( $attribute_keys ) === $attribute_name ? apply_filters( 'woocommerce_reset_variations_link', '<a class="reset_variations" href="#">' . esc_html__( 'Clear', 'woocommerce' ) . '</a>' ) : '';
															?>
                                                        </td>
                                                    </tr>
												<?php } ?>
                                                </tbody>
                                            </table>
                                            <div class="single_variation_wrap">
                                                <div class="woocommerce-variation single_variation">
                                                    <div class="woocommerce-variation-description"></div>
                                                    <div class="woocommerce-variation-price">
                                                        <span class="price"></span>
                                                    </div>
                                                    <div class="woocommerce-variation-availability"></div>
                                                </div>
                                                <div class="woocommerce-variation-add-to-cart variations_button woocommerce-variation-add-to-cart-enabled">
                                                    <div class="wpcuv-actions">
														<?php
														woocommerce_quantity_input(
															[
																'min_value'   => apply_filters( 'woocommerce_quantity_input_min', $variable_product->get_min_purchase_quantity(), $variable_product ),
																'max_value'   => apply_filters( 'woocommerce_quantity_input_max', $variable_product->get_max_purchase_quantity(), $variable_product ),
																'input_value' => $selected_qty ? wc_stock_amount( wp_unslash( $selected_qty ) ) : $variable_product->get_min_purchase_quantity(),
															], $variable_product
														);
														?>
                                                        <button type="submit" class="single_add_to_cart_button wpcuv-update button">
															<?php echo esc_html( $update_button_text ); ?>
                                                        </button>
                                                        <span class="button button-primary wpcuv-cancel" data-key="<?php echo esc_attr( $current_key ); ?>"><?php echo esc_html( $cancel_button_text ); ?></span>
                                                    </div>
                                                    <input type="hidden" class="product_thumbnail" value="<?php echo htmlentities( $variable_product->get_image() ); ?>"/>
                                                    <input type="hidden" name="add-to-cart" value="<?php echo absint( $variable_product->get_id() ); ?>"/>
                                                    <input type="hidden" name="product_id" value="<?php echo absint( $variable_product->get_id() ); ?>"/>
                                                    <input type="hidden" name="variation_id" class="variation_id" value="0"/>
                                                    <input name="old_key" class="old_key" type="hidden" value="<?php echo esc_attr( $current_key ); ?>"/>
                                                </div>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
					<?php
					wp_die();
				}

				public function ajax_update_variation() {
					if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'wpcuv-security' ) ) {
						die( 'Permissions check failed!' );
					}

					$form_data = sanitize_text_field( $_POST['form_data'] );
					$old_key   = sanitize_key( $_POST['old_key'] );
					parse_str( $form_data, $data );

					if ( ! empty( $data['variation_id'] ) && ( absint( $data['variation_id'] ) > 0 ) ) {
						$variable_product = wc_get_product( $data['variation_id'] );

						if ( $variable_product ) {
							$max_quantity = $variable_product->get_max_purchase_quantity();

							if ( isset( $data['quantity'] ) && ( $max_quantity < 0 || (float) $data['quantity'] <= $max_quantity ) ) {
								// remove old variation when ready to add new variation
								WC()->cart->remove_cart_item( $old_key );
							}
						}
					}

					wp_redirect( sprintf( '%s?%s', wc_get_cart_url(), $form_data ) );
					wp_die();
				}
			}

			return WPCleverWpcuv::instance();
		}

		return null;
	}
}

if ( ! function_exists( 'wpcuv_notice_wc' ) ) {
	function wpcuv_notice_wc() {
		?>
        <div class="error">
            <p><strong>WPC Update Variations In Cart</strong> require WooCommerce version 3.0 or greater.</p>
        </div>
		<?php
	}
}
