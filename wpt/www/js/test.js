function ValidateInput(form)
{
    if( (form.url.value == "" || form.url.value == "Enter a Website URL") &&
        form.script.value == "" && form.bulkurls.value == "" && form.bulkfile.value == "" )
    {
        alert( "Please enter an URL to test." );
        form.url.focus();
        return false
    }
    
    if( form.url.value == "Enter a Website URL" )
        form.url.value = "";
    
    var runs = form.runs.value;
    if( runs < 1 || runs > maxRuns )
    {
        alert( "Please select a number of runs between 1 and " + maxRuns + "." );
        form.runs.focus();
        return false
    }
    
    var date = new Date();
    date.setTime(date.getTime()+(730*24*60*60*1000));
    var expires = "; expires="+date.toGMTString();
    var options = 0;
    if( form.private.checked )
        options = 1;
    if( form.viewFirst.checked )
        options = options | 2;
    document.cookie = 'testOptions=' + options + expires + '; path=/';
    document.cookie = 'runs=' + runs + expires + '; path=/';
    
    // save out the selected location and connection information
    document.cookie = 'cfg=' + $('#connection').val() + expires +  '; path=/';
    document.cookie = 'u=' + $('#bwUp').val() + expires +  '; path=/';
    document.cookie = 'd=' + $('#bwDown').val() + expires +  '; path=/';
    document.cookie = 'l=' + $('#latency').val() + expires +  '; path=/';
    document.cookie = 'p=' + $('#plr').val() + expires +  '; path=/';

    return true;
}

/*
    Do any populating of the input form based on the loaded location information
*/
(function($) {
    $(document).ready(function() {

        // enable tooltips
        $("#DOMElement").tooltip({ position: "top center", offset: [-5, 0]  });  
        
        // enable tab-input in the script field
        $("#enter_script").tabby();
            
       // handle when the selection changes for the location
        $("#location").change(function(){
            LocationChanged();
        });
        $("#location2").change(function(){
            $("#location").val($("#location2").val());
            LocationChanged();
        });

        $("#browser").change(function(){
            BrowserChanged();
        });

        $("#connection").change(function(){
            ConnectionChanged();
        });
        
        if( $('#aftCheck').attr('checked') )
            $('#aftSettings').removeClass('hidden');
            
        $("#aftCheck").change(function(){
            if( $('#aftCheck').attr('checked') )
            {
                $('#videoCheck').attr('checked', true);
                $('#aftSettings').removeClass('hidden');
            }
            else
                $('#aftSettings').addClass('hidden');
        });

        $("#videoCheck").change(function(){
            if( !$('#videoCheck').attr('checked') )
            {
                $('#aftCheck').attr('checked', false);
                $('#aftSettings').addClass('hidden');
            }
        });

        // make sure to select an intelligent default (in case the back button was hit)
        LocationChanged();
        
        //$('#url').focus();
    });
})(jQuery);

/*
    Populate the different browser options for a given location
*/
function LocationChanged()
{
    $("#current-location").text($('#location option:selected').text());
    var loc = $('#location').val(); 
    $('#location2').val(loc); 

    var marker = locations[loc]['marker'];
    try{
        marker.setIcon('/images/map_green.png');
    }catch(err){}
    try{
        selectedMarker.setIcon('/images/map_red.png');
    }catch(err){}
    selectedMarker = marker;
    
    var browsers = [];
    var defaultConfig = locations[loc]['default'];
    if( defaultConfig == undefined )
        defaultConfig = locations[loc]['1'];
    var defaultBrowser = locations[defaultConfig]['browser'];
    
    // build the list of browsers for this location
    for( var key in locations[loc] )
    {
        // only care about the integer indexes
        if( !isNaN(key) )
        {
            var config = locations[loc][key];
            var browser = locations[config]['browser'];
            if( browser != undefined )
            {
                // see if we already know about this browser
                var browserKey = browser.replace(" ","");
                browsers[browserKey] = browser;
            }
        }
    }
    
    // fill in the browser list, selecting the default one
    browserHtml = '';
    for( var key in browsers )
    {
        var browser = browsers[key];
        var selected = '';
        if( browser == defaultBrowser )
            selected = ' selected';
        browserHtml += '<option value="' + key + '"' + selected + '>' + browser + '</option>';
    }
    $('#browser').html(browserHtml);
    
    BrowserChanged();
    
    UpdateSponsor();
}

