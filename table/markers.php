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
  
  if (!class_exists ('WP_List_Table'))
    require_once (ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
  
  class wp_worthy_table_markers extends WP_List_Table {
    private $Parent;
    
    // {{{ __construct
    /**
     * Setup new address table
     * 
     * @param plugin $Parent
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Parent) {
      parent::__construct (array (
        'singular' => 'worthy_marker',
        'plural' => 'worthy_markers',
        'ajax' => false,
      ));
      
      $this->Parent = $Parent;
    }
    // }}}
    
    // {{{ setupOptions
    /**
     * Setup screen-options for this table
     * 
     * @access public
     * @return void
     **/
    public static function setupOptions () {
      add_screen_option ('per_page', array ( 
        'label' => __ ('Markers', 'wp-worthy'),
        'default' => 20,
        'option' => 'wp_worthy_markers_per_page'
      ));
    }
    // }}}
    
    // {{{ setupColumns
    /**
     * Setup columns used in this table
     * 
     * @access public
     * @return array
     **/
    public static function setupColumns () {
      $columns = array (
        'cb' => '<input type="checkbox" />',
        'public' => __ ('Public Marker', 'wp-worthy'),
        'private' => __ ('Private Marker', 'wp-worthy'),
        'url' => __ ('URL', 'wp-worthy'),
        'author' => __ ('Author', 'wp-worthy'),
        'status' => __ ('Status', 'wp-worthy'),
        'postid' => __ ('Post', 'wp-worthy'),
        'postlen' => __ ('Relevant Characters', 'wp-worthy'),
        'actions' => __ ('Actions', 'wp-worthy'),
      );
      
      if (!defined ('WORTHY_PREMIUM') || !WORTHY_PREMIUM)
        unset ($columns ['cb'], $columns ['status'], $columns ['actions']);
      
      return $columns;
    }
    // }}}
    
    // {{{ get_columns
    /**
     * Retrive all columns
     * 
     * @access public
     * @return array
     **/
    public function get_columns () {
      return get_column_headers (get_current_screen ());
    }
    // }}}
    
    // {{{ get_sortable_columns
    /**
     * Retrive a list of columns on this table that are sortable
     * 
     * @access public
     * @return array 
     **/
    public function get_sortable_columns () {
      $columns = array (
        'public' => 'public',
        'private' => 'private',
        'url' => 'url',
        'author' => 'author',
        'postid' => 'postid',
        'postlen' => 'postlen',
      );
      
      if (defined ('WORTHY_PREMIUM') && WORTHY_PREMIUM)
        $columns ['status'] = 'status';
      
      return $columns;
    }
    // }}}
    
    // {{{ column_default
    /**
     * Retrive default data for any column
     * 
     * @param object $item
     * @param string $column_name
     * 
     * @access public
     * @return string
     **/
    public function column_default ($item, $column_name) {
      return $item->$column_name;
    }
    // }}}
    
    // {{{ column_cb
    /**
     * Retrive marker for bulk-actions
     * 
     * @param object $item
     * 
     * @access public
     * @return string 
     **/
    public function column_cb ($item) {
      // Check if no post is assigned or the status does not allow actions
      if (!$item->postid || ($item->status < 1) || ($item->status > 3) || ($item->postlen == null) || ($item->post_title == null))
        return '';
       
      return '<input id="cb-select-' . $item->postid . '" type="checkbox" name="post[]" value="' . $item->postid . '" />';
    }
    // }}}
    
    // {{{ column_status
    /**
     * Generate status-text for a marker
     * 
     * @param array $item
     * 
     * @access public
     * @return string
     **/
    public function column_status ($item) {
      static $Map = array (
        0 => 'not counted or synced',
        1 => 'not qualified',
        2 => 'partial qualified',
        3 => 'qualified',
        4 => 'reported',
      );
      
      return '<span class="worthy-status-' . intval ($item->status) . '">' . __ ($Map [intval ($item->status)], 'wp-worthy') . '</span>';
    }
    // }}}
    
    // {{{ column_postid
    /**
     * Retrive content for post-column
     * 
     * @access public
     * @return string
     **/
    public function column_postid ($item) {
      // Check if no post is assigned
      if (!$item->postid || ($item->post_title == null))
        return '';
      
      if (!$this->Parent)
        return $this->column_default ($item, 'postid');
      
      return $this->Parent->wpLinkPost ($item->postid);
    }
    // }}}
    
    // {{{ column_postlen
    /**
     * Retrive the number of characters for a post
     *  
     * @param object $item
     * 
     * @access public
     * @return string
     **/
    public function column_postlen ($item) {
      // Check if no post is assigned
      if (!$item->postid || ($item->post_title == null))
        return '';
      
      $length = $item->postlen;
      
      return sprintf (__ ('%d chars', 'wp-worthy'), $length);
    }
    // }}}
    
    // {{{ column_actions
    /**
     * Generate content of action-column for a marker
     * 
     * @param array $item
     * 
     * @access public
     * @return string
     **/
    public function column_actions ($item) {
      // Check if no post is assigned or the status does not allow actions
      if (!$item->postid || ($item->status < 1) || ($item->status > 3) || ($item->post_title == null))
        return '';
      
      $Links =
        '<li><a href="#" onclick="worthy_bulk_single(\'worthy_premium_create_webareas\', \'' . $item->postid . '\');">' . __ ('Create webarea', 'wp-worthy') . '</a></li>';
      
      if (($item->status == 3) || (($item->status == 2) && ($item->postlen > 9999)))
        $Links .=
          '<li><a href="#" onclick="worthy_bulk_single(\'worthy_premium_report_posts_preview\', \'' . $item->postid . '\');">' . __ ('Preview report for VG-Wort', 'wp-worthy') . '</a></li>' .
          '<li><a href="#" onclick="worthy_bulk_single(\'worthy_premium_report_posts\', \'' . $item->postid . '\');">' . __ ('Report directly to VG-Wort', 'wp-worthy') . '</a></li>';
      
      return '<ul>' . $Links . '</ul>';
    }
    // }}}
    
    // {{{ get_bulk_actions
    /**
     * Retrive a list of all bulk-actions
     *    
     * @access public
     * @return array
     **/
    public function get_bulk_actions () {
      if (defined ('WORTHY_PREMIUM') && WORTHY_PREMIUM)
        return array (
          'worthy_premium_report_posts_preview' => __ ('Report with preview', 'wp-worthy'),
          'worthy_premium_report_posts' => __ ('Report without preview', 'wp-worthy'),
          'worthy_premium_create_webareas' => __ ('Create webareas', 'wp-worthy'),
        );
      
      return parent::get_bulk_actions ();
    }   
    // }}}
    
    // {{{ extra_tablenav
    /**
     * Output additional filters for navigation
     * 
     * @access public
     * @return void
     **/
    public function extra_tablenav ($which) {
      if ($which != 'top')
        return;
      
      echo
        '<div class="alignleft actions">';
      
      if (count ($Users = $GLOBALS ['wpdb']->get_results ('SELECT m.userid, u.display_name FROM `' . $this->Parent->getTablename ('worthy_markers')  . '` m, `' . $this->Parent->getTablename ('users')  . '` u WHERE m.userid=u.ID GROUP BY userid')) > 1) {
        $uid = (isset ($_REQUEST ['worthy-filter-author']) ? intval ($_REQUEST ['worthy-filter-author']) : -1);
        
        echo
          '<select name="worthy-filter-author">',
            '<option value="-1">', __ ('Display all authors', 'wp-worthy'), '</option>';
        
        foreach ($Users as $User)
          echo '<option value="', $User->userid, '"', ($uid == $User->userid ? ' selected="1"' : ''), '>', $User->display_name, '</option>';
        
        echo '</select>';
      }
      
      if (defined ('WORTHY_PREMIUM') && WORTHY_PREMIUM)
        echo
          '<select name="worthy-filter-marker">',
            '<option value="-1">', __ ('Display all marker-stati', 'wp-worthy'), '</option>',
            '<option value="0"', (isset ($_REQUEST ['worthy-filter-marker']) && ($_REQUEST ['worthy-filter-marker'] == '0') ? ' selected="1"' : ''), '>', __ ('not counted or synced', 'wp-worthy'), '</option>',
            '<option value="1"', (isset ($_REQUEST ['worthy-filter-marker']) && ($_REQUEST ['worthy-filter-marker'] == '1') ? ' selected="1"' : ''), '>', __ ('not qualified', 'wp-worthy'), '</option>',
            '<option value="2"', (isset ($_REQUEST ['worthy-filter-marker']) && ($_REQUEST ['worthy-filter-marker'] == '2') ? ' selected="1"' : ''), '>', __ ('partial qualified', 'wp-worthy'), '</option>',
            '<option value="3"', (isset ($_REQUEST ['worthy-filter-marker']) && ($_REQUEST ['worthy-filter-marker'] == '3') ? ' selected="1"' : ''), '>', __ ('qualified', 'wp-worthy'), '</option>',
            '<option value="4"', (isset ($_REQUEST ['worthy-filter-marker']) && ($_REQUEST ['worthy-filter-marker'] == '4') ? ' selected="1"' : ''), '>', __ ('reported', 'wp-worthy'), '</option>',
          '</select>';
      
      echo
          '<select name="worthy-filter-ignored">',
            '<option value="0"', (isset ($_REQUEST ['worthy-filter-ignored']) && ($_REQUEST ['worthy-filter-ignored'] == '0') ? ' selected="1"' : ''), '>', __ ('Display all markers', 'wp-worthy'), '</option>',
            '<option value="1"', (isset ($_REQUEST ['worthy-filter-ignored']) && ($_REQUEST ['worthy-filter-ignored'] == '1') ? ' selected="1"' : ''), '>', __ ('All Markers with posts assigned', 'wp-worthy'), '</option>',
            '<option value="2"', (isset ($_REQUEST ['worthy-filter-ignored']) && ($_REQUEST ['worthy-filter-ignored'] == '2') ? ' selected="1"' : ''), '>', __ ('Markers with posts assigned that are not ignored', 'wp-worthy'), '</option>',
            '<option value="3"', (isset ($_REQUEST ['worthy-filter-ignored']) && ($_REQUEST ['worthy-filter-ignored'] == '3') ? ' selected="1"' : ''), '>', __ ('Markers with posts assigned that are ignored', 'wp-worthy'), '</option>',
          '</select>',
          '<button type="submit" class="button action" name="ignore_action" value="1">', __ ('Filter'), '</button>';
      
      echo '</div>';
    }
    // }}}
    
    // {{{ prepare_items
    /**
     * Preload all items displayed on this table
     * 
     * @access public
     * @return void
     **/
    public function prepare_items () {
      global $wpdb;
      
      $per_page = $this->get_items_per_page ('wp_worthy_markers_per_page');
      $page = $this->get_pagenum ();
      
      $sort_field = 'ID';
      $sort_order = 'DESC';
      
      if (isset ($_REQUEST ['orderby']) && in_array ($_REQUEST ['orderby'], array_keys ($this->get_sortable_columns ())))
        $sort_field = $_REQUEST ['orderby'];
      
      if (isset ($_REQUEST ['order']) && in_array ($_REQUEST ['order'], array ('asc', 'desc')))
        $sort_order = $_REQUEST ['order'];
      
      $Where = ' WHERE 1=1';
      
      if (isset ($_REQUEST ['displayMarkers'])) {
        $Markers = explode (',', $_REQUEST ['displayMarkers']);
        
        foreach ($Markers as $i=>$Marker)
          $Markers [$i] = intval ($Marker);
        
        $Where .= ' AND id IN (' . implode (',', $Markers) . ')';
      }
      
      if (isset ($_REQUEST ['status_since']) && is_numeric ($_REQUEST ['status_since']))
        $Where .= ' AND status_date>' . intval ($_REQUEST ['status_since']);
      
      if (isset ($_REQUEST ['worthy-filter-author']) && ($_REQUEST ['worthy-filter-author'] >= 0))
        $Where .= ' AND userid="' . intval ($_REQUEST ['worthy-filter-author']) . '"';
      
      if (isset ($_REQUEST ['worthy-filter-marker']) && ($_REQUEST ['worthy-filter-marker'] >= 0))
        $Where .= ' AND (status="' . intval ($_REQUEST ['worthy-filter-marker']) . '"' . ($_REQUEST ['worthy-filter-marker'] == 0 ? ' OR status IS NULL' : '') . ')';
      
      if (isset ($_REQUEST ['worthy-filter-ignored']) && ($_REQUEST ['worthy-filter-ignored'] > 0)) {
        $Where .= ' AND NOT (p.post_title IS NULL)';
        
        if ($_REQUEST ['worthy-filter-ignored'] > 1) {
          $Where = ' LEFT JOIN `' . $this->Parent->getTablename ('postmeta') . '` pmi ON (m.postid=pmi.post_id AND pmi.meta_key="worthy_ignore")' . $Where;
          
          if ($_REQUEST ['worthy-filter-ignored'] == 2)
            $Where .= ' AND (pmi.meta_value IS NULL OR pmi.meta_value="0")';
          else
            $Where .= ' AND pmi.meta_value="1"';
        }
      }
      
      if (isset ($_REQUEST ['s']) && (strlen (trim ($_REQUEST ['s'])) > 0))
        $Where .= $wpdb->prepare (' AND (private LIKE "%%%%%s%%%%" OR public LIKE "%%%%%s%%%%")', trim ($_REQUEST ['s']), trim ($_REQUEST ['s']));
      
      $this->items = $wpdb->get_results (sprintf (
        'SELECT SQL_CALC_FOUND_ROWS m.*, p.post_title, CONVERT(pm.meta_value, UNSIGNED INTEGER) AS postlen, u.display_name AS author ' .
        'FROM `' . $this->Parent->getTablename ('worthy_markers')  . '` m ' .
        'LEFT JOIN `' . $this->Parent->getTablename ('posts')  . '` p ON (m.postid=p.ID) ' .
        'LEFT JOIN `' . $this->Parent->getTablename ('postmeta') . '` pm ON (m.postid=pm.post_id AND pm.meta_key="worthy_counter")' .
        'LEFT JOIN `' . $this->Parent->getTablename ('users') . '` u ON (m.userid=u.ID)' .
        $Where . ' ' .
        'ORDER BY %s %s ' .
        'LIMIT %d,%d',
        $sort_field, $sort_order, ($page - 1) * $per_page, $per_page
      ));
      
      $this->set_pagination_args (array (
        'total_items' => $wpdb->get_var ('SELECT FOUND_ROWS()'),
        'per_page' => $per_page,
        'total_pages' => ceil ($total / $per_page),
      ));
    }
    // }}}
  }
  
  add_filter ('set-screen-option', function ($status, $option, $value) { if ($option == 'wp_worthy_markers_per_page') return $value; return $status; }, 10, 3);

?>