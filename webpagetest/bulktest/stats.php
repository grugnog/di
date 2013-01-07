<?php
include './settings.inc';

$results = array();

$loc = array();
$loc['EC2_East_wptdriver:Chrome.DSL'] = 'Chrome';
$loc['EC2_East_wptdriver:Firefox.DSL'] = 'Firefox';
$loc['EC2_East.DSL'] = 'IE 9';

$metrics = array('ttfb', 'startRender', 'docComplete', 'fullyLoaded', 'speedIndex', 'bytes', 'requests');

// Load the existing results
if (LoadResults($results)) {
    // split them up by URL and location
    $data = array();
    $stats = array();
    foreach($results as &$result) {
        $url = $result['url'];
        $location = $loc[$result['location']];
        if( !array_key_exists($url, $data) ) {
            $data[$url] = array();
        }
        $data[$url][$location] = array();
        $data[$url][$location]['id'] = $result['id'];
        $data[$url][$location]['result'] = $result['result'];
        if( $result['result'] == 0 || $result['result'] == 99999 ) {
            if (!array_key_exists($location, $stats)) {
                $stats[$location] = array();
                foreach ($metrics as $metric) {
                    $stats[$location][$metric] = array();
                }
            }
            foreach ($metrics as $metric) {
                $data[$url][$location][$metric] = $result[$metric];
                $stats[$location][$metric][] = $result[$metric];
            }
        }
    }
    ksort($data);
    foreach ($metrics as $metric) {
        $file = fopen("./$metric.csv", 'w+');
        if ($file) {
            fwrite($file, "URL,Chrome 23,Firefox 16,Firefox 16 Delta,IE 9,IE 9 Delta,Test Comparison\r\n");
            $metricData = array('Chrome' => array(), 'Firefox' => array(), 'IE' => array());
            $firefoxFaster = 0;
            $ieFaster = 0;
            foreach($data as $url => &$urlData) {
                fwrite($file, "$url,");
                $chrome = null;
                if (array_key_exists('Chrome', $urlData) && array_key_exists($metric, $urlData['Chrome'])) {
                    $chrome = $urlData['Chrome'][$metric];
                    fwrite($file, $chrome);
                }
                fwrite($file, ',');
                $firefox = null;
                if (array_key_exists('Firefox', $urlData) && array_key_exists($metric, $urlData['Firefox'])) {
                    $firefox = $urlData['Firefox'][$metric];
                    fwrite($file, $firefox);
                }
                fwrite($file, ',');
                if (isset($chrome) && isset($firefox)) {
                    $delta = $firefox - $chrome;
                    fwrite($file, $delta);
                }
                fwrite($file, ',');
                $ie = null;
                if (array_key_exists('IE 9', $urlData) && array_key_exists($metric, $urlData['IE 9'])) {
                    $ie = $urlData['IE 9'][$metric];
                    fwrite($file, $ie);
                }
                fwrite($file, ',');
                if (isset($chrome) && isset($ie)) {
                    $delta = $ie - $chrome;
                    fwrite($file, $delta);
                }
                fwrite($file, ',');
                $compare = "\"http://www.webpagetest.org/video/compare.php?thumbSize=200&ival=100&end=doc&tests=";
                $compare .= $urlData['Chrome']['id'] . '-l:Chrome%2023,';
                $compare .= $urlData['Firefox']['id'] . '-l:Firefox%2016,';
                $compare .= $urlData['IE 9']['id'] . '-l:IE%209';
                $compare .= '"';
                fwrite($file, $compare);
                fwrite($file, "\r\n");
                if (isset($chrome) && isset($firefox) && isset($ie)) {
                    $metricData['Chrome'][] = $chrome;
                    $metricData['Firefox'][] = $firefox;
                    $metricData['IE'][] = $ie;
                    if ($firefox < $chrome) {
                        $firefoxFaster++;
                    }
                    if ($ie < $chrome) {
                        $ieFaster++;
                    }
                }
            }
            fclose($file);
            $summary = fopen("./{$metric}_Summary.csv", 'w+');
            if ($summary) {
                sort($metricData['Chrome']);
                sort($metricData['Firefox']);
                sort($metricData['IE']);
                fwrite($summary, ",Chrome 23,Firefox 16,Firefox 16 delta,IE 9,IE 9 delta\r\n");
                $chrome = Avg($metricData['Chrome']);
                $firefox = Avg($metricData['Firefox']);
                $firefoxDelta = $firefox - $chrome;
                $firefoxDeltaPct = round(($firefoxDelta / $chrome) * 100);
                $ie = Avg($metricData['IE']);
                $ieDelta = $ie - $chrome;
                $ieDeltaPct = number_format(($ieDelta / $chrome) * 100, 2);
                fwrite($summary, "Average,$chrome,$firefox,$firefoxDelta ($firefoxDeltaPct%),$ie,$ieDelta ($ieDeltaPct%)\r\n");
                $percentiles = array(25,50,75,95,99);
                foreach($percentiles as $percentile) {
                    $chrome = Percentile($metricData['Chrome'], $percentile);
                    $firefox = Percentile($metricData['Firefox'], $percentile);
                    $firefoxDelta = $firefox - $chrome;
                    $firefoxDeltaPct = number_format(($firefoxDelta / $chrome) * 100, 2);
                    $ie = Percentile($metricData['IE'], $percentile);
                    $ieDelta = $ie - $chrome;
                    $ieDeltaPct = number_format(($ieDelta / $chrome) * 100, 2);
                    fwrite($summary, "{$percentile}th Percentile,$chrome,$firefox,$firefoxDelta ($firefoxDeltaPct%),$ie,$ieDelta ($ieDeltaPct%)\r\n");
                }
                fwrite($summary, "\r\n");
                fwrite($summary, "Test Count," . count($metricData['Chrome']) . "\r\n");
                fwrite($summary, "Firefox Faster,$firefoxFaster\r\n");
                fwrite($summary, "IE Faster,$ieFaster\r\n");
                fclose($summary);
            }
        }
    }
}