/*
    Populate the various connection types that are available
*/
function BrowserChanged()
{
    var loc = $('#location').val();
    var selectedBrowser = $('#browser').val();
    var defaultConfig = locations[loc]['default'];
    var selectedConfig;
    
    var connections = [];

    // build the list of connections for this location/browser
    for( var key in locations[loc] )
    {
        // only care about the integer indexes
        if( !isNaN(key) )
        {
            var config = locations[loc][key];
            var browser = locations[config]['browser'].replace(" ","");;
            if( browser == selectedBrowser )
            {
                if( locations[config]['connectivity'] != undefined )
                {
                    connections[config] = locations[config]['connectivity'];
                    if( config == defaultConfig )
                        selectedConfig = config;
                }
                else
                {
                    for( var conn in connectivity )
                    {
                        if( selectedConfig == undefined )
                            selectedConfig = config + '.' + conn;
                        connections[config + '.' + conn] = connectivity[conn]['label'];
                    }
                    
                    connections[config + '.custom'] = 'Custom';
                    if( selectedConfig == undefined )
                        selectedConfig = config + '.custom';
                }
            }
        }
    }
    
    // if the default configuration couldn't be selected, pick the first one
    if( selectedConfig == undefined )
    {
        for( var config in connections )
        {
            selectedConfig = config;
            break;
        }
    }
    
    // build the actual list
    connectionHtml = '';
    for( var config in connections )
    {
        var selected = '';
        if( config == selectedConfig )
            selected = ' selected';
        connectionHtml += '<option value="' + config + '"' + selected + '>' + connections[config] + '</option>';
    }
    $('#connection').html(connectionHtml);
    
    ConnectionChanged();
}

/*
    Populate the specifics of the connection information
*/
function ConnectionChanged()
{
    var conn = $('#connection').val();
    if( conn != undefined && conn.length )
    {
        var parts = conn.split('.');
        var config = parts[0];
        var connection = parts[1];
        var setSpeed = true;
        
        var backlog = locations[config]['backlog'];
        var wait = locations[config]['wait'];
        var waitText = '';
        if( wait < 0 )
        {
            waitText = 'Location is offline, please select a different browser or location';
            $('#wait').removeClass('backlogWarn').addClass('backlogHigh');
            //$('#start_test-button').hide();
        }
        else if( wait > 120 )
        {
            waitText = 'Location is exceptionally busy, please select a different location or try again later';
            $('#wait').removeClass('backlogWarn').addClass('backlogHigh');
            //$('#start_test-button').hide();
        }
        else
        {
            $('#wait').removeClass('backlogWarn , backlogHigh');
            //$('#start_test-button').show();
            if( wait == 1 )
                waitText = '1 minute';
            else if (wait > 0)
            {
                if (wait > 120)
                    waitText = Math.rount(wait / 60) + ' hours';
                else
                    waitText = wait + ' minutes';
            }
            else
                waitText = 'None';
        }

        var up = locations[config]['up'] / 1000;
        var down = locations[config]['down'] / 1000;
        var latency = locations[config]['latency'];
        var plr = 0;
        var aftCutoff = $('#aftec').val();
        if( connection != undefined && connection.length )
        {
            if( connectivity[connection] != undefined )
            {
                up = connectivity[connection]['bwOut'] / 1000;
                down = connectivity[connection]['bwIn'] / 1000;
                latency = connectivity[connection]['latency'];
                if( connectivity[connection]['plr'] != undefined )
                    plr = connectivity[connection]['plr'];
                if( connectivity[connection]['aftCutoff'] != undefined )
                    aftCutoff = connectivity[connection]['aftCutoff'];
            }
            else
            {
                setSpeed = false;
            }
        }

        if( setSpeed )
        {
            $('#bwDown').val(down);
            $('#bwUp').val(up);
            $('#latency').val(latency);
            $('#plr').val(plr);
            $('#aftec').val(aftCutoff);
        }
        
        // enable/disable the fields as necessary
        if( connection == 'custom' )
            $('#bwTable').show();
        else
            $('#bwTable').hide();
        
        $('#backlog').text(backlog);
        if( backlog < 5 )
            $('#pending_tests').removeClass('backlogWarn , backlogHigh').addClass('hidden');
        else if( backlog < 20 )
            $('#pending_tests').removeClass('backlogHigh , hidden').addClass("backlogWarn");
        else
            $('#pending_tests').removeClass('backlogWarn , hidden').addClass("backlogHigh");

        $('#wait').text(waitText);
            
        UpdateSettingsSummary();
    }
}

