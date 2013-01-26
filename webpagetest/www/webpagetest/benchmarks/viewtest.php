<?php
set_time_limit(600);
chdir('..');
include 'common.inc';
include './benchmarks/data.inc.php';
$page_keywords = array('Benchmarks','Webpagetest','Website Speed Test','Page Speed');
$page_description = "WebPagetest benchmark test details";
$benchmark = '';
if (array_key_exists('benchmark', $_REQUEST)) {
    $benchmark = $_REQUEST['benchmark'];
    $info = GetBenchmarkInfo($benchmark);
    if (array_key_exists('options', $info) && array_key_exists('median_run', $info['options'])) {
        $median_metric = $info['options']['median_run'];
    }
}
$test_time = 0;
if (array_key_exists('time', $_REQUEST))
    $test_time = $_REQUEST['time'];
else {
    // figure out the time of the most recent test
    if (is_dir("./results/benchmarks/$benchmark/data")) {
        $files = scandir("./results/benchmarks/$benchmark/data");
        foreach( $files as $file ) {
            if (preg_match('/([0-9]+_[0-9]+)\..*/', $file, $matches)) {
                $UTC = new DateTimeZone('UTC');
                $date = DateTime::createFromFormat('Ymd_Hi', $matches[1], $UTC);
                $time = $date->getTimestamp();
                if ($time > $test_time)
                    $test_time = $time;
            }
        }
    }
}
$series = GetSeriesLabels($benchmark);
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
                'browser_version' => 'Browser Version'
                );
