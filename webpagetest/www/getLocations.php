<?php
include 'common.inc';
$remote_cache = array();
if ($CURL_CONTEXT !== false) {
  curl_setopt($CURL_CONTEXT, CURLOPT_CONNECTTIMEOUT, 30);
  curl_setopt($CURL_CONTEXT, CURLOPT_TIMEOUT, 30);
}

// load the locations
$locations = LoadLocations();

// get the backlog for each location
foreach( $locations as $id => &$location )
{
    if (strlen($location['relayServer']) && strlen($location['relayLocation'])) {
        $location['PendingTests'] = GetRemoteBacklog($location['relayServer'], $location['relayLocation']);
    } else {
        $location['PendingTests'] = GetBacklog($location['localDir'], $location['location']);
    }
    
    // strip out any sensitive data
    unset($location['localDir']);
}

// kick out the data
if( array_key_exists('f', $_REQUEST) && $_REQUEST['f'] == 'json' ) {
  $ret = array();
  $ret['statusCode'] = 200;
  $ret['statusText'] = 'Ok';
  $ret['data'] = $locations;
  json_response($ret);
} elseif( array_key_exists('f', $_REQUEST) && $_REQUEST['f'] == 'html' ) {
  echo "<!DOCTYPE html>\n";
  echo "<html>\n<head>\n<title>WebPagetest - Tester Status</title>\n";
  echo "<head>
        <noscript>
        <meta http-equiv=\"refresh\" content=\"240\" />
        </noscript>
        <script language=\"JavaScript\">
        setTimeout( \"window.location.reload(true)\", 240000 );
        </script>\n";
  echo "<style type=\"text/css\">\n";
  echo "th,td{text-align:center; padding: 0px 20px;}\n";
  echo ".location{text-align: left;}\n";
  echo ".warning{background-color: yellow}\n";
  echo ".error{background-color: red}\n";
  echo "</style>\n";
  echo "</head>\n<body>\n";
  echo "<table>\n";
  echo "<tr>
          <th class=\"location\">Location</th>
          <th>Idle Testers</th>
          <th>Total Tests</th>
          <th>Being Tested</th>
          <th>High Priority</th>
          <th>P1</th>
          <th>P2</th>
          <th>P3</th>
          <th>P4</th>
          <th>P5</th>
          <th>P6</th>
          <th>P7</th>
          <th>P8</th>
          <th>P9</th>
        </tr>\n";
  foreach( $locations as $name => &$location ) {
    $error = '';
    if (array_key_exists('PendingTests', $location) &&
        array_key_exists('Total', $location['PendingTests']) &&
        array_key_exists('HighPriority', $location['PendingTests']) &&
        array_key_exists('Testing', $location['PendingTests'])) {
        if ($location['PendingTests']['HighPriority'] > $location['PendingTests']['Testing'] * 10)
          $error = ' error';
        elseif ($location['PendingTests']['Total'] > 1)
          $error = ' warning';
    }
    echo "<tr id=\"$name\">";
    echo "<td class=\"location$error\">" . @htmlspecialchars($name) . "</td>";
    if (array_key_exists('PendingTests', $location)) {
      echo "<td>" . @htmlspecialchars($location['PendingTests']['Idle']) . "</td>";
      echo "<td>" . @htmlspecialchars($location['PendingTests']['Total']) . "</td>";
      echo "<td>" . @htmlspecialchars($location['PendingTests']['Testing']) . "</td>";
      echo "<td>" . @htmlspecialchars($location['PendingTests']['HighPriority']) . "</td>";
      echo "<td>" . @htmlspecialchars($location['PendingTests']['p1']) . "</td>";
      echo "<td>" . @htmlspecialchars($location['PendingTests']['p2']) . "</td>";
      echo "<td>" . @htmlspecialchars($location['PendingTests']['p3']) . "</td>";
      echo "<td>" . @htmlspecialchars($location['PendingTests']['p4']) . "</td>";
      echo "<td>" . @htmlspecialchars($location['PendingTests']['p5']) . "</td>";
      echo "<td>" . @htmlspecialchars($location['PendingTests']['p6']) . "</td>";
      echo "<td>" . @htmlspecialchars($location['PendingTests']['p7']) . "</td>";
      echo "<td>" . @htmlspecialchars($location['PendingTests']['p8']) . "</td>";
      echo "<td>" . @htmlspecialchars($location['PendingTests']['p9']) . "</td>";
    }
    echo "</tr>";
  }
  echo "</table>\n";
  echo "</body>\n</html>";
} else {
    header ('Content-type: text/xml');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<?xml-stylesheet type=\"text/xsl\" encoding=\"UTF-8\" href=\"getLocations.xsl\" version=\"1.0\"?>\n";
    echo "<response>\n";
    echo "<statusCode>200</statusCode>\n";
    echo "<statusText>Ok</statusText>\n";
    if( strlen($_REQUEST['r']) )
        echo "<requestId>{$_REQUEST['r']}</requestId>\n";
    echo "<data>\n";
    
    foreach( $locations as $name => &$location )
    {
        echo "<location>\n";
        echo "<id>$name</id>\n";
        foreach( $location as $key => &$value )
            if( is_array($value) )
            {
                echo "<$key>\n";
                foreach( $value as $k => &$v )
                {
                    if (htmlspecialchars($v)!=$v)
                        echo "<$k><![CDATA[$v]]></$k>\n";
                    else
                        echo "<$k>$v</$k>\n";
                }
                echo "</$key>\n";
            }
            else
            {
                if (htmlspecialchars($value)!=$value)
                    echo "<$key><![CDATA[$value]]></$key>\n";
                else
                    echo "<$key>$value</$key>\n";
            }            
        echo "</location>\n";
    }
    
    echo "</data>\n";
    echo "</response>\n";
}

/**
* Load the location information and extract just the end nodes
* 
*/
function LoadLocations()
{
    $locations = array();
    $loc = parse_ini_file('./settings/locations.ini', true);
    FilterLocations($loc);
    BuildLocations($loc);
    
    if( isset($loc['locations']['default']) )
        $default = $loc['locations']['default'];
    else
        $default = $loc['locations'][1];
        
    $i = 1;
    while( isset($loc['locations'][$i]) )
    {
        $group = &$loc[$loc['locations'][$i]];
        if( !array_key_exists('hidden', $group) ||
            !$group['hidden'] ||
            $_REQUEST['hidden'] )
        {
            $label = $group['label'];
            
            if( isset($group['default']) )
                $def = $group['default'];
            else
                $def = $group[1];
                
            $j = 1;
            while( isset($group[$j]) )
            {
                if (array_key_exists($group[$j], $loc)) {
                    if (!array_key_exists('hidden', $loc[$group[$j]]) ||
                        !$loc[$group[$j]]['hidden'] ||
                        $_REQUEST['hidden']) {
                        $locations[$group[$j]] = array( 'Label' => $label, 
                                                        'location' => $loc[$group[$j]]['location'],
                                                        'Browser' => $loc[$group[$j]]['browser'],
                                                        'localDir' => $loc[$group[$j]]['localDir'],
                                                        'relayServer' => @$loc[$group[$j]]['relayServer'],
                                                        'relayLocation' => @$loc[$group[$j]]['relayLocation']
                                                        );

                        if( $default == $loc['locations'][$i] && $def == $group[$j] )
                            $locations[$group[$j]]['default'] = true;
                    }
                }                
                $j++;
            }
        }
        
        $i++;
    }
    
    return $locations;
}

/**
* Get the backlog for the given directory
* 
* @param mixed $dir
*/
function GetBacklog($dir, $locationId)
{
    $backlog = array();
    
    $userCount = 0;
    $lowCount = 0;
    $testing = 0;
    $idle = 0;
    for($i = 1; $i <= 9; $i++)
        $backlog["p$i"] = 0;

    $queue = GetQueueLengths($locationId);
    if (count($queue)) {
        $userCount = $queue[0];
        for( $i = 1; $i <= 9; $i++ ) {
            $backlog["p$i"] = $queue[$i];
            $lowCount += $queue[$i];
        }
    }

    $testers = GetTesters($locationId);
    if (isset($testers) && is_array($testers) && array_key_exists('testers', $testers)) {
        foreach($testers['testers'] as &$tester) {
            if( $tester['busy'] )
                $testing++;
            else
                $idle++;
        }
    }
    
    $backlog['Total'] = $userCount + $lowCount + $testing;
    $backlog['HighPriority'] = $userCount;
    $backlog['LowPriority'] = $lowCount;
    $backlog['Testing'] = $testing;
    $backlog['Idle'] = $idle;
    
    return $backlog;
}

/**
* Pull the location information from a remote server
*/
function GetRemoteBacklog($server, $remote_location) {
    $backlog = array();
    global $remote_cache;
    
    $server_hash = md5($server);

    if (array_key_exists('relay', $_REQUEST) && $_REQUEST['relay']) {
      // see if we need to populate the cache from the remote server
      if (!array_key_exists($server_hash, $remote_cache)) {
          $xml = http_fetch("$server/getLocations.php?hidden=1");
          if ($xml) {
            $remote = json_decode(json_encode((array)simplexml_load_string($xml)), true);
            if (is_array($remote) && array_key_exists('data', $remote) && array_key_exists('location', $remote['data'])) {
                $cache_entry = array();
                foreach($remote['data']['location'] as &$location) {
                    $parts = explode(':', $location['id']);
                    $id = $parts[0];
                    $cache_entry[$id] = $location['PendingTests'];
                }
                $remote_cache[$server_hash] = $cache_entry;
            }
          }
      }

      if (array_key_exists($server_hash, $remote_cache) && array_key_exists($remote_location,$remote_cache[$server_hash])) {
          $backlog = $remote_cache[$server_hash][$remote_location];
      }
    }
    
    return $backlog;
}
?>
