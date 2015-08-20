<?PHP

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
  
  // Avoid loading this twice
  if (class_exists ('qcWP'))
    return;
  
  abstract class qcWp {
    /* Plugin-Path */
    protected $pluginPath = null;
    
    /* Localization */
    protected $textDomain = null;
    
    /* Informations about plugin-pages */
    private $onPluginPage = false;
    private $pluginPages = array ();
    private $pluginPageHandlerInstalled = false;
    
    /* Registered admin-menus */
    private $adminMenus = array ();
    private $adminHandlerInstalled = false;
    
    /* Registered widgets */
    private $widgets = array ();
    private $widgetHandlerInstalled = false;
    
    /* Registered styles and scripts */
    private $Stylesheets = array ();
    private $Scripts = array ();
    private $scriptHandlerInstalled = false;
    
    /* Registered short-codes */
    private $Shortcodes = array ();
    private $shortcodeHandlerInstalled = false;
    
    /* Registered tables */
    private $Tables = array ();
    
    // {{{ __construct
    /**
     * Create a new wordpress-plugin
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Path = null) {
      // Check for an text-domain
      if ($Path === null)
        $Path = dirname ( __FILE__ );
      elseif (is_file ($Path))
        $Path = dirname ($Path);
      
      $this->pluginPath = $Path;
      $bN = basename ($Path);
      
      if (is_dir ($Path . '/lang'))
        $this->textDomain = $bN;
      
      // Register installation-hooks
      register_activation_hook ($Path . '/' . $bN . '.php', array ($this, 'onActivate'));
      register_deactivation_hook ($Path . '/' . $bN . '.php', array ($this, 'onDeactivate'));
      
      // Register runtime-hooks
      add_action ('init', array ($this, 'onInit'));
    }
    // }}}
    
    // {{{ onActivate
    /**
     * Common installation stuff
     * 
     * @access public
     * @return void
     **/
    public function onActivate () {
      $this->checkTables ();
    }
    // }}}
    
    // {{{ onDeactivate
    /**
     * Common deinstallation stuff
     * 
     * @access public
     * @return void
     **/
    public function onDeactivate () { }
    // }}}
    
    // {{{ onInit
    /**
     * Wordpress is being initialized
     * 
     * @access public
     * @return void
     **/
    public function onInit () {
      // Check if we have language-files available
      if ($this->textDomain)
        load_plugin_textdomain ($this->textDomain, false, $this->textDomain . '/lang');
    }
    // }}}
    
    // {{{ onEnqueueScripts
    /**
     * Action: Install all registered scripts and stylesheets
     * 
     * @access public
     * @return void  
     **/
    public function onEnqueueScripts () {
      // Enqueue all queued stylesheets
      foreach ($this->Stylesheets as $ID=>$File)
        wp_enqueue_style (get_class ($this) . '-' . $ID, $File, array (), false, 'all');
      
      // Enqueue all queued scripts
      foreach ($this->Scripts as $ID=>$Info) {
        wp_enqueue_script (get_class ($this) . '-' . $ID, $Info ['File'], false);
        
        foreach ($Info ['l10n'] as $k=>$v)
          $Info ['l10n'][$k] = __ ($v, $this->textDomain);
        
        if ($Info ['l10nVarname'] && (count ($Info ['l10n']) > 0))
          wp_localize_script (get_class ($this) . '-' . $ID, $Info ['l10nVarname'], $Info ['l10n']);
      }
    }
    // }}}
    
    // {{{ onAdminMenu
    /**
     * Install all queued admin-menus
     * 
     * @access public
     * @return void  
     **/
    public function onAdminMenu () {
      foreach ($this->adminMenus as $Menu) {
        if ((substr ($Menu [6], -4, 4) == '.svg') && ($Content = @file_get_contents ($Menu [6])))
          $Menu [6] = 'data:image/svg+xml;base64,' . base64_encode ($Content);
        
        $hook = add_object_page (
          __ ($Menu [0], $this->textDomain),
          __ ($Menu [1], $this->textDomain),
          $Menu [2],
          $Menu [3],
          $Menu [4],
          $Menu [6]
        );
        
        if ($Menu [5] !== null)
          add_action ('load-' . $hook, $Menu [5]);
        
        if (is_array ($Menu [7]))
          foreach ($Menu [7] as $Child) {
            $hook = add_submenu_page (
              $Menu [3],
              __ ($Child [0], $this->textDomain),
              __ ($Child [1], $this->textDomain),
              $Child [2],
              $Child [3],
              $Child [4]
            );
            
            if ($Child [5])
              add_action ('load-' . $hook, $Child [5]);
            elseif ($Menu [5] !== null)
              add_action ('load-' . $hook, $Menu [5]);
          }
      }
    }  
    // }}}
    
    // {{{ addStylesheet
    /**
     * Register a stylesheet for this plugin
     * 
     * @param string $Path
     * 
     * @access protected
     * @return void
     **/
    protected function addStylesheet ($Path) {
      // Make sure the path points to a real file
      if ((strpos ($Path, '://') === false) && !is_file ($Path))
        $Path = untrailingslashit (plugins_url ($Path, $this->pluginPath . '/qcWp.php'));
      
      $this->Stylesheets [] = $Path;
      
      // Check wheter to install the script-handler
      if ($this->scriptHandlerInstalled)
        return;
      
      $this->scriptHandlerInstalled = true;
      
      add_action ('wp_enqueue_scripts', array ($this, 'onEnqueueScripts'));
      add_action ('admin_enqueue_scripts', array ($this, 'onEnqueueScripts'));
    }
    // }}}
    
    // {{{ addScript
    /**
     * Enqueue a script
     * 
     * @param stirng $Path
     * 
     * @access protected
     * @return void
     **/
    protected function addScript ($Path, $l10n = array (), $l10nVarname = null) {
      // Make sure the path points to a real file
      if ((strpos ($Path, '://') === false) && !is_file ($Path))
        $Path = untrailingslashit (plugins_url ($Path, $this->pluginPath . '/qcWp.php'));
      
      $this->Scripts [] = array (
        'File' => $Path,
        'l10n' => $l10n,
        'l10nVarname' => $l10nVarname,
      );
      
      // Check wheter to install the script-handler
      if ($this->scriptHandlerInstalled)
        return;
      
      $this->scriptHandlerInstalled = true;
      
      add_action ('wp_enqueue_scripts', array ($this, 'onEnqueueScripts'));
      add_action ('admin_enqueue_scripts', array ($this, 'onEnqueueScripts'));
    }
    // }}}
    
    // {{{ addAdminMenu
    /**
     * Register a handler for admin-menu
     * 
     * @param string $Caption
     * @param string $Title
     * @param string $Capability
     * @param string $Slug
     * @param string $Icon
     * @param callable $Handler
     * @param callable $updateHandler (optional)
     * @param array $Children (optional)
     * 
     * @access portected
     * @return bool
     **/
    protected function addAdminMenu ($Caption, $Title, $Capability, $Slug, $Icon, $Handler, $updateHandler = null, $Children = null) {
      // Check if we are on administrator
      if (!is_admin ())
        return false;
      
      // Make sure the icon points to a real file
      if (!is_file ($Icon))
        $Icon = untrailingslashit (plugins_url ('', $this->pluginPath . '/qcWp.php')) . '/' . $Icon;
      
      // Register the menu
      $this->adminMenus [$Slug] = array ($Caption, $Title, $Capability, $Slug, $Handler, $updateHandler, $Icon, $Children);
      
      // Check wheter to register a handler for this
      if ($this->adminHandlerInstalled)
        return true;
      
      $this->adminHandlerInstalled = true;
      
      add_action ('admin_menu', array ($this, 'onAdminMenu'));
      
      return true;
    }
    // }}}
    
    // {{{ addWidget
    /**
     * Register a widget for this plugin
     * 
     * @param string $Classname
     * 
     * @access protected
     * @return bool
     **/
    protected function addWidget ($Classname) {
      if (!class_exists ($Classname))
        return false;
      
      if (!is_subclass_of ($Classname, 'WP_Widget'))
        return false;
      
      $this->widgets [] = $Classname;
      
      if (!$this->widgetHandlerInstalled) {
        add_action ('widgets_init', array ($this, 'installWidgets'));
        
        $this->widgetHandlerInstalled = true;
      }
      
      return true;
    }
    // }}}
    
    // {{{ addShortcode
    /**
     * Register a short-code-handler
     * 
     * @param string $Shortcode
     * @param callback $Callback
     * 
     * @access protected
     * @return bool
     **/
    protected function addShortcode ($Shortcode, $Callback) {
      // Validate the callback
      if (!is_callable ($Callback)) {
        $Callback = array ($this, $Callback);
        
        if (!is_callable ($Callback))
          return false;
      }
      
      $this->Shortcodes [$Shortcode] = $Callback;
      
      if ($this->shortcodeHandlerInstalled)
        return true;
      
      add_action ('plugins_loaded', array ($this, 'activateShortcodes'), 1);
      $this->shortcodeHandlerInstalled = true;
      
      return true;
    }
    // }}}
    
    // {{{ activateShortcodes
    /**
     * Activate all registered shortcodes
     * 
     * @access public
     * @return void
     **/
    public function activateShortcodes () {
      foreach ($this->Shortcodes as $Code=>$Handler)
        add_shortcode ($Code, $Handler);
    }
    // }}}
    
    // {{{ installWidgets
    /**
     * Install all registered widgets
     * 
     * @access public
     * @return void
     **/
    public function installWidgets () {
      foreach ($this->widgets as $Widget)
        register_widget ($Widget);
    }
    // }}}
    
    // {{{ setURLHandler
    /**
     * Redirect a custom URL to this plugin
     * 
     * @param string $URL
     * @param callable $Handler
     * 
     * @access protected
     * @return bool
     **/
    protected function setURLHandler ($URL, $Handler) {
      if (!is_callable ($Handler))
        return false;
      
      $this->pluginPages [$URL] = $Handler;
      
      if (!$this->pluginPageHandlerInstalled) {
        // Make sure claimed URLs are not rewritten
        add_filter ('redirect_canonical', array ($this, 'checkPluginPageURL'));
        
        // Make sure claimed URLs are processed here
        add_action ('parse_request', array ($this, 'claimPluginPage'));
        add_action ('wp', array ($this, 'handleClaimedRequest'));
        
        $this->pluginPageHandlerInstalled = true;
      }
      
      return true;
    }
    // }}}
    
    // {{{ checkPluginPageURL
    /**
     * Filter: Check if an URL to be rewritten is claimed by this plugin (and stop rewrite-process)
     * 
     * @param string $redirect_url
     * 
     * @access public
     * @return bool
     **/
    public function checkPluginPageURL ($redirect_url) {
      $base = get_site_url ();
      
      foreach ($this->pluginPages as $url=>$Handler)
        if ($base . '/' . $url == $redirect_url)
          return false;
    }
    // }}}
    
    // {{{ claimPluginPage
    /**
     * Action: Check if an incoming request matches an URL claimed by this plugin
     * 
     * @param object $wp
     * 
     * @access public
     * @return void
     **/
    public function claimPluginPage ($wp) {
      if (!isset ($this->pluginPages [$wp->request]))
        return;
      
      $this->onPluginPage = true;
      
      $wp->query_vars = array ('name' => '__plugin_page', 'page' => '');
      $wp->query_string = '';
      $wp->matched_rule = '([^/]+)(/[0-9]+)?/?$';
      $wp->matched_query = 'name=__plugin_page&page=';
    }
    // }}}
    
    // {{{ handleClaimedRequest
    /**
     * Action: Handle a request claimed by this plugin
     * 
     * @param object $wp
     * 
     * @access public
     * @return void
     **/
    public function handleClaimedRequest ($wp) {
      // Check if we are on page claimed by this plugin
      if (!$this->onPluginPage || !isset ($this->pluginPages [$wp->request]))
        return;
      
      // Dispatch the request
      $Handle = new WP_Post ((object)array ('ID' => 0xffffffff, 'post_type' => 'page', 'filter' => 'raw', 'comment_status' => 'closed', 'ping_status' => 'closed'));
      call_user_func ($this->pluginPages [$wp->request], $Handle);
      
      // Reset the HTTP-Status-Code
      header ('HTTP/1.1 200 Ok');
      
      // Override Query-Settings
      global $wp_query;
      
      $wp_query->is_page = true;
      $wp_query->is_404 = false;
      $wp_query->post_count = 1;
      $wp_query->posts = array ($Handle);
    }
    // }}}
    
    // {{{ wpLinkPost
    /**
     * Create a link to a post or page
     * 
     * @param mixed $Post
     * @param bool $frontend (optional) Should this be a link to the frontend
     * 
     * @access public
     * @return string
     **/
    public function wpLinkPost ($post, $frontend = null) {
      // Check where to link to
      if ($frontend === null)
        $frontend = !is_admin ();
      elseif (!is_admin ())
        $frontend = true;
      
      // Collect all information
      if (is_object ($post)) {
        $postID = $post->ID;
        $postTitle = $post->post_title;
      } else {
        $postID = $post;
        $postTitle = get_the_title ($post);
      }
      
      if ($postID < 1)
        return false;
      
      // Generate the link
      if ($frontend)
        $url = '';
      else
        $url = get_admin_url () . 'post.php?post=' . $postID . '&action=edit';
      
      // Return the HTML
      return '<a href="' . $url . '">' . $postTitle . (!$frontend ? ' (' . $postID . ')' : '') . '</a>';
    }
    // }}}
    
    // {{{ getTablename
    /**
     * Retrive the name of a table on wordpress database
     * 
     * @param string $Name
     * 
     * @access protected
     * @return string
     **/
    public function getTablename ($Name) {
      global $wpdb;
      static $Prefix = null;
      
      if ($Prefix === null)
        $Prefix = (is_multisite () ? $wpdb->prefix : $wpdb->base_prefix);
      
      return $Prefix . $Name;
    }
    // }}}
    
    // {{{ registerTable
    /**
     * Register a table as required for this plugin
     * 
     * @param string $Name 
     * @param array $Schema
     * @param array $Primary (optional)
     * @param array $Indexes (optional)
     * @param array $Uniques (optional)
     * @param int $Version (optional)
     * 
     * @access protected
     * @return void
     **/
    protected function registerTable ($Name, $Schema, $Primary = array (), $Indexes = array (), $Uniques = array (), $version = null) {
      $this->Tables [$Name] = array ($Schema, $Primary, $Indexes, $Uniques, $version);
      
      if (count ($this->Tables) == 1)
        add_action ('plugins_loaded', array ($this, 'checkTables'));
    }
    // }}}
    
    // {{{ checkTables
    /**
     * Callback: Check if all required tables exist
     * 
     * @access public
     * @return void
     **/
    public function checkTables () {
      foreach ($this->Tables as $Name=>$Table) {
        if (($Table [4] !== null) && (get_option ('db-' . $Name, false) == $Table [4]))
          continue;
        
        $this->assureTable ($Name, $Table [0], $Table [1], $Table [2], $Table [3], $Table [4]);
      }
    }
    // }}}
    
    // {{{ assureTable
    /**
     * Make sure a given table exists on the database
     * 
     * @param string $Name
     * @param array $Schema
     * @param array $Primary (optional)
     * @param array $Indexes (optional)
     * @param array $Uniques (optional)
     * @param int $Version (optional)
     * 
     * @access protected
     * @return bool
     **/
    protected function assureTable ($Name, $Schema, $Primary = array (), $Indexes = array (), $Uniques = array (), $version = null) {
      global $wpdb;
      
      // Check if the table exists already (we assume that the schema is correct if it does)
      if ($wpdb->get_var ('SHOW TABLES LIKE "' . $Name . '"') == $Name)
        if (($version === null) || ($version == get_option ('db-' . $Name, false)))
          return null;
      
      // Create the table
      require_once (ABSPATH . 'wp-admin/includes/upgrade.php');
      
      $Query = 'CREATE TABLE `' . $Name . '` (' . "\n";
      $AI = false;
      
      foreach ($Schema as $Field=>$Definition) {
        $Query .= '`' . $Field . '` ';
        $Params = explode (':', strtolower ($Definition));
        
        switch ($Params [0]) {
          case 'int':
            $Query .= 'int' . (in_array ('unsigned', $Params) ? '(10) UNSIGNED' : '(11)') . (in_array ('null', $Params) ? ' DEFAULT NULL' : ' NOT NULL') . (!$AI && ($AI = in_array ($Field, $Primary)) ? ' AUTO_INCREMENT' : '') . ',' . "\n";
            break;
          
          case 'string':
            $Query .= 'varchar(' . (isset ($Params [1]) ? $Params [1] : 32) . ')' . (in_array ('null', $Params) ? ' DEFAULT NULL' : ' NOT NULL') . ',' . "\n";
            break;
          
          default:
            return false;
        }
      }
      
      if (count ($Primary) > 0)
        $Query .= 'PRIMARY KEY  (' . implode (',', $Primary) . '),' . "\n";
      
      if (count ($Indexes) > 0)
        foreach ($Indexes as $Index)
          $Query .= 'KEY ' . implode ('_', $Index) . ' (' . implode (',', $Index) . '),' . "\n";
      
      if (count ($Uniques) > 0)
        foreach ($Uniques as $Index)
          $Query .= 'UNIQUE KEY ' . implode ('_', $Index) . ' (' . implode (',', $Index) . '),' . "\n";
      
      $Query = substr ($Query, 0, -2) . "\n" . ')';
      
      dbDelta ($Query);
      
      if ($version !== null) {
        if (get_option ('db-' . $Name, false) !== false)
          update_option ('db-' . $Name, $version);
        else
          add_option ('db-' . $Name, $version, null, 'yes');
      }
      
      return true;
    }
    // }}}
  }

?>