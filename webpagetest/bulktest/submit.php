<?php
require_once('./settings.inc');

$results = array();

// see if there is an existing test we are working with
if (LoadResults($results)) {
    echo "Re-submitting failed tests from current results.txt...\r\n";
} else {
    echo "Loading URL list from urls.txt...\r\n";
    $urls = file('./urls.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    for ($i = 0; $i < $iterations; $i++) {
        foreach ($urls as $url) {
            $url = trim($url);
            if (strlen($url)) {
                foreach( $permutations as $label => &$permutation )
                    $results[] = array( 'url' => $url, 'label' => $label );
            }
        }
    }
}

// go through and submit tests for any url where we don't have a test ID or where the test failed
if (count($results)) {
    // first count the number of tests we are going to have to submit (to give some progress indication)
    $testCount = 0;
    foreach ($results as &$result) {
        if (!array_key_exists('id', $result) || 
            !strlen($result['id']) || 
            (array_key_exists('resubmit', $result) && $result['resubmit']) ||
            (array_key_exists('result', $result) && 
             $result['result'] != 0 && 
             $result['result'] != 99999)) {
            $testCount++;
        }
    }
    
    if ($testCount) {
        echo "$testCount tests to submit (combination of URL and Location...\r\n";
        SubmitTests($results, $testCount);
        
        // store the results
        StoreResults($results);
        
        echo "Done submitting tests.  The test ID's are stored in results.json\r\n";
    } else {
        echo "No tests to submit, all tests have completed successfully are are still running\r\n";
    }
} else {
    echo "Nothing to do (no urls found)\r\n";
}

/**
* Submit the actual tests
* 
* @param mixed $results
*/
function SubmitTests(&$results, $testCount) {
    global $video;
    global $private;
    global $runs;
    global $server;
    global $docComplete;
    global $fvonly;
    global $key;
    global $options;
    global $permutations;
    global $priority;

    $count = 0;
    foreach ($results as &$result) {
      if (array_key_exists('label', $result) &&
          strlen($result['label']) &&
          array_key_exists($result['label'], $permutations) &&
          array_key_exists('location', $permutations[$result['label']])) {
        if (!array_key_exists('id', $result) ||
            !strlen($result['id']) || 
            (array_key_exists('resubmit', $result) && $result['resubmit']) ||
            (array_key_exists('result', $result) &&
             strlen($result['result']) && 
             $result['result'] != 0 && 
             $result['result'] != 99999)) {
            $count++;
            echo "\rSubmitting test $count of $testCount...                  ";

            $location = $permutations[$result['label']]['location'];
            $request = $server . "runtest.php?f=json&priority=9&runs=$runs&url=" . urlencode($result['url']) . '&location=' . urlencode($location);
            if( $private )
                $request .= '&private=1';
            if( $video )
                $request .= '&video=1';
            if( $docComplete )
                $request .= '&web10=1';
            if($fvonly)
                $request .= '&fvonly=1';
            if(isset($priority)) {
                if ($priority > 0 && array_key_exists('resubmit', $result) && $result['resubmit']) {
                  $p = $priority - 1;
                  $request .= "&priority=$p";
                } else
                  $request .= "&priority=$priority";
            }
            if(strlen($key))
                $request .= "&k=$key";
            if (isset($options) && strlen($options))
                $request .= '&' . $options;
            if (array_key_exists('options', $permutations[$result['label']]) && strlen($permutations[$result['label']]['options']))
                $request .= '&' . $permutations[$result['label']]['options'];

            $response_str = http_fetch($request);
            if (strlen($response_str)) {
                $response = json_decode($response_str, true);
                if ($response['statusCode'] == 200) {
                    $entry = array();
                    $entry['url'] = $result['url'];
                    $entry['label'] = $result['label'];
                    $entry['id'] = $response['data']['testId'];
                    $result = $entry;
                } else {
                    echo "\r                                                     ";
                    echo "\rError '{$response['statusText']}' submitting {$result['url']} for {$result['label']}\r\n";
                }
            }
        }
      }
    }
    
    // clear the progress text
    echo "\r                                                     \r";
}
?>
