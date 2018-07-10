<?php

/*
	Plugin Name: ACF Input Counter
	Plugin URI: https://github.com/rowatt/acf-input-counter/
	Description: Show character count for limited text and textarea fields
	Version: 1.5.0
	Author: John A. Huebner II, Mark Rowatt Anderson
	Author URI: https://github.com/Hube2/
	Text-domain: acf-counter
	Domain-path: languages
	GitHub Plugin URI: https://github.com/rowatt/acf-input-counter/
	License: GPL
*/

// If this file is called directly, abort.
if (!defined('WPINC')) {die;}

new acf_input_counter();

class acf_input_counter {

	private $version = '1.5.0';

	/**
	 * Field types which have character limits
	 *
	 * @var array
	 */
	private $limited_field_types = [
		'text',
		'textarea',
		'wysiwyg'
	];

	public function __construct() {

		add_action('plugins_loaded', [ $this, '_acf_counter_load_plugin_textdomain' ] );
		add_action('acf/input/admin_enqueue_scripts', [ $this, '_scripts' ] );
		add_filter('jh_plugins_list', [ $this, '_meta_box_data' ] );

		foreach ($this->limited_field_types as $type) {
			//adds counter beneath fields
			add_action('acf/render_field/type=' . $type, [ $this, '_render_field' ], 20, 1);
			//validates field length
			add_filter('acf/validate_value/type=' . $type, [ $this, '_validate_maxlength' ], 10, 4 );
		}

		//wysiwyg field specific hook
		add_action('acf/render_field_settings/type=wysiwyg', [ $this, '_wysiwyg_field_settings' ], 10, 1);

	}

	public function _acf_counter_load_plugin_textdomain() {
		load_plugin_textdomain( 'acf-counter', FALSE, basename( dirname( __FILE__ ) ) . '/languages/' );
	}

	public function _meta_box_data( $plugins=[] ) {

		$plugins[] = array(
			'title' 	=> 'ACF Input Counter',
			'screens' 	=> array('acf-field-group', 'edit-acf-field-group'),
			'doc' 		=> 'https://github.com/rowatt/acf-input-counter'
		);

		return $plugins;
	}

	/**
	 * Can we run?
	 *
	 * We cannot run on field group editor as it will
	 * add code to every ACF field in the editor
	 *
	 * @return bool
	 */
	private function run() {
		$run = true;
		global $post;
		if ($post && $post->ID && get_post_type($post->ID) == 'acf-field-group') {
			$run = false;
		}

		return $run;
	}

	/**
	 * Enqueue scripts and stylesheets
	 */
	public function _scripts() {
		if (!$this->run()) {
			return;
		}

		$handle    	= 'acf-input-counter';
		$src       	= plugin_dir_url(__FILE__).'acf-input-counter.js';
		$deps      	= array('acf-input');
		$ver       	= $this->version;
		$in_footer 	= false;
		wp_enqueue_script($handle, $src, $deps, $ver, $in_footer);

		$data = [
			'init' => true
		];
		wp_localize_script( $handle, 'acf_input_counter_data', $data );

		/**
		 * Turn off plugin CSS if you want to incorporate into site stylesheet
		 *
		 * @param bool
		 */
		if( apply_filters( 'acf-input-counter/load-css', true ) ) {
			wp_enqueue_style('acf-counter', plugins_url( 'acf-counter.css' , __FILE__ ));
		}
	}

	/**
	 * Add max length option to wysiwyg field
	 *
	 * @param $field
	 */
	public function _wysiwyg_field_settings( $field ) {

		acf_render_field_setting( $field, array(
			'label'			=> __('Character Limit','acf'),
			'instructions'	=> __('Leave blank for no limit','acf'),
			'type'			=> 'number',
			'name'			=> 'maxlength',
		));

	}

