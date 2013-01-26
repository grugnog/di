<?php
/*
    This is called every 15 minutes as long as agents are polling for work
*/
ignore_user_abort(true);
chdir('..');
require 'common.inc';
require 'testStatus.inc';
require 'breakdown.inc';
set_time_limit(36000);
$debug=true;
if (!is_dir('./log')) {
    mkdir('./log', 0777, true);
}
$logFile = 'benchmark.log';

header ("Content-type: text/plain");

$nonZero = array('TTFB', 'bytesOut', 'bytesOutDoc', 'bytesIn', 'bytesInDoc', 'connections', 'requests', 'requestsDoc', 'render', 
                'fullyLoaded', 'docTime', 'domElements', 'titleTime', 'domContentLoadedEventStart', 'visualComplete', 'SpeedIndex');

// see if we need to actuall process the given benchmark
if (array_key_exists('benchmark', $_GET) && strlen($_GET['benchmark'])) {
    $benchmark = trim($_GET['benchmark']);
    if (is_file("./settings/benchmarks/$benchmark.php")) {
        $lock = fopen("./tmp/$benchmark.bm", "w+");
        if ($lock !== false) {
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                $logFile = "bm-$benchmark.log";
                // see if we are using API keys
                $key = null;
                if (is_file('./settings/keys.ini')) {
                    $keys = parse_ini_file('./settings/keys.ini', true);
                    if (array_key_exists('server', $keys) && array_key_exists('key', $keys['server']))
                        $key = $keys['server']['key'];
                }
                ProcessBenchmark(basename($benchmark, '.php'));
            }
            fclose($lock);
        }
    }
} else {
    if (is_file('./settings/benchmarks/benchmarks.txt')) {
        // make sure we don't execute multiple cron jobs concurrently
        $lock = fopen("./tmp/benchmark_cron.lock", "w+");
        if ($lock !== false) {
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_file("./log/$logFile")) {
                    unlink("./log/$logFile");
                }
                logMsg("Running benchmarks cron processing", "./log/$logFile", true);

                // iterate over all of the benchmarks and if we need to do any processing spawn off a child request to do the actual work
                // this way we can concurrently process all of the benchmarks

                // load the list of benchmarks
                $bm_list = file('./settings/benchmarks/benchmarks.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (!count($bm_list))
                    $bm_list = glob('./settings/benchmarks/*.php');
                foreach ($bm_list as $benchmark) {
                    PreProcessBenchmark(basename($benchmark, '.php'));
                }

                logMsg("Done", "./log/$logFile", true);
            } else {
                echo "Benchmark cron job is already running\n";
            }
            fclose($lock);
        }
    }
}

/**
* Check to see if we need to do any processing for the given benchmark
* if so, send an async request to do the actual processing
* 
* @param mixed $benchmark
*/
function PreProcessBenchmark($benchmark) {
    global $logFile;
    $needsRunning = false;
    echo "PreProcessing benchmark '$benchmark'\n";
    logMsg("PreProcessing benchmark '$benchmark'", "./log/$logFile", true);
    $lock = fopen("./tmp/$benchmark.bm", "w+");
    if ($lock !== false) {
        if (flock($lock, LOCK_EX | LOCK_NB)) {
            $options = array();
            if(include "./settings/benchmarks/$benchmark.php") {
                if (!is_dir("./results/benchmarks/$benchmark"))
                    mkdir("./results/benchmarks/$benchmark", 0777, true);
                if (is_file("./results/benchmarks/$benchmark/state.json")) {
                    $state = json_decode(file_get_contents("./results/benchmarks/$benchmark/state.json"), true);
                    if (array_key_exists('running', $state) && $state['running']) {
                        $needsRunning = true;
                    } elseif (array_key_exists('needs_aggregation', $state) && $state['needs_aggregation']) {
                        $needsRunning = true;
                    }
                } else {
                    $needsRunning = true;
                }
                
                // see if we need to kick off a new benchmark run
                if (!$needsRunning) {
                    if (!array_key_exists('last_run', $state))
                        $state['last_run'] = 0;
                    $now = time();
                    if (call_user_func("{$benchmark}ShouldExecute", $state['last_run'], $now)) {
                        $needsRunning = true;
                    }
                }
                if (isset($state)) {
                    file_put_contents("./results/benchmarks/$benchmark/state.json", json_encode($state));
                }
            }
        } else {
            echo "Benchmark '$benchmark' is currently locked\n";
            logMsg("Benchmark '$benchmark' is currently locked", "./log/$logFile", true);
        }
        fclose($lock);
    }
    
    if ($needsRunning) {
        echo "Benchmark '$benchmark' needs processing, spawning task\n";
        logMsg("Benchmark '$benchmark' needs processing, spawning task", "./log/$logFile", true);
        
        $url = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?benchmark=' . urlencode($benchmark);
        $c = curl_init();
        curl_setopt($c, CURLOPT_URL, $url);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($c, CURLOPT_TIMEOUT, 1);
        curl_exec($c);
        curl_close($c);
    } else {
        echo "Benchmark '$benchmark' is idle\n";
        logMsg("Benchmark '$benchmark' is idle", "./log/$logFile", true);
    }
}

