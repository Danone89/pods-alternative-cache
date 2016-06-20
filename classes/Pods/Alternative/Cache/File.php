<?php
/**
 * Class Pods_Alternative_Cache_File
 */
class Pods_Alternative_Cache_File extends Pods_Alternative_Cache_Storage {

	/**
	 * @var boolean Whether compatibility has been run
	 */
	public static $wpe_compatible = false;

	/**
	 * Setup storage type object
	 */
	public function __construct() {

		// Set cache directory path
		if ( ! defined( 'PODS_ALT_FILE_CACHE_DIR' ) ) {
			define( 'PODS_ALT_FILE_CACHE_DIR', WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'podscache' );
		}

		// Tell Pods 2.4.1+ that we can prime the Pods cache after flushing it
		if ( ! defined( 'PODS_PRELOAD_CONFIG_AFTER_FLUSH' ) ) {
			define( 'PODS_PRELOAD_CONFIG_AFTER_FLUSH', true );
		}

	}

	/**
	 * Activate plugin routine
	 *
	 * @param boolean $network_wide Whether the action is network-wide
	 */
	public function activate( $network_wide = false ) {

		$this->clear();

		require_once( ABSPATH . 'wp-admin/includes/file.php' );

		/**
		 * @var $wp_filesystem WP_Filesystem_Base
		 */
		global $wp_filesystem;

		WP_Filesystem();

		if ( ! $wp_filesystem ) {
			return false;
		} elseif ( ! $wp_filesystem->is_dir( PODS_ALT_FILE_CACHE_DIR ) ) {
			if ( ! $wp_filesystem->mkdir( PODS_ALT_FILE_CACHE_DIR, FS_CHMOD_DIR ) ) {
				return false;
			}
		}

		return true;

	}

	/**
	 * Deactivate plugin routine
	 *
	 * @param boolean $network_wide Whether the action is network-wide
	 */
	public function deactivate( $network_wide = false ) {

		$this->clear();

		require_once( ABSPATH . 'wp-admin/includes/file.php' );

		/**
		 * @var $wp_filesystem WP_Filesystem_Base
		 */
		global $wp_filesystem;

		WP_Filesystem();

		if ( ! $wp_filesystem ) {
			return false;
		} elseif ( $wp_filesystem->is_dir( PODS_ALT_FILE_CACHE_DIR ) ) {
			$wp_filesystem->rmdir( PODS_ALT_FILE_CACHE_DIR );
		}

		return true;

	}

	/**
	 * WPEngine support for anonymous file writes
	 */
	public function wpe_compatibility() {

		if ( ! self::$wpe_compatible && defined( 'WPE_APIKEY' ) && ! is_user_logged_in() ) {
			$wpe_cookie = 'wpe-auth';

			$cookie_value = md5( 'wpe_auth_salty_dog|' . WPE_APIKEY );

			if ( ! isset( $_COOKIE[ $wpe_cookie ] ) || $_COOKIE[ $wpe_cookie ] != $cookie_value ) {
				setcookie( $wpe_cookie, $cookie_value, 0, '/' );
			}

			self::$wpe_compatible = true;
		}

	}

