<?php
chdir('..');
include 'common.inc';
include './benchmarks/data.inc.php';
$page_keywords = array('Benchmarks','Webpagetest','Website Speed Test','Page Speed');
$page_description = "WebPagetest benchmark details";
$aggregate = 'median';
if (array_key_exists('aggregate', $_REQUEST))
    $aggregate = $_REQUEST['aggregate'];
$benchmark = '';
if (array_key_exists('benchmark', $_REQUEST)) {
    $benchmark = $_REQUEST['benchmark'];
    $info = GetBenchmarkInfo($benchmark);
}
if (array_key_exists('f', $_REQUEST)) {
    $out_data = array();
} else {
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
    <head>
        <title>WebPagetest - Benchmark details</title>
        <meta http-equiv="charset" content="iso-8859-1">
        <meta name="keywords" content="Performance, Optimization, Pagetest, Page Design, performance site web, internet performance, website performance, web applications testing, web application performance, Internet Tools, Web Development, Open Source, http viewer, debugger, http sniffer, ssl, monitor, http header, http header viewer">
        <meta name="description" content="Speed up the performance of your web pages with an automated analysis">
        <meta name="author" content="Patrick Meenan">
        <?php $gaTemplate = 'About'; include ('head.inc'); ?>
        <script type="text/javascript" src="/js/dygraph-combined.js"></script>
        <style type="text/css">
        .chart-container { clear: both; width: 875px; height: 350px; margin-left: auto; margin-right: auto; padding: 0;}
        .benchmark-chart { float: left; width: 700px; height: 350px; }
        .benchmark-legend { float: right; width: 150px; height: 350px; }
        </style>
    </head>
    <body>
        <div class="page">
            <?php
            $tab = 'Benchmarks';
            include 'header.inc';
            ?>
            
            <div class="translucent">
            <?php
            if (isset($info) && array_key_exists('links', $info)) {
                echo '<div style="clear:both; padding-bottom: 0.5em;">Benchmark Information: ';
                $link_count = 0;
                foreach ($info['links'] as $link_label => $link_url) {
                    if ($link_count) {
                        echo " - ";
                    }
                    echo "<a href=\"$link_url\">$link_label</a>";
                    $link_count++;
                }
                echo '</div>';
            }
            ?>
            <div style="clear:both;">
                <div style="float:left;" class="notes">
                    Click on a data point in the chart to see the scatter plot results for that specific test.<br>
                    Highlight an area of the chart to zoom in on that area and double-click to zoom out.
                    <?php
                    if (isset($info) && array_key_exists('notes', $info)) {
                        echo '<br>';
                        echo $info['notes'];
                    }
                    ?>
                </div>
                <div style="float: right;">
                    <form name="aggregation" method="get" action="view.php">
                        <?php
                        echo "<input type=\"hidden\" name=\"benchmark\" value=\"$benchmark\">";
                        ?>
                        Aggregation <select name="aggregate" size="1" onchange="this.form.submit();">
                            <option value="avg" <?php if ($aggregate == 'avg') echo "selected"; ?>>Average</option>
                            <option value="geo-mean" <?php if ($aggregate == 'geo-mean') echo "selected"; ?>>Geometric Mean</option>
                            <option value="median" <?php if ($aggregate == 'median') echo "selected"; ?>>Median</option>
                            <option value="75pct" <?php if ($aggregate == '75pct') echo "selected"; ?>>75th Percentile</option>
                            <option value="95pct" <?php if ($aggregate == '95pct') echo "selected"; ?>>95th Percentile</option>
                            <option value="count" <?php if ($aggregate == 'count') echo "selected"; ?>>Count</option>
                        </select>
                    </form>
                </div>
            </div>
            <div style="clear:both;">
            </div>
            <script type="text/javascript">
            function SelectedPoint(benchmark, metric, series, time, cached) {
                time = parseInt(time / 1000, 10);
                var isCached = 0;
                if (cached)
                    isCached = 1;
                var menu = '<div><h4>View details:</h4>';
                var scatter = "viewtest.php?benchmark=" + encodeURIComponent(benchmark) + "&metric=" + encodeURIComponent(metric) + "&cached=" + isCached + "&time=" + time;
                var delta = "delta.php?benchmark=" + encodeURIComponent(benchmark) + "&metric=" + encodeURIComponent(metric) + "&time=" + time;
                menu += '<a href="' + scatter + '">Scatter Plot</a><br>';
                menu += '<a href="' + delta + '">Comparison Distribution</a><br>';
                menu += '</div>';
                $.modal(menu, {overlayClose:true});
            }
            </script>
            <?php
}
            $metrics = array('docTime' => 'Load Time (onload)', 
                            'SpeedIndex' => 'Speed Index',
                            'TTFB' => 'Time to First Byte', 
                            'titleTime' => 'Time to Title', 
                            'render' => 'Time to Start Render', 
                            'fullyLoaded' => 'Load Time (Fully Loaded)', 
                            'server_rtt' => 'Estimated RTT to Server',
                            'domElements' => 'Number of DOM Elements', 
                            'connections' => 'Connections', 
                            'requests' => 'Requests (Fully Loaded)', 
                            'requestsDoc' => 'Requests (onload)', 
                            'bytesInDoc' => 'Bytes In (KB - onload)', 
                            'bytesIn' => 'Bytes In (KB - Fully Loaded)', 
                            'js_bytes' => 'Javascript Bytes (KB)', 
                            'js_requests' => 'Javascript Requests', 
                            'css_bytes' => 'CSS Bytes (KB)', 
                            'css_requests' => 'CSS Requests', 
                            'image_bytes' => 'Image Bytes (KB)', 
                            'image_requests' => 'Image Requests',
                            'flash_bytes' => 'Flash Bytes (KB)', 
                            'flash_requests' => 'Flash Requests', 
                            'html_bytes' => 'HTML Bytes (KB)', 
                            'html_requests' => 'HTML Requests', 
                            'text_bytes' => 'Text Bytes (KB)', 
                            'text_requests' => 'Text Requests',
                            'other_bytes' => 'Other Bytes (KB)', 
                            'other_requests' => 'Other Requests',
                            'browser_version' => 'Browser Version');