/*
    Update the location sponsor
*/
function UpdateSponsor()
{
    var loc = $('#location').val(); 
    var spon = new Array();

    // build the list of sponsors for this location
    for( var key in locations[loc] )
    {
        // only care about the integer indexes
        if( !isNaN(key) )
        {
            var config = locations[loc][key];
            var sponsor = locations[config]['sponsor'];
            if( sponsor != undefined && sponsor.length && sponsors[sponsor] != undefined )
            {
                // avoid duplicates
                var found = false;
                for( var index in spon )
                    if( spon[index] == sponsor )
                        found = true;
                
                if( !found )
                    spon.push(sponsor);
            }
        }
    }
    
    if( spon.length )
    {
        var html = '<p class="centered"><small>Provided by</small></p>';
        var count = 0;
        
        // randomize the list
        if( spon.length > 1 )
            spon.sort(function() {return 0.5 - Math.random()});
        
        for( var index in spon )
        {
            var sponsor = spon[index];
            var s = sponsors[sponsor];
            if( s != undefined )
            {
                var sponsorImg = null;
                var sponsorTxt = '';
                var sponsorHref = '';
                var sponsorStyle = '';

                if( s["logo_big"] != undefined && s["logo_big"].length )
                    sponsorImg = s["logo_big"];
                else if( s["logo"] != undefined && s["logo"].length ) {
                    sponsorImg = s["logo"];
                    sponsorStyle = ' style="top: ' + s["offset"] + 'px;" ';
                }
                    
                if( s["alt"] != undefined && s["alt"].length )
                    sponsorTxt = s["alt"];

                if( s["href"] != undefined && s["href"].length )
                    sponsorHref = s["href"];
                
                if( sponsorImg && sponsorImg.length )
                {
                    if( count )
                        html += '<p class="centered nomargin"><small>and</small></p>';

                    html += '<p class="centered nomargin">';
                    if( sponsorStyle.length )
                        html += '<div class="sponsor_logo">';
                    if( sponsorHref.length )
                        html += '<a class="sponsor_link" href="' + sponsorHref + '">';
                    
                    html += '<img title="' + sponsorTxt + '" alt="' + sponsorTxt + '" src="' + sponsorImg + '" ' + sponsorStyle + '>';
                    
                    if( sponsorHref.length )
                        html += '</a>';
                    if( sponsorStyle.length )
                        html += '</div>';
                    
                    html += '</p>';
                
                    count++;
                }
            }
        }
        
        $('#sponsor').html(html);
        $('#sponsor').show();
    }
    else
        $('#sponsor').hide();
}

/*
    Update the summary text with the current test settings
*/
function UpdateSettingsSummary()
{
    var summary = '';

    var runs = $('#number_of_tests').val();
    summary += runs;
    if( runs == 1 )
        summary += " run";
    else
        summary += " runs";
        
    if( $('#viewFirst').attr('checked') )
        summary += ", First View only";
        
    var conn = $('#connection option:selected').text();
    if( conn != undefined )
        summary += ", " + conn.replace(/\((.)*\)/,'') + " connection";
        
    if( $('#keep_test_private').attr('checked') )
        summary += ", private";
    else
        summary += ", results are public";
        
    $('#settings_summary').text(summary);
}

/*
    Show the multiple location selection dialog
*/
function OpenMultipleLocations()
{
    document.getElementById('multiple-location-dialog').style.display = 'block'; 
}

/*
    Close the multiple location selection dialog.
*/
function CloseMultipleLocations()
{
    document.getElementById('multiple-location-dialog').style.display = 'none'; 
}

/*
    Pop up the location selection dialog
*/
var map;
var selectedMarker;
function SelectLocation()
{
    $("#location-dialog").modal({opacity:80});
    $('#location2').val($('#location').val()); 
   
    var script = document.createElement("script");
    script.type = "text/javascript";
    script.src = "http://maps.google.com/maps/api/js?v=3.1&sensor=false&callback=InitializeMap";
    document.body.appendChild(script);
    
    return false;
}

function InitializeMap()
{
    var myLatlng = new google.maps.LatLng(15,17);
    var myOptions = {
        zoom: 2,
        center: myLatlng,
        mapTypeControl: false,
        navigationControl: true,
        navigationControlOptions: {style: google.maps.NavigationControlStyle.SMALL},
        mapTypeId: google.maps.MapTypeId.ROADMAP
    }
    var map = new google.maps.Map(document.getElementById("map"), myOptions);

    var currentLoc = $('#location').val();
    for( var loc in locations )
    {
        if( locations[loc]['lat'] != undefined && locations[loc]['lng'] != undefined )
        {
            var pos = new google.maps.LatLng(locations[loc]['lat'], locations[loc]['lng']);
            var marker = new google.maps.Marker({
                position: pos,
                title:locations[loc]['label'],
                icon:'/images/map_red.png',
                map: map
            });
            
            if( loc == currentLoc )
            {
                marker.setIcon('/images/map_green.png');
                selectedMarker = marker;
            }
            
            locations[loc]['marker'] = marker;
            
            AttachClickEvent(marker, loc);
        }
    }
    
}

function AttachClickEvent(marker, loc)
{
    google.maps.event.addListener(marker, 'click', function() {
        $('#location').val(loc);
        LocationChanged();
    });
}