/**
* Do all of the processing for a given benchmark
* 
* @param mixed $benchmark
*/
function ProcessBenchmark($benchmark) {
    global $logFile;
    logMsg("Processing benchmark '$benchmark'", "./log/$logFile", true);
    $options = array();
    if(include "./settings/benchmarks/$benchmark.php") {
        if (!is_dir("./results/benchmarks/$benchmark"))
            mkdir("./results/benchmarks/$benchmark", 0777, true);
        if (is_file("./results/benchmarks/$benchmark/state.json")) {
            $state = json_decode(file_get_contents("./results/benchmarks/$benchmark/state.json"), true);
        } else {
            $state = array('running' => false, 'needs_aggregation' => true, 'runs' => array());
            // build up a list of runs if we have data
            if (is_dir("./results/benchmarks/$benchmark/data")) {
                $files = scandir("./results/benchmarks/$benchmark/data");
                $last_run = 0;
                foreach( $files as $file ) {
                    if (preg_match('/([0-9]+_[0-9]+)\..*/', $file, $matches)) {
                        $UTC = new DateTimeZone('UTC');
                        $date = DateTime::createFromFormat('Ymd_Hi', $matches[1], $UTC);
                        $time = $date->getTimestamp();
                        $state['runs'][] = $time;
                        if ($time > $last_run)
                            $last_run = $time;
                    }
                }
                if ($last_run) {
                    $state['last_run'] = $last_run;
                }
            }
            file_put_contents("./results/benchmarks/$benchmark/state.json", json_encode($state));        
        }
        if (!is_array($state)) {
            $state = array('running' => false);
        }
        if (!array_key_exists('running', $state)) {
            $state['running'] = false;
        }
        
        if (array_key_exists('running', $state)) {
            CheckBenchmarkStatus($benchmark, $state);
            // update the state between steps
            file_put_contents("./results/benchmarks/$benchmark/state.json", json_encode($state));
        } else {
            $state['running'] = false;
        }
        
        if (!$state['running'] && 
            (array_key_exists('runs', $state) && count($state['runs'])) &&
            (!array_key_exists('needs_aggregation', $state) || $state['needs_aggregation']) ){
            AggregateResults($benchmark, $state, $options);
            file_put_contents("./results/benchmarks/$benchmark/state.json", json_encode($state));
        }
        
        // see if we need to kick off a new benchmark run
        if (!$state['running'] && !array_key_exists('tests', $state)) {
            if (!array_key_exists('last_run', $state))
                $state['last_run'] = 0;
            $now = time();
            if (call_user_func("{$benchmark}ShouldExecute", $state['last_run'], $now)) {
                if (is_file("./log/$logFile")) {
                    unlink("./log/$logFile");
                }
                logMsg("Running benchmark '$benchmark'", "./log/$logFile", true);
                if (SubmitBenchmark($configurations, $state, $benchmark)) {
                    $state['last_run'] = $now;
                    $state['running'] = true;
                }
            } else {
                logMsg("Benchmark '$benchmark' does not need to be run", "./log/$logFile", true);
            }
        }
        file_put_contents("./results/benchmarks/$benchmark/state.json", json_encode($state));
    }
    logMsg("Done Processing benchmark '$benchmark'", "./log/$logFile", true);
}

