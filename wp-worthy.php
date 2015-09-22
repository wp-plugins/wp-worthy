<?PHP

  /**
   * @package wp-worthy
   * @author Bernd Holzmueller <bernd@quarxconnect.de>
   * @author Jan P. Günther <jan.guenther@tiggerswelt.net>
   * @license GPLv3
   *
   * @wordpress-plugin
   * Plugin Name: wp-worthy
   * Plugin URI: https://wp-worthy.de/
   * Description: VG-Wort Integration for Wordpress
   * Version: 1.2
   * Author: tiggersWelt.net
   * Author URI: https://tiggerswelt.net/
   * License: GPLv3
   * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
   * Text Domain: wp-worthy
   * Domain Path: /lang
   **/
  
  /**
   * Copyright (C) 2013 Bernd Holzmueller <bernd@quarxconnect.de>
   *
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or   
   * (at your option) any later version.
   * 
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of 
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the  
   * GNU General Public License for more details.  
   *  
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/
  
  if (!defined ('WPINC'))
    die ('Please do not invoke this file directly');
  
  require_once (dirname (__FILE__) . '/qcWp.php');
  require_once (dirname (__FILE__) . '/table/markers.php');
  require_once (dirname (__FILE__) . '/table/posts.php');
  
  class wp_worthy extends qcWp {
    /* Sections for admin-menu */
    const ADMIN_SECTION_OVERVIEW = 'overview';
    const ADMIN_SECTION_MARKERS = 'markers';
    const ADMIN_SECTION_POSTS = 'posts';
    const ADMIN_SECTION_CONVERT = 'convert';
    const ADMIN_SECTION_SETTINGS = 'settings';
    const ADMIN_SECTION_PREMIUM = 'premium';
    
    /* Minimum length of posts to be relevant for VG-Wort */
    const MIN_LENGTH = 1800;
    const WARN_LIMIT = 1600; 
    
    const PREMIUM_STATUS_UPDATE_INTERVAL = 3600;
    const PREMIUM_MARKER_UPDATE_INTERVAL = 604800;
    
    /* Status-Feedback for admin-menu */
    private $adminStatus = array ();
    
    // {{{ __construct
    /**
     * Create a new worthy-plugin
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      // Do some generic stuff first
      parent::__construct (__FILE__);
      
      // Register our stylesheet
      if (is_admin ())
        $this->addStylesheet ('wp-worthy.css');
      else
        add_action ('wp_head', function () {
          echo '<style type="text/css"> #worthy_pixel { line-height: 1px; height: 1px; margin: 0; padding: 0; } </style>';
        });
      
      // Install our menu on admin
      $this->addAdminMenu (
        'Worthy - VG-Wort Integration for Wordpress',
        'Worthy',
        'publish_posts',
        __CLASS__ . '-' . $this::ADMIN_SECTION_OVERVIEW,
        'assets/wp-worthy-small.svg',
        array ($this, 'adminMenuOverview'),
        array ($this, 'adminMenuPrepare'),
        array (
          array ('Overview', 'Overview', 'publish_posts', __CLASS__ . '-' . $this::ADMIN_SECTION_OVERVIEW, array ($this, 'adminMenuOverview')),
          array ('Markers', 'Markers', 'publish_posts', __CLASS__ . '-' . $this::ADMIN_SECTION_MARKERS, array ($this, 'adminMenuMarkers'), array ($this, 'adminMenuMarkersPrepare')),
          array ('Posts', 'Posts', 'publish_posts', __CLASS__ . '-' . $this::ADMIN_SECTION_POSTS, array ($this, 'adminMenuPosts'), array ($this, 'adminMenuPostsPrepare')),
          array ('Import / Export', 'Import / Export', 'publish_posts', __CLASS__ . '-' . $this::ADMIN_SECTION_CONVERT, array ($this, 'adminMenuConvert'), array ($this, 'adminMenuConvertPrepare')),
          array ('Settings', 'Settings', 'publish_posts', __CLASS__ . '-' . $this::ADMIN_SECTION_SETTINGS, array ($this, 'adminMenuSettings'), array ($this, 'adminMenuSettingsPrepare')),
          array ('Premium', 'Premium', 'publish_posts', __CLASS__ . '-' . $this::ADMIN_SECTION_PREMIUM, array ($this, 'adminMenuPremium'), array ($this, 'adminMenuPremiumPrepare')),
        )
      );
      
      // Load counter-javascript for post-editor
      if (is_admin ())
        $this->addScript (
          'wp-worthy.js',
          array (
            'characters' => 'Characters',
            'counter' => 'Characters (VG-Wort)',
            'accept_tac' => 'You have to accept the terms of service and privacy statement before you can continue',
            'no_goods' => 'You don\'t have selected anything to buy, pressing this button does not make sense',
            'empty_giropay_bic' => 'You have to supply a BIC when using Giropay',
          ),
          'wpWorthyLang'
        );
      
      // Add ourself to dashboard
      add_filter ('dashboard_glance_items', array ($this, 'dashboardContent'));
      
      // Hook in to posts/pages tables
      add_filter ('manage_posts_columns', array ($this, 'adminPostColumnHeaders'));
      add_filter ('manage_pages_columns', array ($this, 'adminPostColumnHeaders'));
      add_action ('manage_posts_custom_column', array ($this, 'adminPostColumns'), 10, 2);
      add_action ('manage_pages_custom_column', array ($this, 'adminPostColumns'), 10, 2);
      
      // Append custom option to publish-box
      add_action ('post_submitbox_misc_actions', array ($this, 'adminPostPublishBox'));
      
      // Hook into save-/deleteprocess
      add_action ('admin_notices', array ($this, 'adminAddPostBanner'));
      add_action ('edit_page_form', array ($this, 'adminAddPostBanner'));
      add_action ('edit_form_advanced', array ($this, 'adminAddPostBanner'));
      add_action ('save_post', array ($this, 'adminSavePost'));
      
      // Add VG-Wort pixel to output
      add_filter ('the_content', array ($this, 'addContentMarker'));
      
      // Register our own POST-Handlers
      add_action ('admin_post_wp-worthy-settings', array ($this, 'saveUserSettings'));
      add_action ('admin_post_wp-worthy-import-csv', array ($this, 'importMarkers'));
      add_action ('admin_post_wp-worthy-claim-and-import-csv', array ($this, 'importClaimMarkers'));
      add_action ('admin_post_wp-worthy-report-csv', array ($this, 'reportMarkers'));
      add_action ('admin_post_wp-worthy-export-csv', array ($this, 'exportUnusedMarkers'));
      add_action ('admin_post_wp-worthy-migrate-preview', array ($this, 'migratePostsPreview'));
      add_action ('admin_post_wp-worthy-bulk-migrate', array ($this, 'migratePostsBulk'));
      add_action ('admin_post_wp-worthy-migrate', array ($this, 'migratePosts'));
      add_action ('admin_post_wp-worthy-marker-inquiry', array ($this, 'searchPrivateMarkers'));
      add_action ('admin_post_worthy_reindex', array ($this, 'reindexPosts'));
      add_action ('admin_post_wp-worthy-bulk-assign', array ($this, 'assignPosts'));
      add_action ('admin_post_worthy_bulk_ignore', array ($this, 'ignorePosts'));
      add_action ('admin_post_worthy_feedback', array ($this, 'doFeedback'));
      add_action ('admin_post_worthy_premium_signup', array ($this, 'premiumSignup'));
      add_action ('admin_post_worthy_premium_sync_status', array ($this, 'premiumSyncStatus'));
      add_action ('admin_post_worthy_premium_sync_markers', array ($this, 'premiumSyncMarkers'));
      add_action ('admin_post_worthy_premium_import', array ($this, 'premiumImportMarkers'));
      add_action ('admin_post_worthy_premium_create_webareas', array ($this, 'premiumCreateWebareas'));
      add_action ('admin_post_worthy_premium_report_posts_preview', array ($this, 'premiumReportPostsPreview'));
      add_action ('admin_post_worthy_premium_report_posts', array ($this, 'premiumReportPosts'));
      add_action ('admin_post_worthy_premium_select_server', array ($this, 'premiumDebugSetServer'));
      add_action ('admin_post_worthy_premium_drop_session', array ($this, 'premiumDebugDropSession'));
      add_action ('admin_post_worthy_premium_drop_registration', array ($this, 'premiumDebugDropRegistration'));
      add_action ('admin_post_wp-worthy-premium-purchase', array ($this, 'premiumPurchase'));
      add_action ('admin_post_-1', array ($this, 'redirectNoAction'));
      
      // Check for an action on posts-list
      if (isset ($_GET ['action']) && ($_GET ['action'] == 'wp-worthy-apply') && (intval ($_GET ['post_id']) > 0)) {
        $this->adminSavePost (intval ($_GET ['post_id']), true);
        
        unset ($_GET ['action'], $_GET ['post_id']);
      }
      
      // Setup database-schema
      $this->registerTable (
        $this->getTablename ('worthy_markers'),
        array (
          'id' => 'Int',
          'userid' => 'Int',
          'public' => 'String:32',
          'private' => 'String:32:null',
          'server' => 'String:32:null',
          'url' => 'String:64',
          'postid' => 'Int:unsigned:null',
          'disabled' => 'Int',
          'status' => 'Int:unsigned:null',
          'status_date' => 'Int:unsigned:null',
        ),
        array ('id'),
        array (array ('status', 'status_date'), array ('userid')),
        array (array ('public'), array ('private'), array ('postid')),
        1
      );
      
      // Perform some migration-checks
      $version = get_option ('worthy_version', 0);
      
      if ($version < 1) {
        $userID = $this->getUserID ();
        
        foreach (array ('worthy_markers_imported_csv', 'worthy_premium_markers_imported', 'worthy_premium_username', 'worthy_premium_password', 'worthy_premium_server', 'worthy_premium_status', 'worthy_premium_status_updated', 'worthy_premium_markers_updated', 'worthy_premium_marker_updates', 'worthy_premium_markers_updated') as $k)
          if ($v = get_option ($k, false))
            update_user_meta ($userID, $k, $v);
        
        foreach (array ('worthy_premium_username', 'worthy_premium_password', 'worthy_premium_server', 'worthy_premium_status', 'worthy_premium_status_updated', 'worthy_premium_markers_updated', 'worthy_premium_session') as $k)
          delete_option ($k);
        
        update_option ('worthy_version', 1);
      }
      if ($version < 2) {
        $GLOBALS ['wpdb']->query ('UPDATE `' . $this->getTablename ('worthy_markers') . '` SET postid=NULL WHERE postid IN (SELECT ID FROM `' . $this->getTablename ('posts') . '` WHERE post_type="revision")');
        update_option ('worthy_version', 2);
      }
    }
    // }}}
    
    // {{{ addContentMarker
    /**
     * Append VG-Wort pixel to output if neccessary
     * 
     * @param string $content
     * 
     * @access public
     * @return string
     **/
    public function addContentMarker ($content) {
      global $post, $more, $wpdb;
      
      // Check if there should be a pixel on the output
      if (!$more || !$post || !$post->ID || (get_post_meta ($postID, 'worthy_ignore', true) == 1))
        return $content;
      
      // Check if there is a pixel assigned
      if (!($marker = $wpdb->get_row ($wpdb->prepare ('SELECT id, public, url, server, userid FROM `' . $this->getTablename ('worthy_markers') . '` WHERE postid="%d" LIMIT 0,1', $post->ID))))
        return $content;
      
      // Check if the user disabled marker-output
      if (get_user_meta (($marker->userid ? $marker->userid : $post->post_author), 'wp-worthy-disable-output', true) == 1)
        return $content;
      
      // Check if there is a marker inside
      if (($Cleanup = $this->removeInlineMarkers ($content)) !== null) {
        add_post_meta ($postID, 'wp-worthy-duplicate', 1);
        
        $content = $Cleanup;
      } else
        delete_post_meta ($postID, 'wp-worthy-duplicate');
      
      // Find the right place for the marker
      if (($p = strpos ($content, '<span id="more-')) !== false) {
        $p = strpos ($content, '</span>', $p) + 7;
        
        // Check if the more-marker is embeded into a paragraph, if yes: skip this paragraph as it will mess up the template
        if ((($p2 = strpos ($content, '</p>', $p)) !== false) &&
            (($p3 = strpos ($content, '<p>', $p)) !== false) &&
            ($p2 < $p3))
          $p = $p2 + 4;
      } else
        $p = strrpos ($content, '</p>');
      
      // Generate URL
      if (($_SERVER ['HTTPS'] == 'on') && $marker->public)
        $url = 'https://ssl-vg03.met.vgwort.de/na/' . $marker->public;
      elseif ($marker->url)
        $url = $marker->url;
      elseif (!$marker->public) // WTF?!
        return $Content;
      elseif ($marker->server)
        $url = 'http://' . $marker->server . '/na/' . $marker->public;
      elseif ($uServer = get_user_meta ($post->post_author, 'wp-worthy-default-server', true))
        $url = 'http://' . $uServer . '/na/' . $marker->public;
      else // We should never ever get here, but...
        $url = 'http://vg0' . (($marker->id % 9) + 1) . '.met.vgwort.de/na/' . $marker->public;
      
      return
        substr ($content, 0, $p) .
        '<div id="worthy_pixel"><img src="' . esc_attr ($url) . '" height="1" width="1" /></div>' .
        substr ($content, $p);
    }
    // }}}
    
    // {{{ getRelevantUnassignedCount
    /**
     * Retrive the number of (indexed) posts that are relevant for worthy but do not have a marker assigned
     * 
     * @access private
     * @return int
     **/
    private function getRelevantUnassignedCount () {
      return $GLOBALS ['wpdb']->get_var (
        'SELECT count(*) FROM `' . $this->getTablename ('postmeta') . '` pm, `' . $this->getTablename ('posts') . '` p ' .
        'LEFT JOIN `' . $this->getTablename ('postmeta') . '` i ON (p.ID=i.post_id AND i.meta_key="worthy_ignore") ' .
        'WHERE p.post_type IN ("post", "page") AND p.post_status="publish" AND p.ID=pm.post_id AND pm.meta_key="worthy_counter" AND CONVERT(pm.meta_value, UNSIGNED INTEGER)>=' . $this::MIN_LENGTH . ' AND NOT (p.ID IN (SELECT postid FROM `' . $this->getTablename ('worthy_markers') . '` WHERE NOT (postid IS NULL))) AND ((i.meta_value IS NULL) OR NOT (i.meta_value="1"))'
      );
    }
    // }}}
    
    // {{{ getUnindexedCount
    /**
     * Retrive the number of not indexed posts
     * 
     * @access private
     * @return int
     **/
    private function getUnindexedCount () {
      return $GLOBALS ['wpdb']->get_var ('SELECT count(*), meta_value FROM `' . $this->getTablename ('posts') . '` p LEFT JOIN `' . $this->getTablename ('postmeta') . '` m ON (m.post_id=p.ID AND m.meta_key="worthy_counter") WHERE post_type IN ("post", "page") AND post_status="publish" HAVING meta_value IS NULL');
    }
    // }}}
    
    // {{{ getAvailableMarkersCount
    /**
     * Retrive number of available markers
     * 
     * @param int $userID (optional)
     * 
     * @access private
     * @return int
     **/
    private function getAvailableMarkersCount ($userID = null) {
      return $GLOBALS ['wpdb']->get_var ('SELECT count(*) FROM `' . $this->getTablename ('worthy_markers') . '` WHERE postid IS NULL AND userid IN ("' . $this->getUserID ($userID) . '","0")');
    }
    // }}}
    
    // {{{ linkSection
    /**
     * Generate a link to admin-section of this plugin
     * 
     * @param enum $Section
     * @param array $Parameters (optional)
     * @param bool $Post (optional)
     * 
     * @access public
     * @return string
     **/
    public function linkSection ($Section, $Parameters = null, $Post = false) {
      // Generate the Base-URL for the section
      $URL = menu_page_url (__CLASS__ . '-' . $Section, false);
      
      if (strlen ($URL) == 0)
        $URL = admin_url ('admin.php?page=' . urlencode (__CLASS__ . '-' . $Section));
      
      // Check wheter to use admin-post.php instead of admin.php
      if ($Post)
        $URL = str_replace ('/admin.php?', '/admin-post.php?', $URL);
      
      // Append parameters
      if (is_array ($Parameters))
        $URL = add_query_arg ($Parameters, $URL);
      
      // Return the URL
      return $URL;
    }
    // }}}
    
    // {{{ inlineAction
    /**
     * Embed a form as simple link to trigger some changing-actions
     * 
     * @param string $Section
     * @param string $Action
     * @param string $Caption
     * @param array $Parameter (optional)
     * 
     * @access public
     * @return void
     **/
    public function inlineAction ($Section, $Action, $Caption, $Parameter = array ()) {
      if (is_array ($Parameter)) {
        $buf = '';
        
        foreach ($Parameter as $Key=>$Value)
          $buf .= '<input type="hidden" name="' . esc_attr ($Key) . '" value="' . esc_attr ($Value) . '" />';
        
        $Parameter = $buf;
      } else
        $Parameter = '';
      
      return
        '<form class="worthy_inline" method="post" action="' . $this->linkSection ($Section, null, true) . '">' .
          $Parameter .
          '<button type="submit" name="action" value="' . esc_attr ($Action) . '">' . $Caption . '</button>' .
        '</form>';
    }
    // }}}
    
    // {{{ inlineActions
    /**
     * Embed a form as simple link to trigger some changing-actions
     * 
     * @param string $Section
     * @param array $Actions
     * @param array $Parameter (optional)
     * 
     * @access public
     * @return void  
     **/
    public function inlineActions ($Section, $Actions, $Parameter = array ()) {
      $buf = '<form class="worthy_inline" method="post" action="' . $this->linkSection ($Section, null, true) . '">';
      
      if (is_array ($Parameter))
        foreach ($Parameter as $Key=>$Value)
          $buf .= '<input type="hidden" name="' . esc_attr ($Key) . '" value="' . esc_attr ($Value) . '" />';
      
      foreach ($Actions as $Action=>$Caption)
        $buf .= '<button type="submit" name="action" value="' . esc_attr ($Action) . '">' . $Caption . '</button><br />';
      
      return
          $buf .
        '</form>';
    }
    // }}}
    
    // {{{ getUserID
    /**
     * Retrive the ID of the current user we work for
     * 
     * @param int $UserID (optional)
     * 
     * @access public
     * @return int
     **/
    public function getUserID ($UserID = null) {
      if ($UserID === true)
        return intval (get_current_user_id ());
      
      if ($userID === null)
        $userID = intval (get_current_user_id ());
      
      while (($oID = intval (get_user_meta ($userID, 'wp-worthy-authorid', true))) > 0)
        $userID = $oID;
      
      return $userID;
    }
    // }}}
    
    // {{{ getUserIDforPost
    /**
     * Retrive a user-id based on a given post
     * 
     * @param mixed $Post
     * 
     * @access public
     * @return int
     **/
    public function getUserIDforPost ($Post) {
      if (!is_object ($Post))
        $Post = get_post ($Post);
      
      return $this->getUserID (($Post && isset ($Post->post_author) ? $Post->post_author : null));
    }
    // }}}
    
    // {{{ dashboardContent
    /**
     * Output some values on the dashbord "at a glance"-Section
     * 
     * @access public
     * @return void
     **/
    public function dashboardContent () {
      // Check if there are relevant posts without a marker assigned
      if (($c = $this->getRelevantUnassignedCount ()) > 0)
        echo '<li><a href="', $this->linkSection ($this::ADMIN_SECTION_POSTS, array ('worthy-filter-marker' => 0, 'worthy-filter-length' => 1)), '">', sprintf (__ ('%d relevant for VG-Wort', $this->textDomain), $c), '</a></li>';
      
      foreach ($GLOBALS ['wpdb']->get_results ('SELECT userid, count(*) As count FROM `' . $this->getTablename ('worthy_markers') . '` WHERE postid IS NULL AND userid IN (0, ' . $this->getUserID () . ') GROUP BY userid') as $UserMarkers)
        if ($UserMarkers->userid == 0)
          echo '<li><a href="', $this->linkSection ($this::ADMIN_SECTION_MARKERS, array ('orderby' => 'postid', 'order' => 'asc')), '">', sprintf (__ ('%d unused general markers', $this->textDomain), $UserMarkers->count), '</a></li>';
        else
          echo '<li><a href="', $this->linkSection ($this::ADMIN_SECTION_MARKERS, array ('orderby' => 'postid', 'order' => 'asc')), '">', sprintf (__ ('%d unused markers', $this->textDomain), $UserMarkers->count), '</a></li>';
    }
    // }}}
    
    // {{{ adminPostColumnHeaders
    /**
     * Append custom column-headers to post/pages-table
     * 
     * @param array $defaults
     * 
     * @access public
     * @return array
     **/
    public function adminPostColumnHeaders ($defaults) {
      $defaults ['worthy'] = __ ('Relevant Characters', $this->textDomain);
       
      return $defaults;
    }
    // }}}
    
    // {{{ getPostLength
    /**
     * Retrive the relevant length of a given post
     * 
     * @param mixed $post
     * 
     * @access public
     * @return strlen
     **/
    public function getPostLength ($post) {
      // Count characters on post
      $content = trim (str_replace (array ("\r", "\n"), array (' ', ' '), strip_tags (html_entity_decode (is_object ($post) ? $post->post_content : $post, ENT_COMPAT, 'UTF-8'))));
      
      while (strpos ($content, '  ') !== false)
        $content = str_replace ('  ', ' ', $content);
      
      return strlen ($content);
    }
    // }}}
    
    // {{{ postHasMarker
    /**
     * Check if a given post has a marker assigned
     * 
     * @param mixed $post
     * 
     * @access public
     * @return bool
     **/
    public function postHasMarker ($post) {
      global $wpdb;
      
      if (is_object ($post))
        $post = $post->ID;
      
      return ($wpdb->get_var ($wpdb->prepare ('SELECT count(*) FROM `' . $this->getTablename ('worthy_markers') . '` WHERE postid="%d"', $post)) > 0);
    }
    // }}}
    
    // {{{ adminPostColumns
    /**
     * Generate output on post/pages-table
     * 
     * @param string $column
     * @param int $postID
     * 
     * @access public
     * @return void
     **/
    public function adminPostColumns ($column, $postID) {
      global $post, $wpdb;
      
      // Check if the post is ignored
      if (get_post_meta ($postID, 'worthy_ignore', true) == 1) {
        echo '<span class="worthy-ignored">', __ ('Ignored', $this->textDomain), '</span>';
        
        return;
      }
      
      // Check if our column is requested
      if (!$post || ($column != 'worthy'))
        return;
      
      // Retrive the length of the post
      $length = $this->getPostLength ($post);
      
      // Determine the class
      $Class = 'worthy-neutral';
      
      if ($length >= $this::MIN_LENGTH) {
        $haveMarker = $this->postHasMarker ($post);
        $Class = 'worthy-relevant';
        
        if ($haveMarker)
          $Class .= ' worthy-marker';
        else
          $Class .= ' worthy-nomarker';
      } else
        $haveMarker = true;
      
      // Do the output
      $url = esc_html ($_SERVER ['REQUEST_URI']);
      
      if (strpos ($url, '?') === false)
        $url .= '?action=wp-worthy-apply&post_id=';
      else
        $url .= '&action=wp-worthy-apply&post_id=';
      
      echo
        '<span class="', $Class, '">', sprintf (__ ('%d chars', $this->textDomain), $length), '</span>';
      
      if (!$haveMarker)
        echo '<br /><a href="', $url, intval ($post->ID), '">', __ ('Assign marker', $this->textDomain), '</a>';
    }
    // }}}
    
    // {{{ adminAddPostBanner
    /**
     * Add some notices to wordpress' post editor
     * 
     * @param WP_Post $post (optional)
     * 
     * @access public
     * @return void
     **/
    public function adminAddPostBanner ($post = null) {
      // Just output notice-section if no post is used for this call
      if (!$post) {
        echo '<div id="worthy-notices"></div>';
        
        return;
      }
      
      // Check if the post has a marker assigned
      if ($this->postHasMarker ($post->ID))
        return;
      
      // Check if the post is ignored
      if (get_post_meta ($post->ID, 'worthy_ignore', true) == 1)
        return;
      
      // Retrive some information first
      $isLyric = (get_post_meta ($post->ID, 'worthy_lyric', true) == 1);
      $noMarkers = ($this->getAvailableMarkersCount ($this->getUserIDforPost ($post)) == 0);
      $Length = $this->getPostLength ($post->post_content);
      
      // Check wheter to output a notice
      if (isset ($_REQUEST ['wp-worthy-assign-status']) && ($_REQUEST ['wp-worthy-assign-status'] == 1))
        $this->adminNotice (__ ('Worthy was not able to assign a marker to this post because there are no free markers left on the database.', $this->textDomain), 'error');
      elseif (isset ($_REQUEST ['wp-worthy-assign-status']) && ($_REQUEST ['wp-worthy-assign-status'] == 2))
        $this->adminNotice (__ ('Worthy was not able to assign a marker to this post because of an unknown internal error. Please contact developers.', $this->textDomain), 'error');
      elseif (($isLyric || ($Length >= $this::WARN_LIMIT)) && $noMarkers)
        $this->adminNotice (__ ('Worthy will not be able to assign a marker to this post because there are no free markers left on the database.', $this->textDomain), 'error');
      elseif ($isLyric)
        $this->adminNotice (__ ('This article is flagged as lyric work but you did not assign a marker. The lyric-flag only makes sense if you want to assign a marker to a short text.', $this->textDomain), 'error');
      elseif ($Length >= $this::MIN_LENGTH)
        $this->adminNotice (sprintf (__ ('Your article is more than %d characters long but you did not assign a marker. It is advisable to assign a marker now or to ignore it for use with worthy.', $this->textDomain), $this::MIN_LENGTH), 'error');
      elseif (($Length < $this::MIN_LENGTH) && ($Length >= $this::WARN_LIMIT))
        $this->adminNotice (sprintf (__ ('Your article is close to %d characters long and though may qualify to be reported to VG-Wort if you write some more words.', $this->textDomain), $this::MIN_LENGTH), 'update-nag');
    }
    // }}}
    
    // {{{ adminNotice
    /**
     * Enqueue an admin-notice
     * 
     * @param 
     * @access private
     * @return void
     **/
    private function adminNotice ($Message, $Class) {
      echo '<script type="text/javascript"> worthy.postNotice ("', esc_html ($Message), '", "', esc_html ($Class), '"); </script>';
    }
    // }}}
    
    // {{{ adminSavePost
    /**
     * Assign a marker to a post if requested
     * 
     * @access public
     * @return bool
     **/
    public function adminSavePost ($postID, $force = false) {
      // Just ignore revisions
      if (wp_is_post_revision ($postID))
        return;
      
      // Store the length of the post
      if (isset ($_REQUEST ['content']))
        update_post_meta ($postID, 'worthy_counter', $this->getPostLength ($_REQUEST ['content']));
      
      if (!$force) {
        // Toggle ignore-flag
        if (isset ($_POST ['worthy_ignore'])) {
          update_post_meta ($postID, 'worthy_ignore', 1);
          
          unset ($_POST ['worthy_embed']);
        } else
          delete_post_meta ($postID, 'worthy_ignore', 1);
        
        // Toggle lyric-flag
        if (isset ($_POST ['worthy_lyric']))
          update_post_meta ($postID, 'worthy_lyric', 1);
          
        else
          delete_post_meta ($postID, 'worthy_lyric', 1);
      }
      
      // Check wheter to assign a marker
      $hasMarker = $this->postHasMarker ($postID);
      
      if ($hasMarker || (!$force && (!isset ($_POST ['worthy_embed']) || ($_POST ['worthy_embed'] != 1))))
        return $hasMarker;
      
      // Assign a random marker to this post
      global $wpdb;
      
      foreach (array ($this->getUserIDforPost ($postID), 0) as $uid)
        if ($wpdb->query ($wpdb->prepare (
          'UPDATE IGNORE `' . $this->getTablename ('worthy_markers') . '` ' .
          'SET postid=%d WHERE postid IS NULL AND userid=%d LIMIT 1',
          $postID, $uid
        )) == 1)
          break;
      
      // Check if a new marker was assigned
      if (!($success = ($wpdb->rows_affected == 1)))
        add_filter ('redirect_post_location', array ($this, 'adminSavePostError'), 10, 2);
      
      return $success;
    }
    // }}}
    
    // {{{ adminSavePostError
    /**
     * Hook into redirect-url if a marker could not be assigned
     * 
     * @param string $location
     * 
     * @access public
     * @return string
     **/
    public function adminSavePostError ($location, $postID) {
      return add_query_arg ('wp-worthy-assign-status', ($this->getAvailableMarkersCount ($this->getUserIDforPost ($postID)) == 0 ? 1 : 2), $location);
    }
    // }}}
    
    // {{{ adminPostPublishBox
    /**
     * Place our options on publish-box
     * 
     * @access public
     * @return void
     **/
    public function adminPostPublishBox () {
      global $post, $wpdb;
      
      // Check our premium-subscribtion
      $isPremium = $this->isPremium ();
      
      // Check current settings
      if ($enabled = ($post && ($post->ID > 0))) {
        $c_checked = ($wpdb->get_var ($wpdb->prepare ('SELECT count(*) FROM `' . $this->getTablename ('worthy_markers') . '` WHERE postid="%d"', $post->ID)) > 0);
        $l_checked = (get_post_meta ($post->ID, 'worthy_lyric', true) == 1);
        $i_checked = (get_post_meta ($post->ID, 'worthy_ignore', true) == 1);
      } else {
        $c_checked = false;
        $l_checked = false;
        $i_checked = false;
      }
      
      // Make sure there are markers available
      if (!$c_checked)
        $enabled = ($this->getAvailableMarkersCount ($this->getUserIDforPost ($post)) > 0);
      
      // Append our options to the publish-box
      echo
        '<div class="misc-pub-section misc-worthy worthy-publish">',
          '<span class="label">Worthy:</span>',
          '<span class="value">',
          ($enabled ? '' :
            '<span class="worthy-warning">' . __ ('No markers available', $this->textDomain) . '</span>'),
            '<input type="checkbox" name="worthy_embed" id="worthy_embed" data-wp-worthy-auto="', get_user_meta (get_current_user_id (), 'wp-worthy-auto-assign-markers', true), '" value="1"', ($c_checked ? ' checked="1"' : ''), ($c_checked || !$enabled ? ' readonly disabled' : ''), ' /> ',
            '<label for="worthy_embed" id="worthy_embed_label">', __ ('Assign VG-Wort marker', $this->textDomain), '</label><br />',
          ($isPremium ?
            '<input onclick="worthy.counter (false);" type="checkbox" name="worthy_lyric" id="worthy_lyric" value="1"' . ($l_checked ? ' checked="1"' : '') . ' /> ' .
            '<label for="worthy_lyric">' . __ ('Lyric Work', $this->textDomain) . '</label><br />'
          : ''),
            '<input onclick="worthy.counter (false);" type="checkbox" name="worthy_ignore" id="worthy_ignore" value="1"', ($i_checked ? ' checked="1"' : ''), '/> ',
            '<label for="worthy_ignore" id="worthy_ignore_label">', __ ('Ignore this article', $this->textDomain), '</label>',
          '</span>',
          '<div class="clear"></div>',
        '</div>';
    }
    // }}}
    
    // {{{ adminMenuHeader
    /**
     * Output HTML-code for admin-menu
     * 
     * @param enum $Current (optional)
     * 
     * @access private
     * @return void
     **/
    private function adminMenuHeader ($Current = null) {
      // Output the header of the administration-menu
      $Sections = array (
        $this::ADMIN_SECTION_OVERVIEW => 'Overview',
        $this::ADMIN_SECTION_MARKERS => 'Markers',
        $this::ADMIN_SECTION_POSTS => 'Posts',
        $this::ADMIN_SECTION_CONVERT => 'Import / Export',
        $this::ADMIN_SECTION_SETTINGS => 'Settings',
        $this::ADMIN_SECTION_PREMIUM => array ('Premium', 1, 'worthy-premium'),
      );
      
      echo
        '<div id="wp-worthy" class="wrap">',
          '<h1>', __ ('Worthy - VG-Wort Integration for Wordpress', $this->textDomain), '</h1>',
          '<h2 class="nav-tab-wrapper">';
      
      foreach ($Sections as $Key=>$Title) {
        if (is_array ($Title)) {
          $Class = (isset ($Title [2]) ? $Title [2] : null);
          $Align = (isset ($Title [1]) ? $Title [1] : 0);
          $Title = $Title [0];
        } else {
          $Align = 0;
          $Class = null;
        }
        
        echo '<a href="', $this->linkSection ($Key), '" class="nav-tab', ($Key == $Current ? ' nav-tab-active' : ''), ($Align == 1 ? ' nav-tab-right' : ''), ($Class !== null ? ' ' . $Class : '') . '">', __ ($Title, $this->textDomain), '</a>';
      }
      
      echo
          '<div class="clear"></div></h2>';
      
      echo
          '<div id="poststuff">';
      
      // Output status-messages first
      $this->adminMenuStatus ();
    }
    // }}}
    
    // {{{ adminMenuFooter
    /**
     * 
     **/
    private function adminMenuFooter () {
      // Finish the output
      echo
          '</div>',
        '</div>';
    }
    // }}}
    
    // {{{ adminMenuOverview
    /**
     * Generate an overview about our status
     * 
     * @access public
     * @return void
     **/
    public function adminMenuOverview () {
      // Draw admin-Header
      $this->adminMenuHeader ($this::ADMIN_SECTION_OVERVIEW);
      
      global $wpdb;
      
      // Collect some status-information
      $notIndexed = $this->getUnindexedCount ();
      $unassigedRelevant = $this->getRelevantUnassignedCount ();
      $invalidAssigned = $wpdb->get_var ('SELECT count(*) FROM `' . $this->getTablename ('worthy_markers') . '` WHERE NOT (postid IS NULL) AND NOT postid IN (SELECT ID FROM `' . $this->getTablename ('posts') . '` WHERE  post_type IN ("post", "page") AND post_status="publish")');
      
      // Start the output
      echo
        '<div class="stuffbox">',
          '<h2>', __ ('Status', $this->textDomain), '</h2>',
          '<div class="inside">',
            '<h3>', __ ('Markers', $this->textDomain), '</h3>',
            '<ul id="worthy-marker-status">';
      
      // Output marker-summaries
      $userID = $this->getUserID ();
      $Users = array ();
      $unused = 0;
      $used = 0;
      
      foreach ($wpdb->get_results ('SELECT userid, IF(postid IS NULL, 0, 1) AS post, count(*) AS count FROM `' . $this->getTablename ('worthy_markers') . '` GROUP BY userid, post ORDER BY userid ASC, post ASC') as $MarkerInfo) {
        if (!isset ($Users [$MarkerInfo->userid]))
          $Users [$MarkerInfo->userid] = array ('unused' => 0, 'used' => 0);
        
        $Users [$MarkerInfo->userid][($MarkerInfo->post == 0 ? 'unused' : 'used')] = $MarkerInfo->count;
      }
      
      foreach ($Users as $userid=>$Info) {
        // Increase counters
        $unused += $Info ['unused'];
        $used += $Info ['used'];
        
        // Check if there are more than one user on the output
        if (($c = count ($Users)) > 1) {
          echo '<li><strong>';
          
          if ($userid != 0) {
            if ($u = get_userdata ($userid))
              echo sprintf (__ ('Markers for %s', $this->textDomain), $u->display_name) . ':';
            else
              echo __ ('Markers for an unknown user', $this->textDomain), ':';
          } else
            echo __ ('Not personalized markers', $this->textDomain), ':';
            
          echo '</strong> <small>(<a href="' . $this->linkSection ($this::ADMIN_SECTION_MARKERS, array ('worthy-filter-author' => intval ($userid))), '">', __ ('Show only these', $this->textDomain), '</a>)</small><ul>';
        }
        
        echo
              '<li>',
                sprintf (__ ('<strong>%d unused markers</strong> on database', $this->textDomain), $Info ['unused']), ' ',
            ($userID != $userid ? '' :
                '<small>(<a href="' . $this->linkSection ($this::ADMIN_SECTION_CONVERT) . '">' . __ ('Import new markers', $this->textDomain) . '</a>)</small>'),
              '</li><li>',
                sprintf (__ ('<strong>%d used markers</strong> on database', $this->textDomain), $Info ['used']),
              '</li>',
          ($c > 1 ? '</ul></li>' : '');
      }
      
      // Check if there are some markers wasted on non-existant posts
      if ($invalidAssigned > 0)
        echo
              '<li>' . sprintf (__ ('<strong>%d markers</strong> of them are assigned to non-existant posts', $this->textDomain), $invalidAssigned) . '</li>';
      
      echo
              '<li>', sprintf (__ ('<strong>%d markers</strong> total on database', $this->textDomain), $unused + $used), '</li>';
      
      // Check if there are markers for the current user
      if (!isset ($Users [$userID]))
        echo '<li><a href="' . $this->linkSection ($this::ADMIN_SECTION_CONVERT) . '">', __ ('Import new markers', $this->textDomain), '</a></li>';
      
      echo
            '</ul>',
            '<h3>', __ ('Posts', $this->textDomain), '</h3>',
            '<ul>',
              '<li>', 
                '<strong>', sprintf (__ ('%d posts', $this->textDomain), $unassigedRelevant), '</strong> ', __ ('on index that are qualified but do not have a marker assigned', $this->textDomain),
                ($unassigedRelevant > 0 ? ' <small>(<a href="' . $this->linkSection ($this::ADMIN_SECTION_POSTS, array ('worthy-filter-marker' => 0, 'worthy-filter-length' => 1)) . '">' . __ ('Find them', $this->textDomain) . '</a>)</small>' : ''),
              '</li>',
              '<li>',
                '<strong>', sprintf (__ ('%d posts', $this->textDomain), $notIndexed), '</strong> ', __ ('do not have a length-index for Worthy stored', $this->textDomain),
                ($notIndexed > 0 ? ' <small>(' . $this->inlineAction ($this::ADMIN_SECTION_SETTINGS, 'worthy_reindex', __ ('Generate length-index', $this->textDomain)) . ')</small>' : ''),
              '</li>';
      
      if (($c = $wpdb->get_var ('SELECT count(*) FROM `' . $this->getTablename ('post_meta') . '` WHERE meta_key="worthy_duplicate"')) > 0)
        echo  '<li><strong>', sprintf (__ ('%d posts', $this->textDomain), $c), '</strong> ', __ ('were found on frontend with at least two markers assigned!', $this->textDomain), '</li>';
      
      echo
            '</ul>',
          '</div>',
        '</div>';
      
      // Check wheter to suggest migration
      $inline = $this->migrateInline (false, true);
      $vgw = $this->migrateByMeta (array ('vgwpixel'), false, true);
      $wpvg = $this->migrateByMeta (array (get_option ('wp_vgwortmetaname', 'wp_vgwortmarke')), false, true);
      $wppvgw = $this->migrateProsodia (false, true);
      $tlvgw = $this->migrateTlVGWort (false, true);
      
      if ((count ($inline) > 0) || (count ($vgw) > 0) || (count ($wpvg) > 0) || (count ($wppvgw) > 0) || (count ($tlvgw) > 0)) {
        // Output summary to to-migrate-posts
        echo
          '<div class="stuffbox">',
            '<h2>', __ ('Migration', $this->textDomain), '</h2>',
            '<div class="inside">',
              '<ul>',
                '<li><strong>', sprintf (__ ('%d posts', $this->textDomain), count ($inline)), '</strong> ', __ ('are using inline markers', $this->textDomain), '</li>',
                '<li><strong>', sprintf (__ ('%d posts', $this->textDomain), count ($vgw)), '</strong> ', __ ('are using markers from VGW (VG-Wort Krimskram)', $this->textDomain), '</li>',
                '<li><strong>', sprintf (__ ('%d posts', $this->textDomain), count ($wpvg)), '</strong> ', __ ('are using markers from WP VG-Wort', $this->textDomain), '</li>',
                '<li><strong>', sprintf (__ ('%d posts', $this->textDomain), count ($wppvgw)), '</strong> ', __ ('are using markers from Prosodia VGW', $this->textDomain), '</li>',
                '<li><strong>', sprintf (__ ('%d posts', $this->textDomain), count ($tlvgw)), '</strong> ',  __ ('are using markers from Torben Leuschners VG-Wort', $this->textDomain), '</li>',
              '</ul>';
        
        // Sanity-check all IDs
        $ids = array ();
        
        foreach (array ($inline, $vgw, $wpvg, $wppvgw, $tlvgw) as $k)
          foreach ($k as $i)
            $ids [intval ($i)] = intval ($i);
        
        if (count ($ids) != (count ($inline) + count ($vgw) + count ($wpvg) + count ($wppvgw) + count ($tlvgw)))
          echo '<p><strong>', __ ('Attention', $this->textDomain), ':</strong> ', __ ('Some of this posts seem to have assigned markers using more than one plugin!', $this->textDomain), '</p>';
        
        if (($c = $wpdb->get_var ('SELECT count(*) FROM `' . $this->getTablename ('worthy_markers') . '` WHERE postid IN (' . implode (',', $ids) . ')')) > 0)
          echo '<p><strong>', __ ('Attention', $this->textDomain), ':</strong> ', sprintf (__ ('%d of this posts are already managed by Worthy!', $this->textDomain), $c), '</p>';
        
        echo
              '<p><a href="', $this->linkSection ($this::ADMIN_SECTION_CONVERT), '">', __ ('Go to Import / Export to migrate those posts to Worthy', $this->textDomain), '</a></p>',
            '</div>',
          '</div>';
      }
      
      $this->adminMenuFooter ();
    }
    // }}}
    
    // {{{ adminMenuMarkers
    /**
     * Display a summary of all VG-Wort markers
     * 
     * @access public
     * @return void
     **/
    public function adminMenuMarkers () {
      // Draw admin-header
      $this->adminMenuHeader ($this::ADMIN_SECTION_MARKERS);
      
      // Make sure our premium-status is registered / known
      $this->isPremium ();
      
      // Create a table-widget
      $Table = new wp_worthy_table_markers ($this);
      $Table->prepare_items ();
      
      // Display the table-widget
      echo
        '<form method="post" action="', $this->linkSection ($this::ADMIN_SECTION_MARKERS, null, true), '">',
          '<input type="hidden" name="action" value="-1" />',
          '<script type="text/javascript">',
            'function worthy_bulk_single (action, postid) {',
              'e=document.getElementsByName("post[]");',
              'for (i=0;i<e.length;i++) e [i].checked=(e [i].value==postid);',
              'e=document.getElementsByName("action");',
              'for (i=0;i<e.length;i++) if (e [i].localName=="select") for (j=0;j<e [i].options.length;j++) if (e [i].options [j].value == action) { e [i].selectedIndex = j; break; }',
              'if (e [0] && e [0].form) e [0].form.submit ();',
            '}',
          '</script>',
          (isset ($_REQUEST ['displayMarkers']) ? '<input type="hidden" name="displayMarkers" value="' . esc_attr ($_REQUEST ['displayMarkers']) . '" />' : ''),
          (isset ($_REQUEST ['orderby']) ? '<input type="hidden" name="orderby" value="' . esc_attr ($_REQUEST ['orderby']) . '" />' : ''),
          (isset ($_REQUEST ['order']) ? '<input type="hidden" name="order" value="' . esc_attr ($_REQUEST ['order']) . '" />' : ''),
          $Table->search_box (__ ('Search Marker', $this->textDomain), 'wp-worthy-search'),
          $Table->display (),
        '</form>';
      
      // Output Marker-Inquiry
      echo
        '<div class="stuffbox">',
          '<h2>', __ ('Private Marker Inquiry', $this->textDomain), '</h2>',
          '<div class="inside">',
            '<form method="post" enctype="multipart/form-data" action="', $this->linkSection ($this::ADMIN_SECTION_MARKERS, null, true), '">',
              '<p>',
                __ ('The private-marker-inquiry will display a list of markers managed by Worthy (including post-assignment) from a CSV-List.', $this->textDomain), '<br />',
                __ ('You may upload a CSV-file like the one you can download from the marker-inquiry at T.O.M..', $this->textDomain),
              '</p><p>',
                __ ('CSV-File containing private markers', $this->textDomain), ':<br />',
                '<input type="file" name="worthy_marker_file" accept="text/csv" />',
              '</p>',
              '<p><button type="submit" name="action" value="wp-worthy-marker-inquiry" class="button action">', __ ('Search private markers', $this->textDomain), '</button></p>',
            '</form>',
          '</div>',
        '</div>';
      
      // Finish admin-page
      $this->adminMenuFooter ();
    }
    // }}}
    
    // {{{ adminMenuMarkersPrepare
    /**
     * Prepare to display markers-table
     * 
     * @access public
     * @return void
     **/
    public function adminMenuMarkersPrepare () {
      // Register our current premium-status
      $this->isPremium ();
      
      // Setup the table
      wp_worthy_table_markers::setupOptions ();
      
      if ($current_screen = get_current_screen ())
        add_filter ('manage_' . $current_screen->id . '_columns', array ('wp_worthy_table_markers', 'setupColumns'));
      
      // Do some more commen stuff
      $this->adminMenuPrepare ();
    }
    // }}}
    
    // {{{ adminMenuPosts
    /**
     * Display all posts and their markers
     * This is just like wordpress' own post-table but with focus on markers
     * 
     * @access public
     * @return void
     **/
    public function adminMenuPosts () {
      // Draw admin-header
      $this->adminMenuHeader ($this::ADMIN_SECTION_POSTS);
      
      // Make sure our premium-status is registered / known
      $Premium = $this->isPremium ();
      
      // Prepare the table
      $Table = new wp_worthy_table_posts ($this);
      $Table->prepare_items ();
      
      // Check if we are running low on markers
      global $wpdb;
      
      $perPage = $Table->get_items_per_page ('wp_worthy_posts_per_page');
      $freeMarkers = $this->getAvailableMarkersCount ();
      
      if ($freeMarkers == 0)
        echo
          '<div class="stuffbox">',
            '<h2>', __ ('No more markers on the database available', $this->textDomain), '</h2>',
            '<div class="inside">',
              '<p>',
                '<strong>', __ ('There are no more markers available!', $this->textDomain), '</strong><br />',
                __ ('There are no markers left on the Worthy Database.', $this->textDomain), '<br />',
                __ ('It is not possible to assign a new marker to a post or page until you import a new set of markers ' . ($Premium ? 'via Worthy Premium or ' : '') . 'from a csv file.', $this->textDomain),
              '</p>',
              '<p><a href="', $this->linkSection ($this::ADMIN_SECTION_CONVERT), '">', __ ('Import new markers', $this->textDomain), '</a></p>',
            '</div>',
          '</div>';
      
      elseif ($freeMarkers < $perPage)
        echo
          '<div class="stuffbox">',
            '<h2>', __ ('Low amount of unused markers left', $this->textDomain), '</h2>',
            '<div class="inside">',
              '<p>',
                '<strong>', __ ('Worthy is running low on markers!', $this->textDomain), '</strong><br />',
                sprintf (__ ('If you are going to assign more than %d markers to posts without a marker assigned, some of them will fail until you import new markers ' . ($Premium ? 'via Worthy Premium or ' : '') . 'from a csv file into the Worthy database.', $this->textDomain), $freeMarkers),
              '</p>',
              '<p><a href="', $this->linkSection ($this::ADMIN_SECTION_CONVERT), '">', __ ('Import new markers', $this->textDomain), '</a></p>',
            '</div>',
          '</div>';
      
      // Display the table
      echo
        '<form method="post" action="', $this->linkSection ($this::ADMIN_SECTION_POSTS, null, true), '">',
          '<script type="text/javascript">',
            'function worthy_bulk_single (action, postid) {',
              'e=document.getElementsByName("post[]");',
              'for (i=0;i<e.length;i++) e [i].checked=(e [i].value==postid);',
              'e=document.getElementsByName("action");',
              'for (i=0;i<e.length;i++) if (e [i].localName=="select") for (j=0;j<e [i].options.length;j++) if (e [i].options [j].value == action) { e [i].selectedIndex = j; break; }',
              'if (e [0] && e [0].form) e [0].form.submit ();',
            '}',
          '</script>',
          (isset ($_REQUEST ['displayMarkersForMigration']) ? '<input type="hidden" name="displayMarkers" value="' . esc_attr ($_REQUEST ['displayMarkersForMigration']) . '" />' : ''),
          (isset ($_REQUEST ['migrate_inline']) ? '<input type="hidden" name="migrate_inline" value="' . esc_attr ($_REQUEST ['migrate_inline']) . '" />' : ''),
          (isset ($_REQUEST ['migrate_vgw']) ? '<input type="hidden" name="migrate_vgw" value="' . esc_attr ($_REQUEST ['migrate_vgw']) . '" />' : ''),
          (isset ($_REQUEST ['migrate_vgwort']) ? '<input type="hidden" name="migrate_vgwort" value="' . esc_attr ($_REQUEST ['migrate_vgwort']) . '" />' : ''),
          (isset ($_REQUEST ['migrate_wppvgw']) ? '<input type="hidden" name="migrate_wppvgw" value="' . esc_attr ($_REQUEST ['migrate_wppvgw']) . '" />' : ''),
          (isset ($_REQUEST ['migrate_tlvgw']) ? '<input type="hidden" name="migrate_tlvgw" value="' . esc_attr ($_REQUEST ['migrate_tlvgw']) . '" />' : ''),
          (isset ($_REQUEST ['migrate_repair_dups']) ? '<input type="hidden" name="migrate_repair_dups" value="' . esc_attr ($_REQUEST ['migrate_repair_dups']) . '" />' : ''),
          (isset ($_REQUEST ['orderby']) ? '<input type="hidden" name="orderby" value="' . esc_attr ($_REQUEST ['orderby']) . '" />' : ''),
          (isset ($_REQUEST ['order']) ? '<input type="hidden" name="order" value="' . esc_attr ($_REQUEST ['order']) . '" />' : ''),
          $Table->search_box (__ ('Search Marker', $this->textDomain), 'wp-worthy-search'),
          $Table->display (),
        '</form>';
      
      $this->adminMenuFooter ();
    }
    // }}}
    
    // {{{ adminMenuPostsPrepare
    /**
     * Prepare to display the posts-table
     * 
     * @access public
     * @return void
     **/
    public function adminMenuPostsPrepare () {
      // Register premium-status
      $this->isPremium ();
      
      // Setup the posts-table
      wp_worthy_table_posts::setupOptions ();
      
      if ($current_screen = get_current_screen ())
        add_filter ('manage_' . $current_screen->id . '_columns', array ('wp_worthy_table_posts', 'setupColumns'));
      
      // Do more common stuff
      $this->adminMenuPrepare ();
    }
    // }}}
    
    // {{{ adminMenuConvert
    /**
     * Output HTML-code for convert-section on admin-menu
     * 
     * @access public
     * @return void
     **/
    public function adminMenuConvert () {
      global $wpdb;
      
      // Draw admin-header
      $this->adminMenuHeader ($this::ADMIN_SECTION_CONVERT);
      
      // Check if we are subscribed to premium
      $isPremium = $this->isPremium ();
      
      // Output the dialog
      echo
        '<div class="stuffbox">',
          '<h2>', __ ('Import VG-Wort markers', $this->textDomain), '</h2>',
          '<div class="inside">';
      
      if ($isPremium)
        echo
            '<div class="wp-worthy-menu-half">',
              '<form method="post" enctype="multipart/form-data" action="', $this->linkSection ($this::ADMIN_SECTION_CONVERT, null, true), '">',
                '<p>', __ ('By using Worthy Premium you may directly import markers without the need to download them manually from VG-Wort.', $this->textDomain), '</p>',
                '<p>', __ ('Number of markers to import (at most 100)', $this->textDomain), '</p>',
                '<p><input type="number" name="count" id="count" min="1" max="100" step="1" value="10" /></p>',
                '<p><button type="submit" class="button action button-primary" name="action" value="worthy_premium_import">', __ ('Import via Worthy Premium', $this->textDomain), '</button></p>',
              '</form>',
            '</div>',
            '<div class="wp-worthy-menu-half">';
      
      echo
              '<form method="post" enctype="multipart/form-data" action="', $this->linkSection ($this::ADMIN_SECTION_CONVERT, null, true), '">',
                '<p>', __ ('If you have requested a CSV-list of markers via VG-Wort you may upload this file and import contained markers here.', $this->textDomain), '</p>',
                ($isPremium ? '<p>&nbsp;</p>' : ''),
                '<p><input type="file" name="csvfile" /></p>',
                '<p><button type="submit" class="button action', ($isPremium ? '' : ' button-primary'), '" name="action" value="wp-worthy-import-csv">', __ ('Import CSV', $this->textDomain), '</button></p>',
              '</form>',
            ($isPremium ? '</div><div class="clear"></div>' : ''),
          '</div>',
        '</div>';
      
      if ($isPremium)
        echo
        '<div class="stuffbox">',
          '<h2>', __ ('Personalize and import VG-Wort markers', $this->textDomain), '</h2>',
          '<div class="inside">',
            '<form method="post" enctype="multipart/form-data" action="', $this->linkSection ($this::ADMIN_SECTION_CONVERT, null, true), '">',
              '<p>',
                __ ('Some very active authors may run out of markers before the end of the year. If this also happend to you, you may personalize anonymous markers here by uploading the CSV-File you received from VG-Wort by ordering anonymous markers on their website.', $this->textDomain),
              '</p><p>',
                '<label for="wp-worthy-claim-csv">', __ ('CSV-File containing anonymous markers', $this->textDomain), '</label><br />',
                '<input type="file" name="wp-worthy-claim-csv" id="wp-worthy-claim-csv" />',
              '</p><p>',
                '<input type="checkbox" name="wp-worthy-claim-import" id="wp-worthy-claim-import" value="1" checked="1" /> ',
                '<label for="wp-worthy-claim-import">', __ ('Import markers after they have been claimed', $this->textDomain), '</label>',
              '</p><p>',
                '<button type="submit" class="button action button-primary" name="action" value="wp-worthy-claim-and-import-csv">', __ ('Personalize and import markers', $this->textDomain), '</button>',
              '</p>',
            '</form>',
          '</div>',
        '</div>';
      
      echo
        '<div class="stuffbox">',
          '<h2>', __ ('Create Report about VG-Wort markers', $this->textDomain), '</h2>',
          '<div class="inside">',
            '<p>',
              __ ('Worthy can generate a CSV-file for you that contains known markers and may be imported into any spreadsheet program, e.g. LibreOffice Calc or Microsoft Excel.', $this->textDomain), '<br />',
              sprintf (__ ('You can choose which markers to be included in the report and extend them with further information. Using <a href="%s">Worthy Premium</a> you may also filter by state of the markers.', $this->textDomain), $this->linkSection ($this::ADMIN_SECTION_PREMIUM)),
            '</p>',
            '<form method="post" action="', $this->linkSection ($this::ADMIN_SECTION_CONVERT, null, true), '">',
              '<p>',
                '<input type="checkbox" name="wp-worthy-report-unused" id="wp-worthy-report-unused" value="1" /> ',
                '<label for="wp-worthy-report-unused">', __ ('Report markers that are not assigned to any post or page', $this->textDomain), '</label><br />',
                '<input type="checkbox" name="wp-worthy-report-used" id="wp-worthy-report-used" value="1" checked="1" /> ',
                '<label for="wp-worthy-report-used">', __ ('Report markers that are actually in use by a post or a page', $this->textDomain), '</label><br />',
                '<input type="checkbox" name="wp-worthy-report-title" id="wp-worthy-report-title" value="1" /> ',
                '<label for="wp-worthy-report-title">', __ ('Report title of post if assigned', $this->textDomain), '</label>';
      
      if (count ($Users = $GLOBALS ['wpdb']->get_results ('SELECT m.userid, u.display_name FROM `' . $this->getTablename ('worthy_markers')  . '` m, `' . $this->getTablename ('users')  . '` u WHERE m.userid=u.ID GROUP BY userid')) > 1) {
        echo
              '</p><p>',
                '<input type="radio" name="wp-worthy-report-filter" id="wp-worthy-report-users-all" value="0" onchange="document.getElementById(\'wp-worthy-report-users\').style.display=\'none\'" checked="1" /> ',
                '<label for="wp-worthy-report-users-all">', __ ('Report markers from all authors', $this->textDomain), '</label><br />',
                '<input type="radio" name="wp-worthy-report-filter" id="wp-worthy-report-users-filter" value="1" onchange="document.getElementById(\'wp-worthy-report-users\').style.display=\'block\'" /> ',
                '<label for="wp-worthy-report-users-filter">', __ ('Report markers from specific authors', $this->textDomain), '</label><br />',
                '<blockquote style="display:none" id="wp-worthy-report-users">';
      
        $uid = $this->getUserID ();
      
        foreach ($Users as $User)
          echo    '<input type="checkbox" id="wp-worthy-report-user-', $User->userid, '" name="wp-worthy-report-user[]" value="', $User->userid, '"', ($uid == $User->userid ? ' checked="1"' : ''), ' /> ',
                  '<label for="wp-worthy-report-user-', $User->userid, '">', $User->display_name, '</label><br />';
        
        echo 
                '</blockquote>';
      }
      
      echo
            (!$isPremium ? '' :
              '</p><p>' .
                '<input type="checkbox" name="wp-worthy-report-premium-uncounted" id="wp-worthy-report-premium-uncounted" value="1" checked="1" /> ' .
                '<label for="wp-worthy-report-premium-uncounted">' . __ ('Report markers that were not counted yet', $this->textDomain) . '</label><br />' .
                '<input type="checkbox" name="wp-worthy-report-premium-notqualified" id="wp-worthy-report-premium-notqualified" value="1" checked="1" /> ' .
                '<label for="wp-worthy-report-premium-notqualified">' . __ ('Report markers that have not qualified yet', $this->textDomain) . '</label><br />' .
                '<input type="checkbox" name="wp-worthy-report-premium-partialqualified" id="wp-worthy-report-premium-partialqualified" value="1" checked="1" /> ' .
                '<label for="wp-worthy-report-premium-partialqualified">' . __ ('Report markers that have qualified partial', $this->textDomain) . '</label><br />' .
                '<input type="checkbox" name="wp-worthy-report-premium-qualified" id="wp-worthy-report-premium-qualified" value="1" checked="1" /> ' .
                '<label for="wp-worthy-report-premium-qualified">' . __ ('Report markers that have qualified', $this->textDomain) . '</label><br />' .
                '<input type="checkbox" name="wp-worthy-report-premium-reported" id="wp-worthy-report-premium-reported" value="1" checked="1" /> ' .
                '<label for="wp-worthy-report-premium-reported">' . __ ('Report markers that have already been reported', $this->textDomain) . '</label>'
            ),
              '</p><p>',
                '<button type="submit" class="button action button-primary" name="action" value="wp-worthy-report-csv">', __ ('Generate Report as CSV', $this->textDomain), '</button>',
              '</p>',
            '</form>',
          '</div>',
        '</div>',
        '<div class="stuffbox">',
          '<h2>', __ ('Export unused VG-Wort markers', $this->textDomain), '</h2>',
          '<div class="inside">',
            '<form method="post" action="', $this->linkSection ($this::ADMIN_SECTION_CONVERT, null, true), '">',
              '<p>',
                __ ('Worthy can generate a CSV-File containing unused VG-Wort markers for you. All markers on the export will be removed from Worthy\'s database.', $this->textDomain), ' ',
                __ ('The exported CSV-file is usefull if you already have ordered the maximum amount of markers for an entire year and need additional markers at another place.', $this->textDomain), ' ',
                __ ('You may choose between two export-formats - one suitable for "normal" VG-Wort Authors and another one for publishers. We recommend you to use the one for authors as less informations are lost on export.', $this->textDomain),
              '</p><p>',
                '<label for="wp-worthy-export-count">', __ ('Number of unused markers', $this->textDomain), ' (', sprintf (__ ('%d available', $this->textDomain), $this->getAvailableMarkersCount (true)), ')</label><br />',
                '<input type="number" name="wp-worthy-export-count" id="wp-worthy-export-count" value="100" /><br />',
                '<label for="wp-worthy-export-format">', __ ('Export-Format to use', $this->textDomain), '</label><br />',
                '<select name="wp-worthy-export-format" id="wp-worthy-export-format">',
                  '<option value="author">', __ ('Authors', $this->textDomain), '</option>',
                  '<option value="publisher">', __ ('Publishers', $this->textDomain), '</option>',
                '</select>',
              '</p><p>',
                __ ('Be aware that using this export-function will remove information from your Worthy-Database. Use it with caution!', $this->textDomain),
              '</p><p>',
                '<button type="submit" class="button button-primary delete" name="action" value="wp-worthy-export-csv">', __ ('Export markers and remove from database', $this->textDomain), '</button>',
              '</p>',
            '</form>',
          '</div>',
        '</div>',
        '<hr />',
        '<div class="stuffbox">',
          '<h2>', __ ('Migrate existing VG-Wort markers', $this->textDomain), '</h2>',
          '<div class="inside">',
            '<p>',
              __ ('If you have used markers before Worthy you may want to migrate them to worthy.', $this->textDomain), ' ',
              __ ('Worthy is able to import markers from other plugins and also markers that are manually embeded into posts.', $this->textDomain), '<br />',
              __ ('If markers where embeded manually or managed via some basic plugins it is neccessary that you import the corresponding CSV-files as well, because worthy need to get in touch with the original private markers.', $this->textDomain),
            '</p><p>',
              '<span class="worthy-exclamation">!</span>',
              '<strong>', __ ('Please make sure that you have a recent backup of your wordpress-installation!', $this->textDomain), '</strong><br />',
                __ ('We have made some effors to make sure that there are no issues with the migrate-tool, but nobody can say that it is safe in every case.', $this->textDomain), '<br />',
                __ ('It is recommended to make a backup of your wordpress at least once a week even without using Worthy. We just want to remind you to make sure that you are able to restore lost data in case of any error.', $this->textDomain),
              '<div class="clear"></div>',
            '</p>',
            '<form method="post" action="', $this->linkSection ($this::ADMIN_SECTION_CONVERT, null, true), '">',
              '<p>',
                '<strong>', __ ('Selection:', $this->textDomain), '</strong>',
              '</p><p>',
                '<input type="checkbox" name="migrate_inline" id="wp-worthy-migrate_inline" value="1" /> ',
                '<label for="wp-worthy-migrate_inline">', __ ('Markers that are embeded into posts or pages', $this->textDomain), '</label><br />',
                '<input type="checkbox" name="migrate_vgw" id="wp-worthy-migrate_vgw" value="1" /> ',
                '<label for="wp-worthy-migrate_vgw">', __ ('Markers from plugin VGW (VG-Wort Krimskram)', $this->textDomain), '</label><br />',
                '<input type="checkbox" name="migrate_vgwort" id="wp-worthy-migrate_vgwort" value="1" /> ',
                '<label for="wp-worthy-migrate_vgwort">', __ ('Markers from plugin WP VG-Wort', $this->textDomain), '</label><br />',
                '<input type="checkbox" name="migrate_wppvgw" id="wp-worthy-migrate_wppvgw" value="1" /> ',
                '<label for="wp-worthy-migrate_wppvgw">', __ ('Markers from plugin Prosodia VGW', $this->textDomain), '</label><br />',
                '<input type="checkbox" name="migrate_tlvgw" id="wp-worthy-migrate_tlvgw" value="1" /> ',
                '<label for="wp-worthy-migrate_tlvgw">', __ ('Markers from plugin Torben Leuschner VG-Wort', $this->textDomain), '</label><br />',
              '</p><p>',
                '<strong>', __ ('Repair-Options:', $this->textDomain), '</strong>',
              '</p><p>',
                '<input type="checkbox" name="migrate_repair_dups" id="wp-worthy-migrate_repair_dups" value="1" /> ',
                '<label for="wp-worthy-migrate_repair_dups">', __ ('Assign new markers to posts that have a marker assigned that is already used', $this->textDomain), '</label>',
              '</p><p>',
                '<button type="submit" class="button action" name="action" value="wp-worthy-migrate-preview">', __ ('Preview', $this->textDomain), '</button> ',
                '<button type="submit" class="button action button-primary" name="action" value="wp-worthy-migrate">', __ ('Migrate posts and pages', $this->textDomain), '</button>',
              '</p>',
            '</form>',
          '</div>',
        '</div>';
      
      $this->adminMenuFooter ();
    }
    // }}}
    
    // {{{ adminMenuConvertPrepare
    /**
     * Prepare to show convert-menu
     * 
     * @access public
     * @return void
     **/
    public function adminMenuConvertPrepare () {
      // Do some common stuff
      $this->adminMenuPrepare ();
      
      // Check wheter to display some status-messages
      if (!isset ($_REQUEST ['displayStatus']))
        return;
      
      switch ($_REQUEST ['displayStatus']) {
        case 'importDone':
          if ($_REQUEST ['fileCount'] > 0)
            $this->adminStatus [] =
              '<div class="wp-worthy-success">' .
                '<ul class="ul-square">' .
                  '<li>' . sprintf (__ ('Read %d files containing %d markers', $this->textDomain), intval ($_REQUEST ['fileCount']), intval ($_REQUEST ['fileMarkerCount'])) . '</li>' .
                  '<li>' . sprintf (__ ('%d markers were already known, %d of them received an update', $this->textDomain), intval ($_REQUEST ['markerExisting']), intval ($_REQUEST ['markerUpdated'])) . '</li>' .
                  '<li>' . sprintf (__ ('%d markers were newly added to database, %d updates in total', $this->textDomain), intval ($_REQUEST ['markerCreated']) - intval ($_REQUEST ['markerUpdated']), intval ($_REQUEST ['markerCreated'])) . '</li>' .
                '</ul>' .
              '</div>';
          else
            $this->adminStatus [] = '<div class="wp-worthy-error">' . __ ('No files were uploaded or there was an error importing all records', $this->textDomain) . '</div>';
        
          break;
        case 'importClaimDone':
          if ($_REQUEST ['fileCount'] > 0) {
            $claimedMarkers = (isset ($_REQUEST ['markerClaimed']) && (strlen ($_REQUEST ['markerClaimed']) > 0) ? explode (',', esc_html ($_REQUEST ['markerClaimed'])) : array ());
            $failedMarkers = (isset ($_REQUEST ['markerFailed']) && (strlen ($_REQUEST ['markerFailed']) > 0) ? explode (',', esc_html ($_REQUEST ['markerFailed'])) : array ());
            
            $this->adminStatus [] =
              '<div class="wp-worthy-success">' .
                '<ul class="ul-square">' .
                  '<li>' . sprintf (__ ('Read %d files containing %d markers', $this->textDomain), intval ($_REQUEST ['fileCount']), intval ($_REQUEST ['fileMarkerCount'])) . '</li>' .
                (count ($claimedMarkers) == 0 ? '' :
                  '<li>' . __ ('The following markers were personalized:', $this->textDomain) . '<ul class="ul-square"><li>' . implode ('</li><li>', $claimedMarkers) . '</li></ul></li>' .
                  '<li>' . sprintf (__ ('%d markers were added to database', $this->textDomain), intval ($_REQUEST ['markerCreated'])) . '</li>') .
                (count ($failedMarkers) == 0 ? '' :
                  '<li>' . __ ('The following markers could not be personalized:', $this->textDomain) . '<ul class="ul-square"><li>' . implode ('</li><li>', $failedMarkers) . '</li></ul></li>') .
                 '</ul>' .
               '</div>';
          } else
            $this->adminStatus [] = '<div class="wp-worthy-error">' . __ ('No files were uploaded or there was an error importing all records', $this->textDomain) . '</div>';
          
          break;
        case 'premiumImportDone':
          $this->adminStatus [] = '<div class="wp-worthy-success">' . sprintf (__ ('<strong>%d new markers</strong> were imported via Worthy Premium', $this->textDomain), (isset ($_REQUEST ['markerCount']) ? intval ($_REQUEST ['markerCount']) : 0)) . '</div>';
          
          break;
        case 'migrateDone':
          $postsMigrated = (isset ($_REQUEST ['migrateCount']) ? intval ($_REQUEST ['migrateCount']) : 0);
          $postsTotal = (isset ($_REQUEST ['totalCount']) ? intval ($_REQUEST ['totalCount']) : 0);
          $dups = (isset ($_REQUEST ['duplicates']) && (strlen ($_REQUEST ['duplicates']) > 0) ? explode (',', $_REQUEST ['duplicates']) : array ());
          $repair_dups = (isset ($_REQUEST ['repair_dups']) ? $_REQUEST ['repair_dups'] % 2 : 0);
          $migrate_inline = (isset ($_REQUEST ['migrate_inline']) ? $_REQUEST ['migrate_inline'] % 2 : 0);
          $migrate_vgw = (isset ($_REQUEST ['migrate_vgw']) ? $_REQUEST ['migrate_vgw'] % 2 : 0);
          $migrate_vgwort = (isset ($_REQUEST ['migrate_vgwort']) ? $_REQUEST ['migrate_vgwort'] % 2 : 0);
          $migrate_wppvgw = (isset ($_REQUEST ['migrate_wppvgw']) ? $_REQUEST ['migrate_wppvgw'] % 2 : 0);
          $migrate_tlvgw = (isset ($_REQUEST ['migrate_tlvgw']) ? $_REQUEST ['migrate_tlvgw'] % 2 : 0);   
          
          // Give initial feedback
          $this->adminStatus [] = 
            '<div class="wp-worthy-success">' .
              sprintf (__ ('<strong>%s of %s posts and pages</strong> were successfully migrated', $this->textDomain), $postsMigrated, $postsTotal) .
            '</div>';
          
          // Check for duplicates 
          if (count ($dups) < 1)
            break;
          
          global $wpdb;
          
          $markers = $this->getAvailableMarkersCount ();
          $msg =
            '<div class="wp-worthy-error">' .
              __ ('There were some duplicate VG-Wort markers on the following posts and pages detected during migration', $this->textDomain) .
              '<ul>';
          
          foreach ($dups as $dup)
            $msg .= '<li>' . $this->wpLinkPost ($dup) . '</li>';
          
          $msg .= '</ul>';
          
          if ($repair_dups)
            $msg .= '<p></p>';
          elseif ($markers > 0)
            $msg .=
              '<p>' .
                '<strong>' .
                  $this->inlineAction ($this::ADMIN_SECTION_CONVERT, 'wp-worthy-migrate', __ ('Restart migration and assign new markers to this posts', $this->textDomain), array (
                    'migrate_inline' => ($migrate_inline ? 1 : 0),
                    'migrate_vgw' => ($migrate_vgw ? 1 : 0),
                    'migrate_vgwort' => ($migrate_vgwort ? 1 : 0),
                    'migrate_wppvgw' => ($migrate_wppvgw ? 1 : 0),
                    'migrate_tlvgw' => ($migrate_tlvgw ? 1 : 0),  
                    'migrate_repair_dups' => 1,
                  )) .
                '</strong>' .
              '</p>';
          else
            $msg .=
              '<p>' .
                __ ('There are no markers left on the Worthy Database. It is not possible to assign a new marker to a post or page until you import a new set of markers', $this->textDomain) .
              '</p>';
          
          $msg .= '</div>';
          $this->adminStatus [] = $msg;
          
          break;
      }
    }
    // }}}
    
    // {{{ adminMenuSettings
    /**
     * Display settings-section on admin-menu
     * 
     * @access public
     * @return void
     **/
    public function adminMenuSettings () {
      // Draw admin-header
      $this->adminMenuHeader ($this::ADMIN_SECTION_SETTINGS);
      
      global $wpdb;
      
      $eUID = get_current_user_id ();
      
      // Collect available users for account-sharing
      $sharingUsers = $wpdb->get_results ('SELECT u.ID, u.display_name, u.user_login, m.meta_value FROM `' . $this->getTablename ('users') . '` u LEFT JOIN `' . $this->getTablename ('usermeta') . '` m ON (u.ID=m.user_id AND m.meta_key="wp-worthy-authorid") WHERE meta_value IS NULL AND NOT ID=' . intval ($eUID));
      
      // Personal settings
      echo
        '<div class="stuffbox">',
          '<h2>', __ ('Personal settings', $this->textDomain), '</h2>',
          '<div class="inside">',
            '<form class="worthy-form" method="post" action="', $this->linkSection ($this::ADMIN_SECTION_SETTINGS, null, true), '">',
              '<p>',
                '<input type="checkbox" id="wp-worthy-auto-assign-markers" name="wp-worthy-auto-assign-markers" value="1"', (get_user_meta ($eUID, 'wp-worthy-auto-assign-markers', true) == 1 ? ' checked="1"' : ''), ' /> ',
                '<label for="wp-worthy-auto-assign-markers">', __ ('Automatically assign a marker to qualified posts', $this->textDomain), '</label>',
              '</p>';
      
      if (count ($sharingUsers) > 0) {
        $userID = $this->getUserID ();
        
        echo
              '<hr /><p>',
                '<label for="wp-worthy-account-sharing">', __ ('Account-Sharing', $this->textDomain), ':</label> ',
                '<select id="wp-worthy-account-sharing" name="wp-worthy-account-sharing">',
                  '<option value="0">', __ ('Don\'t use other account', $this->textDomain), '</option>';
        
        foreach ($sharingUsers as $sharingUser)
          echo    '<option value="', $sharingUser->ID, '"', ($userID == $sharingUser->ID ? ' selected="1"' : ''), '>', $sharingUser->display_name, ' (', $sharingUser->user_login, ')</option>';
        
        echo
                '</select>',
              '</p><p>',
                __ ('With account-sharing you can link your wordpress-account to another one.', $this->textDomain), ' ',
                __ ('In this case worthy will behave just like the other account performs your actions, e.g. markers will be imported for this account and will be assigned to posts from this account.', $this->textDomain), ' ',
                __ ('The same also applies to Worthy Premium of course.', $this->textDomain),
              '</p><hr />';
      }
      
      echo
              '<p class="wp-worthy-no-sharing">',
                '<input type="checkbox" id="wp-worthy-disable-output" name="wp-worthy-disable-output" value="1"', (get_user_meta ($eUID, 'wp-worthy-disable-output', true) == 1 ? ' checked="1"' : ''), ' /> ',
                '<label for="wp-worthy-disable-output">', __ ('Don\'t output markers on wordpress-frontend', $this->textDomain), '</label>',
              '</p><p class="wp-worthy-no-sharing">',
                __ ('There might be situations when you want to disable the output of markers managed by Worthy entirely. When you check this option Worthy will stop inserting markers into posts that are viewed on the wordpress frontend.', $this->textDomain),
              '</p><p class="wp-worthy-no-sharing">',
                '<label for="wp-worthy-default-server">', __ ('Default VG-Wort Server', $this->textDomain), '</label>',
                '<input type="text" id="wp-worthy-default-server" name="wp-worthy-default-server" value="', esc_attr (get_user_meta ($eUID, 'wp-worthy-default-server', true)), '" />',
              '</p><p class="wp-worthy-no-sharing">',
                __ ('When using a publisher-account at VG-Wort CSV-files don\'t come with server-information set, in this case Worthy has to know which server you are using.', $this->textDomain),
              '</p><p>',
                '<button type="submit" class="button action button-primary" name="action" value="wp-worthy-settings">', __ ('Save'), '</button>',
              '</p>',
            '</form>',
          '</div>',
        '</div>';
      
      // Toolbox for reindexing posts
      echo
        '<div class="stuffbox">',
          '<h2>', __ ('Worthy Index', $this->textDomain), '</h2>',
          '<div class="inside">',  
            '<form method="post" action="', $this->linkSection ($this::ADMIN_SECTION_SETTINGS, null, true), '">',
              '<ul>',
                '<li><strong>', sprintf (__ ('%d posts', $this->textDomain), $wpdb->get_var ('SELECT count(*) FROM `' . $this->getTablename ('postmeta') . '` WHERE meta_key="worthy_counter"')) . '</strong> ', __ ('on index', $this->textDomain), '</li>',
                '<li><strong>', sprintf (__ ('%d posts', $this->textDomain), $this->getUnindexedCount ()), '</strong> ', __ ('do not have a length-index for Worthy stored', $this->textDomain), '</li>',
              '</ul>',
              '<p><input type="checkbox" value="1" name="reindex_all" id="worthy_reindex_all" /> <label for="worthy_reindex_all">', __ ('Reindex everything, even posts that are already indexed', $this->textDomain), '</label></p>',
              '<p><button type="submit" name="action" value="worthy_reindex" class="button action">', __ ('Generate length-index', $this->textDomain), '</button></p>',
            '</form>',
          '</div>',
        '</div>';
      
      $this->adminMenuFooter ();
    }
    // }}}
    
    // {{{ adminMenuSettingsPrepare
    /**
     * Prepare to display settings-menu
     * 
     * @access public
     * @return void
     **/
    public function adminMenuSettingsPrepare () {
      // Do some common stuff
      $this->adminMenuPrepare ();
      
      if (isset ($_REQUEST ['displayStatus']) && ($_REQUEST ['displayStatus'] == 'reindexDone'))
        $this->adminStatus [] =
          '<div class="wp-worthy-success">' .
            '<strong>' . sprintf (__ ('%d posts', $this->textDomain), intval ($_REQUEST ['postCount'])) . '</strong> ' .
            __ ('have been indexed', $this->textDomain) .
          '</div>';
    }
    // }}}
    
    // {{{ adminMenuPremium
    /**
     * Display premium section
     * 
     * @access public
     * @return void
     **/
    public function adminMenuPremium () {
      // Draw admin-header
      $this->adminMenuHeader ($this::ADMIN_SECTION_PREMIUM);
      
      // Make sure we have SOAP available
      if (!extension_loaded ('soap') || !extension_loaded ('openssl')) {
        echo
          '<div class="stuffbox">',
            '<h2>', __ ('Worthy Premium', $this->textDomain), '</h2>',
            '<div class="inside">',
              '<p>', __ ('Oops! You need to have the SOAP- and OpenSSL-Extension for PHP available to use Worthy Premium.', $this->textDomain), '</p>',
            '</div>',
          '</div>';
        
        return $this->adminMenuFooter ();
      }
      
      if (isset ($_REQUEST ['feedback'])) {
        echo
          '<div class="stuffbox">',
            '<h2>', __ ('Worthy Premium Feedback', $this->textDomain), '</h2>',
            '<div class="inside">',
              '<form class="worthy-form" id="worthy-feedback" method="post" action="', $this->linkSection ($this::ADMIN_SECTION_PREMIUM, null, true), '">',
                '<p>',
                  '<label for="worthy-feedback-mail">', __ ('E-Mail (optional)'), '</label>',
                  '<input type="text" id="worthy-feedback-mail" name="worthy-feedback-mail" />',
                '</p><p>',
                  '<label for="worthy-feedback-caption">', __ ('Summary'), '</label>',
                  '<input type="text" id="worthy-feedback-caption" name="worthy-feedback-caption" />',
                '</p><p>',
                  '<label for="worthy-feedback-rating">', __ ('Rating'), '</label>',
                  '<select name="worthy-feedback-rating" id="worthy-feedback-rating">',
                    '<option value="0">', __ ('0 stars - you guys really messed it up', $this->textDomain), '</option>',
                    '<option value="1">', __ ('1 star - good idea, but ...', $this->textDomain), '</option>',
                    '<option value="2">', __ ('2 stars - works with some issues', $this->textDomain), '</option>',
                    '<option value="3" selected="1">', __ ('3 stars - works for me, but could be better', $this->textDomain), '</option>',
                    '<option value="4">', __ ('4 stars - great work that could be improved a bit', $this->textDomain), '</option>',
                    '<option value="5">', __ ('5 stars - it\'s simply amazing!', $this->textDomain), '</option>',
                  '</select>',
                '</p><p>',
                  '<label for="worthy-feedback-text">', __ ('Feedback'), '</label>',
                  '<textarea name="worthy-feedback-text" id="worthy-feedback-text"></textarea>',
                '</p><p>',
                  '<button type="submit" name="action" value="worthy_feedback" class="button button-large button-primary">', __ ('Submit'), '</button>',
                '</p>',
              '</form>',
            '</div>',
          '</div>';
        
        return $this->adminMenuFooter ();
      }
      
      // Try to retrive our account-status from worthy-premium
      $Status = $this->updateStatus ();
      
      if (isset ($_REQUEST ['shopping']) && ($Status ['Status'] != 'unregistered'))
        return $this->adminMenuPremiumShop ($Status);
      
      // Display notice if this account is not active
      if (($Status ['Status'] != 'testing') && ($Status ['Status'] != 'registered'))
        return $this->adminMenuPremiumUnregistered ($Status);
      
      // Check wheter to output status
      if (isset ($_REQUEST ['displayStatus']) &&
          (($_REQUEST ['displayStatus'] == 'reportDone') || ($webAreas = ($_REQUEST ['displayStatus'] == 'webareasDone')))) {
        echo
          '<div class="stuffbox">',
            '<h2>', __ ($webAreas ? 'Webareas were created' : 'Report to VG-Wort was done', $this->textDomain), '</h2>',
            '<div class="inside">',
              '<ul class="ul-square">';
        
        // Output list of posts that were successfully reported
        if (isset ($_REQUEST ['sIDs']) && (strlen ($_REQUEST ['sIDs']) > 0)) {
          global $wpdb;
          
          echo
            '<li>',
               __ ($webAreas ? 'Posts that were webareas created for' : 'Posts that were successfully reported', $this->textDomain),
              '<ul class="ul-square">';
          
          foreach (explode (',', $_REQUEST ['sIDs']) as $ID)
            echo '<li>', $this->wpLinkPost ($ID), '</li>';
          
          echo
              '</ul>',
            '</li>';
          
          // Update markers-status for reported markers
          if (!$webAreas)
            $this->updateMarkerStatus (false, false, false, true);
        }
        
        // Output list of posts that could not be reported
        if (isset ($_REQUEST ['fIDs']) && (strlen ($_REQUEST ['fIDs']) > 0)) {
          echo
            '<li>',
              __ ($webAreas ? 'Post that could not be a webarea created for' : 'Posts that could not be reported', $this->textDomain),
              '<ul class="ul-square">';
          
          foreach (explode (',', $_REQUEST ['fIDs']) as $ID)
            echo '<li>', $this->wpLinkPost ($ID), '</li>';
          
          echo
              '</ul>',
            '</li>';
        } elseif (!isset ($_REQUEST ['iIDs']) || (strlen ($_REQUEST ['iIDs']) == 0))
          echo '<li>', __ ('No errors happended during the process', $this->textDomain), '</li>';
        
        // Output list of invalid post-ids
        if (isset ($_REQUEST ['iIDs']) && (strlen ($_REQUEST ['iIDs']) > 0)) {
          echo
            '<li>',
              __ ('Invalid Post-IDs', $this->textDomain),
              '<ul class="ul-square">';
          
          foreach (explode (',', $_REQUEST ['iIDs']) as $ID)
            echo '<li>', intval ($ID), '</li>';
          
          echo
              '</ul>',
            '</li>';
        }
        
        echo
              '</ul>',
            '</div>',
          '</div>';
      }
      
      // Check wheter to preview the report for a number of posts
      if (isset ($_REQUEST ['action']) && ($_REQUEST ['action'] == 'worthy_premium_report_posts_preview')) {
        echo
          '<form method="post" action="', $this->linkSection ($this::ADMIN_SECTION_PREMIUM, null, true), '">',
            '<input type="hidden" name="action" value="worthy_premium_report_posts" />',
            '<div class="stuffbox">',
              '<button type="submit" style="float: right; margin: 6px;">', __ ('Report to VG-Wort', $this->textDomain), '</button>',
              '<h2>', __ ('Report preview', $this->textDomain), '</h2>',
              '<div style="clear: both;"></div>',
            '</div>';
        
        // Retrive SOAP-Client
        if (!is_object ($Client = $this->getSOAPClient ()) || !is_object ($Session = $this->getSession ())) {
          echo
              '<div class="wp-worthy-error">',
                __ ('Internal Error: Failed to create a SOAP-Client for bootstraping', $this->textDomain),
              '</div>',
            '</form>'; 
          
          return $this->adminMenuFooter ();
        }
         
        // Create a helper-table for output
        $Table = new wp_worthy_table_posts ($this);
        static $sMap = array (
          0 => 'not counted or synced',
          1 => 'not qualified',
          2 => 'partial qualified',
          3 => 'qualified',
          4 => 'reported',
        );
        
        if (!isset ($_REQUEST ['post']) || !is_array ($_REQUEST ['post']))
          $_REQUEST ['post'] = array ();
        
        foreach ($_REQUEST ['post'] as $PostID) {
          // Retrive this post
          if (!is_object ($post = get_post ($PostID, OBJECT, 'edit'))) {
            echo
              '<div class="wp-worthy-error">',
                '<p>', sprintf (__ ('Could not retrive the requested post %d', $this->textDomain), $PostID), '</p>',
                '<p>', $E->faultcode, ': ', $E->faultstring, '</p>',
              '</div>';
            
            continue;
          }
          
          // Retrive marker for this post
          if (!($markerStatus = $GLOBALS ['wpdb']->get_row ('SELECT * FROM `' . $this->getTablename ('worthy_markers') . '` WHERE postid=' . intval ($PostID))))
            continue;
          
          try {
            echo
              '<div class="stuffbox" style="padding-left: 20px; padding-bottom: 20px;">',
                '<p class="worthy-report-preview">',
                  '<input type="checkbox" checked="1" id="post_', $PostID, '" name="post[]" value="', $PostID, '" /> ',
                  '<label for="post_', $PostID, '">',
                    '<span>', __ ('Post-ID', $this->textDomain), ': <strong>', $PostID, '</strong>,</span> ',
                    '<span>', __ ('Title', $this->textDomain), ': <strong>', $post->post_title, '</strong>,</span> ',
                    '<span>', __ ('Author', $this->textDomain), ': <strong>', $Table->column_author ($post), '</strong>,</span> ',
                    '<span>', __ ('Date', $this->textDomain), ': <strong>', $Table->column_date ($post), '</strong>,</span> ',   
                    '<span>', __ ('Length', $this->textDomain), ': <strong>', $Table->column_characters ($post), '</strong>,</span> ',
                    '<span>', __ ('URL', $this->textDomain), ': <a target="_blank" href="', ($l = get_permalink ($PostID)), '">', $l, '</a>,</span> ',
                    '<span>', __ ('Private Marker', $this->textDomain), ': <strong>', $markerStatus->private, '</strong>,</span> ',
                    '<span>', __ ('Status', $this->textDomain), ': <strong>', __ ($sMap [$markerStatus->status], $this->textDomain), '</strong></span>',
                  '</label>',
                '</p>',
              (strlen ($post->post_title) <= 100 ? '' :
                '<p><span class="worthy-warning">' . __ ('Title is too long', $this->textDomain) . '</span></p>') .
                '<pre class="wp-worthy-preview">',
                  '<span class="wp-worthy-inline-title" id="wp-worthy-title-', $PostID, '">', $post->post_title, "\n", str_repeat ('-', strlen ($post->post_title)), "</span>\n",
                  '<span class="wp-worthy-inline-content" id="wp-worthy-content-', $PostID, '">', esc_html ($Client->reportPreview ($Session, '', apply_filters ('the_content', $post->post_content), false)), '</span>',
                # '</pre><a href="#" onclick="this.nextElementSibling.style.display=(this.toggled?\'none\':\'block\'); this.innerHTML=(this.toggled ? \'Display\' : \'Hide\') + \' original content\'; this.toggled=!this.toggled; return false;">Display original content</a><pre style="border-top: 2px solid #aaa; padding-top: 20px; display: none;">',
                #   str_replace ('<', '&lt', apply_filters ('the_content', $post->post_content)),
                '</pre>',
              '</div>';  
          } catch (SOAPFault $E) {
            echo
              '<div class="wp-worthy-error">',
                '<p>', __ ('Service-Error: Caught an unexpected exception. Strange!', $this->textDomain), '</p>',
                '<p>', $E->faultcode, ': ', $E->faultstring, '</p>',
              '</div>';
          }
        }  
           
        echo '</form>';
        
        return $this->adminMenuFooter ();
      }
      
      /**
       * Display subscribtion-status
       **/
      $tf = get_option ('time_format');
      $df = get_option ('date_format');
      $userID = $this->getUserID ();
      
      echo
        '<div class="stuffbox">',
          '<h2>', __ ('Worthy Premium Subscription', $this->textDomain), '</h2>',
          '<div class="inside">';
      
      if ($Status ['Status'] == 'registered')
        echo '<p>', __ ('You are fully subscribed to Worthy Premium.', $this->textDomain), '</p>';
      else
        echo
          '<p>', __ ('You are using the Worthy Premium Test-Drive.', $this->textDomain), '</p>' .
          ($Status ['Status'] == 'testing-pending' ? '<p>' . __ ('Please be patient! We received your subscription-request but have not received or processed your payment yet.', $this->textDomain) . '</p>' : '');
      
      echo
            '<ul class="ul-square">',
              '<li><span class="wp-worthy-label">', __ ('Number of reports remaining', $this->textDomain), ':</span> ', sprintf (__ ('%d reports', $this->textDomain), $Status ['ReportLimit']), '</li>',
              '<li><span class="wp-worthy-label">', __ ('Begin of subscribtion', $this->textDomain), ':</span> ', sprintf (__ ('%s at %s', $this->textDomain), date_i18n ($df, $Status ['ValidFrom']), date_i18n ($tf, $Status ['ValidFrom'])), '</li>',
              '<li><span class="wp-worthy-label">', __ ('End of subscribtion', $this->textDomain), ':</span> ', sprintf (__ ('%s at %s', $this->textDomain), date_i18n ($df, $Status ['ValidUntil']), date_i18n ($tf, $Status ['ValidUntil'])), '</li>',
            '</ul>',
            '<p><a href="', $this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('shopping' => 'isfun')), '">',
            ($Status ['Status'] == 'registered' ?
              __ ('If you need more reports or want to advance you subscribtion, please visit our Shop.', $this->textDomain) :
              __ ('If you want to subscribe to Worthy Premium, please visit our Shop.', $this->textDomain)
            ),
            '</a></p>',
          '</div>',
        '</div>',
        '<div class="stuffbox">',
          '<h2>', __ ('Worthy Premium Status', $this->textDomain), '</h2>',
          '<div class="inside">',
            '<ul class="ul-square">',
              '<li>',
                '<span class="wp-worthy-label">', __ ('Number of markers imported', $this->textDomain), ':</span> ', intval (get_user_meta ($userID, 'worthy_premium_markers_imported', true)), ' (', sprintf (__ ('%d total', $this->textDomain), get_option ('worthy_premium_markers_imported', 0)), ') ',
                '<small>(<a href="', $this->linkSection ($this::ADMIN_SECTION_CONVERT), '">', __ ('Import new markers', $this->textDomain), '</a>)</small>',
              '</li>',
              '<li>',
                '<span class="wp-worthy-label">', __ ('Number of markers synced', $this->textDomain), ':</span> ', intval (get_user_meta ($userID, 'worthy_premium_marker_updates', true)), ' (', sprintf (__ ('%d total', $this->textDomain), get_option ('worthy_premium_marker_updates', 0)), ') ',
                # '<small>(', $this->inlineAction ($this::ADMIN_SECTION_PREMIUM, 'worthy_premium_sync_markers', __ ('Synchronize now', $this->textDomain)), ')</small>',
              '</li>',
            '</ul>',
            '<ul class="ul-square">',
              '<li>',
                '<span class="wp-worthy-label">', __ ('Last check of subscribtion-status', $this->textDomain), ':</span> ',
              ((($ts = intval (get_user_meta ($userID, 'worthy_premium_status_updated', true))) > 0) ?
                sprintf (__ ('%s at %s', $this->textDomain), date_i18n ($df, $ts), date_i18n ($tf, $ts)) : __ ('Not yet', $this->textDomain)), ' ',
                '<small>(', $this->inlineAction ($this::ADMIN_SECTION_PREMIUM, 'worthy_premium_sync_status', __ ('Synchronize now', $this->textDomain)), ')</small>',
              '</li>',
              '<li>',
                '<span class="wp-worthy-label">', __ ('Last syncronisation of marker-status', $this->textDomain), ':</span> ',
              ((($ts = intval (get_user_meta ($userID, 'worthy_premium_markers_updated', true))) > 0) ?
                sprintf (__ ('%s at %s', $this->textDomain), date_i18n ($df, $ts), date_i18n ($tf, $ts)) : __ ('Not yet', $this->textDomain)), ' ',
                '<small>(', $this->inlineAction ($this::ADMIN_SECTION_PREMIUM, 'worthy_premium_sync_markers', __ ('Synchronize now', $this->textDomain)), ')</small>',
              '</li>',
            '</ul>',
          '</div>',
        '</div>';
      
      $this->adminMenuPremiumServer ();
      $this->adminMenuFooter ();
    }
    // }}}
    
    // {{{ adminMenuPremiumUnregistered
    /**
     * Display Premium-Menu for unregistered or expired users
     * 
     * @param enum $Status
     * 
     * @access private
     * @return void
     **/
    private function adminMenuPremiumUnregistered ($Status) {
      /**
       * Display a notice if testing-period is expired
       **/
      if ($Status ['Status'] == 'testing-expired')
        echo
          '<div class="stuffbox">',
            '<h2>', __ ('Worthy Premium Subscription', $this->textDomain), '</h2>',
            '<div class="inside">',
              '<p>',
                __ ('Sadly your test-drive is over now. :-(', $this->textDomain), '<br />',
                __ ('We hope you enjoyed the test and we could convince you with our service!', $this->textDomain),
              '</p>',
              '<ul class="ul-square">',
                '<li><a href="', $this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('shopping' => 'isfun')), '">', __ ('Subscribe to Worthy Premium', $this->textDomain), '</a></li>',
                '<li><a href="http://wordpress.org/support/view/plugin-reviews/wp-worthy" target="_blank">', __ ('Write a review about Worthy', $this->textDomain), '</a></li>',
                '<li><a href="', $this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('feedback' => 1)), '">', __ ('Tell us your opinion about Worthy - in private', $this->textDomain), '</a></li>',
                '<li>', $this->inlineAction ($this::ADMIN_SECTION_PREMIUM, 'worthy_premium_sync_status', __ ('Check your subscription-status again', $this->textDomain)), '</li>',
              '</ul>',
            '</div>',
          '</div>';
      /**
       * Display notice if a premium-subscribtion has expired
       **/
      elseif ($Status ['Status'] == 'expired')
        echo
          '<div class="stuffbox">',
            '<h2>', __ ('Worthy Premium Subscription', $this->textDomain), '</h2>',
            '<div class="inside">',
              '<p>', __ ('Your Worthy Premium Subscription expired.', $this->textDomain), '</p>',
              '<p>', __ ('If you want continue to use Worthy Premium, we ask you to renew your subscribtion. We would be very glad to have you for another year as our customer!', $this->textDomain), '</p>',
              '<ul class="ul-square">',
                '<li><a href="', $this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('shopping' => 'isfun')), '">', __ ('Subscribe to Worthy Premium', $this->textDomain), '</a></li>',
                '<li><a href="', $this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('feedback' => 1)), '">', __ ('Tell us your opinion about Worthy - in private', $this->textDomain), '</a></li>',
              '</ul>',
            '</div>',
          '</div>';
      /**
       * Display notice if the account is being upgraded (not from testing)
       **/
      elseif ($Status ['Status'] == 'pending') {
        echo
          '<div class="stuffbox">',
            '<h2>', __ ('Worthy Premium Subscription', $this->textDomain), '</h2>',
            '<div class="inside">',
              '<p>',
                __ ('Please be patient! We received your subscription-request but have not received or processed your payment yet.', $this->textDomain),
              '</p>',
            '</div>',
          '</div>';
        
        $this->adminMenuPremiumServer ();
        
        return $this->adminMenuFooter ();
      
      /**
       * Display sign-up formular
       **/
      } else
        echo
          '<div class="stuffbox">',
            '<h2>', __ ('Worthy Premium Sign Up', $this->textDomain), '</h2>',
            '<div class="inside">',
              '<div class="worthy-signup">',
                '<form method="post" id="wp-worthy-signup" class="worthy-form" action="', $this->linkSection ($this::ADMIN_SECTION_PREMIUM, null, true), '">',
                  '<fieldset>',
                    '<p>',
                      '<label for="wp-worthy-username">', __ ('Username for VG-Wort T.O.M.', $this->textDomain), '</label>',
                      '<input id="wp-worthy-username" type="text" name="wp-worthy-username" />',
                    '</p><p>',
                      '<label for="wp-worthy-password">', __ ('Password', $this->textDomain), '</label>',
                      '<input id="wp-worthy-password" type="password" name="wp-worthy-password" />',
                    '</p><p>',
                      '<input type="checkbox" name="wp-worthy-accept-tac" id="wp-worthy-accept-tac" value="1" /> ',
                      '<label for="wp-worthy-accept-tac">',
                        sprintf (__ ('I have read and accepted the <a href="%s" id="wp-worthy-terms" target="_blank">terms of service</a> and <a href="%s" id="wp-worthy-privacy" target="_blank">the privacy statement</a>', $this->textDomain), 'https://wp-worthy.de/api/terms.html', 'https://wp-worthy.de/api/privacy.html'),
                      '</label>',
                    '</p><p>',
                      '<button type="submit" class="button action" name="action" value="worthy_premium_signup">', __ ('Sign up for Worthy Premium Testdrive', $this->textDomain), '</button>',
                    '</p>',
                  '</fieldset>',
                '</form>',
              '</div><div>',
                '<p>', __ ('To sign up for Worthy Premium, you\'ll need a valid T.O.M.-Login.', $this->textDomain), '</p>',
                '<p>', __ ('Your Login-Information is required to get automated access to your T.O.M. account. Without this Worthy Premium is not able to work.', $this->textDomain), '</p>',
                '<p>',
                  __ ('To get in touch with Worthy Premium and its amazing functions, you\'ll receive a risk-free trail-account in first place. We are very sure that you\'ll be excited.', $this->textDomain), ' ',
                  __ ('You may buy a full-featured Worthy Premium-Subscribtion whenever you want.', $this->textDomain),
              '</div>',
              '<div class="clear"></div>',
            '</div>',
          '</div>';
      
      /**
       * Give an overview what "Worthy Premium" is
       **/
      echo
        '<div class="stuffbox">',
          '<h2>', __ ('Worthy Premium', $this->textDomain), '</h2>',
          '<div class="inside">',
            '<p>', __ ('Why should I use Worthy Premium?', $this->textDomain), '</p>',
            '<ul class="ul-square">',
              '<li>',
                __ ('Worthy Premium gives you an <strong>automated import of markers</strong>.', $this->textDomain), '<br />',
                __ ('You will no longer have to leave Wordpress and login at T.O.M. for this task.', $this->textDomain),
              '</li>',
              '<li>',
                __ ('Worthy Premium <strong>keeps track on the status of markers</strong>.', $this->textDomain), '<br />',
                __ ('You will be able to directly see if a post has already qualified, is on a good way or not. Everything happens directly inside your wordpress admin-panel!', $this->textDomain),
              '</li>',
              '<li>',
                '<strong>', __ ('Most important', $this->textDomain), ':</strong> ', __ ('Worthy Premium enables you to <strong>generate reports for all qualified posts</strong>!', $this->textDomain), '<br />',
                __ ('Save hours of time by submitting reports to VG-Wort via Worthy Premium instead of copy and pasting posts on your own! This is the most comfortable feature most professional authors and bloggers have waited for!', $this->textDomain),
              '</li>',
            '</ul>',
          '</div>',
        '</div>';
      
      /**
       * Introduce the "Worthy Premium Testdrive"
       * 
       * Huge remark:
       * Below are stated some numbers belonging to our free test-drive.
       * They are inserted dynamically into output just to keep translation-overhead
       * small. If you change them on your own, it does not affect anything.
       **/
      if ($Status ['Status'] == 'unregistered')
        echo
          '<div class="stuffbox">',
            '<h2>', __ ('Worthy Premium Testdrive', $this->textDomain), '</h2>',
            '<div class="inside">',
              '<p>',
                __ ('I guess we do now have your attention, right? But before you have to paid even a cent for Worthy Premium you may validate every of our promises by yourself.', $this->textDomain), '<br />',
                __ ('There are no hidden costs, no traps and no automatic renewals. There is no way that is more fair or uncomplicated!', $this->textDomain),
              '</p><p>',
                __ ('This is the reason why we offer a limited test-drive.', $this->textDomain),
              '</p>',
              '<ul class="ul-square">',
                '<li><strong>', sprintf (__ ('Get free access to our service for %d days', $this->textDomain), 7), '</strong></li>',
                '<li><strong>', sprintf (__ ('Submit reports to VG-Wort for up to %d posts during that time', $this->textDomain), 3), '</strong></li>',
                '<li>', __ ('Import as much new markers as you like to (in batches of 100 markers per import) using your limited trial-access', $this->textDomain), '</li>',
                '<li>', __ ('Get free status-updates for all your markers while the trial-period is running', $this->textDomain), '</li>',
              '</ul>',
              '<p>', __ ('To setup a trial-account you only need to have an existing VG-Wort T.O.M. account. We only need your login-credentials. (See Worthy Premium Security Notes for details)', $this->textDomain), '</p>',
            '</div>',
          '</div>';
      
      if ($Status ['Status'] == 'unregistered')
        echo
          '<div class="stuffbox">',
            '<h2>', __ ('Worthy Premium Security Notes', $this->textDomain), '</h2>',
            '<div class="inside">',
              '<p>',
                __ ('Worthy Premium is a webservice located between your Wordpress-Blog and VG-Wort T.O.M..', $this->textDomain), ' ',
                __ ('As Worthy Premium works on your behalf at T.O.M., you need to supply your login-information to Worthy.', $this->textDomain),
              '</p><p>',
                __ ('Your login-information will be handled with highest security. Worthy Premium will not store your password, it will be submitted by your wordpress-installation whenever a login for T.O.M. is required.', $this->textDomain),
              '</p><p>',
                __ ('If you choose not to use our service, we ask kindly to change your login-credentials.', $this->textDomain),
              '</p>',
            '</div>',
          '</div>';
      
      $this->adminMenuPremiumServer ();
      $this->adminMenuFooter ();
    }
    // }}}
    
    // {{{ adminMenuPremiumShop
    /**
     * Output Worthy Premium Shop
     * 
     * @access private
     * @return void
     **/
    private function adminMenuPremiumShop ($Status) {
      // Access the SOAP-Client here
      if (!is_object ($Client = $this->getSOAPClient ()) || !is_object ($Session = $this->getSession ())) {
        echo
          '<div class="stuffbox">',
            '<h2>', __ ('Worthy Premium Shop', $this->textDomain), '</h2>',
            '<div class="inside wp-worthy-error">',
              __ ('Internal Error: Failed to create a SOAP-Client for bootstraping', $this->textDomain),
            '</div>',
          '</div>';
        
        return $this->adminMenuFooter ();
      }
      
      // Check if there is a shopping-result
      if (isset ($_GET ['rc']) && in_array ($_GET ['rc'], array ('done', 'processing', 'canceled'))) {
        if ($_GET ['rc'] == 'done') {
          $msg = array ('All done', 'Your order was successfull and is already paid. We hope that you enjoy using Worthy Premium! Thank you!');
          
          $this->updateStatus (true);
        } elseif ($_GET ['rc'] == 'processing')
          $msg = array ('We are processing your order', 'Once your order is paid your account will be updated. This usually takes less than a minute but can depend on how you processed the payment.');
        elseif ($_GET ['rc'] == 'canceled')
          $msg = array ('Payment was canceled', 'How sad! Your payment was canceled. Don\'t you feel confident with using Worthy Premium?');
        
        echo
          '<div class="stuffbox">',
            '<h2>', __ ($msg [0], $this->textDomain), '</h2>',
            '<div class="inside">',
              __ ($msg [1], $this->textDomain),
            '</div>',
          '</div>';
      }
      
      // Output items available on the shop
      try {
        $Goods = $Client->serviceGetPurchableGoods ($Session);
        
        echo
          '<form method="post" action="', $this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('shopping' => 'isfun'), true), '" id="wp-worthy-shop">',
            '<input type="hidden" name="action" value="wp-worthy-premium-purchase" />',
            '<div class="stuffbox" id="wp-worthy-shop-goods">',
              '<h2>', __ ('Worthy Premium Shop', $this->textDomain), '</h2>',
              '<div class="inside">';
        
        $r = 0;
        $c = 0;
        
        foreach ($Goods as $Good) {
          echo
                '<div class="wp-worthy-menu-half">',
                  '<h3>', __ ($Good->Name, $this->textDomain), '</h3>',
                ($Good->Description ? '<p>' . __ ($Good->Description, $this->textDomain) . '</p>' : ''),
                  '<p>';
          
          if (!isset ($Good->Required) || !$Good->Required)
            echo
                    '<input type="radio" name="wp-worthy-good-', $Good->ID, '" value="none" id="wp-worthy-good-', $Good->ID, '-none" checked="1" /> ',
                    '<label for="wp-worthy-good-', $Good->ID, '-none">',
                      __ ('Leave unchanged', $this->textDomain),
                    '</label>',
                  '</p><p>';
          else
            $r++;
          
          foreach ($Good->Options as $Option)
            echo
                    '<input type="radio" name="wp-worthy-good-', $Good->ID, '" value="', $Option->ID, '" id="wp-worthy-good-', $Good->ID, '-', $Option->ID, '"', ($Good->Required && $Option->Default ? ' checked="1"' : ''), ' data-value="', $Option->PriceTotal, '" data-tax="', $Option->PriceTax, '" /> ',
                    '<label for="wp-worthy-good-', $Good->ID, '-', $Option->ID, '">',
                      '<span class="wp-worthy-label">',
                        __ ($Option->Name, $this->textDomain),
                        ($Option->Description ? '<br /><span class="wp-worthy-shop-option-description">' . __ ($Option->Description, $this->textDomain) . '</span>' : ''),
                      '</span>',
                      '<span class="wp-worthy-value wp-worthy-price">', number_format ($Option->PriceTotal, 2, ',', '.'), ' &euro;*</span>',
                    '</label><br />';
          
          echo
                  '</p>',
                '</div>';
          
          if ($c++ % 2 == 1)
            echo '<div class="clear"></div>';
        }
        
        echo
              ($r == count ($Goods) ? '<div class="wp-worthy-menu-half"><p>' . __ ('You have not purchased any subscribtion yet. Upon the first subscribtion it is required that you but a subscribtion and a bundle as well in combination.', $this->textDomain) . '</p></div>' : ''),
                '<div class="clear"></div>',
              '</div>',
            '</div>',
            '<div class="stuffbox">',
              '<h2>', __ ('Payment Options', $this->textDomain), '</h2>',
              '<div class="inside">',
                '<div class="wp-worthy-menu-half">',
                  '<p>',
                    '<input type="radio" name="wp-worthy-payment" id="wp-worthy-payment-giropay" value="giropay" checked="1" /> ',
                    '<label for="wp-worthy-payment-giropay">',
                      '<img src="', plugins_url ('assets/giropay.png', __FILE__), '" width="100" height="43" align="absmiddle" />',
                    '</label>',
                    '<span id="wp-worthy-payment-giropay-box">',
                      '<strong>BIC:</strong><br />',
                      '<input type="text" name="wp-worthy-payment-giropay-bic" id="wp-worthy-payment-giropay-bic" autocomplete="off" />',
                    '</span>',
                  '</p><p>',
                    '<ul class="ul-square">',
                      '<li>', __ ('No signup required, works with normal online-banking', $this->textDomain), '</li>',
                      '<li>', __ ('No personal data is exchanged', $this->textDomain), '</li>',
                      '<li>', __ ('Checkout is finished immediatly', $this->textDomain), '</li>',
                      '<li>', __ ('German Payment-Service-Provider', $this->textDomain), '</li>',
                    '</ul>',
                  '</p>',
                '</div><div class="wp-worthy-menu-half">',
                  '<p>',
                    '<input type="radio" name="wp-worthy-payment" id="wp-worthy-payment-paypal" value="paypal" /> ',
                    '<label for="wp-worthy-payment-paypal">',
                      '<img src="', plugins_url ('assets/paypal.png', __FILE__), '" width="150" height="38" align="absmiddle" />',
                    '</label>',
                  '</p><p>',
                    '<ul class="ul-square">',
                      '<li>', __ ('Does not depend on a giropay-capable bank', $this->textDomain), '</li>',
                      '<li>', __ ('Works with credit-cards', $this->textDomain), '</li>',
                      '<li>', __ ('Checkout finishes fast', $this->textDomain), '</li>',
                    '</ul>',
                  '</p>',
                '</div>',
              '</div>',
              '<div class="clear"></div>',
            '</div>',
            '<div class="stuffbox">',
              '<div class="inside">',
                '<p style="float: right; text-align: right; max-width: 200px;">',
                  '<button type="submit" class="button button-large button-primary">', __ ('Proceed to checkout', $this->textDomain), '</button><br />',
                '</p>',
                '<p>',
                  '<strong>', __ ('Total', $this->textDomain), ': <span id="wp-worthy-shop-price">0,00</span> &euro;</strong><br />',
                  '<small>', __ ('Tax included', $this->textDomain), ': <span id="wp-worthy-shop-tax">0,00</span> &euro;</small>',
                '</p><p>',
                  '<input type="checkbox" value="1" name="wp-worthy-accept-tac" id="wp-worthy-accept-tac" /> ',
                  '<label for="wp-worthy-accept-tac">',
                    sprintf (__ ('I have read and accepted the <a href="%s" id="wp-worthy-terms" target="_blank">terms of service</a> and <a href="%s" id="wp-worthy-privacy" target="_blank">the privacy statement</a>', $this->textDomain), 'https://wp-worthy.de/api/terms.html', 'https://wp-worthy.de/api/privacy.html'),
                  '</label>',
                '</p>',
                '<p>', __ ('* All price are with tax included', $this->textDomain), '</p>',
                '<div class="clear"></div>',
              '</div>',
            '</div>',
          '</form>';
      } catch (Exception $E) {
        # TODO: Bail out an error
      }
      
      $this->adminMenuPremiumServer ();
      $this->adminMenuFooter ();
    }
    
    // {{{ adminMenuPremiumServer
    /**
     * Output Menu to select server to use with worthy-premium
     * 
     * @access private
     * @return void
     **/
    private function adminMenuPremiumServer () {
      // Check the server-setting
      $Server = get_user_meta ($this->getUserID (), 'worthy_premium_server', true);
      
      // Check if this is wanted
      if ((!defined ('WP_DEBUG') || !WP_DEBUG) && (!defined ('WORTHY_DEBUG') || !WORTHY_DEBUG) && ($Server != 'devel') && !isset ($_REQUEST ['wp-worthy-show-debug']))
        return;
      
      echo
        '<div class="stuffbox">',
          '<h2>', __ ('Worthy Premium Debugging', $this->textDomain), '</h2>',
          '<div class="inside">',
            '<form method="post" action="', $this->linkSection ($this::ADMIN_SECTION_PREMIUM, null, true), '">',
              '<p>',
                '<input type="radio" name="worthy_server" id="worthy-server-production" value="production"', (!$Server || ($Server != 'devel') ? ' checked="1"' : ''),' /> ',
                '<label for="worthy-server-production">', __ ('Use Worthy Premium Production Server', $this->textDomain), ' (HTTPS)</label><br />',
                '<input type="radio" name="worthy_server" id="worthy-server-devel" value="devel"', (!$Server || ($Server != 'devel') ? '' : ' checked="1"'), ' /> ',
                '<label for="worthy-server-devel">', __ ('Use Worthy Premium Development Server', $this->textDomain), ' (HTTP)</label><br />',
              '</p><p>',
                '<button class="button action" name="action" value="worthy_premium_select_server">', __ ('Change Worthy Premium Server', $this->textDomain), '</button>',
              '</p><p>',
                __ ('If something in S2S-Communication does not work, you might want to drop the current session', $this->textDomain),
              '</p><p>',
                '<button class="button action" name="action" value="worthy_premium_drop_session">', __ ('Drop current session', $this->textDomain), '</button>',
              '</p><p>',
                __ ('You may want to drop the local user-credentials to make Worthy belive its not subscribed to Worthy Premium', $this->textDomain),
              '</p><p>',
                '<button class="button action" name="action" value="worthy_premium_drop_registration">', __ ('Drop local user-credentials', $this->textDomain), '</button>',
              '</p>',
            '</form>',
          '</div>',
        '</div>';
    }
    // }}}
    
    // {{{ adminMenuPremiumPrepare
    /**
     * Prepare to display premium-menu
     * 
     * @access public
     * @return void
     **/
    public function adminMenuPremiumPrepare () {
      // Do some common stuff first
      $this->adminMenuPrepare ();
      
      // Include Giropay-Widget on shop
      if (isset ($_REQUEST ['shopping'])) {
        $this->addScript ('https://bankauswahl.giropay.de/widget/v1/giropaywidget.min.js');
        $this->addStylesheet ('https://bankauswahl.giropay.de/widget/v1/style.css');
      }
      
      // Check if there is some status to display
      if (!isset ($_REQUEST ['displayStatus']))
        return;
      
      // Output status-message
      switch ($_REQUEST ['displayStatus']) {
        case 'signupDone':
          $this->adminStatus [] =
            ($_REQUEST ['status'] == 0 ?
              '<div class="wp-worthy-error">' . __ ('Could sign up. Please check your login-credentials!', $this->textDomain) . '</div>' :
              ($_REQUEST ['status'] == 1 ?
                '<div class="wp-worthy-success">' .  __ ('Signup with Worthy Premium was successfull!', $this->textDomain) . '</div>' :
                '<div class="wp-worthy-error">' . __ ('Could not store username and/or password on your wordpress-configuration. Strange!', $this->textDomain) . '</div>'
              )
            );
          
          break;
        case 'syncStatusDone':
          $this->adminStatus [] = '<div class="wp-worthy-success">' . __ ('Worthy Premium Status was successfully updated', $this->textDomain) . '</div>';
          
          break;
        case 'syncMarkerDone':
          if (($Count = (isset ($_REQUEST ['markerCount']) ? intval ($_REQUEST ['markerCount']) : -1)) >= 0)
            $this->adminStatus [] =
              '<div class="wp-worthy-success">' .
                '<p>' .
                  sprintf (__ ('<strong>%d markers</strong> received successfully an update (all others are unchanged)', $this->textDomain), $Count) .
                '</p>' .
                ($Count > 0 ? '<p><a href="' . $this->linkSection ($this::ADMIN_SECTION_MARKERS, array ('status_since' => (time () - 5))) . '">' . __ ('Show me that updates, please!', $this->textDomain) . '</a></p>' : '') .
              '</div>';
          else
            $this->adminStatus [] =
              '<div class="wp-worthy-error">' .
                __ ('There was an error while syncronising the markers', $this->textDomain) .
              '</div>';
          
          break;
        case 'feedbackDone':
          $this->adminStatus [] =
            '<div class="wp-worthy-success">' .
              '<p><strong>' . __ ('Thank you for your feedback!', $this->textDomain) . '</strong></p>' .
              '<p>' . __ ('We promise to read it carefully and respond within short time if a response is needed.', $this->textDomain) . '</p>' .
            '</div>';
          
          break;
        case 'noGoods':
          $this->adminStatus [] = '<div class="wp-worthy-error">' . __ ('You did not select anything to purchase.', $this->textDomain) . '</div>';
          
          break;
        case 'paymentError':
          $this->adminStatus [] =
            '<div class="wp-worthy-error">' .
              '<p>' . __ ('There was an error while initiating the payment', $this->textDomain) . ':</p>' .
              '<p>' . esc_html (__ ($_REQUEST ['Error'], $this->textDomain)) . '</p>' .
            '</div>';
          
          break;
      }
    }
    // }}}
    
    // {{{ adminMenuStatus
    /**
     * Output status-messages for admin-menu
     * 
     * @access private
     * @return void
     **/
    private function adminMenuStatus () {
      if (count ($this->adminStatus) == 0)
        return;
      
      echo
        '<div class="stuffbox">',
          '<h2>', __ ('Status', $this->textDomain), '</h2>',
          '<div class="inside">',
            '<ul>';
      
      foreach ($this->adminStatus as $Status)
        echo '<li class="wp-worthy-status">', $Status, '</li>';
      
      echo
            '</ul>',
          '</div>',
        '</div>';
    }
    // }}}
    
    // {{{ adminMenuPrepare
    /**
     * Prepare the output of the admin-menu
     * 
     * @access public
     * @return void
     **/
    public function adminMenuPrepare () {
      // Check wheter to redirect
      if (!empty ($_REQUEST ['_wp_http_referer'])) {
        wp_redirect (remove_query_arg (array ('_wp_http_referer', '_wpnonce'), wp_unslash ($_SERVER ['REQUEST_URI'])));
        
        exit ();
      }
      
      // Check wheter to display some status-messages
      if (!isset ($_REQUEST ['displayStatus']))
        return;
      
      switch ($_REQUEST ['displayStatus']) {
        case 'databaseError':
          $this->adminStatus [] = '<div class="wp-worthy-error">' . __ ('Database-Error: The new markers could not be stored on the wordpress-database. This should never, never happen! Please check your installation!', $this->textDomain);
          
          break;
        case 'noSoap':
          $this->adminStatus [] =
            '<div class="wp-worthy-error">' .
              __ ('Internal Error: Failed to create a SOAP-Client for bootstraping', $this->textDomain) .
            '</div>';
          
          break;
        case 'soapException':
          $this->adminStatus [] =
            '<div class="wp-worthy-error">' .
              '<p>' . __ ('Service-Error: Caught an unexpected exception. Strange!', $this->textDomain) . '</p>' .
              '<p>Report: ' . esc_html ($_REQUEST ['faultCode']) . ' - ' . esc_html ($_REQUEST ['faultString']) . '</p>' .
            '</div>';
          
          break;
      }
    }
    // }}}
    
    // {{{ redirectNoAction
    /**
     * Just redirect to normal page, if post-action was executed without an action selected
     * 
     * @access public
     * @return void
     **/
    public function redirectNoAction () {
      // Check if this is a worthy-call
      if (!isset ($_REQUEST ['page']) || (substr ($_REQUEST ['page'], 0, strlen (__CLASS__)) != __CLASS__))
        return;
      
      if (isset ($_REQUEST ['action']) && ($_REQUEST ['action'] == -1) &&
          isset ($_REQUEST ['action2']) && ($_REQUEST ['action2'] != -1))
        return do_action ('admin_post_' . $_REQUEST ['action2']);
      
      // Remove some parameters
      unset ($_REQUEST ['action']);
      unset ($_REQUEST ['action2']);
      
      // Redirect
      wp_redirect (admin_url ('admin.php?' . http_build_query ($_REQUEST)));
      
      exit ();
    }
    // }}}
    
    // {{{ saveUserSettings
    /**
     * Store user-preferences
     * 
     * @access public
     * @return void
     **/
    public function saveUserSettings () {
      // Retrive the ID of the current user
      $eUID = get_current_user_id ();
      
      // Store user-settings
      update_user_meta ($eUID, 'wp-worthy-auto-assign-markers', (isset ($_REQUEST ['wp-worthy-auto-assign-markers']) && ($_REQUEST ['wp-worthy-auto-assign-markers'] == 1)) ? 1 : 0);
      update_user_meta ($eUID, 'wp-worthy-disable-output', (isset ($_REQUEST ['wp-worthy-disable-output']) && ($_REQUEST ['wp-worthy-disable-output'] == 1)) ? 1 : 0);
      update_user_meta ($eUID, 'wp-worthy-default-server', (isset ($_REQUEST ['wp-worthy-default-server']) ? $_REQUEST ['wp-worthy-default-server'] : ''));
      
      // Check wheter to set user-sharing
      if (isset ($_REQUEST ['wp-worthy-account-sharing']) &&
          (($_REQUEST ['wp-worthy-account-sharing'] == 0) ||
            $GLOBALS ['wpdb']->get_row ('SELECT u.ID,m.meta_value FROM `' . $this->getTablename ('users') . '` u LEFT JOIN `' . $this->getTablename ('usermeta') . '` m ON (u.ID=m.user_id AND m.meta_key="wp-worthy-authorid") WHERE meta_value IS NULL AND ID=' . intval ($_REQUEST ['wp-worthy-account-sharing']))))
        // Update the current user
        update_user_meta ($eUID, 'wp-worthy-authorid', intval ($_REQUEST ['wp-worthy-account-sharing']));
      
      // Redirect back
      wp_redirect ($this->linkSection ($this::ADMIN_SECTION_SETTINGS, array ('displayStatus' => 'settingsSaved')));
    }
    // }}}
    
    // {{{ importMarkers
    /**
     * Import a list of markers from an uploaded CSV-File
     * 
     * @access public
     * @return void
     **/
    public function importMarkers () {
      global $wpdb;
      
      // Check all uploaded files
      $userID = $this->getUserID ();
      $files = 0;
      $records = 0;
      $created = 0;
      $existing = 0;
      $updated = 0;
      
      foreach ($_FILES as $Key=>$Info) {
        // Try to read records from this file
        if (is_resource ($f = @fopen ($Info ['tmp_name'], 'r'))) {
          if ($markers = $this->parseMarkersFromFile ($f)) {
            $files++;
            $records += count ($markers);
          }
          
          fclose ($f);
          
          // Check existing markers
          $existing_query = 'SELECT public, private FROM `' . $this->getTablename ('worthy_markers') . '` WHERE public IN (';
          
          foreach ($markers as $marker)
            $existing_query .= $wpdb->prepare ('%s,', $marker ['publicMarker']);
          
          foreach (($results = $wpdb->get_results (substr ($existing_query, 0, -1) . ')', ARRAY_N)) as $result)
            if ($result [1] != $marker ['privateMarker'])
              $updated++;
          
          $existing += count ($results);
          
          // Import the markers into database
          $create_query = 'INSERT IGNORE INTO `' . $this->getTablename ('worthy_markers') . '` (userid, public, private, server, url) VALUES ';
          
          foreach ($markers as $marker)
            $create_query .= $wpdb->prepare ('(%d, %s, %s, %s, %s), ', $userID, $marker ['publicMarker'], $marker ['privateMarker'],
             parse_url ($marker ['url'], PHP_URL_HOST), $marker ['url']);
          
          $wpdb->query (substr ($create_query, 0, -2) . ' ON DUPLICATE KEY UPDATE Private=VALUES(Private)');
          $created += $wpdb->rows_affected;
        }
        
        // Remove all informations about this upload
        @unlink ($Info ['tmp_name']);
        unset ($_FILES [$Key]);
      }
      
      // Update statistics
      if ($records > 0) {
        update_option ('worthy_markers_imported_csv', get_option ('worthy_markers_imported_csv') + $created);
        update_user_meta ($userID, 'worthy_markers_imported_csv', get_user_meta ($userID, 'worthy_markers_imported_csv', true) + $created);
      }
      
      // Check if there was anything imported
      $Parameters = array (
        'displayStatus' => 'importDone',
      );
      
      if ($files > 0) {
        $Parameters ['fileMarkerCount'] = $records;
        $Parameters ['markerExisting'] = $existing;
        $Parameters ['markerUpdated'] = $updated;
        $Parameters ['markerCreated'] = $created;
        $Parameters ['fileCount'] = $files;
      }
      
      wp_redirect ($this->linkSection ($this::ADMIN_SECTION_CONVERT, $Parameters));
      
      exit ();
    }
    // }}}
    
    // {{{ importClaimMarkers
    /**
     * Claim and import a set of anonymous markers
     * 
     * @access public
     * @return void
     **/
    public function importClaimMarkers () {
      // Check if we are subscribed to premium
      if (!$this->isPremium ())
        exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM)));
      
      // Try to access the SOAP-Client
      if (!is_object ($Client = $this->getSOAPClient ()) || !is_object ($Session = $this->getSession ()))
        exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'noSoap'))));
      
      // Process all required parameters
      $Import = (isset ($_REQUEST ['wp-worthy-claim-import']) && ($_REQUEST ['wp-worthy-claim-import'] == 1));
      
      // Collect all markers from upload
      $files = 0;
      $created = 0;
      $allMarkers = array ();
      
      foreach ($_FILES as $Key=>$Info) {
        // Try to read records from this file
        $markers = null;
        
        if (is_resource ($f = @fopen ($Info ['tmp_name'], 'r'))) {
          if ($markers = $this->parseMarkersFromFile ($f))
            $files++;
          
          fclose ($f);
        }
        
        if (!$markers)
          continue;
        
        // Just forward to complete set
        foreach ($markers as $marker)
          $allMarkers [$marker ['privateMarker']] = $marker;
        
        // Remove all informations about this upload
        @unlink ($Info ['tmp_name']);
        unset ($_FILES [$Key]);
      }
      
      // Try to claim all markers
      try {
        $Result = $Client->markersClaim ($Session, array_keys ($allMarkers));
      } catch (SOAPFault $E) {
        exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'soapException', 'faultCode' => $E->faultcode, 'faultString' => $E->faultstring))));
      }
      
      // Process the claim-result
      $claimedMarkers = array ();
      $failedMarkers = array ();
      
      foreach ($Result as $Item)
        if ($Item->Status == 'ok')
          $claimedMarkers [$Item->Private] = $allMarkers [$Item->Private];
        else
          $failedMarkers [$Item->Private] = $allMarkers [$Item->Private];
      
      // Update statistics
      if (count ($claimedMarkers) > 0) {
        update_option ('worthy_premium_markers_claimed', get_option ('worthy_premium_markers_claimed') + count ($claimedMarkers));
        update_user_meta ($userID, 'worthy_premium_markers_claimed', get_user_meta ($userID, 'worthy_premium_markers_claimed', true) + count ($claimedMarkers));
      }
      
      // Import claimed markers
      if ($Import && (count ($claimedMarkers) > 0)) {
        $create_query = 'INSERT IGNORE INTO `' . $this->getTablename ('worthy_markers') . '` (userid, public, private, server, url) VALUES ';
        $userID = $this->getUserID ();
        
        foreach ($claimedMarkers as $marker)
          $create_query .= $GLOBALS ['wpdb']->prepare ('(%d, %s, %s, %s, %s), ', $userID, $marker ['publicMarker'], $marker ['privateMarker'], parse_url ($marker ['url'], PHP_URL_HOST), $marker ['url']);
        
        $GLOBALS ['wpdb']->query (substr ($create_query, 0, -2) . ' ON DUPLICATE KEY UPDATE Private=VALUES(Private)');
        $created += $GLOBALS ['wpdb']->rows_affected;
        
        // Update statistics
        if ($created > 0) {
          update_option ('worthy_markers_imported_csv', get_option ('worthy_markers_imported_csv') + $created);
          update_user_meta ($userID, 'worthy_markers_imported_csv', get_user_meta ($userID, 'worthy_markers_imported_csv', true) + $created);
        }
      }
      
      // Check if there was anything imported
      $Parameters = array (
        'displayStatus' => 'importClaimDone',
      );
      
      if ($files > 0) {
        $Parameters ['fileCount'] = $files;
        $Parameters ['fileMarkerCount'] = count ($allMarkers);
        $Parameters ['markerClaimed'] = implode (',', array_keys ($claimedMarkers));
        $Parameters ['markerFailed'] = implode (',', array_keys ($failedMarkers));
        $Parameters ['markerCreated'] = $created;
      }
      
      wp_redirect ($this->linkSection ($this::ADMIN_SECTION_CONVERT, $Parameters));
      
      exit ();
    }
    // }}}
    
    // {{{ reportMarkers
    /**
     + Generate a list of markers
     * 
     * @access public
     * @return void
     **/
    public function reportMarkers () {
      global $wpdb;
      
      // Determine with types to export
      $unassigned = (isset ($_REQUEST ['wp-worthy-report-unused']) && ($_REQUEST ['wp-worthy-report-unused'] == 1));
      $assigned = (isset ($_REQUEST ['wp-worthy-report-used']) && ($_REQUEST ['wp-worthy-report-used'] == 1));
      $title = (isset ($_REQUEST ['wp-worthy-report-title']) && ($_REQUEST ['wp-worthy-report-title'] == 1));
      
      // Generate the query
      if ($unassigned && $assigned)
        $Where = ' WHERE 1=1';
      elseif ($assigned)
        $Where = ' WHERE NOT (postid IS NULL)';
      else
        $Where = ' WHERE postid IS NULL';
      
      // Process user-filter
      if (isset ($_REQUEST ['wp-worthy-report-filter']) && ($_REQUEST ['wp-worthy-report-filter'] == 1)) {
        if (!is_array ($_REQUEST ['wp-worthy-report-user']))
          $_REQUEST ['wp-worthy-report-user'] = array ();
        
        foreach ($_REQUEST ['wp-worthy-report-user'] as $i=>$n)
          $_REQUEST ['wp-worthy-report-user'][$i] = intval ($n);
        
        $Where .= ' AND userid IN ("' . implode ('", "', $_REQUEST ['wp-worthy-report-user']) . '")';
      }
      
      // Process premium-filter
      // We need premium here because of the marker-synchronization - without this does not make sense
      if ($isPremium = $this->isPremium ()) {
        $Status = array ();
        
        if (isset ($_REQUEST ['wp-worthy-report-premium-notqualified']))
          $Status [] = 1;
        
        if (isset ($_REQUEST ['wp-worthy-report-premium-partialqualified']))
          $Status [] = 2;
        
        if (isset ($_REQUEST ['wp-worthy-report-premium-qualified']))
          $Status [] = 3;
        
        if (isset ($_REQUEST ['wp-worthy-report-premium-reported']))
          $Status [] = 4;
        
        if (isset ($_REQUEST ['wp-worthy-report-premium-uncounted'])) {
          $Status [] = 0;
          $Where .= ' AND (status IN ("' . implode ('","', $Status) . '") OR status IS NULL)';
        } elseif (count ($Status) > 0)
          $Where .= ' AND status IN ("' . implode ('","', $Status) . '")';
      }
      
      // Load all records for export
      # TODO-AUTHOR
      $results = $wpdb->get_results ('SELECT public, private, status, postid, post_title FROM `' . $this->getTablename ('worthy_markers') . '` m LEFT JOIN `' . $this->getTablename ('posts') . '` p ON (m.postid=p.ID)' . $Where, ARRAY_N);
      
      // Generate the output
      header ('Content-Type: text/csv; charset=utf-8');
      header ('Content-Disposition: attachment; filename="wp-worthy-report-' . date ('Ymd-His') . '.csv"');
      
      static $Map = array (
        0 => 'not counted or synced',
        1 => 'not qualified',
        2 => 'partial qualified',
        3 => 'qualified',
        4 => 'reported',
      );
      
      if ($assigned) {
        echo __ ('Public Marker', $this->textDomain), ';', __ ('Private Marker', $this->textDomain), ($isPremium ? ';' . __ ('Status', $this->textDomain) : ''), ';', __ ('Post ID', $this->textDomain), ($title ? ';' . __ ('Post title', $this->textDomain) : ''), "\r\n";
        
        foreach ($results as $result) {
          if (!$title)
            unset ($result [4]);
          
          if ($isPremium)
            $result [2] = __ ($Map [$result [2]], $this->textDomain);
          else
            unset ($result [2]);
          
          echo implode (';', $result), "\r\n";
        }
      } else {
        echo __ ('Public Marker', $this->textDomain), ';', __ ('Private Marker', $this->textDomain), ($isPremium ? ';' . __ ('Status', $this->textDomain) : ''), "\r\n";
        
        foreach ($results as $result) {
          unset ($result [4]);
          
          if ($isPremium)
            $result [2] = __ ($Map [$result [2]], $this->textDomain);
          else
            unset ($result [2]);
          
          echo implode (';', $result), "\r\n";
        }
      }
      
      exit ();
    }
    // }}}
    
    // {{{ exportUnusedMarkers
    /**
     * Export and remove unused markers from our database
     * 
     * @access public
     * @return void
     **/
    public function exportUnusedMarkers () {
      // Retrive all parameters
      $Count = (isset ($_REQUEST ['wp-worthy-export-count']) ? intval ($_REQUEST ['wp-worthy-export-count']) : 0);
      $Format = (isset ($_REQUEST ['wp-worthy-export-format']) ? $_REQUEST ['wp-worthy-export-format'] : 'author');
      $UserID = $this->getUserID (true);
      
      // Check if any marker should be returned
      if ($Count == 0) {
        header ('HTTP/1.1 204 Nothing to export');
        header ('Status: 204 Nothing to export');
        exit ();
      }
      
      // Make sure the format is valid
      if (($Format != 'author') && ($Format != 'publisher')) {
        header ('HTTP/1.1 406 Invalid format selected');
        header ('Status: 406 Invalid format selected');
        exit ();
      }
      
      // Start output
      header ('Content-Type: text/csv; charset=utf-8');
      header ('Content-Disposition: attachment; filename="wp-worthy-export-' . date ('Ymd-His') . '.csv"');
      
      if ($Format == 'publisher')
        echo '"Öffentlicher Identifikationscode";Privater Identifikationscode', "\r\n";
      else
        echo ';VG WORT', "\r\n", ';Zählmarken', "\r\n", ';', "\r\n", ';Die unten angegebenen Zählmarken wurden am ', date ('d.m.Y'), ' um ', date ('H:i'), ' aus WP-Worthy exportiert.', "\r\n", ';', "\r\n";
      
      // Retrive all markers
      $markers = $GLOBALS ['wpdb']->get_results ('SELECT id, public, private, url FROM `' . $this->getTablename ('worthy_markers') . '` WHERE postid IS NULL AND userid=' . intval ($UserID) . ' LIMIT ' . $Count);
      
      // Output markers first
      $ids = array ();
      $c = 0;
      
      foreach ($markers as $marker) {
        $ids [] = intval ($marker->id);
        
        if ($Format == 'publisher')
          echo $marker->public, ';', $marker->private, "\r\n";
        else
          echo
            ';Zählmarke für HTML Texte;Zählmarke für PDF Dokumente', "\r\n",
            ++$c, ';<img src="', $marker->url, '" width="1" height="1" alt="">;<a href="', $marker->url, '?l=PDF-ADRESSE">LINK-NAME</a>', "\r\n",
            ';Privater Identifikationscode:;', $marker->private, "\r\n\r\n";
      }
      
      // Remove markers from database
      $GLOBALS ['wpdb']->query ('DELETE FROM `' . $this->getTablename ('worthy_markers') . '` WHERE id IN ("' . implode ('","', $ids) . '")');
      
      exit ();
    }
    // }}}
    
    // {{{ migratePostsPreview
    /**
     * Generate a preview of all posts to be migrated
     * 
     * @access public
     * @return void
     **/
    public function migratePostsPreview () {
      // Determine what to migrate
      $inline = (isset ($_REQUEST ['migrate_inline']) && ($_REQUEST ['migrate_inline'] == 1));
      $vgw = (isset ($_REQUEST ['migrate_vgw']) && ($_REQUEST ['migrate_vgw'] == 1));
      $vgwort = (isset ($_REQUEST ['migrate_vgwort']) && ($_REQUEST ['migrate_vgwort'] == 1));
      $wppvgw = (isset ($_REQUEST ['migrate_wppvgw']) && ($_REQUEST ['migrate_wppvgw'] == 1));
      $tlvgw = (isset ($_REQUEST ['migrate_tlvgw']) && ($_REQUEST ['migrate_tlvgw'] == 1));
      $repair_dups = (isset ($_REQUEST ['migrate_repair_dups']) && ($_REQUEST ['migrate_repair_dups'] == 1));
      
      // Collect post-ids
      if ($inline)
        $ids = $this->migrateInline (false, true);
      else
        $ids = array ();
      
      $keys = array ();
      
      if ($vgw)
        $keys ['vgwpixel'] = 'vgwpixel';
      
      if ($vgwort && ($key =  get_option ('wp_vgwortmetaname', 'wp_vgwortmarke')))  
        $keys [$key] = $key;
       
      if (count ($keys) > 0)
        $ids = array_merge ($ids, $this->migrateByMeta ($keys, false, true));
      
      if ($wppvgw)
        $ids = array_merge ($ids, $this->migrateProsodia (false, true));
      
      if ($tlvgw)
        $ids = array_merge ($ids, $this->migrateTlVGWort (false, true));
      
      // Just redirect to posts-view
      exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_POSTS, array ('migrate_inline' => ($inline ? 1 : 0), 'migrate_vgw' => ($vgw ? 1 : 0), 'migrate_vgwort' => ($vgwort ? 1 : 0), 'migrate_wppvgw' => ($wppvgw ? 1 : 0), 'migrate_tlvgw' => ($tlvgw ? 1 : 0), 'migrate_repair_dups' => ($repair_dups ? 1 : 0), 'displayPostsForMigration' => implode (',', $ids)))));
    }
    // }}}
    
    // {{{ migratePostsBulk
    /**
     * Migrate posts by using a bulk-action
     * 
     * @access public
     * @return void
     **/
    public function migratePostsBulk () {
      $this->migratePosts ($_REQUEST ['post']);
    }
    // }}}
    
    // {{{ migratePosts
    /**
     * Migrate existing VG-Wort markers to worthy
     * 
     * @access public
     * @return void
     **/
    public function migratePosts ($postids = null) {
      // Determine what to migrate
      $inline = (isset ($_REQUEST ['migrate_inline']) && ($_REQUEST ['migrate_inline'] == 1));
      $vgw = (isset ($_REQUEST ['migrate_vgw']) && ($_REQUEST ['migrate_vgw'] == 1));
      $vgwort = (isset ($_REQUEST ['migrate_vgwort']) && ($_REQUEST ['migrate_vgwort'] == 1));
      $wppvgw = (isset ($_REQUEST ['migrate_wppvgw']) && ($_REQUEST ['migrate_wppvgw'] == 1));
      $tlvgw = (isset ($_REQUEST ['migrate_tlvgw']) && ($_REQUEST ['migrate_tlvgw'] == 1));
      $posts = array (0, 0);
      $dups = array ();
      
      if (!is_array ($postids))
        $postids = null;
      
      // Migrate inline markers
      if ($inline) {
        $rc = $this->migrateInline (false, false, $postids);
        
        $posts [0] += $rc [0];
        $posts [1] += $rc [1];
        $dups = $rc [2];
      }
      
      // Migrate extensions
      $keys = array ();
      
      if ($vgw)
        $keys ['vgwpixel'] = 'vgwpixel';
      
      if ($vgwort && ($key =  get_option ('wp_vgwortmetaname', 'wp_vgwortmarke')))
        $keys [$key] = $key;
      
      if (count ($keys) > 0) {
        $rc = $this->migrateByMeta ($keys, false, false, $postids);
        
        $posts [0] += $rc [0];
        $posts [1] += $rc [1];
        $dups = array_merge ($dups, $rc [2]);
      }
      
      // Migrate Prosodia VGW
      if ($wppvgw) {
        $rc = $this->migrateProsodia (false, false, $postids);
          
        $posts [0] += $rc [0];
        $posts [1] += $rc [1];
        $dups = $rc [2];
      }
      
      // Migrate Torben Leuschners VG-Wort
      if ($tlvgw) {
        $rc = $this->migrateTlVGWort (false, false, $postids);
        
        $posts [0] += $rc [0];
        $posts [1] += $rc [1];
        $dups = $rc [2];
      }
      
      // Check wheter to re-run with repair of duplicates
      $repair_dups = (isset ($_REQUEST ['migrate_repair_dups']) && ($_REQUEST ['migrate_repair_dups'] == 1));
      
      if ((count ($dups) > 0) && $repair_dups) {
        if ($inline) {
          $rc = $this->migrateInline (true, false, $postids);
          
          $posts [1] += $rc [1];
          $dups = $rc [2];
        }
        
        if (count ($keys) > 0) {
          $rc = $this->migrateByMeta ($keys, true, false, $postids);
          
          $posts [1] += $rc [1];
          $dups = array_merge ($dups, $rc [2]);
        }
        
        if ($wppvgw) {
          $rc = $this->migrateProsodia (true, false, $postids);
          
          $posts [1] += $rc [1];
          $dups = array_merge ($dups, $rc [2]);
        }
        
        if ($tlvgw) {
          $rc = $this->migrateTlVGWort (true, false, $postids);
          
          $posts [1] += $rc [1];
          $dups = array_merge ($dups, $rc [2]);
        }
      }
      
      // Redirect to summary
      wp_redirect (admin_url ($this->linkSection (
        $this::ADMIN_SECTION_CONVERT,
        array (
          'displayStatus' => 'migrateDone',
          'migrateCount' => $posts [1],
          'totalCount' => $posts [0],
          'duplicates' => implode (',', $dups),
          'repair_dups' => ($repair_dups ? 1 : 0),
          'migrate_inline' => ($inline ? 1 : 0),
          'migrate_vgw' => ($vgw ? 1 : 0),
          'migrate_vgwort' => ($vgwort ? 1 : 0),
          'migrate_wppvgw' => ($wppvgw ? 1 : 0),
          'migrate_tlvgw' => ($tlvgw ? 1 : 0),
        )
      )));

      exit ();
    }
    // }}}
    
    // {{{ searchPrivateMarkers
    /**
     * Search for private markers
     * 
     * @access public
     * @return void
     **/
    public function searchPrivateMarkers () {
      global $wpdb;

      // Check all uploaded files
      $files = 0;
      $records = 0;
      $created = 0;
      $markers = array ();

      foreach ($_FILES as $Key=>$Info) {
        // Try to read records from this file
        if (is_resource ($f = @fopen ($Info ['tmp_name'], 'r'))) {
          $index = null;
          
          while ($rec = fgetcsv ($f, 0, ';')) {
            if ($index === null) {
              if ((count ($rec) == 1) || (($index = array_search ('Privater Identifikationscode', $rec)) === false))
                $index = 0;
              
              continue;
            }
            
            if (!isset ($markers [$rec [$index]]))
              $markers [$rec [$index]] = $wpdb->prepare ('%s', $rec [$index]);
          }
          
          $files++;
          $records += count ($markers);
        }
        
        // Remove all informations about this upload 
        @fclose ($f);
        @unlink ($Info ['tmp_name']);
        unset ($_FILES [$Key]);
      }
      
      // Search markers on database
      $ids = $wpdb->get_col ('SELECT id FROM `' . $this->getTablename ('worthy_markers') . '` WHERE private IN (' . implode (',', $markers) . ')');
      
      // Check if there was anything imported
      wp_redirect ($this->linkSection ($this::ADMIN_SECTION_MARKERS, array ('displayMarkers' =>implode (',', $ids))));

      exit ();
    }
    // }}}
    
    // {{{ reindexPosts
    /**
     * Reindex character-counter
     * 
     * @param bool $All (optional) Reindex even posts that have already a character-counter set
     * 
     * @access public
     * @return void
     **/
    public function reindexPosts ($All = null) {
      global $wpdb;
      
      // Initialize parameters
      $p = 0;
      $c = 100;
      
      $All = ($All !== null ? !!$All : (isset ($_REQUEST ['reindex_all']) && ($_REQUEST ['reindex_all'] == 1)));
      
      // Create the query
      if ($All)
        $Query = 'SELECT ID, post_content FROM `' . $this->getTablename ('posts') . '` WHERE post_type IN ("post", "page") AND post_status="publish" LIMIT %d,' . $c;
      else
        $Query = 'SELECT p.ID, p.post_content, pm.meta_value FROM `' . $this->getTablename ('posts') . '` p LEFT JOIN `' . $this->getTablename ('postmeta') . '` pm ON (p.ID=pm.post_id AND pm.meta_key="worthy_counter") WHERE post_type IN ("post", "page") AND post_status="publish" AND meta_value IS NULL LIMIT %d,' . $c;
      
      // Update the index
      while (count ($posts = $wpdb->get_results (sprintf ($Query, $p))) > 0) {
        foreach ($posts as $post)
          update_post_meta ($post->ID, 'worthy_counter', $this->getPostLength ($post));
          
          $p += count ($posts);
        }
      
      // Redirect to summary
      wp_redirect ($this->linkSection ($this::ADMIN_SECTION_SETTINGS, array ('displayStatus' => 'reindexDone', 'postCount' => $p)));
      
      exit ();
    }
    // }}}
    
    // {{{ assignPosts
    /**
     * Assign markers to a set of posts
     * 
     * @access public
     * @return void
     **/
    public function assignPosts () {
      // Check wheter to ignore this action
      if (isset ($_REQUEST ['filter_action']) && ($_REQUEST ['filter_action'] == 1))
        return $this->redirectNoAction ();
      
      // Fetch Post-IDs to assign
      $sIDs = array ();
      $fIDs = array ();
      
      foreach ((array)$_REQUEST ['post'] as $ID) {
        $ID = intval ($ID);
        
        if ($this->adminSavePost ($ID, true))
          $sIDs [] = $ID;
        else
          $fIDs [] = $ID;
      }
      
      // Push the client back
      $sendback = wp_get_referer ();
      
      if (!$sendback)
        $sendback = $this->linkSection ($this::ADMIN_SECTION_POSTS);
      
      wp_redirect (add_query_arg (array ('assigned' => implode (',', $sIDs), 'not_assigned' => implode (',', $fIDs)), $sendback));
      
      exit ();
    }
    // }}}
    
    // {{{ ignorePosts
    /**
     * Ignore a set of posts for worthy
     * 
     * @access public
     * @return void
     **/
    public function ignorePosts () {
      // Check wheter to ignore this action
      if (isset ($_REQUEST ['filter_action']) && ($_REQUEST ['filter_action'] == 1))
        return $this->redirectNoAction ();
      
      // Mark all those posts as ignored
      foreach ((array)$_REQUEST ['post'] as $ID)
        update_post_meta ($ID, 'worthy_ignore', 1);
      
      // Push the client back
      $sendback = wp_get_referer();
      
      if (!$sendback)
        $sendback = $this->linkSection ($this::ADMIN_SECTION_POSTS);
      
      wp_redirect ($sendback);
      
      exit ();
    }
    // }}}
    
    // {{{ doFeedback
    /**
     * Send feedback back to ourself
     * 
     * @access public
     * @return void
     **/
    public function doFeedback () {
      // Try to access the SOAP-Client
      if (!is_object ($Client = $this->getSOAPClient ()) || !is_object ($Session = $this->getSession ()))
        exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'noSoap'))));
      
      try {
        $Client->serviceFeedback ($Session, $_REQUEST ['worthy-feedback-mail'], $_REQUEST ['worthy-feedback-caption'], $_REQUEST ['worthy-feedback-rating'], $_REQUEST ['worthy-feedback-text']);
      } catch (Exception $E) {
      
      }
      
      exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'feedbackDone'))));
    }
    // }}}
    
    // {{{ premiumSignup
    /**
     * Sign up for worthy premium
     * 
     * @access public
     * @return void
     **/
    public function premiumSignup () {
      // Try to create a bootstrap-client
      if (!is_object ($Client = $this->getSOAPClient (false)))
        exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'noSoap'))));
      
      // Try to sign up at worthy premium
      try {
        $Result = $Client->serviceSignup ($_POST ['wp-worthy-username'], $_POST ['wp-worthy-password'], $_POST ['wp-worthy-accept-tac']);
      } catch (SOAPFault $E) {
        exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'soapException', 'faultCode' => $E->faultcode, 'faultString' => $E->faultstring))));
      }
      
      // Try to store credentials on success
      $userID = $this->getUserID ();
      
      if ($Result ['Status'] != 'unregistered') {
        $stored = (((get_user_meta ($userID, 'worthy_premium_username', true) == $_POST ['wp-worthy-username']) || update_user_meta ($userID, 'worthy_premium_username', $_POST ['wp-worthy-username'])) &&
                   ((get_user_meta ($userID, 'worthy_premium_password', true) == $_POST ['wp-worthy-password']) || update_user_meta ($userID, 'worthy_premium_password', $_POST ['wp-worthy-password'])));
        
        // Store the status
        $Result ['ValidFrom'] = strtotime ($Result ['ValidFrom']);
        $Result ['ValidUntil'] = strtotime ($Result ['ValidUntil']);
        
        update_user_meta ($userID, 'worthy_premium_status', $Result);
        update_user_meta ($userID, 'worthy_premium_status_updated', time ());
        
        // Update/Synchronize markers for the first time
        $this->updateMarkerStatus ();
      }
      
      // Redirect to status-page
      wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'signupDone', 'status' => ($Result ['Status'] == 'unregistered' ? 0 : ($stored ? 1 : -1)))));
      
      exit ();
    }
    // }}}
    
    // {{{ premiumSyncStatus
    /**
     * Synchronize our premium-subscription-status
     * 
     * @access public
     * @return void
     **/
    public function premiumSyncStatus () {
      // Just force a status-update
      $this->updateStatus (true);
      
      // Redirect to status-page
      wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'syncStatusDone')));
      
      exit ();
    }
    // }}}
    
    // {{{ premiumSyncMarkers
    /**
     * Syncronize markers with VG-Wort (Worthy Premium)
     * 
     * @access public
     * @return void
     **/
    public function premiumSyncMarkers () {
      // Check if we are subscribed to premium
      if (!$this->isPremium ())
        wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM));
      
      // Try to do the sync
      elseif (($Count = $this->updateMarkerStatus ()) === false)
        wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'syncMarkerDone', 'markerCount' => -1)));
      else
        wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'syncMarkerDone', 'markerCount' => $Count)));
      
      exit ();
    }
    // }}}
    
    // {{{ premiumImportMarkers
    /**
     * Import markers using Worthy Premium
     * 
     * @access public
     * @return void
     **/
    public function premiumImportMarkers () {
      global $wpdb;

      // Check if we are subscribed to premium
      if (!$this->isPremium ())
        exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM)));
      
      // Try to access the SOAP-Client
      if (!is_object ($Client = $this->getSOAPClient ()) || !is_object ($Session = $this->getSession ()))
        exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'noSoap'))));
      
      try {
        $Markers = $Client->markersCreate ($this->getSession (), max (1, min (100, intval ($_POST ['count']))));
      } catch (SOAPFault $E) {
        exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'soapException', 'faultCode' => $E->faultcode, 'faultString' => $E->faultstring))));
      }
      
      // Generate import-query
      $query = 'INSERT INTO `' . $this->getTablename ('worthy_markers') . '` (userid, public, private, server, url) VALUES ';
      $userID = $this->getUserID ();
      
      foreach ($Markers as $Marker)
        $query .= $wpdb->prepare ('(%d, %s, %s, %s, %s), ', $userID, $Marker->Public, $Marker->Private, parse_url ($Marker->URL, PHP_URL_HOST), $Marker->URL);
      
      // Try to import the markers into database
      if ($wpdb->query (substr ($query, 0, -2)) !== false) {
        // Update local statistics
        if (($c = $wpdb->rows_affected) > 0) {
          update_option ('worthy_premium_markers_imported', get_option ('worthy_premium_markers_imported', 0) + $c);
          update_user_meta ($userID, 'worthy_premium_markers_imported', intval (get_user_meta ($userID, 'worthy_premium_markers_imported', true)) + $c);
        }
        
        wp_redirect ($this->linkSection ($this::ADMIN_SECTION_CONVERT, array ('displayStatus' => 'premiumImportDone', 'markerCount' => $c)));
      
      // Handle errors during import
      } else {
        // Make sure that the import-directory is there
        $imDir = dirname (__FILE__) . '/import';
        
        if (!is_dir ($imDir) && @wp_mkdir_p ($imDir))
          file_put_contents ($imDir . '/index.html', ':-)');
        
        // Try to store the markers on disk
        if (is_resource ($f = @fopen ($imDir . '/' . date ('Y-m-d-H-i-s') . '_' . rand (100, 999) . '.csv', 'w'))) {
          fputcsv ($f, array ('Private', 'Public', 'URL'));
          
          foreach ($Markers as $Marker)
            fputcsv ($f, array ($Marker->Private, $Marker->Public, $Marker->URL));
          
          fclose ($f);
        }
        
        wp_redirect ($this->linkSection ($this::ADMIN_SECTION_CONVERT, array ('displayStatus' => 'databaseError')));
      }
      
      exit ();
    }
    // }}}
    
    // {{{ premiumCreateWebareas
    /**
     * Create webareas for a set of posts (Worthy Premium)
     * 
     * @access public
     * @return void
     **/
    public function premiumCreateWebareas () {
      global $wpdb;
      
      // Check if we are subscribed to premium
      if (!$this->isPremium ())
        exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM)));
      
      // Check wheter to ignore this action
      if (isset ($_REQUEST ['filter_action']) && ($_REQUEST ['filter_action'] == 1))
        return $this->redirectNoAction ();
      
      // Try to access the SOAP-Client
      if (!is_object ($Client = $this->getSOAPClient ()) || !is_object ($Session = $this->getSession ()))
        exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'noSoap'))));
      
      // Process each post
      $invalidIDs = array ();
      $failedIDs = array ();
      $successIDs = array ();

      if (!isset ($_REQUEST ['post']) || !is_array ($_REQUEST ['post']))
        $_REQUEST ['post'] = array ();

      foreach ($_REQUEST ['post'] as $PostID) {
        // Make sure the ID is an integer
        $PostID = intval ($PostID);

        // Try to retrive the post
        if (!is_object ($post = get_post ($PostID, OBJECT, 'edit'))) {
          $invalidIDs [] = $PostID;

          continue;
        }

        // Collect informations
        $Private = $wpdb->get_var ($wpdb->prepare ('SELECT private FROM `' . $this->getTablename ('worthy_markers') . '` WHERE postid="%d" LIMIT 0,1', $PostID));
        $URL = get_permalink ($PostID);

        // Issue the request
        if ($Client->webareaCreate ($Session, $Private, $URL, true))
          $successIDs [] = $PostID;
        else
          $failedIDs [] = $PostID;
      }

      // Redirect to summary
      wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'webareasDone', 'sIDs' => implode (',', $successIDs), 'fIDs' => implode (',', $failedIDs), 'iIDs' => implode (',', $invalidIDs))));

      exit ();
    }
    // }}}
    
    // {{{ premiumReportPostsPreview
    /**
     * Redirect to preview-view for post-reports
     * 
     * @access public
     * @return void
     **/
    public function premiumReportPostsPreview () {
      // Check wheter to ignore this action
      if (isset ($_REQUEST ['filter_action']) && ($_REQUEST ['filter_action'] == 1))
        return $this->redirectNoAction ();
      
      // Remove some parameters  
      unset ($_REQUEST ['action2']);
      
      // Reset some parameters
      $_REQUEST ['section'] = $this::ADMIN_SECTION_PREMIUM;
      $_REQUEST ['action'] = 'worthy_premium_report_posts_preview';
      
      // Redirect
      wp_redirect (admin_url ('admin.php?' . http_build_query ($_REQUEST)));
      
      exit ();
    }
    // }}}
    
    // {{{ premiumReportPosts
    /**
     * Report selected posts to VG-Wort (Worthy Premium)
     * 
     * @access public
     * @return void
     **/
    public function premiumReportPosts () {
      global $wpdb;
      
      // Check if we are subscribed to premium
      if (!$this->isPremium ())
        exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM)));
      
      // Check wheter to ignore this action
      if (isset ($_REQUEST ['filter_action']) && ($_REQUEST ['filter_action'] == 1))
        return $this->redirectNoAction ();
      
      // Try to access the SOAP-Client
      if (!is_object ($Client = $this->getSOAPClient ()) || !is_object ($Session = $this->getSession ()))
        exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'noSoap'))));
      
      // Process each post
      $invalidIDs = array ();
      $failedIDs = array ();
      $successIDs = array ();
      
      if (!isset ($_REQUEST ['post']) || !is_array ($_REQUEST ['post']))
        $_REQUEST ['post'] = array ();
      
      foreach ($_REQUEST ['post'] as $PostID) {
        // Make sure the ID is an integer
        $PostID = intval ($PostID);
        
        // Try to retrive the post
        if (!is_object ($post = get_post ($PostID, OBJECT, 'edit'))) {
          $invalidIDs [] = $PostID;
          
          continue;
        }
        
        // Collect informations
        $Private = $wpdb->get_var ($wpdb->prepare ('SELECT private FROM `' . $this->getTablename ('worthy_markers') . '` WHERE postid="%d" LIMIT 0,1', $PostID));
        $URL = get_permalink ($PostID);
        $Category = (get_post_meta ($post->ID, 'worthy_lyric', true) == 1 ? 'lyric' : 'default');
        $Involvement = 'author-single'; # TODO: This is hardcoded
        $InvolvementCount = 1;
        $RestrictedAccess = (strlen ($post->post_password) > 0);
        
        if (isset ($_REQUEST ['wp-worthy-title-' . $PostID]))
          $Title = $_REQUEST ['wp-worthy-title-' . $PostID];
        else
          $Title = $post->post_title;
        
        if (isset ($_REQUEST ['wp-worthy-content-' . $PostID]))
          $Content = $_REQUEST ['wp-worthy-content-' . $PostID];
        else
          $Content = apply_filters ('the_content', $post->post_content);
        
        // Issue the request
        try {
          $rc = $Client->reportCreateWithWebarea ($Session, $Private, $URL, $Title, $Content, $Category, $Involvement, $InvolvementCount, $RestrictedAccess, '', isset ($_REQUEST ['wp-worthy-content-' . $PostID]), true);
        } catch (Exception $E) {
          $rc = false;
        }
        
        if (!$rc || !$rc ['Status'])
          $failedIDs [] = $PostID;
        else
          $successIDs [] = $PostID;
      }
      
      // Redirect to summary
      wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'reportDone', 'sIDs' => implode (',', $successIDs), 'fIDs' => implode (',', $failedIDs), 'iIDs' => implode (',', $invalidIDs))));
      
      exit ();
    }
    // }}}
    
    // {{{ premiumPurchase
    /**
     * Purchase something for worthy-premium
     * 
     * @access public
     * @return void
     **/
    public function premiumPurchase () {
      // Try to access the SOAP-Client
      if (!is_object ($Client = $this->getSOAPClient ()) || !is_object ($Session = $this->getSession ()))
        exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('displayStatus' => 'noSoap'))));
      
      // Collect all goods
      $Goods = array ();
      
      foreach ($_REQUEST as $Key=>$Value)
        if (substr ($Key, 0, 15) == 'wp-worthy-good-') {
          if ($Value == 'none')
            continue;
          
          $Goods [intval (substr ($Key, 15))] = $Good = new stdClass;
          
          $Good->ID = intval (substr ($Key, 15));
          $Good->Options = array ($Option = new stdClass);
          $Option->ID = intval ($Value);
        }
      
      if (count ($Goods) == 0)
        exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('shopping' => 'isfun', 'displayStatus' => 'noGoods'))));
      
      // Setup payment
      $Payment = new stdClass;
      
      if (($Payment->Type = $_REQUEST ['wp-worthy-payment']) == 'giropay')
        $Payment->BIC = $_REQUEST ['wp-worthy-payment-giropay-bic'];
      
      // Try to start the purchase
      $Result = $Client->servicePurchaseGoods ($Session, $Goods, $Payment, $this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('shopping' => 'isfun')), $_REQUEST ['wp-worthy-accept-tac']);
      
      if ($Result ['Status'])
        exit (wp_redirect ($Result ['PaymentURL']));
      
      exit (wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM, array ('shopping' => 'isfun', 'displayStatus' => 'paymentError', 'Error' => $Result ['Message']))));
    }
    // }}}
    
    // {{{ premiumDebugSetServer
    /**
     * Change the server used for worthy premium
     * 
     * @access public
     * @return void
     **/
    public function premiumDebugSetServer () {
      // Set server and remove current status
      $userID = $this->getUserID ();
      
      update_user_meta ($userID, 'worthy_premium_server', $_REQUEST ['worthy_server']);
      delete_user_meta ($userID, 'worthy_premium_status');
      delete_user_meta ($userID, 'worthy_premium_status_updated');
      delete_user_meta ($userID, 'worthy_premium_session');
      
      // Redirect back
      wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM));
      
      exit ();
    }
    // }}}
    
    // {{{ premiumDebugDropSession
    /**
     * Just remove the current session for worthy-premium
     * 
     * @access public
     * @return void
     **/
    public function premiumDebugDropSession () {
      delete_user_meta ($this->getUserID (), 'worthy_premium_session');
      
      // Redirect back
      wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM));
      
      exit ();
    }
    // }}}
    
    // {{{ premiumDebugDropRegistration
    /**
     * Drop worthy-premium registration
     * 
     * @access public
     * @return void
     **/
    public function premiumDebugDropRegistration () {
      // Remove options
      $userID = $this->getUserID ();
      
      delete_user_meta ($userID, 'worthy_premium_username');
      delete_user_meta ($userID, 'worthy_premium_password');
      delete_user_meta ($userID, 'worthy_premium_status');
      delete_user_meta ($userID, 'worthy_premium_status_updated');
      
      // Redirect back
      wp_redirect ($this->linkSection ($this::ADMIN_SECTION_PREMIUM));
      
      exit ();
    }
    // }}}
    
    // {{{ migrateInline
    /**
     * Migrate posts to worthy that carry a marker on their content
     * 
     * @param bool $Repair (optional) Assign new marker if an existing marker could not be assigned
     * @param bool $onlyCollect (optional) Just collect post-ids that would be migrated
     * @param array $postids (optional) Only migrate Posts with this id
     * 
     * @access public
     * @return array
     **/
    public function migrateInline ($Repair = false, $onlyCollect = false, $postids = null) {
      global $wpdb;
      
      // Load all posts that seem to carry a VG-Wort URL
      if (is_array ($postids)) {
        foreach ($postids as $i=>$v)
          $postids [$i] = intval ($v);
        
        $Where = ' AND ID IN (' . implode (',', $postids) . ')';
      } else
        $Where = '';
      
      $posts = $wpdb->get_results ($q = 'SELECT ID, post_content FROM `' . $this->getTablename ('posts') . '` WHERE post_content LIKE "%http://vg%.met.vgwort.de/na/%"' . $Where);
      
      // Try to convert all posts
      $counter = 0;
      $total = 0;
      $dups = array ();
      $Markers = null;
      
      foreach ($posts as $post) {
        // Try to extract and remove the markers
        if (($post->post_content = $this->removeInlineMarkers ($post->post_content, true, $Markers)) === null)
          continue;
        
        // Check if any marker was extracted
        if (count ($Markers) == 0)
          continue;
        
        if ($onlyCollect) {
          $dups [] = $post->ID;
          
          continue;
        }
        
        // Increase the counter
        $total += count ($Markers);
        
        // Register the markers
        foreach ($Markers as $URL=>$publicMarker)
          if (($rc = $this->migrateDo ($post->ID, $publicMarker, null, null, $URL, null, null, $Repair)) === null)
            $dups [] = $post->ID;
        
        // Update the post
        if ($wpdb->update ($this->getTablename ('posts'), array ('post_content' => $post->post_content), array ('ID' => $post->ID), array ('%s'), array ('%d')))
          $counter++;
      }
      
      if ($onlyCollect)
        return $dups;
      
      return array ($total, $counter, $dups);
    }
    // }}}
    
    // {{{ removeInlineMarkers
    /**
     * Remove and extract VG-Wort markers from a given content
     * 
     * @param string $Content
     * @param bool $Extract (optional)
     * @param array &$Markers (optional)
     * 
     * @access private
     * @return string NULL if nothing was changed
     **/
    private function removeInlineMarkers ($Content, $Extract = false, &$Markers = null) {
      $p = 0;
      $c = false;
      $m = false;
      $Markers = array ();
      
      while (($p = strpos ($Content, 'src=', $p)) !== false) {
        $p += 4;
        
        // Extract URL from Tag
        if (($Content [$p] == '"') || ($Content [$p] == '"'))
          $URL = substr ($Content, $p + 1, strpos ($Content, $Content [$p], $p + 2) - $p - 1);
        else
          continue;
        
        // Check if this is a VG-Wort URL
        if ((substr ($URL, 0, 9) != 'http://vg') || (substr ($URL, 11, 18) != '.met.vgwort.de/na/'))
          continue;
        
        if (!$c)
          $c = true;
        
        // Extract public marker from URL
        if ($Extract)
          $Markers [$URL] = $this->getMarkerFromURL ($URL);
        
        // Find the whole tag
        $ps = null;
        
        for ($i = $p - 4; $i > 0; $i--)
          if ($Content [$i] == '<') {
            $ps = $i;
            break;
          }
        
        if (!$ps || (($pe = strpos ($Content, '>', $ps)) === false))
          continue;
        
        $m = true;
        
        // Remove the marker from content
        $Content = substr ($Content, 0, $ps) . substr ($Content, $pe + 1);
        $p = $ps;
      }
      
      if ($m)
        return $Content;
    }
    // }}}
    
    // {{{ migrateByMeta
    /**
     * Migrate posts that carry VG-Wort markers in a meta-field
     * 
     * @param array $Keys
     * @param bool $Repair (optional) Assign new marker if an existing marker could not be assigned
     * @param bool $onlyCollect (optional) Just collect post-ids that would be migrated
     * @param array $postids (optional) Only migrate Posts with this id
     * 
     * @access public
     * @return array
     **/
    public function migrateByMeta ($Keys, $Repair = false, $onlyCollect = false, $postids = null) {
      global $wpdb;
      
      // Make sure there are keys requested
      if (!is_array ($Keys) || (count ($Keys) == 0))
        return 0;
      
      // Generate the query
      $Query = 'SELECT meta_id, post_id, meta_value FROM `' . $this->getTablename ('postmeta') . '` WHERE meta_key IN (';
      
      foreach ($Keys as $Key)
        $Query .= $wpdb->prepare ('%s, ', $Key);
      
      $Query = substr ($Query, 0, -2) . ')';
      
      if (is_array ($postids)) {
        foreach ($postids as $i=>$v)
          $postids [$i] = intval ($v);
        
        $Query .= ' AND post_id IN (' . implode (',', $postids) . ')';
      }
      
      // Load all metas matching this keys
      $metas = $wpdb->get_results ($Query);
      
      // Convert all metas
      $metaIDs = array ();
      $dups = array ();
      
      foreach ($metas as $meta) {
        // Parse the VG-Wort-Tag
        if (!($URL = $this->getURLFromMarkerTag ($meta->meta_value)))
          continue;
        
        if ($onlyCollect) {
          $dups [] = $meta->post_id;
          
          continue;
        }
        
        $publicMarker = $this->getMarkerFromURL ($URL);
        
        $rc = $this->migrateDo ($meta->post_id, $publicMarker, null, null, $URL, null, null, $Repair);
        
        if ($rc === null)
          $dups [] = $meta->post_id;
        
        if (!$rc)
          continue;
        
        $metaIDs [] = intval ($meta->meta_id);
      }
      
      if ($onlyCollect)
        return $dups;
      
      // Remove all metas that have been converted
      $wpdb->query ('DELETE FROM `' . $this->getTablename ('postmeta') . '` WHERE meta_id IN ("' . implode ('","', $metaIDs) . '")');
      
      return array (count ($metas), count ($metaIDs), $dups);
    }
    // }}}
    
    // {{{ migrateTlVGWort
    /**
     * Migrate markers from Tl-VG-Wort
     * 
     * @param bool $Repair (optional) Assign new marker if an existing marker could not be assigned
     * @param bool $onlyCollect (optional) Just collect post-ids that would be migrated
     * @param array $postids (optional) Only migrate Posts with this id
     * 
     * @access public
     * @return array
     **/
    public function migrateTlVGWort ($Repair = false, $onlyCollect = false, $postids = null) {
      global $wpdb;
      
      // Generate the query
      $Query = 'SELECT meta_id, post_id, meta_key, meta_value FROM `' . $this->getTablename ('postmeta') . '` WHERE meta_key IN ("vgwort-public", "vgwort-private", "vgwort-user", "vgwort-domain")';
      
      if (is_array ($postids)) {
        foreach ($postids as $i=>$v)
          $postids [$i] = intval ($v);
        
        $Query .= ' AND post_id IN (' . implode (',', $postids) . ')';
      } 
      
      // Load all metas matching this keys
      $metas = $wpdb->get_results ($Query);
      
      // Group by posts
      $posts = array ();
      $map = array (
        'vgwort-public' => 'public',
        'vgwort-private' => 'private',
        'vgwort-user' => 'userid',
        'vgwort-domain' => 'server',
      );
      
      foreach ($metas as $meta) {
        // Make sure the post is initialized
        if (!isset ($posts [$meta->post_id]))
          $posts [$meta->post_id] = array (
            'public' => null,
            'private' =>  null,
            'userid' => null,
            'server' => null,
            'ids' => array (),
          );
        
        // Push the meta to post
        $posts [$meta->post_id][$map [$meta->meta_key]] = $meta->meta_value;
        
        // Remember the ID of this meta
        $posts [$meta->post_id]['ids'][] = intval ($meta->meta_id);
      }
      
      // Check if only post-ids where requested
      if ($onlyCollect)
        return array_keys ($posts);
      
      // Retrive Options
      $Options = get_option ('tl-vgwort-options', array (
        'domain' => 'vg01.met.vgwort.de',
        'limit' => 1000,
        'codes' => array (),
        'usercodes' => array (),
        'domaincodes' => array (),
      ));
      
      // Migrate all posts
      $Migrated = 0;
      $MetaIDs = array ();
      $Duplicates = array ();
      
      foreach ($posts as $postid=>$marker) {
        // Make sure there is a domain set
        if ($marker ['server'] === null) {
          if (isset ($Options ['domaincodes'][$marker ['public']]))
            $marker ['server'] = $Options ['domaincodes'][$marker ['public']];
          else
            $marker ['server'] = $Options ['domain'];
        }
        
        // Check if there is a user not set correctly
        if (($marker ['userid'] === null) && isset ($Options ['usercodes'][$marker ['public']]))
          $marker ['userid'] = $Options ['usercodes'][$marker ['public']];
        
        // Try to migrate to post
        if (($rc = $this->migrateDo ($postid, $marker ['public'], $marker ['private'], $marker ['server'], null, $marker ['userid'], null, $Repair)) === null)
          $Duplicates [] = $postid;
        elseif (!$rc)
          continue;
        
        // Increase the migration-counter
        $Migrated++;
        
        // Collect the migrated meta-ids
        $MetaIDs = array_merge ($MetaIDs, $marker ['ids']);
      }
      
      // Remove all metas that have been converted
      $wpdb->query ('DELETE FROM `' . $this->getTablename ('postmeta') . '` WHERE meta_id IN ("' . implode ('","', $MetaIDs) . '")');
      
      // Migrate spare markers
      if ($postids === null) {
        foreach ($Options ['codes'] as $public=>$private)
          $wpdb->insert (
            $this->getTablename ('worthy_markers'),
            array (
              'public' => $public,
              'private' => $private,
              'server' => (isset ($Options ['domaincodes'][$public]) ? $Options ['domaincodes'][$public] : $Options ['domain']),
              'url' => 'http://' . (isset ($Options ['domaincodes'][$public]) ? $Options ['domaincodes'][$public] : $Options ['domain']) . '/na/' . $public,
              'userid' => (isset ($Options ['usercodes'][$public]) ? $Options ['usercodes'][$public] : null),
              'disabled' => '0',
            ),
            array (
              '%s', '%s', '%s', '%s', '%d', '%d',
            )
          );
        
        // Remove the markers from TL VG-Wort
        $Options ['codes'] = $Options ['domaincodes'] = $Options ['usercodes'] = array ();
        
        // Commit the changes
        update_option ('tl-vgwort-options', $Options);
      }
      
      return array (count ($posts), $Migrated, $Duplicates);
    }
    // }}}
    
    // {{{ migrateProsodia
    /**
     * Migrate markers from prosodia VGW
     * 
     * @param bool $Repair (optional) Assign new marker if an existing marker could not be assigned
     * @param bool $onlyCollect (optional) Just collect post-ids that would be migrated
     * @param array $postids (optional) Only migrate Posts with this id
     * 
     * @access public
     * @return array
     **/
    public function migrateProsodia ($Repair = false, $onlyCollect = false, $postids = null) {
      global $wpdb;
      
      // Migrate markers without a code assigned first
      if (!$onlyCollect && ($postids === null))
        $wpdb->query (
          'INSERT IGNORE INTO `' . $this->getTablename ('worthy_markers') . '` (userid, public, private, server, disabled) ' .
          'SELECT IF(user_id>0,user_id,"' . $this->getUserID () . '") AS user_id, public_marker, private_marker, server, is_marker_disabled FROM `' . $this->getTablename ('wpvgw_markers') . '` WHERE post_id IS NULL'
        );
      
      // Try to migrate posts
      if (is_array ($postids)) {
        foreach ($postids as $i=>$v)
          $postids [$i] = intval ($v);
        
        $Where = 'post_id IN (' . implode (',', $postids) . ')';
      } else
        $Where = 'NOT (post_id IS NULL)';
      
      $total = 0;
      $counter = 0;
      $dups = array ();
      
      foreach ($wpdb->get_results ('SELECT post_id, public_marker, private_marker, server, user_id, is_marker_disabled FROM `' . $this->getTablename ('wpvgw_markers') . '` WHERE ' . $Where, ARRAY_N) as $post) {
        if ($onlyCollect) {
          $dups [] = $post [0];
          
          continue;
        }
        
        // Increate the counter
        $total++;
        
        if ($this->migrateDo ($post [0], $post [1], $post [2], $post [3], null, $post [4], $post [5], $Repair) !== null)
          $counter++;
        else
          $dups [] = $post [0];
      }
      
      if ($onlyCollect)
        return $dups;
      
      // Return the result
      return array ($total, $counter, $dups);
    }
    // }}}
    
    // {{{ migrateDo
    /**
     * Create a database-entry for migration
     * 
     * @param int $postID
     * @param string $publicMarker
     * @param string $privateMarker (optional)
     * @param string $Server (optional)
     * @param string $URL (optional)
     * @param int $userID (optional)
     * @param bool $Disabled (optional)
     * @param bool $Repair (optional) Assign new marker if an existing marker could not be assigned
     * 
     * @access private
     * @return bool
     **/
    private function migrateDo ($postID, $publicMarker, $privateMarker, $Server, $URL, $userID = null, $Disabled = null, $Repair = false) {
      global $wpdb;
      
      // Try to reconstruct some values
      if (($URL === null) && ($Server !== null) && ($publicMarker !== null))
        $URL = 'http://' . $Server . '/na/' . $publicMarker;
      elseif ((($Server === null) || ($publicMarker === null)) && ($URL !== null) && is_array ($url = parse_url ($URL))) {
        if ($Server === null)
          $Server = $url ['host'];
        
        if ($publicMarker === null)
          $publicMarker = basename ($url ['path']);
      }
      
      if (($userID === null) || ($userID < 1))
        $userID = $this->getUserID ();
      
      // Make sure the marker is on the database
      if (($privateMarker === null) && ($Server === null))
        $q = $wpdb->prepare ('INSERT IGNORE INTO `' . $this->getTablename ('worthy_markers') . '` SET userid=%d, public=%s, private=NULL, server=NULL, url=%s, disabled=%d, postid=NULL', $userID, $publicMarker, $URL, ($Disabled ? 1 : 0));
      elseif ($Server === null)
        $q = $wpdb->prepare ('INSERT IGNORE INTO `' . $this->getTablename ('worthy_markers') . '` SET userid=%d, public=%s, private=%s, server=NULL, url=%s, disabled=%d, postid=NULL', $userID, $publicMarker, $privateMarker, $URL, ($Disabled ? 1 : 0));
      else
        $q = $wpdb->prepare ('INSERT IGNORE INTO `' . $this->getTablename ('worthy_markers') . '` SET userid=%d, public=%s, private=%s, server=%s, url=%s, disabled=%d, postid=NULL', $userID, $publicMarker, $privateMarker, $Server, $URL, ($Disabled ? 1 : 0));
      
      if ($wpdb->query ($q) === false)
        return false;
      
      // Try to assign the marker to this post (this should never fail)
      if ($wpdb->query ($wpdb->prepare ('UPDATE IGNORE `' . $this->getTablename ('worthy_markers') . '` SET postid=%d WHERE public=%s AND (postid IS NULL OR postid=%d)', $postID, $publicMarker, $postID)) === false)
        return false;
      
      // Check if there was exact one match
      if ($wpdb->rows_affected == 1)
        return true;
      
      // Sanity-Check if the marker is assigned
      if ($wpdb->get_var ($wpdb->prepare ('SELECT count(*) FROM `' . $this->getTablename ('worthy_markers') . '` WHERE postid=%d AND public=%s', $postID, $publicMarker)) > 0)
        return true;
      
      if ($Repair)
        return $this->adminSavePost ($postID, true);
      
      return null;
    }
    // }}}
    
    // {{{ getURLFromMarkerTag
    /**
     * Extract URL from a VG-Wort Marker-Tag
     * 
     * @param string $Tag
     * 
     * @access private
     * @return string
     **/
    private function getURLFromMarkerTag ($Tag) {
      if (($p = strpos ($Tag, 'src=')) !== false)
        $URL = substr ($Tag, $p + 4);
      elseif (($p = strpos ($Tag, 'href=')) !== false)
        $URL = substr ($Tag, $p + 5);
      else
        return false;
      
      if (($URL [0] == '"') || ($URL [0] == "'"))
        $URL = substr ($URL, 1, strpos ($URL, $URL [0], 1) - 1);
      
      if (($p = strpos ($URL, '?')) !== false)
        $URL = substr ($URL, 0, $p);
      
      return $URL;
    }
    // }}}
    
    // {{{ getMarkerFromURL
    /**
     * Extract public marker from VG-Wort URL
     * 
     * @param string $URL
     * 
     * @access private
     * @return string
     **/
    private function getMarkerFromURL ($URL) {
      return substr ($URL, strrpos ($URL, '/') + 1);
    }
    // }}}
    
    // {{{ parseMarkersFromFile
    /**
     * Parse VG-Wort markers from a file/stream-resource
     * 
     * @param resource $fp
     * 
     * @access private
     * @return array
     **/
    private function parseMarkersFromFile ($fp) {
      $rc = array ();
      
      // Read all CSV-Records from file-pointer
      while ($rec = fgetcsv ($fp, 0, ';')) {  
        // Check if first column contains text
        if (strlen ($rec [0]) == 0)
          continue;
        
        // Retrive the number
        $num = intval ($rec [0]);
        
        if ($rec [0] != strval ($num)) {
          if ((count ($rec) == 2) && preg_match ('/^[a-zA-Z0-9]{32}$/', $rec [0]) && preg_match ('/^[a-zA-Z0-9]{32}$/', $rec [1]))
            $rc [] = array ('url' => null, 'publicCode' => $rec [0], 'privateCode' => $rec [1]);

          continue;
        }
        
        // URL with public marker
        if (!($URL = $this->getURLFromMarkerTag ($rec [1])))
          continue;
        
        // Grep public marker from URL
        $publicMarker = $this->getMarkerFromURL ($URL);
        
        // Extract private marker
        if (!($rec = fgetcsv ($fp, 0, ';')))
          return false;
        
        $privateMarker = $rec [2];
        
        // Store the result
        $rc [$num] = array ('url' => $URL, 'publicMarker' => $publicMarker, 'privateMarker' => $privateMarker);
      }
      
      return $rc;
    }
    // }}}
    
    // {{{ getSOAPClient
    /**
     * Retrive SOAP-Client for Worthy-Premium
     * 
     * @param bool $requireCredentials (optional) Only return a soap-client if login-credentials are available (default)
     * 
     * @access private
     * @return SOAPClient
     **/
    private function getSOAPClient ($requireCredentials = true) {
      // Check if SOAP-Support is available
      if (!class_exists ('SOAPClient')) {
        trigger_error ('SOAP-Extension is not insalled');
        
        return false;
      }
      
      // Retrive credentials
      $userID = $this->getUserID ();
      
      if ((!($worthy_user = get_user_meta ($userID, 'worthy_premium_username', true)) ||
           !($worthy_pass = get_user_meta ($userID, 'worthy_premium_password', true))) && 
          $requireCredentials) {
        trigger_error ('No credentials available');
        
        return false;
      }
      
      # TODO: Maybe encrypt/decrypt credentials in some way...
      
      static $Client = null;
      
      if ($Client !== null)
        return $Client;
      
      if (!($Server = get_user_meta ($userID, 'worthy_premium_server', true)) || ($Server != 'devel'))
        $URL = 'https://wp-worthy.de/api/?wsdl';
      else
        $URL = 'http://sandbox.wp-worthy.de/api/?wsdl';
      
      // Create and return the SOAP-Client
      try {
        $nClient = new SOAPClient (
          $URL,
          array (
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
            'trace' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,
          )
        );
      } catch (Exception $E) {
        trigger_error ('Could not create SOAP-Client');
        
        return false;
      }
      
      // Store the credentials on the account
      if ($worthy_user && $worthy_pass) {
        $nClient->Username = $worthy_user;
        $nClient->Password = $worthy_pass;
        
        $Client = $nClient;
      }
      
      return $nClient;
    }
    // }}}
    
    // {{{ updateStatus
    /**
     * Retrive (if neccessary) our worthy-premium status and return it
     * 
     * @param bool $Force (optional) Force an update from service
     * 
     * @access private
     * @return array
     **/
    private function updateStatus ($Force = false) {
      // Check if the status was retrived during the last hour
      $userID = $this->getUserID ();
      
      if (!$Force && (time () - get_user_meta ($userID, 'worthy_premium_status_updated', true) < $this::PREMIUM_STATUS_UPDATE_INTERVAL) && ($Status = get_user_meta ($userID, 'worthy_premium_status', true)))
        return $Status;
      
      // Try to get a handle of our SOAP-Client
      if (!($Client = $this->getSOAPClient ())) {
        trigger_error ('Could not retrive SOAP-Client');
        
        return array ('Status' => 'unregistered');
      }
      
      // Retrive the status
      try {
        $Status = $Client->serviceAccountStatus ($Client->Username, $Client->Password);
        
        // Check if we get an unregistered status - this should not happen if we have credentials stored
        if ($Status ['Status'] == 'unregistered')
          $Status = $Client->serviceSignup ($Client->Username, $Client->Password);
        
        // Convert time-stamps from result
        $Status ['ValidFrom'] = strtotime ($Status ['ValidFrom']);
        $Status ['ValidUntil'] = strtotime ($Status ['ValidUntil']);
      } catch (SOAPFault $E) {
        trigger_error ('Exception while SOAP-request');
        
        return array ('Status' => 'unregistered');
      }
      
      // Return the status directly if we are not registered
      if ($Status ['Status'] == 'unregistered') {
        trigger_error ('Not registered');
        
        return $Status;
      }
      
      // Store the status
      update_user_meta ($userID, 'worthy_premium_status', $Status);
      update_user_meta ($userID, 'worthy_premium_status_updated', time ());
      
      // Check wheter to sync marker-statuses
      if (time () - get_user_meta ($userID, 'worthy_premium_markers_updated', true) >= $this::PREMIUM_MARKER_UPDATE_INTERVAL)
        $this->updateMarkerStatus ();
      
      return $Status;
    }
    // }}}
    
    // {{{ updateMarkerStatus
    /**
     * Retrive status of markers
     * 
     * @param bool $Unreached (optional) Update markers that have not qualified yet
     * @param bool $Partial (optional) Update markers that have partial qualified
     * @param bool $Reached (optional) Update markers that have fully qualified
     * @param bool $Reported (optional) Update markers that have been reported
     * 
     * @access private
     * @return int
     **/
    private function updateMarkerStatus ($Unreached = true, $Partial = true, $Reached = true, $Reported = true) {
      global $wpdb;
      
      // Try to get a handle of our SOAP-Client
      if (!($Client = $this->getSOAPClient ()))
        return;
      
      try {
        $counter = 0;
        
        // Update markers that are not qualified
        if ($Unreached) {
          // Request from worthy premium
          $Unreached = $Client->markersSearch ($this->getSession (), false, true, false, true, false, false);
          
          // Preprocess values
          foreach ($Unreached as $k=>$v)
            $Unreached [$k] = $wpdb->prepare ('%s', $v);
          
          // Sync the database
          if (count ($Unreached) > 0) {
            $wpdb->query ('UPDATE `' . $this->getTablename ('worthy_markers') . '` SET status="1", status_date="' . time () . '" WHERE private IN (' . implode (',', $Unreached) . ') AND ((status IS NULL) OR NOT (status="1"))');
            $counter += $wpdb->rows_affected;
          }
        }
        
        // Update markers that have partial qualified
        if ($Partial) {
          // Request from worthy premium
          $Partial = $Client->markersSearch ($this->getSession (), false, true, false, false, true, false);
          
          // Preprocess values
          foreach ($Partial as $k=>$v)
            $Partial [$k] = $wpdb->prepare ('%s', $v);
          
          // Sync the database
          if (count ($Partial) > 0) {
            $wpdb->query ('UPDATE `' . $this->getTablename ('worthy_markers') . '` SET status="2", status_date="' . time () . '" WHERE private IN (' . implode (',', $Partial) . ') AND ((status IS NULL) OR NOT (status="2"))');
            $counter += $wpdb->rows_affected;
          }
        }
        
        // Update markers that have fully qualified
        if ($Reached) {
          // Request from worthy premium
          $Reached = $Client->markersSearch ($this->getSession (), false, true, false, false, false, true);
          
          // Preprocess values
          foreach ($Reached as $k=>$v)
            $Reached [$k] = $wpdb->prepare ('%s', $v);
          
          // Sync the database
          if (count ($Reached) > 0) {
            $wpdb->query ($q = 'UPDATE `' . $this->getTablename ('worthy_markers') . '` SET status="3", status_date="' . time () . '" WHERE private IN (' . implode (',', $Reached) . ') AND ((status IS NULL) OR NOT (status="3"))');
            $counter += $wpdb->rows_affected;
          }
        }
        
        // Update markers that have already been reported
        if ($Reported) {
          // Request from worthy premium
          $Reported = $Client->markersSearch ($this->getSession (), true, true, false, true, true, true);
        
          // Preprocess values
          foreach ($Reported as $k=>$v)
            $Reported [$k] = $wpdb->prepare ('%s', $v);
          
          // Sync the database
          if (count ($Reported) > 0) {
            $wpdb->query ('UPDATE `' . $this->getTablename ('worthy_markers') . '` SET status="4", status_date="' . time () . '" WHERE private IN (' . implode (',', $Reported) . ') AND ((status IS NULL) OR NOT (status="4"))');
            $counter += $wpdb->rows_affected;
          }
        }
      } catch (SOAPFault $E) {
        return;
      }
      
      // Update statistics
      if ($counter > 0) {
        $userID = $this->getUserID ();
        
        update_option ('worthy_premium_marker_updates', get_option ('worthy_premium_marker_updates', 0) + $counter);
        update_user_meta ($userID, 'worthy_premium_marker_updates', intval (get_user_meta ($userID, 'worthy_premium_marker_updates', true)) + $counter);
      }
      
      // Store the time of this update
      update_user_meta ($userID, 'worthy_premium_markers_updated', time ());
      
      return $counter;
    }
    // }}}
    
    // {{{ getSession
    /**
     * Retrive the authorization-parameter for SOAP-Calls
     * 
     * @access private
     * @return mixed
     **/
    private function getSession () {
      if (!($Client = $this->getSOAPClient ()))
        return false;
      
      // Check for a cached session
      if (is_object ($Session = get_user_meta ($this->getUserID (), 'worthy_premium_session', true)) && ($d = (time () - $Session->Last < 360))) {
        if ($d > 4) {
          $Session->Last = time ();
          
          update_user_meta ($this->getUserID (), 'worthy_premium_session', $Session);
        }
        
        return $Session->Authorization;
      }
      
      // Try to create a new session
      $Session = new stdClass;
      
      $Session->Last = time ();
      $Session->Authorization = new stdClass;
      $Session->Authorization->Username = $Client->Username;
      $Session->Authorization->Password = $Client->Password;
      
      // Try to log in
      try {
        $Result = $Client->serviceLogin ($Client->Username, $Client->Password);
        
        $Session->Authorization->SessionID = $Result;
        
        unset ($Session->Authorization->Username, $Session->Authorization->Password);
      } catch (SOAPFault $E) {
        return $Session->Authorization;
      }
      
      update_user_meta ($this->getUserID (), 'worthy_premium_session', $Session);
      
      return $Session->Authorization;
    }
    // }}}
    
    // {{{ isPremium
    /**
     * Check if we are registered for worthy premium
     * 
     * @access private
     * @return bool
     **/
    private function isPremium () {
      if (defined ('WORTHY_PREMIUM'))
        return WORTHY_PREMIUM;
      
      $Status = $this->updateStatus ();
      
      define ('WORTHY_PREMIUM', (($Status ['Status'] == 'testing') || ($Status ['Status'] == 'testing-pending') || ($Status ['Status'] == 'registered')));
      
      return WORTHY_PREMIUM;
    }
    // }}}
  }
  
  // Create a new plugin-handle
  global $wp_plugin_worthy;
  
  if (!isset ($wp_plugin_worthy) || !is_object ($wp_plugin_worthy))
    $wp_plugin_worthy = new wp_worthy;

?>