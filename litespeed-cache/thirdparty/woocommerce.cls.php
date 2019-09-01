<?php
/**
 * The Third Party integration with the WooCommerce plugin.
 *
 * @since         1.0.5
 * @since  1.6.6 Added function_exists check for compatibility
 * @package       LiteSpeed_Cache
 * @subpackage    LiteSpeed_Cache/thirdparty
 * @author        LiteSpeed Technologies <info@litespeedtech.com>
 */
namespace LiteSpeed\Thirdparty ;

defined( 'WPINC' ) || exit ;

use \LiteSpeed\API ;

class WooCommerce
{
	private static $_instance ;

	const CACHETAG_SHOP = 'WC_S' ;
	const CACHETAG_TERM = 'WC_T.' ;
	const O_UPDATE_INTERVAL = 'wc_update_interval' ;
	const O_SHOP_FRONT_TTL = 'wc_shop_use_front_ttl' ;
	const O_WOO_CACHE_CART = 'woo_cache_cart' ;
	const O_PQS_CS = 0 ; // flush product on quantity + stock change, categories on stock change
	const O_PS_CS = 1 ; // flush product and categories on stock change
	const O_PS_CN = 2 ; // flush product on stock change, categories no flush
	const O_PQS_CQS = 3 ; // flush product and categories on quantity + stock change

	const ESI_PARAM_ARGS = 'wc_args' ;
	const ESI_PARAM_POSTID = 'wc_post_id' ;
	const ESI_PARAM_NAME = 'wc_name' ;
	const ESI_PARAM_PATH = 'wc_path' ;
	const ESI_PARAM_LOCATED = 'wc_located' ;

	private $cache_cart ;
	private $esi_eanbled ;

	/**
	 * Detects if WooCommerce is installed.
	 *
	 * @since 1.0.5
	 * @access public
	 */
	public static function detect()
	{
		if ( ! defined( 'WOOCOMMERCE_VERSION' ) ) {
			return ;
		}

		self::get_instance()->add_hooks() ;

	}

	/**
	 * Add hooks to woo actions
	 *
	 * @since  1.6.3
	 * @access public
	 */
	public function add_hooks()
	{
		$this->cache_cart = API::config( self::O_WOO_CACHE_CART ) ;
		$this->esi_eanbled = API::esi_enabled() ;

		API::hook_control( array( $this, 'set_control' ) ) ;
		API::hook_tag( array( $this, 'set_tag' ) ) ;

		// Purging a product on stock change should only occur during product purchase. This function will add the purging callback when an order is complete.
		add_action( 'woocommerce_product_set_stock', array( $this, 'purge_product' ) ) ;
		add_action( 'woocommerce_variation_set_stock', array( $this, 'purge_product' ) ) ; // #984479 Update variations stock

		$this->_option_append() ;

		add_action( 'comment_post', array( $this, 'add_review' ), 10, 3 ) ;

		if ( $this->esi_eanbled ) {
			if ( function_exists( 'is_shop' ) && ! is_shop() ) {
				API::hook_tpl_not_esi( array( $this, 'set_block_template' ) ) ;
				// No need for add-to-cart button
				// API::hook_tpl_esi( 'wc-add-to-cart-form', array( $this, 'load_add_to_cart_form_block' ) ) ;

				API::hook_tpl_esi( 'storefront-cart-header', array( $this, 'load_cart_header' ) ) ;
				API::hook_tpl_esi( 'widget', array( $this, 'register_post_view' ) ) ;
			}

			if ( function_exists( 'is_product' ) && is_product() ) {
				API::hook_esi_param( array( $this, 'add_post_id' ) ) ;
			}

			/**
			 * Only when cart is not empty, give it an ESI with private cache
			 * Call when template_include to make sure woo cart is initialized
			 * @since  1.7.2
			 */
			add_action( 'template_include', array( $this, 'check_if_need_esi' ) ) ;
			API::hook_vary_finalize( array( $this, 'vary_maintain' ) ) ;

		}

		if ( is_admin() ) {
			API::hook_purge_post( array( $this, 'backend_purge' ) ) ;
			add_action( 'delete_term_relationships', array( $this, 'delete_rel' ), 10, 2 ) ;
			API::hook_setting_tab( array( $this, 'settings_add_tab' ) ) ;
			API::hook_setting_content( array( $this, 'settings_add_content' ) ) ;
			API::hook_widget_default_options( array( $this, 'wc_widget_default' ), 10, 2 ) ;
		}

		// Purge cart if is ESI / Purge private if not enabled ESI
		if ( $this->cache_cart ) {
			$hooks_to_purge = array(
				'woocommerce_add_to_cart', 'woocommerce_ajax_added_to_cart',
				'woocommerce_remove_cart_item',
				'woocommerce_restore_cart_item',
				'woocommerce_after_cart_item_quantity_update',
				'woocommerce_applied_coupon', 'woocommerce_removed_coupon',
				'woocommerce_checkout_order_processed',
			) ;
			foreach ( $hooks_to_purge as $v ) {
				if ( $this->esi_eanbled ) {
					add_action( $v, array( $this, 'purge_esi' ) ) ;
				}
				else {
					add_action( $v, 'LiteSpeed\API::purge_private_all' ) ;
				}
			}
		}

	}