$metric = 'SpeedIndex';
if (!$info['video']) {
    unset($metrics['SpeedIndex']);
    $metric = 'docTime';
}
if (array_key_exists('metric', $_REQUEST)) {
    $metric = $_REQUEST['metric'];
}
if (array_key_exists('f', $_REQUEST)) {
    $out_data = array();
} else {
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
    <head>
        <title>WebPagetest - Benchmark test details</title>
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
        .dygraph-axis-label-x {display:none;}
        </style>
    </head>
    <body>
        <div class="page">
            <?php
            $tab = 'Benchmarks';
            include 'header.inc';
            echo "<script type=\"text/javascript\" src=\"{$GLOBALS['cdnPath']}/js/site.js?v=" . VER_JS . "\"></script>\n";
            $site_js_loaded = true;
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
                    Click on a data point in the chart to see the full test data (waterfall, etc) for the given data point.<br>
                    Highlight an area of the chart to zoom in on that area and double-click to zoom out.
                    <?php
                    if (isset($info) && array_key_exists('notes', $info)) {
                        echo '<br>';
                        echo $info['notes'];
                    }
                    ?>
                </div>
                <div style="float: right;">
                    <form name="metric" method="get" action="viewtest.php">
                        <?php
                        echo "<input type=\"hidden\" name=\"benchmark\" value=\"$benchmark\">";
                        echo "<input type=\"hidden\" name=\"time\" value=\"$test_time\">";
                        ?>
                        Metric <select name="metric" size="1" onchange="this.form.submit();">
                        <?php
                        foreach( $metrics as $m => $metricLabel) {
                            $selected = '';
                            if ($m == $metric) {
                                $selected = ' selected';
                            }
                            echo "<option value=\"$m\"$selected>$metricLabel</option>\n";
                        }
                        ?>
                        </select>
                    </form>
                </div>
            </div>
            <div style="clear:both;">
            <br>
            </div>
            <div style="text-align:center; clear:both;">
                <script type="text/javascript">
                    var charts = new Array();
                    <?php
                    echo 'var seriesData = ' . json_encode($series) . ";\n";
                    ?>
                    function ToggleSeries(checked, series) {
                        setTimeout('ToggleSeriesDelayed(' + checked + ',' + series + ');', 1);
                    }
                    function ToggleSeriesDelayed(checked,series) {
                        for(i=0; i < charts.length; i++) {
                            eval(charts[i] + '.setVisibility(' + series + ',' + checked + ');');
                        }
                    }
                    function SelectedPoint(url, tests, series, index, cached) {
                        <?php
                            echo "var benchmark=\"$benchmark\";\n";
                            echo "var medianMetric=\"$median_metric\";\n";
                        ?>
                        var menu = '<div><h4>View test for ' + url + '</h4>';
                        var compare = "/video/compare.php?ival=100&medianMetric=" + medianMetric + "&tests=";
                        for( i = 0; i < tests.length; i++ ) {
                            menu += '<a href="/result/' + tests[i] + '/?medianMetric=' + medianMetric + '" target="_blank">' + seriesData[i].name + '</a><br>';
                            if (i) {
                                compare += ",";
                            }
                            compare += encodeURIComponent(tests[i] + "-l:" + seriesData[i].name.replace("-","").replace(":",""));
                        }
                        menu += '<br><a href="trendurl.php?benchmark=' + encodeURIComponent(benchmark) + '&url=' + encodeURIComponent(url) + '">Trend over time</a>';
                        menu += '<br><a href="' + compare + '">Filmstrip Comparison</a>';
                        menu += '</div>';
                        $.modal(menu, {overlayClose:true});
                    }
                </script>
                <form name="aggregation" method="get" action="view.php">
                    <?php
                    if ($series && count($series)) {
                        echo 'Display: ';
                        $index = 0;
                        foreach($series as &$series_data) {
                            echo "<input type=\"checkbox\" checked onclick=\"ToggleSeries(this.checked, $index);\"> {$series_data['name']} &nbsp; ";
                            $index++;
                        }
                    }
                    ?>
                </form>
            </div>
            <?php
}
            if (isset($info)) {
                if (!isset($out_data)) {
                    echo "<h1>{$info['title']}</h1>";
                    if (array_key_exists('description', $info)) {
                        echo "<p>{$info['description']}</p>\n";
                    }
                    echo "<h2>{$metrics[$metric]} <span class=\"small\">(<a name=\"$metric\" href=\"#$metric\">direct link</a>)</span></h2>\n";
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
            if (!isset($out_data)) {
                echo "<hr><h2>Test Errors <span class=\"small\">(<a name=\"errors\" href=\"#errors\">direct link</a>)</span></h2>\n";
                if (GetTestErrors($errors, $benchmark, $test_time)) {
                    foreach($errors as &$configuration) {
                        if (count($configuration['locations'])) {
                            echo "<h2>{$configuration['label']}</h2>\n";
                            foreach($configuration['locations'] as &$location) {
                                echo "<h3>{$location['label']}</h3>\n";
                                if (count($location['urls'])) {
                                    echo "<ul>";
                                    foreach($location['urls'] as &$url) {
                                        echo "<li>" . htmlspecialchars($url['url']) . " - ";
                                        $first = true;
                                        foreach( $url['errors'] as &$test ) {
                                            if ($first)
                                                $first = false;
                                            else
                                                echo ", ";
                                            $cached = '';
                                            if ($test['cached'])
                                                $cached = 'cached/';
                                            echo "<a href=\"/result/{$test['id']}/{$test['run']}/details/$cached\">{$test['error']}</a>";
                                        }
                                        echo "</li>";
                                    }
                                    echo "</ul>";
                                } else {
                                    echo "No Errors Detected";
                                }
                            }
                        }
                    }
                } else {
                    echo "No Errors Detected";
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

function DisplayBenchmarkData(&$benchmark, $metric, $loc = null, $title = null) {
    global $count;
    global $aggregate;
    global $test_time;
    global $out_data;
    $chart_title = '';
    if (isset($title))
        $chart_title = "title: \"$title (First View)\",";
    $annotations = null;
    $bmname = $benchmark['name'];
    if (isset($loc)) {
        $bmname .= ".$loc";
    }
    $tsv = LoadTestDataTSV($benchmark['name'], 0, $metric, $test_time, $meta, $loc, $annotations);
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
                var {$id}meta = " . json_encode($meta) . ";
                $id = new Dygraph(
                    document.getElementById(\"$id\"),
                    \"" . str_replace("\t", '\t', str_replace("\n", '\n', $tsv)) . "\",
                    {drawPoints: true,
                    strokeWidth: 0.0,
                    labelsSeparateLines: true,
                    labelsDiv: document.getElementById('{$id}_legend'),
                    axes: {x: {valueFormatter: function(urlid) {return {$id}meta[urlid].url;}}},
                    pointClickCallback: function(e, p) {SelectedPoint({$id}meta[p.xval].url, {$id}meta[p.xval]['tests'], p.name, p.xval, false);},
                    $chart_title
                    legend: \"always\"}
                );
                charts.push('$id');";
        if (isset($annotations) && count($annotations)) {
            echo "$id.setAnnotations(" . json_encode($annotations) . ");\n";
        }
        echo "</script>\n";
    }
    if (!array_key_exists('fvonly', $benchmark) || !$benchmark['fvonly']) {
        if (isset($title))
            $chart_title = "title: \"$title (Repeat View)\",";
        $tsv = LoadTestDataTSV($benchmark['name'], 1, $metric, $test_time, $meta, $loc, $annotations);
        if (isset($out_data)) {
            $out_data[$bmname][$metric]['RV'] = TSVEncode($tsv);
        }
        if (!isset($out_data) && isset($tsv) && strlen($tsv)) {
            $count++;
            $id = "g$count";
            echo "<br><div class=\"chart-container\"><div id=\"$id\" class=\"benchmark-chart\"></div><div id=\"{$id}_legend\" class=\"benchmark-legend\"></div></div>\n";
            echo "<script type=\"text/javascript\">
                    var {$id}meta = " . json_encode($meta) . ";
                    $id = new Dygraph(
                        document.getElementById(\"$id\"),
                        \"" . str_replace("\t", '\t', str_replace("\n", '\n', $tsv)) . "\",
                        {drawPoints: true,
                        strokeWidth: 0.0,
                        labelsSeparateLines: true,
                        labelsDiv: document.getElementById('{$id}_legend'),
                        axes: {x: {valueFormatter: function(urlid) {return {$id}meta[urlid].url;}}},
                        pointClickCallback: function(e, p) {SelectedPoint({$id}meta[p.xval].url, {$id}meta[p.xval]['tests'], p.name, p.xval, true);},
                        $chart_title
                        legend: \"always\"}
                    );
                    charts.push('$id');";
            if (isset($annotations) && count($annotations)) {
                echo "$id.setAnnotations(" . json_encode($annotations) . ");\n";
            }
            echo "</script>\n";
        }
    }
}    
?>