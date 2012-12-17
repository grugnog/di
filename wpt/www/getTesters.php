<?php

include 'common.inc';
$remote_cache = array();

// load the locations
$locations = &GetAllTesters();

// kick out the data
if( $_REQUEST['f'] == 'json' )
{
}
else
{
    header ('Content-type: text/xml');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<?xml-stylesheet type=\"text/xsl\" encoding=\"UTF-8\" href=\"getTesters.xsl\" version=\"1.0\"?>\n";
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
    
    // see if we need to populate the cache from the remote server
    if (!array_key_exists($server_hash, $remote_cache)) {
        $remote = json_decode(json_encode((array)simplexml_load_file("$server/getTesters.php?hidden=1")), true);
        if (is_array($remote) && array_key_exists('data', $remote) && array_key_exists('location', $remote['data'])) {
            $cache_entry = array();
            foreach($remote['data']['location'] as &$location) {
                $cache_entry[$location['id']] = array(  'elapsed' => $location['elapsed'],
                                                        'testers' => $location['testers']['tester']);
            }
            $remote_cache[$server_hash] = $cache_entry;
        }
    }

    if (array_key_exists($server_hash, $remote_cache) && array_key_exists($remote_location,$remote_cache[$server_hash])) {
        $testers = $remote_cache[$server_hash][$remote_location];
    }
    
    return $testers;
}
?>