	/**
	 * Purge esi private tag
	 *
	 * @since  1.6.3
	 * @access public
	 */
	public function purge_esi()
	{
		API::debug( '3rd woo purge ESI in action: ' . current_filter() ) ;
		API::purge_private( API::TYPE_ESI . 'storefront-cart-header' ) ;

	}

	/**
	 * Check if need to give an ESI block for cart
	 *
	 * @since  1.7.2
	 * @access public
	 */
	public function check_if_need_esi( $template )
	{
		if (  $this->vary_needed() ) {
			API::debug( 'API: 3rd woo added ESI' ) ;
			API::hook_tpl_not_esi( array( $this, 'set_swap_header_cart' ) ) ;
		}

		return $template ;

	}

	/**
	 * Keep vary on if cart is not empty
	 *
	 * @since  1.7.2
	 * @access public
	 */
	public function vary_maintain( $vary )
	{
		if ( $this->vary_needed() ) {
			API::debug( 'API: 3rd woo added vary due to cart not empty' ) ;
			$vary[ 'woo_cart' ] = 1 ;
		}
		return $vary ;
	}

	/**
	 * Check if vary need to be on based on cart
	 *
	 * @since  1.7.2
	 * @access private
	 */
	private function vary_needed()
	{
		if ( ! function_exists( 'WC' ) ) {
			return false ;
		}

		$woocom = WC() ;
		if ( ! $woocom ) {
			return false ;
		}

		if ( is_null( $woocom->cart ) ) {
			return false ;
		}
		return $woocom->cart->get_cart_contents_count() > 0 ;
	}

	/**
	 * Hooked to the litespeed_is_not_esi_template action.
	 * If the request is not an esi request, I want to set my own hook
	 * in woocommerce_before_template_part to see if it's something I can ESI.
	 *
	 * @since 1.1.0
	 * @since 1.6.3 Removed static
	 * @access public
	 */
	public function set_block_template()
	{
		add_action('woocommerce_before_template_part', array( $this, 'block_template' ), 999, 4) ;
	}

	/**
	 * Hooked to the litespeed_is_not_esi_template action.
	 * If the request is not an esi request, I want to set my own hook
	 * in storefront_header to see if it's something I can ESI.
	 *
	 * Will remove storefront_header_cart in storefront_header.
	 *
	 * @since 1.1.0
	 * @since 1.6.3 Removed static
	 * @access public
	 */
	public function set_swap_header_cart()
	{
		$priority = has_action('storefront_header', 'storefront_header_cart') ;
		if ( $priority !== false ) {
			remove_action('storefront_header', 'storefront_header_cart', $priority) ;
			add_action('storefront_header', array( $this, 'esi_cart_header' ), $priority) ;
		}
	}