/**
* Check the status of any pending tests
* 
* @param mixed $state
*/
function CheckBenchmarkStatus($benchmark, &$state) {
    global $logFile;
    if ($state['running']) {
        $start_time = $state['last_run'];
        if (!is_dir("./results/benchmarks/$benchmark/data"))
            mkdir("./results/benchmarks/$benchmark/data", 0777, true);
        $dataFile = "./results/benchmarks/$benchmark/data/" . gmdate('Ymd_Hi', $start_time) . '.json';
        logMsg("Data File: $dataFile", "./log/$logFile", true);
        $updated = 0;
        if (gz_is_file($dataFile)) {
            $data = json_decode(gz_file_get_contents($dataFile), true);
            logMsg("Loaded " . count($data) . " existing records", "./log/$logFile", true);
        } else {
            $data = array();
            logMsg("Data file doesn't exist, starting fresh", "./log/$logFile", true);
        }
        $done = true;
        foreach ($state['tests'] as &$test) {
            if (!$test['completed']) {
                $status = GetTestStatus($test['id'], false);
                $now = time();
                if ($status['statusCode'] >= 400) {
                    logMsg("Test {$test['id']} : Failed", "./log/$logFile", true);
                    if (ResubmitBenchmarkTest($benchmark, $test['id'], $state)) {
                        $done = false;
                    } else {
                        $test['completed'] = $now;
                    }
                } elseif( $status['statusCode'] == 200 ) {
                    logMsg("Test {$test['id']} : Completed", "./log/$logFile", true);
                    if (!IsTestValid($test['id']) && ResubmitBenchmarkTest($benchmark, $test['id'], $state)) {
                        $done = false;
                    } else {
                        if (array_key_exists('completeTime', $status) && $status['completeTime']) {
                            $test['completed'] = $status['completeTime'];
                        } elseif (array_key_exists('startTime', $status) && $status['startTime']) {
                            $test['completed'] = $status['startTime'];
                        } else {
                            $test['completed'] = $now;
                        }
                    }
                } else {
                    $done = false;
                    logMsg("Test {$test['id']} : {$status['statusText']}", "./log/$logFile", true);
                }
                
                // collect the test data and archive the test as we get each result
                if ($test['completed']) {
                    $updated++;
                    CollectResults($test, $data);
                    if (ArchiveTest($test['id'])) {
                        logMsg("Test {$test['id']} : Archived", "./log/$logFile", true);
                        $path = GetTestPath($test['id']);
                        if (strlen($path))
                            delTree($path);
                    }
                }
            }
        }
        
        if ($updated) {
            logMsg("Data updated for for $updated tests, total data rows: " . count($data), "./log/$logFile", true);
            gz_file_put_contents($dataFile, json_encode($data));
        } else {
            logMsg("No test data updated", "./log/$logFile", true);
        }

        if ($done) {
            logMsg("Benchmark '$benchmark' is finished", "./log/$logFile", true);
            $state['runs'][] = $start_time;
            $state['running'] = false;
            $state['needs_aggregation'] = true;
            unset($state['tests']);    
        } else {
            logMsg("Benchmark '$benchmark' is still running", "./log/$logFile", true);
        }
        
        logMsg("Done checking status", "./log/$logFile", true);
    }
}

