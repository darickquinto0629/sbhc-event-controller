<?php

/**
 * Loads and caches remote site credentials from the ACF `site_details` repeater.
 *
 * Replaces the previous per-site repeater scan (O(N^2) across N sites) with a
 * single pass per request, and centralizes site_key derivation so every code
 * path (legacy AJAX handler, job manager, future worker) agrees on identity.
 *
 * NOTE: site_key is still derived from the site name (matches the existing
 * front-end checkbox IDs for backward compatibility). Renaming a site changes
 * its key. See architecture review Section 2 (M1) for the long-term fix.
 *
 * @link       https://summitbhc.com/
 * @since      1.3.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/includes
 */

class EC_Site_Registry {

	/**
	 * @var array|null Cached sites keyed by site_key, or null if not yet loaded.
	 */
	private static $sites = null;

	/**
	 * Derive a site_key exactly the way the front-end checkbox IDs and the
	 * legacy handler's get_site_credentials_by_id() already do.
	 *
	 * @param string $site_name
	 * @return string
	 */
	public static function derive_site_key( $site_name ) {
		return strtolower( str_replace( ' ', '_', trim( $site_name ) ) );
	}

	/**
	 * Load all configured remote sites once per request and cache the result.
	 *
	 * @return array Keyed by site_key => [ 'name', 'url', 'username', 'password' ]
	 */
	public static function get_all() {
		if ( null !== self::$sites ) {
			return self::$sites;
		}

		self::$sites = array();

		if ( ! function_exists( 'have_rows' ) ) {
			return self::$sites;
		}

		if ( have_rows( 'site_details', 'option' ) ) {
			while ( have_rows( 'site_details', 'option' ) ) {
				the_row();

				$name = get_sub_field( 'site_name' );
				if ( ! $name ) {
					continue;
				}

				$site_key = self::derive_site_key( $name );

				self::$sites[ $site_key ] = array(
					'name'     => $name,
					'url'      => trim( get_sub_field( 'site_url' ) ),
					'username' => trim( get_sub_field( 'application_password_name' ) ),
					'password' => trim( get_sub_field( 'application_password' ) ),
				);
			}
		}

		return self::$sites;
	}

	/**
	 * Get a single site's credentials by site_key.
	 *
	 * @param string $site_key
	 * @return array|false
	 */
	public static function get( $site_key ) {
		$sites = self::get_all();

		return isset( $sites[ $site_key ] ) ? $sites[ $site_key ] : false;
	}

	/**
	 * Clear the cached sites. Mainly useful for long-running processes
	 * (WP-CLI, background workers) that must not serve stale credentials.
	 */
	public static function reset_cache() {
		self::$sites = null;
	}
}
