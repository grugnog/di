<?php
if( !isset($_REQUEST['tests']) && isset($_REQUEST['t']) )
{
    $tests = '';
    foreach($_REQUEST['t'] as $t)
    {
        $parts = explode(',', $t);
        if( count($parts) >= 1 )
        {
            if( strlen($tests) )
                $tests .= ',';
            $tests .= trim($parts[0]);
            if( $parts[1] )
                $tests .= "-r:{$parts[1]}";
        }
    }

    $host  = $_SERVER['HTTP_HOST'];
    $uri = $_SERVER['PHP_SELF'];
    $params = '';
    foreach( $_GET as $key => $value )
        if( $key != 't' )
            $params .= "&$key=" . urlencode($value);
    header("Location: http://$host$uri?tests=$tests{$params}");    
}
else
{
    chdir('..');
    include 'common.inc';
    require_once('page_data.inc');
    include 'video/filmstrip.inc.php';  // include the commpn php shared across the filmstrip code
    include 'object_detail.inc'; 
    require_once('waterfall.inc');

    $page_keywords = array('Video','comparison','Webpagetest','Website Speed Test');
    $page_description = "Visual comparison of multiple websites with a side-by-side video and filmstrip view of the user experience.";

    $title = 'Web page visual comparison';
    $labels = '';
    $location = null;
    foreach( $tests as &$test )
    {
        if (array_key_exists('location', $test)) {
            if (!isset($location)) {
                $location = $test['location'];
            } elseif ($test['location'] != $location) {
                $location = '';
            }
        } else {
            $location = '';
        }
        
        if( strlen($test['name']) )
        {
            if( strlen($labels) )
                $labels .= ", ";
            $labels .= $test['name'];
        }
    }
    if( strlen($labels) )
        $title .= ' - ' . $labels;
    ?>
    <!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
    <html>
        <head>
            <title>WebPagetest - Visual Comparison</title>
            <?php
                if( !$ready )
                {
            ?>
                <noscript>
                <meta http-equiv="refresh" content="10" />
                </noscript>
                <script language="JavaScript">
                setTimeout( "window.location.reload(true)", 10000 );
                </script>
            <?php } $gaTemplate = 'Visual Comparison'; include ('head.inc'); ?>
            <style type="text/css">
            <?php
                $bgcolor = '000000';
                $color = 'ffffff';
                if (array_key_exists('bg', $_GET)) {
                    $bgcolor = $_GET['bg'];
                }
                if (array_key_exists('text', $_GET)) {
                    $color = $_GET['text'];
                }
            ?>
                #video
                {
                    margin-left: auto;
                    margin-right: auto;
                }
                #videoDiv
                {
                    overflow-y: hidden;
                    position: relative;
                    overflow: auto;
                    width: 100%; 
                    height: 100%;
                    padding-bottom: 1em;
                }
                #videoContainer
                {
                    table-layout: fixed;
                    margin-left: auto;
                    margin-right: auto;
                    width: 99%;
                }
                #videoContainer td
                {
                    margin: 2px;
                }
                #labelContainer
                {
                    width: 8em;
                    vertical-align: top;
                    text-align: right;
                    padding-right: 0.5em;
                }
                #videoLabels
                {
                    table-layout: fixed;
                    width: 100%;
                    overflow: hidden;
                }
                th{ font-weight: normal; }
                #videoLabels td
                { 
                    padding: 2px; 
                }
                #video td{ padding: 2px; }
                div.content
                {
                    text-align:center;
                    <?php
                        echo "background: #$bgcolor;\n";
                        echo "color: #$color;\n"
                    ?>
                    font-family: arial,sans-serif
                }
                .pagelink
                {
                    text-decoration: none;
                    <?php
                        echo "color: #$color;\n"
                    ?>
                }
                .thumb{ border: none; }
                .thumbChanged{border: 3px solid #FEB301;}
                .thumbAFT{border: 3px solid #FF0000;}
                #createForm
                {
                    width:100%;
                }
                #bottom
                {
                    width:100%;
                    text-align: left;
                }
                #layout
                {
                    float: right;
                    position: relative;
                    top: -8em;
                }
                #layoutTable
                {
                    text-align: left;
                }
                #layoutTable th
                {
                    padding-left: 1em;
                    text-decoration: underline;
                }
                #layoutTable td
                {
                    padding-left: 2em;
                    vertical-align: top;
                }
                #statusTable
                {
                    table-layout: fixed;
                    margin-left: auto;
                    margin-right: auto;
                    font-size: larger;
                    text-align: left;
                }
                #statusTable th
                {
                    text-decoration: underline;
                    padding-left: 2em;
                }
                #statusTable td
                {
                    padding-top: 1em;
                    padding-left: 2em;
                }
                #image
                {
                    margin-left:auto; 
                    margin-right:auto; 
                    clear: both;
                }
                #advanced
                {
                    <?php
                        echo "background: #$bgcolor;\n";
                        echo "color: #$color;\n"
                    ?>
                    font-family: arial,sans-serif;
                    padding: 20px;
                }
                #advanced td
                {
                    padding: 2px 10px;
                }
                #advanced-ok
                {
                    margin-left: auto;
                    margin-right: auto;
                    margin-top: 10px;
                    display: block;
                }
                #marker {
                    position: absolute; top: 0; left: 250px;
                    height: 100%;
                    width: 2px;
                    background-color: #D00;
                }
                #location {
                    text-align: left;
                    padding: 5px;
                    width: 100%;
                }
                div.compare-graph {margin:20px 0; width:900px; height:600px;margin-left:auto; margin-right:auto;}
                <?php
                include "waterfall.css";
                if (defined('EMBED')) {
                ?>
                #location {display: none;}
                #bottom {display: none;}
                #layout {display: none;}
                #export {display: none;}
                div.content {padding: 0; background-color: #fff;}
                div.page {width: 100%;}
                #videoContainer {background-color: #000; border-spacing: 0; width: 100%; margin: 0;}
                #videoDiv {padding-bottom: 0;}
                body {background-color: #fff; margin: 0; padding: 0;}
                <?php
                }
                ?>
                div.waterfall-container {top: -8em;}
            </style>
        </head>
        <body>
            <div class="page">
                <?php
                $tab = 'Test Result';
                $nosubheader = true;
                $filmstrip = $_REQUEST['tests'];
                include 'header.inc';

                if( $error ) {
                    echo "<h1>$error</h1>";
                } elseif( $ready ) {
                    if (isset($location) && strlen($location)) {
                        echo "<div id=\"location\">Tested From: $location</div>";
                    }
                    ScreenShotTable();
                    DisplayGraphs();
                } else {
                    DisplayStatus();
                }
                ?>
            
                <?php include('footer.inc'); ?>
            </div>

            <script type="text/javascript">
                function ShowAdvanced()
                {
                    $("#advanced").modal({opacity:80});
                }

                $("#videoDiv").scroll(function() {
                    UpdateScrollPosition();
                });

                function UpdateScrollPosition() {
                    var position = $("#videoDiv").scrollLeft();
                    var viewable = $("#videoDiv").width();
                    var width = $("#video").width();
                    var marker = parseInt(250 + ((position / width) * 680));
                    $('#marker').css('left', marker + 'px');
                }
                UpdateScrollPosition();
                
                <?php
                include "waterfall.js";
                ?>
            </script>
        </body>
    </html>

    <?php
}

/**
* Build a side-by-side table with the captured frames from each test
* 
*/
function ScreenShotTable()
{
    global $tests;
    global $thumbSize;
    global $interval;
    global $maxCompare;
    global $color;
    global $bgcolor;
    $aftAvailable = false;
    $endTime = 'visual';
    if( strlen($_REQUEST['end']) )
        $endTime = trim($_REQUEST['end']);

    $filmstrip_end_time = 0;
    if( count($tests) )
    {
        // figure out how many columns there are
        $end = 0;
        foreach( $tests as &$test )
            if( $test['video']['end'] > $end )
                $end = $test['video']['end'];
        
        if (!defined('EMBED')) {
            echo '<br>';
        }
        echo '<form id="createForm" name="create" method="get" action="/video/create.php">';
        echo "<input type=\"hidden\" name=\"end\" value=\"$endTime\">";
        echo '<input type="hidden" name="tests" value="' . htmlspecialchars($_REQUEST['tests']) . '">';
        echo '<table id="videoContainer"><tr>';

        // build a table with the labels
        echo '<td id="labelContainer"><table id="videoLabels"><tr><th>&nbsp;</th></tr>';
        foreach( $tests as &$test )
        {
            if($test['aft'])
                $aftAvailable = true;
            // figure out the height of this video
            $height = 100;
            if( $test['video']['width'] && $test['video']['height'] ) {
                if( $test['video']['width'] > $test['video']['height'] ) {
                    $height = 22 + (int)(((float)$thumbSize / (float)$test['video']['width']) * (float)$test['video']['height']);
                } else {
                    $height = 22 + $thumbSize;
                }
            }

            $break = '';
            if( !strpos($test['name'], ' ') )
                $break = ' style="word-break: break-all;"';
            echo "<tr width=10% height={$height}px ><td$break>";
            $name = urlencode($test['name']);
            $cached = 0;
            if( $test['cached'] )
                $cached = 1;
            $testEnd = '';
            if( $test['end'] )
            {
                $testEnd = (int)(($test['end'] + 99) / 100);
                $testEnd = (float)$testEnd / 10.0;
            }
            if (!defined('EMBED')) {
                //echo "<input type=\"checkbox\" name=\"t[]\" value=\"{$test['id']},{$test['run']}," . $name . ",$cached,$testEnd\" checked=checked> ";
                $cached = '';
                if( $test['cached'] )
                    $cached = 'cached/';
                if( FRIENDLY_URLS )
                    echo "<a class=\"pagelink\" href=\"/result/{$test['id']}/{$test['run']}/details/$cached\">";
                else
                    echo "<a class=\"pagelink\" href=\"/details.php?test={$test['id']}&run={$test['run']}&cached={$test['cached']}\">";
            }
            echo WrapableString($test['name']);
            if (!defined('EMBED')) {
                echo "</a>";
            }
            echo "</td></tr>\n";
        }
        echo '</table></td>';
        
        // the actual video frames        
        echo '<td><div id="videoDiv"><table id="video"><thead><tr>';
        $skipped = $interval;
        $last = $end + $interval - 1;
        for( $frame = 0; $frame <= $last; $frame++ )
        {
            $skipped++;
            if( $skipped >= $interval )
            {
                $skipped = 0;
                echo '<th>' . number_format((float)$frame / 10.0, 1) . 's</th>';
                $filmstrip_end_time = $frame / 10.0;
            }
        }
        echo "</tr></thead><tbody>\n";
        
        $firstFrame = 0;
        foreach( $tests as &$test )
        {
            $aft = (int)$test['aft'] / 100;
            
            // figure out the height of the image
            $height = 0;
            $width = $thumbSize;
            if( $test['video']['width'] && $test['video']['height'] ) {
                if ($test['video']['width'] > $test['video']['height'] ) {
                    $width = $thumbSize;
                    $height = (int)(((float)$thumbSize / (float)$test['video']['width']) * (float)$test['video']['height']);
                } else {
                    $height = $thumbSize;
                    $width = (int)(((float)$thumbSize / (float)$test['video']['height']) * (float)$test['video']['width']);
                }
            }
            echo "<tr>";
            
            $lastThumb = null;
            $frameCount = 0;
            $skipped = $interval;
            $last = $end + $interval - 1;
            $progress = null;
            for( $frame = 0; $frame <= $last; $frame++ )
            {
                $path = null;
                if( isset($test['video']['frames'][$frame]) )
                    $path = $test['video']['frames'][$frame];
                if( isset($path) )
                    $test['currentframe'] = $frame;
                else
                {
                    if( isset($test['currentframe']) )
                        $path = $test['video']['frames'][$test['currentframe']];
                    else
                        $path = $test['video']['frames'][0];
                }

                $ms = $frame * 100;
                if (array_key_exists('progress', $test['video']) 
                    && array_key_exists('frames', $test['video']['progress'])
                    && array_key_exists($ms, $test['video']['progress']['frames']))
                    $progress = $test['video']['progress']['frames'][$ms]['progress'];

                if( !$lastThumb )
                    $lastThumb = $path;
                
                $skipped++;
                if( $skipped >= $interval )
                {
                    $skipped = 0;

                    echo '<td>';
                    if( $frame - $interval + 1 <= $test['video']['end'] )
                    {
                        echo '';

                        $cached = '';
                        if( $test['cached'] )
                            $cached = '_cached';
                        $imgPath = GetTestPath($test['id']) . "/video_{$test['run']}$cached/$path";
                        echo "<a href=\"/$imgPath\">";
                        echo "<img title=\"{$test['name']}\"";
                        $class = 'thumb';
                        if( $lastThumb != $path )
                        {
                            if( !$firstFrame || $frameCount < $firstFrame )
                                $firstFrame = $frameCount;
                            $class = 'thumbChanged';
                        }
                        if( $aft && $frame >= $aft )
                        {
                            $aft = 0;
                            $class = 'thumbAFT';
                        }
                        echo " class=\"$class\"";
                        echo " width=\"$width\"";
                        if( $height )
                            echo " height=\"$height\"";
                        echo " src=\"/thumbnail.php?test={$test['id']}&fit=$thumbSize&file=video_{$test['run']}$cached/$path\"></a>";
                        
                        if (isset($progress)) {
                            echo "<br>$progress%";
                        }
                        
                        $lastThumb = $path;
                    }
                    $frameCount++;
                    echo '</td>';
                }
            }
            echo "</tr>\n";
        }
        echo "</tr>\n";
        
        // end of the table
        echo "</tbody></table></div>\n";
        
        // end of the container table
        echo "</td></tr></table>\n";
        echo "<div id=\"image\">";
        $ival = $interval * 100;
        echo "<a id=\"export\" class=\"pagelink\" href=\"filmstrip.php?tests={$_REQUEST['tests']}&thumbSize=$thumbSize&ival=$ival&end=$endTime&text=$color&bg=$bgcolor\">Export filmstrip as an image...</a>";
        echo "</div>";
        echo '<div id="bottom"><input type="checkbox" name="slow" value="1"> Slow Motion<br><br>';
        echo "<input id=\"SubmitBtn\" type=\"submit\" value=\"Create Video\">";
        echo '<br><br><a class="pagelink" href="javascript:ShowAdvanced()">Advanced customization options...</a>';
        echo "</div></form>";
        if (!defined('EMBED')) {
        ?>
        <div id="layout">
            <form id="layoutForm" name="layout" method="get" action="/video/compare.php">
            <?php
                echo "<input type=\"hidden\" name=\"tests\" value=\"{$_REQUEST['tests']}\">\n";
            ?>
                <table id="layoutTable">
                    <tr><th>Thumbnail Size</th><th>Thumbnail Interval</th><th>Comparison End Point</th></th></tr>
                    <?php
                        // fill in the thumbnail size selection
                        echo "<tr><td>";
                        $checked = '';
                        if( $thumbSize <= 100 )
                            $checked = ' checked=checked';
                        echo "<input type=\"radio\" name=\"thumbSize\" value=\"100\"$checked onclick=\"this.form.submit();\"> Small<br>";
                        $checked = '';
                        if( $thumbSize <= 150 && $thumbSize > 100 )
                            $checked = ' checked=checked';
                        echo "<input type=\"radio\" name=\"thumbSize\" value=\"150\"$checked onclick=\"this.form.submit();\"> Medium<br>";
                        $checked = '';
                        if( $thumbSize > 150 )
                            $checked = ' checked=checked';
                        echo "<input type=\"radio\" name=\"thumbSize\" value=\"200\"$checked onclick=\"this.form.submit();\"> Large";
                        echo "</td>";
                        
                        // fill in the interval selection
                        echo "<td>";
                        $checked = '';
                        if( $interval <= 1 )
                            $checked = ' checked=checked';
                        echo "<input type=\"radio\" name=\"ival\" value=\"100\"$checked onclick=\"this.form.submit();\"> 0.1 sec<br>";
                        $checked = '';
                        if( $interval == 5 )
                            $checked = ' checked=checked';
                        echo "<input type=\"radio\" name=\"ival\" value=\"500\"$checked onclick=\"this.form.submit();\"> 0.5 sec<br>";
                        $checked = '';
                        if( $interval == 10 )
                            $checked = ' checked=checked';
                        echo "<input type=\"radio\" name=\"ival\" value=\"1000\"$checked onclick=\"this.form.submit();\"> 1 sec<br>";
                        $checked = '';
                        if( $interval == 50 )
                            $checked = ' checked=checked';
                        echo "<input type=\"radio\" name=\"ival\" value=\"5000\"$checked onclick=\"this.form.submit();\"> 5 sec<br>";
                        echo "</td>";

                        // fill in the end-point selection
                        echo "<td>";
                        if( !$aftAvailable && !strcasecmp($endTime, 'aft') )
                            $endTime = 'visual';
                        $checked = '';
                        if( !strcasecmp($endTime, 'visual') )
                            $checked = ' checked=checked';
                        echo "<input type=\"radio\" name=\"end\" value=\"visual\"$checked onclick=\"this.form.submit();\"> Visually Complete<br>";
                        $checked = '';
                        if( !strcasecmp($endTime, 'all') )
                            $checked = ' checked=checked';
                        echo "<input type=\"radio\" name=\"end\" value=\"all\"$checked onclick=\"this.form.submit();\"> Last Change<br>";
                        $checked = '';
                        if( !strcasecmp($endTime, 'doc') )
                            $checked = ' checked=checked';
                        echo "<input type=\"radio\" name=\"end\" value=\"doc\"$checked onclick=\"this.form.submit();\"> Document Complete<br>";
                        $checked = '';
                        if( !strcasecmp($endTime, 'full') )
                            $checked = ' checked=checked';
                        echo "<input type=\"radio\" name=\"end\" value=\"full\"$checked onclick=\"this.form.submit();\"> Fully Loaded<br>";
                        if( $aftAvailable )
                        {
                            $checked = '';
                            if( !strcasecmp($endTime, 'aft') )
                                $checked = ' checked=checked';
                            echo "<input type=\"radio\" name=\"end\" value=\"aft\"$checked onclick=\"this.form.submit();\"> AFT<br>";
                        }
                        echo "</td></tr>";
                    ?>
                </table>
            </form>
        </div>
        <?php
        // display the waterfall if there is only one test
        if( count($tests) == 1 )
        {
            $data = loadPageRunData($tests[0]['path'], $tests[0]['run'], $tests[0]['cached']);
            $secure = false;
            $haveLocations = false;
            $requests = getRequests($tests[0]['id'], $tests[0]['path'], $tests[0]['run'], $tests[0]['cached'], $secure, $haveLocations, true, true);
            InsertWaterfall($url, $requests, $tests[0]['id'], $tests[0]['run'], $tests[0]['cached'], "&max=$filmstrip_end_time&mime=1&state=1&cpu=0&bw=0" );
        }
        ?>
        
        <div id="advanced" style="display:none;">
            <h3>Advanced Visual Comparison Configuration</h3>
            <p>There are additional customizations that can be done by modifying the <b>tests</b> parameter in the comparison URL directly.</p>
            <p>URL structure: ...compare.php?tests=&lt;Test 1 ID&gt;,&lt;Test 2 ID&gt;...</p>
            <p>The tests are displayed in the order listed and can be customized with options:</p>
            <table>
            <tr><td>Custom label</td><td>-l:&lt;label&gt;</td><td>110606_MJ_RZEY-l:Original</td></tr>
            <tr><td>Specific run</td><td>-r:&lt;run&gt;</td><td>110606_MJ_RZEY-r:3</td></tr>
            <tr><td>Repeat view</td><td>-c:1</td><td>110606_MJ_RZEY-c:1</td></tr>
            <tr><td>Specific End Time</td><td>-e:&lt;seconds&gt;</td><td>110606_MJ_RZEY-e:1.1</td></tr>
            </table>
            <br>
            <p>Examples:</p>
            <ul>
            <li><b>Customizing labels:</b>
            http://www.webpagetest.org/video/compare.php?tests=110606_MJ_RZEY-l:Original,110606_AE_RZN5-l:No+JS</li>
            <li><b>Compare First vs. Repeat view:</b>
            http://www.webpagetest.org/video/compare.php?tests=110606_MJ_RZEY, 110606_MJ_RZEY-c:1</li>
            </ul>
            <input id="advanced-ok" type=button class="simplemodal-close" value="OK">
        </div>
        <?php
        echo '<br><br>';
        } // EMBED
        // scroll the table to show the first thumbnail change
        $scrollPos = $firstFrame * ($thumbSize + 8);
        ?>
        <script language="javascript">
            var scrollPos = <?php echo "$scrollPos;"; ?>
            document.getElementById("videoDiv").scrollLeft = scrollPos;
        </script>
        <?php
    }
}

/**
* Not all of the tests are done yet so display a progress update
* 
*/
function DisplayStatus()
{
    global $tests;
    
    echo "<h1>Please wait while the tests are run...</h1>\n";
    echo "<table id=\"statusTable\"><tr><th>Test</th><th>Status</th></tr><tr>";
    foreach($tests as &$test)
    {
        echo "<tr><td>{$test['name']}</td><td>";
        if( $test['done'] )
            echo "Done";
        elseif( $test['started'] )
            echo "Testing...";
        else
            echo "Waiting to be tested...";
        
        echo "</td></tr>";
    }
    echo "</table>";
}

/**
* Create a wrapable string from what was passed in
* 
* @param mixed $in
*/
function WrapableString($in)
{
    if( strpos(trim($in), ' '))
        $out = $in;
    else
        $out = join("&#8203;",str_split($in,1));
    
    return $out;
}

/**
* Display the comparison graph with the various time metrics
* 
*/
function DisplayGraphs() {
    global $tests;
    global $filmstrip_end_frame;
    $progress_end = 0;
    require_once('breakdown.inc');
    $mimeTypes = array('html', 'js', 'css', 'text', 'image', 'flash', 'other');
    $timeMetrics = array('visualComplete' => 'Visually Complete',
                        'docTime' => 'Load Time (onload)', 
                        'fullyLoaded' => 'Load Time (Fully Loaded)',
                        'SpeedIndex' => 'Speed Index',
                        'TTFB' => 'Time to First Byte', 
                        'titleTime' => 'Time to Title', 
                        'render' => 'Time to Start Render');
    foreach($tests as &$test) {
        $requests;
        $test['breakdown'] = getBreakdown($test['id'], $test['path'], $test['run'], $test['cached'], $requests);
        if (array_key_exists('progress', $test['video']) 
            && array_key_exists('frames', $test['video']['progress'])) {
                foreach ($test['video']['progress']['frames'] as $ms => &$data) {
                    if ($ms > $progress_end && array_key_exists('progress', $data)) {
                        $progress_end = $ms;
                    }
                }
            }
    }
    if ($progress_end) {
        echo '<div id="compare_visual_progress" class="compare-graph"></div>';
    }
    echo '<div id="compare_times" class="compare-graph"></div>';
    echo '<div id="compare_requests" class="compare-graph"></div>';
    echo '<div id="compare_bytes" class="compare-graph"></div>';
    ?>
    <script type="text/javascript" src="<?php echo $GLOBALS['ptotocol']; ?>://www.google.com/jsapi"></script>
    <script type="text/javascript">
        google.load('visualization', '1', {'packages':['table', 'corechart']});
        google.setOnLoadCallback(drawCharts);
        function drawCharts() {
            var dataTimes = new google.visualization.DataTable();
            var dataRequests = new google.visualization.DataTable();
            var dataBytes = new google.visualization.DataTable();
            dataTimes.addColumn('string', 'Time (ms)');
            dataRequests.addColumn('string', 'MIME Type');
            dataBytes.addColumn('string', 'MIME Type');
            <?php
            foreach($tests as &$test) {
                echo "dataTimes.addColumn('number', '{$test['name']}');\n";
                echo "dataRequests.addColumn('number', '{$test['name']}');\n";
                echo "dataBytes.addColumn('number', '{$test['name']}');\n";
            }
            echo 'dataTimes.addRows(' . count($timeMetrics) . ");\n";
            echo 'dataRequests.addRows(' . strval(count($mimeTypes) + 1) . ");\n";
            echo 'dataBytes.addRows(' . strval(count($mimeTypes) + 1) . ");\n";
            if ($progress_end) {
                echo "var dataProgress = google.visualization.arrayToDataTable([\n";
                echo "  ['Time (ms)'";
                foreach($tests as &$test) {
                    echo ", '{$test['name']}'";
                }
                echo " ]";
                for ($ms = 0; $ms <= $progress_end; $ms += 100) {
                    echo ",\n  ['" . number_format($ms / 1000, 1) . "'";
                    foreach($tests as &$test) {
                        $progress = 0;
                        if (array_key_exists('last_progress', $test)) {
                            $progress = $test['last_progress'];
                        }
                        if (array_key_exists('progress', $test['video']) 
                            && array_key_exists('frames', $test['video']['progress'])
                            && array_key_exists($ms, $test['video']['progress']['frames'])) {
                            $progress = $test['video']['progress']['frames'][$ms]['progress'];
                        }
                        $test['last_progress'] = $progress;
                        echo ", $progress";
                    }
                    echo "]";
                }
                echo "]);\n";
            }
            $row = 0;
            foreach($timeMetrics as $metric => $label) {
                echo "dataTimes.setValue($row, 0, '$label');\n";
                $column = 1;
                foreach($tests as &$test) {
                    echo "dataTimes.setValue($row, $column, {$test['pageData'][$test['run']][$test['cached']][$metric]});\n";
                    $column++;
                }
                $row++;
            }
            echo "dataRequests.setValue(0, 0, 'Total');\n";
            echo "dataBytes.setValue(0, 0, 'Total');\n";
            $column = 1;
            foreach($tests as &$test) {
                echo "dataRequests.setValue(0, $column, {$test['pageData'][$test['run']][$test['cached']]['requests']});\n";
                echo "dataBytes.setValue(0, $column, {$test['pageData'][$test['run']][$test['cached']]['bytesIn']});\n";
                $column++;
            }
            $row = 1;
            foreach($mimeTypes as $mimeType) {
                echo "dataRequests.setValue($row, 0, '$mimeType');\n";
                echo "dataBytes.setValue($row, 0, '$mimeType');\n";
                $column = 1;
                foreach($tests as &$test) {
                    echo "dataRequests.setValue($row, $column, {$test['breakdown'][$mimeType]['requests']});\n";
                    echo "dataBytes.setValue($row, $column, {$test['breakdown'][$mimeType]['bytes']});\n";
                    $column++;
                }
                $row++;
            }
            if ($progress_end) {
                echo "var progressChart = new google.visualization.LineChart(document.getElementById('compare_visual_progress'));\n";
                echo "progressChart.draw(dataProgress, {title: 'Visual Progress (%)', hAxis: {title: 'Time (seconds)'}});\n";
            }            
            ?>
            var timesChart = new google.visualization.ColumnChart(document.getElementById('compare_times'));
            timesChart.draw(dataTimes, {title: 'Timings (ms)'});

            var requestsChart = new google.visualization.ColumnChart(document.getElementById('compare_requests'));
            requestsChart.draw(dataRequests, {title: 'Requests'});

            var bytesChart = new google.visualization.ColumnChart(document.getElementById('compare_bytes'));
            bytesChart.draw(dataBytes, {title: 'Bytes'});
        }
    </script>
    <?php
}
?>
