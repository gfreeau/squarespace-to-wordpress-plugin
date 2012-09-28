<?php
/*
Plugin Name: Squarespace Importer
Description: Import posts and comments from a Squarespace blog. Based on Movable Type and TypePad Importer
Author: Greg Freeman
Author URI: http://www.keleko.com/
Version: 0.1
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if ( !defined('WP_LOAD_IMPORTERS') )
	return;

ini_set('max_execution_time', 0);

require_once ABSPATH . 'wp-admin/includes/import.php';

class Squarespace_Import {
	protected $posts = array();
	protected $file;
	protected $id;
	protected $ssnames = array();
	protected $newauthornames = array();
	protected $j = -1;
	
	public function __construct() {
		// Nothing.
	}
	
	public function dispatch() {
		if ( ! $this->check_squarespace_url() ) {
			return;
		}
		
		if (empty ($_GET['step']))
			$step = 0;
		else
			$step = (int) $_GET['step'];

		switch ($step) {
			case 0 :
				$this->greet();
				break;
			case 1 :
				check_admin_referer('import-upload');
				$this->select_authors();
				break;
			case 2:
				check_admin_referer('import-ss');
				set_time_limit(0);
				$result = $this->import();
				if ( is_wp_error( $result ) )
					echo $result->get_error_message();
				break;
		}
	}

	protected function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>'.__('Import Squarespace', 'squarespace-importer').'</h2>';
	}

	protected function footer() {
		echo '</div>';
	}
	
	protected function check_squarespace_url() {
		if ( defined( 'SQUARESPACE_URL' ) )
			return true;
		
		$this->header();
		echo '<p>'.__('Before continuing, please define your squarespace url in wp-config.php. Add the following lines:', 'squarespace-importer').'</p></div>';
		echo "<p><strong>define('SQUARESPACE_URL', 'http://www.yoursquarespacedomain.com');</strong></p></div>";
		echo '<p>'.__('This value is prepended to your image links in posts because squarespace image urls are relative', 'squarespace-importer').'</p></div>';
		$this->footer();
		
		return false;
	}

	protected function greet() {
		$this->header();
?>
<div class="narrow">
<p><?php _e( 'We are about to begin importing all of your Squarespace entries into WordPress. To begin, either choose a file to upload and click &#8220;Upload file and import&#8221;, or use FTP to upload your Squarespace export file as <code>ss-export.txt</code> in your <code>/wp-content/</code> directory and then click &#8220;Import ss-export.txt&#8221;.' , 'squarespace-importer'); ?></p>

<?php wp_import_upload_form( add_query_arg('step', 1) ); ?>
<form method="post" action="<?php echo esc_attr(add_query_arg('step', 1)); ?>" class="import-upload-form">

<?php wp_nonce_field('import-upload'); ?>
<p>
	<input type="hidden" name="upload_type" value="ftp" />
<?php _e('Or use <code>ss-export.txt</code> in your <code>/wp-content/</code> directory', 'squarespace-importer'); ?></p>
<p class="submit">
<input type="submit" class="button" value="<?php esc_attr_e('Import ss-export.txt', 'squarespace-importer'); ?>" />
</p>
</form>
<p><?php _e('The importer is smart enough not to import duplicates, so you can run this multiple times without worry if&#8212;for whatever reason&#8212;it doesn&#8217;t finish. If you get an <strong>out of memory</strong> error try splitting up the import file into pieces.', 'squarespace-importer'); ?> </p>
</div>
<?php
		$this->footer();
	}

	protected function users_form($n) {
		$users = get_users_of_blog();
?><select name="userselect[<?php echo $n; ?>]">
	<option value="#NONE#"><?php _e('&mdash; Select &mdash;', 'squarespace-importer') ?></option>
	<?php
		foreach ( $users as $user )
			echo '<option value="' . $user->user_login . '">' . $user->user_login . '</option>';
	?>
	</select>
	<?php
	}

	protected function has_gzip() {
		return is_callable('gzopen');
	}

	protected function fopen($filename, $mode='r') {
		if ( $this->has_gzip() )
			return gzopen($filename, $mode);
		return fopen($filename, $mode);
	}

	protected function feof($fp) {
		if ( $this->has_gzip() )
			return gzeof($fp);
		return feof($fp);
	}

	protected function fgets($fp, $len=8192) {
		if ( $this->has_gzip() )
			return gzgets($fp, $len);
		return fgets($fp, $len);
	}

	protected function fclose($fp) {
		if ( $this->has_gzip() )
			return gzclose($fp);
		return fclose($fp);
 	}

	//function to check the authorname and do the mapping
	protected function checkauthor($author) {
		//ssnames is an array with the names in the ss import file
		$pass = wp_generate_password();
		if (!(in_array($author, $this->ssnames))) { //a new ss author name is found
			++ $this->j;
			$this->ssnames[$this->j] = $author; //add that new ss author name to an array
			$user_id = username_exists($this->newauthornames[$this->j]); //check if the new author name defined by the user is a pre-existing wp user
			if (!$user_id) { //banging my head against the desk now.
				if ($this->newauthornames[$this->j] == 'left_blank') { //check if the user does not want to change the authorname
					$user_id = wp_create_user($author, $pass);
					$this->newauthornames[$this->j] = $author; //now we have a name, in the place of left_blank.
				} else {
					$user_id = wp_create_user($this->newauthornames[$this->j], $pass);
				}
			} else {
				return $user_id; // return pre-existing wp username if it exists
			}
		} else {
			$key = array_search($author, $this->ssnames); //find the array key for $author in the $ssnames array
			$user_id = username_exists($this->newauthornames[$key]); //use that key to get the value of the author's name from $newauthornames
		}

		return $user_id;
	}

	protected function get_ss_authors() {
		$temp = array();
		$authors = array();

		$handle = $this->fopen($this->file, 'r');
		if ( $handle == null )
			return false;

		$in_comment = false;
		while ( $line = $this->fgets($handle) ) {
			$line = trim($line);

			if ( 'COMMENT:' == $line )
				$in_comment = true;
			else if ( '-----' == $line )
				$in_comment = false;

			if ( $in_comment || 0 !== strpos($line,"AUTHOR:") )
				continue;

			$temp[] = trim( substr($line, strlen("AUTHOR:")) );
		}

		//we need to find unique values of author names, while preserving the order, so this function emulates the unique_value(); php function, without the sorting.
		$authors[0] = array_shift($temp);
		$y = count($temp) + 1;
		for ($x = 1; $x < $y; $x ++) {
			$next = array_shift($temp);
			if (!(in_array($next, $authors)))
				array_push($authors, $next);
		}

		$this->fclose($handle);

		return $authors;
	}

	protected function get_authors_from_post() {
		$formnames = array ();
		$selectnames = array ();

		foreach ($_POST['user'] as $key => $line) {
			$newname = trim(stripslashes($line));
			if ($newname == '')
				$newname = 'left_blank'; //passing author names from step 1 to step 2 is accomplished by using POST. left_blank denotes an empty entry in the form.
			array_push($formnames, $newname);
		} // $formnames is the array with the form entered names

		foreach ($_POST['userselect'] as $user => $key) {
			$selected = trim(stripslashes($key));
			array_push($selectnames, $selected);
		}

		$count = count($formnames);
		for ($i = 0; $i < $count; $i ++) {
			if ($selectnames[$i] != '#NONE#') { //if no name was selected from the select menu, use the name entered in the form
				array_push($this->newauthornames, "$selectnames[$i]");
			} else {
				array_push($this->newauthornames, "$formnames[$i]");
			}
		}
	}

	protected function ss_authors_form() {
?>
<div class="wrap">
<?php screen_icon(); ?>
<h2><?php _e('Assign Authors', 'squarespace-importer'); ?></h2>
<p><?php _e('To make it easier for you to edit and save the imported posts and drafts, you may want to change the name of the author of the posts. For example, you may want to import all the entries as admin&#8217;s entries.', 'squarespace-importer'); ?></p>
<p><?php _e('Below, you can see the names of the authors of the Squarespace posts in <em>italics</em>. For each of these names, you can either pick an author in your WordPress installation from the menu, or enter a name for the author in the textbox.', 'squarespace-importer'); ?></p>
<p><?php _e('If a new user is created by WordPress, a password will be randomly generated. Manually change the user&#8217;s details if necessary.', 'squarespace-importer'); ?></p>
	<?php


		$authors = $this->get_ss_authors();
		echo '<ol id="authors">';
		echo '<form action="?import=ss&amp;step=2&amp;id=' . $this->id . '" method="post">';
		wp_nonce_field('import-ss');
		$j = -1;
		foreach ($authors as $author) {
			++ $j;
			echo '<li><label>'.__('Current author:', 'squarespace-importer').' <strong>'.$author.'</strong><br />'.sprintf(__('Create user %1$s or map to existing', 'squarespace-importer'), ' <input type="text" value="'. esc_attr($author) .'" name="'.'user[]'.'" maxlength="30"> <br />');
			$this->users_form($j);
			echo '</label></li>';
		}

		echo '<p class="submit"><input type="submit" class="button" value="'.esc_attr__('Submit', 'squarespace-importer').'"></p>'.'<br />';
		echo '</form>';
		echo '</ol></div>';

	}

	protected function select_authors() {
		if ( $_POST['upload_type'] === 'ftp' ) {
			$file['file'] = WP_CONTENT_DIR . '/ss-export.txt';
			if ( !file_exists($file['file']) )
				$file['error'] = __('<code>ss-export.txt</code> does not exist', 'squarespace-importer');
		} else {
			$file = wp_import_handle_upload();
		}
		if ( isset($file['error']) ) {
			$this->header();
			echo '<p>'.__('Sorry, there has been an error', 'squarespace-importer').'.</p>';
			echo '<p><strong>' . $file['error'] . '</strong></p>';
			$this->footer();
			return;
		}
		$this->file = $file['file'];
		$this->id = (int) $file['id'];

		$this->ss_authors_form();
	}

	protected function save_post(&$post, &$comments, &$pings) {
		$post = get_object_vars($post);
		//$post = add_magic_quotes($post);
		$post = (object) $post;

		if ( $post_id = post_exists($post->post_title, '', $post->post_date) ) {
			echo '<li>';
			printf(__('Post <em>%s</em> already exists.', 'squarespace-importer'), stripslashes($post->post_title));
			$post = get_post( $post_id );
		} else {
			echo '<li>';
			printf(__('Importing post <em>%s</em>...', 'squarespace-importer'), stripslashes($post->post_title));

			if ( '' != trim( $post->extended ) )
					$post->post_content .= "\n<!--more-->\n$post->extended";

			$post->post_author = $this->checkauthor($post->post_author); //just so that if a post already exists, new users are not created by checkauthor
			$post_id = wp_insert_post($post);
			if ( is_wp_error( $post_id ) )
				return $post_id;
			
			$post->ID = $post_id;

			// Add categories.
			if ( 0 != count($post->categories) ) {
				wp_create_categories($post->categories, $post_id);
			}

			 // Add tags or keywords
			if ( 1 < strlen($post->post_keywords) ) {
			 	// Keywords exist.
				printf('<br />'.__('Adding tags <em>%s</em>...', 'squarespace-importer'), stripslashes($post->post_keywords));
				wp_add_post_tags($post_id, $post->post_keywords);
			}
		}
		
		$this->download_images( $post );

		$num_comments = 0;
		foreach ( $comments as $comment ) {
			$comment = get_object_vars($comment);
			$comment = add_magic_quotes($comment);

			if ( !comment_exists($comment['comment_author'], $comment['comment_date'])) {
				$comment['comment_post_ID'] = $post_id;
				$comment = wp_filter_comment($comment);
				wp_insert_comment($comment);
				$num_comments++;
			}
		}

		if ( $num_comments )
			printf(' '._n('(%s comment)', '(%s comments)', $num_comments, 'squarespace-importer'), $num_comments);

		$num_pings = 0;
		foreach ( $pings as $ping ) {
			$ping = get_object_vars($ping);
			$ping = add_magic_quotes($ping);

			if ( !comment_exists($ping['comment_author'], $ping['comment_date'])) {
				$ping['comment_content'] = "<strong>{$ping['title']}</strong>\n\n{$ping['comment_content']}";
				$ping['comment_post_ID'] = $post_id;
				$ping = wp_filter_comment($ping);
				wp_insert_comment($ping);
				$num_pings++;
			}
		}

		if ( $num_pings )
			printf(' '._n('(%s ping)', '(%s pings)', $num_pings, 'squarespace-importer'), $num_pings);

		echo "</li>";

        ob_flush();flush();
	}
	
	protected function download_images( $post ) {
		// Match squarespace images
		preg_match_all('#(?<ss_image><img.*src="(?<ss_src>/storage/[^"]+)".*>)#Um', $post->post_content, $images);

        if ( ! $num_images = count($images['ss_src']) )
			return;

        $images_downloaded = false;
		
		for( $i = 0; $i < $num_images; $i++ ) {
			$img_html = $images['ss_image'][$i];
            $src = $images['ss_src'][$i];
            $alt = "";

            preg_match('#alt="([^"]+)"#', $img_html, $matches);
            if (isset($matches[1]))
                $alt = $matches[1];

            unset($matches);

			$full_src = $src;
			if ( ! preg_match( '#^https?://#', $full_src ) )
				$full_src = rtrim(SQUARESPACE_URL, '/') . $full_src;
			
			/*if( strpos( $src, 'SQUARESPACE_CACHEVERSION' ) === FALSE ) {
				continue;
			}*/
			
			$new_html = media_sideload_image( $full_src, $post->ID, $alt );
			
			if ( is_wp_error( $new_html ) ) {
				printf('<br />'.__('<em>%s</em> <strong>could not be downloaded</strong>', 'squarespace-importer'), $full_src );
				continue;
			}
			
			$post->post_content = str_replace( $img_html, $new_html, $post->post_content );
			
			printf('<br />'.__('Squarespace image imported <em>%s</em>...', 'squarespace-importer'), esc_attr($src) );
			
			$images_downloaded = true;
		}

        echo '<br />';
		
		if ( ! get_post_meta( $post->ID, '_thumbnail_id', true ) ) {
			$args = array(
				'post_parent'    => $post->ID,
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'numberposts'    => 1,
				'order'          => 'ASC',
			);
			
			$images =& get_children( $args );
			if ( ! empty( $images ) ) {
				$first_image = current( $images );
				update_post_meta( $post->ID, '_thumbnail_id', $first_image->ID );
			}
		}
		
		if ( $images_downloaded )
			wp_update_post( array( 'ID' => $post->ID, 'post_content' => $post->post_content) );
	}

	protected function process_posts() {
		global $wpdb;

		$handle = $this->fopen($this->file, 'r');
		if ( $handle == null )
			return false;

		$context = '';
		$post = new StdClass();
		$comment = new StdClass();
		$comments = array();
		$ping = new StdClass();
		$pings = array();

		echo "<div class='wrap'><ol>";

		while ( $line = $this->fgets($handle) ) {
			$line = trim($line);

			if ( '-----' == $line ) {
				// Finishing a multi-line field
				if ( 'comment' == $context ) {
					$comments[] = $comment;
					$comment = new StdClass();
				} else if ( 'ping' == $context ) {
					$pings[] = $ping;
					$ping = new StdClass();
				}
				$context = '';
			} else if ( '--------' == $line ) {
				// Finishing a post.
				$context = '';
				$result = $this->save_post($post, $comments, $pings);
				if ( is_wp_error( $result ) )
					return $result;
				$post = new StdClass;
				$comment = new StdClass();
				$ping = new StdClass();
				$comments = array();
				$pings = array();
			} else if ( 'BODY:' == $line ) {
				$context = 'body';
			} else if ( 'EXTENDED BODY:' == $line ) {
				$context = 'extended';
			} else if ( 'EXCERPT:' == $line ) {
				$context = 'excerpt';
			} else if ( 'KEYWORDS:' == $line ) {
				$context = 'keywords';
			} else if ( 'COMMENT:' == $line ) {
				$context = 'comment';
			} else if ( 'PING:' == $line ) {
				$context = 'ping';
			} else if ( 0 === strpos($line, 'AUTHOR:') ) {
				$author = trim( substr($line, strlen('AUTHOR:')) );
				if ( '' == $context )
					$post->post_author = $author;
				else if ( 'comment' == $context )
					 $comment->comment_author = $author;
			} else if ( 0 === strpos($line, 'TITLE:') ) {
				$title = trim( substr($line, strlen('TITLE:')) );
				if ( '' == $context )
					$post->post_title = $title;
				else if ( 'ping' == $context )
					$ping->title = $title;
			} else if ( 0 === strpos($line, 'BASENAME:') ) {
				$slug = trim( substr($line, strlen('BASENAME:')) );
				if ( !empty( $slug ) )
					$post->post_name = $slug;
			} else if ( 0 === strpos($line, 'STATUS:') ) {
				$status = trim( strtolower( substr($line, strlen('STATUS:')) ) );
				if ( empty($status) )
					$status = 'publish';
				$post->post_status = $status;
			} else if ( 0 === strpos($line, 'ALLOW COMMENTS:') ) {
				$allow = trim( substr($line, strlen('ALLOW COMMENTS:')) );
				if ( $allow == 1 )
					$post->comment_status = 'open';
				else
					$post->comment_status = 'closed';
			} else if ( 0 === strpos($line, 'ALLOW PINGS:') ) {
				$allow = trim( substr($line, strlen('ALLOW PINGS:')) );
				if ( $allow == 1 )
					$post->ping_status = 'open';
				else
					$post->ping_status = 'closed';
			} else if ( 0 === strpos($line, 'CATEGORY:') ) {
				$category = trim( substr($line, strlen('CATEGORY:')) );
				if ( '' != $category )
					$post->categories[] = $category;
			} else if ( 0 === strpos($line, 'PRIMARY CATEGORY:') ) {
				$category = trim( substr($line, strlen('PRIMARY CATEGORY:')) );
				if ( '' != $category )
					$post->categories[] = $category;
			} else if ( 0 === strpos($line, 'DATE:') ) {
				$date = trim( substr($line, strlen('DATE:')) );
				$date = strtotime($date);
				$date = date('Y-m-d H:i:s', $date);
				$date_gmt = get_gmt_from_date($date);
				if ( '' == $context ) {
					$post->post_modified = $date;
					$post->post_modified_gmt = $date_gmt;
					$post->post_date = $date;
					$post->post_date_gmt = $date_gmt;
				} else if ( 'comment' == $context ) {
					$comment->comment_date = $date;
				} else if ( 'ping' == $context ) {
					$ping->comment_date = $date;
				}
			} else if ( 0 === strpos($line, 'EMAIL:') ) {
				$email = trim( substr($line, strlen('EMAIL:')) );
				if ( 'comment' == $context )
					$comment->comment_author_email = $email;
				else
					$ping->comment_author_email = '';
			} else if ( 0 === strpos($line, 'IP:') ) {
				$ip = trim( substr($line, strlen('IP:')) );
				if ( 'comment' == $context )
					$comment->comment_author_IP = $ip;
				else
					$ping->comment_author_IP = $ip;
			} else if ( 0 === strpos($line, 'URL:') ) {
				$url = trim( substr($line, strlen('URL:')) );
				if ( 'comment' == $context )
					$comment->comment_author_url = $url;
				else
					$ping->comment_author_url = $url;
			} else if ( 0 === strpos($line, 'BLOG NAME:') ) {
				$blog = trim( substr($line, strlen('BLOG NAME:')) );
				$ping->comment_author = $blog;
			} else {
				// Processing multi-line field, check context.

				if( !empty($line) )
					$line .= "\n";

				if ( 'body' == $context ) {
					$post->post_content .= $line;
				} else if ( 'extended' ==  $context ) {
					$post->extended .= $line;
				} else if ( 'excerpt' == $context ) {
					$post->post_excerpt .= $line;
				} else if ( 'keywords' == $context ) {
					$post->post_keywords .= $line;
				} else if ( 'comment' == $context ) {
					$comment->comment_content .= $line;
				} else if ( 'ping' == $context ) {
					$ping->comment_content .= $line;
				}
			}
		}

		$this->fclose($handle);

		echo '</ol>';

		wp_import_cleanup($this->id);
		do_action('import_done', 'ss');

		echo '<h3>'.sprintf(__('All done. <a href="%s">Have fun!</a>', 'squarespace-importer'), get_option('home')).'</h3></div>';
	}

	protected function import() {
		$this->id = (int) $_GET['id'];
		if ( $this->id == 0 )
			$this->file = WP_CONTENT_DIR . '/ss-export.txt';
		else
			$this->file = get_attached_file($this->id);
		$this->get_authors_from_post();
		$result = $this->process_posts();
		if ( is_wp_error( $result ) )
			return $result;
	}
}

$ss_import = new Squarespace_Import();
register_importer( 'ss', __( 'Squarespace', 'squarespace-importer' ), __( 'Import posts and comments from a Squarespace blog.', 'squarespace-importer' ), array( $ss_import, 'dispatch' ) );

function squarespace_importer_init() {
    load_plugin_textdomain( 'squarespace-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'init', 'squarespace_importer_init' );


