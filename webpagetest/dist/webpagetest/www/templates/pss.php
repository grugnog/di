<?php
/*
    Template for PSS tests
    Automatically fills in the script and batch information
    and does some validation to prevent abuse
    TODO: add support for caching tests
*/  
$json = true;
$req_location = 'closest';
$test['runs'] = 8;
$test['private'] = 1;
$test['view'] = 'pss';
$test['video'] = 1;
$req_priority = 0;
$test['median_video'] = 1;
$test['web10'] = 1;
$test['discard'] = 3;
$test['script'] = "setDnsName\t%HOSTR%\tghs.google.com\noverrideHost\t%HOSTR%\tpsa.pssdemos.com\nnavigate\t%URL%";
$req_bulkurls = "Original=$req_url noscript\nOptimized=$req_url";
$test['label'] = "Page Speed Service Comparison for $req_url";

// see if we have a cached test already
if (array_key_exists('url', $test) && strlen($test['url']) && (!array_key_exists('force', $_REQUEST) || $_REQUEST['force'] == 0)) {
    $cached_id = PSS_GetCacheEntry($test['url']);
    if (isset($cached_id) && strlen($cached_id)) {
        $test['id'] = $cached_id;
    }
}

if (!array_key_exists('id', $test)) {
    $test['submit_callback'] = 'PSS_TestSubmitted';
}

/**
* Get a cached test result
*/
function PSS_GetCacheEntry($url) {
    $id = null;
    $cache_lock = fopen('./tmp/pss.cache.lock', 'w+');
    if ($cache_lock) {
        if (flock($cache_lock, LOCK_EX)) {
            if (is_file('./tmp/pss.cache')) {
                $cache = json_decode(file_get_contents('./tmp/pss.cache'), true);

                // delete stale cache entries
                $now = time();
                $dirty = false;
                foreach($cache as $cache_key => &$cache_entry) {
                    if ( $cache_entry['expires'] < $now) {
                        $dirty = true;
                        unset($cache[$cache_key]);
                    }
                }
                if ($dirty) {
                    file_put_contents('./tmp/pss.cache', json_encode($cache));
                }
                $key = md5($url);
                if (array_key_exists($key, $cache) && array_key_exists('id', $cache[$key])) {
                    $id = $cache[$key]['id'];
                }
            }
        }
        fclose($cache_lock);
    }
    return $id;
}

/**
* Cache the test ID in the case of multiple submits
*/
function PSS_TestSubmitted(&$test) {
    if (array_key_exists('id', $test) && array_key_exists('url', $test)) {
        $now = time();
        $cache_time = 10080;    // 7 days (in minutes)
        if (array_key_exists('cache', $_REQUEST) && $_REQUEST['cache'] > 0)
            $cache_time = (int)$_REQUEST['cache'];
        $expires = $now + ($cache_time * 60);
        $entry = array('id' => $test['id'], 'expires' => $expires);
        $key = md5($test['url']);
        
        // update the cache
        $cache_lock = fopen('./tmp/pss.cache.lock', 'w+');
        if ($cache_lock) {
            if (flock($cache_lock, LOCK_EX)) {
                if (is_file('./tmp/pss.cache')) {
                    $cache = json_decode(file_get_contents('./tmp/pss.cache'), true);
                } else {
                    $cache = array();
                }
                // delete stale cache entries
                foreach($cache as $cache_key => &$cache_entry) {
                    if ( $cache_entry['expires'] < $now) {
                        unset($cache[$cache_key]);
                    }
                }
                $cache[$key] = $entry;
                file_put_contents('./tmp/pss.cache', json_encode($cache));
            }
            fclose($cache_lock);
        }
    }
}
?>