	/**
	 * Get cached value from file cache
	 *
	 * @param string $cache_key
	 * @param string $group
	 *
	 * @return mixed|null
	 */
	public function get_value( $cache_key, $group = '' ) {

		require_once( ABSPATH . 'wp-admin/includes/file.php' );

		/**
		 * @var $wp_filesystem WP_Filesystem_Base
		 */
		global $wp_filesystem;

		WP_Filesystem();

		if ( ! $wp_filesystem ) {
			return false;
		}

		$current_blog_id = (string) get_current_blog_id();

		// Force 0000123 format (like W3TC)
		$current_blog_id = str_pad( $current_blog_id, 6, '0', STR_PAD_LEFT );

		$md5 = md5( $cache_key . '/' . $group );

		$md5_path = substr( $md5, 0, 1 ) . DIRECTORY_SEPARATOR . substr( $md5, 1, 3 ) . DIRECTORY_SEPARATOR . substr( $md5, 4, 3 );
		$md5_file = substr( $md5, 7 ) . '.php';

		$path = $current_blog_id . DIRECTORY_SEPARATOR . $md5_path;

		$path = $this->get_path_for_file( $path );

		if ( ! $path ) {
			return null;
		} else {
			$path .= DIRECTORY_SEPARATOR . $md5_file;
		}

		if ( ! $wp_filesystem->is_readable( $path ) ) {
			return null;
		}

		if ( defined( 'PODS_ALT_CACHE_DEBUG' ) && PODS_ALT_CACHE_DEBUG ) {
			echo '<!--' . __CLASS__ . ': File read (' . $path . ')-->' . "\n";
		}

		// @todo Figure out how to use WP_Filesystem to do fread() on limited byte range

		$contents = $wp_filesystem->get_contents( $path );

		$expires_at = substr( $contents, 0, 4 );

		$data_unserialized = null;

		if ( false !== $expires_at && ! empty( $expires_at ) ) {
			$expires_at = unpack( 'L', $expires_at );
			$expires_at = (int) $expires_at[1];

			// Force writes
			//$expires_at = time() - 10;

			//var_dump( array( 'read_key' => $cache_key, 'read_group' => $group, 'read_expires' => $expires_at, 'read_time' => time() ) );

			if ( 0 < (int) $expires_at && (int) $expires_at < time() ) {
				// Data has expired, delete it
				$this->set_value( $cache_key, '' );

				return $data_unserialized;
			} else {
				$data = substr( $contents, 20 );

				$data_unserialized = maybe_unserialize( $data );

				//var_dump( array( 'read_data' => $data_unserialized ) );
			}
		}

		return $data_unserialized;

	}

	/**
	 * Set cached value in file cache
	 *
	 * @param string|boolean $cache_key
	 * @param mixed          $cache_value
	 * @param int            $expires
	 * @param string         $group
	 *
	 * @return bool
	 */
	public function set_value( $cache_key, $cache_value, $expires = 0, $group = '' ) {

		require_once( ABSPATH . 'wp-admin/includes/file.php' );

		/**
		 * @var $wp_filesystem WP_Filesystem_Base
		 */
		global $wp_filesystem;

		WP_Filesystem();

		if ( ! $wp_filesystem ) {
			return false;
		}

		$current_blog_id = (string) get_current_blog_id();

		// Force 0000123 format (like W3TC)
		$current_blog_id = str_pad( $current_blog_id, 6, '0', STR_PAD_LEFT );

		$md5 = md5( $cache_key . '/' . $group );

		$md5_path = substr( $md5, 0, 1 ) . DIRECTORY_SEPARATOR . substr( $md5, 1, 3 ) . DIRECTORY_SEPARATOR . substr( $md5, 4, 3 );
		$md5_file = substr( $md5, 7 ) . '.php';

		$path = $current_blog_id . DIRECTORY_SEPARATOR . $md5_path;

		$path = $this->get_path_for_file( $path, true );

		if ( ! $path ) {
			return false;
		} else {
			$path .= DIRECTORY_SEPARATOR . $md5_file;
		}

		if ( '' === $cache_value ) {
			if ( true === $cache_key ) {
				return $this->clear();
			}

			if ( ! $wp_filesystem->is_file( $path ) ) {
				return false;
			}

			return $wp_filesystem->delete( $path );
		} else {
			$expires_at = 0;

			if ( 0 < (int) $expires ) {
				$expires_at = time() + (int) $expires;
			}

			// WPE Compatibility for anonymous file writes
			$this->wpe_compatibility();

			$contents = pack( 'L', $expires_at ) . PHP_EOL . '<?php exit; ?>' . PHP_EOL . maybe_serialize( $cache_value );

			//var_dump( array( 'write_key' => $cache_key, 'write_group' => $group, 'write_expires' => $expires_at, 'write_pack' => pack( 'L', $expires_at ), 'write_value' => $cache_value, 'write_value_serialized' => maybe_serialize( $cache_value ) ) );

			$success = $wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );

			if ( ! $success ) {
				if ( defined( 'PODS_ALT_CACHE_DEBUG' ) && PODS_ALT_CACHE_DEBUG ) {
					echo '<!--' . __CLASS__ . ': File cannot be written (' . $path . ')-->' . "\n";
				}

				return false;
			}

			if ( defined( 'PODS_ALT_CACHE_DEBUG' ) && PODS_ALT_CACHE_DEBUG ) {
				echo '<!--' . __CLASS__ . ': File written (' . $path . ')-->' . "\n";
			}
		}

