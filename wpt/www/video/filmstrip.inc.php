<?php

// Shared code for creating the visual filmstrips
require_once('visualProgress.inc.php');

// build up the actual test data (needs to include testID and RUN in the requests)
$defaultInterval = 0;
$tests = array();
$fastest = null;
$ready = true;
$error = null;
$endTime = 'visual';
if( strlen($_REQUEST['end']) )
    $endTime = trim($_REQUEST['end']);

$compTests = explode(',', $_REQUEST['tests']);
foreach($compTests as $t) {
    $parts = explode('-', $t);
    if (count($parts) >= 1) {
        $test = array();
        $test['id'] = $parts[0];
        if (ValidateTestId($test['id'])) {
            $test['cached'] = 0;
            $test['end'] = $endTime;
            
            for ($i = 1; $i < count($parts); $i++) {
                $p = explode(':', $parts[$i]);
                if (count($p) >= 2) {
                    if( $p[0] == 'r' )
                        $test['run'] = (int)$p[1];
                    if( $p[0] == 'l' )
                        $test['label'] = $p[1];
                    if( $p[0] == 'c' )
                        $test['cached'] = (int)$p[1];
                    if( $p[0] == 'e' )
                        $test['end'] = trim($p[1]);
                }
            }
            
            RestoreTest($test['id']);
            $test['path'] = GetTestPath($test['id']);
            $test['pageData'] = loadAllPageData($test['path']);
            
            $info = json_decode(gz_file_get_contents("./{$test['path']}/testinfo.json"), true);
            if (isset($info) && is_array($info)) {
                if (array_key_exists('discard', $info) && 
                    $info['discard'] >= 1 && 
                    array_key_exists('priority', $info) &&
                    $info['priority'] >= 1) {
                    $defaultInterval = 100;
                }
                $test['url'] = $info['url'];
            }
            
            $testInfo = parse_ini_file("./{$test['path']}/testinfo.ini",true);
            if ($testInfo !== FALSE) {
                if (array_key_exists('test', $testInfo) && array_key_exists('location', $testInfo['test']))
                    $test['location'] = $testInfo['test']['location'];
                if (isset($testInfo['test']) && isset($testInfo['test']['completeTime'])) {
                    $test['done'] = true;

                    if( !$test['run'] )
                        $test['run'] = GetMedianRun($test['pageData'],$test['cached'], $median_metric);
                    $test['aft'] = $test['pageData'][$test['run']][$test['cached']]['aft'];

                    $loadTime = $test['pageData'][$test['run']][$test['cached']]['fullyLoaded'];
                    if( isset($loadTime) && (!isset($fastest) || $loadTime < $fastest) )
                        $fastest = $loadTime;

                    // figure out the real end time (in ms)
                    if (isset($test['end'])) {
                        if( !strcmp($test['end'], 'visual') && array_key_exists('visualComplete', $test['pageData'][$test['run']][$test['cached']]) )
                            $test['end'] = $test['pageData'][$test['run']][$test['cached']]['visualComplete'];
                        elseif( !strcmp($test['end'], 'doc') )
                            $test['end'] = $test['pageData'][$test['run']][$test['cached']]['docTime'];
                        elseif(!strncasecmp($test['end'], 'doc+', 4))
                            $test['end'] = $test['pageData'][$test['run']][$test['cached']]['docTime'] + (int)((double)substr($test['end'], 4) * 1000.0);
                        elseif( !strcmp($test['end'], 'full') )
                            $test['end'] = 0;
                        elseif( !strcmp($test['end'], 'all') )
                            $test['end'] = -1;
                        elseif( !strcmp($test['end'], 'aft') ) {
                            $test['end'] = $test['aft'];
                            if( !$test['end'] )
                                $test['end'] = -1;
                        } else
                            $test['end'] = (int)((double)$test['end'] * 1000.0);
                    } else
                        $test['end'] = 0;
                    if( !$test['end'] )
                        $test['end'] = $test['pageData'][$test['run']][$test['cached']]['fullyLoaded'];
                } else {
                    $test['done'] = false;
                    $ready = false;
                    
                    if( isset($testInfo['test']) && isset($testInfo['test']['startTime']) )
                        $test['started'] = true;
                    else
                        $test['started'] = false;
                }
                
                $tests[] = $test;
            }
        }
    }
}

