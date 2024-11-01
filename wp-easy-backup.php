<?php
/*
Plugin Name: WP Easy Backup
Plugin URI: http://www.wpeasybackup.com
Description: Create a database dump and zip all your media, theme and plugin files with one click.
Author: Michael R. Hunter
Version: 1.0.3
Author URI: http://www.michaelrhunter.com/
*/

// Define the directory seperator if it isn't already
if(! defined('DS')) {
	if(strtoupper(substr(PHP_OS, 0, 3)) == 'WIN') {
		define('DS', '\\');
	} else {
		define('DS', '/');
	}
}

if(! defined('ROWS_PER_SEGMENT')) {
	define('ROWS_PER_SEGMENT', 100);
}


class WP_Backup {
	var $errors;
	var $upload_dir;
	var $upload_url;
	var $db_filename;
	var $gz_filename;
	var $nicename;
	var $fp;
	var $replaced;
	
	function __construct() {
		$this->errors = array();
		$this->upload_dir =(defined('WP_CONTENT_DIR')) ? WP_CONTENT_DIR . '/uploads' : ABSPATH . 'wp-content' . DS . 'uploads';
		$this->upload_url =(defined('WP_CONTENT_URL')) ? WP_CONTENT_URL . '/uploads' : get_option('siteurl') . '/wp-content/uploads';
		
		$name = str_replace(array('.', 'http://'), array('_', ''), get_bloginfo('siteurl'));
		$hash = substr(md5(md5(DB_PASSWORD)), - 5);
		$this->filename = 'dump.sql';
		$this->gz_filename = $name . '-' . $hash . '.zip';
		$this->nicename = $name . '.zip';
		
		$this->replaced['serialized']['count'] = 0;
		$this->replaced['serialized']['strings'] = '';
		$this->replaced['nonserialized']['count'] = 0;
		
		if(is_admin()) {
			set_time_limit(0);
		}
	}
	
	function show_error($key) {
		if(isset($this->errors[$key])) {
			echo '<br /><span style="color: #cc0000; font-weight: bold;">', $this->errors[$key], '</span>';
		}
	}
	
	function options_page() {
		?>

<div class="wrap">
<h2 style="margin-bottom: 0.5em;">WP Easy Backup</h2>

            <?php
		if(isset($_POST['Submit'])) {
			if(empty($this->errors)) {
				$this->fp = $this->open($this->upload_dir . DS . $this->filename);
				$this->db_backup_header();
				$this->db_backup();
				$this->close($this->fp);
				$this->Zip(ABSPATH . '/wp-content', ABSPATH . '/wp-content/uploads/' . $this->gz_filename);
			}
			
			if(empty($this->errors)) {
				?>

                    <div class="message updated">

                    <?php
				if(isset($_POST['savefile']) && $_POST['savefile']) {
					add_action('admin_head-settings_page_wp-easy-backup', array($this, 'admin_head'));
					?>
                        <p>Your site has been successfully zipped. Your download should begin any second. Or click here to download
<a href="<?php echo $this->upload_url, '/', $this->gz_filename;?>">Click here to download.</a></p>
                        <?php
				} else {
					?>
                        <p>Your site has been successfully zipped. <a href="<?php echo $this->upload_url, '/', $this->gz_filename;?>">Click here to download.</a></p>
                        <?php
				}
				?>

                    </div>
                    <?php
			}
			$form_values = $_POST;
		} else {
			$form_values['old_url'] = get_bloginfo('siteurl');
			
			$form_values['old_path'] = dirname(__FILE__);
			$form_values['old_path'] = str_replace(DS . 'wp-easy-backup', '', $form_values['old_path']);
			$form_values['old_path'] = realpath($form_values['old_path'] . '/../..');
			
			if(get_bloginfo('siteurl') != get_bloginfo('wpurl')) {
				$wp_dir = str_replace(get_bloginfo('siteurl'), '', get_bloginfo('wpurl'));
				$wp_dir = str_replace('/', DS, $wp_dir);
				$form_values['old_path'] = str_replace($wp_dir, '', $form_values['old_path']);
			}
		}
		
		if(! isset($_POST['Submit']) ||(isset($_POST['Submit']) && ! empty($this->errors))) {
			if(! is_writable($this->upload_dir)) {
				?>

                    <div id="message" class="message error">
						<p>The directory <?php echo $this->upload_dir;?> needs to be writable.</p>
					</div>

                    <?php
			}
			
			if(! empty($this->errors)) {
				?>

                    <div id="message" class="message error">
<p>Sorry, there were errors with your form submission. Please correct
them below and try again.</p>
</div>

                    <?php
			}
			?>

                <p>WP Easy Backup exports your database as a MySQL data
dump and creates a gzip package with all theme and plugins files.</p>
<p>It can take a few minutes depending on how much data your site has.</p>
<p>The database dump is found in the "uploads" dir</p>
<form method="post">
<input id="savefile" type="hidden" value="1" name="savefile" />
<p class="submit"><input class="button" type="submit"
	value="Generate WP Easy Backup Zip" name="Submit" /></p>
</form>
                <?php
		}
		?>
        </div>
<?php
	}
	
