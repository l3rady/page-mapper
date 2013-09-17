<?php
/*
Plugin Name: Page Mapper
Plugin URI: https://github.com/l3rady/page-mapper
Description: WordPress plugin that allows you to map pages to custom post types and custom taxonomies for menu highlighting in wp_nav_menu() and wp_list_pages()
Author: Scott Cariss
Version: 1.0
Author URI: http://l3rady.com/
Text Domain: scpagemapper
Domain Path: /languages
*/

/*  Copyright 2013  Scott Cariss  (email : scott@l3rady.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 * Class SCPageMapper
 */
Class SCPageMapper {
	/**
	 * Holds the setting name for this plugin. Mappings stored in an array in wp_options
	 */
	const SETTINGS_NAME = "sc_page_mapper_settings";


	/**
	 * @var array Holds all custom post types registered before wp hook as an array of slugs
	 */
	private $post_types = array();


	/**
	 * @var array Holds all custom taxonomies registered before wp hook as an array of slugs
	 */
	private $taxonomies = array();


	/**
	 * @var array Holds a merged array of $post_types and $taxonomies
	 */
	private $objects = array();


	/**
	 * @var string If viewing a taxonomy or post type the object slug is stored here
	 */
	private $object_on = "";


	/**
	 * @var int Page ID that is mapped to the currently viewed taxonomy or post type
	 */
	private $page_id_linked = 0;


	/**
	 * @var array Page ancestors of $page_id_linked
	 */
	private $page_ancestors = array();


	/**
	 * @return SCPageMapper Instance of this class
	 */
	public static function instance() {
		static $inst = null;

		if ( $inst === null ) {
			$inst = new SCPageMapper();
		}

		return $inst;
	}


	/**
	 * Do not allow clone outside of this class
	 */
	private function __clone() {
	}


	/**
	 * Do not allow construct outside of this class
	 */
	private function __construct() {
		// Once WP has finished loading get all the registered post types and taxonomies that
		// have been registered. Assuming that all post types and taxonomies have been registered
		// in the init hook
		add_action( "wp_loaded", array( $this, "getCustomPostTypesAndTaxonomies" ) );

		// Apply settings stuff for this plugin to admin
		add_action( "admin_init", array( $this, "setupAdminReadingSettings" ) );

		// Before WP goes into loading templates and after it has got page data:
		// See if the content we are looking at matches any of the mappings set by the settings
		add_action( "wp", array( $this, "workOutMappingsAndHookMenus" ) );
	}


	/**
	 * Gets all the post types and taxonomies that are not built in
	 */
	public function getCustomPostTypesAndTaxonomies() {
		$this->post_types = array_values( (array) get_post_types( array( "_builtin" => false ) ) );
		$this->taxonomies = array_values( (array) get_taxonomies( array( "_builtin" => false ) ) );
		$this->objects    = array_merge( $this->post_types, $this->taxonomies );
	}


	/**
	 * Setup admin settings. Don't load if no custom post types or taxonomies are found
	 */
	public function setupAdminReadingSettings() {
		// If no post types or taxonomies then no need to show settings
		if ( empty( $this->objects ) ) {
			return;
		}

		// Register plugin settings to be added to the reading settings screen
		register_setting( "reading", self::SETTINGS_NAME, array( $this, "validatePageMapperSettings" ) );

		// Setup a new section on the reading settings screen
		add_settings_section( "sc_page_mapper_reading", __( "Page Mapper Settings", "scpagemapper" ), array( $this, "pageMapperSettingsDescription" ), "reading" );

		// Only show setting if we have custom post types
		if ( !empty( $this->post_types ) ) {
			add_settings_field( "sc_page_mapper_reading_cpts", __( "Custom Post Types", "scpagemapper" ), array( $this, "pageMapperSettingsField" ), "reading", "sc_page_mapper_reading", array( "object_type" => "post_types" ) );
		}

		// Only show setting if we have custom taxonomies
		if ( !empty( $this->taxonomies ) ) {
			add_settings_field( "sc_page_mapper_reading_taxonomies", __( "Custom Taxonomies", "scpagemapper" ), array( $this, "pageMapperSettingsField" ), "reading", "sc_page_mapper_reading", array( "object_type" => "taxonomies" ) );
		}
	}


	/**
	 * @param $input array Settings received from setting form. Needs sanitising
	 *
	 * @return array Input settings sanitised
	 */
	public function validatePageMapperSettings( $input ) {
		// Treat all inputs as integers and remove false values
		return array_filter( array_map( "intval", $input ) );
	}


	/**
	 * Description to settings section
	 */
	public function pageMapperSettingsDescription() {
		_e( "Here you set page mappings with all custom post types and taxonomies.", "scpagemapper" );
	}


	/**
	 * Shows setting field.
	 *
	 * @param $args array Passed arguments to setting field
	 */
	public function pageMapperSettingsField( $args ) {
		$settings = get_option( self::SETTINGS_NAME );

		// What type of object are we showing the settings field for. Post Types or Taxonomies
		$objects = ( isset( $args['object_type'] ) && $args['object_type'] === "taxonomies" ) ? $this->taxonomies : $this->post_types;

		// Loop through each object giving a list of pages select drop down for each object
		foreach ( $objects as $slug ) {
			echo "<p><label>Page for " . $slug . ": ";
			wp_dropdown_pages(
				array(
					'name'              => self::SETTINGS_NAME . "[$slug]",
					'echo'              => 1,
					'show_option_none'  => __( '&mdash; Select &mdash;' ),
					'option_none_value' => '0',
					'selected'          => isset( $settings[$slug] ) ? $settings[$slug] : ""
				)
			);
			echo "</label></p>";
		}
	}


	/**
	 * Work out if the page we are viewing is what we want to hook into and
	 * that we have a valid mapping for the CPT or taxonomy we are looking at
	 */
	public function workOutMappingsAndHookMenus() {
		// Only interested in taxonomy archives, single posts and post type archives
		if ( !( is_tax() || is_single() || is_archive() ) ) {
			return;
		}

		// Get what we are looking at.
		$object     = get_queried_object();
		$post_types = array_filter( (array) get_query_var( 'post_type' ) );

		// Work out what object (post type or taxonomy) we are on
		if ( is_tax() ) {
			$this->object_on = $object->taxonomy;
		}
		elseif ( is_single() ) {
			$this->object_on = $object->post_type;
		}
		elseif ( is_archive() && count( $post_types ) === 1 ) {
			$this->object_on = reset( $post_types );
		}

		// Get plugin settings
		$settings = get_option( self::SETTINGS_NAME );

		// Only continue if we are on an object we are interested in, have
		// some mappings in settings and a valid mapping for the object we are on
		if ( !$this->object_on || !$settings || !array_key_exists( $this->object_on, $settings ) ) {
			return;
		}

		// Get the mapped page ID and page ancestors
		$this->page_id_linked = $settings[$this->object_on];
		$this->page_ancestors = array_filter( array_map( "intval", (array) get_post_ancestors( $this->page_id_linked ) ) );

		// Hook into wp_list_pages and wp_nav_menu css filters.
		add_filter( "page_css_class", array( $this, "filterWpListPagesCss" ), 5, 10 );
		add_filter( "wp_get_nav_menu_items", array( $this, "filterWpNavMenuCss" ), 3, 10 );
	}


	/**
	 * Hooked into wp_list_pages() menu item classes.
	 *
	 * Here we apply additional classes to menu items where there is a mapping
	 * between the menu item page and the custom post type or taxonomy
	 *
	 * @param $css_class    array Classes of current menu item
	 * @param $page         WP_Post WordPress post object of current menu item
	 * @param $depth        int Depth the menu is set to
	 * @param $args         array Array of arguments sent to menu when requested
	 * @param $current_page WP_Post WordPress post object of current page viewing
	 *
	 * @return array Return modified class of menu item
	 */
	public function filterWpListPagesCss( $css_class, $page, $depth, $args, $current_page ) {
		if ( $page->ID == $this->page_id_linked ) {
			$css_class[] = "current_page_item";
		}

		if ( $page->ID == end( $this->page_ancestors ) ) {
			$css_class[] = "current_page_parent";
		}

		if ( in_array( $page->ID, $this->page_ancestors ) ) {
			$css_class[] = "current_page_ancestor";
		}

		return $css_class;
	}


	/**
	 * Hooked into wp_nav_menu() menu item classes.
	 *
	 * Here we apply additional classes to menu items where there is a mapping
	 * between the menu item page and the custom post type or taxonomy
	 *
	 * @param $items array Holds all the menu items
	 * @param $menu
	 * @param $args  array Array of arguments sent to menu when requested
	 *
	 * @return array
	 */
	public function filterWpNavMenuCss( $items, $menu, $args ) {
		foreach ( $items as $key => $item ) {
			if ( $item->object_id == $this->page_id_linked ) {
				$items[$key]->classes = array_merge( $items[$key]->classes, array( "current-menu-item", "current_page_item" ) );
			}

			if ( $item->object_id == end( $this->page_ancestors ) ) {
				$items[$key]->classes = array_merge( $items[$key]->classes, array( "current-menu-parent", "current_page_parent" ) );
			}

			if ( in_array( $item->object_id, $this->page_ancestors ) ) {
				$items[$key]->classes = array_merge( $items[$key]->classes, array( "current-menu-ancestor", "current_page_ancestor" ) );
			}
		}

		return $items;
	}
}

SCPageMapper::instance();