$count = count($tests);
if( $count ) {
    setcookie('fs', urlencode($_REQUEST['tests']));
    LoadTestData();
}
else
    $error = "No valid tests selected.";

$thumbSize = $_REQUEST['thumbSize'];
if( !isset($thumbSize) || $thumbSize < 50 || $thumbSize > 500 ) {
    if( $count > 6 )
        $thumbSize = 100;
    elseif( $count > 4 )
        $thumbSize = 150;
    else
        $thumbSize = 200;
}

$interval = (int)$_REQUEST['ival'];
if( !$interval ) {
    if ($defaultInterval) {
        $interval = $defaultInterval;
    } else if( isset($fastest) )
    {
        if( $fastest > 3000 )
            $interval = 500;
        else
            $interval = 100;
    }
    else
        $interval = 100;
}
$interval /= 100;

/**
* Load information about each of the tests (particularly about the video frames)
* 
*/
function LoadTestData() {
    global $tests;
    global $admin;
    global $supportsAuth;

    $count = 0;
    foreach( $tests as &$test ) {
        $count++;
        $testPath = &$test['path'];
        $pageData = &$test['pageData'];
        $url = trim($pageData[1][0]['URL']);
        if (strlen($url)) {
            $test['url'] = $url;
        }
        
        if( strlen($test['label']) )
            $test['name'] = $test['label'];
        else {
            $testInfo = json_decode(gz_file_get_contents("./$testPath/testinfo.json"), true);
            $test['name'] = trim($testInfo['label']);
        }
        if( !strlen($test['name']) ) {
            $test['name'] = $test['url'];
            $test['name'] = str_replace('http://', '', $test['name']);
            $test['name'] = str_replace('https://', '', $test['name']);
        }
        $test['index'] = $count;
        $test['name'] = "$count: {$test['name']}";
        
        $videoPath = "./$testPath/video_{$test['run']}";
        if( $test['cached'] )
            $videoPath .= '_cached';
            
        $test['video'] = array();
        if( is_dir($videoPath) ) {
            $test['video']['start'] = 20000;
            $test['video']['end'] = 0;
            $test['video']['frames'] = array();
            $test['video']['progress'] = GetVisualProgress($testPath, $test['run'], $test['cached']);
            
            // get the path to each of the video files
            $dir = opendir($videoPath);
            if( $dir ) {
                while($file = readdir($dir)) {
                    $path = $videoPath  . "/$file";
                    if( is_file($path) && !strncmp($file, 'frame_', 6) && strpos($file, '.thm') === false && strpos($file, '.hist') === false ) {
                        $parts = explode('_', $file);
                        if( count($parts) >= 2 ) {
                            $index = (int)$parts[1];
                            $ms = $index * 100;
                            
                            if( !$test['end'] || $test['end'] == -1 || $ms <= $test['end'] ) {
                                if( $index < $test['video']['start'] )
                                    $test['video']['start'] = $index;
                                if( $index > $test['video']['end'] )
                                    $test['video']['end'] = $index;
                                
                                // figure out the dimensions of the source image
                                if( !$test['video']['width'] || !$test['video']['height'] ) {
                                    $size = getimagesize($path);
                                    $test['video']['width'] = $size[0];
                                    $test['video']['height'] = $size[1];
                                }
                                
                                $test['video']['frames'][$index] = "$file";
                            }
                        }
                    }
                }
                
                if ($test['end'] == -1)
                    $test['end'] = $test['video']['end'] * 100;
                elseif ($test['end'])
                    $test['video']['end'] = ($test['end'] + 99) / 100;

                closedir($dir);
            }
            
            if( !isset($test['video']['frames'][0]) )
                $test['video']['frames'][0] = $test['video']['frames'][$test['video']['start']];
        }
    }
}
?>