	function replace_sql_strings($search, $replace, $subject) {
		$search_esc = mysql_real_escape_string($search);
		$replace_esc = mysql_real_escape_string($replace);
		
		$regex = '@s:([0-9]+):"(.*?)' . preg_quote($search_esc, '@') . '(.*?)";@';
		
		if(preg_match_all($regex, $subject, $matches, PREG_SET_ORDER)) {
			foreach($matches as $match) {
				if(preg_match_all('@s:([0-9]+):"(.*?)";@', $match[0], $finds, PREG_SET_ORDER)) {
					foreach($finds as $find) {
						if(false === strpos($find[0], $search_esc))
							continue;
						
						list($old_line, $old_strlen, $old_str) = $find;
						
						$new_str = str_replace($search_esc, $replace_esc, $old_str);
						$new_strlen = strlen($new_str) - strlen($old_str) + $old_strlen;
						$new_line = sprintf('s:%s:"%s";', $new_strlen, $new_str);
						
						$subject = str_replace($old_line, $new_line, $subject, $count);
						
						if($count) {
							$this->replaced['serialized']['strings'] .= $old_line . "\n";
							$this->replaced['serialized']['strings'] .= $new_line . "\n\n";
							
							$this->replaced['serialized']['count'] += $count;
						}
					}
				}
			}
		}
		
		$subject = str_replace($search_esc, $replace_esc, $subject, $count);
		
		$this->replaced['nonserialized']['count'] += $count;
		
		return $subject;
	}
	
	/**
	 * Taken partially from phpMyAdmin and partially from
	 * Alain Wolf, Zurich - Switzerland
	 * Website: http://restkultur.ch/personal/wolf/scripts/db_backup/

	 * Modified by Scott Merrill(http://www.skippy.net/)
	 * to use the WordPress $wpdb object
	 * @param string $table
	 * @param string $segment
	 * @return void
	 */
	function backup_table($table, $segment = 'none') {
		global $wpdb;
		
		$table_structure = $wpdb->get_results("DESCRIBE $table");
		if(! $table_structure) {
			$this->error(__('Error getting table details', 'wp-easy-backup') . ": $table");
			return false;
		}
		
		if(($segment == 'none') ||($segment == 0)) {
			// Add SQL statement to drop existing table
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('Delete any existing table %s', 'wp-easy-backup'), $this->backquote($table)) . "\n");
			$this->stow("#\n");
			$this->stow("\n");
			$this->stow("DROP TABLE IF EXISTS " . $this->backquote($table) . ";\n");
			