//                            'responses_404' => 'Not Found Responses (404)', 
//                            'responses_other' => 'Non-404 Error Responses');
            if (isset($info)) {
                if (!$info['video']) {
                    unset($metrics['SpeedIndex']);
                }
                if (!isset($out_data)) {
                    echo "<h1>{$info['title']}</h1>";
                    if (array_key_exists('description', $info))
                        echo "<p>{$info['description']}</p>\n";
                }
                foreach( $metrics as $metric => $label) {
                    if (!isset($out_data)) {
                        echo "<h2>$label <span class=\"small\">(<a name=\"$metric\" href=\"#$metric\">direct link</a>)</span></h2>\n";
                    }
                    if ($info['expand'] && count($info['locations'] > 1)) {
                        foreach ($info['locations'] as $location => $label) {
                            if (is_numeric($label))
                                $label = $location;
                            DisplayBenchmarkData($info, $metric, $location, $label);
                        }
                    } else {
                        DisplayBenchmarkData($info, $metric);
                    }
                }
            }
if (!isset($out_data)) {
            ?>
            </div>
            
            <?php include('footer.inc'); ?>
        </div>
    </body>
</html>
<?php
} else {
    // spit out the raw data
    header ("Content-type: application/json; charset=utf-8");
    echo json_encode($out_data);
}

/**
* Display the charts for the given benchmark/metric
* 
* @param mixed $benchmark
*/
function DisplayBenchmarkData(&$benchmark, $metric, $loc = null, $title = null) {
    global $count;
    global $aggregate;
    global $out_data;
    $chart_title = '';
    if (isset($title))
        $chart_title = "title: \"$title (First View)\",";
    $bmname = $benchmark['name'];
    if (isset($loc)) {
        $bmname .= ".$loc";
    }
    $tsv = LoadDataTSV($benchmark['name'], 0, $metric, $aggregate, $loc, $annotations);
    if (isset($out_data)) {
        if (!array_key_exists($bmname, $out_data)) {
            $out_data[$bmname] = array();
        }
        $out_data[$bmname][$metric] = array();
        $out_data[$bmname][$metric]['FV'] = TSVEncode($tsv);
    }
    if (!isset($out_data) && isset($tsv) && strlen($tsv)) {
        $count++;
        $id = "g$count";
        echo "<div class=\"chart-container\"><div id=\"$id\" class=\"benchmark-chart\"></div><div id=\"{$id}_legend\" class=\"benchmark-legend\"></div></div><br>\n";
        echo "<script type=\"text/javascript\">
                $id = new Dygraph(
                    document.getElementById(\"$id\"),
                    \"" . str_replace("\t", '\t', str_replace("\n", '\n', $tsv)) . "\",
                    {drawPoints: true,
                    rollPeriod: 1,
                    showRoller: true,
                    labelsSeparateLines: true,
                    labelsDiv: document.getElementById('{$id}_legend'),
                    pointClickCallback: function(e, p) {SelectedPoint(\"{$benchmark['name']}\", \"$metric\", p.name, p.xval, false);},
                    $chart_title
                    legend: \"always\"}
                );";
        if (isset($annotations) && count($annotations)) {
            echo "$id.setAnnotations(" . json_encode($annotations) . ");\n";
        }
        echo "</script>\n";
    }
    if (!array_key_exists('fvonly', $benchmark) || !$benchmark['fvonly']) {
        if (isset($title))
            $chart_title = "title: \"$title (Repeat View)\",";
        $tsv = LoadDataTSV($benchmark['name'], 1, $metric, $aggregate, $loc, $annotations);
        if (isset($out_data)) {
            $out_data[$bmname][$metric]['RV'] = TSVEncode($tsv);
        }
        if (!isset($out_data) && isset($tsv) && strlen($tsv)) {
            $count++;
            $id = "g$count";
            echo "<br><div class=\"chart-container\"><div id=\"$id\" class=\"benchmark-chart\"></div><div id=\"{$id}_legend\" class=\"benchmark-legend\"></div></div>\n";
            echo "<script type=\"text/javascript\">
                    $id = new Dygraph(
                        document.getElementById(\"$id\"),
                        \"" . str_replace("\t", '\t', str_replace("\n", '\n', $tsv)) . "\",
                        {drawPoints: true,
                        rollPeriod: 1,
                        showRoller: true,
                        labelsSeparateLines: true,
                        labelsDiv: document.getElementById('{$id}_legend'),
                        pointClickCallback: function(e, p) {SelectedPoint(\"{$benchmark['name']}\", \"$metric\", p.name, p.xval, true);},
                        $chart_title
                        legend: \"always\"}
                    );";
            if (isset($annotations) && count($annotations)) {
                echo "$id.setAnnotations(" . json_encode($annotations) . ");\n";
            }
            echo "</script>\n";
        }
    }
}    
?>
