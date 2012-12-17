<?php
include 'common.inc';
require_once('./video/visualProgress.inc.php');

// make sure the test has finished, otherwise return a 404
if( isset($test['test']) && (isset($test['test']['completeTime']) || $test['test']['batch']) )
{
    $fileType = 'IEWPG.txt';
    $filename = "{$id}_summary.csv";
    $header = '"Date","Time","Event Name","URL","Load Time (ms)","Time to First Byte (ms)","unused","Bytes Out","Bytes In","DNS Lookups","Connections","Requests","OK Responses","Redirects","Not Modified","Not Found","Other Responses","Error Code","Time to Start Render (ms)","Segments Transmitted","Segments Retransmitted","Packet Loss (out)","Activity Time(ms)","Descriptor","Lab ID","Dialer ID","Connection Type","Cached","Event URL","Pagetest Build","Measurement Type","Experimental","Doc Complete Time (ms)","Event GUID","Time to DOM Element (ms)","Includes Object Data","Cache Score","Static CDN Score","One CDN Score","GZIP Score","Cookie Score","Keep-Alive Score","DOCTYPE Score","Minify Score","Combine Score","Bytes Out (Doc)","Bytes In (Doc)","DNS Lookups (Doc)","Connections (Doc)","Requests (Doc)","OK Responses (Doc)","Redirects (Doc)","Not Modified (Doc)","Not Found (Doc)","Other Responses (Doc)","Compression Score","Host","IP Address","ETag Score","Flagged Requests","Flagged Connections","Max Simultaneous Flagged Connections","Time to Base Page Complete (ms)","Base Page Result","Gzip Total Bytes","Gzip Savings","Minify Total Bytes","Minify Savings","Image Total Bytes","Image Savings","Base Page Redirects","Optimization Checked","AFT (ms)","DOM Elements","Page Speed Version","Page Title","Time to Title","Load Event Start","Load Event End","DOM Content Ready Start","DOM Content Ready End","Visually Complete (ms)","Browser Name","Browser Version","Base Page Server Count","Base Page Server RTT","Base Page CDN","Adult Site"';
    $column_count = 88;
    $is_requests = false;
    if( $_GET['requests'] )
    {
        $fileType = 'IEWTR.txt';
        $header = '"Date","Time","Event Name","IP Address","Action","Host","URL","Response Code","Time to Load (ms)","Time to First Byte (ms)","Start Time (ms)","Bytes Out","Bytes In","Object Size","Cookie Size (out)","Cookie Count(out)","Expires","Cache Control","Content Type","Content Encoding","Transaction Type","Socket ID","Document ID","End Time (ms)","Descriptor","Lab ID","Dialer ID","Connection Type","Cached","Event URL","Pagetest Build","Measurement Type","Experimental","Event GUID","Sequence Number","Cache Score","Static CDN Score","GZIP Score","Cookie Score","Keep-Alive Score","DOCTYPE Score","Minify Score","Combine Score","Compression Score","ETag Score","Flagged","Secure","DNS Time","Connect Time","SSL Time","Gzip Total Bytes","Gzip Savings","Minify Total Bytes","Minify Savings","Image Total Bytes","Image Savings","Cache Time (sec)","Real Start Time (ms)","Full Time to Load (ms)","Optimization Checked","CDN Provider","DNS Start","DNS End","Connect Start","Connect End","SSL Negotiation Start","SSL Negotiation End","Initiator","Initiator Line","Initiator Column","Server Count","Server RTT"';
        $column_count = 72;
        $filename = "{$id}_details.csv";
        $is_requests = true;
    }
    header("Content-disposition: attachment; filename=$filename");
    header ("Content-type: text/csv");
    
    
    if( $test['test']['batch'] )
    {
        echo "\"Test\",$header,\"Run\"";
        if (!$is_requests) {
            echo ',"Speed Index"';
        }
        echo "\r\n";
        $tests = null;
        if( gz_is_file("$testPath/tests.json") )
        {
            $legacyData = json_decode(gz_file_get_contents("$testPath/tests.json"), true);
            $tests = array();
            $tests['variations'] = array();
            $tests['urls'] = array();
            foreach( $legacyData as &$legacyTest )
                $tests['urls'][] = array('u' => $legacyTest['url'], 'id' => $legacyTest['id']);
        }
        elseif( gz_is_file("$testPath/bulk.json") )
            $tests = json_decode(gz_file_get_contents("$testPath/bulk.json"), true);
        if( isset($tests) )
        {
            foreach( $tests['urls'] as &$testData )
            {
                $label = $testData['l'];
                if( !strlen($label) )
                    $label = htmlspecialchars(ShortenUrl($testData['u']));
                $path = './' . GetTestPath($testData['id']);
                for( $i = 1; $i <= $test['test']['runs']; $i++ )
                {
                    $additional = array($i, SpeedIndex($path, $i, 0));
                    csvFile("$path/{$i}_$fileType", $label, $column_count, $additional);
                    $additional = array($i, SpeedIndex($path, $i, 1));
                    csvFile("$path/{$i}_Cached_$fileType", $label, $column_count, $additional);
                }
                
                foreach( $testData['v'] as $variationIndex => $variationId )
                {
                    $path = './' . GetTestPath($variationId);
                    for( $i = 1; $i <= $test['test']['runs']; $i++ )
                    {
                        $additional = array($i, SpeedIndex($path, $i, 0));
                        csvFile("$path/{$i}_$fileType", "$label - {$tests['variations'][$variationIndex]['l']}", $column_count, $additional);
                        $additional = array($i, SpeedIndex($path, $i, 1));
                        csvFile("$path/{$i}_Cached_$fileType", "$label - {$tests['variations'][$variationIndex]['l']}", $column_count, $additional);
                    }
                }
            }
        }
    }
    else
    {
        echo "$header,\"Run\"";
        if (!$is_requests) {
            echo ',"Speed Index"';
        }
        echo "\r\n";
        // loop through all  of the results files (one per run) - both cached and uncached
        for( $i = 1; $i <= $test['test']['runs']; $i++ )
        {
            $additional = array($i, SpeedIndex($testPath, $i, 0));
            csvFile("$testPath/{$i}_$fileType", null, $column_count, $additional);
            $additional = array($i, SpeedIndex($testPath, $i, 1));
            csvFile("$testPath/{$i}_Cached_$fileType", null, $column_count, $additional);
        }
    }
}
else
{
    header("HTTP/1.0 404 Not Found");
}

