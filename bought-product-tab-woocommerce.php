<?php
/**
 *  Bought Product Tab for WooCommerce
 *
 * @package bought-product-tab-woocommerce
 * @author  Shadowfied <admin@shadfieowfied.com>
 * @copyright  2018 Shadowfied
 * @license  GPL-3.0
 *
 * @wordpress-plugin
 * Plugin Name: Bought Product Tab for WooCommerce
 * Description: Add a tab to WooCommerce products but content is only visible for accounts that has bought the product
 * Version: 1.0.0
 * Author: Shadowfied
 * Author URI: https://shadfieowfied.com
 * Text Domain: bought-product-tab-woocommerce
 * License: GPL-3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Include to use is_plugin_active
include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
	return SHADFIE_Bought_Product_Tab::instance();
} else {
	deactivate_plugins( '/bought-product-tab-woocommerce/bought-product-tab-woocommerce.php' );
	add_action( 'admin_notices', 'shadfie_display_woo_error' );
}

function shadfie_display_woo_error() {
	?>
	<style>
		#message.updated {
			display: none;
		}
	</style>

	<div class="error">
		<p><?php _e( 'Bought Product Tab for WooCommerce could not be activated as it requires WooCommerce to be installed and activated', 'bought-product-tab-woocommerce' ); ?></p>
		<p><?php _e( 'Please install and activate ', 'bought-product-tab-woocommerce' ); ?><a href="<?php echo admin_url( 'plugin-install.php?tab=search&type=term&s=WooCommerce' ); ?>">WooCommerce</a><?php _e( ' in order to activate this plugin.', 'bought-product-tab-woocommerce' ); ?></p>
	</div>
	<?php
}


/**
 * Database:
 * - Postmeta named 'shadfie_bought_product_tab_content' attached to products containing the
 * 	 content of the new tab the plugin adds
 *
 *  WooCommerce: 
 *  - Tab named 'shadfie_woocommerce_tab'
 *  
 * @since 1.0
 */
class SHADFIE_Bought_Product_Tab{
	protected static $instance;


	/**
	 * Setup the class
	 */
	public function __construct() {
		add_action( 'current_screen', array( $this, 'content_meta_box' ) );
		
		add_filter( 'woocommerce_product_tabs', array( $this, 'woocommerce_tab' ) );
		
		/* Make sure WooCommerce Tab Manager doesn't remove the tab */
		add_filter( 'woocommerce_tab_manager_integration_tab_allowed', '__false' );
	}


	/**
	 * Create an instance of the class
	 * @return 	BPT_Bought_Product_Tab
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Add a meta box for the tab content
	 * @param  	WP_Screen $current_screen
	 * @return 	void
	 */
	public function content_meta_box( $current_screen ) {
		/* Check that current screen is of the post type product */
		if ( $current_screen->post_type === 'product' ) {

			/* Add a meta box for the content to be displayed if the product has been purchased by the current user */
			add_action( 'add_meta_boxes',  function() { 
				add_meta_box( 'shadfie_bought_product_tab', 'Bought Tab Content', array( $this, 'content_meta_box_output' ) );
			});

			/* Save the boxes content when the post updates */
			add_action( 'save_post', function( $post_id ) {
				$data = $_POST[ 'shadfie_bought_product_tab_input' ];

				/* Save the data */
				update_post_meta( $post_id, 'shadfie_bought_product_tab_content', $data );
			}); 
		}
	}


	/**
	 * Turn the meta box into a TinyMCE WYSIWYG
	 * @param  WP_Post $post
	 * @return void
	 */
	public function content_meta_box_output( $post ) {
		/* Get the currently stored text */
		$content = get_post_meta( $post->ID, 'shadfie_bought_product_tab_content' , true );

		/* Turn the meta box into a TinyMCE WYSIWYG editor */
		wp_editor( htmlspecialchars_decode( $content ), 'shadfie_bought_product_tab_editor', $settings = array( 'textarea_name'=>'shadfie_bought_product_tab_input' ) );
	}


	/**
	 * Register the new WooCommerce Tab
	 * @param  	array $tabs
	 * @return 	array
	 */
	public function woocommerce_tab( $tabs ) {
		if ( empty( get_post_meta( get_the_ID(), 'shadfie_bought_product_tab_content', true ) ) ) {
				return $tabs;
		}

		$tabs['shadfie_woocommerce_tab'] = array(
			'title' 	=> __( 'Extra info', 'bought-product-tab-woocommerce' ),
			'priority'	=> 30,
			'callback' 	=> array( $this, 'tab_output' )
		);

		return $tabs;
	}


	/**
	 * Return the contents of the new WooCommerce Tab
	 * @return 	void
	 */
	public function tab_output() {
		if ( $this->has_bought_items( [ get_the_ID() ] ) ) {
			echo get_post_meta( get_the_ID(), 'shadfie_bought_product_tab_content', true );
		} else {
			_e( "The contents of this tab will be visible once you purchase the current product.", 'bought-product-tab-woocommerce' );
		}
	}


	/**
	 * Function to check if the current user has bought the item, credits to LoicTheAztec on 
	 * StackOverflow (https://stackoverflow.com/a/38772202)
	 * @param 	array $ids
	 * @return 	boolean
	 */
	private function has_bought_items( $ids ) {
		$bought = false;

		// Get all customer orders
		$customer_orders = get_posts( array(
			'numberposts' => -1,
			'meta_key'    => '_customer_user',
			'meta_value'  => get_current_user_id(),
			'post_type'   => 'shop_order', // WC orders post type
			'post_status' => 'wc-completed' // Only orders with status "completed"
		) );
		foreach ( $customer_orders as $customer_order ) {
			// Updated compatibility with WooCommerce 3+
			$order_id = method_exists( $order, 'get_id' ) ? $order->get_id() : $order->id;
			$order = wc_get_order( $customer_order );

			// Iterating through each current customer products bought in the order
			foreach ($order->get_items() as $item) {
				// WC 3+ compatibility
				if ( version_compare( WC_VERSION, '3.0', '<' ) ) 
					$product_id = $item['product_id'];
				else
					$product_id = $item->get_product_id();

				// Your condition related to your 2 specific products Ids
				if ( in_array( $product_id, $ids ) ) 
					$bought = true;
			}
		}
		// return "true" if one the specifics products have been bought before by customer
		return $bought;
	}

	
}