			// Table structure
			// Comment in SQL-file
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('Table structure of table %s', 'wp-easy-backup'), $this->backquote($table)) . "\n");
			$this->stow("#\n");
			$this->stow("\n");
			
			$create_table = $wpdb->get_results("SHOW CREATE TABLE $table", ARRAY_N);
			if(false === $create_table) {
				$err_msg = sprintf(__('Error with SHOW CREATE TABLE for %s.', 'wp-easy-backup'), $table);
				$this->error($err_msg);
				$this->stow("#\n# $err_msg\n#\n");
			}
			$this->stow($create_table[0][1] . ' ;');
			
			if(false === $table_structure) {
				$err_msg = sprintf(__('Error getting table structure of %s', 'wp-easy-backup'), $table);
				$this->error($err_msg);
				$this->stow("#\n# $err_msg\n#\n");
			}
			
			// Comment in SQL-file
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow('# ' . sprintf(__('Data contents of table %s', 'wp-easy-backup'), $this->backquote($table)) . "\n");
			$this->stow("#\n");
		}
		
		if(($segment == 'none') ||($segment >= 0)) {
			$defs = array();
			$ints = array();
			foreach($table_structure as $struct) {
				if((0 === strpos($struct->Type, 'tinyint')) ||(0 === strpos(strtolower($struct->Type), 'smallint')) ||(0 === strpos(strtolower($struct->Type), 'mediumint')) ||(0 === strpos(strtolower($struct->Type), 'int')) ||(0 === strpos(strtolower($struct->Type), 'bigint'))) {
					$defs[strtolower($struct->Field)] =(null === $struct->Default) ? 'NULL' : $struct->Default;
					$ints[strtolower($struct->Field)] = "1";
				}
			}
			
			// Batch by $row_inc
			

			if($segment == 'none') {
				$row_start = 0;
				$row_inc = ROWS_PER_SEGMENT;
			} else {
				$row_start = $segment * ROWS_PER_SEGMENT;
				$row_inc = ROWS_PER_SEGMENT;
			}
			
			do {
				// don't include extra stuff, if so requested
				$excs =(array) get_option('wp_db_backup_excs');
				$where = '';
				if(is_array($excs['spam']) && in_array($table, $excs['spam'])) {
					$where = ' WHERE comment_approved != "spam"';
				} elseif(is_array($excs['revisions']) && in_array($table, $excs['revisions'])) {
					$where = ' WHERE post_type != "revision"';
				}
				
				if(! ini_get('safe_mode'))
					@set_time_limit(15 * 60);
				$table_data = $wpdb->get_results("SELECT * FROM $table $where LIMIT {$row_start}, {$row_inc}", ARRAY_A);
				
				$entries = 'INSERT INTO ' . $this->backquote($table) . ' VALUES(';
				//    \x08\\x09, not required
				$search = array("\x00", "\x0a", "\x0d", "\x1a");
				$replace = array('\0', '\n', '\r', '\Z');
				if($table_data) {
					foreach($table_data as $row) {
						$values = array();
						foreach($row as $key => $value) {
							if($ints[strtolower($key)]) {
								// make sure there are no blank spots in the insert syntax,
								// yet try to avoid quotation marks around integers
								$value =(null === $value || '' === $value) ? $defs[strtolower($key)] : $value;
								$values[] =('' === $value) ? "''" : $value;
							} else {
								if(null === $value)
									$values[] = 'NULL';
								else
									$values[] = "'" . str_replace($search, $replace, $this->sql_addslashes($value)) . "'";
							}
						}
						$this->stow(" \n" . $entries . implode(', ', $values) . ') ;');
					}
					$row_start += $row_inc;
				}
			} while((count($table_data) > 0) and($segment == 'none'));
		}
		
		if(($segment == 'none') ||($segment < 0)) {
			// Create footer/closing comment in SQL-file
			$this->stow("\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('End of data contents of table %s', 'wp-easy-backup'), $this->backquote($table)) . "\n");
			$this->stow("# --------------------------------------------------------\n");
			$this->stow("\n");
		}
	} // end backup_table()
	

	function db_backup() {
		global $table_prefix, $wpdb;
		
		$tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
		$tables = array_map(create_function('$a', 'return $a[0];'), $tables);
		
		/*
        if(is_writable($this->backup_dir)) {
			$this->fp = $this->open($this->backup_dir . $this->backup_filename);
			if(!$this->fp) {
				$this->error(__('Could not open the backup file for writing!','wp-easy-backup'));
				return false;
			}
		} else {
			$this->error(__('The backup directory is not writeable!','wp-easy-backup'));
			return false;
		}*/
		
		foreach($tables as $table) {
			// Increase script execution time-limit to 15 min for every table.
			if(! ini_get('safe_mode'))
				@set_time_limit(15 * 60);
			
		// Create the SQL statements
			$this->stow("# --------------------------------------------------------\n");
			$this->stow("# " . sprintf(__('Table: %s', 'wp-easy-backup'), $this->backquote($table)) . "\n");
			$this->stow("# --------------------------------------------------------\n");
			$this->backup_table($table);
		}
		
		//$this->close($this->fp);
		

		if(count($this->errors)) {
			return false;
		} else {
			//return $this->backup_filename;
			return true;
		}
	
	} //wp_db_backup
	

	function db_backup_header() {
		$this->stow("# " . __('WordPress MySQL database migration', 'wp-easy-backup') . "\n", false);
		$this->stow("# " . sprintf(__('Hostname: %s', 'wp-easy-backup'), get_bloginfo('siteurl')) . "\n", false);
		$this->stow("#\n", false);
		$this->stow("# " . sprintf(__('Generated: %s', 'wp-easy-backup'), date("l j. F Y H:i T")) . "\n", false);
		$this->stow("# " . sprintf(__('Hostname: %s', 'wp-easy-backup'), DB_HOST) . "\n", false);
		$this->stow("# " . sprintf(__('Database: %s', 'wp-easy-backup'), $this->backquote(DB_NAME)) . "\n", false);
		$this->stow("# --------------------------------------------------------\n\n", false);
	}
	
	function gzip() {
		return false; //function_exists('gzopen');
	}
	
	function open($filename = '', $mode = 'w') {
		if('' == $filename)
			return false;
		if($this->gzip())
			$fp = gzopen($filename, $mode);
		else
			$fp = fopen($filename, $mode);
		return $fp;
	}
	
	function close($fp) {
		if($this->gzip())
			gzclose($fp);
		else
			fclose($fp);
	}
	
	function stow($query_line, $replace = false) {
		if($replace) {
			$query_line = $this->replace_sql_strings($_POST['old_url'], $_POST['new_url'], $query_line);
			$query_line = $this->replace_sql_strings($_POST['old_path'], $_POST['new_path'], $query_line);
		}
		//$query_line = 
		if($this->gzip()) {
			if(! @gzwrite($this->fp, $query_line))
				$this->errors['file_write'] = __('There was an error writing a line to the backup script:', 'wp-db-backup') . '  ' . $query_line . '  ' . $php_errormsg;
		} else {
			if(false === @fwrite($this->fp, $query_line))
				$this->error['file_write'] = __('There was an error writing a line to the backup script:', 'wp-db-backup') . '  ' . $query_line . '  ' . $php_errormsg;
		}
	}
	
	/**
	 * Add backquotes to tables and db-names in
	 * SQL queries. Taken from phpMyAdmin.
	 */
	function backquote($a_name) {
		if(! empty($a_name) && $a_name != '*') {
			if(is_array($a_name)) {
				$result = array();
				reset($a_name);
				while(list($key, $val) = each($a_name))
					$result[$key] = '`' . $val . '`';
				return $result;
			} else {
				return '`' . $a_name . '`';
			}
		} else {
			return $a_name;
		}
	}
	
	/**
	 * Better addslashes for SQL queries.
	 * Taken from phpMyAdmin.
	 */
	function sql_addslashes($a_string = '', $is_like = false) {
		if($is_like)
			$a_string = str_replace('\\', '\\\\\\\\', $a_string);
		else
			$a_string = str_replace('\\', '\\\\', $a_string);
		
		$a_string = str_replace('’', '&#8217;', $a_string);
		$a_string = utf8_decode($a_string);
		return str_replace('\'', '\\\'', $a_string);
	}
	
	function download_file() {
		set_time_limit(0);
		$diskfile = $this->upload_dir . DS . $this->gz_filename;
		if(file_exists($diskfile)) {
			header('Content-Description: File Transfer');
			header('Content-Type: application/octet-stream');
			header('Content-Length: ' . filesize($diskfile));
			header("Content-Disposition: attachment; filename={$this->nicename}");
			$success = readfile($diskfile);
			unlink($diskfile);
			exit();
		} else {
			wp_die('Could not find the file to download.');
		}
	}
	
	function admin_menu() {
		if(function_exists('add_management_page')) {
			add_management_page('WP Easy Backup', 'WP Easy Backup', 'level_8', 'wp-easy-backup', array($this, 'options_page'));
		}
	}
	
	function admin_head() {
		return;
		$url = admin_url('tools.php?page=wp-easy-backup&download=true');
		?>
<meta http-equiv="refresh" content="1;url=<?php
		echo $url;
		?>" />
<?php
	}
	
	function Zip($source, $destination) {
		if(! extension_loaded('zip') || ! file_exists($source)) {
			return false;
		}
		
		if(file_exists($destination))
			unlink($destination);
		
		$zip = new ZipArchive();
		if(! $zip->open($destination, ZIPARCHIVE::CREATE)) {
			return false;
		}
		
		$source = str_replace('\\', '/', realpath($source));
		
		if(is_dir($source) === true) {
			$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);
			
			foreach($files as $file) {
				$file = str_replace('\\', '/', realpath($file));
				
				if(is_dir($file) === true) {
					$zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
				} else if(is_file($file) === true) {
					$zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
				}
			}
		} else if(is_file($source) === true) {
			$zip->addFromString(basename($source), file_get_contents($source));
		}
		
		return $zip->close();
	}
}



global $wpbackup;
$wpbackup = new WP_Backup();

if(is_admin()) {
	add_action('admin_menu', array($wpbackup, 'admin_menu'));
	
	if(isset($_GET['page']) && $_GET['page'] == 'wp-easy-backup') {
		if(isset($_POST['savefile']) && $_POST['savefile']) {
			add_action('admin_head-tools_page_wp-easy-backup', array($wpbackup, 'admin_head'));
		}
		
		if(isset($_GET['download']) && $_GET['download']) {
			add_action('init', array($wpbackup, 'download_file'));
		}
	}
}

?>