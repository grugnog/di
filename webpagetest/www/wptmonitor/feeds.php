<link rel="stylesheet" href="css/pagestyle.css" type="text/css">
  <style type="text/css">
    body{background-color: #ffffff;}
    #feeds {background-color: #302f2f; color: #fff; width: 100%; border-collapse: collapse; table-layout: fixed;}
  </style>
<script type="text/javascript">
     function showFeeds(column)
     {
         var label = document.getElementById("show_more_feeds_" + column);
         label.parentNode.removeChild( label );

         document.getElementById("more_feeds_" + column).className = "";
         return false;
     }
 </script>
<?php
      $feeds = null;
      if( is_file('temp/feeds.dat') )
          $feeds = json_decode(file_get_contents('temp/feeds.dat'), true);

      if( count($feeds) && !defined('BARE_UI') )
      {
          echo '<table id="feeds" width="100%"><tr>';

          // display the column headers
          foreach( $feeds as $title => &$feed )
              echo "<th>$title</th>";
          echo '</tr><tr>';

          // display the feed contents
          $column = 0;
          foreach( $feeds as $title => &$feed )
          {
              $column++;
              echo '<td><ul>';

              // keep a list of titles so we only display the most recent (for threads)
              $titles = array();

              $count = 0;
              $extended = false;
              foreach( $feed as $time => &$item )
              {
                  if( $count <= 25 )
                  {
                      $dupe = false;
                      foreach( $titles as $title )
                          if( $title == $item['title'] )
                              $dupe = true;

                      if( !$dupe )
                      {
                          $count++;
                          if( $count == 6 )
                          {
                              $extended = true;
                              echo "<li id=\"show_more_feeds_$column\"><a href=\"javascript:void(o)\" onclick=\"showFeeds($column)\">more...</a></li><li class=\"morefeeds\" ><div id=\"more_feeds_$column\" class=\"hidden\"><ul>";
                          }

                          $titles[] = $item['title'];
                          echo "<li><a title=\"{$item['source']}\" target='_blank' href=\"{$item['link']}\">{$item['title']}</a></li>";
                      }
                  }
              }

              unset($titles);

              if( $extended )
                  echo '</ul></div></li>';

              echo '</ul></td>';
          }
          echo '</tr></table>';
      }
  ?>