/**
* Do any aggregation once all of the tests have finished
* 
* @param mixed $state
*/
function CollectResults(&$test, &$data) {
    global $logFile;
    $testPath = './' . GetTestPath($test['id']);
    logMsg("Loading page data from $testPath", "./log/$logFile", true);
    $page_data = loadAllPageData($testPath);
    if (count($page_data)) {
        foreach ($page_data as $run => &$page_run) {
            foreach ($page_run as $cached => &$test_data) {
                $data_row = $test_data;
                unset($data_row['URL']);
                // figure out the per-type request info (todo: measure how expensive this is and see if we have a better way)
                $breakdown = getBreakdown($test['id'], $testPath, $run, $cached, $requests);
                foreach ($breakdown as $mime => &$values) {
                    $data_row["{$mime}_requests"] = $values['requests'];
                    $data_row["{$mime}_bytes"] = $values['bytes'];
                }
                // capture the page speed score
                if ($cached)
                    $data_row['page_speed'] = GetPageSpeedScore("$testPath/{$run}_Cached_pagespeed.txt");
                else
                    $data_row['page_speed'] = GetPageSpeedScore("$testPath/{$run}_pagespeed.txt");
                $data_row['url'] = $test['url'];
                $data_row['label'] = $test['label'];
                $data_row['location'] = $test['location'];
                $data_row['config'] = $test['config'];
                $data_row['cached'] = $cached;
                $data_row['run'] = $run;
                $data_row['id'] = $test['id'];
                $data[] = $data_row;
                $test['has_data'] = 1;
            }
        }
    } else {
        $data_row = array();
        $data_row['url'] = $test['url'];
        $data_row['label'] = $test['label'];
        $data_row['location'] = $test['location'];
        $data_row['config'] = $test['config'];
        $data_row['id'] = $test['id'];
        $data[] = $data_row;
    }
}