	/**
	 * Add character counter when rendering limited field
	 *
	 * @param array $field
	 */
	public function _render_field( $field ) {

		//only run on field types we are limiting which have maxlength set
		if ( ! $this->run() ||
		     ! isset( $field[ 'maxlength' ] ) ||
		     ! in_array( $field['type'], $this->limited_field_types ) ) {
			return;
		}

		$len = $this->content_length( $field[ 'value' ] );
		$max = $field[ 'maxlength' ] ?? 0;

		$classes = apply_filters( 'acf-input-counter/classes', array() );
		$ids     = apply_filters( 'acf-input-counter/ids', array() );

		$insert = TRUE;
		if ( count( $classes ) || count( $ids ) ) {

			$exist = [];
			if ( $field[ 'wrapper' ][ 'class' ] ) {
				$exist = explode( ' ', $field[ 'wrapper' ][ 'class' ] );
			}
			$insert = $this->does_allowed_exist( $classes, $exist );

			if ( ! $insert && $field[ 'wrapper' ][ 'id' ] ) {
				$exist = array();
				if ( $field[ 'wrapper' ][ 'id' ] ) {
					$exist = explode( ' ', $field[ 'wrapper' ][ 'id' ] );
				}
				$insert = $this->does_allowed_exist( $ids, $exist );
			}
		}

		if ( ! $insert ) {
			return;
		}

		//output wysiwyg maxlength - used by counter js
		if( $max && 'wysiwyg' == $field['type'] ) {
			printf( '<script>acf_input_counter_data.%s="%d";</script>', $field['key'], $max );
		}

		$display = sprintf(
			__( 'chars: %1$s of %2$s', 'acf-counter' ),
			'%%len%%',
			'%%max%%'
		);
		/**
		 * Filter the text format of the character counter
		 *
		 * String should contain:
		 * 	%%len%% - replaced with number of characters
		 * 	%%max%% - replaced with max allowed number of characters
		 *
		 * @param string text to display
		 */
		$display = apply_filters( 'acf-input-counter/display', $display );

		$display = str_replace( '%%len%%', '<span class="count">' . $len . '</span>', $display );
		$display = str_replace( '%%max%%', $max, $display );
		if ( isset( $field[ 'maxlength' ] ) && $field[ 'maxlength' ] > 0 ) {
			printf( '<span class="char-count">%s</span>',$display );
		}

	}

	/**
	 * Do any elements present in an array match at least
	 * one element in the allowed list?
	 *
	 * @param array $allow elements that are allowed
	 * @param array $exist elements that are present
	 *
	 * @return bool
	 */
	private function does_allowed_exist($allow, $exist) {
		$intersect = array_intersect($allow, $exist);
		if (count($intersect)) {
			return true;
		}

		return false;
	}

	/**
	 * Make sure that fields can't be more than max length
	 *
	 * @param $valid
	 * @param $value
	 * @param $field
	 * @param $input
	 *
	 * @return string
	 */
	public function _validate_maxlength( $valid, $value, $field, $input ) {

		$maxlength = $field['maxlength'] ?? 0;

		if( $maxlength ) {
			$content_length = $this->content_length( $value );
			if ( $content_length > $maxlength ) {
				$msg = __( 'Field is %d characters but must be no more than %d', 'acf-counter' );

				return sprintf( $msg, $content_length, $maxlength );
			}
		}

		return $valid;
	}

	/**
	 * Get length of content after stripping out HTML and other things
	 *
	 * post_content can include HTML tags, so make sure we strip those out, remove double spaces etc
	 * and convert any HTML entities to their single unicode character.
	 * 
	 * @param $content
	 *
	 * @return int content length
	 */
	private function content_length( $content ) {

		$content  = strip_tags( $content );

		//remove linebreaks
		$content = str_replace( "\n", '', $content );
		$content = str_replace( "\r", '', $content );

		$content  = preg_replace( '#[\s]{2,}#', ' ', $content );
		$content  = html_entity_decode( $content, ENT_HTML5 );

		return mb_strlen( $content );
	}

}

if (!function_exists('jh_plugins_list_meta_box')) {

	function jh_plugins_list_meta_box() {
		if (apply_filters('remove_hube2_nag', false)) {
			return;
		}
		$plugins = apply_filters('jh_plugins_list', array());

		$id 		= 'plugins-by-john-huebner';
		$title 		= '<a style="text-decoration: none; font-size: 1em;" href="https://github.com/Hube2" target="_blank">Plugins by John Huebner</a>';
		$callback 	= 'show_blunt_plugins_list_meta_box';
		$screens 	= array();
		foreach ($plugins as $plugin) {
			$screens = array_merge($screens, $plugin['screens']);
		}
		$context 	= 'side';
		$priority 	= 'low';
		add_meta_box($id, $title, $callback, $screens, $context, $priority);
	}
	add_action('add_meta_boxes', 'jh_plugins_list_meta_box');

	function show_blunt_plugins_list_meta_box() {
		$plugins = apply_filters('jh_plugins_list', array());
		?>
			<p style="margin-bottom: 0;">Thank you for using my plugins</p>
			<ul style="margin-top: 0; margin-left: 1em;">
				<?php
					foreach ($plugins as $plugin) {
						?>
							<li style="list-style-type: disc; list-style-position:">
								<?php
									echo $plugin['title'];
									if ($plugin['doc']) {
										?> <a href="<?php echo $plugin['doc']; ?>" target="_blank">Documentation</a><?php
									}
								?>
							</li>
						<?php
					}
				?>
			</ul>
			<p><a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=hube02%40earthlink%2enet&lc=US&item_name=Donation%20for%20WP%20Plugins%20I%20Use&no_note=0&cn=Add%20special%20instructions%20to%20the%20seller%3a&no_shipping=1&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donateCC_LG%2egif%3aNonHosted" target="_blank">Please consider making a small donation.</a></p><?php
	}
}

/* EOF */