	/**
	 * Hooked to the woocommerce_before_template_part action.
	 * Checks if the template contains 'add-to-cart'. If so, and if I
	 * want to ESI the request, block it and build my esi code block.
	 *
	 * The function parameters will be passed to the esi request.
	 *
	 * @since 1.1.0
	 * @since 1.6.3 Removed static
	 * @access public
	 * @global type $post Needed for post id
	 * @param type $template_name
	 * @param type $template_path
	 * @param type $located
	 * @param type $args
	 */
	public function block_template($template_name, $template_path, $located, $args)
	{
		if ( strpos($template_name, 'add-to-cart') === false ) {
			if ( strpos($template_name, 'related.php') !== false ) {
				remove_action('woocommerce_before_template_part', array( $this, 'block_template' ), 999) ;
				add_filter('woocommerce_related_products_args', array( $this, 'add_related_tags' ) ) ;
				add_action('woocommerce_after_template_part', array( $this, 'end_template' ), 999) ;
			}
			return ;
		}
		return ;
		global $post ;
		$params = array(
			self::ESI_PARAM_ARGS => $args,
			self::ESI_PARAM_NAME => $template_name,
			self::ESI_PARAM_POSTID => $post->ID,
			self::ESI_PARAM_PATH => $template_path,
			self::ESI_PARAM_LOCATED => $located
		) ;
		add_action('woocommerce_after_add_to_cart_form', array( $this, 'end_form' ) ) ;
		add_action('woocommerce_after_template_part', array( $this, 'end_form' ), 999) ;
		echo API::esi_url('wc-add-to-cart-form', 'WC_CART_FORM', $params) ;
		echo API::clean_wrapper_begin() ;
	}

	/**
	 * Hooked to the woocommerce_after_add_to_cart_form action.
	 * If this is hit first, clean the buffer and remove this function and
	 * end_template.
	 *
	 * @since 1.1.0
	 * @since 1.6.3 Removed static
	 * @access public
	 */
	public function end_form($template_name = '')
	{
		if ( ! empty($template_name) && strpos($template_name, 'add-to-cart') === false ) {
			return ;
		}
		echo API::clean_wrapper_end() ;
		remove_action('woocommerce_after_add_to_cart_form', array( $this, 'end_form' ) ) ;
		remove_action('woocommerce_after_template_part', array( $this, 'end_form' ), 999) ;
	}

	/**
	 * If related products are loaded, need to add the extra product ids.
	 *
	 * The page will be purged if any of the products are changed.
	 *
	 * @since 1.1.0
	 * @since 1.6.3 Removed static
	 * @access public
	 * @param array $args The arguments used to build the related products section.
	 * @return array The unchanged arguments.
	 */
	public function add_related_tags($args)
	{
		if ( empty($args) || ! isset($args['post__in']) ) {
			return $args ;
		}
		$related_posts = $args['post__in'] ;
		foreach ( $related_posts as $related ) {
			API::tag_add( API::TYPE_POST . $related ) ;
		}
		return $args ;
	}

	/**
	 * Hooked to the woocommerce_after_template_part action.
	 * If the template contains 'add-to-cart', clean the buffer.
	 *
	 * @since 1.1.0
	 * @since 1.6.3 Removed static
	 * @access public
	 * @param type $template_name
	 */
	public function end_template($template_name)
	{
		if ( strpos($template_name, 'related.php') !== false ) {
			remove_action('woocommerce_after_template_part', array( $this, 'end_template' ), 999) ;
			$this->set_block_template() ;
		}
	}

	/**
	 * Hooked to the storefront_header header.
	 * If I want to ESI the request, block it and build my esi code block.
	 *
	 * @since 1.1.0
	 * @since 1.6.3 Removed static
	 * @access public
	 */
	public function esi_cart_header()
	{
		echo API::esi_url( 'storefront-cart-header', 'STOREFRONT_CART_HEADER' ) ;
	}

	/**
	 * Hooked to the litespeed_load_esi_block-storefront-cart-header action.
	 * Generates the cart header for esi display.
	 *
	 * @since 1.1.0
	 * @since 1.6.3 Removed static
	 * @access public
	 */
	public function load_cart_header()
	{
		storefront_header_cart() ;
	}

	/**
	 * Hooked to the litespeed_load_esi_block-wc-add-to-cart-form action.
	 * Parses the esi input parameters and generates the add to cart form
	 * for esi display.
	 *
	 * @since 1.1.0
	 * @since 1.6.3 Removed static
	 * @access public
	 * @global type $post
	 * @global type $wp_query
	 * @param type $params
	 */
	public function load_add_to_cart_form_block($params)
	{
		global $post, $wp_query ;
		$post = get_post($params[self::ESI_PARAM_POSTID]) ;
		$wp_query->setup_postdata($post) ;
		function_exists( 'wc_get_template' ) && wc_get_template($params[self::ESI_PARAM_NAME], $params[self::ESI_PARAM_ARGS], $params[self::ESI_PARAM_PATH]) ;
	}