function SpeedIndex($testPath, $run, $cached) {
    $speed_index = '';
    $progress = GetVisualProgress($testPath, $run, $cached);
    if (isset($progress) && is_array($progress) && array_key_exists('FLI', $progress)) {
        $speed_index = $progress['FLI'];
    }
    return $speed_index;
}

/**
* Take a tab-separated file, convert it to csv and spit it out
* 
* @param mixed $fileName
* @param mixed $includeHeader
*/
function csvFile($fileName, $label, $column_count, &$additional_columns)
{
    $lines = gz_file($fileName);
    if( $lines)
    {
        // loop through each line in the file
        foreach($lines as $linenum => $line) 
        {
            if( $linenum > 0 || strncasecmp($line,'Date', 4) )
            {
                $line = trim($line, "\r\n");
                if( strlen($line) )
                {
                    $parseLine = str_replace("\t", "\t ", $line);
                    $fields = explode("\t", $parseLine);
                    if (count($fields)) {
                        if( isset($label) ) {
                            $label = str_replace('"', '', $label);
                            echo "\"$label\",";
                        }
                        for ($i = 0; $i < $column_count; $i++) {
                            $value = '';
                            if (array_key_exists($i, $fields)) {
                                $value = str_replace('"', '""', trim($fields[$i]));
                            }
                            echo "\"$value\",";
                        }
                        if (isset($additional_columns) && count($additional_columns)) {
                            foreach ($additional_columns as $value) {
                                $value = str_replace('"', '""', trim($value));
                                echo "\"$value\",";
                            }
                        }
                        echo "\r\n";
                    }
                }
            }
        }
    }
}
?>