		return true;

	}

	/**
	 * Clear file cache
	 *
	 * @return bool
	 */
	public function clear() {

		require_once( ABSPATH . 'wp-admin/includes/file.php' );

		/**
		 * @var $wp_filesystem WP_Filesystem_Base
		 */
		global $wp_filesystem;

		WP_Filesystem();

		if ( ! $wp_filesystem ) {
			return false;
		} // Check if directory exists
		elseif ( ! $wp_filesystem->is_dir( PODS_ALT_FILE_CACHE_DIR ) ) {
			return false;
		}

		// Delete all files in directory
		$this->delete_files_in_directory( PODS_ALT_FILE_CACHE_DIR );

		return true;

	}

	/**
	 * Get the path to the cache directory for the file, attempt to create if it doesn't exist
	 *
	 * @param string $file  File path
	 * @param bool   $mkdir Whether to attempt to create the directory
	 *
	 * @return string|false The path, false if the path couldn't be created
	 */
	public function get_path_for_file( $file, $mkdir = false ) {

		$path = PODS_ALT_FILE_CACHE_DIR . DIRECTORY_SEPARATOR . trim( $file, DIRECTORY_SEPARATOR );

		require_once( ABSPATH . 'wp-admin/includes/file.php' );

		/**
		 * @var $wp_filesystem WP_Filesystem_Base
		 */
		global $wp_filesystem;

		WP_Filesystem();

		if ( ! $wp_filesystem ) {
			$path = false;
		} elseif ( ! $wp_filesystem->is_dir( dirname( $path ) ) ) {
			if ( $mkdir ) {
				$directories = explode( DIRECTORY_SEPARATOR, $file );

				array_unshift( $directories, PODS_ALT_FILE_CACHE_DIR );

				$dir_path = '';

				foreach ( $directories as $directory ) {
					$dir_path .= DIRECTORY_SEPARATOR . $directory;

					if ( ! $wp_filesystem->is_dir( $dir_path ) && ! $wp_filesystem->mkdir( $dir_path, FS_CHMOD_DIR ) ) {
						$path = false;

						break;
					}
				}
			} else {
				$path = false;
			}
		}

		return $path;

	}

	/**
	 * Delete all files in a directory
	 *
	 * @param string|null $directory
	 */
	public function delete_files_in_directory( $directory = null ) {

		if ( null === $directory ) {
			$directory = PODS_ALT_FILE_CACHE_DIR;
		}

		require_once( ABSPATH . 'wp-admin/includes/file.php' );

		/**
		 * @var $wp_filesystem WP_Filesystem_Base
		 */
		global $wp_filesystem;

		WP_Filesystem();

		if ( ! $wp_filesystem || ! $wp_filesystem->is_dir( $directory ) ) {
			return;
		}

		$file_list = $wp_filesystem->dirlist( $directory, false );

		foreach ( $file_list as $file ) {

			$file_path = $directory . DIRECTORY_SEPARATOR . $file['name'];

			if ( 'f' == $file['type'] ) {
				// Delete folder
				$this->delete_files_in_directory( $file_path );
			} else {
				// Delete file
				$wp_filesystem->delete( $file_path );
			}

			if ( PODS_ALT_FILE_CACHE_DIR !== $directory ) {
				$wp_filesystem->rmdir( $directory );
			}

		}

	}

}