	/**
	 * Update woocommerce when someone visits a product and has the
	 * recently viewed products widget.
	 *
	 * Currently, this widget should not be cached.
	 *
	 * @since 1.1.0
	 * @since 1.6.3 Removed static
	 * @access public
	 * @param array $params Widget parameter array
	 */
	public function register_post_view($params)
	{
		if ( $params[API::PARAM_NAME] !== 'WC_Widget_Recently_Viewed' ) {
			return ;
		}
		if ( ! isset($params[self::ESI_PARAM_POSTID]) ) {
			return ;
		}
		$id = $params[self::ESI_PARAM_POSTID] ;
		$esi_post = get_post($id) ;
		$product = function_exists( 'wc_get_product' ) ? wc_get_product($esi_post) : false ;

		if ( empty($product) ) {
			return ;
		}

		global $post ;
		$post = $esi_post ;
		function_exists( 'wc_track_product_view' ) && wc_track_product_view() ;
	}

	/**
	 * Adds the post id to the widget ESI parameters for the Recently Viewed widget.
	 *
	 * This is needed in the esi request to update the cookie properly.
	 *
	 * @since 1.1.0
	 * @since 1.6.3 Removed static
	 * @access public
	 * @param array $params The current ESI parameters.
	 * @return array The updated esi parameters.
	 */
	public function add_post_id( $params, $block_id )
	{
		if ( $block_id == 'widget' ) {
			if ( $params[ API::PARAM_NAME ] == 'WC_Widget_Recently_Viewed' ) {
				$params[ self::ESI_PARAM_POSTID ] = get_the_ID() ;
			}
		}

		return $params ;
	}

	/**
	 * Hooked to the litespeed_widget_default_options filter.
	 *
	 * The recently viewed widget must be esi to function properly.
	 * This function will set it to enable and no cache by default.
	 *
	 * @since 1.1.0
	 * @since 1.6.3 Removed static
	 * @access public
	 * @param array $options The current default widget options.
	 * @param type $widget The current widget to configure.
	 * @return array The updated default widget options.
	 */
	public function wc_widget_default($options, $widget)
	{
		if ( ! is_array($options) ) {
			return $options ;
		}
		$widget_name = get_class($widget) ;
		if ( $widget_name === 'WC_Widget_Recently_Viewed' ) {
			$options[API::WIDGET_O_ESIENABLE] = API::VAL_ON2 ;
			$options[API::WIDGET_O_TTL] = 0 ;
		}
		elseif ( $widget_name === 'WC_Widget_Recent_Reviews' ) {
			$options[API::WIDGET_O_ESIENABLE] = API::VAL_ON ;
			$options[API::WIDGET_O_TTL] = 86400 ;
		}
		return $options ;
	}

