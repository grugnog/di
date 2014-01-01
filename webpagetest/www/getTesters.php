<?php

include 'common.inc';
$remote_cache = array();
if ($CURL_CONTEXT !== false) {
  curl_setopt($CURL_CONTEXT, CURLOPT_CONNECTTIMEOUT, 30);
  curl_setopt($CURL_CONTEXT, CURLOPT_TIMEOUT, 30);
}

// load the locations
$locations = GetAllTesters();

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
  echo "th,td{text-align:center; padding: 0px 15px;}\n";
  echo ".tester{text-align: left;}\n";
  echo ".header{text-align: left; background-color: yellow;font-size: larger; padding: 0.2em;}\n";
  echo ".error{background-color: red}\n";
  echo "</style>\n";
  echo "</head>\n<body>\n";
  echo "<table>\n";
  foreach( $locations as $name => &$location ) {
    $error = ' error';
    $elapsed = '';
    if (array_key_exists('elapsed', $location)) {
      $elapsed = " ({$location['elapsed']} minutes)";
      if ($location['elapsed'] < 30)
        $error = '';
    }
    echo "<tr id=\"$name\"><th class=\"header$error\" colspan=\"12\">" . htmlspecialchars($name) . "$elapsed</th></tr>\n";
    if (array_key_exists('testers', $location)) {
      echo "<tr><th class=\"tester\">Tester</th><th>Version</th><th>PC</th><th>EC2 Instance</th><th>CPU Utilization</th><th>Free Disk (GB)</th><th>IE Version</th>";
      echo "<th>GPU?</th><th>IP</th><th>Busy?</th><th>Last Check (minutes)</th><th>Last Work (minutes)</th></tr>\n";
      $count = 0;
      foreach($location['testers'] as $tester) {
        $count++;
        echo "<tr><td class=\"tester\">$count</td>";
        echo "<td>" . @htmlspecialchars($tester['version']) . "</td>";
        echo "<td>" . @htmlspecialchars($tester['pc']) . "</td>";
        echo "<td>" . @htmlspecialchars($tester['ec2']) . "</td>";
        echo "<td>" . @htmlspecialchars($tester['cpu']) . "</td>";
        echo "<td>" . @htmlspecialchars($tester['freedisk']) . "</td>";
        echo "<td>" . @htmlspecialchars($tester['ie']) . "</td>";
        echo "<td>" . @htmlspecialchars($tester['GPU']) . "</td>";
        echo "<td>" . @htmlspecialchars($tester['ip']) . "</td>";
        echo "<td>" . @htmlspecialchars($tester['busy']) . "</td>";
        echo "<td>" . @htmlspecialchars($tester['elapsed']) . "</td>";
        echo "<td>" . @htmlspecialchars($tester['last']) . "</td>";
        echo "</tr>";
      }
    }
  }
  echo "</table>\n";
  echo "</body>\n</html>";
} else {
    header ('Content-type: text/xml');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<?xml-stylesheet type=\"text/xsl\" encoding=\"UTF-8\" href=\"getTesters.xsl\" version=\"1.0\"?>\n";
    echo "<response>\n";
    echo "<statusCode>200</statusCode>\n";
    echo "<statusText>Ok</statusText>\n";
    if( array_key_exists('r', $_REQUEST) && strlen($_REQUEST['r']) )
        echo "<requestId>{$_REQUEST['r']}</requestId>\n";
    echo "<data>\n";
    
    foreach( $locations as $name => &$location )
    {
        echo "<location>\n";
        echo "<id>$name</id>\n";
        foreach( $location as $key => &$value )
        {
            if( is_array($value) )
            {
                echo "<testers>\n";
                foreach( $value as $index => &$tester )
                {
                    echo "<tester>\n";
                    $count = $index + 1;
                    echo "<index>$count</index>\n";
                    foreach( $tester as $k => &$v )
                    {
                        if (is_array($v))
                          $v = '';
                        if (htmlspecialchars($v)!=$v)
                            echo "<$k><![CDATA[$v]]></$k>\n";
                        else
                            echo "<$k>$v</$k>\n";
                    }
                    echo "</tester>\n";
                }
                echo "</testers>\n";
            }
            else
            {
                if (htmlspecialchars($value)!=$value)
                    echo "<$key><![CDATA[$value]]></$key>\n";
                else
                    echo "<$key>$value</$key>\n";
            }            
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
function GetAllTesters()
{
    $locations = array();
    $loc = parse_ini_file('./settings/locations.ini', true);
    BuildLocations($loc);
    
    $i = 1;
    while( isset($loc['locations'][$i]) )
    {
        $group = &$loc[$loc['locations'][$i]];
        $j = 1;
        while( isset($group[$j]) )
        {
            if (array_key_exists('relayServer', $loc[$group[$j]]) && strlen($loc[$group[$j]]['relayServer']) &&
                array_key_exists('relayLocation', $loc[$group[$j]]) && strlen($loc[$group[$j]]['relayLocation'])) {
                $locations[$loc[$group[$j]]['location']] = GetRemoteTesters($loc[$group[$j]]['relayServer'], $loc[$group[$j]]['relayLocation']);
            } else {
                $locations[$loc[$group[$j]]['location']] = GetTesters($loc[$group[$j]]['location']);
            }

            $j++;
        }
        
        $i++;
    }
    
    return $locations;
}

/**
* Get the tester information for a remote location
*/
function GetRemoteTesters($server, $remote_location) {
    $testers = array();
    global $remote_cache;
    
    $server_hash = md5($server);
    
    if (array_key_exists('relay', $_REQUEST) && $_REQUEST['relay']) {
      // see if we need to populate the cache from the remote server
      if (!array_key_exists($server_hash, $remote_cache)) {
        $xml = http_fetch("$server/getTesters.php?hidden=1");
        if ($xml) {
          $remote = json_decode(json_encode((array)simplexml_load_string($xml)), true);
          if (is_array($remote) && array_key_exists('data', $remote) && array_key_exists('location', $remote['data'])) {
              $cache_entry = array();
              foreach($remote['data']['location'] as &$location) {
                  if (array_key_exists('testers', $location) && array_key_exists('tester', $location['testers'])) {
                    $parts = explode(':', $location['id']);
                    $id = $parts[0];
                    if (array_key_exists(0, $location['testers']['tester']))
                      $cache_entry[$id] = array(  'elapsed' => $location['elapsed'],
                                                              'testers' => $location['testers']['tester']);
                    else
                      $cache_entry[$id] = array(  'elapsed' => $location['elapsed'],
                                                              'testers' => array($location['testers']['tester']));
                  }
              }
              $remote_cache[$server_hash] = $cache_entry;
          }
        }
      }

      if (array_key_exists($server_hash, $remote_cache) && array_key_exists($remote_location,$remote_cache[$server_hash])) {
          $testers = $remote_cache[$server_hash][$remote_location];
      }
    }
    
    return $testers;
}
?>
