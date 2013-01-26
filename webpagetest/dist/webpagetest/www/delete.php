<?php
include 'common.inc';

// only allow download of relay tests
$ok = false;
if( strpos($testPath, 'relay') !== false
    && strpos($testPath, 'results') !== false
    && strpos($testPath, '..') === false
    && is_dir($testPath) ) {
    // delete the test directory
    DelTree($testPath);
    
    // delete empty directories above this one
    do {
        $testPath = rtrim($testPath, "/\\");
        $testPath = dirname($testPath);
        rmdir($testPath);
    } while( strpos($testPath, 'relay') );
    
    $ok = true;
}

if( !$ok )
    header("HTTP/1.0 404 Not Found");
?>