	/**
	 * Check and set shop front page ttl
	 *
	 * @since 1.1.3
	 * @since 1.6.3 Removed static
	 * @access private
	 */
	private function set_ttl()
	{
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			if ( API::config( self::O_SHOP_FRONT_TTL ) ) {
				API::set_use_frontpage_ttl() ;
			}
		}
	}

	/**
	 * Set WooCommerce cache tags based on page type.
	 *
	 * @since 1.0.9
	 * @since 1.6.3 Removed static
	 * @access public
	 */
	public function set_tag()
	{
		$id = get_the_ID() ;
		if ( $id === false ) {
			return ;
		}

		// Check if product has a cache ttl limit or not
		$sale_from = get_post_meta( $id, '_sale_price_dates_from', true ) ;
		$sale_to = get_post_meta( $id, '_sale_price_dates_to', true ) ;
		$now = current_time( 'timestamp' ) ;
		$ttl = false ;
		if ( $sale_from && $now < $sale_from ) {
			$ttl = $sale_from - $now ;
		}
		elseif ( $sale_to && $now < $sale_to ) {
			$ttl = $sale_to - $now ;
		}
		if ( $ttl && $ttl < API::get_ttl() ) {
			API::debug( "WooCommerce set scheduled TTL to $ttl" ) ;
			API::set_ttl( $ttl ) ;
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			API::tag_add(self::CACHETAG_SHOP) ;
		}
		if ( function_exists( 'is_product_taxonomy' ) && ! is_product_taxonomy() ) {
			return ;
		}
		if ( isset($GLOBALS['product_cat']) ) {
			$term = get_term_by('slug', $GLOBALS['product_cat'], 'product_cat') ;
		}
		elseif ( isset($GLOBALS['product_tag']) ) {
			$term = get_term_by('slug', $GLOBALS['product_tag'], 'product_tag') ;
		}
		else {
			$term = false ;
		}

		if ( $term === false ) {
			return ;
		}
		while ( isset($term) ) {
			API::tag_add(self::CACHETAG_TERM . $term->term_id) ;
			if ( $term->parent == 0 ) {
				break ;
			}
			$term = get_term($term->parent) ;
		}
	}

	/**
	 * Check if the page is cacheable according to WooCommerce.
	 *
	 * @since 1.0.5
	 * @since 1.6.3 Removed static
	 * @access public
     * @param string $esi_id 		The ESI block id if a request is an ESI request.
	 * @return boolean           	True if cacheable, false if not.
	 */
	public function set_control($esi_id)
	{
		if ( API::not_cacheable() ) {
			return ;
		}

		/**
		 * Avoid possible 500 issue
		 * @since 1.6.2.1
		 */
		if ( ! function_exists( 'WC' ) ) {
			return ;
		}

		$woocom = WC() ;
		if ( ! isset($woocom) ) {
			return ;
		}
		$this->set_ttl() ;

		// For later versions, DONOTCACHEPAGE should be set.
		// No need to check uri/qs.
		if ( version_compare($woocom->version, '1.4.2', '>=') ) {
			if ( version_compare( $woocom->version, '3.2.0', '<' ) && defined('DONOTCACHEPAGE') && DONOTCACHEPAGE ) {
				API::debug('3rd party woocommerce not cache by constant') ;
				API::set_nocache() ;
				return ;
			}
			elseif ( version_compare($woocom->version, '2.1.0', '>=') ) {
				$err = false ;

				if ( ! function_exists( 'wc_get_page_id' ) ) {
					return ;
				}
				/**
				 * From woo/inc/class-wc-cache-helper.php:prevent_caching()
				 * @since  1.4
				 */
				$page_ids = array_filter( array( wc_get_page_id( 'cart' ), wc_get_page_id( 'checkout' ), wc_get_page_id( 'myaccount' ) ) );
				if ( isset( $_GET['download_file'] ) || isset( $_GET['add-to-cart'] ) || is_page( $page_ids ) ) {
					$err = 'woo non cacheable pages' ;
				}
				elseif ( is_null($woocom->cart) ) {
					$err = 'null cart' ;
				}
				elseif ( ! $this->esi_eanbled && $woocom->cart->get_cart_contents_count() !== 0 ) {
					if ( $this->cache_cart ) {
						API::set_cache_private() ;
						/**
						 * no rewrite rule to set no vary, so can't set no_vary otherwise it will always miss as can't match vary
						 * @since 1.6.6.1
						 */
						// API::set_cache_no_vary() ;
						API::add_private( API::TYPE_ESI . 'storefront-cart-header' ) ;
					}
					else {
						$err = 'cart is not empty' ;
					}
				}
				elseif ( $esi_id === 'storefront-cart-header' ) {
					if ( $this->cache_cart ) {
						API::set_cache_private() ;
						API::set_cache_no_vary() ;
						API::add_private( API::TYPE_ESI . 'storefront-cart-header' ) ;
					}
					else {
						$err = 'ESI cart should be nocache' ;
					}
				}
				elseif ( function_exists( 'wc_notice_count' ) && wc_notice_count() > 0 ) {
					$err = 'has wc notice' ;
				}

				if ( $err ) {
					API::debug('3rd party woocommerce not cache due to ' . $err) ;
					API::set_nocache() ;
					return ;
				}
			}
			return ;
		}

		$uri = esc_url($_SERVER["REQUEST_URI"]) ;
		$uri_len = strlen($uri) ;
		if ( $uri_len < 5 ) {
			return ;
		}

		if ( in_array($uri, array('cart/', 'checkout/', 'my-account/', 'addons/', 'logout/', 'lost-password/', 'product/')) ) {
			API::set_nocache() ;
			return ;
		}

		$qs = sanitize_text_field($_SERVER["QUERY_STRING"]) ;
		$qs_len = strlen($qs) ;
		if ( ! empty($qs) && $qs_len >= 12 && strpos( $qs, 'add-to-cart=' ) === 0 ) {
			API::set_nocache() ;
			return ;
		}
	}

	/**
	 * Purge a product page and related pages (based on settings) on checkout.
	 *
	 * @since 1.0.9
	 * @since 1.6.3 Removed static
	 * @access public
	 * @param WC_Product $product
	 */
	public function purge_product($product)
	{
		$config = API::config(self::O_UPDATE_INTERVAL) ;
		if ( is_null($config) ) {
			$config = self::O_PQS_CS ;
		}

		if ( $config === self::O_PQS_CQS ) {
			$this->backend_purge($product->get_id()) ;
		}
		elseif ( $config !== self::O_PQS_CS && $product->is_in_stock() ) {
			return ;
		}
		elseif ( $config !== self::O_PS_CN && ! $product->is_in_stock() ) {
			$this->backend_purge($product->get_id()) ;
		}

		API::purge( API::TYPE_POST . $product->get_id() ) ;

		// Check if is variation, purge stock too #984479
		if ( $product->is_type( 'variation' ) ) {
			API::purge( API::TYPE_POST . $product->get_parent_id() ) ;
		}
	}

	/**
	 * Delete object-term relationship. If the post is a product and
	 * the term ids array is not empty, will add purge tags to the deleted
	 * terms.
	 *
	 * @since 1.0.9
	 * @since 1.6.3 Removed static
	 * @access public
	 * @param int $post_id Object ID.
	 * @param array $term_ids An array of term taxonomy IDs.
	 */
	public function delete_rel($post_id, $term_ids)
	{
		if ( ! function_exists( 'wc_get_product' ) ) {
			return ;
		}

		if ( empty($term_ids) || wc_get_product($post_id) === false ) {
			return ;
		}
		foreach ( $term_ids as $term_id ) {
			API::purge(self::CACHETAG_TERM . $term_id) ;
		}
	}

	/**
	 * Purge a product's categories and tags pages in case they are affected.
	 *
	 * @since 1.0.9
	 * @since 1.6.3 Removed static
	 * @access public
	 * @param int $post_id Post id that is about to be purged
	 */
	public function backend_purge($post_id)
	{
		if ( ! function_exists( 'wc_get_product' ) ) {
			return ;
		}

		if ( ! isset($post_id) || wc_get_product($post_id) === false ) {
			return ;
		}

		$cats = $this->get_cats($post_id) ;
		if ( ! empty($cats) ) {
			foreach ( $cats as $cat ) {
				API::purge(self::CACHETAG_TERM . $cat) ;
			}
		}

		if ( ! function_exists( 'wc_get_product_terms' ) ) {
			return ;
		}

		$tags = wc_get_product_terms($post_id, 'product_tag', array('fields' => 'ids')) ;
		if ( ! empty($tags) ) {
			foreach ( $tags as $tag ) {
				API::purge(self::CACHETAG_TERM . $tag) ;
			}
		}
	}

	/**
	 * When a product has a new review added, purge the recent reviews widget.
	 *
	 * @since 1.1.0
	 * @since 1.6.3 Removed static
	 * @access public
	 * @param $unused
	 * @param integer $comment_approved Whether the comment is approved or not.
	 * @param array $commentdata Information about the comment.
	 */
	public function add_review($unused, $comment_approved, $commentdata)
	{
		if ( ! function_exists( 'wc_get_product' ) ) {
			return ;
		}

		$post_id = $commentdata['comment_post_ID'] ;
		if ( $comment_approved !== 1 || ! isset($post_id) || wc_get_product($post_id) === false ) {
			return ;
		}
		global $wp_widget_factory ;
		$recent_reviews = $wp_widget_factory->widgets[ 'WC_Widget_Recent_Reviews' ] ;
		if ( ! is_null( $recent_reviews ) ) {
			API::tag_add( API::TYPE_WIDGET . $recent_reviews->id ) ;
		}
	}

	/**
	 * Append new options
	 *
	 * @since 1.6.3 Removed static
	 * @since  3.0 new API
	 */
	private function _option_append()
	{
		API::conf_append( self::O_UPDATE_INTERVAL, false ) ;
		API::conf_append( self::O_SHOP_FRONT_TTL, true ) ;
		API::conf_append( self::O_WOO_CACHE_CART, true ) ;

		// Append option save value filter
		API::conf_multi_switch( self::O_UPDATE_INTERVAL, 3 ) ;
	}

	/**
	 * Hooked to `litespeed_settings_tab` action.
	 * Adds the integration configuration options (currently, to determine purge rules)
	 *
	 * @since 1.6.3 Removed static
	 */
	public function settings_add_tab( $setting_page )
	{
		if ( $setting_page != 'cache' ) {
			return ;
		}

		require 'woocommerce.tab.tpl.php' ;
	}

	/**
	 * Hook to show config content
	 *
	 * @since  3.0
	 */
	public function settings_add_content( $setting_page )
	{
		if ( $setting_page != 'cache' ) {
			return ;
		}

		require 'woocommerce.content.tpl.php' ;
	}

	/**
	 * Helper function to select the function(s) to use to get the product
	 * category ids.
	 *
	 * @since 1.0.10
	 * @since 1.6.3 Removed static
	 * @access private
	 * @param int $product_id The product id
	 * @return array An array of category ids.
	 */
	private function get_cats($product_id)
	{
		if ( ! function_exists( 'WC' ) ) {
			return ;
		}

		$woocom = WC() ;
		if ( isset($woocom) && version_compare($woocom->version, '2.5.0', '>=') && function_exists( 'wc_get_product_cat_ids' ) ) {
			return wc_get_product_cat_ids($product_id) ;
		}
		$product_cats = wp_get_post_terms($product_id, 'product_cat', array("fields" => "ids")) ;
		foreach ( $product_cats as $product_cat ) {
			$product_cats = array_merge($product_cats, get_ancestors($product_cat, 'product_cat')) ;
		}

		return $product_cats ;
	}

	/**
	 * 3rd party prepload
	 *
	 * @since  2.9.8.4
	 */
	public static function preload()
	{
		/**
		 * Auto puge for WooCommerce Advanced Bulk Edit plugin,
		 * Bulk edit hook need to add to preload as it will die before detect.
		 */
		add_action( 'wp_ajax_wpmelon_adv_bulk_edit', __CLASS__ . '::bulk_edit_purge', 1 ) ;
	}

	/**
	 * Auto puge for WooCommerce Advanced Bulk Edit plugin,
	 *
	 * @since  2.9.8.4
	 */
	public static function bulk_edit_purge()
	{
		if ( empty( $_POST[ 'type' ] ) || $_POST[ 'type' ] != 'saveproducts' || empty( $_POST[ 'data' ] ) ) return ;

		/*
		* admin-ajax form-data structure
		* array(
		*		"type" => "saveproducts",
		*		"data" => array(
		*			"column1" => "464$###0$###2#^#463$###0$###4#^#462$###0$###6#^#",
		*			"column2" => "464$###0$###2#^#463$###0$###4#^#462$###0$###6#^#"
		*		)
		*	)
		*/
		$stock_string_arr = array() ;
		foreach ( $_POST[ 'data' ] as $stock_value ) {
			$stock_string_arr = array_merge( $stock_string_arr, explode( '#^#', $stock_value ) ) ;
		}

		$lscwp_3rd_woocommerce = new self() ;

		if ( count( $stock_string_arr ) < 1 ) {
			return ;
		}

		foreach ( $stock_string_arr as $edited_stock ) {
			$product_id = strtok( $edited_stock, '$' );
			$product = wc_get_product( $product_id ) ;

			if ( empty( $product ) ) {
				API::debug( '3rd woo purge: ' . $product_id . ' not found.' ) ;
				continue ;
			}

			$lscwp_3rd_woocommerce->purge_product( $product );
		}
	}

	/**
	 * Get the current instance object.
	 *
	 * @since 1.6.3
	 * @access public
	 * @return Current class instance.
	 */
	public static function get_instance()
	{
		if ( ! isset( self::$_instance ) ) {
			self::$_instance = new self() ;
		}

		return self::$_instance ;
	}
}