/**
* Submit the various test permutations
* 
* @param mixed $configurations
* @param mixed $state
*/
function SubmitBenchmark(&$configurations, &$state, $benchmark) {
    $submitted = false;
    
    $state['tests'] = array();
    
    // group all of the tests by URL so that any given URL is tested in all configurations before going to the next URL
    $tests = array();
    foreach ($configurations as $config_label => $config) {
        $urls = file("./settings/benchmarks/{$config['url_file']}", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($urls as $url) {
            $url = trim($url);
            $label = '';
            $separator = strpos($url, "\t");
            if ($separator !== false) {
                $label = trim(substr($url, 0, $separator));
                $url = trim(substr($url, $separator + 1));
            }
            $key = md5($url);
            if (strlen($label)) {
                $key = md5($label);
            }
            if (!array_key_exists($key, $tests)) {
                $tests[$key] = array();
            }
            foreach ($config['locations'] as $location) {
                $tests[$key][] = array('url' => $url,
                                        'location' => $location,
                                        'settings' => $config['settings'],
                                        'benchmark' => $benchmark,
                                        'label' => $label,
                                        'config' => $config_label);
            }
        }
    }

    // now submit the actual tests    
    foreach($tests as &$testGroup) {
        foreach($testGroup as &$test) {
            $id = SubmitBenchmarkTest($test['url'], $test['location'], $test['settings'], $test['benchmark']);
            if ($id !== false ) {
                $state['tests'][] = array(  'id' => $id, 
                                            'label' => $test['label'],
                                            'url' => $test['url'], 
                                            'location' => $test['location'], 
                                            'config' => $test['config'],
                                            'submitted' => time(), 
                                            'completed' => 0);
            }
        }
    }
    
    if (count($state['tests'])) {
        $submitted = true;
    }
    
    return $submitted;
}

// see if the given test has valid results
function IsTestValid($id) {
    $valid = false;
    $testPath = './' . GetTestPath($id);
    $page_data = loadAllPageData($testPath);
    if (CountSuccessfulTests($page_data, 0) >= 3) {
        $valid = true;
    }
    return $valid;
}

// re-submit the given benchmark test
function ResubmitBenchmarkTest($benchmark, $id, &$state) {
    $resubmitted = false;
    $MAX_RETRIES = 2;
    global $logFile;
    
    logMsg("Resubmitting test $id", "./log/$logFile", true);
    
    // find the ID and remove them from the list
    if(include "./settings/benchmarks/$benchmark.php") {
        if (isset($configurations) && array_key_exists('tests', $state)) {
            foreach ($state['tests'] as $index => &$testData) {
                if ($testData['id'] == $id) {
                    if (!array_key_exists('retry', $testData) || $testData['retry'] < $MAX_RETRIES) {
                        $new_id = SubmitBenchmarkTest($testData['url'], $testData['location'], $configurations[$testData['config']]['settings'], $benchmark);
                        if ($new_id !== false ) {
                            $testData['id'] = $new_id;
                            $testData['submitted'] = time();
                            $testData['completed'] = 0;
                            if (!array_key_exists('retry', $testData)) {
                                $testData['retry'] = 0;
                            }
                            $testData['retry']++;
                            $resubmitted = true;
                            logMsg("Test $id from $benchmark resubmitted, new ID = $new_id", "./log/$logFile", true);
                        }
                    }
                    break;
                }
            }
        }
    }    
    return $resubmitted;
}

/**
* Submit a single test and return the test ID (or false in the case of failure)
* 
* @param mixed $url
* @param mixed $location
* @param mixed $settings
*/
function SubmitBenchmarkTest($url, $location, &$settings, $benchmark) {
    $id = false;
    global $key;
    global $logFile;
    $priority = 7;  // default to a really low priority
    
    $boundary = "---------------------".substr(md5(rand(0,32000)), 0, 10);
    $data = "--$boundary\r\n";
    
    foreach ($settings as $setting => $value) {
        if ($setting == 'priority') {
            $priority = $value;
        } else {
            $data .= "Content-Disposition: form-data; name=\"$setting\"\r\n\r\n$value";
            $data .= "\r\n--$boundary\r\n"; 
        }
    }
    
    if (isset($key)) {
        $data .= "Content-Disposition: form-data; name=\"k\"\r\n\r\n$key";
        $data .= "\r\n--$boundary\r\n"; 
    }

    if (!strncasecmp($url, 'script:', 7)) {
        $url = substr($url, 7);
        $url = str_replace('\r', "\r", $url);
        $url = str_replace('\n', "\n", $url);
        $url = str_replace('\t', "\t", $url);
        $data .= "Content-Disposition: form-data; name=\"script\"\r\n\r\n$url";
        $data .= "\r\n--$boundary\r\n"; 
    } else {
        $data .= "Content-Disposition: form-data; name=\"url\"\r\n\r\n$url";
        $data .= "\r\n--$boundary\r\n"; 
    }
    $data .= "Content-Disposition: form-data; name=\"location\"\r\n\r\n$location";
    $data .= "\r\n--$boundary\r\n"; 
    $data .= "Content-Disposition: form-data; name=\"benchmark\"\r\n\r\n$benchmark";
    $data .= "\r\n--$boundary\r\n"; 
    $data .= "Content-Disposition: form-data; name=\"f\"\r\n\r\njson";
    $data .= "\r\n--$boundary\r\n"; 
    $data .= "Content-Disposition: form-data; name=\"priority\"\r\n\r\n$priority"; 
    $data .= "\r\n--$boundary--\r\n";

    $params = array('http' => array(
                       'method' => 'POST',
                       'header' => "Connection: close\r\nContent-Type: multipart/form-data; boundary=$boundary",
                       'content' => $data
                    ));

    $ctx = stream_context_create($params);
    $fp = fopen("http://{$_SERVER['HTTP_HOST']}/runtest.php", 'rb', false, $ctx);
    if ($fp) {
        $response = @stream_get_contents($fp);
        if ($response && strlen($response)) {
            $result = json_decode($response, true);
            if (is_array($result) && array_key_exists('statusCode', $result) && 
                $result['statusCode'] == 200 && 
                array_key_exists('data', $result) && 
                array_key_exists('testId', $result['data']) ){
                $id = $result['data']['testId'];
                logMsg("Test submitted: $id", "./log/$logFile", true);
            } else {
                logMsg("Error submitting benchmark test: {$result['statusText']}", "./log/$logFile", true);
            }
        }
    }
    
    return $id;
}

/**
* Generate aggregate metrics for the given test
* 
* @param mixed $benchmark
* @param mixed $state
*/
function AggregateResults($benchmark, &$state, $options) {
    global $logFile;
    
    if (!is_dir("./results/benchmarks/$benchmark/aggregate"))
        mkdir("./results/benchmarks/$benchmark/aggregate", 0777, true);
    if (is_file("./results/benchmarks/$benchmark/aggregate/info.json")) {
        $info = json_decode(file_get_contents("./results/benchmarks/$benchmark/aggregate/info.json"), true);
    } else {
        $info = array('runs' => array());
    }
    
    if (!array_key_exists('runs', $info)) {
        $info['runs'] = array();
    }
    
    // store a list of metrics that we aggregate in the info block
    $info['metrics'] = array('TTFB', 'bytesOut', 'bytesOutDoc', 'bytesIn', 'bytesInDoc', 
                                'connections', 'requests', 'requestsDoc', 'render', 
                                'fullyLoaded', 'docTime', 'domTime', 'score_cache', 'score_cdn',
                                'score_gzip', 'score_keep-alive', 'score_compress', 'gzip_total', 'gzip_savings',
                                'image_total', 'image_savings', 'domElements', 'titleTime', 'loadEvent-Time', 
                                'domContentLoadedEventStart', 'domContentLoadedEvent-Time', 'visualComplete',
                                'js_bytes', 'js_requests', 'css_bytes', 'css_requests', 'image_bytes', 'image_requests',
                                'flash_bytes', 'flash_requests', 'html_bytes', 'html_requests', 'text_bytes', 'text_requests',
                                'other_bytes', 'other_requests', 'SpeedIndex', 'responses_404', 'responses_other', 'browser_version', 'server_rtt');

    // loop through all of the runs and see which ones we don't have aggregates for
    foreach ($state['runs'] as $run_time) {
        if (!array_key_exists($run_time, $info['runs'])) {
            $file_name = "./results/benchmarks/$benchmark/data/" . gmdate('Ymd_Hi', $run_time) . '.json';
            logMsg("Aggregating Results for $file_name", "./log/$logFile", true);
            if (gz_is_file($file_name)) {
                $data = json_decode(gz_file_get_contents($file_name), true);
                FilterRawData($data, $options);
                CreateAggregates($info, $data, $benchmark, $run_time, $options);
                unset($data);
                $info['runs'][$run_time] = 1;
            } else {
                logMsg("Missing data file - $file_name", "./log/$logFile", true);
            }
        }
    }
    
    file_put_contents("./results/benchmarks/$benchmark/aggregate/info.json", json_encode($info));
    $state['needs_aggregation'] = false;
    logMsg("Agregation complete", "./log/$logFile", true);
}

/**
* Create the various aggregations for the given data chunk
* 
* @param mixed $info
* @param mixed $data
* @param mixed $benchmark
*/
function CreateAggregates(&$info, &$data, $benchmark, $run_time, $options) {
    foreach ($info['metrics'] as $metric) {
        $metric_file = "./results/benchmarks/$benchmark/aggregate/$metric.json";
        if (gz_is_file($metric_file)) {
            $agg_data = json_decode(gz_file_get_contents($metric_file), true);
        } else {
            $agg_data = array();
        }
        AggregateMetric($metric, $info, $data, $run_time, $agg_data, $options);
        gz_file_put_contents($metric_file, @json_encode($agg_data));
        unset($agg_data);
        
        if (array_key_exists('labels', $info) && count($info['labels']) <= 20) {
            $metric_file = "./results/benchmarks/$benchmark/aggregate/$metric.labels.json";
            if (gz_is_file($metric_file)) {
                $agg_data = json_decode(gz_file_get_contents($metric_file), true);
            } else {
                $agg_data = array();
            }
            AggregateMetricByLabel($metric, $info, $data, $run_time, $agg_data, $options);
            gz_file_put_contents($metric_file, json_encode($agg_data));
            unset($agg_data);
        }
    }
}

/**
* Create the aggregates for the given metric grouped by config and cached state
* 
* @param mixed $info
* @param mixed $data
* @param mixed $run_time
* @param mixed $agg_data
*/
function AggregateMetric($metric, $info, &$data, $run_time, &$agg_data, $options) {
    $configs = array();
    global $nonZero;
    
    // group the individual records
    foreach ($data as &$record) {
        if (array_key_exists($metric, $record) && 
            array_key_exists('result', $record) && 
            array_key_exists('config', $record) && 
            array_key_exists('cached', $record) && 
            array_key_exists('location', $record) && 
            strlen($record['config']) &&
            strlen($record['location']) &&
            $record['loadTime'] != 0 &&
            ($record['result'] == 0 || $record['result'] == 99999)) {
                
            // make sure all of the metrics that we expect to be non-zero are
            $ok = true;
            foreach($nonZero as $nzMetric) {
                if ($nzMetric == $metric && $record[$metric] == 0) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $config = $record['config'];
                $location = $record['location'];
                $cached = $record['cached'];
                if (!array_key_exists($config, $configs)) {
                    $configs[$config] = array();
                }
                if (!array_key_exists($location, $configs[$config])) {
                    $configs[$config][$location] = array();
                }
                if (!array_key_exists($cached, $configs[$config][$location])) {
                    $configs[$config][$location][$cached] = array();
                }
                $configs[$config][$location][$cached][] = $record[$metric];
                
                if (array_key_exists('label', $record) &&
                    strlen($record['label'])) {
                    if (!array_key_exists('labels', $info)) {
                        $info['labels'] = array();
                    }
                    if (!array_key_exists($record['label'], $info['labels'])) {
                        $info['labels'][$record['label']] = $record['label'];
                    }
                }
            }
        }
    }
    
    foreach ($configs as $config => &$locations) {
        foreach ($locations as $location => &$cache_state) {
            foreach ($cache_state as $cached => &$records) {
                $entry = CalculateMetrics($records);
                if (is_array($entry)) {
                    $entry['time'] = $run_time;
                    $entry['config'] = $config;
                    $entry['location'] = $location;
                    $entry['cached'] = $cached;
                    
                    // see if we already have a record that matches that we need to overwrite
                    $exists = false;
                    foreach ($agg_data as $i => &$row) {
                        if ($row['time'] == $run_time && 
                            $row['config'] == $config &&
                            $row['location'] == $location &&
                            $row['cached'] == $cached) {
                            $exists = true;
                            $agg_data[$i] = $entry;
                            break;
                        }
                    }
                    if (!$exists)
                        $agg_data[] = $entry;
                    unset ($entry);
                }
            }
        }
    }
}

/**
* Create the aggregates for the given metric grouped by config, label and cached state
* 
* @param mixed $info
* @param mixed $data
* @param mixed $run_time
* @param mixed $agg_data
*/
function AggregateMetricByLabel($metric, $info, &$data, $run_time, &$agg_data, $options) {
    $labels = array();
    global $nonZero;
    // group the individual records
    foreach ($data as &$record) {
        if (array_key_exists($metric, $record) && 
            array_key_exists('result', $record) && 
            array_key_exists('config', $record) && 
            array_key_exists('location', $record) && 
            array_key_exists('cached', $record) && 
            array_key_exists('label', $record) && 
            strlen($record['config']) &&
            strlen($record['location']) &&
            strlen($record['label']) &&
            ($record['result'] == 0 || $record['result'] == 99999)) {
            // make sure all of the metrics that we expect to be non-zero are
            $ok = true;
            foreach($nonZero as $nzMetric) {
                if ($nzMetric == $metric && $record[$metric] == 0) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $label = $record['label'];
                $config = $record['config'];
                $location = $record['location'];
                $cached = $record['cached'];
                if (!array_key_exists($label, $labels)) {
                    $labels[$label] = array();
                }
                if (!array_key_exists($config, $labels[$label])) {
                    $labels[$label][$config] = array();
                }
                if (!array_key_exists($location, $labels[$label][$config])) {
                    $labels[$label][$config][$location] = array();
                }
                if (!array_key_exists($cached, $labels[$label][$config][$location])) {
                    $labels[$label][$config][$location][$cached] = array();
                }
                $labels[$label][$config][$location][$cached][] = $record[$metric];
            }
        }
    }

    foreach ($labels as $label => &$configs) {
        foreach ($configs as $config => &$locations) {
            foreach ($locations as $location => &$cache_state) {
                foreach ($cache_state as $cached => &$records) {
                    $entry = CalculateMetrics($records);
                    if (is_array($entry)) {
                        $entry['time'] = $run_time;
                        $entry['label'] = $label;
                        $entry['config'] = $config;
                        $entry['location'] = $location;
                        $entry['cached'] = $cached;
                        // see if we already have a record that matches that we need to overwrite
                        $exists = false;
                        foreach ($agg_data as $i => &$row) {
                            if ($row['time'] == $run_time && 
                                $row['config'] == $config &&
                                $row['location'] == $location &&
                                $row['cached'] == $cached &&
                                $row['label'] == $label) {
                                $exists = true;
                                $agg_data[$i] = $entry;
                                break;
                            }
                        }
                        if (!$exists)
                            $agg_data[] = $entry;
                        unset ($entry);
                    }
                }
            }
        }
    }
}

/** 
* Calculate several aggregations on the given data set
* 
* @param mixed $records
*/
function CalculateMetrics(&$records) {
    $entry = null;
    sort($records, SORT_NUMERIC);
    $count = count($records);
    if ($count) {
        $entry = array('count' => $count);
        // average
        $sum = 0;
        foreach ($records as $value) {
            $sum += $value;
        }
        $avg = $sum / $count;
        $entry['avg'] = $avg;
        // geometric mean
        $sum = 0.0;
        foreach($records as $value) {
             $sum += log(doubleval($value));
        }
        $entry['geo-mean'] = exp($sum/$count);  
        // median
        if ($count %2) {
            $entry['median'] = $records[floor($count * 0.5)];
        } else {
            $entry['median'] = intval(round(($records[floor($count * 0.5)] + $records[floor($count * 0.5) - 1]) / 2));
        }
        // 75th percentile
        $entry['75pct'] = $records[floor($count * 0.75)];  // 0-based array, hence the floor instead of ceil
        // 95th percentile
        $entry['95pct'] = $records[floor($count * 0.95)];  // 0-based array, hence the floor instead of ceil
        // standard deviation
        $sum = 0;
        foreach ($records as $value) {
            $sum += pow($value - $avg, 2);
        }
        $entry['stddev'] = sqrt($sum / $count);
    }
    return $entry;
}

/**
* Prune the extra data we don't need for benchmarks from the test result
* video, screen shots, headers, etc
* 
* @param mixed $id
*/
function PruneTestData($id) {
    $testPath = './' . GetTestPath($id);
    
    $files = scandir($testPath);
    foreach( $files as $file ) {
        // just do the videos for now
        if (strpos($file, 'video_') !== false && is_dir("$testPath/$file")) {
            delTree("$testPath/$file");
        } elseif (strpos($file, 'bodies') !== false) {
            unlink("$testPath/$file");
        } elseif (strpos($file, 'pagespeed') !== false) {
            unlink("$testPath/$file");
        } elseif (strpos($file, '_doc.jpg') !== false) {
            unlink("$testPath/$file");
        } elseif (strpos($file, '_render.jpg') !== false) {
            unlink("$testPath/$file");
        } elseif (strpos($file, 'status.txt') !== false) {
            unlink("$testPath/$file");
        }
    }
}

/**
* Do any necessary pre-processing on the data set (like reducing to the median run)
* 
* @param mixed $data
* @param mixed $options
*/
function FilterRawData(&$data, $options) {
    if (isset($options) && is_array($options) && array_key_exists('median_run', $options)) {
        $metric = $options['median_run'];
        if (is_numeric($metric) || is_bool($metric)) {
            $metric = 'docTime';
        }
        // first group the results for each test
        $grouped = array();
        foreach($data as $row) {
            if (array_key_exists('id', $row) && 
                array_key_exists($metric, $row) && 
                array_key_exists('cached', $row) && 
                array_key_exists('result', $row) &&
                ($row['result'] == 0 || $row['result'] == 99999)) {
                $id = $row['id'];
                $cached = $row['cached'];
                if (!array_key_exists($id, $grouped)) {
                    $grouped[$id] = array();
                }
                if (!array_key_exists($cached, $grouped[$id])) {
                    $grouped[$id][$cached] = array();
                }
                $grouped[$id][$cached][] = $row;
            }
        }
        // now select the median from each for the filtered data set
        $data = array();
        foreach($grouped as &$test) {
            foreach($test as $test_data) {
                // load the times into an array so we can sort them and extract the median
                $times = array();
                foreach($test_data as $row) {
                    $times[] = $row[$metric];
                }
                $median_run_index = 0;
                $count = count($times);
                if( $count > 1 ) {
                    asort($times);
                    $medianIndex = (int)floor(((float)$count + 1.0) / 2.0);
                    $current = 0;
                    foreach( $times as $index => $time ) {
                        $current++;
                        if( $current == $medianIndex ) {
                            $median_run_index = $index;
                            break;
                        }
                    }
                }
                $data[] = $test_data[$median_run_index];
            }
        }
    }
}
?>
