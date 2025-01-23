<?php

if ( !defined('ABSPATH') ){ die(); } //Exit if accessed directly

if ( !trait_exists('Functions') ){
	trait Functions {
		public $slug_keywords = false; //Start with this false for 404 pages
		public $twitter_widget_loaded = false;
		public $linkedin_widget_loaded = false;
		public $pinterest_pin_widget_loaded = false;
		public $current_theme_template;
		public $error_query;

		public function hooks(){
			global $pagenow;

			add_action('template_redirect', array($this, 'set_content_width'));
			add_action('after_setup_theme', array($this, 'theme_setup'));
			add_filter('site_icon_image_sizes', array($this, 'site_icon_sizes'));
			add_filter('get_site_icon_url', array($this, 'add_query_to_icon_url'));
			add_filter('image_size_names_choose', array($this, 'image_size_human_names'));
			add_action('rest_api_init', array($this, 'rest_api_routes'));
			add_action('wp_head', array($this, 'add_back_post_feed'));
			add_action('init', array($this, 'set_default_timezone'), 1); //WP Health Check does not like this, but date() times break without this

			if ( $this->get_option('console_css') && !$this->is_background_request() ){
				add_action('wp_head', array($this, 'calling_card'));
			}

			if ( is_writable(get_template_directory()) ){
				if ( !file_exists($this->manifest_json_location(false)) || filemtime($this->manifest_json_location(false)) < (time()-DAY_IN_SECONDS) || $this->is_debug() ){ //If the manifest file does not exist, or last modified time is older than 24 hours re-write it
					add_action('init', array($this, 'manifest_json'));
				}
			}

			if ( $this->get_option('service_worker') && is_writable(get_home_path()) ){
				if ( file_exists($this->sw_location(false)) ){
					add_action('save_post', array($this, 'update_sw_js'));
				}
			}

			add_action('init', array($this, 'index_now_rewrite_rule'));
			add_action('wp', array($this, 'index_now_output_key'));
			add_action('nebula_options_saved', array($this, 'index_now_generate_key')); //This will only actually re-generate if an IndexNow key does not already exist

			add_action('wp_loaded', array($this, 'favicon_cache'));
			add_action('after_setup_theme', array($this, 'nav_menu_locations'));
			add_filter('nav_menu_link_attributes', array($this, 'add_menu_attributes'), 10, 3);

			add_action('admin_init', array($this, 'disable_trackbacks'));
			add_action('template_include', array($this, 'define_current_template'), 1000);
			add_action('wp_ajax_nebula_twitter_cache', array($this, 'twitter_cache'));
			add_action('wp_ajax_nopriv_nebula_twitter_cache', array($this, 'twitter_cache'));
			add_filter('get_search_form', array($this, 'search_form'), 100, 1);
			add_filter('the_password_form', array($this, 'password_form_simplify'));
			add_filter('the_posts', array($this, 'always_get_post_custom'));
			add_action('pre_get_posts', array($this, 'redirect_empty_search'));
			add_action('template_redirect', array($this, 'redirect_single_search_result'));
			add_filter('relevanssi_post_ok', array($this, 'exclude_from_relevanssi'), 10, 2);

			if ( !$this->is_background_request() && !$this->is_admin_page() ){
				add_action('wp_head', array($this, 'arbitrary_code_head'), 1000);
				add_action('nebula_body_open', array($this, 'arbitrary_code_body'), 1000);
				add_action('wp_footer', array($this, 'arbitrary_code_footer'), 1000);

				add_filter('single_template', array($this, 'single_category_template'));

				add_action('wp_head', array($this, 'internal_suggestions'));
				add_filter('body_class', array($this, 'body_classes'));
				add_filter('post_class', array($this, 'post_classes'));
				add_filter('wp_get_attachment_url', array($this, 'wp_get_attachment_url_force_protocol'));
				add_filter('embed_oembed_html', array($this, 'oembed_modifiers'), 9999, 4);
			}

			add_action('wp_ajax_nebula_infinite_load', array($this, 'infinite_load'));
			add_action('wp_ajax_nopriv_nebula_infinite_load', array($this, 'infinite_load'));

			add_filter('acf/settings/google_api_key', array($this, 'acf_google_api_key')); //ACF hook

			if ( is_plugin_active('wordpress-seo/wp-seo.php') ){ //If Yoast is active
				add_filter('wpseo_metadesc', array($this, 'meta_description')); //Yoast hook
			}

			if ( is_user_logged_in() ){
				add_filter('wpcf7_verify_nonce', '__return_true'); //Always verify CF7 nonce for logged-in users (this allows for it to detect user data)
			}
			add_filter('wpcf7_form_elements', array($this, 'cf7_autocomplete_attribute'));
			add_filter('wpcf7_special_mail_tags', array($this, 'cf7_custom_special_mail_tags'), 10, 3);
			add_action('wpcf7_mail_failed', array($this, 'cf7_note_mail_failed'));

			if ( is_plugin_active('contact-form-7/wp-contact-form-7.php') && $this->get_option('store_form_submissions') ){ //If CF7 is installed and active and capturing submission data is enabled
				add_action('init', array($this, 'cf7_storage_taxonomies')); //Custom Post Type and Custom Status
				add_filter('wpcf7_posted_data', array($this, 'cf7_enhance_data')); //Add more context for CF7 form submissions
				add_action('wpcf7_submit', array($this, 'cf7_storage'), 2, 2); //Store CF7 submissions as a CPT (formerly hooked on wpcf7_before_send_mail)
			}

			if ( $this->is_bypass_cache() ){
				if ( !defined('DONOTCACHEPAGE') ){
					define('DONOTCACHEPAGE', true); //Tell other plugins not to cache this page
				}

				add_filter('style_loader_src', array($this, 'add_debug_query_args'), 500, 2);
				add_filter('script_loader_src', array($this, 'add_debug_query_args'), 500, 2);
				add_action('send_headers', array($this, 'clear_site_data'));
				add_action('send_headers', 'nocache_headers'); //WP Core function that adds nocache headers
				add_action('shutdown', array($this, 'flush_rewrite_on_debug')); //Just on debug, not when auditing
			}
		}

		//Adjust the content width when the full width page template is being used
		public function set_content_width(){
			$override = apply_filters('pre_nebula_set_content_width', false);
			if ( $override !== false ){return $override;}

			//$content_width is a global variable used by WordPress for max image upload sizes and media embeds (in pixels).
			global $content_width;

			//If the content area is 960px wide, set $content_width = 940; so images and videos will not overflow.
			if ( !isset($content_width) ){
				$content_width = 710;
			}

			if ( is_page_template('fullwidth.php') ){
				$content_width = 1040;
			}
		}

		//Check if the Nebula Companion plugin is installed and active
		public function is_companion_active(){
			include_once ABSPATH . 'wp-admin/includes/plugin.php'; //Needed to use is_plugin_active() outside of WP admin
			if ( is_plugin_active('nebula-companion/nebula-companion.php') || is_plugin_active('Nebula-Companion-main/nebula-companion.php') ){
				return true;
			}

			return false;
		}

		//Prep custom theme support
		public function theme_setup(){
			//Additions
			add_theme_support('post-thumbnails');
			add_theme_support('custom-logo'); //Custom logo support.
			add_theme_support('title-tag'); //Title tag support allows WordPress core to create the <title> tag.
			//add_theme_support('html5', array('comment-list', 'comment-form', 'search-form', 'gallery', 'caption'));
			add_theme_support('automatic-feed-links'); //Add default posts and comments RSS feed links to head

			add_theme_support('responsive-embeds');
			add_theme_support('wp-block-styles');
			add_theme_support('align-wide'); //Wide image alignment

			//Custom color palette to Gutenberg editor
			add_theme_support('editor-color-palette', array(
				array(
					'name' => 'Primary',
					'slug' => 'primary',
					'color' => get_theme_mod('nebula_primary_color', $this->get_color('$primary_color')),
				),
				array(
					'name' => 'Secondary',
					'slug' => 'secondary',
					'color' => get_theme_mod('nebula_secondary_color', $this->get_color('$secondary_color')),
				)
			));

			add_post_type_support('page', 'excerpt'); //Allow pages to have excerpts too

			//Removals
			remove_theme_support('custom-background');
			remove_theme_support('custom-header');

			//Remove capital P core function
			remove_filter('the_title', 'capital_P_dangit', 11);
			remove_filter('the_content', 'capital_P_dangit', 11);
			remove_filter('comment_text', 'capital_P_dangit', 31);

			//Head information
			remove_action('wp_head', 'rsd_link'); //Remove the link to the Really Simple Discovery service endpoint and EditURI link (third-party editing APIs)
			remove_action('wp_head', 'wp_generator'); //Removes the WordPress XHTML Generator meta tag and WP version
			remove_action('wp_head', 'wp_shortlink_wp_head'); //Removes the shortlink tag in the head
			remove_action('wp_head', 'feed_links', 2); //Remove the links to the general feeds: Post and Comment Feed
			remove_action('wp_head', 'wlwmanifest_link'); //Remove the link to the Windows Live Writer manifest file
			remove_action('wp_head', 'feed_links_extra', 3); //Remove the links to the extra feeds such as category feeds
			remove_action('wp_head', 'index_rel_link'); //Remove index link (deprecated?)
			remove_action('wp_head', 'start_post_rel_link', 10, 0); //Remove start link
			remove_action('wp_head', 'parent_post_rel_link', 10, 0); //Remove previous link
			remove_action('wp_head', 'adjacent_posts_rel_link', 10, 0); //Remove relational links for the posts adjacent to the current post

			//Add new image sizes (Given human-readible names in another function below)
			//"max_size" custom image size is defined in /libs/Optimization.php
			add_image_size('square', 512, 512, 1);
			add_image_size('open_graph_large', 1200, 630, 1);
			add_image_size('open_graph_small', 600, 315, 1);
			add_image_size('twitter_large', 280, 150, 1);
			add_image_size('twitter_small', 200, 200, 1);
		}

		//Give custom Nebula image sizes human readable names
		public function image_size_human_names($sizes){
			return array_merge($sizes, array(
				'square' => 'Square',
				'open_graph_large' => 'Open Graph (Large)',
				'open_graph_small' => 'Open Graph (Small)',
				'twitter_large' => 'Twitter (Large)',
				'twitter_small' => 'Twitter (Small)',
			));
		}

		//Add custom meta icon (favicon) sizes when the site_icon is used via the Customizer
		public function site_icon_sizes($core_sizes){
			$nebula_sizes = array(16, 32, 70, 150, 180, 192, 310);
			$all_sizes = array_unique(array_merge($core_sizes, $nebula_sizes));
			return $all_sizes;
		}

		//Add a version query string to favicons
		public function add_query_to_icon_url($url){
			if ( strpos($url, 'ver=') !== false ){ //If the version number already exists (not likely), just return it //@todo "Nebula" 0: Update strpos() to str_contains() in PHP8
				return $url;
			}

			$cache_query = 'ver=' . nebula()->child_version('full'); //Start with the child theme version number that is similarly used for other assets (including the /inc/metadata.php template)
			if ( nebula()->is_debug() ){
				$cache_query = 'nocache' . random_int(100000, 999999) . '=debug' . random_int(100000, 999999); //Add a random query string when debugging to force-clear the cache.
			}

			if ( strpos($url, '?') !== false ){ //If the URL already has a "?" append using "&" //@todo "Nebula" 0: Update strpos() to str_contains() in PHP8
				$url .= '&' . $cache_query;
			} else {
				$url .= '?' . $cache_query;
			}

			return $url;
		}

		//Register REST API routes/endpoints
		public function rest_api_routes(){
			register_rest_route('nebula/v2', '/autocomplete_search/', array('methods' => 'GET', 'callback' => array($this, 'rest_autocomplete_search'), 'permission_callback' => '__return_true')); //.../wp-json/nebula/v2/autocomplete_search?term=whatever&types=post|page
		}

		//Add the Posts RSS Feed back in
		public function add_back_post_feed(){
			echo '<link rel="alternate" type="application/rss+xml" title="RSS 2.0 Feed" href="' . get_bloginfo('rss2_url') . '" />';
		}

		//Set server timezone to match Wordpress
		public function set_default_timezone(){
			if ( $this->get_option('force_wp_timezone') ){
				//@todo "Nebula" 0: Use null coalescing operator here if possible
				$timezone_option = wp_timezone_string();

				//If that returns an offset instead of a named timezone
				if ( strpos($timezone_option, ':') !== false ){ //@todo "Nebula" 0: Update strpos() to str_contains() in PHP8
					$date_timezone = wp_timezone();
					if ( !empty($date_timezone->timezone) ){
						$timezone_option = $date_timezone['timezone'];
					}
				}

				//If we still do not have a usable option, default to Eastern Time
				if ( empty($timezone_option) ){
					$timezone_option = 'America/New_York';
				}

				date_default_timezone_set($timezone_option); //@todo "Nebula" 0: WordPress Health Check does not like this... but date() is wrong (uses UTC) without it...
			}
		}

		//Add the Nebula note to the browser console (if enabled)
		public function calling_card(){
			if ( $this->is_desktop() && !is_customize_preview() ){
				echo "<script>console.log('%c Created using Nebula " . esc_html($this->version('primary')) . "', 'padding: 2px 10px; background: #0098d7; color: #fff;');</script>";
			}
		}

		//Get the location URI of the Service Worker JavaScript file.
		//Override this in your child theme if changing the location or filename of the service worker.
		public function sw_location($uri=true){
			$override = apply_filters('pre_sw_location', null, $uri);
			if ( isset($override) ){return $override;}

			if ( !empty($uri) ){
				return get_site_url() . '/sw.js';
			}

			return get_home_path() . 'sw.js';
		}

		//Update variables within the service worker JavaScript file for install caching
		public function update_sw_js($version=false){
			$this->timer('Update SW');

			$override = apply_filters('pre_nebula_update_swjs', null);
			if ( isset($override) ){return;}

			if ( empty($version) ){
				$version = apply_filters('nebula_sw_cache_version', $version);
			}

			WP_Filesystem();
			global $wp_filesystem;
			$sw_js = $wp_filesystem->get_contents($this->sw_location(false));

			if ( !empty($sw_js) ){
				$find = array(
					"/(const THEME_NAME = ')(.+)(';)/m",
					"/(const NEBULA_VERSION = ')(.+)(';)(.+$)?/m",
					"/(const OFFLINE_URL = ')(.+)(';)/m",
					"/(const OFFLINE_IMG = ')(.+)(';)/m",
					"/(const META_ICON = ')(.+)(';)/m",
					"/(const MANIFEST = ')(.+)(';)/m",
					"/(const HOME_URL = ')(.+)(';)/m",
					"/(const START_URL = ')(.+)(';)/m",
				);

				//$new_cache_name = "nebula-" . strtolower(get_option('stylesheet')) . "-" . random_int(100000, 999999); //PHP 7.4 use numeric separators here

				$replace = array(
					"$1" . strtolower(get_option('stylesheet')) . "$3",
					"$1" . 'v' . $version . "$3 //" . date('l, F j, Y g:i:s A'),
					"$1" . home_url('/') . "offline/$3",
					"$1" . get_theme_file_uri('/assets/img') . "/offline.svg$3",
					"$1" . get_theme_file_uri('/assets/img/meta') . "/android-chrome-512x512.png$3",
					"$1" . $this->manifest_json_location() . "$3",
					"$1" . home_url('/') . "$3",
					"$1" . home_url('/') . "?utm_source=pwa$3", //If getting "start_url does not respond" when offline in Lighthouse, make sure you are not disabling the cache in DevTools Network tab!
				);

				$sw_js = preg_replace($find, $replace, $sw_js);
				$update_sw_js = $wp_filesystem->put_contents($this->sw_location(false), $sw_js);
				do_action('nebula_wrote_sw_js');
				do_action('qm/info', 'Updated sw.js File');
			}

			$this->timer('Update SW', 'end');
			return false;
		}

		//Manifest JSON file location
		public function manifest_json_location($uri=true){
			$override = apply_filters('pre_manifest_json_location', null, $uri);
			if ( isset($override) ){return $override;}

			if ( !empty($uri) ){
				return get_theme_file_uri('/inc/manifest.json');
			}

			return get_theme_file_path('/inc/manifest.json');
		}

		//Create/Write a manifest JSON file
		public function manifest_json(){
			$timer_name = $this->timer('Write Manifest JSON', 'start', 'Manifest');

			$override = apply_filters('pre_nebula_manifest_json', null);
			if ( isset($override) ){return;}

			$manifest_json = '{
				"name": "' . get_bloginfo('name') . ': ' . get_bloginfo('description') . '",
				"short_name": "' . get_bloginfo('name') . '",
				"description": "' . get_bloginfo('description') . '",
				"theme_color": "' . get_theme_mod('nebula_primary_color', $this->get_color('$primary_color')) . '",
				"background_color": "' . get_theme_mod('nebula_background_color', $this->get_color('$background_color')) . '",
				"gcm_sender_id": "' . $this->get_option('gcm_sender_id') . '",
				"scope": "/",
				"start_url": "' . home_url('/') . '?utm_source=pwa",
				"display": "standalone",
				"orientation": "portrait",';

			$shortcuts = apply_filters('nebula_manifest_shortcuts', array()); //Allow the child theme (or plugins) to add shortcuts to the PWA
			if ( !empty($shortcuts) ){
				$manifest_json .= '"shortcuts": ' . wp_json_encode($shortcuts, JSON_PRETTY_PRINT) . ',';
			}

			$manifest_json .= '"icons": [';
			if ( has_site_icon() ){
				$manifest_json .= '{
					"src": "' . get_site_icon_url(16, get_theme_file_uri('/assets/img/meta') . '/favicon-16x16.png') . '",
					"sizes": "16x16",
					"type": "image/png"
				}, {
					"src": "' . get_site_icon_url(32, get_theme_file_uri('/assets/img/meta') . '/favicon-32x32.png') . '",
					"sizes": "32x32",
					"type": "image/png"
				}, {
					"src": "' . get_site_icon_url(192, get_theme_file_uri('/assets/img/meta') . '/android-chrome-192x192.png') . '",
					"sizes": "192x192",
					"type": "image/png",
					"purpose": "any maskable"
				}, {
					"src": "' . get_site_icon_url(512, get_theme_file_uri('/assets/img/meta') . '/android-chrome-512x512.png') . '",
					"sizes": "512x512",
					"type": "image/png",
					"purpose": "any maskable"
				}';
			} else {
				//Loop through all meta images
				$files = glob(get_theme_file_path('/assets/img/meta') . '/*.png');
				foreach ( $files as $file ){
					$filename = $this->url_components('filename', $file);
					$dimensions = getimagesize($file); //Considering adding an @ to ignore notices when getimagesize fails
					if ( !empty($dimensions) ){
						$manifest_json .= '{
							"src": "' . get_theme_file_uri('/assets/img/meta') . '/' . $filename . '",
							"sizes": "' . $dimensions[0] . 'x' . $dimensions[1] . '",
							"type": "image/png",
							"purpose": "any maskable"
						}, ';
					}
				}
			}

			$manifest_json = rtrim($manifest_json, ', ') . ']}';

			WP_Filesystem();
			global $wp_filesystem;
			$wp_filesystem->put_contents($this->manifest_json_location(false), $manifest_json);
			do_action('qm/info', 'Updated manifest.json File');
			$this->timer($timer_name, 'end');
		}

		//Ex: https://nebula.gearside.com/nebula-index-now-0123456789AB.txt
		public function index_now_rewrite_rule(){
			if ( !$this->get_option('index_now') ){
				return false;
			}

			global $wp;
			$wp->add_query_var('nebula_index_now_key');
			add_rewrite_rule('^nebula-index-now-([a-zA-Z0-9]+)\.txt$', 'index.php?nebula_index_now_key=$matches[1]', 'top');
		}

		//Output the IndexNow key txt file
		public function index_now_output_key(){
			if ( !$this->get_option('index_now') ){
				return false;
			}

			$url_key = get_query_var('nebula_index_now_key');

			if ( empty($url_key) ){
				return false;
			}

			$actual_index_now_key = $this->index_now_get_key();

			if ( $url_key === $actual_index_now_key ){ //If the keys match, output the key txt file
				header_remove();
				header('Content-Type: text/plain; charset=UTF-8');
				echo esc_html($actual_index_now_key);
				die;
			}

			//Otherwise show a 404
			global $wp_query;
			$wp_query->set_404();
		}

		public function index_now_get_key(){
			if ( !$this->get_option('index_now') ){
				return false;
			}

			$actual_index_now_key = $this->get_option('index_now_key');

			if ( empty($actual_index_now_key) ){
				$actual_index_now_key = $this->index_now_generate_key();
			}

			return $actual_index_now_key;
		}

		//Generate an IndexNow key and store it in Nebula Options
		public function index_now_generate_key($force=false){
			if ( !$this->get_option('index_now') ){
				return false;
			}

			if ( !$this->get_option('index_now_key') || $force ){ //If we do not already have a key or if we are forcing a new key to be generated
				//Generate a random 12-character string
				$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
				for ( $i = 0; $i < 12; $i++ ){
					$index_now_key .= $chars[random_int(0, strlen($chars)-1)];
				}

				$this->update_option('index_now_key', $index_now_key);
				flush_rewrite_rules(); //Flush rewrite rules so the redirect works later
			}

			return $index_now_key;
		}

		//Redirect to favicon to force-clear the cached version when ?favicon is added to the URL.
		public function favicon_cache(){
			if ( array_key_exists('favicon', $this->super->get) ){
				header('Location: ' . get_theme_file_uri('/assets/img/meta') . '/favicon.ico');
				exit;
			}
		}

		//Convenience function to return only the URL for specific thumbnail sizes of an ID.
		public function get_thumbnail_src($img=null, $size='full', $type='post'){
			if ( empty($img) ){
				return false;
			}

			//If HTML is passed, immediately parse it with HTML
			if ( strpos($img, '<img') !== false ){ //@todo "Nebula" 0: Update strpos() to str_contains() in PHP8
				return ( preg_match('~\bsrc="([^"]++)"~', $img, $matches) )? $matches[1] : ''; //Pull the img src from the HTML tag itself
			}

			$id = intval($img); //Can now use the ID

			//If and ID was not passed, immediately return it (in case it is already an image URL)
			if ( $id === 0 || ($id === 1 && $img != 1) ){
				return $img;
			}

			$size = apply_filters('nebula_thumbnail_src_size', $size, $id);

			//If an attachment ID (or thumbnail ID) was passed
			if ( get_post_type($id) === 'attachment' || $type !== 'post' ){
				$image = wp_get_attachment_image_src(get_post_thumbnail_id($id), $size);

				if ( !empty($image[0]) ){
					return $image[0];
				}
			}

			//Otherwise get the HTML from the post ID (or if the attachment src did not work above)
			$img_tag = get_the_post_thumbnail($id, $size);
			if ( get_post_type($id) === 'attachment' ){
				$img_tag = wp_get_attachment_image($id, $size);
			}

			return ( preg_match('~\bsrc="([^"]++)"~', $img_tag, $matches) )? $matches[1] : ''; //Pull the img src from the HTML tag itself
		}

		//Sets the current post/page template to a variable.
		function define_current_template($template){
			$this->current_theme_template = str_replace(ABSPATH . 'wp-content', '', $template);
			return $template;
		}

		//Show different meta data information about the post. Typically used inside the loop.
		//Example: post_meta('by');
		public function post_meta($meta, $options=array()){
			$override = apply_filters('pre_post_meta', null, $meta, $options);
			if ( isset($override) ){return;}

			if ( $meta === 'date' || $meta === 'time' || $meta === 'on' || $meta === 'day' || $meta === 'when' ){
				echo $this->post_date($options);
			} elseif ( $meta === 'author' || $meta === 'by' ){
				echo $this->post_author($options);
			} elseif ( $meta === 'type' || $meta === 'cpt' || $meta === 'post_type' ){
				echo $this->post_type($options);
			} elseif ( $meta === 'categories' || $meta === 'category' || $meta === 'cat' || $meta === 'cats' || $meta === 'in' ){
				echo $this->post_categories($options);
			} elseif ( $meta === 'tags' || $meta === 'tag' ){
				echo $this->post_tags($options);
			} elseif ( $meta === 'dimensions' || $meta === 'size' ){
				echo $this->post_dimensions($options);
			} elseif ( $meta === 'exif' || $meta === 'camera' ){
				echo $this->post_exif($options);
			} elseif ( $meta === 'comments' || $meta === 'comment' ){
				echo $this->post_comments($options);
			} elseif ( $meta === 'social' || $meta === 'sharing' || $meta === 'share' ){
				$this->social(array('facebook', 'twitter', 'linkedin', 'pinterest'), 0);
			}
		}

		//Date post meta
		public function post_date($options=array()){
			$defaults = apply_filters('nebula_post_date_defaults', array(
				'label' => 'icon', //"icon" or "text"
				'type' => 'published', //"published", or "modified"
				'relative' => get_theme_mod('post_date_format'),
				'linked' => true,
				'day' => true,
				'format' => 'F j, Y',
			));

			$data = array_merge($defaults, $options);

			if ( $data['relative'] === 'disabled' ){
				return false;
			}

			//Apply the requested label
			$label = '';
			if ( $data['label'] == 'icon' ){
				$label = '<i class="nebula-post-date-label fa-regular fa-fw fa-calendar"></i> ';
			} elseif ( $data['label'] == 'text' ){
				$label = '<span class="nebula-post-date-label">' . esc_html(ucwords($data['type'])) . ' </span>';
			}

			//Use the publish or modified date per options
			$the_date = get_the_date('U');
			$modified_date_html = '';
			if ( $data['type'] === 'modified' ){
				$the_date = get_the_modified_date('U');
			}

			$relative_date = human_time_diff($the_date) . ' ago';

			if ( $data['relative'] ){
				return '<time class="posted-on meta-item post-date relative-date" title="' . date('F j, Y', $the_date) . '">' . $label . $relative_date . $modified_date_html . '</time>';
			}

			$day = ( $data['day'] )? date('d', $the_date) . '/' : ''; //If the day should be shown (otherwise, just month and year).

			if ( $data['linked'] && !isset($options['format']) ){
				return '<span class="posted-on meta-item post-date">' . $label . '<time class="entry-date" datetime="' . date('c', $the_date) . '" itemprop="datePublished" content="' . date('c', $the_date) . '"><a href="' . home_url('/') . date('Y/m', $the_date) . '/">' . date('F', $the_date) . '</a> <a href="' . home_url('/') . date('Y/m', $the_date) . '/' . $day . '">' . date('j', $the_date) . '</a>, <a href="' . home_url('/') . date('Y', $the_date) . '/">' . date('Y', $the_date) . '</a></time>' . $modified_date_html . '</span>';
			} else {
				return '<span class="posted-on meta-item post-date">' . $label . '<time class="entry-date" datetime="' . date('c', $the_date) . '" itemprop="datePublished" content="' . date('c', $the_date) . '">' . date($data['format'], $the_date) . '</time>' . $modified_date_html . '</span>';
			}
		}

		//Author post meta
		public function post_author($options=array()){
			$defaults = apply_filters('nebula_post_author_defaults', array(
				'label' => 'icon', //"icon" or "text"
				'linked' => true, //Link to author page
				'force' => false, //Override author_bios Nebula option
			));

			$data = array_merge($defaults, $options);

			//Include support for multi-authors: is_multi_author

			if ( ($this->get_option('author_bios') || $data['force']) && get_theme_mod('post_author', true) ){
				$label = '';
				if ( $data['label'] === 'icon' ){
					$label = '<i class="nebula-post-author-label fa-solid fa-fw fa-user"></i> ';
				} elseif ( $data['label'] === 'text' ){
					$label = '<span class="nebula-post-author-label">Author </span>';
				}

				//Get the author metadata
				$author_id = get_the_author_meta('ID');
				if ( empty($author_id) ){ //Author ID can be empty outside of the loop
					global $post;
					$author_id = $post->post_author;
					$author_name = get_the_author_meta('display_name', $author_id);
				} else {
					$author_name = get_the_author();
				}

				if ( $data['linked'] && !$data['force'] ){
					return '<span class="posted-by" itemprop="author" itemscope itemtype="https://schema.org/Person">' . $label . '<span class="meta-item entry-author"><a href="' . get_author_posts_url($author_id) . '" itemprop="name">' . $author_name . '</a></span></span>';
				} else {
					return '<span class="posted-by" itemprop="author" itemscope itemtype="https://schema.org/Person">' . $label . '<span class="meta-item entry-author" itemprop="name">' . esc_html($author_name) . '</span></span>';
				}
			}
		}

		//Post type meta
		public function post_type($options=array()){
			$defaults = apply_filters('nebula_post_type_defaults', array(
				'icon' => true, //True for generic defaults, false to disable icon, or string of class name(s) for icon.
				'linked' => false //True links output to the post type archive page
			));
			$data = array_merge($defaults, $options);
			$post_icon_img = false;

			if ( get_theme_mod('search_result_post_types', true) ){
				global $wp_post_types;
				$post_type = get_post_type();
				$post_type_labels = get_post_type_object( $post_type )->labels;

				if ( $data['icon'] ){
					$post_icon = $wp_post_types[$post_type]->menu_icon;
					$post_icon_img = '<i class="fa-solid fa-thumbtack"></i>';

					if ( !empty($post_icon) ){
						$post_icon_img = '<img src="' . $post_icon . '" style="width: 16px; height: 16px;" loading="lazy" />';

						if ( strpos('dashicons-', $post_icon) >= 0 ){ //@todo "Nebula" 0: Update strpos() to str_contains() in PHP8
							$post_icon_img = '<i class="dashicons-before ' . $post_icon . '"></i>';
						}
					}

					if ( gettype($data['icon']) === 'string' && $data['icon'] !== '' ){
						$post_icon_img = '<i class="' . esc_html($data['icon']) . '"></i>';
					}elseif ( $post_type === 'post' ){
						$post_icon_img = '<i class="fa-solid fa-fw fa-thumbtack"></i>';
					} elseif ( $post_type === 'page' ){
						$post_icon_img = '<i class="fa-solid fa-fw fa-file-alt"></i>';
					}
				}

				if ( $data['linked'] ){
					return '<span class="meta-item post-type"><a href="' . esc_url(get_post_type_archive_link($post_type)) . '" title="See all ' . $post_type_labels->name . '">' . $post_icon_img . esc_html($post_type_labels->singular_name) . '</a></span>';
				}

				return '<span class="meta-item post-type">' . $post_icon_img . esc_html($post_type_labels->singular_name) . '</span>';
			}
		}

		//Categories post meta
		public function post_cats($options=array()){return $this->post_categories($options);}
		public function post_categories($options=array()){
			$defaults = apply_filters('nebula_post_categories_defaults', array(
				'id' => get_the_ID(),
				'label' => 'icon', //"icon" or "text"
				'linked' => true, //Link to category archive
				'show_uncategorized' => true, //Show "Uncategorized" category
				'force' => false,
				'string' => false, //Return a string with no markup
			));

			$data = array_merge($defaults, $options);

			if ( get_theme_mod('post_categories', true) || $data['force'] ){
				$label = '';
				if ( $data['label'] === 'icon' ){
					$label = '<i class="nebula-post-categories-label fa-solid fa-fw fa-bookmark"></i> ';
				} elseif ( $data['label'] === 'text' ){
					$label = '<span class="nebula-post-categories-label">' . __('Category', 'nebula') . '</span>';
				}

				if ( is_object_in_taxonomy(get_post_type(), 'category') ){
					$category_list = get_the_category_list(', ', '', $data['id']);

					if ( strip_tags($category_list) === 'Uncategorized' && !$data['show_uncategorized'] ){
						return false;
					}

					if ( !$data['linked'] ){
						$category_list = strip_tags($category_list);
					}

					if ( $data['string'] ){
						return strip_tags($category_list);
					}

					return '<span class="posted-in meta-item post-categories">' . $label . $category_list . '</span>';
				}
			}
		}

		//Tags post meta
		public function post_tags($options=array()){
			$defaults = apply_filters('nebula_post_tags_defaults', array(
				'id' => get_the_ID(),
				'label' => 'icon', //"icon" or "text"
				'linked' => true, //Link to tag archive
				'force' => false,
				'string' => false, //Return a string with no markup
			));

			$data = array_merge($defaults, $options);

			if ( get_theme_mod('post_tags', true) || $data['force'] ){
				$tag_list = get_the_tag_list('', ', ', '', $data['id']);
				if ( $tag_list ){
					$label = '';
					if ( $data['label'] === 'icon' ){
						$the_tags = get_the_tags();
						$tag_plural = ( !empty($the_tags) && is_array($the_tags) && count($the_tags) > 1 )? __('tags', 'nebula') : __('tag', 'nebula'); //One time get_the_tags() was not an array and caused a PHP error, so this conditional is for extra precaution
						$label = '<i class="nebula-post-tags-label fa-solid fa-fw fa-' . $tag_plural . '"></i> ';
					} elseif ( $data['label'] === 'text' ){
						$label = '<span class="nebula-post-tags-label">' . ucwords($tag_plural) . ' </span>';
					}

					if ( !$data['linked'] ){
						$tag_list = strip_tags($tag_list);
					}

					if ( $data['string'] ){
						return strip_tags($tag_list);
					}

					return '<span class="posted-in meta-item post-tags">' . $label . $tag_list . '</span>';
				}
			}
		}

		//Image dimensions post meta
		public function post_dimensions($options=array()){
			if ( wp_attachment_is_image() ){
				$defaults = array(
					'icon' => true, //Show icon
					'linked' => true, //Link to attachment
				);

				$data = array_merge($defaults, $options);

				$the_icon = '';
				if ( $data['icon'] ){
					$the_icon = '<i class="fa-solid fa-fw fa-expand"></i> ';
				}

				$metadata = wp_get_attachment_metadata();
				if ( $data['linked'] ){
					echo '<span class="meta-item meta-dimensions">' . $the_icon . '<a href="' . wp_get_attachment_url() . '" >' . $metadata['width'] . ' &times; ' . $metadata['height'] . '</a></span>';
				} else {
					echo '<span class="meta-item meta-dimensions">' . $the_icon . $metadata['width'] . ' &times; ' . $metadata['height'] . '</span>';
				}
			}
		}

		//Image EXIF post meta
		public function post_exif($icon=true){
			$the_icon = '';
			if ( $icon ){
				$the_icon = '<i class="fa-solid fa-fw fa-camera-retro"></i> ';
			}

			$imgmeta = wp_get_attachment_metadata();
			if ( $imgmeta ){ //Check for Bad Data
				if ( $imgmeta['image_meta']['focal_length'] === 0 || $imgmeta['image_meta']['aperture'] === 0 || $imgmeta['image_meta']['shutter_speed'] === 0 || $imgmeta['image_meta']['iso'] === 0 ){
					$output = __('No valid EXIF data found', 'nebula');
				} else { //Convert the shutter speed retrieve from database to fraction
					if ( $imgmeta['image_meta']['shutter_speed'] > 0 && (1/$imgmeta['image_meta']['shutter_speed']) > 1 ){
						if ( (number_format((1/$imgmeta['image_meta']['shutter_speed']), 1)) == 1.3 || number_format((1/$imgmeta['image_meta']['shutter_speed']), 1) == 1.5 || number_format((1/$imgmeta['image_meta']['shutter_speed']), 1) == 1.6 || number_format((1/$imgmeta['image_meta']['shutter_speed']), 1) == 2.5 ){
							$pshutter = '1/' . number_format((1/$imgmeta['image_meta']['shutter_speed']), 1, '.', '') . ' ' . __('second', 'nebula');
						} else {
							$pshutter = '1/' . number_format((1/$imgmeta['image_meta']['shutter_speed']), 0, '.', '') . ' ' . __('second', 'nebula');
						}
					} else {
						$pshutter = $imgmeta['image_meta']['shutter_speed'] . ' ' . __('seconds', 'nebula');
					}

					$output = '<time datetime="' . date('c', $imgmeta['image_meta']['created_timestamp']) . '"><span class="month">' . date('F', $imgmeta['image_meta']['created_timestamp']) . '</span> <span class="day">' . date('j', $imgmeta['image_meta']['created_timestamp']) . '</span><span class="suffix">' . date('S', $imgmeta['image_meta']['created_timestamp']) . '</span> <span class="year">' . date('Y', $imgmeta['image_meta']['created_timestamp']) . '</span></time>, ';
					$output .= $imgmeta['image_meta']['camera'] . ', ';
					$output .= $imgmeta['image_meta']['focal_length'] . 'mm, ';
					$output .= '<span style="font-style: italic; font-family: "Trebuchet MS", "Candara", "Georgia", serif; text-transform: lowercase;">f</span>/' . $imgmeta['image_meta']['aperture'] . ', ';
					$output .= $pshutter . ', ';
					$output .= $imgmeta['image_meta']['iso'] . ' ISO';
				}
			} else {
				$output = __('No EXIF data found', 'nebula');
			}

			return '<span class="meta-item meta-exif">' . $the_icon . $output . '</span>';
		}

		//Use this instead of the_excerpt(); and get_the_excerpt(); to have better control over the excerpt.
		//Inside the loop (or outside the loop for current post/page): nebula()->excerpt(array('words' => 20, 'ellipsis' => true));
		//Outside the loop: nebula()->excerpt(array('id' => 572, 'words' => 20, 'ellipsis' => true));
		//Custom text: nebula()->excerpt(array('text' => 'Lorem ipsum <strong>dolor</strong> sit amet.', 'more' => 'Continue &raquo;', 'words' => 3, 'ellipsis' => true, 'strip_tags' => true));
		public function excerpt($options=array()){
			$override = apply_filters('pre_nebula_excerpt', null, $options);
			if ( isset($override) ){return $override;}

			$defaults = apply_filters('nebula_excerpt_defaults', array(
				'id' => false,
				'text' => false,
				'paragraphs' => false, //Allow paragraph tags in the excerpt //@todo "Nebula" 0: currently not working
				'characters' => false,
				'words' => get_theme_mod('nebula_excerpt_length', 55),
				'length' => false, //Used for dynamic length, otherwise an alias of "words"
				'min' => 0, //Minimum length of dynamic sentence
				'ellipsis' => false,
				'url' => false,
				'more' => get_theme_mod('nebula_excerpt_more_text', __('Read More', 'nebula') . ' &raquo;'),
				'wp_more' => true, //Listen for the WP more tag
				'btn' => false, //Alias of "button"
				'button' => false,
				'strip_shortcodes' => true,
				'strip_tags' => true,
				'wrap_links' => false,
				'shorten_urls' => false, //Currently only works with wrap_links
			));

			$data = array_merge($defaults, $options);

			//Establish text
			if ( empty($data['text']) ){
				if ( !empty($data['id']) ){
					if ( is_object($data['id']) && get_class($data['id']) == 'WP_Post' ){ //If we already have a WP_Post class object
						$the_post = $data['id'];
					} elseif ( intval($data['id']) ){ //If an ID is passed
						$the_post = get_post(intval($data['id']));
					}
				} else {
					$the_post = get_post(get_the_ID());
				}

				if ( empty($the_post) ){
					return false;
				}

				if ( !empty($the_post->post_excerpt) ){
					$data['text'] = $the_post->post_excerpt;
				} else {
					$data['text'] = $the_post->post_content;
					if ( $data['wp_more'] ){
						$wp_more_split = get_extended($the_post->post_content); //Split the content on the WordPress <!--more--> tag
						$data['text'] = $wp_more_split['main'];

						if ( preg_match('/<!--more(.*?)?-->/', $the_post->post_content, $matches) ){ //Get the custom <!--more Keep Reading--> text. RegEx from: https://core.trac.wordpress.org/browser/tags/4.8/src/wp-includes/post-template.php#L288
							if ( !empty($matches[1]) ){
								$data['more'] = strip_tags(wp_kses_no_null(trim($matches[1])));
							}
						}
					}
				}
			}

			//Strip Newlines
			$data['text'] = str_replace(array("\r\n", "\r", "\n"), " ", $data['text']); //Replace newline characters (keep double quotes)
			$data['text'] = preg_replace('/\s+/', ' ', $data['text']); //Replace multiple spaces with single space

			//Strip Shortcodes
			if ( $data['strip_shortcodes'] ){
				$data['text'] = strip_shortcodes($data['text']);
			} else {
				$data['text'] = preg_replace('~(?:\[/?)[^/\]]+/?\]~s', ' ', $data['text']);
			}

			//Strip Tags
			if ( $data['strip_tags'] ){
				$allowable_tags = ( !empty($data['paragraphs']) )? 'p' : '';
				$data['text'] = strip_tags($data['text'], $allowable_tags);
			}

			//Apply string limiters (words or characters)
			if ( !empty($data['characters']) && intval($data['characters']) ){ //Characters
				$limited = $this->string_limit_chars($data['text'], intval($data['characters'])); //Returns array: $limited['text'] is the string, $limited['is_limited'] is boolean if it was limited or not.
				$data['text'] = trim($limited['text']);
			} elseif ( (!empty($data['words']) && intval($data['words'])) || (!empty($data['length']) && intval($data['length'])) ){ //Words (or Length)
				$word_limit = ( !empty($data['length']) && intval($data['length']) )? intval($data['length']) : intval($data['words']);
				$limited = $this->string_limit_words($data['text'], $word_limit); //Returns array: $limited['text'] is the string, $limited['is_limited'] is boolean if it was limited or not.
				$data['text'] = trim($limited['text']);
			}

			//Apply dynamic sentence length limiter
			if ( $data['length'] === 'dynamic' ){
				$last_punctuation = -1;
				foreach ( array('.', '?', '!') as $punctuation ){
					if ( strrpos($data['text'] . ' ', $punctuation . ' ') ){
						$this_punctuation = strrpos($data['text'] . ' ', $punctuation . ' ')+1; //Find the last punctuation (add a space to the end of the string in case it already ends at the punctuation). Add 1 to capture the punctuation, too.

						if ( $this_punctuation > $last_punctuation ){
							$last_punctuation = $this_punctuation;
						}
					}
				}

				if ( $last_punctuation >= $data['min'] ){
					$data['text'] = substr($data['text'], 0, $last_punctuation); //Remove everything after the last punctuation in the string.
				}
			}

			//Check here for links to wrap
			if ( $data['wrap_links'] ){
				$data['text'] = preg_replace('/(\(?(?:(http|https|ftp):\/\/)?(?:((?:[^\W\s]|\.|-|[:]{1})+)@{1})?((?:www.)?(?:[^\W\s]|\.|-)+[\.][^\W\s]{2,4}|localhost(?=\/)|\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})(?::(\d*))?([\/]?[^\s\?]*[\/]{1})*(?:\/?([^\s\n\?\[\]\{\}\#]*(?:(?=\.)){1}|[^\s\n\?\[\]\{\}\.\#]*)?([\.]{1}[^\s\?\#]*)?)?(?:\?{1}([^\s\n\#\[\]]*))?([\#][^\s\n]*)?\)?)(?![^<]*<\/)/i', '<a class="nebula-excerpt-url" href="$1">$1</a>', $data['text']); //Capture any URL not within < and </ using a negative lookahead (so it plays nice in case strip_tags is false)
			}

			//Shorten visible URL text
			if ( $data['shorten_urls'] ){
				$data['text'] = preg_replace_callback('/(<a.+>)(.+)(<\/a>)/', function($matches){
					$output = $matches[1];
					if ( strlen($matches[2]) > 20 ){
						$short_url = str_replace(array('http://', 'https://'), '', $matches[2]);
						$url_directories = explode('/', $short_url);
						$short_url = $url_directories[0];
						if ( count($url_directories) > 1 ){
							$short_url .= '/...';
						}
						$output .= $short_url;
					} else {
						$output .= $matches[2];
					}
					$output .= $matches[3];
					return $output;
				}, $data['text']);
			}

			//Ellipsis
			if ( $data['ellipsis'] && !empty($limited['is_limited']) ){
				$data['text'] .= '&hellip;';
			}

			//Link
			if ( !empty($data['more']) ){
				if ( empty($data['url']) ){ //If has "more" text, but no link URL
					$data['url'] = ( !empty($data['id']) )? get_permalink($data['id']) : get_permalink(get_the_id()); //Use the ID if available, or use the current ID.
				}

				//Button
				$btn_class = '';
				if ( $data['button'] || $data['btn'] ){
					$button = ( $data['button'] )? $data['button'] : $data['btn'];
					$btn_class = ( is_bool($button) )? 'btn btn-brand' : 'btn ' . $data['button'];

					$data['text'] .= '<br /><br />';
				}

				$data['text'] .= ' <a class="nebula_excerpt ' . $btn_class . '" href="' . $data['url'] . '">' . $data['more'] . '</a>';
			}

			return $data['text'];
		}

		//Get the word count of a post
		public function word_count($options=array()){
			$override = apply_filters('pre_nebula_word_count', null, $options);
			if ( isset($override) ){return $override;}

			$defaults = array(
				'id' => get_the_id(),
				'content' => false,
				'range' => false, //Show a range instead of exact count
			);

			$data = array_merge($defaults, $options);

			$content = ( !empty($data['content']) )? $data['content'] : get_post_field('post_content', $data['id']);
			$content = apply_filters('nebula_word_count', $content, $data['id']); //Allow additional content to be added to the word count (such as ACF fields)

			$word_count = intval(round(str_word_count(strip_tags($content))));

			if ( is_int($word_count) ){
				if ( !$data['range'] ){
					return $word_count;
				}

				$words_label = __('words', 'nebula');

				if ( $word_count < 10 ){
					$word_count_range = '<10 ' . $words_label;
				} elseif ( $word_count < 500 ){
					$word_count_range = '10 - 499 ' . $words_label;
				} elseif ( $word_count < 1000 ){
					$word_count_range = '500 - 999 ' . $words_label;
				} elseif ( $word_count < 1500 ){
					$word_count_range = '1,000 - 1,499 ' . $words_label;
				} elseif ( $word_count < 2000 ){
					$word_count_range = '1,500 - 1,999 ' . $words_label;
				} else {
					$word_count_range = '2,000+ ' . $words_label;
				}

				return $word_count_range;
			}

			return false;
		}

		//Determines the estimated time to read a post (in minutes).
		//Note: Does not account for ACF fields unless hooked into 'nebula_word_count' above
		public function estimated_reading_time($id=false){
			//@todo "Nebula" 0: Use null coalescing operator here if possible
			if ( empty($id) ){
				$id = get_the_ID();
			}

			$wpm = 250; //Words per minute reading speed
			$content = $this->word_count(array('id' => $id));

			return intval(round($content/$wpm));
		}

		//Use WP Pagenavi if active, or manually paginate.
		public function paginate($query=false, $args=array()){
			if ( function_exists('wp_pagenavi') ){
				wp_pagenavi();
			} else {
				if( !$query ){
					global $wp_query;
					$query = $wp_query;
				}

				$big = 999999999; //An unlikely integer //PHP 7.4 use numeric separators here

				//Set some defaults if not passed by the $args value...
				$args['base'] = ( !empty($args['base']) )? $args['base'] : str_replace($big, '%#%', esc_url(get_pagenum_link($big)));
				$args['format'] = ( !empty($args['format']) )? $args['format'] : '?paged=%#%';
				$args['current'] = ( !empty($args['current']) )? $args['current'] : max(1, get_query_var('paged'));
				$args['total'] = ( !empty($args['total']) )? $args['total'] : $query->max_num_pages;

				echo '<div class="wp-pagination">';
					echo paginate_links($args);
				echo '</div>';
			}
		}

		//A consistent way to link to social network and other external profiles
		public function social_link($network){return $this->social_url($network);}
		public function social_url($network){
			switch ( strtolower($network) ){
				case 'facebook':
				case 'fb':
					return esc_url($this->get_option('facebook_url'));

				case 'twitter':
				case 'x':
					return $this->twitter_url(); //Use the provided function from Nebula Options

				case 'linkedin':
					return esc_url($this->get_option('linkedin_url'));

				case 'instagram':
				case 'ig':
					return esc_url($this->get_option('instagram_url'));

				case 'pinterest':
					return esc_url($this->get_option('pinterest_url'));

				case 'youtube':
					return esc_url($this->get_option('youtube_url'));

				case 'tiktok':
					return esc_url($this->get_option('tiktok_url'));

				case 'wikipedia':
					return esc_url($this->get_option('wikipedia_url'));

				default:
					return false;
			}

			return false;
		}

		//Display non-native social buttons
		//This is a more optimized solution that does not require SDKs and does not load third-party resources, so these will also be a consistent size.
		public function share($networks=array('shareapi', 'facebook', 'twitter'), $id=false){
			$override = apply_filters('pre_nebula_share', null, $networks, $id);
			if ( isset($override) ){return;}

			//@todo "Nebula" 0: Use null coalescing operator here if possible
			if ( empty($id) ){
				$id = get_the_id();
			}

			$encoded_url = urlencode(get_permalink($id));
			$encoded_title = urlencode(get_the_title($id));

			//Convert $networks to lower case without dashes/spaces for more flexible string matching later.
			$networks = array_map(function($value){
				return str_replace(array(' ', '_', '-'), '', strtolower($value));
			}, $networks);

			echo '<div class="sharing-links">';

			//If the 'shareapi' cookie exists and 'shareapi' is requested, return *only* the Share API
			if ( isset($this->super->cookie['shareapi']) || in_array($networks, array('shareapi')) ){
				$networks = array('shareapi');
			}

			foreach ( $networks as $network ){
				//Share API
				if ( in_array($network, array('shareapi')) ){
					echo '<a class="nebula-share-btn nebula-share shareapi" href="#">' . __('Share', 'nebula') . '</a>';
				}

				//Facebook
				if ( in_array($network, array('facebook', 'fb')) ){
					echo '<a class="nebula-share-btn facebook" href="http://www.facebook.com/sharer.php?u=' . $encoded_url . '&t=' . $encoded_title . '" target="_blank" rel="noopener">' . __('Share', 'nebula') . '</a>';
				}

				//Twitter
				if ( in_array($network, array('twitter')) ){
					echo '<a class="nebula-share-btn twitter" href="https://twitter.com/intent/tweet?text=' . $encoded_title . '&url=' . $encoded_url . '" target="_blank" rel="noopener">' . __('Tweet', 'nebula') . '</a>';
				}

				//LinkedIn
				if ( in_array($network, array('linkedin', 'li')) ){
					echo '<a class="nebula-share-btn linkedin" href="http://www.linkedin.com/shareArticle?mini=true&url=' . $encoded_url . '&title=' . $encoded_title . '" target="_blank" rel="noopener">' . __('Share', 'nebula') . '</a>';
				}

				//Pinterest
				if ( in_array($network, array('pinterest', 'pin')) ){
					echo '<a class="nebula-share-btn pinterest" href="http://pinterest.com/pin/create/button/?url=' . $encoded_url . '" target="_blank" rel="noopener">' . __('Share', 'nebula') . '</a>';
				}

				//Email
				if ( in_array($network, array('email')) ){
					echo '<a class="nebula-share-btn email" href="mailto:?subject=' . $encoded_title . '&body=' . $encoded_url . '" target="_blank" rel="noopener">' . __('Email', 'nebula') . '</a>';
				}
			}

			echo '</div><!--/sharing-links-->';
		}

		//Display Native Social Buttons
		public function social($networks=array('shareapi', 'facebook', 'twitter'), $counts=0){
			$override = apply_filters('pre_nebula_social', null, $networks, $counts);
			if ( isset($override) ){return;}

			if ( is_string($networks) ){ //if $networks is a string, create an array for the string.
				$networks = array($networks);
			} elseif ( is_int($networks) && ($networks === 1 || $networks === 0) ){ //If it is an integer of 1 or 0, then set it to $counts
				$counts = $networks;
				$networks = array('shareapi', 'facebook', 'twitter');
			} elseif ( !is_array($networks) ){
				$networks = array('shareapi', 'facebook', 'twitter');
			}

			//Convert $networks to lower case without dashes/spaces for more flexible string matching later.
			$networks = array_map(function($value){
				return str_replace(array(' ', '_', '-'), '', strtolower($value));
			}, $networks);

			echo '<div class="sharing-links">';

			//If the 'shareapi' cookie and 'shareapi' is requested, return *only* the Share API
			if ( isset($this->super->cookie['shareapi']) || in_array($networks, array('shareapi')) ){
				$networks = array('shareapi');
			}

			foreach ( $networks as $network ){
				//Share API
				if ( in_array($network, array('shareapi')) ){
					$this->share_api();
				}

				//Facebook
				if ( in_array($network, array('facebook', 'fb')) ){
					$this->facebook_share($counts);
				}

				//Twitter
				if ( in_array($network, array('twitter')) ){
					$this->twitter_tweet($counts);
				}

				//LinkedIn
				if ( in_array($network, array('linkedin', 'li')) ){
					$this->linkedin_share($counts);
				}

				//Pinterest
				if ( in_array($network, array('pinterest', 'pin')) ){
					$this->pinterest_pin($counts);
				}
			}

			echo '</div><!--/sharing-links-->';
		}

		//Social Button Functions

		public function share_api(){
			$override = apply_filters('pre_nebula_share_api', null);
			if ( isset($override) ){return;}
			?>
				<div class="nebula-social-button shareapi">
					<a class="btn btn-secondary btn-sm" href="#" target="_blank"><i class="fa-solid fa-fw fa-share"></i> <?php _e('Share', 'nebula'); ?></a>
				</div>
			<?php
		}

		public function facebook_share($counts=0, $url=false){
			$override = apply_filters('pre_nebula_facebook_share', null, $counts);
			if ( isset($override) ){return;}
			?>
			<div class="nebula-social-button facebook-share require-fbsdk">
				<div class="fb-share-button" data-href="<?php echo ( !empty($url) )? $url : get_page_link(); ?>" data-layout="<?php echo ( $counts !== 0 )? 'button_count' : 'button'; ?>" data-size="small" data-mobile-iframe="true"></div>
			</div>
		<?php }


		public function facebook_like($counts=0, $url=false){
			$override = apply_filters('pre_nebula_facebook_like', null, $counts);
			if ( isset($override) ){return;}
			?>
			<div class="nebula-social-button facebook-like require-fbsdk">
				<div class="fb-like" data-href="<?php echo ( !empty($url) )? $url : get_page_link(); ?>" data-layout="<?php echo ( $counts !== 0 )? 'button_count' : 'button'; ?>" data-action="like" data-show-faces="false" data-share="false"></div>
			</div>
		<?php }

		public function facebook_both($counts=0, $url=false){
			$override = apply_filters('pre_nebula_facebook_both', null, $counts);
			if ( isset($override) ){return;}
			?>
			<div class="nebula-social-button facebook-both require-fbsdk">
				<div class="fb-like" data-href="<?php echo ( !empty($url) )? $url : get_page_link(); ?>" data-layout="<?php echo ( $counts !== 0 )? 'button_count' : 'button'; ?>" data-action="like" data-show-faces="false" data-share="true"></div>
			</div>
		<?php }


		public function twitter_tweet($counts=0){
			$override = apply_filters('pre_nebula_twitter_tweet', null, $counts);
			if ( isset($override) ){return;}
			?>
			<div class="nebula-social-button twitter-tweet">
				<a href="https://twitter.com/share" class="twitter-share-button" <?php echo ( $counts !== 0 )? '': 'data-count="none"'; ?>><?php _e('Tweet', 'nebula'); ?></a>
				<?php $this->twitter_widget_script(); ?>
			</div>
			<?php
		}

		public function twitter_follow($counts=0, $username=false){
			$override = apply_filters('pre_nebula_twitter_follow', null, $counts, $username);
			if ( isset($override) ){return;}

			if ( empty($username) && !$this->get_option('twitter_username') ){
				return false;
			} elseif ( empty($username) && $this->get_option('twitter_username') ){
				$username = $this->get_option('twitter_username');
			} elseif ( strpos($username, '@') === false ){ //@todo "Nebula" 0: Update strpos() to str_contains() in PHP8
				$username = '@' . $username;
			}
			?>
			<div class="nebula-social-button twitter-follow">
				<a href="https://twitter.com/<?php echo str_replace('@', '', $username); ?>" class="twitter-follow-button" <?php echo ( $counts !== 0 )? '': 'data-show-count="false"'; ?> <?php echo ( !empty($username) )? '': 'data-show-screen-name="false"'; ?>><?php echo __('Follow', 'nebula') . ' ' . $username; ?></a>
				<?php $this->twitter_widget_script(); ?>
			</div>
			<?php
		}

		public function twitter_widget_script(){
			if ( empty($this->twitter_widget_loaded) ){
				?>
				<script>!function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0],p=/^http:/.test(d.location)?'http':'https';if(!d.getElementById(id)){js=d.createElement(s);js.id=id;js.src=p+'://platform.twitter.com/widgets.js';fjs.parentNode.insertBefore(js,fjs);}}(document, 'script', 'twitter-wjs');</script>
				<?php
				$this->twitter_widget_loaded = true;
			}
		}

		public function linkedin_share($counts=0){
			$override = apply_filters('pre_nebula_linkedin_share', null, $counts);
			if ( isset($override) ){return;}
			?>
			<div class="nebula-social-button linkedin-share">
				<?php $this->linkedin_widget_script(); ?>
				<script type="IN/Share" <?php echo ( $counts !== 0 )? 'data-counter="right"' : ''; ?>></script>
			</div>
			<?php
		}

		public function linkedin_follow($counts=0){
			$override = apply_filters('pre_nebula_linkedin_follow', null, $counts);
			if ( isset($override) ){return;}
			?>
			<div class="nebula-social-button linkedin-follow">
				<?php $this->linkedin_widget_script(); ?>
				<script type="IN/FollowCompany" data-id="1337" <?php echo ( $counts !== 0 )? 'data-counter="right"' : ''; ?>></script>
			</div>
			<?php
		}

		public function linkedin_widget_script(){
			if ( empty($this->linkedin_widget_loaded) ){
				?>
				<script type="text/javascript" src="//platform.linkedin.com/in.js" async defer> lang: en_US</script>
				<?php
				$this->linkedin_widget_loaded = true;
			}
		}

		public function pinterest_pin($counts=0){ //@TODO "Nebula" 0: Bubble counts are not showing up...
			$override = apply_filters('pre_nebula_pinterest_pin', null, $counts);
			if ( isset($override) ){return;}

			$featured_image = get_template_directory_uri() . '/assets/img/meta/og-thumb.png';
			if ( has_post_thumbnail() ){
				$featured_image = $this->get_thumbnail_src(get_the_post_thumbnail(get_the_id(), 'full'));
			}
			?>
			<div class="nebula-social-button pinterest-pin">
				<a href="//www.pinterest.com/pin/create/button/?url=<?php echo get_page_link(); ?>&media=<?php echo $featured_image; ?>&description=<?php echo urlencode(get_the_title()); ?>" data-pin-do="buttonPin" data-pin-config="<?php echo ( $counts !== 0 )? 'beside' : 'none'; ?>" data-pin-color="red">
					<img src="//assets.pinterest.com/images/pidgets/pinit_fg_en_rect_red_20.png" alt="Pinterest Pin Button" loading="lazy" />
				</a>
				<?php if ( empty($this->pinterest_pin_widget_loaded) ): ?>
					<script type="text/javascript" async defer src="//assets.pinterest.com/js/pinit.js"></script>
					<?php $this->pinterest_pin_widget_loaded = true; ?>
				<?php endif; ?>
			</div>
			<?php
		}

		//Get metadata from Youtube or Vimeo
		public function vimeo_meta($id, $meta=''){return $this->video_meta('vimeo', $id);}
		public function youtube_meta($id, $meta=''){return $this->video_meta('youtube', $id);}
		public function video_meta($provider, $id){
			$override = apply_filters('pre_video_meta', null, $provider, $id);
			if ( isset($override) ){return $override;}

			$timer_name = $this->timer('Video Meta (' . $id . ')', 'start', 'Video Meta');

			$video_metadata = array(
				'origin' => $this->url_components('basedomain'),
				'id' => $id,
				'error' => false
			);

			if ( !empty($provider) ){
				$provider = strtolower($provider);
			} else {
				$video_metadata['error'] = 'Video provider is required.';
				$this->timer($timer_name, 'end');
				return $video_metadata;
			}

			//Get Transients
			$video_json = nebula()->transient('nebula_' . $provider . '_' . $id, function($data){
				if ( $data['provider'] === 'youtube' ){
					if ( !$this->get_option('google_server_api_key') && $this->is_staff() ){
						trigger_error('No Google Youtube Iframe API key. Youtube videos may not be tracked!', E_USER_WARNING);
						echo '<script>console.warn("No Google Youtube Iframe API key. Youtube videos may not be tracked!");</script>';
						$video_metadata['error'] = 'No Google Youtube Iframe API key.';
					}

					$response = $this->remote_get('https://www.googleapis.com/youtube/v3/videos?id=' . $data['id'] . '&part=snippet,contentDetails,statistics&key=' . $this->get_option('google_server_api_key'));
					if ( is_wp_error($response) ){
						trigger_error('Youtube video is unavailable.', E_USER_WARNING);
						$video_metadata['error'] = 'Youtube video is unavailable.';
						$this->timer($data['timer_name'], 'end');
						return $video_metadata;
					}

					$video_json = $response['body'];
				} elseif ( $data['provider'] === 'vimeo' ){
					$response = $this->remote_get('http://vimeo.com/api/v2/video/' . $data['id'] . '.json');
					if ( is_wp_error($response) ){
						trigger_error('Vimeo video is unavailable.', E_USER_WARNING);
						$video_metadata['error'] = 'Vimeo video is unavailable.';
						$this->timer($data['timer_name'], 'end');
						return $video_metadata;
					}

					$video_json = $response['body'];
				}

				return $video_json;
			}, array('provider' => $provider, 'id' => $id, 'timer_name' => $timer_name), HOUR_IN_SECONDS*12);

			if ( !is_array($video_json) ){ //If it is not already an array, decode it from the JSON string
				$video_json = json_decode($video_json);
			}

			//Check for errors
			if ( empty($video_json) ){
				if ( current_user_can('manage_options') || $this->is_dev() ){
					if ( $provider === 'youtube' ){
						$video_metadata['error'] = 'A Youtube Data API error occurred. Make sure the Youtube Data API is enabled in the Google Developer Console and the server key is saved in Nebula Options.';
					} else {
						$video_metadata['error'] = 'A Vimeo API error occurred (A video with ID ' . $id . ' may not exist). Tracking will not be possible.';
					}
				}
				$this->timer($timer_name, 'end');
				return $video_metadata;
			} elseif ( $provider === 'youtube' && !empty($video_json->error) ){
				if ( current_user_can('manage_options') || $this->is_dev() ){
					$video_metadata['error'] = 'Youtube API Error: ' . $video_json->error->message;
				}
				$this->timer($timer_name, 'end');
				return $video_metadata;
			} elseif ( $provider === 'youtube' && empty($video_json->items) ){
				if ( current_user_can('manage_options') || $this->is_dev() ){
					$video_metadata['error'] = 'A Youtube video with ID ' . $id . ' does not exist.';
				}
				$this->timer($timer_name, 'end');
				return $video_metadata;
			} elseif ( $provider === 'vimeo' && is_array($video_json) && empty($video_json[0]) ){
				$video_metadata['error'] = 'A Vimeo video with ID ' . $id . ' does not exist.';
			}

			//Build Data
			if ( $provider === 'youtube' ){
				$video_metadata['raw'] = $video_json->items[0];
				$video_metadata['title'] = $video_json->items[0]->snippet->title;
				$video_metadata['safetitle'] = preg_replace('/(\W)/i', '', $video_json->items[0]->snippet->title);
				$video_metadata['description'] = $video_json->items[0]->snippet->description;
				$video_metadata['thumbnail'] = $video_json->items[0]->snippet->thumbnails->high->url;
				$video_metadata['author'] = $video_json->items[0]->snippet->channelTitle;
				$video_metadata['date'] = $video_json->items[0]->snippet->publishedAt;
				$video_metadata['url'] = 'https://www.youtube.com/watch?v=' . $id;
				$start = new DateTime('@0'); //Unix epoch
				$start->add(new DateInterval($video_json->items[0]->contentDetails->duration));
				$duration_seconds = intval($start->format('H'))*60*60 + intval($start->format('i'))*60 + intval($start->format('s'));
			} elseif ( $provider === 'vimeo' ){
				$video_metadata['raw'] = $video_json[0];
				$video_metadata['title'] = $video_json[0]->title;
				$video_metadata['safetitle'] = preg_replace('/(\W)/i', '', $video_json[0]->title);
				$video_metadata['description'] = $video_json[0]->description;
				$video_metadata['thumbnail'] = $video_json[0]->thumbnail_large;
				$video_metadata['author'] = $video_json[0]->user_name;
				$video_metadata['date'] = $video_json[0]->upload_date;
				$video_metadata['url'] = $video_json[0]->url;
				$duration_seconds = strval($video_json[0]->duration);
			}
			$video_metadata['duration'] = array(
				'time' => intval(gmdate("i", $duration_seconds)) . gmdate(":s", $duration_seconds),
				'seconds' => $duration_seconds
			);

			$this->timer($timer_name, 'end');
			return $video_metadata;
		}

		//Breadcrumbs
		public function breadcrumbs($options=array()){
			$override = apply_filters('pre_nebula_breadcrumbs', null);
			if ( isset($override) ){return;}

			$this->timer('Breadcrumbs');

			global $post;
			$defaults = apply_filters('nebula_breadcrumb_defaults', array(
				'delimiter' => '/', //Delimiter between crumbs
				'home' => get_bloginfo('title'), //Text for the 'Home' link
				'home_link' => home_url('/'),
				'prefix' => 'off', //Prefix categories and tags with "text", "icon", or none with "off" (default)
				'current' => true, //Show/Hide the current title in the breadcrumb
				'before' => '<li class="current" itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem">', //Tag before the current crumb
				'after' => '</li>', //Tag after the current crumb
				'force' => false //Override the breadcrumbs with an array of specific links
			));

			$data = array_merge($defaults, $options);
			$data['delimiter_html'] = '<li class="delimiter">' . $data['delimiter'] . '</li>';
			$data['current_node'] = $data['before'] . '<a class="current-breadcrumb-link" href="' . get_the_permalink() . '" itemprop="item"><span itemprop="name">' . strip_tags(get_the_title()) . '</span></a>';
			$position = 1; //Incrementer for each node (for schema tags)

			if ( !empty($data['force']) ){ //If using forced override
				echo '<ol class="nebula-breadcrumbs" itemscope itemtype="http://schema.org/BreadcrumbList">';

				foreach ( $data['force'] as $node ){
					$node_text = ( !empty($node['text']) )? $node['text'] : $node[0];
					$node_url = false;
					if ( !empty($node['url']) ){
						$node_url = $node['url'];
					} else {
						if ( !empty($node[1]) ){
							$node_url = $node[1];
						}
					}

					if ( !empty($node_text) ){
						if ( !empty($node_url) ){
							echo '<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"><a href="' . $node_url . '" itemprop="item">';
						}

						echo '<span itemprop="name">' . $node_text . '</span>';

						if ( !empty($node_url) ){
							echo '</a><meta itemprop="position" content="' . $position . '" /></li>';
						}

						echo ' ' . $data['delimiter_html'] . ' ';
					}

					$position++;
				}

				if ( !empty($data['current']) ){
					echo $data['current_node'] . '<meta itemprop="position" content="' . $position . '" />' . $data['after'];
				}

				echo '</ol>';
			} elseif ( is_home() || is_front_page() ){
				echo '<ol class="nebula-breadcrumbs" itemscope itemtype="http://schema.org/BreadcrumbList"><li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"><a href="' . $data['home_link'] . '" itemprop="item"><span itemprop="name">' . $data['home'] . ' <span class="visually-hidden">' . get_bloginfo('title') . '</span></span></a><meta itemprop="position" content="' . $position . '" /></li></ol>';
				$position++;
				return false;
			} else {
				echo '<ol class="nebula-breadcrumbs" itemscope itemtype="http://schema.org/BreadcrumbList"><li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"><a href="' . $data['home_link'] . '" itemprop="item"><span itemprop="name">' . $data['home'] . ' <span class="visually-hidden">' . get_bloginfo('title') . '</span></span></a><meta itemprop="position" content="' . $position . '" /></li> ' . $data['delimiter_html'] . ' ';
				$position++;

				if ( is_category() ){
					$thisCat = get_category(get_query_var('cat'), false);
					if ( $thisCat->parent !== 0 ){
						$parents = get_ancestors($thisCat->parent, 'category', 'taxonomy');
						array_unshift($parents, $thisCat->parent);
						foreach ( array_reverse($parents) as $term_id ){
							$parent = get_term($term_id, 'category');
							echo '<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"><a href="' . esc_url(get_term_link($parent->term_id, 'category')) . '" itemprop="item"><span itemprop="name">' . $parent->name . '</span></a><meta itemprop="position" content="' . $position . '" /></li> ' . $data['delimiter_html'] . ' ';
							$position++;
						}
					}

					$prefix = '';
					if ( $data['prefix'] === 'icon' ){
						$prefix = '<i class="fa-solid fa-bookmark"></i>';
					} elseif ( $data['prefix'] === 'text' ){
						$prefix = 'Category: ';
					}

					echo apply_filters('nebula_breadcrumbs_category', $data['before'] . '<a class="current-breadcrumb-link" href="' . get_category_link($thisCat->term_id) . '" itemprop="item"><span itemprop="name">' . $prefix . single_cat_title('', false) . '</span></a><meta itemprop="position" content="' . $position . '" />' . $data['after'], $data);
					$position++;
				} elseif ( is_search() ){
					echo $data['before'] . 'Search results' . $data['after'];
				} elseif ( is_day() ){
					echo '<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"><a href="' . get_year_link(get_the_time('Y')) . '" itemprop="item"><span itemprop="name">' . get_the_time('Y') . '</span></a><meta itemprop="position" content="' . $position . '" /></li> ' . $data['delimiter_html'] . ' ';
					$position++;

					echo '<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"><a href="' . get_month_link(get_the_time('Y'), get_the_time('m')) . '" itemprop="item"><span itemprop="name">' . get_the_time('F') . '</span></a><meta itemprop="position" content="' . $position . '" /></li> ' . $data['delimiter_html'] . ' ';
					$position++;

					echo $data['before'] . get_the_time('d') . $data['after'];
				} elseif ( is_month() ){
					echo '<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"><a href="' . get_year_link(get_the_time('Y')) . '" itemprop="item"><span itemprop="name">' . get_the_time('Y') . '</span></a><meta itemprop="position" content="' . $position . '" /></li> ' . $data['delimiter_html'] . ' ';
					$position++;

					echo $data['before'] . get_the_time('F') . $data['after'];
				} elseif ( is_year() ){
					echo $data['before'] . get_the_time('Y') . $data['after'];
				} elseif ( is_single() && !is_attachment() ){
					if ( get_post_type() !== 'post' ){ //Custom Post Type
						$post_type = get_post_type_object(get_post_type());

						$slug = $post_type->rewrite;
						if ( is_string($post_type->has_archive) ){ //If the post type has a custom archive slug
							$slug['slug'] = $post_type->has_archive; //Replace slug with the custom archive slug string
						}

						echo '<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"><a href="' . $data['home_link'] . $slug['slug'] . '/" itemprop="item"><span itemprop="name">' . $post_type->labels->name . '</span></a><meta itemprop="position" content="' . $position . '" /></li>'; //Changed from singular_name so plurals would appear in breadcrumb nodes
						$position++;

						//Check for parent "pages" on the custom post type and output them if they exist
						$parent_id = $post->post_parent;
						if ( !empty($parent_id) ){
							echo $data['delimiter_html'];
							$breadcrumbs = array();

							while ( $parent_id ){
								$page = get_page($parent_id);
								$breadcrumbs[] = '<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"><a href="' . get_permalink($page->ID) . '" itemprop="item"><span itemprop="name">' . strip_tags(get_the_title($page->ID)) . '</span></a><meta itemprop="position" content="' . $position . '" /></li>';
								$position++;
								$parent_id = $page->post_parent;
							}

							$breadcrumbs = array_reverse($breadcrumbs);
							$breadcrumbs_nodes = count($breadcrumbs);
							for ( $i = 0; $i < $breadcrumbs_nodes; $i++ ){
								echo $breadcrumbs[$i];
								if ( $i !== $breadcrumbs_nodes-1 ){
									echo ' ' . $data['delimiter_html'] . ' ';
								}
							}
						}

						if ( !empty($data['current']) ){
							echo ' ' . $data['delimiter_html'] . ' ' . $data['current_node'] . '<meta itemprop="position" content="' . $position . '" />' . $data['after'];
						}
					} else { //Post Category
						$cat = get_the_category();
						if ( !empty($cat) ){
							$cat = $cat[0];

							echo '<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"><a href="' . esc_url(get_term_link($cat->term_id, 'category')) . '" itemprop="item"><span itemprop="name">' . $cat->name . '</span></a><meta itemprop="position" content="' . $position . '" /></li> ' . $data['delimiter_html'] . ' ';
							$position++;

							if ( !empty($data['current']) ){
								echo $data['current_node'] . '<meta itemprop="position" content="' . $position . '" />' . $data['after'];
							}
						}
					}
				} elseif ( !is_single() && !is_page() && get_post_type() !== 'post' && !is_404() ){
					if( $post_type = get_post_type_object( get_post_type() ) ){
						echo $data['before'] . '<span itemprop="name">' . $post_type->labels->name . '</span><meta itemprop="position" content="' . $position . '" />' . $data['after'];
					}else{
						echo $data['before'] . get_queried_object()->name . $data['after'];
					}
				} elseif ( is_attachment() ){ //@TODO "Nebula" 0: Check for gallery pages? If so, it should be Home > Parent(s) > Gallery > Attachment
					if ( !empty($post->post_parent) ){ //@TODO "Nebula" 0: What happens if the page parent is a child of another page?
						echo '<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"><a href="' . get_permalink($post->post_parent) . '" itemprop="item"><span itemprop="name">' . strip_tags(get_the_title($post->post_parent)) . '</span></a><meta itemprop="position" content="' . $position . '" /></li> ' . $data['delimiter_html'] . ' ' . strip_tags(get_the_title());
						$position++;
					} else {
						echo strip_tags(get_the_title());
					}
				} elseif ( is_page() && !$post->post_parent ){ //Page without ancestors/parents
					if ( !empty($data['current']) ){
						echo $data['current_node'] . '<meta itemprop="position" content="' . $position . '" />' . $data['after'];
					}
				} elseif ( is_page() && $post->post_parent ){ //Page with ancestors/parents
					$parent_id = $post->post_parent;
					$breadcrumbs = array();

					while ( $parent_id ){
						$page = get_page($parent_id);
						$breadcrumbs[] = '<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"><a href="' . get_permalink($page->ID) . '" itemprop="item"><span itemprop="name">' . strip_tags(get_the_title($page->ID)) . '</span></a><meta itemprop="position" content="' . $position . '" /></li>';
						$position++;
						$parent_id = $page->post_parent;
					}

					$breadcrumbs = array_reverse($breadcrumbs);
					$breadcrumbs_nodes = count($breadcrumbs);
					for ( $i = 0; $i < $breadcrumbs_nodes; $i++ ){
						echo $breadcrumbs[$i];
						if ( $i !== $breadcrumbs_nodes-1 ){
							echo ' ' . $data['delimiter_html'] . ' ';
						}
					}

					if ( !empty($data['current']) ){
						echo ' ' . $data['delimiter_html'] . ' ' . $data['current_node'] . '<meta itemprop="position" content="' . $position . '" />' . $data['after'];
					}
				} elseif ( is_tag() ){
					$prefix = '';
					if ( $data['prefix'] === 'icon' ){
						$prefix = '<i class="fa-solid fa-tag"></i>';
					} elseif ( $data['prefix'] === 'text' ){
						$prefix = 'Tag: ';
					}

					echo apply_filters('nebula_breadcrumbs_tag', $data['before'] . $prefix . '<span itemprop="name">' . single_tag_title('', false) . '</span><meta itemprop="position" content="' . $position . '" />' . $data['after'], $data);
					//echo $data['before'] . '<a class="current-breadcrumb-link" href="' . get_tag_link($thisTag->term_id) . '">'. $prefix . single_tag_title('', false) . '</a>' . $data['after']; //@todo "Nebula": Need to get $thisTag like $thisCat above
				} elseif ( is_author() ){
					//@TODO "Nebula" 0: Support for multi author? is_multi_author()

					global $author;
					$userdata = get_userdata($author);
					echo apply_filters('nebula_breadcrumbs_author', $data['before'] . '<span itemprop="name">' . $userdata->display_name . '</span><meta itemprop="position" content="' . $position . '" />' . $data['after'], $data);
				} elseif ( is_404() ){
					echo apply_filters('nebula_breadcrumbs_error', $data['before'] . '<span itemprop="name">Error 404</span>' . $data['after'], $data);
				}

				if ( get_query_var('paged') ){
					echo apply_filters('nebula_breadcrumbs_paged', '&nbsp;(Page ' . get_query_var('paged') . ')', $data); //nbsp is needed here because something is stripping out the first space
				}
				echo '</ol>';
			}

			$this->timer('Breadcrumbs', 'end');
		}

		//Modified WordPress search form using Bootstrap components
		public function search_form($form=null, $button=true){
			$override = apply_filters('pre_nebula_search_form', null, $form);
			if ( isset($override) ){return $override;}

			$placeholder = ( get_search_query() )? get_search_query() : __('Search', 'nebula');

			$form = '<search><form id="searchform" class="row gx-2 ignore-form" role="search" method="get" action="' . home_url('/') . '">
						<div class="col">
							<div class="input-group">
								<div class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></div>
								<label class="visually-hidden" for="s">Search</label>
								<input id="s" class="form-control ignore-form" type="text" name="s" value="' . get_search_query() . '" placeholder="' . $placeholder . '" role="search" autocorrect="off" autocapitalize="off" spellcheck="false" />
							</div>
						</div>';

			if ( !empty($button) ){
				$form .= '<div class="col"><button id="searchsubmit" class="btn btn-brand wp_search_submit mb-2" type="submit">' . __('Submit', 'nebula') . '</button></div>';
			}

			$form .= '</form></search>';

			return $form;
		}

		//Easily create markup for a Hero area search input
		public function hero_search($placeholder=false){
			if ( empty($placeholder) ){
				$placeholder = __('What are you looking for?', 'nebula');
			}

			$override = apply_filters('pre_nebula_hero_search', null, $placeholder);
			if ( isset($override) ){return $override;}

			$form = '<search id="nebula-hero-formcon">
					<form id="nebula-hero-search" class="form-group search ignore-form" method="get" action="' . home_url('/') . '" role="search">
						<div class="input-group">
							<i class="fa-solid fa-magnifying-glass"></i>
							<label class="visually-hidden" for="nebula-hero-search-input">Autocomplete Search</label>
							<input id="nebula-hero-search-input" type="search" class="form-control open input search nofade ignore-form" name="s" placeholder="' . $placeholder . '" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" tabindex="0" x-webkit-speech />
						</div>
					</form>
				</search>';

			return $form;
		}

		//Infinite Load
		//Ajax call handle in nebula()->infinite_load();
		public function infinite_load_query($args=array('post_status' => 'publish', 'showposts' => 4), $loop=false){
			$timer_name = $this->timer('Infinite Load Query');

			$override = apply_filters('pre_nebula_infinite_load_query', null);
			if ( isset($override) ){return;}

			global $wp_query;
			if ( empty($args['paged']) ){
				$args['paged'] = 1;
				if ( get_query_var('paged') ){
					$args['paged'] = get_query_var('paged');
					?>
					<div class="infinite-start-note">
						<a href="<?php echo get_the_permalink(); ?>">&laquo; <?php _e('Back to page 1', 'nebula'); ?></a>
					</div>
					<?php
				} elseif ( !empty($wp_query->query['paged']) ){
					$args['paged'] = $wp_query->query['paged'];
					?>
					<div class="infinite-start-note">
						<a href="<?php echo get_the_permalink(); ?>">&laquo; <?php _e('Back to page 1', 'nebula'); ?></a>
					</div>
					<?php
				}
			}

			query_posts($args);

			if ( empty($args['post_type']) ){
				$post_type_label = 'posts';
			} else {
				$post_type = ( is_array($args['post_type']) )? $args['post_type'][0] : $args['post_type'];
				$post_type_obj = get_post_type_object($args['post_type']);
				$post_type_label = lcfirst($post_type_obj->label);
			}
			?>

			<div id="infinite-posts-list" data-max-pages="<?php echo $wp_query->max_num_pages; ?>" data-max-posts="<?php echo $wp_query->found_posts; ?>">
				<?php
				$loop = sanitize_text_field($loop);
				if ( !$loop ){
					get_template_part('loop');
				} else {
					if ( function_exists($loop) ){
						call_user_func($loop);
					} elseif ( locate_template($loop . '.php') ){
						get_template_part($loop);
					} else {
						if ( $this->is_dev() ){
							echo '<strong>Warning:</strong> The custom loop template or function ' . $loop . ' does not exist! Falling back to loop.php.';
						}
						get_template_part('loop');
					}
				}
				?>
			</div>

			<?php do_action('nebula_infinite_before_load_more'); ?>

			<div class="loadmorecon <?php echo ( $args['paged'] >= $wp_query->max_num_pages )? 'disabled' : ''; ?>">
				<a class="infinite-load-more" href="#"><?php echo ( $args['paged'] >= $wp_query->max_num_pages )? 'No more ' . $post_type_label . '.' : 'Load More'; ?></a>
				<div class="infinite-loading">
					<div class="a"></div> <div class="b"></div> <div class="c"></div>
				</div>
			</div>

			<script><?php //Must be in PHP so $args can be encoded. @todo "Nebula" 0: This must have to load in the footer if jQuery is set to the footer...? ?>
				window.addEventListener('load', function(){
					var pageNumber = <?php echo $args['paged']; ?>+1;

					document.querySelector('.infinite-load-more').addEventListener('click', function(e){
						var maxPages = document.getElementById('infinite-posts-list').getAttribute('data-max-pages');
						var maxPosts = document.getElementById('infinite-posts-list').getAttribute('data-max-posts');

						if ( pageNumber <= maxPages ){
							document.querySelector('.loadmorecon').classList.add('loading');

							fetch(nebula.site.ajax.url, {
								method: 'POST',
								credentials: 'same-origin',
								headers: {
									'Content-Type': 'application/x-www-form-urlencoded',
									'Cache-Control': 'no-cache',
								},
								body: new URLSearchParams({
									nonce: nebula.site.ajax.nonce,
									action: 'nebula_infinite_load',
									page: pageNumber,
									args: JSON.stringify(<?php echo wp_json_encode($args); ?>),
									loop: <?php echo wp_json_encode($loop); ?>,
								}),
								priority: 'high'
							}).then(function(response){
								if ( response.ok ){
									return response.text();
								}
							}).then(function(response){
								let newDiv = document.createElement('div');
								newDiv.className = 'clearfix infinite-page infinite-page-' + (pageNumber-1) + ' sliding';
								newDiv.setAttribute('style', 'display: none;');
								newDiv.innerHTML = response;
								document.getElementById('infinite-posts-list').appendChild(newDiv);

								jQuery('.infinite-page-' + (pageNumber-1)).slideDown({ //I would like to remove this jQuery so this embedded script tag does not have a potential race condition depending on where jQuery is loaded. Try animating the height some other way
									duration: 750,
									//easing: 'easeInOutQuad',
									complete: function(){
										document.querySelector('.loadmorecon').classList.remove('loading');
										document.querySelector('.infinite-page.sliding').classList.remove('sliding');
										document.dispatchEvent(new Event('nebula_infinite_slidedown_complete'));
									}
								});

								if ( pageNumber >= maxPages ){
									document.querySelector('.loadmorecon').classList.add('disabled').querySelector('a').innerHTML = '<?php __('No more', 'nebula'); ?> <?php echo $post_type_label; ?>.';
								}

								var newQueryStrings = '';
								if ( typeof document.URL.split('?')[1] !== 'undefined' ){
									newQueryStrings = '?' + document.URL.split('?')[1].replace(/[?&]paged=\d+/, '');
								}

								history.replaceState(null, document.title, nebula.post.permalink + 'page/' + pageNumber + newQueryStrings);
								document.dispatchEvent(new Event('nebula_infinite_finish'));

								gtag('event', 'Load More', {
									event_category: 'Infinite Query',
									event_label: 'Loaded page ' + pageNumber,
								});

								pageNumber++;
							}).catch(function(error){
								document.dispatchEvent(new Event('nebula_infinite_finish'));
								gtag('event', 'exception', {
									message: 'AJAX Error: Infinite Query Load More AJAX',
									fatal: false
								});
							});
						}

						e.preventDefault();
					});
				});
			</script>
			<?php
			$this->timer($timer_name, 'end');
		}

		//Infinite Load AJAX Call
		public function infinite_load(){
			if ( !wp_verify_nonce($this->super->post['nonce'], 'nebula_ajax_nonce') ){ die('Permission Denied.'); }

			$page_number = sanitize_text_field($this->super->post['page']);
			$args = json_decode(stripslashes($this->super->post['args']), true); //Remove escaped slashes and decode to an array
			$args['paged'] = $page_number; //Add the page number to the array
			$loop = sanitize_text_field($this->super->post['loop']);
			$args = array_map('esc_attr', $args); //Sanitize the args array

			query_posts($args);

			if ( $loop == 'false' ){
				get_template_part('loop');
			} else {
				call_user_func($loop); //Custom loop callback function must be defined in a functions file (not a template file) for this to work.
			}

			wp_die();
		}

		//Related Posts by term frequency
		public function related_posts($post_id=null, $args=array()){
			$this->timer('Related Posts');

			global $post, $wpdb;

			$post_id = intval($post_id);
			if ( !$post_id && $post->ID ){
				$post_id = $post->ID;
			}

			if ( !$post_id ){
				return false; //Post ID is required for this function
			}

			$defaults = array(
				'taxonomy' => 'post_tag',
				'post_type' => array('post'),
				'max' => 5
			);
			$options = wp_parse_args($args, $defaults);

			$related_post_ids = nebula()->transient('nebula-related-' . $options['taxonomy'] . '-' . $post_id, function($data){
				$term_args = array(
					'fields' => 'ids',
					'orderby' => 'count', //Sort by frequency
					'order' => 'ASC' //Least popular to most popular
				);

				$orig_terms_set = wp_get_object_terms($data['post_id'], $data['options']['taxonomy'], $term_args);
				$orig_terms_set = array_map('intval', $orig_terms_set); //Make sure each returned term id to be an integer.
				$terms_to_iterate = $orig_terms_set; //Store a copy that we'll be reducing by one item for each iteration.

				$post_args = array(
					'fields' => 'ids',
					'post_type' => $data['options']['post_type'],
					'post__not_in' => array($data['post_id']),
					'posts_per_page' => 50 //Start with more than enough posts
				);

				$related_post_ids = array();

				//Loop through the terms to find posts that contain multiple terms (term1 AND term2 AND term3)
				while ( count($terms_to_iterate) > 1 ){
					$post_args['tax_query'] = array(
						array(
							'taxonomy' => $data['options']['taxonomy'],
							'field' => 'id',
							'terms' => $terms_to_iterate,
							'operator' => 'AND'
						)
					);

					$posts = get_posts($post_args);
					foreach( $posts as $id ){
						$id = intval($id);
						if ( !in_array($id, $related_post_ids) ){
							$related_post_ids[] = $id;
						}
					}

					array_pop($terms_to_iterate); //Remove the least related post ID
				}

				$post_args['posts_per_page'] = $data['options']['max']; //Reduce the number to our desired max
				$post_args['tax_query'] = array(
					array(
						'taxonomy' => $data['options']['taxonomy'],
						'field' => 'id',
						'terms' => $orig_terms_set
					)
				);

				//Check for posts that contain any of the terms (to fill out the desired max)
				$posts = get_posts($post_args);
				foreach ( $posts as $count => $id ){
					$id = intval($id);
					if ( !in_array($id, $related_post_ids) ){
						$related_post_ids[] = $id;
					}

					if ( count($related_post_ids) > $data['options']['max'] ){
						break; //We have enough related post IDs now, stop the loop.
					}
				}

				return $related_post_ids;
			}, array('options' => $options, 'post_id' => $post_id), DAY_IN_SECONDS);

			if ( !$related_post_ids ){
				$this->timer('Related Posts', 'end');
				return false;
			}

			//Query for the related post IDs
			$query_options = array(
				'post__in' => $related_post_ids,
				'orderby' => 'post__in',
				'post_type' => $options['post_type'],
				'posts_per_page' => min(array(count($related_post_ids), $options['max'])),
			);

			$this->timer('Related Posts', 'end');
			return new WP_Query($query_options);
		}

		//Check for single category templates with the filename single-cat-slug.php or single-cat-id.php
		public function single_category_template($single_template){
			if ( !empty($single_template) ){
				global $wp_query, $post;

				//Check for single template by category slug and ID
				foreach ( get_the_category() as $category ){
					if ( file_exists(get_stylesheet_directory() . '/single-cat-' . $category->slug . '.php') ){
						return get_stylesheet_directory() . '/single-cat-' . $category->slug . '.php';
					} elseif ( file_exists(get_stylesheet_directory() . '/single-cat-' . $category->term_id . '.php') ){
						return get_stylesheet_directory() . '/single-cat-' . $category->term_id . '.php';
					} elseif ( file_exists(get_template_directory() . '/single-cat-' . $category->term_id . '.php') ){
						return get_template_directory() . '/single-cat-' . $category->term_id . '.php';
					} elseif ( file_exists(get_template_directory() . '/single-cat-' . $category->term_id . '.php') ){
						return get_template_directory() . '/single-cat-' . $category->term_id . '.php';
					}
				}
			}

			return $single_template;
		}

		//Feedback System
		//If no CF7 form ID is provided, this simply logs yes/no from users in Google Analytics
		//Nebula does not automatically add this to pages! It must be added in the child theme. Consider adding a function in via hooks such as 'loop_end', 'nebula_after_search_results', 'nebula_no_search_results', 'nebula_404_content' to conditionally/dynamically add this feedback form. Refer to the child functions file for an example.
		public function feedback($form_id=false){
			?>
				<div id="nebula-feedback-system" class="<?php echo ( empty($form_id) )? 'no-feedback-form' : 'has-feedback-form'; ?>">
					<div id="nebula-feedback-question" class="">
						<span><?php echo __('Was this page helpful?', 'nebula'); ?></span> <a id="nebula-feedback-yes" class="nebula-feedback-button" href="#"><i class="fa-solid fa-fw fa-thumbs-up"></i> <?php echo __('Yes', 'nebula'); ?></a> <a id="nebula-feedback-no" class="nebula-feedback-button" href="#"><i class="fa-solid fa-fw fa-thumbs-down"></i> <?php echo __('No', 'nebula'); ?></a>
					</div>

					<?php if ( !empty($form_id) ): ?>
						<div id="nebula-feedback-form-container" data-form-id="<?php echo $form_id; ?>">
							<?php echo do_shortcode('[contact-form-7 id="' . $form_id . '"]'); ?>
						</div>
					<?php endif; ?>

					<div id="nebula-feedback-thanks">
						<span><?php echo __('Thank you for your feedback!', 'nebula'); ?></span>
					</div>
				</div>
			<?php
		}

		//Check if business hours exist in Nebula Options
		public function has_business_hours(){
			//Check object cache first so the loop logic does not need to run more than once
			$has_business_hours = wp_cache_get('has_business_hours');
			if ( is_string($has_business_hours) ){
				if ( strtolower($has_business_hours) === 'true' ){
					return true;
				}

				return false;
			}

			foreach ( array('sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday') as $weekday ){
				if ( $this->get_option('business_hours_' . $weekday . '_enabled') || $this->get_option('business_hours_' . $weekday . '_open') || $this->get_option('business_hours_' . $weekday . '_close') ){
					wp_cache_set('has_business_hours', 'true');
					return true;
				}
			}

			wp_cache_set('has_business_hours', 'false');
			return false;
		}

		//Check if the requested datetime is within business hours.
		//If $general is true this function returns true if the business is open at all on that day
		public function is_business_open($date=null, $general=false){ return $this->business_open($date, $general); }
		public function is_business_closed($date=null, $general=false){ return !$this->business_open($date, $general); }
		public function business_open($date=null, $general=false){
			$override = apply_filters('pre_business_open', null, $date, $general);
			if ( isset($override) ){return $override;}

			//Check object cache first so the full loop logic does not need to run more than once
			$is_business_open = wp_cache_get('is_business_open');
			if ( is_string($is_business_open) ){
				if ( strtolower($is_business_open) === 'true' ){
					return true;
				}

				return false;
			}

			nebula()->timer('Is Business Open');

			if ( $this->has_business_hours() ){
				if ( empty($date) || $date === 'now' ){
					$date = time();
				} elseif ( strtotime($date) ){
					$date = strtotime($date . ' ' . date('g:ia', strtotime('now')));
				}
				$today = strtolower(date('l', $date));

				$businessHours = array();
				foreach ( array('sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday') as $weekday ){
					$businessHours[$weekday] = array(
						'enabled' => $this->get_option('business_hours_' . $weekday . '_enabled'),
						'open' => $this->get_option('business_hours_' . $weekday . '_open'),
						'close' => $this->get_option('business_hours_' . $weekday . '_close')
					);
				}

				$days_off = ( !empty($this->get_option('business_hours_closed')) )? $this->get_option('business_hours_closed') : ''; //Ensure correct type
				$days_off = array_filter(explode(', ', $days_off));
				if ( !empty($days_off) ){
					foreach ( $days_off as $key => $day_off ){
						$days_off[$key] = strtotime($day_off . ' ' . date('Y', $date));

						if ( date('N', $days_off[$key]) === 6 ){ //If the date is a Saturday
							$days_off[$key] = strtotime(date('F j, Y', $days_off[$key]) . ' -1 day');
						} elseif ( date('N', $days_off[$key]) === 7 ){ //If the date is a Sunday
							$days_off[$key] = strtotime(date('F j, Y', $days_off[$key]) . ' +1 day');
						}

						if ( date('Ymd', $days_off[$key]) === date('Ymd', $date) ){
							nebula()->timer('Is Business Open', 'end');
							wp_cache_set('is_business_open', 'false');
							return false;
						}
					}
				}

				if ( $businessHours[$today]['enabled'] == '1' ){ //If the Nebula Options checkmark is checked for this day of the week.
					if ( !empty($general) ){
						nebula()->timer('Is Business Open', 'end');
						wp_cache_set('is_business_open', 'true');
						return true;
					}

					$openToday = date('Gi', strtotime($businessHours[$today]['open']));
					$closeToday = date('Gi', strtotime($businessHours[$today]['close'])-1); //Subtract one second to ensure midnight represents the same day
					if ( date('Gi', $date) >= $openToday && date('Gi', $date) <= $closeToday ){
						nebula()->timer('Is Business Open', 'end');
						wp_cache_set('is_business_open', 'true');
						return true;
					}
				}
			}

			nebula()->timer('Is Business Open', 'end');
			wp_cache_set('is_business_open', 'false');
			return false;
		}

		//If the business is open, return the time that the business closes today
		public function business_open_until($day){
			//@todo "Nebula" 0: Use null coalescing operator here if possible
			if ( empty($day) ){
				$day = strtolower(date('l'));
			}

			if ( $this->is_business_open() ){
				return esc_html($this->get_option('business_hours_' . $day . '_close'));
			}

			return false;
		}

		//Get the relative time of day
		public function relative_time($format=null){
			$override = apply_filters('pre_nebula_relative_time', null, $format);
			if ( isset($override) ){return $override;}

			if ( $this->contains(date('H'), array('00', '01', '02')) ){
				$relative_time = array(
					'description' => array('early', 'night'),
					'standard' => array(0, 1, 2),
					'military' => array(0, 1, 2),
					'ampm' => 'am'
				);
			} elseif ( $this->contains(date('H'), array('03', '04', '05')) ){
				$relative_time = array(
					'description' => array('late', 'night'),
					'standard' => array(3, 4, 5),
					'military' => array(3, 4, 5),
					'ampm' => 'am'
				);
			} elseif ( $this->contains(date('H'), array('06', '07', '08')) ){
				$relative_time = array(
					'description' => array('early', 'morning'),
					'standard' => array(6, 7, 8),
					'military' => array(6, 7, 8),
					'ampm' => 'am'
				);
			} elseif ( $this->contains(date('H'), array('09', '10', '11')) ){
				$relative_time = array(
					'description' => array('late', 'morning'),
					'standard' => array(9, 10, 11),
					'military' => array(9, 10, 11),
					'ampm' => 'am'
				);
			} elseif ( $this->contains(date('H'), array('12', '13', '14')) ){
				$relative_time = array(
					'description' => array('early', 'afternoon'),
					'standard' => array(12, 1, 2),
					'military' => array(12, 13, 14),
					'ampm' => 'pm'
				);
			} elseif ( $this->contains(date('H'), array('15', '16', '17')) ){
				$relative_time = array(
					'description' => array('late', 'afternoon'),
					'standard' => array(3, 4, 5),
					'military' => array(15, 16, 17),
					'ampm' => 'pm'
				);
			} elseif ( $this->contains(date('H'), array('18', '19', '20')) ){
				$relative_time = array(
					'description' => array('early', 'evening'),
					'standard' => array(6, 7, 8),
					'military' => array(18, 19, 20),
					'ampm' => 'pm'
				);
			} elseif ( $this->contains(date('H'), array('21', '22', '23')) ){
				$relative_time = array(
					'description' => array('late', 'evening'),
					'standard' => array(9, 10, 11),
					'military' => array(21, 22, 23),
					'ampm' => 'pm'
				);
			}

			if ( !empty($format) ){
				return $relative_time[$format];
			} else {
				return $relative_time;
			}
		}

		//Get the appropriate logo from the themes, plugin, or Customizer
		public function logo($location='header'){
			$timer_name = $this->timer('Finding Appropriate Logo (' . $location . ')');

			//Allow a theme or plugin to handle the logo itself. This assumes it does its own priorities or overrides for everything!
			$hooked_logo = apply_filters('nebula_logo', false);
			if ( !empty($hooked_logo) ){
				$this->timer($timer_name, 'end');
				return $hooked_logo;
			}

			$logo = false;
			$logo_filename = apply_filters('nebula_logo_filename', 'logo'); //Allow themes and plugins to set the logo filename to use. No extension here!

			//Search the parent theme for the logo file (SVG or PNG)
			if ( file_exists(get_template_directory() . '/assets/img/' . $logo_filename . '.svg') && $location !== 'meta' ){
				$logo = get_template_directory_uri() . '/assets/img/' . $logo_filename . '.svg';
			} elseif ( file_exists(get_template_directory() . '/assets/img/' . $logo_filename . '.png') ){
				$logo = get_template_directory_uri() . '/assets/img/' . $logo_filename . '.png';
			}

			//Search the child theme for the logo file (SVG or PNG)
			if ( file_exists(get_stylesheet_directory() . '/assets/img/' . $logo_filename . '.svg') && $location !== 'meta' ){
				$logo = get_stylesheet_directory_uri() . '/assets/img/' . $logo_filename . '.svg';
			} elseif ( file_exists(get_stylesheet_directory() . '/assets/img/' . $logo_filename . '.png') ){
				$logo = get_stylesheet_directory_uri() . '/assets/img/' . $logo_filename . '.png';
			}

			//If full color Customizer logo exists
			if ( get_theme_mod('custom_logo') ){
				$logo = $this->get_thumbnail_src(get_theme_mod('custom_logo'));
			}

			//If it is the footer and the one-color logo (footer) is requested (checkbox)
			if ( $location === 'footer' && get_theme_mod('nebula_footer_single_color_logo') ){
				if ( get_theme_mod('one_color_logo') ){ //If one-color Customizer logo exists
					$this->timer($timer_name, 'end');
					return $this->get_thumbnail_src(get_theme_mod('one_color_logo'));
				}
			}

			//If it is the home page and the one-color logo (home) is requested (checkbox)
			if ( is_front_page() && get_theme_mod('nebula_hero_single_color_logo') && $location !== 'meta' ){
				if ( get_theme_mod('one_color_logo') ){ //If one-color Customizer logo exists
					$this->timer($timer_name, 'end');
					return $this->get_thumbnail_src(get_theme_mod('one_color_logo'));
				}
			}

			//If it a sub page and the one-color (sub) logo is requested (checkbox)
			if ( !is_front_page() && get_theme_mod('nebula_header_single_color_logo') && $location !== 'meta' ){
				if ( get_theme_mod('one_color_logo') ){ //If one-color Customizer logo exists
					$this->timer($timer_name, 'end');
					return $this->get_thumbnail_src(get_theme_mod('one_color_logo'));
				}
			}

			$this->timer($timer_name, 'end');
			return esc_url($logo);
		}

		//Get a datapoint for a user
		public function get_user_info($datapoint, $options=array()){
			$defaults = array(
				'id' => get_current_user_id(),
				'datapoint' => $datapoint,
				'fresh' => false,
				'prepend' => '',
				'append' => '',
				'fallback' => false,
			);

			$data = array_merge($defaults, $options);

			if ( empty($data['id']) ){ //If there is no user or current user is not logged in
				return $data['fallback'];
			}

			//Get from object cache unless specifically requested fresh data
			if ( !$data['fresh'] ){
				$userdata = wp_cache_get('nebula_user_info', 'user-id-' . $data['id']);
			}
			if ( empty($userdata) ){
				$userdata = get_userdata($data['id']);
				wp_cache_set('nebula_user_info', $userdata, 'user-id-' . $data['id']); //Store in object cache
			}

			if ( !empty($data['datapoint']) ){
				$requested_data = $data['datapoint'];

				if ( !empty($userdata->$requested_data) ){
					return $data['prepend'] . $userdata->$requested_data . $data['append'];
				} else {
					return $data['fallback'];
				}
			}

			if ( !empty($userdata) ){
				return $userdata;
			}

			return $data['fallback'];
		}

		//Determine if the author should be the Company Name or the specific author's name.
		public function the_author($show_authors=1){
			$override = apply_filters('pre_nebula_the_author', null, $show_authors);
			if ( isset($override) ){return $override;}

			if ( !is_single() || $show_authors === 0 || !$this->get_option('author_bios') ){
				return $this->get_option('site_owner', get_bloginfo('name'));
			} else {
				//@TODO "Nebula" 0: Add support for multi-authors? is_multi_author()
				return ( get_the_author_meta('first_name') != '' )? get_the_author_meta('first_name') . ' ' . get_the_author_meta('last_name') : get_the_author_meta('display_name');
			}
		}

		//Register the Navigation Menus
		public function nav_menu_locations(){
			$override = apply_filters('pre_nebula_nav_menu_locations', null);
			if ( isset($override) ){return;}

			register_nav_menus(array(
				'utility' => 'Utility Menu',
				'primary' => 'Primary Menu',
				'offcanvas' => 'Offcanvas Menu',
				'footer' => 'Footer Menu'
			));
		}

		//Add navigation menu item attributes (and metadata)
		public function add_menu_attributes($atts, $item, $args){
			$atts['itemprop'] = 'url';
			return $atts;
		}

		//Disable support for trackbacks in post types
		public function disable_trackbacks(){
			$post_types = get_post_types();
			foreach ( $post_types as $post_type ){
				remove_post_type_support($post_type, 'trackbacks');
			}
		}

		//Twitter cached feed
		public function twitter_cache($options=array()){
			$defaults = apply_filters('nebula_twitter_cache_defaults', array(
				'user' => 'Great_Blakes',
				'list' => null,
				'number' => 5,
				'retweets' => 1,
			));

			$options = ( is_array($options) )? $options : array(); //Ensure the provided options is an array (and assign an empty array if it does not exist). Remember, this function may be called via WP hook.

			$data = array_merge($defaults, $options);
			$post = $this->super->post; //Get the $_POST data

			if ( !empty($post['data']) ){
				if ( !wp_verify_nonce($post['nonce'], 'nebula_ajax_nonce') ){ die('Permission Denied.'); }
				$data['user'] = ( isset($post['data']['user']) )? sanitize_text_field($post['data']['user']) : $defaults['user'];
				$data['list'] = ( isset($post['data']['list']) )? sanitize_text_field($post['data']['list']) : $defaults['list']; //Only used for list feeds
				$data['number'] = ( isset($post['data']['number']) )? sanitize_text_field($post['data']['number']) : $defaults['number'];
				$data['retweets'] = ( isset($post['data']['retweets']) )? sanitize_text_field($post['data']['retweets']) : $defaults['retweets']; //1: Yes, 0: No
			}

			$twitter_timing_id = $this->timer('Twitter Cache (' . $data['user'] . ')', 'start', 'Twitter Cache');

			error_reporting(0); //Prevent PHP errors from being cached.

			if ( !empty($data['list']) ){
				$feed = 'https://api.twitter.com/1.1/lists/statuses.json?slug=' . $data['list'] . '&owner_screen_name=' . $data['user'] . '&count=' . $data['number'] . '&include_rts=' . $data['retweets'] . '&tweet_mode=extended';
			} else {
				$feed = 'https://api.twitter.com/1.1/statuses/user_timeline.json?screen_name=' . $data['user'] . '&count=' . $data['number'] . '&include_rts=' . $data['retweets'] . '&tweet_mode=extended';
			}

			$bearer = $this->get_option('twitter_bearer_token', '');
			if ( empty($bearer) ){
				trigger_error('A Twitter bearer token is required to get tweets', E_USER_WARNING);

				if ( !empty($post['data']) ){
					echo false;
					wp_die();
				} else {
					return false;
				}
			}

			$tweets = nebula()->transient('nebula_twitter_' . $data['user'], function($data){
				$args = array('headers' => array('Authorization' => 'Bearer ' . $data['bearer']));

				$response = $this->remote_get($data['feed'], $args);
				if ( is_wp_error($response) ){
					return false;
				}

				$tweets = json_decode($response['body']);

				//If there are no tweets -or- if an error is return (for example if an account does not exist)
				if ( empty($tweets) || !empty($tweets->errors) || !empty($tweets->error) ){
					trigger_error('No tweets were retrieved. Verify all options are correct, the requested Twitter account exists, and that an active bearer token is being used.', E_USER_NOTICE);

					if ( !empty($data['post']['data']) ){
						echo false;
						wp_die(); //Exit AJAX
					} else {
						return false;
					}
				}

				//Add convenient data to the tweet object
				foreach ( $tweets as $tweet ){
					$tweet->tweet_url = 'http://twitter.com/' . $tweet->user->screen_name . '/status/' . $tweet->id; //Add Tweet URL

					//Convert times
					$tweet->time_ago = human_time_diff(strtotime($tweet->created_at)); //Relative time
					$tweet->time_formatted = date('l, F j, Y \a\t g:ia', strtotime($tweet->created_at)); //Human readable time
					$tweet->time_ago_raw = date('U')-strtotime($tweet->created_at);

					//Convert usernames, hashtags, and URLs into clickable links and add other markup
					$tweet->markup = preg_replace(array(
						"/(http\S+)/i", //URLs (must be first)
						"/@([a-z0-9_]+)/i", //Usernames
						"/#([a-z0-9_]+)/i", //Hashtags
						"/(\d+\/(\d+)?)$/i", //Series numbers
					), array(
						"<a class='tweet-embedded-link' href='$1' target='_blank' rel='noopener'>$1</a>",
						"<a class='tweet-embedded-username' href='https://twitter.com/$1' target='_blank' rel='noopener'>@$1</a>",
						"<a class='tweet-embedded-hashtag' href='https://twitter.com/hashtag/$1' target='_blank' rel='noopener'>#$1</a>",
						"<small class='tweet-embedded-series-number'>$1</small>",
					), trim($tweet->full_text));
				}

				return $tweets;
			}, array('bearer' => $bearer, 'feed' => $feed, 'post' => $post), MINUTE_IN_SECONDS*5);

			$this->timer($twitter_timing_id, 'end');

			if ( !empty($post['data']) ){
				echo wp_json_encode($tweets);
				wp_die();
			} else {
				return $tweets;
			}
		}

		//Replace text on password protected posts to be more minimal
		public function password_form_simplify(){
			$output = '<form class="ignore-form" action="' . esc_url(site_url('wp-login.php?action=postpass', 'login_post')) . '" method="post">
							<span>' . __('Password', 'nebula') . ': </span>
							<input type="password" class="ignore-form" name="post_password" size="20" autocomplete="current-password" />
							<input type="submit" name="Submit" value="' . __('Go', 'nebula') . '" />
						</form>';
			return $output;
		}

		//Always get custom fields with post queries
		public function always_get_post_custom($posts){
			$posts_count = count($posts);
			for ( $i = 0; $i < $posts_count; $i++ ){
				$custom_fields = get_post_custom($posts[$i]->ID);
				$posts[$i]->custom_fields = $custom_fields;
			}
			return $posts;
		}

		//Prevent empty search query error (Show all results instead)
		public function redirect_empty_search($query){
			global $wp_query;
			if ( isset($this->super->get['s']) && $wp_query->query && !array_key_exists('invalid', $this->super->get) ){
				if ( $this->super->get['s'] == '' && $wp_query->query['s'] == '' && !$this->is_admin_page() ){
					header('Location: ' . home_url('/') . 'search/?invalid'); //Why not wp_redirect() here?
					exit;
				} else {
					return $query;
				}
			}
		}

		//Redirect if only single search result
		public function redirect_single_search_result(){
			if ( is_search() ){
				global $wp_query;

				if ( $wp_query->post_count == 1 && $wp_query->max_num_pages == 1 ){
					if ( isset($this->super->get['s']) ){
						//If the redirected post is the homepage, serve the regular search results page with one result (to prevent a redirect loop)
						if ( $wp_query->posts['0']->ID !== 1 && get_permalink($wp_query->posts['0']->ID) !== home_url() . '/' ){
							$this->super->get['s'] = str_replace(' ', '+', $this->super->get['s']);
							wp_redirect(get_permalink($wp_query->posts['0']->ID ) . '?rs=' . $this->super->get['s']);
							exit;
						}
					} else {
						wp_redirect(get_permalink($wp_query->posts['0']->ID) . '?rs');
						exit;
					}
				}
			}
		}

		//Explicitly exclude certain posts/types from Relevanssi search
		public function exclude_from_relevanssi($post_ok, $post_id){
			//If this is an ignored post type
			$ignore_post_types = apply_filters('nebula_autocomplete_ignore_types', array('nebula_cf7_submits')); //Allow post types to be globally ignored from relevanssi/autocomplete search
			if ( in_array(get_post_type($post_id), $ignore_post_types) ){
				return false;
			}

			//If this is an ignored post ID
			$ignore_post_ids = apply_filters('nebula_autocomplete_ignore_ids', array()); //Allow individual posts to be globally ignored from autocomplete search
			if ( in_array($post_id, $ignore_post_ids) ){
				return false;
			}

			return $post_ok;
		}

		//Autocomplete Search (REST endpoint)
		public function rest_autocomplete_search(){
			$timer_name = $this->timer('Autocomplete Search');

			if ( isset($this->super->get['term']) ){
				ini_set('memory_limit', '256M'); //@todo Nebula 0: Remove these when possible...

				$term = sanitize_text_field(trim($this->super->get['term']));
				if ( empty($term) ){
					return false;
				}

				$types = 'any';
				if ( isset($this->super->get['types']) ){
					$types =  explode(',', sanitize_text_field(trim($this->super->get['types'])));

					//This is not necessary
					$types = array_filter($types, function($type){
						return $type !== 'nebula_cf7_submits';
					});
				}

				//Prepare the standard WP search query parameters (do not include custom fields here).
				//This is used by both Relevanssi -or- the combined query later
				$initial_query_args = array(
					'post_type' => $types,
					'post_status' => 'publish',
					'posts_per_page' => 10, //Do not use numberposts or any other parameter instead here
					's' => $term,
				);

				if ( function_exists('relevanssi_do_query') ){ //If the Relevanssi plugin is active use its engine
					$should_be_sorted = false; //Let Relevanssi determine result order

					$relevanssi_query_prep = new WP_Query();
					$relevanssi_query_prep->parse_query($initial_query_args); //Must be performed this way to retrieve Relevanssi results
					$relevanssi_autocomplete = relevanssi_do_query($relevanssi_query_prep); //Run the query

					foreach ( $relevanssi_autocomplete as $post ){
						$ignore_post_ids = apply_filters('nebula_autocomplete_ignore_ids', array()); //Allow individual posts to be globally ignored from autocomplete search
						if ( in_array($post->ID, $ignore_post_ids) || !get_the_title($post->ID) ){ //Ignore results without titles
							continue;
						}

						$suggestion = array();
						similar_text(strtolower($term), strtolower(esc_html(get_the_title($post->ID))), $suggestion['similarity']); //Determine how similar the query is to this post title
						$suggestion['label'] = esc_html(get_the_title($post->ID));
						$suggestion['link'] = get_permalink($post->ID);

						$suggestion['classes'] = 'type-' . get_post_type($post->ID) . ' id-' . $post->ID . ' slug-' . $post->post_name . ' similarity-' . str_replace('.', '_', number_format($suggestion['similarity'], 2));
						if ( $post->ID == get_option('page_on_front') ){
							$suggestion['classes'] .= ' page-home';
						} elseif ( is_sticky($post->ID) ){ //@TODO "Nebula" 0: If sticky post. is_sticky() does not work here?
							$suggestion['classes'] .= ' sticky-post';
						}
						$suggestion['classes'] .= $this->close_or_exact($suggestion['similarity']);
						$suggestions[] = $suggestion;
					}
				} else { //Manually find relevant posts
					$query1 = new WP_Query($initial_query_args); //Run the first query with the initial arguments now

					//Search custom fields now too
					$query2 = new WP_Query(array(
						'post_type' => $types,
						'post_status' => 'publish',
						'posts_per_page' => 10, //Do not use numberposts or any other parameter instead here
						'meta_query' => array(
							array(
								'value' => $term,
								'compare' => 'LIKE'
							)
						)
					));

					//Combine the above queries
					$autocomplete_query = new WP_Query();
					$autocomplete_query->posts = array_unique(array_merge($query1->posts, $query2->posts), SORT_REGULAR); //Is this the right way to do it? Or should we use parse_query here?
					$autocomplete_query->post_count = count($autocomplete_query->posts);

					$ignore_post_types = apply_filters('nebula_autocomplete_ignore_types', array('nebula_cf7_submits')); //Allow post types to be globally ignored from autocomplete search
					$ignore_post_ids = apply_filters('nebula_autocomplete_ignore_ids', array()); //Allow individual posts to be globally ignored from autocomplete search

					$suggestions = array();

					$should_be_sorted = true; //Re-sort results by our similarity score

					//Loop through the posts
					if ( $autocomplete_query->have_posts() ){
						while ( $autocomplete_query->have_posts() ){
							$autocomplete_query->the_post();
							if ( in_array(get_the_id(), $ignore_post_ids) || !get_the_title() ){ //Ignore results without titles
								continue;
							}

							$post = get_post();

							$suggestion = array();
							similar_text(strtolower($term), strtolower(esc_html(get_the_title())), $suggestion['similarity']); //Determine how similar the query is to this post title
							$suggestion['label'] = esc_html(get_the_title());
							$suggestion['link'] = get_permalink();

							$suggestion['classes'] = 'type-' . get_post_type() . ' id-' . get_the_id() . ' slug-' . $post->post_name . ' similarity-' . str_replace('.', '_', number_format($suggestion['similarity'], 2));
							if ( get_the_id() == get_option('page_on_front') ){
								$suggestion['classes'] .= ' page-home';
							} elseif ( is_sticky() ){ //@TODO "Nebula" 0: If sticky post. is_sticky() does not work here?
								$suggestion['classes'] .= ' sticky-post';
							}
							$suggestion['classes'] .= $this->close_or_exact($suggestion['similarity']);
							$suggestions[] = $suggestion;
						}
					}

					//Find media library items
					if ( !$this->in_array_any(array('attachment'), $ignore_post_types) && $this->in_array_any(array('any', 'attachment'), $types) ){
						$attachments = get_posts(array('post_type' => 'attachment', 's' => $term, 'numberposts' => 10, 'post_status' => null));
						if ( $attachments ){
							$attachment_count = 0;
							foreach ( $attachments as $attachment ){
								if ( in_array($attachment->ID, $ignore_post_ids) || strpos(get_attachment_link($attachment->ID), '?attachment_id=') ){ //Skip if media item is not associated with a post. //@todo "Nebula" 0: Update strpos() to str_contains() in PHP8
									continue;
								}
								$suggestion = array();
								$attachment_meta = wp_get_attachment_metadata($attachment->ID);

								if ( isset($attachment_meta['file']) ){
									$path_parts = pathinfo($attachment_meta['file']);
									$attachment_search_meta = ( get_the_title($attachment->ID) != '' )? get_the_title($attachment->ID) : $path_parts['filename'];
									similar_text(strtolower($term), strtolower($attachment_search_meta), $suggestion['similarity']);
									if ( $suggestion['similarity'] >= 50 ){
										$suggestion['label'] = ( get_the_title($attachment->ID) != '' )? get_the_title($attachment->ID) : $path_parts['basename'];
										$suggestion['classes'] = 'type-attachment file-' . $path_parts['extension'];
										$suggestion['classes'] .= $this->close_or_exact($suggestion['similarity']);
										if ( in_array(strtolower($path_parts['extension']), array('jpg', 'jpeg', 'png', 'gif', 'bmp')) ){
											$suggestion['link'] = get_attachment_link($attachment->ID);
										} else {
											$suggestion['link'] = wp_get_attachment_url($attachment->ID);
											$suggestion['external'] = true;
											$suggestion['classes'] .= ' external-link';
										}
										$suggestion['similarity'] = $suggestion['similarity']-0.001; //Force lower priority than posts/pages.
										$suggestions[] = $suggestion;
										$attachment_count++;
									}
									if ( $attachment_count >= 2 ){
										break;
									}
								}
							}
						}
					}

					//Find menu items
					if ( !$this->in_array_any(array('menu'), $ignore_post_types) && $this->in_array_any(array('any', 'menu'), $types) ){
						$menus = nebula()->transient('nebula_autocomplete_menus', function(){
							return get_terms('nav_menu');
						}, WEEK_IN_SECONDS); //This transient is deleted when a post is updated or Nebula Options are saved.

						foreach ( $menus as $menu ){
							$menu_items = wp_get_nav_menu_items($menu->term_id);
							foreach ( $menu_items as $key => $menu_item ){
								$suggestion = array();
								similar_text(strtolower($term), strtolower($menu_item->title), $menu_title_similarity);
								similar_text(strtolower($term), strtolower($menu_item->attr_title), $menu_attr_similarity);
								if ( $menu_title_similarity >= 65 || $menu_attr_similarity >= 65 ){
									if ( $menu_title_similarity >= $menu_attr_similarity ){
										$suggestion['similarity'] = $menu_title_similarity;
										$suggestion['label'] = $menu_item->title;
									} else {
										$suggestion['similarity'] = $menu_attr_similarity;
										$suggestion['label'] = $menu_item->attr_title;
									}

									if ( !empty($menu_item->url) ){
										$suggestion['link'] = $menu_item->url;
										$path_parts = pathinfo($menu_item->url);
										$suggestion['classes'] = 'type-menu-item';
										if ( $path_parts['extension'] ){
											$suggestion['classes'] .= ' file-' . $path_parts['extension'];
											$suggestion['external'] = true;
										} elseif ( !strpos($suggestion['link'], $this->url_components('domain')) ){ //@todo "Nebula" 0: Update strpos() to str_contains() in PHP8
											$suggestion['classes'] .= ' external-link';
											$suggestion['external'] = true;
										}
										$suggestion['classes'] .= $this->close_or_exact($suggestion['similarity']);
										$suggestion['similarity'] = $suggestion['similarity']-0.001; //Force lower priority than posts/pages.
										$suggestions[] = $suggestion;
										break;
									}
								}
							}
						}
					}

					//Find categories
					if ( !$this->in_array_any(array('category', 'cat'), $ignore_post_types) && $this->in_array_any(array('any', 'category', 'cat'), $types) ){
						$categories = nebula()->transient('nebula_autocomplete_categories', function(){
							return get_categories();
						}, WEEK_IN_SECONDS); //This transient is deleted when a post is updated or Nebula Options are saved.

						foreach ( $categories as $category ){
							$suggestion = array();
							$cat_count = 0;
							similar_text(strtolower($term), strtolower($category->name), $suggestion['similarity']);
							if ( $suggestion['similarity'] >= 65 ){
								$suggestion['label'] = $category->name;
								$suggestion['link'] = get_category_link($category->term_id);
								$suggestion['classes'] = 'type-category';
								$suggestion['classes'] .= $this->close_or_exact($suggestion['similarity']);
								$suggestions[] = $suggestion;
								$cat_count++;
							}
							if ( $cat_count >= 2 ){
								break;
							}
						}
					}

					//Find tags
					if ( !$this->in_array_any(array('tag'), $ignore_post_types) && $this->in_array_any(array('any', 'tag'), $types) ){
						$tags = nebula()->transient('nebula_autocomplete_tags', function(){
							return get_tags();
						}, WEEK_IN_SECONDS); //This transient is deleted when a post is updated or Nebula Options are saved.

						foreach ( $tags as $tag ){
							$suggestion = array();
							$tag_count = 0;
							similar_text(strtolower($term), strtolower($tag->name), $suggestion['similarity']);
							if ( $suggestion['similarity'] >= 65 ){
								$suggestion['label'] = $tag->name;
								$suggestion['link'] = get_tag_link($tag->term_id);
								$suggestion['classes'] = 'type-tag';
								$suggestion['classes'] .= $this->close_or_exact($suggestion['similarity']);
								$suggestions[] = $suggestion;
								$tag_count++;
							}
							if ( $tag_count >= 2 ){
								break;
							}
						}
					}

					//Find authors (if author bios are enabled)
					if ( $this->get_option('author_bios') && !$this->in_array_any(array('author'), $ignore_post_types) && $this->in_array_any(array('any', 'author'), $types) ){
						$authors = nebula()->transient('nebula_autocomplete_authors', function(){
							return get_users(array('role' => 'author', 'has_published_posts' => true, 'role__not_in' => array('subscriber')));
						}, WEEK_IN_SECONDS); //This transient is deleted when a post is updated or Nebula Options are saved.

						foreach ( $authors as $author ){
							$author_name = ( $author->first_name != '' )? $author->first_name . ' ' . $author->last_name : $author->display_name; //might need adjusting here
							if ( strtolower($author_name) === strtolower($term) ){ //todo: if similarity of author name and query term is higher than X. Return only 1 or 2.
								$suggestion = array();
								$suggestion['label'] = $author_name;
								$suggestion['link'] = get_author_posts_url($author->ID);
								$suggestion['classes'] = 'type-user';
								$suggestion['classes'] .= $this->close_or_exact($suggestion['similarity']);
								$suggestion['similarity'] = ''; //todo: save similarity to array too
								$suggestions[] = $suggestion;
								break;
							}
						}
					}
				}

				//Now do stuff to the resulting suggestions array
				if ( !empty($suggestions) ){
					if ( $should_be_sorted ){
						//Order by match similarity to page title (DESC).
						function autocomplete_similarity_compare($a, $b){
							return $b['similarity'] - $a['similarity'];
						}
						usort($suggestions, "autocomplete_similarity_compare");
					}

					//Remove any duplicate links (higher similarity = higher priority)
					$outputArray = array(); //This array is where unique results will be stored
					$keysArray = array(); //This array stores values to check duplicates against.
					foreach ( $suggestions as $suggestion ){
						if ( !in_array($suggestion['link'], $keysArray) ){
							$keysArray[] = $suggestion['link'];
							$outputArray[] = $suggestion;
						}
					}

					$outputArray = array_slice($outputArray, 0, 9); //Limit to a maximum amount of results (they are already ordered by similarity)
				}

				//Add a link to search at the end of the list
				//@TODO "Nebula" 0: The empty result is not working for some reason... (Press Enter... is never appearing)
				$suggestion = array();
				$suggestion['label'] = ( !empty($suggestions) )? __('...more results for', 'nebula') . ' "' . $term . '"' : __('Press enter to search for', 'nebula') . ' "' . $term . '"';
				$suggestion['link'] = home_url('/') . '?s=' . str_replace(' ', '%20', $term);
				$suggestion['classes'] = ( !empty($suggestions) )? 'more-results search-link' : 'no-results search-link';
				$outputArray[] = $suggestion;

				$this->timer($timer_name, 'end');
				return $outputArray;
			}
		}

		//Test for close or exact matches. Use: $suggestion['classes'] .= $this->close_or_exact($suggestion['similarity']); //Rename this function
		public function close_or_exact($rating=0, $close_threshold=80, $exact_threshold=95){
			if ( $rating > $exact_threshold ){
				return ' exact-match';
			} elseif ( $rating > $close_threshold ){
				return ' close-match';
			}

			return '';
		}

		//404 page suggestions
		public function internal_suggestions(){
			if ( is_404() ){
				$this->timer('Internal Suggestions');
				$this->ga_send_exception('(PHP) 404 Error for requested URL: ' . $this->url_components()); //Track 404 error pages as exceptions in Google Analytics

				$this->slug_keywords = array_filter(explode('/', $this->url_components('filepath'))); //Convert the requested filepath into an array (ignore query strings and remove empty items)
				$this->slug_keywords = end($this->slug_keywords); //Get the last "directory" from the path (this was the requested "term" we will search for)

				if ( !empty($this->slug_keywords) ){
					//Query the DB with clues from the requested URL
					$this->error_query = new WP_Query(array('post_status' => 'publish', 'posts_per_page' => 4, 's' => str_replace('-', ' ', $this->slug_keywords))); //Query the DB for this term

					if ( function_exists('relevanssi_do_query') ){
						relevanssi_do_query($this->error_query);
					}

					//Check for an exact match
					if ( !empty($this->error_query->posts) && $this->slug_keywords === $this->error_query->posts[0]->post_name ){
						$this->error_404_exact_match = $this->error_query->posts[0];
					}
				}

				$this->timer('Internal Suggestions', 'end');
			}
		}

		//Add custom body classes
		public function body_classes($classes){
			if ( !$this->is_admin_page() ){
				$this->timer('Nebula Body Classes');

				$spaces_and_dots = array(' ', '.');
				$underscores_and_hyphens = array('_', '-');

				//Check the Save Data header
				$classes[] = ( $this->is_save_data() )? 'save-data' : '';

				//Device
				$classes[] = strtolower($this->get_device('formfactor')); //Form factor (desktop, tablet, mobile)
				$classes[] = strtolower($this->get_device('full')); //Device make and model
				$classes[] = strtolower(str_replace($spaces_and_dots, $underscores_and_hyphens, $this->get_os('full'))); //Operating System name with version
				$classes[] = strtolower(str_replace($spaces_and_dots, $underscores_and_hyphens, $this->get_os('name'))); //Operating System name
				$classes[] = strtolower(str_replace($spaces_and_dots, $underscores_and_hyphens, $this->get_browser('full'))); //Browser name and version
				$classes[] = strtolower(str_replace($spaces_and_dots, $underscores_and_hyphens, $this->get_browser('name'))); //Browser name
				$classes[] = strtolower(str_replace($spaces_and_dots, $underscores_and_hyphens, $this->get_browser('engine'))); //Rendering engine

				//Website language
				$classes[] = 'lang-blog-' . strtolower(get_bloginfo('language'));
				if ( is_rtl() ){
					$classes[] = 'lang-dir-rtl';
				}

				//Preferred browser language
				if ( !empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) ){
					$classes[] = 'lang-user-' . strtolower(explode(",", $_SERVER['HTTP_ACCEPT_LANGUAGE'])[0]); //Example: fr-fr,en-us;q=0.7,en;q=0.3
				}

				//When installed to the homescreen, Chrome is detected as "Chrome Mobile". Supplement it with a "chrome" class.
				if ( $this->get_browser('name') === 'Chrome Mobile' ){
					$classes[] = 'chrome';
				}

				//User Information
				$current_user = wp_get_current_user();
				if ( is_user_logged_in() ){
					$classes[] = 'user-logged-in';
					$classes[] = 'user-' . $current_user->user_login;

					$user_info = get_userdata(get_current_user_id());
					if ( !empty($user_info->roles) ){
						$classes[] = 'user-role-' . $user_info->roles[0];
					} else {
						$classes[] = 'user-role-unknown';
					}
				} else {
					$classes[] = 'user-not-logged-in';
				}

				//Staff
				if ( $this->is_staff() ){
					$classes[] = 'is-staff';
					if ( $this->is_dev() ){
						$classes[] = 'staff-developer';
					} elseif ( $this->is_client() ){
						$classes[] = 'staff-client';
					}
				}

				//Post Information
				if ( !is_search() && !is_archive() && !is_front_page() && !is_404() ){
					global $post;
					if ( isset($post) ){
						$segments = explode('/', trim(parse_url($this->super->server['REQUEST_URI'], PHP_URL_PATH), '/'));
						$parents = get_post_ancestors($post->ID);
						foreach ( $parents as $parent ){
							if ( !empty($parent) ){
								$classes[] = 'ancestor-id-' . $parent;
							}
						}

						foreach ( $segments as $segment ){
							if ( !empty($segment) ){
								$classes[] = 'ancestor-of-' . $segment;
							}
						}

						foreach ( get_the_category($post->ID) as $category ){
							$classes[] = 'cat-id-' . $category->cat_ID;
							$classes[] = 'cat-' . $category->slug;
						}
					}
				}

				//Singular
				if ( is_singular() ){
					$classes[] = 'singular';
				} else {
					$classes[] = 'hfeed'; //Adds `hfeed` to non singular pages.
				}

				//Give each page a unique class
				if ( is_page() ){
					$classes[] = 'page-' . basename(get_permalink());
				}

				//If this post has a featured image
				if ( has_post_thumbnail() ){
					$classes[] = 'has-featured-image';
				}

				//Customizer
				if ( is_customize_preview() ){
					$classes[] = 'customizer-preview';
				}

				//Front Page
				if ( is_front_page() ){
					$classes[] = 'front-page';

					//Homepage Hero (Customizer)
					if ( !get_theme_mod('nebula_hero', true) ){
						$classes[] = 'no-hero';
					}
				}

				$nebula_theme_info = wp_get_theme();
				$classes[] = 'nebula';
				$classes[] = 'nebula_' . str_replace('.', '-', $this->version('primary'));

				//Time of Day
				if ( $this->has_business_hours() ){
					$classes[] = ( $this->business_open() )? 'business-open' : 'business-closed';
				}

				$relative_time = $this->relative_time('description');
				foreach( $relative_time as $relative_desc ){
					$classes[] = 'time-' . $relative_desc;
				}
				if ( date('H') >= 12 ){
					$classes[] = 'time-pm';
				} else {
					$classes[] = 'time-am';
				}

				if ( $this->get_option('latitude') && $this->get_option('longitude') ){
					$latitude = floatval($this->get_option('latitude'));
					$longitude = floatval($this->get_option('longitude'));
					global $sunrise, $sunset;
					$suninfo = date_sun_info(strtotime('today'), $latitude, $longitude); //Civil twilight = 96°, Nautical twilight = 102°, Astronomical twilight = 108° - these are already accounted for in this PHP function
					$sunrise = strtotime($suninfo['sunrise']); //The timestamp of the sunrise (zenith angle = 90°35')
					$sunset  = strtotime($suninfo['sunset']); //The timestamp of the sunset (zenith angle = 90°35')
					$length_of_daylight = $sunset-$sunrise;
					$length_of_darkness = DAY_IN_SECONDS-$length_of_daylight;

					if ( time() >= $sunrise && time() <= $sunset ){
						$classes[] = 'time-daylight';
						if ( strtotime('now') < $sunrise+($length_of_daylight/2) ){
							$classes[] = 'time-waxing-gibbous'; //Before solar noon
							$classes[] = ( strtotime('now') < ($length_of_daylight/4)+$sunrise )? 'time-narrow' : 'time-wide';
						} else {
							$classes[] = 'time-waning-gibbous'; //After solar noon
							$classes[] = ( strtotime('now') < ((3*$sunset)+$sunrise)/4 )? 'time-wide' : 'time-narrow';
						}
					} else {
						$classes[] = 'time-darkness';
						$previous_sunset_modifier = ( date('H') < 12 )? DAY_IN_SECONDS : 0; //Times are in UTC, so if it is after actual midnight (before noon) we need to use the sunset minus 1 day in formulas
						$solar_midnight = (($sunset-$previous_sunset_modifier)+($length_of_darkness/2)); //Calculate the appropriate solar midnight (either yesterday's or tomorrow's) [see above]
						if ( strtotime('now') < $solar_midnight ){
							$classes[] = 'time-waning-crescent'; //Before solar midnight
							$classes[] = ( strtotime('now') < ($length_of_darkness/4)+($sunset-$previous_sunset_modifier) )? 'time-wide' : 'time-narrow';
						} else {
							$classes[] = 'time-waxing-crescent'; //After solar midnight
							$classes[] = ( strtotime('now') < ($sunrise+$solar_midnight)/2 )? 'time-narrow' : 'time-wide';
						}
					}

					$sunrise_sunset_length = 35; //Length of sunrise/sunset in minutes.
					if ( strtotime('now') >= $sunrise-(60*$sunrise_sunset_length) && strtotime('now') <= $sunrise+(60*$sunrise_sunset_length) ){ //X minutes before and after true sunrise
						$classes[] = 'time-sunrise';
					}
					if ( strtotime('now') >= $sunset-(60*$sunrise_sunset_length) && strtotime('now') <= $sunset+(60*$sunrise_sunset_length) ){ //X minutes before and after true sunset
						$classes[] = 'time-sunset';
					}
				}

				$classes[] = 'date-day-' . strtolower(date('l'));
				$classes[] = 'date-ymd-' . strtolower(date('Y-m-d'));
				$classes[] = 'date-month-' . strtolower(date('F'));

				$this->timer('Nebula Body Classes', 'end');
			}

			return $classes;
		}

		//Add additional classes to post wrappers
		public function post_classes($classes){
			if ( !$this->is_admin_page() ){
				$this->timer('Nebula Post Classes');

				global $post;
				global $wp_query;

				if ( $wp_query->current_post === 0 ){ //If first post in a query
					$classes[] = 'first-post';
				}
				if ( is_sticky() ){
					$classes[] = 'sticky';
				}
				$classes[] = 'nebula-entry';

				if ( !is_page() ){
					$classes[] = 'date-day-' . strtolower(get_the_date('l'));
					$classes[] = 'date-ymd-' . strtolower(get_the_date('Y-m-d'));
					$classes[] = 'date-month-' . strtolower(get_the_date('F'));
				}

				if ( !empty($post) ){
					foreach ( get_the_category($post->ID) as $category ){
						$classes[] = 'cat-id-' . $category->cat_ID;
					}
				}

				$classes[] = 'author-id-' . $post->post_author;
				if ( is_multi_author() ){
					$classes[] = 'multi-author';
				}

				//If this post has a featured image
				if ( has_post_thumbnail() ){
					$classes[] = 'has-featured-image';
				}

				//Remove "hentry" meta class on pages or if Author Bios are disabled
				if ( is_page() || !$this->get_option('author_bios') ){
					$classes = array_diff($classes, array('hentry'));
				}

				$this->timer('Nebula Post Classes', 'end');
			}

			return $classes;
		}

		//Make sure attachment URLs match the protocol (to prevent mixed content warnings).
		public function wp_get_attachment_url_force_protocol($url){
			$http = site_url(false, 'http');
			$https = site_url(false, 'https');

			if ( isset($this->super->server['HTTPS']) && $this->super->server['HTTPS'] === 'on' ){
				return str_replace($http, $https, $url);
			} else {
				return $url;
			}
		}

		//Modify oembeds when necessary
		public function oembed_modifiers($html, $url, $attr, $post_id){
			//Enable the JS API for Youtube videos
			if ( strstr($html, 'youtube.com/embed/') ){
				$html = str_replace('feature=oembed', 'feature=oembed&enablejsapi=1&rel=0', $html);
			}

			return $html;
		}

		//Add autocomplete attributes to CF7 form fields
		//Note: Is this still needed? When commented out, the fields are still getting autocomplete attributes...
		public function cf7_autocomplete_attribute($content){
			$this->timer('CF7 Autocomplete Attributes');
			$content = $this->autocomplete_find_replace($content, array('name', 'full-name', 'fullname', 'your-name'), 'name');
			$content = $this->autocomplete_find_replace($content, array('first-name', 'firstname'), 'given-name');
			$content = $this->autocomplete_find_replace($content, array('last-name', 'lastname'), 'family-name');
			$content = $this->autocomplete_find_replace($content, array('email', 'your-email'), 'email');
			$content = $this->autocomplete_find_replace($content, 'phone', 'tel');
			$content = $this->autocomplete_find_replace($content, array('company', 'company-name', 'companyname'), 'organization');
			$content = $this->autocomplete_find_replace($content, array('address', 'street'), 'address-line1');
			$content = $this->autocomplete_find_replace($content, 'city', 'address-level2');
			$content = $this->autocomplete_find_replace($content, 'state', 'address-level1');
			$content = $this->autocomplete_find_replace($content, array('zip', 'zipcode', 'postalcode'), 'postal-code');

			$this->timer('CF7 Autocomplete Attributes', 'end');
			return $content;
		}

		//Find field names and add the autocomplete attribute when found
		public function autocomplete_find_replace($content, $finds=array(), $autocomplete_value=''){
			if ( !empty($content) && !empty($finds) && !empty($autocomplete_value) ){
				if ( is_string($finds) ){
					$finds = array($finds); //Convert the string to an array
				}

				foreach ( $finds as $find ){
					if ( strpos(strtolower($content), 'autocomplete') >= 1 ){ //@todo "Nebula" 0: Update strpos() to str_contains() in PHP8
						continue; //Skip it if it already has an autocomplete attribute
					}

					$field_name_pos = strpos(strtolower($content), ' name="' . strtolower($find) . '"'); //The space before name= prevents data-name= attributes from matching. @todo "Nebula" 0: Update strpos() to str_contains() in PHP8

					if ( !empty($field_name_pos) ){
						$content = substr_replace($content, ' autocomplete="' . $autocomplete_value . '" ', $field_name_pos, 0);
					}
				}
			}

			return $content;
		}

		//Add custom special mail tags to Contact Form 7
		public function cf7_custom_special_mail_tags($output, $name, $html){
			$submission = WPCF7_Submission::get_instance();
			if ( !$submission ){
				return $output;
			}

			//Contact Email
			if ( $name === '_nebula_contact_email' ){
				return ( $this->get_option('contact_email') )? $this->get_option('contact_email') : get_option('admin_email', $this->get_user_info('user_email', array('id' => 1)));
			}

			//Notification Email
			if ( $name === '_nebula_notification_email' ){
				return ( $this->get_option('notification_email') )? $this->get_option('notification_email') : get_option('admin_email', $this->get_user_info('user_email', array('id' => 1)));
			}

			//Safe From Address
			if ( $name === '_nebula_safe_from' ){
				$site_owner = ( $this->get_option('site_owner') )? $this->get_option('site_owner') : get_bloginfo('name');
				return $site_owner . '<wordpress@' . $this->url_components('domain') . '>';
			}

			//Debug Info
			if ( $name === 'debuginfo' || $name === '_debuginfo' || $name === '_nebula_debuginfo' || $name === '_nebula_debug' ){
				$nebula_debug_info = $this->cf7_debug_info($submission);
				$debug_output = '';
				foreach ( $nebula_debug_info as $key => $value ){
					$debug_output .= ucwords(str_replace('_', ' ', $key)) . ': ' . $value . PHP_EOL;
				}
				return $debug_output;
			}

			return $output;
		}

		//Note when CF7 emails have failed recently
		public function cf7_note_mail_failed(){
			set_transient('smtp_status', 'Recent CF7 Fail Error', DAY_IN_SECONDS*5); //Remember this must contain the word "error" for Dashboard status check
		}

		//Create a custom post type for Contact Form 7 submission storage
		public function cf7_storage_taxonomies(){
			register_post_type('nebula_cf7_submits', array( //This is the text that appears in the URL
				'labels' => array( //https://developer.wordpress.org/reference/functions/get_post_type_labels/
					'name' => 'CF7 Submissions', //Plural
					'singular_name' => 'CF7 Submission',
					'edit_item' => 'CF7 Submission Details',
					'menu_name' => '<i class="fa-solid fa-fw fa-table-list"></i> CF7 Submissions',
				),
				'description' => 'Contact Form 7 form submissions',
				'menu_icon' => 'dashicons-feedback',
				'supports' => array('title'),
				'capabilities' => array(
					'create_posts' => false, //Remove the Add New button
				),
				'map_meta_cap' => true, //User can view even though they cannot create posts in the admin
				'show_in_menu' => 'wpcf7', //Show as a submenu item of Contact Form 7
				'menu_position' => 31, //CF7 itself is at 29 or 30
				'show_in_nav_menus' => false,
				'has_archive' => false,
				'exclude_from_search' => true,
				'show_in_rest' => false,
				'public' => true, //Allow it to appear in the admin menu
				'publicly_queryable' => false, //Don't let visitors ever access this data
			));

			//@todo "Nebula" 0: If possible, I would set "submission" to be the default status viewed instead of "All" and then allow the spam/invalid posts to appear in "All".
			//@todo "Nebula" 0: After that, when viewing the "All" list, add a color-coded left-border to each row to indicate the submission type
			register_post_status('submission', array(
				'post_type' => array('nebula_cf7_submits'), //Only for Nebula CF7 Submissions CPT
				'label' => _x('Submission', 'post status label', 'nebula'),
				'public' => true, //This must be true for it to appear in the "All" list
				'internal' => true,
				'protected' => true,
				'private' => true,
				'exclude_from_search' => true,
				'show_in_admin_all_list' => true, //We want valid submissions to appear in the "All" list
				'show_in_admin_status_list' => true, //This is the linked list above the listing table
				'label_count' => _n_noop('Submissions <span class="count">(%s)</span>', 'Submission <span class="count">(%s)</span>', 'nebula'),
			));

			register_post_status('invalid', array(
				'post_type' => array('nebula_cf7_submits'), //Only for Nebula CF7 Submissions CPT
				'label' => _x('Invalid', 'post status label', 'nebula'),
				'public' => false,
				'internal' => true,
				'exclude_from_search' => true,
				'show_in_admin_all_list' => false, //This is the "All" list grouping above the listing table
				'show_in_admin_status_list' => true, //This is the linked list above the listing table
				'label_count' => _n_noop('Invalid <span class="count">(%s)</span>', 'Invalid <span class="count">(%s)</span>', 'nebula'),
			));

			register_post_status('spam', array(
				'post_type' => array('nebula_cf7_submits'), //Only for Nebula CF7 Submissions CPT
				'label' => _x('Spam', 'post status label', 'nebula'),
				'public' => false,
				'internal' => true,
				'exclude_from_search' => true,
				'show_in_admin_all_list' => false, //This is the "All" list grouping above the listing table
				'show_in_admin_status_list' => true, //This is the linked list above the listing table
				'label_count' => _n_noop('Spam <span class="count">(%s)</span>', 'Spam <span class="count">(%s)</span>', 'nebula'),
			));
		}

		//Modify/add data to CF7 submissions in a way that other themes/plugins can use it as well (remember: cf7_storage calls this function as well)
		public function cf7_enhance_data($posted_data=array()){
			$nebula_debug_info = $this->cf7_debug_info($posted_data);
			foreach ( $nebula_debug_info as $key => $value ){
				$posted_data['_' . $key] = $value;
			}

			return $posted_data;
		}

		//Listen for form submissions to store into the DB right before sending the mail
		//Note: Spam submissions often do not come through this function, so cannot be mitigated/noted here
		public function cf7_storage($form, $result){
			$submission = WPCF7_Submission::get_instance();
			$submission_data = filter_input_array(INPUT_POST, FILTER_SANITIZE_FULL_SPECIAL_CHARS); //Get the $_POST data array and sanitize it
			$submission_uploads = $submission->uploaded_files();
			$contact_form = WPCF7_ContactForm::get_current();
			$form_id = intval($contact_form->id()); //Use this to get information about the form

			//$result examples:
				//Validation Error: {"contact_form_id":1962,"status":"validation_failed","message":"One or more fields have an error. Please check and try again.","invalid_fields":{"email":{"reason":"The e-mail address entered is invalid.","idref":"your-email"}},"posted_data_hash":""}
				//No consent: {"contact_form_id":1962,"status":"validation_failed","message":"One or more fields have an error. Please check and try again.","invalid_fields":{"privacy-acceptance":{"reason":"You must accept the terms and conditions before sending your message.","idref":"cf7-privacy-acceptance"}},"posted_data_hash":""}
				//Success: {"contact_form_id":1962,"status":"mail_sent","message":"Thank you for your message. It has been sent.","posted_data_hash":"42854ce084a745127ab9774cecdf7b42"}
				//Spam: {"contact_form_id":1962,"status":"spam","message":"There was an error trying to send your message. Please try again later.","posted_data_hash":""}

			//Invalid: validation_failed
			//Success: mail_sent
			//Fail: mail_failed
			//Spam: spam
			$status = $result['status'];

			//If we are not capturing spam messages, ignore them here
			if ( $status == 'spam' && !$this->get_option('cf7_spam_submission_capture') ){
				return false;
			}

			//If the form is invalid and also does not have consent acceptance we do not process form field input data
			$is_processing_allowed = true;
			if ( $status == 'validation_failed' ){ //If the user had a validation error
				// if ( empty($submission->collect_consent()) ){ //Disabled because this is a false positive if the acceptance checkbox does not exist
				// 	$is_processing_allowed = false;
				// }

				if ( !empty($result['invalid_fields']['privacy-acceptance']) ){ //If the acceptance checkbox is in the invalid field list
					$is_processing_allowed = false;
				}
			}

			if ( !$is_processing_allowed ){
				//Preserve some metadata
				$wpcf7_metadata = array(
					'_wpcf7' => $submission_data['_wpcf7'],
					'_wpcf7_version' => $submission_data['_wpcf7_version'],
					'_wpcf7_locale' => $submission_data['_wpcf7_locale'],
					'_wpcf7_unit_tag' => $submission_data['_wpcf7_unit_tag'],
					'_wpcf7_container_post' => $submission_data['_wpcf7_container_post'],
					'_nebula_form_flow' => $submission_data['_nebula_form_flow'],
				);

				$submission_data = array(); //Empty the submission data to remove all form input field data
				$submission_data = array_merge($submission_data, $wpcf7_metadata); //Now restore the WPCF7 metadata since it was just removed
			}

			//Add more data to the submission
			$submission_data['form_id'] = $form_id;
			$submission_data['form_name'] = sanitize_text_field(get_the_title($form_id));

			//Nebula contextual data
			$nebula_debug_info = $this->cf7_debug_info($submission, $submission_data);
			foreach ( $nebula_debug_info as $key => $value ){
				$submission_data['_' . $key] = $value;
			}

			//Try to get the last recaptcha score from CF7 (0.1 = most bot, 0.9 = most human)
			$recaptcha = $submission->get_meta('recaptcha'); //This is not working (probably due to it being a protected array entry)
			if ( !empty($recaptcha) ){
				$submission_data['_recaptcha_spam_score'] = $recaptcha['response']['score'];
			} elseif ( class_exists('WPCF7_RECAPTCHA') ){
				$recaptcha_service = WPCF7_RECAPTCHA::get_instance();
				$submission_data['_last_recaptcha_spam_score'] = $recaptcha_service->get_last_score();
			}

			$submission_data['_wpcf7_recaptcha'] = $submission->pull('recaptcha');
			$submission_data['_wpcf7_spam_log'] = $submission->get_spam_log(); //Try to get the spam log for this submission

			$unique_identifier = '';
			if ( $is_processing_allowed ){
				if ( !empty($submission_data['email']) ){
					$unique_identifier = ' from ' . sanitize_text_field($submission_data['email']);
				} elseif ( !empty($submission_data['your-email']) ){
					$unique_identifier = ' from ' . sanitize_text_field($submission_data['your-email']);
				}
			}

			//Handle file uploads
			if ( $is_processing_allowed && !empty($submission_uploads) ){
				foreach ( $submission_uploads as $upload_field_name => $file_uploads ){
					foreach ( $file_uploads as $file_count => $file_location ){
						//Note that /wpcf7_uploads/ is only a temporary storage location. The file upload is deleted after the email is sent. https://contactform7.com/file-uploading-and-attachment/#How-your-uploaded-files-are-managed
						$file_location = apply_filters('nebula_cf7_file_location', $file_location); //Allow others to modify the outputted uploaded file storage location string. Note: This does NOT change where the file is stored!
						$submission_data[$upload_field_name . '_' . $file_count] = $file_location;
					}
				}
			}

			$submission_title = apply_filters('nebula_cf7_submission_title', get_the_title($form_id) . $unique_identifier, $submission_data); //Allow others to modify the title of the CF7 submissions as they are shown in WP Admin

			//Determine the WP post status and annotate the submission title if necessary
			$post_status = 'submission'; //"submission" status for success and mail failed
			$submission_data['message_shown'] = $result['message']; //This is the confirmation or error message the user was shown when submitting

			if ( $status == 'mail_failed' ){
				$submission_title = $submission_title . ' (Mail Failed)';
				$submission_data['mail_failed'] = 'CF7 Mail Failed may have been triggered with this submission. The user likely saw an error message that indicated it was not submitted, and administrators likely did not receive an email notifcation about this form submission!'; //Add the new item to the data array
			} elseif ( $status == 'validation_failed' ){
				$post_status = 'invalid';
				$submission_data['invalid_fields'] = addslashes(json_encode($result['invalid_fields'], JSON_PRETTY_PRINT));
				$submission_title = $submission_title . ' (Invalid)';
			} elseif ( $status == 'spam' ){
				$post_status = 'spam';
				$submission_title = $submission_title . ' (Spam)';
			}

			$submission_data = map_deep($submission_data, 'sanitize_text_field'); //Deep sanitization of the full data array
			$submission_data = apply_filters('nebula_cf7_submission_data', $submission_data); //Allow others to add/modify CF7 submission data before it is stored

			//Store it in a CPT
			//@todo "Nebula" 0: Consider rate limiting this so the same user cannot inadvertently submit many invalid forms in a short period of time
			$new_post_id = wp_insert_post(array(
				'post_title' => sanitize_text_field($submission_title),
				'post_content' => wp_json_encode($submission_data),
				'post_status' => $post_status, //submission, invalid, or spam
				'post_type' => 'nebula_cf7_submits', //This needs to match the CPT slug!
				'meta_input' => array(
					'form_id' => intval($form_id), //Associate this submission with its CF7 form ID
				)
			));
		}

		//Build debug info data for CF7 messages and/or Nebula CF7 storage
		public function cf7_debug_info($submission, $submission_data=false){
			global $wp_version;

			$debug_info = array();

			$debug_info['wp_core_version'] = $wp_version;
			$debug_info['nebula_timestamp'] = date('U');
			$debug_info['nebula_date_formatted'] = date('l, F j, Y \a\t g:ia');
			$debug_info['nebula_version'] = $this->version('full') . ' (Committed ' . $this->version('date') . ')';
			$debug_info['nebula_child_version'] = $this->child_version('full');
			$debug_info['nebula_session_id'] = sanitize_text_field($this->nebula_session_id());
			$debug_info['nebula_ga_cid'] = sanitize_text_field($this->ga_parse_cookie());

			if ( !empty($submission_data) ){
				$debug_info['nebula_current_page'] = sanitize_text_field(get_permalink($submission_data['_wpcf7_container_post']));
			}

			$session_cookie_data = json_decode(stripslashes($this->super->cookie['session']), true);
			if ( isset($session_cookie_data['landing_page']) ){
				$debug_info['nebula_referrer'] = sanitize_text_field($session_cookie_data['referrer']); //This is the original referrer (not just the previous page)
				$debug_info['nebula_landing_page'] = sanitize_text_field($session_cookie_data['landing_page']); //This is the first page view of the session
			}

			if ( $this->get_option('attribution_tracking') ){ //Don't output these unless this option is enabled (to prevent empty values from appearing like a lack of activity)
				$debug_info['nebula_utms'] = sanitize_text_field(htmlspecialchars_decode($this->utms())); //Check for PHP-based attribution cookie

				//Check for the JS-based attribution cookie
				if ( isset($this->super->cookie['attribution']) ){
					$debug_info['nebula_attribution'] = sanitize_text_field($this->super->cookie['attribution']);
				}
			}

			//Staff
			if ( $this->is_dev() ){
				$debug_info['nebula_staff'] = 'Developer';
			} elseif ( $this->is_client() ){
				$debug_info['nebula_staff'] = 'Client';
			} elseif ( $this->is_staff() ){
				$debug_info['nebula_staff'] = 'Staff';
			}

			//Logged-in User Info
			if ( is_object($submission) ){
				$user_id = (int) $submission->get_meta('current_user_id');
				if ( !empty($user_id) ){
					$user_info = get_userdata($user_id);

					$debug_info['nebula_user_id'] = $user_info->ID;
					$debug_info['nebula_username'] = $user_info->user_login;
					$debug_info['nebula_display_name'] = $user_info->display_name;
					$debug_info['nebula_email'] = $user_info->user_email;

					if ( get_the_author_meta('phonenumber', $user_info->ID) ){
						$debug_info['nebula_phone'] = get_the_author_meta('phonenumber', $user_info->ID);
					}

					if ( get_the_author_meta('jobtitle', $user_info->ID) ){
						$debug_info['nebula_job_title'] = get_the_author_meta('jobtitle', $user_info->ID);
					}

					if ( get_the_author_meta('jobcompany', $user_info->ID) ){
						$debug_info['nebula_company'] = get_the_author_meta('jobcompany', $user_info->ID);
					}

					if ( get_the_author_meta('jobcompanywebsite', $user_info->ID) ){
						$debug_info['nebula_company_website'] = get_the_author_meta('jobcompanywebsite', $user_info->ID);
					}

					if ( get_the_author_meta('usercity', $user_info->ID) && get_the_author_meta('userstate', $user_info->ID) ){
						$debug_info['nebula_city_state'] = get_the_author_meta('usercity', $user_info->ID) . ', ' . get_the_author_meta('userstate', $user_info->ID);
					}

					$debug_info['nebula_role'] = $this->user_role();
				}
			}

			//Bot detection
			if ( $this->is_bot() ){
				$debug_info['nebula_bot'] = 'Bot detected';
			}

			//WPML Language
			if ( defined('ICL_LANGUAGE_NAME') ){
				$debug_info['nebula_language'] = ICL_LANGUAGE_NAME . ' (' . ICL_LANGUAGE_CODE . ')';
			}

			//Device information
			if ( isset($this->super->server['HTTP_USER_AGENT']) ){
				$debug_info['nebula_user_agent'] = sanitize_text_field($this->super->server['HTTP_USER_AGENT']);
			}

			$debug_info['nebula_device_type'] = ucwords($this->get_device('formfactor'));
			$debug_info['nebula_device'] = $this->get_device('full');
			$debug_info['nebula_os'] = $this->get_os();
			$debug_info['nebula_browser'] = $this->get_browser('full');

			//Anonymized IP address
			$debug_info['nebula_anonymized_ip'] = sanitize_text_field($this->get_ip_address());

			$debug_info = map_deep($debug_info, 'sanitize_text_field'); //Deep sanitization of the full data array

			return apply_filters('nebula_cf7_debug_info', $debug_info);
		}

		//Add Google API key to Advanced Custom Fields Google Map field type
		public function acf_google_api_key(){
			return $this->get_option('google_browser_api_key');
		}

		//Generate a meta description (either from Yoast, or via Nebula excerpt)
		//Hooked as a filter called from Yoast (which passes $metadesc), and also called directly
		public function meta_description($metadesc=null, $chars=160){
			if ( empty($metadesc) ){
				//@todo "Nebula" 0: Use null coalescing operator here if possible
				$nebula_metadesc = $this->excerpt(array('length' => 'dynamic', 'characters' => $chars, 'more' => '', 'ellipsis' => false, 'strip_tags' => true));
				if ( empty($nebula_metadesc) ){
					$nebula_metadesc = get_bloginfo('description');
				}

				return esc_attr(stripslashes($nebula_metadesc));
			}

			return $metadesc;
		}

		//Execute arbitrary code from the options
		public function arbitrary_code_head(){
			echo $this->get_option('arbitrary_code_head');
		}
		public function arbitrary_code_body(){
			echo $this->get_option('arbitrary_code_body');
		}
		public function arbitrary_code_footer(){
			echo $this->get_option('arbitrary_code_footer');
		}

		//Get fresh resources when debugging and identify asset registration information
		public function add_debug_query_args($src, $handle){
			$src = add_query_arg('handle', $handle, $src); //Append the registration "handle" to more easily identify each
			$src = add_query_arg('debug', str_replace('.', '', $this->version('raw')) . '-' . random_int(100000, 999999), $src); //Add a debug parameter to to ensure no cache //PHP 7.4 use numeric separators here
			return $src;
		}

		//Tell the browser to clear caches when the debug query string is present
		public function clear_site_data(){
			if ( !$this->is_browser('safari') ){ //This header is not currently supported in Safari or iOS as of February 2021: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Clear-Site-Data#browser_compatibility
				//Note: Adding this header significantly increases server-response time!
				header('Clear-Site-Data: "cache", "storage", "executionContexts"'); //Do not clear cookies here because it forces logout which is annoying when Customizer is saved/closed
			}

			clearstatcache(); //This one is specifically for PHP functions like file_exists()
			header('Cache-Control: must-revalidate');
		}

		//Flush rewrite rules when using ?debug at shutdown
		public function flush_rewrite_on_debug(){
			if ( $this->is_debug() ){
				$this->timer('Flush Rewrite Rules');

				flush_rewrite_rules(); //Note: this is an expensive operation and significantly increases server-response time!
				$this->update_child_version_number();

				$this->timer('Flush Rewrite Rules', 'end');
			}
		}
	}
}