function Avg(&$data) {
    $avg = '';
    $count = count($data);
    if ($count) {
        $total = 0;
        foreach($data as $value) {
            $total += $value;
        }
        $avg = round($total / $count);
    }
    return $avg;
}

function Percentile(&$data, $percentile) {
    $val = '';
    $count = count($data);
    if ($count) {
        $pos = min($count - 1, max(0,floor((($count - 1) * $percentile) / 100)));
        $val = $data[$pos];
    }
    return $val;
}

/**
* Dump the data of a particular type to a tab-delimited report
* 
* @param mixed $data
* @param mixed $type
*/
function ReportData(&$data, $type)
{
    global $locations;
    $file = fopen("./report_$type.txt", 'wb');
    if($file)
    {
        fwrite($file, "URL\t");
        foreach($locations as $location)
            fwrite($file, "$location $type avg\t");
        foreach($locations as $location)
            fwrite($file, "$location $type stddev\t");
        foreach($locations as $location)
            fwrite($file, "$location $type stddev/avg\t");
        fwrite($file, "\r\n");
        foreach($data as $urlhash => &$urlentry)
        {
            fwrite($file, "{$urlentry['url']}\t");
            foreach($locations as $location){
                $value = $urlentry['data'][$location]["{$type}_avg"];
                fwrite($file, "$value\t");
            }
            foreach($locations as $location){
                $value = $urlentry['data'][$location]["{$type}_stddev"];
                fwrite($file, "$value\t");
            }
            foreach($locations as $location){
                $value = $urlentry['data'][$location]["{$type}_ratio"];
                fwrite($file, "$value\t");
            }
            fwrite($file, "\r\n");
        }
        fclose($file);
    }
}

/**
* Calculate the average and standard deviation for the supplied data set
* 
* @param mixed $data
* @param mixed $avg
* @param mixed $stddev
* @param mixed $ratio
*/
function CalculateStats(&$data, &$avg, &$stddev, &$ratio)
{
    $avg = 0;
    $stddev = 0;
    $ratio = 0;
    
    // pass 1 - average
    $total = 0;
    $count = 0;
    foreach($data as $value){
        $total += $value;
        $count++;
    }
    if( $count ){
        $avg = $total / $count;
        
        // pass 2 - stddev
        $total = 0;
        foreach($data as $value){
            $total += pow($value - $avg, 2);
        }
        $stddev = sqrt($total / $count);
        if ($avg)
            $ratio = $stddev / $avg;
    }
}
?>
